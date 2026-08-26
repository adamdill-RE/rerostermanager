<?php

declare(strict_types=1);

namespace Rerm\Roster;

use RuntimeException;

/**
 * OLE2 / Compound File Binary Format — the container a legacy .xls lives in.
 *
 * A .xls is not a spreadsheet file. It is a FAT filesystem in a single file,
 * and the spreadsheet is one stream inside it, conventionally named "Workbook".
 * This class does the filesystem half; XlsReader parses what comes out.
 *
 * The layout, all little-endian:
 *
 *   header      512 bytes, signature D0 CF 11 E0 A1 B1 1A E1
 *   FAT         a sector-allocation table, exactly like a disk FAT: entry N
 *               holds the sector that follows sector N, or an end marker
 *   DIFAT       because the FAT itself can span many sectors, its own sector
 *               list is held in the header (first 109) and then chained
 *   directory   128-byte entries naming each stream and its first sector
 *   mini stream a second, 64-byte-sector filesystem for streams under 4096
 *               bytes, stored inside the root entry's own stream
 *
 * Only reading is implemented, and only what a roster needs: find a stream by
 * name, return its bytes.
 */
final class CompoundFile
{
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const FREE      = 0xFFFFFFFF;
    private const END       = 0xFFFFFFFE;
    private const FAT_CHAIN = 0xFFFFFFFD;
    private const DIFAT     = 0xFFFFFFFC;

    /** Streams smaller than this live in the mini stream instead of the FAT. */
    private const MINI_CUTOFF = 4096;

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;

    /** @var array<int, int> */
    private array $fat = [];

    /** @var array<int, int> */
    private array $miniFat = [];

    /** @var array<string, array{start: int, size: int}> */
    private array $streams = [];

    private string $miniStream = '';

    public function __construct(string $data)
    {
        if (strlen($data) < 512 || !str_starts_with($data, self::SIGNATURE)) {
            throw new RuntimeException(
                'Not an OLE2 compound file: the 8-byte signature is missing. A .xlsx is a '
                . 'zip and belongs in XlsxReader.'
            );
        }

        $this->data = $data;
        $this->sectorSize     = 1 << $this->uint16(0x1E);
        $this->miniSectorSize = 1 << $this->uint16(0x20);

        if ($this->sectorSize < 128 || $this->sectorSize > 65536) {
            throw new RuntimeException("Implausible sector size: {$this->sectorSize}");
        }

        $this->readFat();
        $this->readDirectory();
    }

    public static function open(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        return new self($data);
    }

    /** @return array<int, string> */
    public function streamNames(): array
    {
        return array_keys($this->streams);
    }

    /** The bytes of a named stream, or null when it does not exist. */
    public function stream(string $name): ?string
    {
        $entry = $this->streams[$name] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry['size'] < self::MINI_CUTOFF) {
            return substr($this->readMiniChain($entry['start']), 0, $entry['size']);
        }

