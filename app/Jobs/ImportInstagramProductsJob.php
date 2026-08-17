<?php

namespace App\Jobs;

use App\Enums\ContentSource;
use App\Enums\ShopProductStatus;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteImage;
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
 * Import Instagram posts as shop products.
 *
 * Each post becomes one ShopProduct: the image is downloaded and stored as a
 * SiteImage, the caption (if any) becomes the description, and the post URL
 * is kept for traceability. Posts that have already been imported
 * (instagram_post_url matches) are skipped.
 *
 * Uses the same internal Instagram web API as ImportInstagramPhotosJob.
 */
class ImportInstagramProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly Site $site,
        public readonly string $instagramUrl,
        public readonly int $limit = 12,
        public readonly ?int $userId = null,
    ) {}

    public function handle(): void
    {
        $username = ImportInstagramPhotosJob::extractUsername($this->instagramUrl);

        if ($username === null) {
            $this->notifyFailure('No Instagram username could be read from what you entered.');

            return;
        }

        try {
            $posts = $this->fetchPosts($username);
        } catch (Throwable $e) {
            $this->notifyFailure("Instagram fetch failed: {$e->getMessage()}");

            return;
        }

        if (empty($posts)) {
            $this->notifyFailure(
                "@{$username} returned no posts — the profile may be private ".
                "or Instagram's format has changed."
            );

            return;
        }

        $posts = array_slice($posts, 0, $this->limit);
        $imported = 0;
        $existingUrls = $this->site->shopProducts()->pluck('instagram_post_url')->filter()->all();

        $maxSort = $this->site->shopProducts()->max('sort') ?? -1;

        foreach ($posts as $post) {
            if (in_array($post['post_url'], $existingUrls, true)) {
                continue;
            }

            $image = $this->storeImage($post['image_url']);
            $this->createProduct($post, $image, (int) $maxSort + 1);
            $maxSort++;
            $imported++;
        }

        $this->notifySuccess($username, $imported);
    }

    public function failed(?Throwable $exception): void
    {
        $this->notifyFailure($exception?->getMessage() ?? 'The job failed without a reason.');
    }

    /**
     * @return list<array{image_url: string, caption: string, post_url: string}>
     */
    private function fetchPosts(string $username): array
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '.
              '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

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
            throw new RuntimeException("HTTP {$resp->status()} from Instagram.");
        }

        $edges = data_get($resp->json(), 'data.user.edge_owner_to_timeline_media.edges', []);
        $posts = [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $postUrl = 'https://www.instagram.com/p/'.($node['shortcode'] ?? '').'/';
            $caption = data_get($node, 'edge_media_to_caption.edges.0.node.text', '');

            $children = $node['edge_sidecar_to_children']['edges'] ?? [];

            if (! empty($children)) {
                // Carousel: first image represents the product.
                if ($url = $children[0]['node']['display_url'] ?? null) {
                    $posts[] = [
                        'image_url' => $url,
                        'caption' => is_string($caption) ? $caption : '',
                        'post_url' => $postUrl,
                    ];
                }
            } elseif ($url = $node['display_url'] ?? null) {
                $posts[] = [
                    'image_url' => $url,
                    'caption' => is_string($caption) ? $caption : '',
                    'post_url' => $postUrl,
                ];
            }
        }

        return $posts;
    }

    private function storeImage(string $url): ?SiteImage
    {
        try {
            $content = Http::timeout(30)->get($url)->body();

            if (strlen($content) < 1024) {
                return null;
            }

            $key = $this->site->mediaPrefix().'/'.Str::random(20).'.jpg';
            Storage::disk('r2')->put($key, $content, 'public');

            return SiteImage::create([
                'site_id' => $this->site->id,
                'key' => $key,
                'content_source' => ContentSource::Partner,
                'prospect_only' => false,
                'sort' => 0,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{image_url: string, caption: string, post_url: string}  $post
     */
    private function createProduct(array $post, ?SiteImage $image, int $sort): void
    {
        $caption = trim($post['caption']);

        // First line of the caption = product name; rest = description.
        $lines = preg_split('/\r?\n/', $caption, 2) ?: [];
        $title = trim($lines[0] ?? '');
        $description = trim($lines[1] ?? '');

        if ($title === '') {
            $title = 'Product';
        }

        $product = ShopProduct::create([
            'site_id' => $this->site->id,
            'title' => mb_substr($title, 0, 255),
            'description' => $description !== '' ? $description : null,
            'status' => ShopProductStatus::Draft,
            'instagram_post_url' => $post['post_url'],
            'sort' => $sort,
        ]);

        if ($image !== null) {
            $product->images()->attach($image->id, ['sort' => 0]);
        }
    }

    private function notifySuccess(string $username, int $count): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title($count === 0
                ? "No new products from @{$username}"
                : "Imported {$count} ".($count === 1 ? 'product' : 'products')." from @{$username}")
            ->body($count === 0
                ? 'All posts have already been imported.'
                : 'They are set to Draft — open the Products window to review and publish them.')
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
            ->title('Instagram product import failed')
            ->body($reason)
            ->danger()
            ->sendToDatabase($user);
    }

    private function user(): ?User
    {
        return $this->userId === null ? null : User::find($this->userId);
    }
}
