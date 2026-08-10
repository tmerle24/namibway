<?php

namespace App\Services\ImportExport;

/**
 * One column of the listings workbook. `header` is what the exporter writes;
 * `aliases` are additionally accepted on import so a sheet keeps working when
 * someone renames a header to the database field name or its English label.
 */
final class SheetColumn
{
    /**
     * @param  string  $attribute  Listing attribute this column maps to ('' for virtual columns).
     * @param  list<string>  $aliases  Extra headers accepted on import (normalized like the header).
     */
    public function __construct(
        public readonly string $header,
        public readonly SheetColumnType $type,
        public readonly string $attribute,
        public readonly string $help,
        public readonly array $aliases = [],
        public readonly bool $requiredForNew = false,
        public readonly float $width = 22,
    ) {}
}
