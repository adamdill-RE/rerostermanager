<?php

declare(strict_types=1);

namespace Rerm\Roster;

use RuntimeException;

/**
 * Reads a legacy .xls — BIFF8 records inside an OLE2 container.
 *
 * This is the format Rodeo Houston actually sends (docs/data-findings.md), so
 * it is not an optional convenience: asking an administrator to open every
 * roster in Excel and re-save it as CSV is a manual step before a 1,954-row
 * import, and the step people forget is the one that matters.
 *
 * CompoundFile does the container. What arrives here is the "Workbook" stream:
 * a flat sequence of records, each a uint16 opcode, a uint16 length, and that
 * many bytes. Records are read in two passes — the globals substream for the
 * sheet index, the string table and the format table, then each sheet's own
 * substream for its cells.
 *
 * Four things in this format are traps, and three of them are silent:
 *
 *   * **The shared string table spans CONTINUE records**, and a single string
 *     can be cut in half by one. Worse, the byte after the split re-declares
 *     whether the remainder is 8-bit or 16-bit — so compression can change
 *     mid-string. Ignoring that yields mojibake in the middle of a name, on
 *     roughly one roster in three, depending only on where the 8,224-byte
 *     boundary happens to land.
 *   * **Numbers arrive four different ways**: NUMBER (a real double), RK (a
 *     packed 30-bit thing that is either a small integer or the top bits of a
 *     double, optionally divided by 100), MULRK (a run of RKs sharing a row),
 *     and FORMULA (whose cached result may be a number, or a pointer to a
 *     STRING record that follows it).
 *   * **Blank cells emit records too** — or no record at all. Neither can be
 *     read positionally.
 *   * **Dates are doubles wearing a format**, exactly as in .xlsx, so the XF
 *     and FORMAT tables have to be read to tell 45000 from a date.
 */
final class XlsReader implements SpreadsheetReader
{
    // Globals
    private const BOF        = 0x0809;
    private const EOF_REC    = 0x000A;
    private const BOUNDSHEET = 0x0085;
    private const SST        = 0x00FC;
    private const CONTINUE   = 0x003C;
    private const FORMAT     = 0x041E;
    private const XF         = 0x00E0;
    private const DATEMODE   = 0x0022;

    // Cells
    private const LABELSST = 0x00FD;
    private const LABEL    = 0x0204;
    private const NUMBER   = 0x0203;
    private const RK       = 0x027E;
    private const MULRK    = 0x00BD;
    private const BLANK    = 0x0201;
    private const MULBLANK = 0x00BE;
    private const FORMULA  = 0x0006;
    private const STRING   = 0x0207;
    private const BOOLERR  = 0x0205;

    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    private string $book;

    /** @var array<int, array{name: string, offset: int}> */
    private array $sheets = [];

    /** @var array<int, string> */
    private array $sst = [];

    /** @var array<int, int> XF index => number-format id. */
    private array $xf = [];

    /** @var array<int, string> Number-format id => format code. */
    private array $formats = [];

    /** 1904 date system, used by some Mac-authored workbooks. */
    private bool $date1904 = false;

    public function __construct(string $path)
    {
        $container = CompoundFile::open($path);

        // "Workbook" is BIFF8; "Book" is BIFF5/7 and this parser does not
        // claim to read it. Saying so beats producing half a roster.
        $book = $container->stream('Workbook');
        if ($book === null) {
            if ($container->stream('Book') !== null) {
                throw new RuntimeException(
                    'This is an Excel 5.0/95 workbook, which this reader does not support. '
                    . 'Re-save it as "Excel Workbook (.xlsx)" or CSV.'
                );
            }
            throw new RuntimeException(sprintf(
                'No Workbook stream in this compound file. It holds: %s',
                implode(', ', $container->streamNames()) ?: '(nothing)'
            ));
        }

        $this->book = $book;
        $this->readGlobals();
    }

    /** @return array<int, string> */
    public function sheets(): array
    {
        return array_map(static fn (array $s): string => $s['name'], $this->sheets);
    }

