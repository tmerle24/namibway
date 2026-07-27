<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportScrapedProviders extends Command
{
    protected $signature = 'namibway:import-providers
                            {file? : Path to scraped_providers.json (defaults to scripts/scraped_providers.json)}
                            {--dry-run : Show what would be imported without writing to DB}
                            {--skip-existing : Skip if a listing with the same slug already exists}';

    protected $description = 'Import scraped tourism provider data into listings + partners tables';

    private const ALLOWED_TYPES = ['accommodation', 'activity', 'restaurant', 'car_rental'];

    public function handle(): int
    {
        $file = $this->argument('file') ?? base_path('scripts/scraped_providers.json');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            $this->line("Run first: python scripts/scrape_providers.py");
            return self::FAILURE;
        }

        $records = json_decode(file_get_contents($file), true);

        if (! is_array($records)) {
            $this->error("Invalid JSON in {$file}");
            return self::FAILURE;
        }

        $this->info("Found " . count($records) . " records in {$file}");

        $isDryRun = $this->option('dry-run');
        $skipExisting = $this->option('skip-existing');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $bar = $this->output->createProgressBar(count($records));
        $bar->start();

        foreach ($records as $record) {
            try {
                $this->processRecord($record, $isDryRun, $skipExisting, $stats);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->newLine();
                $this->error("Error on '{$record['name']}': {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Created', 'Skipped', 'Errors'],
            [[$stats['created'], $stats['skipped'], $stats['errors']]]
        );

        return self::SUCCESS;
    }

    private function processRecord(array $record, bool $isDryRun, bool $skipExisting, array &$stats): void
    {
        $name = trim($record['name'] ?? '');
        if (! $name) {
            $stats['skipped']++;
            return;
        }

        $type = $this->resolveType($record['type'] ?? 'accommodation');
        $slug = $this->uniqueSlug(Str::slug($name));

        if ($skipExisting && Listing::where('slug', $slug)->exists()) {
            $stats['skipped']++;
            return;
        }

        if ($isDryRun) {
            $stats['created']++;
            return;
        }

        DB::transaction(function () use ($record, $name, $type, $slug, &$stats) {
            // Create a placeholder partner so the listing has an owner to claim
            $partner = Partner::create([
                'name'        => $name,
                'website'     => $record['website'] ?? null,
                'email'       => $record['contact_email'] ?? null,
                'source_url'  => $record['source_url'] ?? null,
                'claim_token' => Str::random(48),
            ]);

            Listing::create([
                'partner_id'    => $partner->id,
                'type'          => $type,
                'name'          => ['en' => $name],
                'slug'          => $slug,
                'description'   => $record['description']
                    ? ['en' => trim($record['description'])]
                    : null,
                'region'        => $this->normalizeRegion($record['region'] ?? null),
                'latitude'      => $record['latitude'] ?? null,
                'longitude'     => $record['longitude'] ?? null,
                'source_url'    => $record['source_url'] ?? null,
                'website'       => $record['website'] ?? null,
                'contact_email' => $record['contact_email'] ?? null,
                'claim_status'  => 'unclaimed',
                'is_published'  => false,
                'accepts_inquiries' => false,
            ]);

            $stats['created']++;
        });
    }

    private function resolveType(string $raw): string
    {
        $map = [
            'lodge'     => 'accommodation',
            'camp'      => 'accommodation',
            'hotel'     => 'accommodation',
            'guesthouse'=> 'accommodation',
            'b&b'       => 'accommodation',
            'tour'      => 'activity',
            'safari'    => 'activity',
            'rent'      => 'car_rental',
            'car'       => 'car_rental',
        ];

        $lower = strtolower($raw);
        foreach ($map as $keyword => $resolved) {
            if (str_contains($lower, $keyword)) {
                return $resolved;
            }
        }

        return in_array($lower, self::ALLOWED_TYPES) ? $lower : 'accommodation';
    }

    private function normalizeRegion(?string $region): ?string
    {
        if (! $region) {
            return null;
        }

        $known = [
            'etosha'         => 'Etosha',
            'sossusvlei'     => 'Sossusvlei',
            'swakopmund'     => 'Swakopmund',
            'windhoek'       => 'Windhoek',
            'damaraland'     => 'Damaraland',
            'skeleton coast' => 'Skeleton Coast',
            'caprivi'        => 'Caprivi',
            'fish river'     => 'Fish River Canyon',
            'kaokoveld'      => 'Kaokoveld',
            'namib'          => 'Namib Desert',
        ];

        $lower = strtolower($region);
        foreach ($known as $keyword => $canonical) {
            if (str_contains($lower, $keyword)) {
                return $canonical;
            }
        }

        return $region;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 2;
        while (Listing::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }
}
