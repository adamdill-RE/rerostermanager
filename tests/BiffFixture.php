<?php

declare(strict_types=1);

/**
 * Builds a minimal but genuine .xls — OLE2 container, BIFF8 records — so the
 * suite can test XlsReader without a committed binary.
 *
 * Two reasons this exists rather than a checked-in fixture file:
 *
 *   * A real roster is PII and this repository is public; CI fails the build
 *     if any .xls, .xlsx or .csv is tracked.
 *   * The real export uses only LABELSST, RK and MULBLANK. NUMBER, MULRK,
 *     FORMULA, STRING, BOOLERR and date-formatted cells are all code paths
 *     the sample never touches, and an untested path is where the next bug
 *     lives.
 *
 * **What this deliberately does not test: CONTINUE.** Writing the SST split
 * here would mean checking the reader against this file's author's own
 * understanding of the rule, which proves nothing if both are wrong. That path
 * is verified instead against the real 1,954-row export, whose string table
 * spans 32 CONTINUE records, cross-checked cell by cell against an independent
 * implementation. See docs/data-findings.md section 9.
 */
final class BiffFixture
{
    private const SECTOR = 512;
    private const ENDOFCHAIN = 0xFFFFFFFE;
    private const FATSECT    = 0xFFFFFFFD;
    private const FREESECT   = 0xFFFFFFFF;

    /** @var array<int, string> */
    private array $strings = [];

    /** @var array<int, string> Cell records for the single worksheet. */
    private array $cells = [];

    public function __construct(private readonly string $sheetName = 'Full Roster')
    {
    }

    /** A shared-string cell (LABELSST) — how almost every roster cell arrives. */
    public function label(int $row, int $col, string $text): self
    {
        $index = array_search($text, $this->strings, true);
        if ($index === false) {
            $this->strings[] = $text;
            $index = count($this->strings) - 1;
        }

        $this->cells[] = $this->record(0x00FD, pack('vvvV', $row, $col, 0, $index));

        return $this;
    }

    /** A full IEEE double (NUMBER). */
    public function number(int $row, int $col, float $value, int $xf = 0): self
    {
        $this->cells[] = $this->record(0x0203, pack('vvv', $row, $col, $xf) . pack('e', $value));

        return $this;
    }

    /** A packed integer (RK) — how the export stores Customer Number. */
    public function rkInt(int $row, int $col, int $value): self
    {
        $rk = (($value << 2) & 0xFFFFFFFF) | 0x02;
        $this->cells[] = $this->record(0x027E, pack('vvvV', $row, $col, 0, $rk));

        return $this;
    }

    /** An RK holding a value that was multiplied by 100 to fit. */
    public function rkScaled(int $row, int $col, float $value): self
    {
        $rk = (((int) round($value * 100) << 2) & 0xFFFFFFFF) | 0x03;
        $this->cells[] = $this->record(0x027E, pack('vvvV', $row, $col, 0, $rk));

        return $this;
    }

    /**
     * A run of RK cells sharing one row (MULRK).
     *
     * @param array<int, int> $values
     */
    public function mulRk(int $row, int $firstCol, array $values): self
    {
        $data = pack('vv', $row, $firstCol);
        foreach ($values as $value) {
            $data .= pack('v', 0) . pack('V', (($value << 2) & 0xFFFFFFFF) | 0x02);
        }
        $data .= pack('v', $firstCol + count($values) - 1);

        $this->cells[] = $this->record(0x00BD, $data);

        return $this;
    }

    /** A date, as a serial number wearing XF 1 (which maps to numFmtId 14). */
    public function date(int $row, int $col, string $ymd): self
    {
        $epoch  = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        $target = new DateTimeImmutable($ymd, new DateTimeZone('UTC'));
        $serial = (float) $epoch->diff($target)->days;

        return $this->number($row, $col, $serial, 1);
    }

