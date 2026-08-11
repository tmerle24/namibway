<?php

namespace App\Console\Commands;

use App\Enums\BusinessType;
use App\Models\Listing;
use App\Models\Site;
use App\Sites\Generation\GenerationReport;
use App\Sites\Generation\SiteGenerator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds a customer website — from a listing, or from nothing.
 *
 * This is the prospecting tool. A draft that already contains a business's own
 * photographs and their own words is a very different conversation from a
 * mock-up, and it takes seconds rather than the week the flyer promises.
 *
 * It reports what it could not fill and why, because that list is what somebody
 * works through before the meeting.
 */
class GenerateSite extends Command
{
    protected $signature = 'sites:generate
        {listing? : Slug or id of a listing to build the site from}
        {--name= : Business name, for a customer with no listing}
        {--type= : Business type, for a customer with no listing}
        {--force : Discard generated content and rebuild. Refuses on a published site}
        {--list : Show the sites that exist and do nothing else}';

    protected $description = 'Generate a customer website from a listing, or empty for a business without one';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->showExisting();
        }

        $generator = new SiteGenerator;

        try {
            $site = filled($this->argument('listing'))
                ? $this->fromListing($generator)
                : $this->fromNothing($generator);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($site === null) {
            return self::FAILURE;
        }

        $this->summarise($site, $generator->report());

        return self::SUCCESS;
    }

    private function fromListing(SiteGenerator $generator): ?Site
    {
        $reference = (string) $this->argument('listing');
        $listing = Listing::where('slug', $reference)->orWhere('id', ctype_digit($reference) ? (int) $reference : 0)->first();

        if ($listing === null) {
            $this->error("No listing matches [{$reference}].");

            return null;
        }

        return $generator->fromListing($listing, (bool) $this->option('force'));
    }

    private function fromNothing(SiteGenerator $generator): ?Site
    {
        $name = $this->option('name');

        if (! is_string($name) || trim($name) === '') {
            $this->error('Name a listing to build from, or pass --name and --type for a business without one.');
            $this->line('  php artisan sites:generate okonjima-bush-camp');
            $this->line('  php artisan sites:generate --name="Swakop Auto Electric" --type=service');

            return null;
        }

        $type = BusinessType::tryFrom((string) $this->option('type'));

        if ($type === null) {
            $this->error('Pass --type as one of: '.implode(', ', array_column(BusinessType::cases(), 'value')).'.');

            return null;
        }

        return $generator->empty(trim($name), $type);
    }

    private function summarise(Site $site, GenerationReport $report): void
    {
        $this->newLine();
        $this->info($site->name);
        $this->line('  '.$site->publicUrl());
        $this->line('  '.$site->business_type->getLabel().($site->host ? ' · '.$site->host : ' · no host yet'));

        if ($report->imagesCopied > 0) {
            $this->line("  {$report->imagesCopied} photographs copied into the site's own storage");
        }

        $this->section('Left empty', array_map(
            fn (array $row) => $row['field'].' — '.$row['reason'],
            $report->skipped,
        ));

        $this->section('Kept as edited', array_map(
            fn (array $row) => $row['field'].' — '.$row['reason'],
            $report->kept,
        ));

        $this->section('Shortened to fit', $report->shortened);

        $this->section('Blocks with nothing to show yet', $report->disabledBlocks);

        $this->newLine();
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function section(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();
        $this->line("  <comment>{$title}</comment>");

        foreach (array_unique($lines) as $line) {
            $this->line('    · '.$line);
        }
    }

    private function showExisting(): int
    {
        $sites = Site::orderBy('name')->get();

        if ($sites->isEmpty()) {
            $this->line('No sites yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Type', 'Status', 'Host', 'Address'],
            $sites->map(fn (Site $site) => [
                $site->name,
                $site->business_type->value,
                $site->status->value,
                $site->host ?? '—',
                $site->publicUrl(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
