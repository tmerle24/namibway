<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillPartnersFromListings extends Command
{
    protected $signature = 'namibway:backfill-partners
                            {--dry-run : Show what would be created without writing to DB}';

    protected $description = 'Create Partner rows with fresh claim tokens for listings that have none (post data-loss recovery)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $listings = Listing::whereNull('partner_id')->get();

        if ($listings->isEmpty()) {
            $this->info('No listings without a partner_id — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$listings->count()} listings without a partner.");

        $created = 0;
        $reused = 0;
        $bar = $this->output->createProgressBar($listings->count());
        $bar->start();

        foreach ($listings as $listing) {
            $partner = null;

            if ($listing->source_url) {
                $partner = Partner::where('source_url', $listing->source_url)->first();
            }

            if (! $partner && $listing->website) {
                $partner = Partner::where('website', $listing->website)->first();
            }

            if ($partner) {
                $reused++;
            } else {
                $created++;

                if (! $dry) {
                    $partner = Partner::create([
                        'name' => $listing->getTranslation('name', 'en') ?: $listing->name,
                        'email' => $listing->contact_email,
                        'phone' => $listing->phone,
                        'website' => $listing->website,
                        'source_url' => $listing->source_url,
                        'claim_token' => Str::random(48),
                    ]);
                }
            }

            if (! $dry && $partner) {
                $listing->update(['partner_id' => $partner->id]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(['Partners created', 'Partners reused'], [[$created, $reused]]);

        return self::SUCCESS;
    }
}