    /** A formula whose cached result is a string, held in a following STRING. */
    public function formulaString(int $row, int $col, string $result): self
    {
        // Result bytes: [0]=0 means "string", [6..7]=0xFFFF marks it non-numeric.
        $cached = "\x00\x00\x00\x00\x00\x00\xFF\xFF";
        $this->cells[] = $this->record(
            0x0006,
            pack('vvv', $row, $col, 0) . $cached . pack('v', 0) . pack('V', 0) . pack('v', 0)
        );
        $this->cells[] = $this->record(0x0207, $this->unicodeString($result));

        return $this;
    }

    /** A formula with a numeric cached result. */
    public function formulaNumber(int $row, int $col, float $value): self
    {
        $this->cells[] = $this->record(
            0x0006,
            pack('vvv', $row, $col, 0) . pack('e', $value) . pack('v', 0) . pack('V', 0) . pack('v', 0)
        );

        return $this;
    }

    /** TRUE/FALSE or an error code (BOOLERR). */
    public function boolean(int $row, int $col, bool $value): self
    {
        $this->cells[] = $this->record(0x0205, pack('vvv', $row, $col, 0) . chr($value ? 1 : 0) . chr(0));

        return $this;
    }

    public function error(int $row, int $col, int $code): self
    {
        $this->cells[] = $this->record(0x0205, pack('vvv', $row, $col, 0) . chr($code) . chr(1));

        return $this;
    }

    /** An explicitly blank cell, which still occupies a record. */
    public function blank(int $row, int $col): self
    {
        $this->cells[] = $this->record(0x0201, pack('vvv', $row, $col, 0));

        return $this;
    }

    /** Writes the workbook and returns the path. */
    public function write(string $path): string
    {
        file_put_contents($path, $this->container($this->workbook()));

        return $path;
    }

    /** The BIFF8 Workbook stream: globals substream, then one worksheet. */
    private function workbook(): string
    {
        $sheet = $this->record(0x0809, pack('vvvvVV', 0x0600, 0x0010, 0, 0, 0, 0))
            . implode('', $this->cells)
            . $this->record(0x000A, '');

        // Two XF records: index 0 is General, index 1 is numFmtId 14, a date.
        $xf = $this->record(0x00E0, pack('vv', 0, 0) . str_repeat("\x00", 16))
            . $this->record(0x00E0, pack('vv', 0, 14) . str_repeat("\x00", 16));

        $sst = $this->sst();

        // BOUNDSHEET carries the byte offset of the sheet's BOF, so the
        // globals have to be measured before it can be written. Built once
        // with a placeholder to learn the length, then again for real.
        $build = function (int $offset) use ($xf, $sst): string {
            return $this->record(0x0809, pack('vvvvVV', 0x0600, 0x0005, 0, 0, 0, 0))
                . $xf
                . $sst
                . $this->record(
                    0x0085,
                    pack('V', $offset) . pack('v', 0) . $this->shortString($this->sheetName)
                )
                . $this->record(0x000A, '');
        };

        return $build(strlen($build(0))) . $sheet;
    }

    /** The shared string table. Single record by design — see the class note. */
    private function sst(): string
    {
        $body = '';
        foreach ($this->strings as $string) {
            $body .= $this->unicodeString($string);
        }

        $data = pack('VV', count($this->strings), count($this->strings)) . $body;

        if (strlen($data) > 8224) {
            throw new RuntimeException(
                'This fixture builder does not write CONTINUE records; keep the strings small. '
                . 'The CONTINUE path is verified against the real export instead.'
            );
        }

        return $this->record(0x00FC, $data);
    }

