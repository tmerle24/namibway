<?php

namespace Tests\Feature\Filament;

use App\Enums\DocumentKind;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Browsing the document store like a file explorer: you are always in one
 * place, you see that place, and what you create is created there.
 *
 * The thing worth guarding is the scoping. A folder screen that quietly shows
 * documents from elsewhere — or creates a folder somewhere other than where the
 * person was standing — is wrong in a way nobody notices until the filing is
 * already a mess.
 */
class DocumentExplorerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Till']);
    }

    private function folder(string $name, ?DocumentCategory $parent = null): DocumentCategory
    {
        return DocumentCategory::create(['name' => $name, 'parent_id' => $parent?->id]);
    }

    public function test_the_top_level_shows_its_own_folders_and_its_own_documents(): void
    {
        $marketing = $this->folder('Marketing');
        $print = $this->folder('Print', $marketing);

        $loose = Document::factory()->create([
            'document_category_id' => null,
            'title' => 'Not filed yet',
        ]);
        $inside = Document::factory()->create([
            'document_category_id' => $marketing->id,
            'title' => 'Flyer copy',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->assertOk()
            // Top-level folders only — a subfolder belongs to its parent's screen.
            ->assertSee('Marketing')
            ->assertDontSee('Print')
            ->assertCanSeeTableRecords([$loose])
            ->assertCanNotSeeTableRecords([$inside]);

        $this->assertSame($marketing->id, $print->parent_id);
    }

    public function test_walking_into_a_folder_shows_what_is_in_it(): void
    {
        $marketing = $this->folder('Marketing');
        $print = $this->folder('Print', $marketing);

        $loose = Document::factory()->create(['document_category_id' => null]);
        $inside = Document::factory()->create([
            'document_category_id' => $marketing->id,
            'title' => 'Flyer copy',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->call('openFolder', $marketing->id)
            ->assertSet('folder', $marketing->id)
            ->assertSee('Print')
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$loose]);
    }

    public function test_a_folder_can_be_linked_to_directly(): void
    {
        $marketing = $this->folder('Marketing');
        $inside = Document::factory()->create(['document_category_id' => $marketing->id]);

        Livewire::actingAs($this->admin())
            ->withQueryParams(['folder' => $marketing->id])
            ->test(ListDocuments::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$inside]);
    }

    public function test_a_link_to_a_folder_that_is_gone_lands_at_the_top(): void
    {
        Livewire::actingAs($this->admin())
            ->withQueryParams(['folder' => 9_999])
            ->test(ListDocuments::class)
            ->assertOk()
            ->assertSet('folder', null);
    }

    public function test_a_new_folder_is_created_where_you_are_standing(): void
    {
        $marketing = $this->folder('Marketing');

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->call('openFolder', $marketing->id)
            ->callAction('addFolder', ['name' => 'Print', 'icon' => 'heroicon-o-folder']);

        $print = DocumentCategory::query()->where('name', 'Print')->firstOrFail();

        $this->assertSame($marketing->id, $print->parent_id);
    }

    public function test_a_new_folder_at_the_top_has_no_parent(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->callAction('addFolder', ['name' => 'Legal', 'icon' => 'heroicon-o-scale']);

        $this->assertNull(DocumentCategory::query()->where('name', 'Legal')->firstOrFail()->parent_id);
    }

    public function test_adding_a_document_carries_the_current_folder_into_the_form(): void
    {
        $marketing = $this->folder('Marketing');

        Livewire::actingAs($this->admin())
            ->withQueryParams(['folder' => $marketing->id])
            ->test(CreateDocument::class)
            ->assertFormSet(['document_category_id' => $marketing->id])
            ->fillForm([
                'kind' => DocumentKind::Page->value,
                'title' => 'Flyer copy',
                'body' => '<p>Lead with the plan.</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $marketing->id,
            Document::query()->where('title', 'Flyer copy')->firstOrFail()->document_category_id,
        );
    }

    public function test_a_document_can_be_filed_at_the_top_level(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateDocument::class)
            ->fillForm([
                'kind' => DocumentKind::Page->value,
                'title' => 'Somewhere for now',
                'body' => '<p>Sort this later.</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Document::query()->firstOrFail()->document_category_id);
    }

    public function test_a_folder_is_renamed_and_moved_from_the_explorer(): void
    {
        $marketing = $this->folder('Marketing');
        $business = $this->folder('Business');
        $print = $this->folder('Print', $marketing);

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->callAction('renameFolder', ['name' => 'Print material', 'icon' => 'heroicon-o-photo'], ['folder' => $print->id])
            ->callAction('moveFolder', ['parent_id' => $business->id], ['folder' => $print->id]);

        $print->refresh();

        $this->assertSame('Print material', $print->name);
        $this->assertSame($business->id, $print->parent_id);
    }

    public function test_a_folder_cannot_be_moved_inside_itself(): void
    {
        $marketing = $this->folder('Marketing');
        $print = $this->folder('Print', $marketing);
        $proofs = $this->folder('Proofs', $print);
        $business = $this->folder('Business');

        $offered = DocumentCategory::options(except: $marketing->subtreeIds());

        // Its own subtree is not on offer — that is the move that would strand
        // the branch. Everything else still is.
        $this->assertArrayNotHasKey($marketing->id, $offered);
        $this->assertArrayNotHasKey($print->id, $offered);
        $this->assertArrayNotHasKey($proofs->id, $offered);
        $this->assertArrayHasKey($business->id, $offered);

        // And the label names the place, not just the folder.
        $this->assertSame('Marketing / Print / Proofs', DocumentCategory::options()[$proofs->id]);
    }

    public function test_a_folder_with_anything_in_it_is_not_deleted(): void
    {
        $marketing = $this->folder('Marketing');
        $this->folder('Print', $marketing);

        $withDocument = $this->folder('Business');
        Document::factory()->create(['document_category_id' => $withDocument->id]);

        $empty = $this->folder('Legal');

        $page = Livewire::actingAs($this->admin())->test(ListDocuments::class);

        $page->callAction('deleteFolder', arguments: ['folder' => $marketing->id]);
        $page->callAction('deleteFolder', arguments: ['folder' => $withDocument->id]);
        $page->callAction('deleteFolder', arguments: ['folder' => $empty->id]);

        $this->assertModelExists($marketing);
        $this->assertModelExists($withDocument);
        $this->assertModelMissing($empty);
    }

    public function test_a_document_is_moved_to_another_folder_from_the_table(): void
    {
        $marketing = $this->folder('Marketing');
        $business = $this->folder('Business');

        $document = Document::factory()->create(['document_category_id' => $marketing->id]);

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->call('openFolder', $marketing->id)
            ->callTableAction('move', $document, ['document_category_id' => $business->id]);

        $this->assertSame($business->id, $document->refresh()->document_category_id);
    }

    public function test_an_image_is_previewed_in_the_list(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/logo.png', 'png-bytes');

        $folder = $this->folder('Brand');

        $image = Document::factory()->file('documents/logo.png')->create([
            'document_category_id' => $folder->id,
            'title' => 'Compass mark',
            'mime_type' => 'image/png',
        ]);
        $pdf = Document::factory()->file('documents/flyer.pdf')->create([
            'document_category_id' => $folder->id,
            'title' => 'Partner flyer',
        ]);

        $page = Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->call('openFolder', $folder->id)
            ->assertOk();

        // The thumbnail is the file itself, served by the admin-gated route —
        // there is no public URL for it, which is the whole point. Matched on
        // the <img> attribute rather than on the bare URL, because every file
        // row also links to that same route from its "Open" action.
        $page->assertSee('src="'.route('documents.download', $image).'"', escape: false);
        $page->assertDontSee('src="'.route('documents.download', $pdf).'"', escape: false);

        $this->assertTrue($image->isImage());
        $this->assertFalse($pdf->isImage());
    }
}
