<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Kaia\AnthropicClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateListingDescriptions extends Command
{
    protected $signature = 'listings:generate-descriptions
                            {--limit=50   : Max listings to process per run}
                            {--dry-run    : Show what would be generated without saving}';

    protected $description = 'Generate short descriptions for published listings that have a photo but no description';

    private const MODEL = 'claude-haiku-4-5-20251001';

    public function handle(AnthropicClient $client): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $listings = Listing::query()
            ->where('is_published', true)
            ->whereNotNull('image')
            ->where(function ($q) {
                $q->whereNull('description')
                    ->orWhereRaw("description->>'en' IS NULL")
                    ->orWhereRaw("description->>'en' = ''");
            })
            ->limit($limit)
            ->get(['id', 'name', 'type', 'region', 'address', 'website', 'phone']);

        $this->info("Found {$listings->count()} listings to describe");

        if ($dry) {
            $this->warn('DRY RUN — nothing will be saved');
        }

        $bar = $this->output->createProgressBar($listings->count());
        $bar->start();

        $generated = 0;
        $failed = 0;

        foreach ($listings as $listing) {
            $bar->advance();

            try {
                $description = $this->generate($client, $listing);

                if ($description) {
                    if (! $dry) {
                        $listing->setTranslation('description', 'en', $description);
                        $listing->saveQuietly();
                    }
                    $generated++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Failed [{$listing->id}]: " . $e->getMessage());
                $failed++;
            }

            usleep(200_000); // 200ms to stay within rate limits
        }

        $bar->finish();
        $this->newLine();
        $this->table(['Generated', 'Failed'], [[$generated, $failed]]);

        return self::SUCCESS;
    }

    private function generate(AnthropicClient $client, Listing $listing): ?string
    {
        $name = $listing->getTranslation('name', 'en');
        $type = $listing->type->value;
        $region = $listing->region ?? 'Namibia';
        $address = $listing->address ? ", {$listing->address}" : '';

        $system = 'You write concise, evocative descriptions for a Namibian travel platform. ' .
            'Write exactly 1–2 sentences (max 200 characters total). ' .
            'Focus on what makes this place appealing to travelers. ' .
            'No marketing fluff, no "welcome to", no repeating the name. ' .
            'Reply with only the description text, nothing else.';

        $prompt = "Write a short travel description for: {$name}\n" .
            "Type: {$type}\n" .
            "Location: {$region}{$address}\n\n" .
            'Give travelers a reason to be interested. Be specific if the type suggests it (e.g. game lodge = wildlife, restaurant = cuisine/atmosphere).';

        $response = $client->send(
            self::MODEL,
            $system,
            [['role' => 'user', 'content' => $prompt]],
            [],
            256,
        );

        $text = collect($response['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        $text = trim($text);

        return strlen($text) >= 10 ? $text : null;
    }
}
