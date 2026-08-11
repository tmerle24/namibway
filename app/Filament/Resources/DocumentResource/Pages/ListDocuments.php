<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentCategory;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

/**
 * The document store, browsed the way a file explorer is browsed: you are
 * always somewhere, you see the folders and the documents of that one place,
 * and whatever you make is made there.
 *
 * Built on the resource's list page rather than as a page of its own, so the
 * documents keep Filament's table — search, sort, row actions, bulk move and
 * delete — while the folders and the breadcrumb are rendered above it by
 * `filament.pages.browse-documents`. A hand-written table of both would have
 * cost all of that to gain one merged list.
 *
 * The current folder lives in the URL (`?folder=`), so a link to a folder is a
 * link somebody can send, and the browser's back button walks back up the tree.
 */
class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected static string $view = 'filament.pages.browse-documents';

    /** Null is the top level, which is a place and not an absence. */
    #[Url]
    public ?int $folder = null;

    public function mount(): void
    {
        parent::mount();

        // A folder that was deleted (or never existed) drops you at the top
        // rather than showing an empty screen that cannot explain itself.
        if ($this->folder !== null && ! DocumentCategory::query()->whereKey($this->folder)->exists()) {
            $this->folder = null;
        }
    }

    /** The place you are in, which is the only title this page needs. */
    public function getTitle(): string
    {
        return $this->currentFolder()?->name ?? 'Documents';
    }

    /**
     * No panel breadcrumbs: "Documents / List" says nothing the folder path
     * above the listing does not say better.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** The folder being looked at, or null at the top level. */
    public function currentFolder(): ?DocumentCategory
    {
        return $this->folder === null
            ? null
            : DocumentCategory::query()->find($this->folder);
    }

    /**
     * The trail from the top down to here.
     *
     * @return list<DocumentCategory>
     */
    public function breadcrumbTrail(): array
    {
        return $this->currentFolder()?->path() ?? [];
    }

    /**
     * The folders inside this one.
     *
     * @return Collection<int, DocumentCategory>
     */
    public function folders(): Collection
    {
        return DocumentCategory::query()
            ->where('parent_id', $this->folder)
            ->withCount(['children', 'documents'])
            ->orderBy('name')
            ->get();
    }

    /** Walk into a folder, or back to the top with null. */
    public function openFolder(?int $folder): void
    {
        $this->folder = $folder;

        // The table is scoped to the folder, so it has to be told the ground
        // moved — and a page-2 cursor from the previous folder is meaningless
        // here.
        $this->resetTable();
    }

    /**
     * Only what is filed in this folder. Everything else about the table —
     * columns, search, actions — stays in the resource.
     *
     * Read on every render (ListRecords wires this as a closure), so walking
     * into a folder re-scopes the table without rebuilding it.
     *
     * @return Builder<Document>
     */
    protected function getTableQuery(): Builder
    {
        /** @var Builder<Document> $query */
        $query = parent::getTableQuery();

        return $query->where('document_category_id', $this->folder);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add document')
                ->icon('heroicon-o-document-plus')
                // Carries the current place into the create form, which is the
                // whole point of the explorer: file it where you are standing.
                ->url(fn (): string => DocumentResource::getUrl('create', ['folder' => $this->folder])),
            Action::make('addFolder')
                ->label('Add folder')
                ->icon('heroicon-o-folder-plus')
                ->color('gray')
                ->modalWidth('lg')
                ->modalHeading(fn (): string => $this->folder === null
                    ? 'New folder at the top level'
                    : 'New folder in '.($this->currentFolder()?->name ?? ''))
                ->modalSubmitActionLabel('Create folder')
                ->form($this->folderForm())
                ->action(function (array $data): void {
                    DocumentCategory::create([
                        'parent_id' => $this->folder,
                        'name' => $data['name'],
                        'icon' => $data['icon'] ?? 'heroicon-o-folder',
                        'description' => $data['description'] ?? null,
                    ]);

                    Notification::make()->success()->title('Folder created')->send();
                }),
        ];
    }

    /** Rename a folder, or change what it says it is for. */
    public function renameFolderAction(): Action
    {
        return Action::make('renameFolder')
            ->label('Rename')
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->tooltip('Rename')
            ->modalWidth('lg')
            ->modalHeading('Rename folder')
            ->fillForm(fn (array $arguments): array => $this->folderFromArguments($arguments)
                ->only(['name', 'icon', 'description']))
            ->form($this->folderForm())
            ->action(function (array $arguments, array $data): void {
                $this->folderFromArguments($arguments)->update([
                    'name' => $data['name'],
                    'icon' => $data['icon'] ?? 'heroicon-o-folder',
                    'description' => $data['description'] ?? null,
                ]);

                Notification::make()->success()->title('Folder renamed')->send();
            });
    }

    /** Move a folder somewhere else in the tree, subtree and all. */
    public function moveFolderAction(): Action
    {
        return Action::make('moveFolder')
            ->label('Move to…')
            ->icon('heroicon-o-arrow-right-circle')
            ->iconButton()
            ->tooltip('Move to…')
            ->modalWidth('lg')
            ->modalHeading('Move folder')
            ->modalSubmitActionLabel('Move')
            ->fillForm(fn (array $arguments): array => [
                'parent_id' => $this->folderFromArguments($arguments)->parent_id,
            ])
            ->form(fn (array $arguments): array => [
                Forms\Components\Select::make('parent_id')
                    ->label('Inside')
                    // Its own subtree is left out: a folder moved into itself is
                    // a branch nobody can reach again.
                    ->options(fn (): array => DocumentCategory::options(
                        except: $this->folderFromArguments($arguments)->subtreeIds(),
                    ))
                    ->placeholder('Top level')
                    ->native(false)
                    ->searchable(),
            ])
            ->action(function (array $arguments, array $data): void {
                $this->folderFromArguments($arguments)->update([
                    'parent_id' => $data['parent_id'] ?? null,
                ]);

                Notification::make()->success()->title('Folder moved')->send();
            });
    }

    /** Delete a folder — only once there is nothing left in it. */
    public function deleteFolderAction(): Action
    {
        return Action::make('deleteFolder')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->iconButton()
            ->tooltip('Delete')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete folder')
            ->modalDescription('Only an empty folder can be deleted. Move or delete what is in it first.')
            ->action(function (array $arguments): void {
                $folder = $this->folderFromArguments($arguments);

                if (! $folder->isEmpty()) {
                    Notification::make()
                        ->danger()
                        ->title('That folder is not empty')
                        ->body('Move or delete what is in it, then delete the folder.')
                        ->send();

                    return;
                }

                $folder->delete();

                Notification::make()->success()->title('Folder deleted')->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function folderFromArguments(array $arguments): DocumentCategory
    {
        return DocumentCategory::query()->findOrFail($arguments['folder'] ?? null);
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private function folderForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('e.g. Contracts'),
            Forms\Components\Select::make('icon')
                ->label('Icon')
                ->options(DocumentCategory::icons())
                ->default('heroicon-o-folder')
                ->native(false),
            Forms\Components\Textarea::make('description')
                ->label('What belongs in here')
                ->rows(2)
                ->maxLength(500)
                ->helperText('Optional. One line is what stops the next person filing into the wrong folder.'),
        ];
    }
}
