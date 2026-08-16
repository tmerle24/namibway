<?php

namespace App\Jobs;

use App\Enums\ContentSource;
use App\Enums\ShopProductStatus;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteImage;
use App\Models\User;
use App\Services\ImportExport\SpreadsheetReader;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import products from an uploaded spreadsheet (.xlsx or .csv).
 *
 * Recognised column headers (case-insensitive):
 *   title       — required; rows without one are skipped
 *   price       — free string ("N$ 350", "Call for price")
 *   category    — free string
 *   description — longer text
 *   image_url   — downloaded and stored as a SiteImage; failures are skipped
 *
 * All imported products start as Draft so the owner can review before publishing.
 * The temporary file is deleted after the job runs.
 */
class ImportProductsFromFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly Site $site,
        public readonly string $fileKey,
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $path = Storage::disk('local')->path($this->fileKey);

        if (! is_file($path)) {
            $this->notifyFailure('The uploaded file could not be found.');

            return;
        }

        try {
            $data = app(SpreadsheetReader::class)->read($path);
        } catch (Throwable $e) {
            $this->notifyFailure('Could not read the file: '.$e->getMessage());
            Storage::disk('local')->delete($this->fileKey);

            return;
        }

        $headers = array_map('strtolower', $data['headers']);
        $col = $this->columnMap($headers);

        if (! isset($col['title'])) {
            $this->notifyFailure(
                'No "title" column found. The first row must be a header row '.
                'with at least a "title" column.'
            );
            Storage::disk('local')->delete($this->fileKey);

            return;
        }

        $maxSort = $this->site->shopProducts()->max('sort') ?? -1;
        $imported = 0;
        $skipped = 0;

        foreach ($data['rows'] as $row) {
            $cells = $row['cells'];
            $title = trim($this->cell($cells, $col['title'] ?? null));

            if ($title === '') {
                $skipped++;

                continue;
            }

            $imageUrl = trim($this->cell($cells, $col['image_url'] ?? null));
            $imageId = $imageUrl !== '' ? $this->storeImage($imageUrl) : null;

            ShopProduct::create([
                'site_id' => $this->site->id,
                'title' => mb_substr($title, 0, 255),
                'price' => mb_substr($this->cell($cells, $col['price'] ?? null), 0, 100) ?: null,
                'category' => mb_substr($this->cell($cells, $col['category'] ?? null), 0, 100) ?: null,
                'description' => $this->cell($cells, $col['description'] ?? null) ?: null,
                'status' => ShopProductStatus::Draft,
                'image_ids' => $imageId !== null ? [$imageId] : [],
                'sort' => ++$maxSort,
            ]);

            $imported++;
        }

        Storage::disk('local')->delete($this->fileKey);

        $this->notifySuccess($imported, $skipped);
    }

    public function failed(?Throwable $exception): void
    {
        Storage::disk('local')->delete($this->fileKey);
        $this->notifyFailure($exception?->getMessage() ?? 'The job failed without a reason.');
    }

    /**
     * Map lowercase header names to their zero-based column indexes.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private function columnMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $i => $header) {
            // Normalise spaces and underscores so "Image URL", "image url"
            // and "image_url" all resolve to the same key.
            $key = preg_replace('/[\s_]+/', '_', $header) ?? $header;

            $map[$key] = $i;
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     */
    private function cell(array $cells, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        return trim($cells[$index] ?? '');
    }

    private function storeImage(string $url): ?int
    {
        try {
            // Attempt to normalise Google Drive / Dropbox share links to
            // direct-download URLs before fetching.
            $url = $this->resolveDownloadUrl($url);

            $response = Http::withOptions(['allow_redirects' => true])
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            if (strlen($body) < 1024) {
                return null;
            }

            $extension = $this->guessExtension($response->header('Content-Type'));
            $key = $this->site->mediaPrefix().'/'.Str::random(20).'.'.$extension;
            Storage::disk('r2')->put($key, $body, 'public');

            return SiteImage::create([
                'site_id' => $this->site->id,
                'key' => $key,
                'content_source' => ContentSource::Partner,
                'prospect_only' => false,
                'sort' => 0,
            ])->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Convert share-page links to raw download URLs where possible.
     *
     * Google Drive: /file/d/{id}/view → /uc?export=download&id={id}
     * Dropbox: ?dl=0 → ?dl=1
     */
    private function resolveDownloadUrl(string $url): string
    {
        // Google Drive share link
        if (preg_match('#drive\.google\.com/file/d/([^/]+)#', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id='.$m[1];
        }

        // Dropbox preview link → direct download
        if (str_contains($url, 'dropbox.com')) {
            return preg_replace('/[?&]dl=0/', '', $url).'?dl=1';
        }

        return $url;
    }

    private function guessExtension(?string $contentType): string
    {
        return match (true) {
            str_contains((string) $contentType, 'png') => 'png',
            str_contains((string) $contentType, 'gif') => 'gif',
            str_contains((string) $contentType, 'webp') => 'webp',
            default => 'jpg',
        };
    }

    private function notifySuccess(int $imported, int $skipped): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $body = 'They are set to Draft — open the Products window to review and publish them.';

        if ($skipped > 0) {
            $body .= ' '.$skipped.' '.($skipped === 1 ? 'row was' : 'rows were').' skipped (missing title).';
        }

        Notification::make()
            ->title('Imported '.$imported.' '.($imported === 1 ? 'product' : 'products').' from spreadsheet')
            ->body($body)
            ->success()
            ->sendToDatabase($user);
    }

    private function notifyFailure(string $reason): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('Spreadsheet import failed')
            ->body($reason)
            ->danger()
            ->sendToDatabase($user);
    }

    private function user(): ?User
    {
        return $this->userId === null ? null : User::find($this->userId);
    }
}
