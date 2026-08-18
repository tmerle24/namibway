<?php

namespace App\Http\Controllers\Sites;

use App\Models\Inquiry;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use App\Sites\LegalText;
use App\Sites\Rendering\BookingPanel;
use App\Sites\Rendering\BookingPanelData;
use App\Sites\Rendering\EnquiryItems;
use App\Sites\Rendering\StoryText;
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
    public function path(Request $request, string $slug, ?string $path = null): Response
    {
        $site = Site::where('slug', $slug)->firstOrFail();

        return $this->page($request, $site, (string) $path);
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

        // Privacy and the legal notice are not made of blocks. They are one
        // body of text the business owns and edits, so they are rendered from
        // the site record rather than being pages somebody could delete and
        // leave the footer pointing at nothing.
        if ($page === null && LegalText::isLegalPage($pageSlug)) {
            return $this->legal($site, $pageSlug);
        }

        if ($page === null && $pageSlug === 'about') {
            return $this->about($request, $site);
        }

        if ($page === null && $pageSlug === 'shop') {
            return $this->shopIndex($request, $site);
        }

        if ($page === null && str_starts_with($pageSlug, 'shop/')) {
            return $this->shopProduct($request, $site, substr($pageSlug, 5));
        }

        if ($page === null && $pageSlug === 'order') {
            return app(SiteOrderController::class)->form($request, $site);
        }

        if ($page === null && $pageSlug === 'order/qr') {
            return app(SiteOrderController::class)->qr($request, $site);
        }

        if ($page === null && $pageSlug === 'order/thanks') {
            return app(SiteOrderController::class)->thanks($request, $site);
        }

        if ($page === null && str_starts_with($pageSlug, 'order/pay/')) {
            $inquiry = Inquiry::findOrFail((int) substr($pageSlug, strlen('order/pay/')));

            return app(SiteOrderController::class)->payPage($request, $site, $inquiry);
        }

        abort_if($page === null, 404);

        $stored = $page->renderableBlocks()->get()
            ->filter(fn (SiteBlock $block) => BlockRegistry::has($block->type));

        // Read live, every render, and only where the property has something
        // sellable — see App\Sites\Rendering\BookingPanel.
        $booking = $stored->contains(fn (SiteBlock $block) => $block->type === 'booking')
            ? $this->bookingPanel->for($site, $request)
            : null;

        // The first page of shop products, loaded only when the shop block is
        // present. shouldRender() then hides the block when none are published.
        $shopBlock = $stored->first(fn (SiteBlock $block) => $block->type === 'shop');
        $shopLimit = (int) ($shopBlock?->data['product_count'] ?? 8);
        $shopProducts = $shopBlock !== null
            ? $site->shopProducts()->with('images')->published()->orderBy('sort')->orderBy('id')->limit($shopLimit)->get()
            : collect();

        $shopHasMore = $shopProducts->count() === $shopLimit
            && $site->shopProducts()->published()->count() > $shopLimit;

        $blocks = $stored
            ->filter(fn (SiteBlock $block) => $this->shouldRender($block, $site, $booking, $shopProducts))
            ->values();

        // What the one contact form offers, resolved once here rather than
        // queried from inside a view. The block decides the type; this decides
        // where the priced rows come from.
        $enquiryBlock = $stored->first(fn (SiteBlock $block) => $block->type === 'enquiry');
        $enquiryType = $enquiryBlock === null
            ? EnquiryFormType::StayRequest
            : EnquiryBlock::formTypeFor($site, $enquiryBlock->data);
        $enquiryItems = EnquiryItems::for($site, $enquiryType);

        $images = $this->images($site, $blocks->pluck('data')->all());

        $response = response()->view('sites.page', [
            'site' => $site,
            'page' => $page,
            'blocks' => $blocks,
            'images' => $images,
            'booking' => $booking,
            'shopProducts' => $shopProducts,
            'shopHasMore' => $shopHasMore,
            'accent' => $this->accent($site),
            // Absolute, because the form is posted from the site's own host as
            // well as from the path fallback, and only one of those can use a
            // named route relative to itself.
            'enquiryAction' => route('sites.enquiry', $site->slug),
            'enquiryType' => $enquiryType,
            'enquiryItems' => $enquiryItems,
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
     * Privacy or the legal notice — one heading, one body, the same furniture.
     */
    private function legal(Site $site, string $slug): Response
    {
        $response = response()->view('sites.legal', [
            'site' => $site,
            'accent' => $this->accent($site),
            'title' => LegalText::pages()[$slug],
            'body' => $slug === 'privacy' ? LegalText::privacy($site) : LegalText::imprint($site),
        ]);

        if (! $site->isPublished()) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * The full about text, on its own page.
     *
     * Reached at /about (or /_sites/{slug}/about for drafts) and linked from
     * the story slideshow when the about block has 2+ paragraphs. The body is
     * already sanitised by the purifier allow-list on save, so it renders as
     * HTML directly — StoryText only restores the paragraph breaks, which text
     * written as plain newlines does not have and which this page needs even
     * more than the slideshow does. 404s when the site has no about block or no
     * body text — the link in the slideshow is only shown when there is
     * something here.
     */
    private function about(Request $request, Site $site): Response
    {
        $this->assertVisible($request, $site);

        /** @var SiteBlock|null $block */
        $block = $site->pages()
            ->where('is_home', true)
            ->first()
            ?->blocks()
            ->where('type', 'about')
            ->where('is_enabled', true)
            ->first();

        abort_if($block === null || blank($block->data['body'] ?? null), 404);

        $response = response()->view('sites.about', [
            'site' => $site,
            'accent' => $this->accent($site),
            'title' => filled($block->data['heading'] ?? null) ? $block->data['heading'] : 'About us',
            'body' => StoryText::html($block->data['body']),
        ]);

        if (! $site->isPublished()) {
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
     *
     * @param Collection<int, ShopProduct>|null $shopProducts
     */
    private function shouldRender(SiteBlock $block, Site $site, ?BookingPanelData $booking, ?Collection $shopProducts = null): bool
    {
        return match ($block->type) {
            'booking' => $booking !== null,
            'shop' => ($shopProducts ?? collect())->isNotEmpty(),
            // Every business gets the form. `accepts_inquiries` used to gate it
            // as well, which meant a partner who does not sell through our
            // booking system had no way to be contacted from their own website
            // — the opposite of the point. An Inquiry now names a listing or a
            // partner (since 2026-08-16), so a partner-only site without a
            // listing still has somewhere for the enquiry to go.
            'enquiry' => $site->sourceListing !== null || $site->partner_id !== null,
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
     * @param  array<int, array<string, mixed>|null>  $payloads  Block data arrays
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

    /**
     * The full shop catalogue, with optional category filter and sort.
     *
     * Served at /shop (or /_sites/{slug}/shop). Filtering and sorting via URL
     * parameters rather than JavaScript: the page is statically renderable, and
     * a URL the browser can bookmark and share is better than a state that lives
     * only in memory.
     */
    private function shopIndex(Request $request, Site $site): Response
    {
        $this->assertVisible($request, $site);

        abort_unless($site->shopProducts()->published()->exists(), 404);

        $category = $request->query('category');
        $sort = $request->query('sort', 'default');
        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;

        $query = $site->shopProducts()->published();

        if (filled($category)) {
            $query->where('category', $category);
        }

        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw('lower(title) like ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhereRaw('lower(category) like ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhereRaw('lower(description) like ?', ['%'.mb_strtolower($search).'%']);
            });
        }

        $query->when($sort === 'name', fn ($q) => $q->orderBy('title'))
            ->when($sort === 'price_asc', fn ($q) => $q->orderByRaw('price asc nulls last'))
            ->when($sort === 'price_desc', fn ($q) => $q->orderByRaw('price desc nulls last'))
            ->when(! in_array($sort, ['name', 'price_asc', 'price_desc']), fn ($q) => $q->orderBy('sort')->orderBy('id'));

        $total = $query->count();
        $totalPages = (int) ceil($total / $perPage);
        $page = min($page, max(1, $totalPages));
        $products = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $products->load('images');

        $categories = $site->shopProducts()->published()
            ->whereNotNull('category')
            ->orderBy('category')
            ->distinct()
            ->pluck('category');

        [$navBlocks, $navPage] = $this->navData($site);

        $response = response()->view('sites.shop', [
            'site' => $site,
            'accent' => $this->accent($site),
            'products' => $products,
            'categories' => $categories,
            'activeCategory' => $category,
            'activeSort' => $sort,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'navBlocks' => $navBlocks,
            'navPage' => $navPage,
        ]);

        if (! $site->isPublished()) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * A single product's detail page.
     *
     * Served at /shop/{slug} (or /_sites/{site-slug}/shop/{product-slug}).
     */
    private function shopProduct(Request $request, Site $site, string $slug): Response
    {
        $this->assertVisible($request, $site);

        /** @var ShopProduct|null $product */
        $product = $site->shopProducts()
            ->published()
            ->where('slug', $slug)
            ->first();

        abort_if($product === null, 404);

        $product->load('images');

        $related = $site->shopProducts()
            ->with('images')
            ->published()
            ->when(filled($product->category), fn ($q) => $q->where('category', $product->category))
            ->where('id', '!=', $product->id)
            ->orderBy('sort')
            ->limit(4)
            ->get();

        [$navBlocks, $navPage] = $this->navData($site);

        $response = response()->view('sites.shop-product', [
            'site' => $site,
            'accent' => $this->accent($site),
            'product' => $product,
            'related' => $related,
            'enquiryAction' => route('sites.enquiry', $site->slug),
            'navBlocks' => $navBlocks,
            'navPage' => $navPage,
        ]);

        if (! $site->isPublished()) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * The home page's blocks and page record, for rendering the site nav on
     * pages that are not built from blocks (shop, about, legal).
     *
     * Shop pages are always solid-bar (no hero behind the nav), so hasHero is
     * not returned — the caller passes `hasHero: false` to the nav partial.
     *
     * @return array{0: Collection<int, SiteBlock>, 1: SitePage|null}
     */
    private function navData(Site $site): array
    {
        $page = $site->pages()
            ->where('locale', $site->default_locale)
            ->where('is_home', true)
            ->first();

        if ($page === null) {
            return [collect(), null];
        }

        // One query to decide if the shop block should render — same gate as on
        // the home page, where shouldRender() hides the shop block when there
        // are no published products. Without it a shop block that is empty on
        // the home page still appears as a nav item on the shop/product pages.
        $shopProducts = $site->shopProducts()->published()->limit(1)->get();

        $blocks = $page->renderableBlocks()
            ->get()
            ->filter(fn (SiteBlock $block) => BlockRegistry::has($block->type))
            ->filter(fn (SiteBlock $block) => $this->shouldRender($block, $site, null, $shopProducts))
            ->values();

        return [$blocks, $page];
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

        // The legal pages are deliberately absent: they carry noindex, and a
        // sitemap that offers a crawler a page it is then told to ignore is
        // just a contradiction in two files.
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
