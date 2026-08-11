<?php

namespace App\Http\Controllers\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use App\Sites\Rendering\BookingPanel;
use App\Sites\Rendering\BookingPanelData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The public renderer for a customer website.
 *
 * Server-rendered Blade, no Inertia, no Vue, no shared Tailwind build. The
 * flyer promises a page that loads on an old phone over a slow connection, and
 * the travel platform's bundle is several hundred kilobytes of JavaScript that
 * none of these pages would use. Requests that reach here have skipped the web
 * middleware group entirely (see App\Http\Middleware\ResolveSiteHost), so there
 * is no session, no CSRF cookie and no Inertia share on the way in either.
 */
class SiteController
{
    public function __construct(private readonly BookingPanel $bookingPanel) {}

    /**
     * Entry point for a request that arrived on a site's own host.
     */
    public function render(Request $request, Site $site, string $path): Response
    {
        $path = trim($path, '/');

        if ($path === 'robots.txt') {
            return $this->robots($site);
        }

        if ($path === 'sitemap.xml') {
            return $this->sitemap($site);
        }

        return $this->page($request, $site, $path);
    }

    /**
     * The back door at /_sites/{slug} — how a site is reviewed before its DNS
     * exists, and how the renderer is exercised in CI. Deliberately not a
     * pretty URL: it is for us, not an address anybody is given.
     */
    public function path(Request $request, string $slug, ?string $page = null): Response
    {
        $site = Site::where('slug', $slug)->firstOrFail();

        return $this->page($request, $site, (string) $page);
    }

    private function page(Request $request, Site $site, string $pageSlug): Response
    {
        $this->assertVisible($request, $site);

        /** @var SitePage|null $page */
        $page = $site->pages()
            ->where('locale', $site->default_locale)
            ->when(
                $pageSlug === '',
                fn ($query) => $query->where('is_home', true),
                fn ($query) => $query->where('slug', $pageSlug),
            )
            ->first();

        abort_if($page === null, 404);

        $stored = $page->renderableBlocks()->get()
            ->filter(fn (SiteBlock $block) => BlockRegistry::has($block->type));

        // Read live, every render, and only where the property has something
        // sellable — see App\Sites\Rendering\BookingPanel.
        $booking = $stored->contains(fn (SiteBlock $block) => $block->type === 'booking')
            ? $this->bookingPanel->for($site, $request)
            : null;

        $blocks = $stored
            ->filter(fn (SiteBlock $block) => $this->shouldRender($block, $site, $booking))
            ->values();

        $images = $this->images($site, $blocks->pluck('data')->all());

        $response = response()->view('sites.page', [
            'site' => $site,
            'page' => $page,
            'blocks' => $blocks,
            'images' => $images,
            'booking' => $booking,
            'accent' => $this->accent($site),
        ]);

        if (! $site->isPublished()) {
            // A draft is research about somebody's business, not publication
            // under their name. Belt and braces with the token check above:
            // nothing indexes what it cannot reach, but a link pasted into a
            // chat thread is exactly how a URL reaches a crawler.
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * Whether a block has anything to show.
     *
     * A block with nothing in it must never render as an empty band — that is
     * the difference between a site that reads as sparse and one that reads as
     * unfinished, and a site generated from a thin listing has several.
     *
     * Most blocks answer this from their own payload. Three cannot, because
     * what they show does not live in the payload: booking depends on whether
     * the property has sellable inventory right now, and location and contact
     * render details held on the site.
     */
    private function shouldRender(SiteBlock $block, Site $site, ?BookingPanelData $booking): bool
    {
        return match ($block->type) {
            'booking' => $booking !== null,
            'location' => filled($site->address) || (filled($site->latitude) && filled($site->longitude)),
            'contact' => filled($site->contact_email) || filled($site->contact_phone) || filled($site->whatsapp),
            default => $block->isFilled(),
        };
    }

    /**
     * A draft renders only for somebody holding its token.
     *
     * The host alone is deliberately not enough. Drafts are generated to win
     * customers, before anybody has agreed to a page existing about them, and a
     * guessable subdomain is not consent.
     */
    private function assertVisible(Request $request, Site $site): void
    {
        if ($site->isPublished()) {
            return;
        }

        $token = $request->query('preview');

        abort_unless(
            is_string($token) && $token !== '' && hash_equals($site->draft_token, $token),
            404
        );
    }

    /**
     * Every image the page's blocks refer to, in one query, keyed by id.
     *
     * A block payload holds ids rather than a relation, so referential
     * integrity is not the database's job here: an id that no longer resolves
     * is simply dropped by the partial. A missing picture is a smaller failure
     * than a page that will not render.
     *
     * @param  array<int, array<string, mixed>|null>  $payloads
     * @return Collection<int, SiteImage>
     */
    private function images(Site $site, array $payloads): Collection
    {
        $ids = [];

        foreach ($payloads as $payload) {
            $data = $payload ?? [];

            foreach (['image_id', 'image_ids'] as $key) {
                foreach ((array) ($data[$key] ?? []) as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        if ($ids === []) {
            return collect();
        }

        return $site->images()->whereIn('id', array_unique($ids))->get()->keyBy('id');
    }

    private function accent(Site $site): string
    {
        /** @var array<string, string> $accents */
        $accents = (array) config('sites.accents', []);

        return $accents[$site->accent]
            ?? $accents[(string) config('sites.default_accent', 'copper')]
            ?? '#9C4A21';
    }

    private function robots(Site $site): Response
    {
        $body = $site->isPublished() && filled($site->host)
            ? "User-agent: *\nAllow: /\nSitemap: https://".$site->host."/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function sitemap(Site $site): Response
    {
        abort_unless($site->isPublished() && filled($site->host), 404);

        $urls = $site->pages()
            ->where('locale', $site->default_locale)
            ->orderBy('sort')
            ->get()
            ->map(fn (SitePage $page) => '  <url><loc>https://'.$site->host.'/'.$page->slug.'</loc></url>')
            ->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$urls."\n"
            .'</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
