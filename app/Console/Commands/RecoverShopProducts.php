<?php

namespace App\Console\Commands;

use App\Enums\ShopProductStatus;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Services\ShopProductDescriber;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuild shop products from pictures nothing points at any more.
 *
 * Deleting a product never deleted its picture — `site_images` rows and the R2
 * objects behind them survive on purpose, because orphan cleanup is a separate,
 * deliberate job (`photos:audit-r2`). That turns out to be what makes this
 * recoverable: when the product editor wiped a shop on 2026-08-16, every
 * photograph was still there, and only the titles and descriptions — which
 * lived in form state and nowhere else — were actually lost.
 *
 * So: find pictures on a site that no product and no page block references,
 * make one draft product per picture, and offer to write the text again with
 * the same AI that wrote it the first time. Unreferenced is the right test
 * because it is conservative in the direction that matters — a picture in use
 * is never touched, and a picture in use by nothing is either the remains of a
 * deleted product or an upload nobody ever used, and a draft product is a
 * harmless thing for either to become.
 */
class RecoverShopProducts extends Command
{
    protected $signature = 'sites:recover-products
        {site : Slug of the site}
        {--ai : Write titles and descriptions from the images}
        {--publish : Create them live instead of as drafts}
        {--dry-run : Show what would be created and write nothing}';

    protected $description = 'Rebuild shop products from site pictures that nothing references any more';

    public function handle(ShopProductDescriber $describer): int
    {
        $site = Site::where('slug', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site with the slug [{$this->argument('site')}].");

            return self::FAILURE;
        }

        $orphans = $this->unreferencedImages($site);

        if ($orphans->isEmpty()) {
            $this->info("Every picture on [{$site->name}] is in use — nothing to recover.");

            return self::SUCCESS;
        }

        $this->info("{$orphans->count()} picture(s) on [{$site->name}] are referenced by nothing:");
        $this->table(['id', 'key'], $orphans->map(fn (SiteImage $i): array => [$i->id, $i->key])->all());

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $texts = [];

        if ($this->option('ai')) {
            $this->info('Asking the model for titles and descriptions…');

            $texts = $describer->describeMany(array_values(
                $orphans->map(fn (SiteImage $i): string => Storage::disk('r2')->url($i->key))->all()
            ));
        }

        $status = $this->option('publish') ? ShopProductStatus::Published : ShopProductStatus::Draft;
        $sort = ($site->shopProducts()->max('sort') ?? -1);

        foreach ($orphans->values() as $i => $image) {
            $product = ShopProduct::create([
                'site_id' => $site->id,
                'title' => $texts[$i]['title'] ?? 'Product '.($i + 1),
                'description' => $texts[$i]['description'] ?? null,
                'status' => $status,
                'sort' => ++$sort,
            ]);

            $product->images()->attach($image->id, ['sort' => 0]);
        }

        $this->info("{$orphans->count()} product(s) created as {$status->value}. Prices still need entering.");

        return self::SUCCESS;
    }

    /**
     * Pictures on this site that no product and no block points at.
     *
     * Blocks hold a picture as either `image_id` or `image_ids` — the two shapes
     * the block library uses, the same pair EditSiteImagesAction cleans up when
     * a picture is removed.
     *
     * @return Collection<int, SiteImage>
     */
    private function unreferencedImages(Site $site): Collection
    {
        $used = [];

        // Images referenced via the pivot table.
        $used = array_merge($used, $site->shopProducts()
            ->join('shop_product_images', 'shop_products.id', '=', 'shop_product_images.shop_product_id')
            ->pluck('shop_product_images.site_image_id')
            ->map(fn ($id) => (int) $id)
            ->all());

        $blocks = SiteBlock::whereIn(
            'site_page_id',
            $site->pages()->select('id')
        )->get();

        foreach ($blocks as $block) {
            $data = $block->data;

            if (isset($data['image_id'])) {
                $used[] = (int) $data['image_id'];
            }

            foreach ($data['image_ids'] ?? [] as $id) {
                $used[] = (int) $id;
            }
        }

        return $site->images()
            ->whereNotIn('id', $used === [] ? [0] : $used)
            ->orderBy('id')
            ->get();
    }
}