    /** uint16 length, uint8 flags, characters. Compressed when it can be. */
    private function unicodeString(string $text): string
    {
        $ascii = mb_check_encoding($text, 'ASCII');
        $chars = $ascii ? strlen($text) : mb_strlen($text, 'UTF-8');
        $bytes = $ascii ? $text : (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return pack('v', $chars) . chr($ascii ? 0x00 : 0x01) . $bytes;
    }

    private function shortString(string $text): string
    {
        return chr(strlen($text)) . "\x00" . $text;
    }

    private function record(int $opcode, string $data): string
    {
        return pack('vv', $opcode, strlen($data)) . $data;
    }

    /**
     * Wraps a stream in an OLE2 compound file.
     *
     * Deliberately the simplest layout that is still valid: the stream is
     * padded past 4096 bytes so it lives in the main FAT and no mini stream
     * has to be written, and the FAT fits in a single sector.
     */
    private function container(string $stream): string
    {
        // Streams under 4096 bytes belong in the mini stream, which this
        // writer does not implement. Padding avoids that branch entirely; the
        // directory entry records the true length, so the reader trims it.
        $trueSize = strlen($stream);
        $padded   = $trueSize < 4096
            ? $stream . str_repeat("\x00", 4096 - $trueSize)
            : $stream;
        $trueSize = max($trueSize, 4096);

        $dataSectors = (int) ceil(strlen($padded) / self::SECTOR);
        $padded     = str_pad($padded, $dataSectors * self::SECTOR, "\x00");

        $dirSector = $dataSectors;
        $fatSector = $dataSectors + 1;

        // FAT: the stream chains through its own sectors, then the directory
        // and the FAT sector each terminate.
        $fat = [];
        for ($i = 0; $i < $dataSectors; $i++) {
            $fat[$i] = $i === $dataSectors - 1 ? self::ENDOFCHAIN : $i + 1;
        }
        $fat[$dirSector] = self::ENDOFCHAIN;
        $fat[$fatSector] = self::FATSECT;

        $fatBytes = '';
        for ($i = 0, $n = intdiv(self::SECTOR, 4); $i < $n; $i++) {
            $fatBytes .= pack('V', $fat[$i] ?? self::FREESECT);
        }

        // Directory entries form a red-black tree, not a list: the root's
        // child field must point at entry 1 or a strict reader finds no
        // streams at all. (Our own CompoundFile scans the array linearly,
        // which is deliberately more forgiving — but writing a file only our
        // reader accepts would make this fixture worthless as a test.)
        $directory = $this->directoryEntry('Root Entry', 5, self::ENDOFCHAIN, 0, 1)
            . $this->directoryEntry('Workbook', 2, 0, $trueSize)
            . str_repeat("\x00", 256);

        $header  = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";   // signature
        $header .= str_repeat("\x00", 16);               // CLSID
        $header .= pack('vv', 0x003E, 0x0003);           // minor / major version
        $header .= pack('v', 0xFFFE);                    // little-endian marker
        $header .= pack('vv', 9, 6);                     // sector shifts: 512 / 64
        $header .= str_repeat("\x00", 6);
        $header .= pack('V', 0);                         // directory sector count
        $header .= pack('V', 1);                         // FAT sector count
        $header .= pack('V', $dirSector);                // first directory sector
        $header .= pack('V', 0);                         // transaction signature
        $header .= pack('V', 4096);                      // mini stream cutoff
        $header .= pack('V', self::ENDOFCHAIN);          // first miniFAT sector
        $header .= pack('V', 0);                         // miniFAT sector count
        $header .= pack('V', self::ENDOFCHAIN);          // first DIFAT sector
        $header .= pack('V', 0);                         // DIFAT sector count
        $header .= pack('V', $fatSector);                // DIFAT[0]
        for ($i = 1; $i < 109; $i++) {
            $header .= pack('V', self::FREESECT);
        }

        return $header . $padded . str_pad($directory, self::SECTOR, "\x00") . $fatBytes;
    }

    private function directoryEntry(
        string $name,
        int $type,
        int $start,
        int $size,
        int $child = self::FREESECT
    ): string {
        $utf16 = (string) mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');

        $entry  = str_pad($utf16 . "\x00\x00", 64, "\x00");
        $entry .= pack('v', strlen($utf16) + 2);   // name length, including NUL
        $entry .= chr($type);
        $entry .= chr(1);                           // colour: black
        $entry .= pack('VVV', self::FREESECT, self::FREESECT, $child); // left, right, child
        $entry .= str_repeat("\x00", 16);           // CLSID
        $entry .= str_repeat("\x00", 4);            // state bits
        $entry .= str_repeat("\x00", 16);           // creation / modification time
        $entry .= pack('V', $start);
        $entry .= pack('V', $size);
        $entry .= pack('V', 0);                     // high half of a 64-bit size

        return $entry;
    }
}
