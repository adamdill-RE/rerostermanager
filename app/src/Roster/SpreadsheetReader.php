<?php

declare(strict_types=1);

namespace Rerm\Roster;

/**
 * A roster file, read as rows of strings.
 *
 * Three implementations, one for each way Rodeo Houston has sent us a roster:
 * CsvReader, XlsxReader and XlsReader. The import (spec 6) talks only to this
 * interface, so the file format is a detail settled at the door.
 *
 * **Every value is a string.** That is the whole contract, and it exists for
 * one column: `Customer Number` is the natural key, it is six or seven digits,
 * and PHP reading it as a float turns 1234567 into 1234567.0 and a hypothetical
 * larger one into 1.0E+15. It is an identifier, never arithmetic. A blank cell
 * is '', never null, so a row is always a dense array and a caller can index
 * it by column position without checking.
 */
interface SpreadsheetReader
{
    /**
     * Sheet names in workbook order. A CSV reports one, named after the file.
     *
     * @return array<int, string>
     */
    public function sheets(): array;

    /**
     * Rows of the named sheet, or the first sheet when null.
     *
     * Generated rather than returned as an array: a roster is ~1,950 rows of
     * 33 columns and `memory_limit` is 128M on the server, shared with
     * whatever the import is building. Nothing here holds more than one row.
     *
     * Each row is zero-indexed by column and padded to the widest column seen
     * in the header, so trailing empty cells are present rather than missing.
     *
     * @return iterable<int, array<int, string>>
     */
    public function rows(?string $sheet = null): iterable;
}