        return substr($this->readChain($entry['start']), 0, $entry['size']);
    }

    /**
     * The FAT, assembled from the sector list the DIFAT describes.
     *
     * The header carries the first 109 FAT sector numbers inline. Beyond that
     * the list continues in DIFAT sectors, each holding (sectorSize/4 - 1)
     * more entries plus a pointer to the next — which is why the last slot of
     * a DIFAT sector is a link and not data.
     */
    private function readFat(): void
    {
        $fatSectors = [];

        for ($i = 0; $i < 109; $i++) {
            $sector = $this->uint32(0x4C + $i * 4);
            if ($sector === self::FREE || $sector === self::END) {
                break;
            }
            $fatSectors[] = $sector;
        }

        $next  = $this->uint32(0x44);          // first DIFAT sector
        $count = $this->uint32(0x48);          // how many there are
        $perSector = intdiv($this->sectorSize, 4) - 1;
        $guard = 0;

        while ($next !== self::END && $next !== self::FREE && $guard++ < $count + 16) {
            $offset = $this->sectorOffset($next);
            for ($i = 0; $i < $perSector; $i++) {
                $sector = $this->uint32($offset + $i * 4);
                if ($sector === self::FREE || $sector === self::END) {
                    continue;
                }
                $fatSectors[] = $sector;
            }
            $next = $this->uint32($offset + $perSector * 4);
        }

        foreach ($fatSectors as $sector) {
            $offset = $this->sectorOffset($sector);
            for ($i = 0, $n = intdiv($this->sectorSize, 4); $i < $n; $i++) {
                $this->fat[] = $this->uint32($offset + $i * 4);
            }
        }

        if ($this->fat === []) {
            throw new RuntimeException('The compound file has an empty FAT.');
        }
    }

    /**
     * Directory entries, 128 bytes each, chained through the FAT.
     *
     * Entry 0 is the root. Its "stream" is the mini stream itself, which is
     * why the root is read for its start sector before anything else can
     * resolve a small stream.
     */
    private function readDirectory(): void
    {
        $directory = $this->readChain($this->uint32(0x30));
        $entries   = intdiv(strlen($directory), 128);

        $miniStart = null;
        $miniSize  = 0;

        for ($i = 0; $i < $entries; $i++) {
            $base       = $i * 128;
            $nameLength = $this->uint16At($directory, $base + 0x40);
            $type       = ord($directory[$base + 0x42]);

            if ($type === 0) {          // unallocated
                continue;
            }

            // UTF-16LE, and the length includes the terminating NUL.
            $raw  = substr($directory, $base, max(0, $nameLength - 2));
            $name = (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');

            $start = $this->uint32At($directory, $base + 0x74);
            $size  = $this->uint32At($directory, $base + 0x78);

            if ($type === 5) {          // root storage
                $miniStart = $start;
                $miniSize  = $size;
                continue;
            }

            if ($type === 2) {          // stream
                $this->streams[$name] = ['start' => $start, 'size' => $size];
            }
        }

        // The miniFAT chains sectors *within* the mini stream, and is itself a
        // normal chain in the main FAT.
        $miniFatStart = $this->uint32(0x3C);
        if ($miniFatStart !== self::END && $miniFatStart !== self::FREE) {
            $raw = $this->readChain($miniFatStart);
            for ($i = 0, $n = intdiv(strlen($raw), 4); $i < $n; $i++) {
                $this->miniFat[] = $this->uint32At($raw, $i * 4);
            }
        }

        if ($miniStart !== null && $miniStart !== self::END && $miniStart !== self::FREE && $miniSize > 0) {
            $this->miniStream = substr($this->readChain($miniStart), 0, $miniSize);
        }
    }

    /** Walk a FAT chain from a starting sector and concatenate its bytes. */
    private function readChain(int $start): string
    {
        $out    = '';
        $sector = $start;
        $seen   = [];

        while ($sector !== self::END && $sector !== self::FREE) {
            if ($sector === self::FAT_CHAIN || $sector === self::DIFAT) {
                break;
            }
            // A corrupt file can point a sector at itself. Without this the
            // loop allocates until memory_limit kills the request, which on
            // the server is a blank page rather than an error.
            if (isset($seen[$sector])) {
                throw new RuntimeException("Cyclic sector chain at sector {$sector}.");
            }
            $seen[$sector] = true;

            $out .= substr($this->data, $this->sectorOffset($sector), $this->sectorSize);

            $sector = $this->fat[$sector] ?? self::END;
        }

        return $out;
    }

    /** The same walk, against the miniFAT and the mini stream. */
    private function readMiniChain(int $start): string
    {
        $out    = '';
        $sector = $start;
        $seen   = [];

        while ($sector !== self::END && $sector !== self::FREE) {
            if (isset($seen[$sector])) {
                throw new RuntimeException("Cyclic mini-sector chain at sector {$sector}.");
            }
            $seen[$sector] = true;

            $out .= substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);

            $sector = $this->miniFat[$sector] ?? self::END;
        }

        return $out;
    }

    /** Sector N begins after the 512-byte header. */
    private function sectorOffset(int $sector): int
    {
        $offset = 512 + $sector * $this->sectorSize;
        if ($offset < 0 || $offset >= strlen($this->data)) {
            throw new RuntimeException("Sector {$sector} lies outside the file.");
        }

        return $offset;
    }

    private function uint16(int $offset): int
    {
        return $this->uint16At($this->data, $offset);
    }

    private function uint32(int $offset): int
    {
        return $this->uint32At($this->data, $offset);
    }

    private function uint16At(string $buffer, int $offset): int
    {
        if ($offset + 2 > strlen($buffer)) {
            throw new RuntimeException("Truncated file: wanted 2 bytes at {$offset}.");
        }
        /** @var array{1: int} $v */
        $v = unpack('v', substr($buffer, $offset, 2));

        return $v[1];
    }

    private function uint32At(string $buffer, int $offset): int
    {
        if ($offset + 4 > strlen($buffer)) {
            throw new RuntimeException("Truncated file: wanted 4 bytes at {$offset}.");
        }
        /** @var array{1: int} $v */
        $v = unpack('V', substr($buffer, $offset, 4));

        return $v[1];
    }
}
