<?php

namespace App\Console\Commands;

use App\Enums\BusinessType;
use App\Enums\ContentSource;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Sites\Generation\GenerationReport;
use App\Sites\Generation\SiteGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
        {--partner= : Partner id or email; a new partner is created from --name when omitted}
        {--force : Discard generated content and rebuild. Refuses on a published site}
        {--list : Show the sites that exist and do nothing else}
        {--candidates : Show listings that would make a presentable site, and do nothing else}';

    protected $description = 'Generate a customer website from a listing, or empty for a business without one';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->showExisting();
        }

        if ($this->option('candidates')) {
            return $this->showCandidates();
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
            $this->line('  php artisan sites:generate --name="Swakop Auto Electric" --type=service --partner=42');

            return null;
        }

        $type = BusinessType::tryFrom((string) $this->option('type'));

        if ($type === null) {
            $this->error('Pass --type as one of: '.implode(', ', array_column(BusinessType::cases(), 'value')).'.');

            return null;
        }

        $partnerRef = $this->option('partner');

        if (is_string($partnerRef) && trim($partnerRef) !== '') {
            $partner = Partner::where('id', ctype_digit($partnerRef) ? (int) $partnerRef : 0)
                ->orWhere('email', $partnerRef)
                ->first();

            if ($partner === null) {
                $this->error("No partner matches [{$partnerRef}].");

                return null;
            }
        } else {
            $partner = Partner::create(['name' => trim($name)]);
        }

        return $generator->empty(trim($name), $type, $partner);
    }

    /**
     * Listings worth building a draft from.
     *
     * The prospecting tool is only as good as what it is pointed at, and most
     * of this catalogue came from directories — whose text and photographs we
     * may read and may not publish. A draft built from one of those is
     * technically correct and looks like nothing, which is the wrong thing to
     * put in front of a business owner.
     */
    private function showCandidates(): int
    {
        $listings = Listing::query()
            ->whereNotNull('image')
            ->where('image', 'not like', '%images.unsplash.com%')
            ->where(fn ($q) => $q->whereNull('photos_source')
                ->orWhere('photos_source', '!=', ContentSource::Directory->value))
            ->where(fn ($q) => $q->whereNull('description_source')
                ->orWhere('description_source', '!=', ContentSource::Directory->value))
            ->whereRaw('coalesce(length(cast(description as text)), 0) > 120')
            ->orderByDesc('enrichment_score')
            ->limit(25)
            ->get();

        if ($listings->isEmpty()) {
            $this->warn('No listing currently holds both a publishable photograph and publishable text.');
            $this->line('  That is a content problem, not a bug — the catalogue is mostly directory-sourced.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Type', 'Photos', 'Score'],
            $listings->map(fn (Listing $listing) => [
                $listing->slug,
                Str::limit((string) $listing->name, 34),
                $listing->type->value,
                1 + count(is_array($listing->gallery) ? $listing->gallery : []),
                $listing->enrichment_score,
            ])->all(),
        );

        $this->line('  Build one with: php artisan sites:generate <slug>');

        return self::SUCCESS;
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

        // Said plainly, because the page itself will not say it. A listing with
        // no publishable photograph and no publishable text produces a site
        // that is correct and looks like nothing, and somebody has to know that
        // before showing it to the business it is about.
        if ($report->imagesCopied === 0 && $this->hasNoPublishedText($site)) {
            $this->newLine();
            $this->warn('  This listing had nothing we may publish — no photograph and no text.');
            $this->line('  The page will be close to empty. Find a better starting point with:');
            $this->line('    php artisan sites:generate --candidates');
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

    private function hasNoPublishedText(Site $site): bool
    {
        $about = $site->pages()->first()?->blocks()->where('type', 'about')->first();

        return blank($about?->data['body'] ?? null);
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
