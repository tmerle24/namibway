<?php

namespace Tests\Feature\Sites;

use App\Enums\ContentSource;
use App\Enums\ShopProductStatus;
use App\Filament\Support\ManageShopProductsAction;
use App\Models\Listing;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Saving the product list on a customer's website.
 *
 * The load-bearing property here is not what the form writes but what it is
 * allowed to delete. This form is a repeater, so "delete" is expressed by a row
 * being absent — which makes an empty form indistinguishable from "remove
 * everything" unless something records what the form was actually showing.
 *
 * It is not a hypothetical. The modal carries import buttons that create
 * products behind it: a photo upload, Instagram, a spreadsheet. Open the modal,
 * upload eleven photographs, save — and the form, still holding the empty list
 * it was opened with, deleted all eleven. That happened on 2026-08-16 to real
 * work, and `ShopProduct` has no soft deletes, so it was gone.
 *
 * So the rule these tests hold down: a save may only delete a product the form
 * was given when it opened. Anything created after that is invisible to it, and
 * what a form cannot see it must not be able to destroy.
 */
class ShopProductEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_form_cannot_delete_products_created_behind_it(): void
    {
        $site = $this->site();

        // The modal opens on an empty shop, so it knows about nothing.
        $known = [];

        // An import then creates products while it stands open.
        $this->product($site, 'Beaded collar');
        $this->product($site, 'Woven basket');

        $this->write($site, [], $known);

        $this->assertSame(2, ShopProduct::count(), 'A form that never saw these products deleted them.');
    }

    public function test_removing_a_row_still_deletes_that_product(): void
    {
        $site = $this->site();
        $kept = $this->product($site, 'Beaded collar');
        $gone = $this->product($site, 'Woven basket');

        $this->write(
            $site,
            [['product_id' => $kept->id, 'title' => 'Beaded collar']],
            [$kept->id, $gone->id],
        );

        $this->assertSame([$kept->id], ShopProduct::pluck('id')->all());
    }

    /**
     * The two cases meet here: the form knew about one product and the user
     * removed it, while an import added another behind the modal. Exactly one
     * of them may go.
     */
    public function test_a_deletion_and_a_concurrent_import_do_not_collide(): void
    {
        $site = $this->site();
        $known = $this->product($site, 'Beaded collar');

        $imported = $this->product($site, 'Woven basket');

        $this->write($site, [], [$known->id]);

        $this->assertSame([$imported->id], ShopProduct::pluck('id')->all());
    }

    public function test_an_edited_product_keeps_its_id(): void
    {
        $site = $this->site();
        $product = $this->product($site, 'Beaded collar');

        $this->write(
            $site,
            [['product_id' => $product->id, 'title' => 'Beaded collar, red', 'price' => '350']],
            [$product->id],
        );

        $this->assertSame(1, ShopProduct::count());
        $this->assertSame('Beaded collar, red', $product->refresh()->title);
        $this->assertSame(350.0, $product->price);
    }

    /**
     * The picture is handed over in the shape a loaded FileUpload holds —
     * keyed by an id, not as the bare storage key.
     *
     * The form is filled two ways: Filament hydrates it when the modal opens,
     * and `Set` writes it directly when the list is refreshed after an import.
     * Only the first runs the component's hydration callbacks, so a bare key
     * survived one path and blew up the other — `getUploadedFiles()` iterates
     * that state, and a string is not iterable. Producing the loaded shape here
     * is what makes the two paths agree.
     */
    public function test_the_picture_is_given_to_the_form_the_way_a_file_upload_holds_it(): void
    {
        $site = $this->site();
        $image = SiteImage::create([
            'site_id' => $site->id,
            'key' => 'sites/monas-collection/collar.jpg',
            'content_source' => ContentSource::Partner,
            'prospect_only' => false,
            'sort' => 0,
        ]);

        $product = $this->product($site, 'Beaded collar');
        $product->update(['image_ids' => [$image->id]]);

        $row = $this->state($site)[0];

        $this->assertIsArray($row['image_key'], 'A FileUpload iterates its state; a bare key is not iterable.');
        $this->assertSame(['sites/monas-collection/collar.jpg'], array_values($row['image_key']));
    }

    public function test_a_product_without_a_picture_gives_the_form_an_empty_list(): void
    {
        $site = $this->site();
        $this->product($site, 'Beaded collar');

        $this->assertSame([], $this->state($site)[0]['image_key']);
    }

    /**
     * A row somebody added and did not fill in is not a product.
     */
    public function test_a_row_without_a_title_is_ignored(): void
    {
        $site = $this->site();

        $this->write($site, [['title' => ''], ['title' => null]], []);

        $this->assertSame(0, ShopProduct::count());
    }

    private function site(): Site
    {
        $listing = Listing::factory()->create();

        return Site::factory()->create(['source_listing_id' => $listing->id, 'slug' => 'monas-collection']);
    }

    private function product(Site $site, string $title): ShopProduct
    {
        return ShopProduct::create([
            'site_id' => $site->id,
            'title' => $title,
            'status' => ShopProductStatus::Published,
            'image_ids' => [],
            'sort' => 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function state(Site $site): array
    {
        $method = new ReflectionMethod(ManageShopProductsAction::class, 'state');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $method->invoke(null, $site->sourceListing);

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  array<int, int>  $knownIds
     */
    private function write(Site $site, array $state, array $knownIds): void
    {
        $method = new ReflectionMethod(ManageShopProductsAction::class, 'write');
        $method->setAccessible(true);
        $method->invoke(null, $site, $state, $knownIds);
    }
}
