<?php

namespace Tests\Feature\Filament;

use App\Enums\DocumentKind;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The team's document store: folders, uploaded files, pages written in the
 * panel, and the comment log that hangs off both.
 *
 * The load-bearing parts, in the order they would hurt if they broke: a
 * document must never be reachable without signing in (the files are on the
 * private disk precisely so this route is the only way to them), a page must
 * survive an edit without losing its body, and deleting a document must take
 * its file and its comments with it.
 */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $name = 'Till'): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => $name]);
    }

    private function folder(string $name = 'Business papers', ?DocumentCategory $parent = null): DocumentCategory
    {
        return DocumentCategory::create([
            'name' => $name,
            'parent_id' => $parent?->id,
        ]);
    }

    public function test_a_folder_names_itself_in_the_url(): void
    {
        $folder = $this->folder('Marketing material');

        $this->assertSame('marketing-material', $folder->slug);

        $second = DocumentCategory::create(['name' => 'Marketing Material 2']);
        $this->assertNotSame($folder->slug, $second->slug);
    }

    public function test_a_page_is_written_in_the_panel_and_kept(): void
    {
        $folder = $this->folder('Wiki');

        Livewire::actingAs($this->admin('Till'))
            ->test(CreateDocument::class)
            ->fillForm([
                'kind' => DocumentKind::Page->value,
                'document_category_id' => $folder->id,
                'title' => 'How we answer a lodge that has never heard of us',
                'description' => 'The script, and what not to promise.',
                'body' => '<p>Lead with the plan, not the commission.</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = Document::query()->firstOrFail();

        $this->assertSame(DocumentKind::Page, $document->kind);
        $this->assertStringContainsString('Lead with the plan', (string) $document->body);
        $this->assertSame('Till', $document->author_name);
        // A page has no file, and must not claim one.
        $this->assertNull($document->path);
        $this->assertFalse($document->isFile());
    }

    public function test_an_uploaded_file_records_what_it_actually_is(): void
    {
        Storage::fake('local');

        $folder = $this->folder('Brand');

        Livewire::actingAs($this->admin())
            ->test(CreateDocument::class)
            ->fillForm([
                'kind' => DocumentKind::File->value,
                'document_category_id' => $folder->id,
                'title' => 'Compass mark, dark background',
            ])
            ->set('data.path', [UploadedFile::fake()->createWithContent('namibway-logo.pdf', 'PDF-ish bytes')])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = Document::query()->firstOrFail();

        $this->assertSame(DocumentKind::File, $document->kind);
        $this->assertSame(Document::DISK, $document->disk);
        $this->assertNotNull($document->path);
        Storage::disk('local')->assertExists((string) $document->path);

        // The name it was uploaded under survives the random storage name, and
        // the size is read back off the disk rather than believed from the
        // browser.
        $this->assertSame('namibway-logo.pdf', $document->original_name);
        $this->assertSame(strlen('PDF-ish bytes'), $document->size);
        $this->assertNotNull($document->humanSize());
    }

    public function test_editing_a_page_records_who_changed_it_without_losing_the_author(): void
    {
        $document = Document::factory()->create([
            'document_category_id' => $this->folder()->id,
            'author_name' => 'Till',
            'body' => '<p>First draft.</p>',
        ]);

        Livewire::actingAs($this->admin('Anna'))
            ->test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->fillForm(['body' => '<p>Second draft.</p>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $document->refresh();

        $this->assertStringContainsString('Second draft', (string) $document->body);
        $this->assertSame('Anna', $document->editor_name);
        $this->assertSame('Till', $document->author_name);
        // The kind field is disabled on edit, so saving must not reset it.
        $this->assertSame(DocumentKind::Page, $document->kind);
    }

    public function test_anyone_on_the_team_can_comment_and_the_comment_keeps_its_author(): void
    {
        $document = Document::factory()->create([
            'document_category_id' => $this->folder()->id,
        ]);

        Livewire::actingAs($this->admin('Anna'))
            ->test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertOk()
            ->callAction('comment', ['body' => 'Superseded by the 2027 version.']);

        $comment = $document->notes()->firstOrFail();

        $this->assertSame('Superseded by the 2027 version.', $comment->body);
        $this->assertSame('Anna', $comment->author_name);
    }

    public function test_the_view_page_shows_the_document_and_its_comments(): void
    {
        $document = Document::factory()->create([
            'document_category_id' => $this->folder('Business papers')->id,
            'title' => 'Partner agreement 2026',
            'body' => '<p>Commission is five percent.</p>',
        ]);

        Note::write($document, 'Legal has not seen this yet.', $this->admin('Till'));

        Livewire::actingAs($this->admin())
            ->test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertOk()
            ->assertSee('Partner agreement 2026')
            ->assertSee('Commission is five percent', escape: false)
            ->assertSee('Legal has not seen this yet.')
            ->assertSee('Business papers');
    }

    public function test_the_store_opens_with_the_three_folders_it_was_asked_for(): void
    {
        // Listed alphabetically, which is how the explorer reads a level.
        $this->assertSame(
            ['Brand & logos', 'Business', 'Marketing'],
            DocumentCategory::query()->orderBy('name')->pluck('name')->all(),
        );

        $this->assertSame([null, null, null], DocumentCategory::query()->pluck('parent_id')->all());
    }

    public function test_the_list_shows_both_kinds_side_by_side(): void
    {
        $folder = $this->folder('Everything');

        $page = Document::factory()->create([
            'document_category_id' => $folder->id,
            'title' => 'Brand voice',
        ]);
        $file = Document::factory()->file()->create([
            'document_category_id' => $folder->id,
            'title' => 'Partner flyer',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ListDocuments::class)
            ->call('openFolder', $folder->id)
            ->assertOk()
            ->assertCanSeeTableRecords([$page, $file]);
    }

    public function test_a_filed_file_is_shown_with_a_way_to_open_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/flyer.pdf', 'bytes');

        $document = Document::factory()->file('documents/flyer.pdf')->create([
            'document_category_id' => $this->folder()->id,
            'title' => 'Partner flyer',
            'original_name' => 'namibway-partner-flyer.pdf',
        ]);

        Livewire::actingAs($this->admin())
            ->test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertOk()
            ->assertSee('namibway-partner-flyer.pdf')
            ->assertSee(route('documents.download', $document), escape: false);
    }

    public function test_only_a_signed_in_admin_can_open_a_filed_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/contract.pdf', 'signed');

        $document = Document::factory()->file('documents/contract.pdf')->create([
            'document_category_id' => $this->folder()->id,
        ]);

        $url = route('documents.download', $document);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get($url)
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_page_has_nothing_to_download(): void
    {
        $document = Document::factory()->create([
            'document_category_id' => $this->folder()->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('documents.download', $document))
            ->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_rather_than_a_broken_stream(): void
    {
        Storage::fake('local');

        $document = Document::factory()->file('documents/gone.pdf')->create([
            'document_category_id' => $this->folder()->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('documents.download', $document))
            ->assertNotFound();
    }

    public function test_deleting_a_document_takes_its_file_and_its_comments_with_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/old.pdf', 'bytes');

        $document = Document::factory()->file('documents/old.pdf')->create([
            'document_category_id' => $this->folder()->id,
        ]);

        Note::write($document, 'Out of date.', $this->admin());

        $document->delete();

        Storage::disk('local')->assertMissing('documents/old.pdf');
        $this->assertSame(0, Note::query()->count());
    }

    public function test_a_folder_with_documents_in_it_cannot_be_deleted(): void
    {
        $folder = $this->folder('Business');
        Document::factory()->create(['document_category_id' => $folder->id]);

        $this->expectException(QueryException::class);

        $folder->delete();
    }
}