    /** @return iterable<int, array<int, string>> */
    public function rows(?string $sheet = null): iterable
    {
        $target = null;
        foreach ($this->sheets as $candidate) {
            if ($sheet === null || $candidate['name'] === $sheet) {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException(sprintf(
                'No sheet named "%s". This workbook has: %s',
                (string) $sheet,
                implode(', ', $this->sheets())
            ));
        }

        yield from $this->readSheet($target['offset']);
    }

    /**
     * The globals substream: sheet index, string table, formats.
     */
    private function readGlobals(): void
    {
        $position = 0;
        $length   = strlen($this->book);

        while ($position + 4 <= $length) {
            [$opcode, , $data, $next] = $this->record($position);

            switch ($opcode) {
                case self::BOUNDSHEET:
                    // uint32 stream offset of this sheet's BOF, then two bytes
                    // of visibility and type, then a short unicode string.
                    $offset = $this->u32($data, 0);
                    $name   = $this->shortString($data, 6);
                    $this->sheets[] = ['name' => $name, 'offset' => $offset];
                    break;

                case self::SST:
                    // Consumes its own CONTINUE records, so read() resumes
                    // after the last of them.
                    $next = $this->readSst($position);
                    break;

                case self::FORMAT:
                    $this->formats[$this->u16($data, 0)] = $this->longString($data, 2);
                    break;

                case self::XF:
                    // ifnt(2) ifmt(2) — the number-format id is at offset 2.
                    $this->xf[] = $this->u16($data, 2);
                    break;

                case self::DATEMODE:
                    $this->date1904 = $this->u16($data, 0) === 1;
                    break;

                case self::EOF_REC:
                    // End of the globals substream. Everything after this is
                    // per-sheet and is read on demand.
                    return;
            }

            $position = $next;
        }
    }

    /**
     * The shared string table, reassembled across CONTINUE records.
     *
     * Returns the stream offset just past the last CONTINUE consumed.
     */
    private function readSst(int $position): int
    {
        // Concatenate the SST payload with every CONTINUE that follows it,
        // remembering where each CONTINUE's payload starts. Those offsets are
        // exactly the points at which a string may switch encoding.
        [, , $data, $next] = $this->record($position);

        $buffer     = $data;
        $boundaries = [];
        $length     = strlen($this->book);

        while ($next + 4 <= $length) {
            [$opcode, , $more, $after] = $this->record($next);
            if ($opcode !== self::CONTINUE) {
                break;
            }
            $boundaries[strlen($buffer)] = true;
            $buffer .= $more;
            $next = $after;
        }

        $unique = $this->u32($buffer, 4);
        $cursor = 8;

        for ($i = 0; $i < $unique; $i++) {
            if ($cursor + 3 > strlen($buffer)) {
                break;      // Truncated table; keep what parsed.
            }
            $this->sst[] = $this->richString($buffer, $cursor, $boundaries);
        }

        return $next;
    }

    /**
     * One XLUnicodeRichExtendedString, advancing $cursor past it.
     *
     * @param array<int, bool> $boundaries Offsets where a CONTINUE payload begins.
     */
    private function richString(string $buffer, int &$cursor, array $boundaries): string
    {
        $chars  = $this->u16($buffer, $cursor);
        $flags  = ord($buffer[$cursor + 2]);
        $cursor += 3;

        $wide     = ($flags & 0x01) === 0x01;   // 16-bit rather than compressed
        $rich     = ($flags & 0x08) === 0x08;
        $extended = ($flags & 0x04) === 0x04;

        $runs   = 0;
        $extLen = 0;

        if ($rich) {
            $runs = $this->u16($buffer, $cursor);
            $cursor += 2;
        }
        if ($extended) {
            $extLen = $this->u32($buffer, $cursor);
            $cursor += 4;
        }

        $parts     = [];
        $remaining = $chars;
        $total     = strlen($buffer);

        while ($remaining > 0 && $cursor < $total) {
            // How far until the next CONTINUE boundary — beyond that point the
            // encoding may change and a fixed-width read would be wrong.
            $limit = $total;
            foreach ($boundaries as $offset => $_) {
                if ($offset > $cursor && $offset < $limit) {
                    $limit = $offset;
                }
            }

            $width     = $wide ? 2 : 1;
            $available = intdiv($limit - $cursor, $width);
            $take      = min($remaining, $available);

            if ($take > 0) {
                $raw = substr($buffer, $cursor, $take * $width);
                $parts[] = $wide
                    ? (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                    : (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
                $cursor    += $take * $width;
                $remaining -= $take;
            }

            if ($remaining > 0) {
                // Sitting on a boundary: the first byte of the continuation
                // re-declares the encoding for the rest of this string.
                if ($cursor >= $total) {
                    break;
                }
                $wide = (ord($buffer[$cursor]) & 0x01) === 0x01;
                $cursor++;
            }
        }

        // Formatting runs and phonetic data trail the characters and are not
        // part of the value, but they do have to be stepped over.
        $cursor += $runs * 4;
        $cursor += $extLen;

        return implode('', $parts);
    }

    /**
     * @return iterable<int, array<int, string>>
     */
    private function readSheet(int $offset): iterable
    {
        $length   = strlen($this->book);
        $position = $offset;

        /** @var array<int, array<int, string>> $cells */
        $cells   = [];
        $maxCol  = -1;
        $started = false;

        /** @var array{row: int, col: int}|null $pendingString */
        $pendingString = null;

        while ($position + 4 <= $length) {
            [$opcode, , $data, $next] = $this->record($position);
            $position = $next;

            if ($opcode === self::BOF) {
                $started = true;
                continue;
            }
            if (!$started) {
                continue;
            }
            if ($opcode === self::EOF_REC) {
                break;
            }

            // A FORMULA whose result is a string is followed by a STRING
            // record carrying it. Resolved here so the pairing cannot be
            // broken by an intervening record.
            if ($pendingString !== null) {
                if ($opcode === self::STRING) {
                    // A STRING record is nothing but the string, from byte 0.
                    $cells[$pendingString['row']][$pendingString['col']] = $this->longString($data, 0);
                    $maxCol = max($maxCol, $pendingString['col']);
                    $pendingString = null;
                    continue;
                }
                // The pairing is supposed to be immediate. If anything else
                // intervenes the cached result is unrecoverable, so the cell
                // stays empty rather than picking up an unrelated string.
                $pendingString = null;
            }

            switch ($opcode) {
                case self::LABELSST:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    $cells[$row][$col] = $this->sst[$this->u32($data, 6)] ?? '';
                    $maxCol = max($maxCol, $col);
                    break;

                case self::LABEL:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    $cells[$row][$col] = $this->longString($data, 6);
                    $maxCol = max($maxCol, $col);
                    break;

                case self::NUMBER:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    $cells[$row][$col] = $this->formatNumber(
                        $this->double($data, 6),
                        $this->u16($data, 4)
                    );
                    $maxCol = max($maxCol, $col);
                    break;

                case self::RK:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    $cells[$row][$col] = $this->formatNumber(
                        self::decodeRk($this->u32($data, 6)),
                        $this->u16($data, 4)
                    );
                    $maxCol = max($maxCol, $col);
                    break;

                case self::MULRK:
                    // row(2) colFirst(2) then (xf(2) rk(4))... then colLast(2)
                    $row   = $this->u16($data, 0);
                    $first = $this->u16($data, 2);
                    $count = intdiv(strlen($data) - 6, 6);
                    for ($i = 0; $i < $count; $i++) {
                        $base = 4 + $i * 6;
                        $col  = $first + $i;
                        $cells[$row][$col] = $this->formatNumber(
                            self::decodeRk($this->u32($data, $base + 2)),
                            $this->u16($data, $base)
                        );
                        $maxCol = max($maxCol, $col);
                    }
                    break;

                case self::BOOLERR:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    $isError = ord($data[7]) === 1;
                    $value   = ord($data[6]);
                    $cells[$row][$col] = $isError
                        ? self::errorText($value)
                        : ($value === 1 ? 'TRUE' : 'FALSE');
                    $maxCol = max($maxCol, $col);
                    break;

                case self::BLANK:
                    $maxCol = max($maxCol, $this->u16($data, 2));
                    break;

                case self::MULBLANK:
                    $maxCol = max($maxCol, $this->u16($data, strlen($data) - 2));
                    break;

                case self::FORMULA:
                    $row = $this->u16($data, 0);
                    $col = $this->u16($data, 2);
                    // Bytes 6..13 are the cached result. 0xFFFF in the last
                    // two marks it as non-numeric, and byte 6 says which kind.
                    if ($this->u16($data, 12) === 0xFFFF) {
                        $kind = ord($data[6]);
                        if ($kind === 0) {
                            // String, arriving in the next STRING record.
                            $pendingString = ['row' => $row, 'col' => $col];
                        } elseif ($kind === 1) {
                            $cells[$row][$col] = ord($data[8]) === 1 ? 'TRUE' : 'FALSE';
                        } elseif ($kind === 2) {
                            $cells[$row][$col] = self::errorText(ord($data[8]));
                        } else {
                            $cells[$row][$col] = '';    // empty string result
                        }
                    } else {
                        $cells[$row][$col] = $this->formatNumber(
                            $this->double($data, 6),
                            $this->u16($data, 4)
                        );
                    }
                    $maxCol = max($maxCol, $col);
                    break;
            }
        }

        if ($cells === []) {
            return;
        }

        // Cells are addressed, not ordered, so the sheet is assembled and then
        // walked in row order. ~64,000 cells for a full roster, which is well
        // inside the 128M limit and far simpler than trusting write order.
        ksort($cells);
        $width = $maxCol + 1;

        foreach ($cells as $cells_row) {
            $out = [];
            for ($col = 0; $col < $width; $col++) {
                $out[$col] = $cells_row[$col] ?? '';
            }
            yield $out;
        }
    }

    /**
     * Opcode, length, payload and the offset of the next record.
     *
     * @return array{0: int, 1: int, 2: string, 3: int}
     */
    private function record(int $position): array
    {
        $opcode = $this->u16($this->book, $position);
        $size   = $this->u16($this->book, $position + 2);
        $data   = substr($this->book, $position + 4, $size);

        return [$opcode, $size, $data, $position + 4 + $size];
    }

    /** A number is only a date if its format says so. */
    private function formatNumber(float $value, int $xfIndex): string
    {
        $formatId = $this->xf[$xfIndex] ?? 0;

        if ($this->isDateFormat($formatId)) {
            return $this->serialToDate($value);
        }

        // An integral double is written without a decimal point: a Customer
        // Number must round-trip as 1234567, never 1234567.0.
        if ($value === floor($value) && abs($value) < 9.007199254740992E15) {
            return (string) (int) $value;
        }

        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    private function isDateFormat(int $formatId): bool
    {
        if (isset($this->formats[$formatId])) {
            $bare = (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $this->formats[$formatId]);

            return preg_match('/[ymd]/i', $bare) === 1;
        }

        return in_array($formatId, self::DATE_FORMATS, true);
    }

    /** Same 1899-12-30 anchor as .xlsx, plus the Mac 1904 variant. */
    private function serialToDate(float $serial): string
    {
        $epoch   = $this->date1904 ? '1904-01-01' : '1899-12-30';
        $days    = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);

        $date = (new \DateTimeImmutable($epoch, new \DateTimeZone('UTC')))
            ->modify("+{$days} days")
            ->modify("+{$seconds} seconds");

        return $seconds === 0 ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
    }

    /**
     * An RK is 30 bits of number plus two flag bits: bit 1 says it is an
     * integer rather than the top half of a double, and bit 0 says the result
     * was multiplied by 100 to fit.
     */
    private static function decodeRk(int $rk): float
    {
        $isInteger    = ($rk & 0x02) === 0x02;
        $wasScaled    = ($rk & 0x01) === 0x01;

        if ($isInteger) {
            $value = $rk >> 2;
            if (($value & 0x20000000) !== 0) {      // 30-bit two's complement
                $value -= 0x40000000;
            }
            $value = (float) $value;
        } else {
            // The 30 bits are the *high* bits of an IEEE-754 double; the low
            // 34 are zero.
            /** @var array{1: float} $unpacked */
            $unpacked = unpack('e', pack('VV', 0, $rk & 0xFFFFFFFC));
            $value    = $unpacked[1];
        }

        return $wasScaled ? $value / 100 : $value;
    }

    private static function errorText(int $code): string
    {
        return match ($code) {
            0x00 => '#NULL!',
            0x07 => '#DIV/0!',
            0x0F => '#VALUE!',
            0x17 => '#REF!',
            0x1D => '#NAME?',
            0x24 => '#NUM!',
            0x2A => '#N/A',
            default => '#ERR',
        };
    }

    /** uint8 length, uint8 flags, then characters. Used by BOUNDSHEET. */
    private function shortString(string $data, int $offset): string
    {
        if ($offset + 2 > strlen($data)) {
            return '';
        }

        $chars = ord($data[$offset]);
        $wide  = (ord($data[$offset + 1]) & 0x01) === 0x01;
        $raw   = substr($data, $offset + 2, $chars * ($wide ? 2 : 1));

        return (string) mb_convert_encoding($raw, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
    }

    /** uint16 length, uint8 flags, then characters. Used by LABEL and FORMAT. */
    private function longString(string $data, int $offset): string
    {
        if ($offset + 3 > strlen($data)) {
            return '';
        }

        $chars = $this->u16($data, $offset);
        $wide  = (ord($data[$offset + 2]) & 0x01) === 0x01;
        $raw   = substr($data, $offset + 3, $chars * ($wide ? 2 : 1));

        return (string) mb_convert_encoding($raw, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
    }

    private function u16(string $buffer, int $offset): int
    {
        if ($offset + 2 > strlen($buffer)) {
            throw new RuntimeException("Truncated record: wanted 2 bytes at {$offset}.");
        }
        /** @var array{1: int} $v */
        $v = unpack('v', substr($buffer, $offset, 2));

        return $v[1];
    }

    private function u32(string $buffer, int $offset): int
    {
        if ($offset + 4 > strlen($buffer)) {
            throw new RuntimeException("Truncated record: wanted 4 bytes at {$offset}.");
        }
        /** @var array{1: int} $v */
        $v = unpack('V', substr($buffer, $offset, 4));

        return $v[1];
    }

    private function double(string $buffer, int $offset): float
    {
        if ($offset + 8 > strlen($buffer)) {
            throw new RuntimeException("Truncated record: wanted 8 bytes at {$offset}.");
        }
        /** @var array{1: float} $v */
        $v = unpack('e', substr($buffer, $offset, 8));

        return $v[1];
    }
}
