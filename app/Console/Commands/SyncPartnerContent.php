<?php

namespace App\Console\Commands;

use App\Connectors\ConnectorFactory;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SyncPartnerContent extends Command
{
    protected $signature = 'partners:sync-content {--listing= : Only sync a specific listing ID}';

    protected $description = 'Fetch and sync property content from Wetu for all linked listings';

    public function handle(): int
    {
        $query = Listing::with('partner')
            ->whereNotNull('wetu_id')
            ->whereHas('partner');

        if ($listingId = $this->option('listing')) {
            $query->where('id', $listingId);
        }

        $listings = $query->get();

        if ($listings->isEmpty()) {
            $this->info('No listings with a Wetu ID found.');

            return self::SUCCESS;
        }

        $this->info("Syncing content for {$listings->count()} listing(s)...");
        $synced = 0;
        $failed = 0;

        foreach ($listings as $listing) {
            $partner = $listing->partner;

            try {
                $connector = ConnectorFactory::makeContent($partner);
            } catch (InvalidArgumentException) {
                $this->warn("  [{$listing->id}] {$listing->name}: partner has no content connector, skipping");

                continue;
            }

            try {
                $content = $connector->fetchPropertyContent($listing->wetu_id);

                if (blank($content->name)) {
                    $this->warn("  [{$listing->id}] {$listing->name}: Wetu returned empty content for ID {$listing->wetu_id}");
                    $failed++;

                    continue;
                }

                $listing->update([
                    'name' => $content->name,
                    'description' => $content->description,
                    'highlights' => $content->highlights ?: null,
                    'region' => $content->region ?? $listing->region,
                    'latitude' => $content->latitude ?? $listing->latitude,
                    'longitude' => $content->longitude ?? $listing->longitude,
                    'content_synced_at' => now(),
                ]);

                $this->line("  [{$listing->id}] ✓ {$content->name}");
                $synced++;
            } catch (Throwable $e) {
                $this->error("  [{$listing->id}] {$listing->name}: {$e->getMessage()}");
                Log::error("SyncPartnerContent failed for listing [{$listing->id}]: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Synced: {$synced}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
