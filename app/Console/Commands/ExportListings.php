<?php

namespace App\Console\Commands;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Services\ImportExport\ListingExporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Writes the listings workbook (see App\Services\ImportExport\ListingSheet).
 * The same export is available in the admin panel — this exists for large or
 * scripted exports, and for the coordinate round trip described in
 * TRAVEL_PLAN.md: export what's missing, fill it in, import it back.
 */
class ExportListings extends Command
{
    protected $signature = 'namibway:export-listings
                            {--out= : Target .xlsx path (default: storage/app/exports/listings-<date>.xlsx)}
                            {--type= : Only this listing type (accommodation, activity, restaurant, vehicle)}
                            {--missing-coordinates : Only listings without latitude/longitude}
                            {--unpublished : Only unpublished listings}
                            {--template : Write an empty capture template instead of the data}';

    protected $description = 'Export listings to an Excel workbook that can be edited and imported back';

    public function handle(ListingExporter $exporter): int
    {
        $path = $this->resolvePath();

        if ($this->option('template')) {
            $exporter->template($path);
            $this->info("Template written: {$path}");

            return self::SUCCESS;
        }

        $query = Listing::query()->orderBy('id');

        if (is_string($type = $this->option('type')) && $type !== '') {
            $listingType = ListingType::tryFrom($type);

            if ($listingType === null) {
                $this->error("Unknown type \"{$type}\".");

                return self::FAILURE;
            }

            $query->where('type', $listingType);
        }

        if ($this->option('missing-coordinates')) {
            $query->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude'));
        }

        if ($this->option('unpublished')) {
            $query->where('is_published', false);
        }

        $count = $exporter->export($query, $path);

        $this->info("{$count} listing(s) exported: {$path}");

        return self::SUCCESS;
    }

    private function resolvePath(): string
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return $out;
        }

        $directory = storage_path('app/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $name = $this->option('template') ? 'listings-template' : 'listings-'.now()->format('Y-m-d-Hi');

        return "{$directory}/{$name}.xlsx";
    }
}
