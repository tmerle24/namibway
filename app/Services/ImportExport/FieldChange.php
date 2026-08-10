<?php

namespace App\Services\ImportExport;

/**
 * A single field the import would change, in sheet dialect (header name, values
 * formatted the way the workbook shows them) so the preview reads like the file
 * the content team is looking at.
 */
final class FieldChange
{
    public function __construct(
        public readonly string $column,
        public readonly ?string $old,
        public readonly ?string $new,
    ) {}
}
