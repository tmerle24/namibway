<?php

namespace App\Filament\Partner\Resources\CustomerResource\Pages;

use App\Filament\Partner\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * One customer: who they are, everything they have stayed, and what staff have
 * written about them.
 *
 * Written as a page with a view rather than an infolist plus two relation
 * managers, because the whole point is that a receptionist sees all three at
 * once while somebody waits at the counter. Three collapsible panels is the
 * version of this screen that gets scrolled instead of read.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected static string $view = 'filament.partner.pages.view-customer';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('addNote')
                ->label('Add a note')
                ->icon('heroicon-o-pencil-square')
                ->modalWidth('lg')
                ->form([
                    Textarea::make('body')
                        ->label('Note')
                        ->required()
                        ->rows(4)
                        ->maxLength(2000)
                        ->placeholder('Asks for a ground-floor room. Allergic to nuts. Pays in cash.'),
                ])
                ->action(function (array $data): void {
                    /** @var User|null $author */
                    $author = auth()->user();

                    Note::write($this->getRecord(), (string) $data['body'], $author);

                    Notification::make()->success()->title('Note added')->send();
                }),
        ];
    }

    /**
     * Every stay this person has had here, newest first — which is the order a
     * desk asks about them in.
     *
     * @return Collection<int, Reservation>
     */
    public function stays(): Collection
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return Reservation::query()
            ->where('customer_id', $customer->id)
            ->with(['listing', 'units.roomType'])
            ->orderByDesc('check_in')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, Note>
     */
    public function noteLog(): Collection
    {
        /** @var Customer $customer */
        $customer = $this->getRecord();

        return $customer->notes()->limit(50)->get();
    }
}
