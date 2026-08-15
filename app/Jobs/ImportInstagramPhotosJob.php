<?php

namespace App\Jobs;

use App\Models\Partner;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Downloads recent photos from a public Instagram profile and merges them
 * into the partner's gallery.
 *
 * Uses Instagram's internal web API — no login required for public profiles,
 * but the endpoint may change. When it does, the error notification says so
 * and the fix is updating fetchImageUrls().
 */
class ImportInstagramPhotosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Partner $partner,
        public readonly int $limit = 12,
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $username = self::extractUsername($this->partner->instagram ?? '');

        if ($username === null) {
            $this->notifyFailure('No Instagram username could be read from the stored URL.');

            return;
        }

        try {
            $imageUrls = $this->fetchImageUrls($username);
        } catch (Throwable $e) {
            $this->notifyFailure("Instagram fetch failed: {$e->getMessage()}");

            return;
        }

        if (empty($imageUrls)) {
            $this->notifyFailure(
                "@{$username} returned no images — the profile may be private ".
                "or Instagram's internal format has changed."
            );

            return;
        }

        $imageUrls = array_slice($imageUrls, 0, $this->limit);
        $keys = $this->uploadImages($imageUrls);

        if (empty($keys)) {
            $this->notifyFailure('Images were found on Instagram but could not be downloaded.');

            return;
        }

        $existing = $this->partner->gallery ?? [];
        $merged = array_values(array_unique(array_merge($existing, $keys)));
        $this->partner->update(['gallery' => $merged]);

        $this->notifySuccess($username, count($keys));
    }

    public function failed(?Throwable $exception): void
    {
        $this->notifyFailure($exception?->getMessage() ?? 'The job failed without a reason.');
    }

    /**
     * Two-step fetch: grab a csrftoken from the homepage, then hit the
     * profile API with it. Works for public profiles without a session.
     *
     * @return list<string>
     */
    private function fetchImageUrls(string $username): array
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '.
              '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

        // Grab a fresh csrftoken so Instagram doesn't reject the API call.
        $init = Http::withHeaders([
            'User-Agent' => $ua,
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->timeout(20)->get('https://www.instagram.com/');

        $csrf = '';
        foreach (explode(';', $init->header('set-cookie')) as $part) {
            if (str_contains($part, 'csrftoken=')) {
                $csrf = trim(str_replace('csrftoken=', '', explode(';', trim($part))[0]));
                break;
            }
        }

        $resp = Http::withHeaders([
            'x-ig-app-id' => '936619743392459',
            'User-Agent' => $ua,
            'Accept' => '*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer' => 'https://www.instagram.com/',
            'Cookie' => "csrftoken={$csrf}",
        ])->timeout(30)->get(
            'https://www.instagram.com/api/v1/users/web_profile_info/',
            ['username' => $username]
        );

        if (! $resp->successful()) {
            throw new RuntimeException("HTTP {$resp->status()} from Instagram — profile may be private or login required.");
        }

        $edges = data_get($resp->json(), 'data.user.edge_owner_to_timeline_media.edges', []);

        $urls = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];

            // Carousel post: collect all child images.
            $children = $node['edge_sidecar_to_children']['edges'] ?? [];

            if (! empty($children)) {
                foreach ($children as $child) {
                    if ($url = $child['node']['display_url'] ?? null) {
                        $urls[] = $url;
                    }
                }
            } elseif ($url = $node['display_url'] ?? null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Downloads each URL and stores it in the partner R2 prefix.
     *
     * @param  list<string>  $urls
     * @return list<string>  R2 keys of the images that were stored
     */
    private function uploadImages(array $urls): array
    {
        $keys = [];

        foreach ($urls as $url) {
            try {
                $content = Http::timeout(30)->get($url)->body();

                if (strlen($content) < 1024) {
                    // Suspiciously small — probably an error page, not an image.
                    continue;
                }

                $key = 'partners/'.Str::random(20).'.jpg';
                Storage::disk('r2')->put($key, $content, 'public');
                $keys[] = $key;
            } catch (Throwable) {
                // Skip individual failures; continue with the rest.
            }
        }

        return $keys;
    }

    private function notifySuccess(string $username, int $count): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title("Imported {$count} ".($count === 1 ? 'photo' : 'photos')." from @{$username}")
            ->body('They are now in the partner gallery — open the Website tab to check.')
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
            ->title('Instagram import failed')
            ->body($reason)
            ->danger()
            ->sendToDatabase($user);
    }

    private function user(): ?User
    {
        return $this->userId === null ? null : User::find($this->userId);
    }

    public static function extractUsername(string $instagramUrl): ?string
    {
        if (blank($instagramUrl)) {
            return null;
        }

        $rawPath = parse_url($instagramUrl, PHP_URL_PATH);
        $path = is_string($rawPath) ? $rawPath : $instagramUrl;
        $username = trim($path, '/');

        // Strip any trailing query params if parse_url missed them.
        $username = explode('?', $username)[0];
        $username = trim($username, '/');

        return filled($username) ? $username : null;
    }
}
