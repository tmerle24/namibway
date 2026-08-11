<x-filament-panels::page>
    @if (! $property)
        <x-filament::section>
            <x-slot name="heading">No property yet</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Rates belong to a property. Once a lodge, camp or guesthouse is on your account it appears
                here, and in the property switcher at the top of the page.
            </p>
        </x-filament::section>
    @elseif (! $hasBookableUnits)
        <x-filament::section>
            <x-slot name="heading">No room types yet</x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ $property->name }} has no room types, and a rate belongs to one. Room types — how many
                units of each, and what they cost a night — are what the calendar is built from.
            </p>
        </x-filament::section>
    @else
        <form wire:submit="apply">
            {{ $this->form }}

            <div class="mt-6 flex items-center gap-3">
                <x-filament::button type="submit">
                    Apply to these nights
                </x-filament::button>

                <span class="text-sm text-gray-500 dark:text-gray-400" wire:loading wire:target="apply">
                    Writing …
                </span>
            </div>
        </form>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">How this behaves</x-slot>

            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                <p>
                    <strong class="font-medium text-gray-950 dark:text-white">An empty field is left alone.</strong>
                    Setting a weekend rate will not clear a minimum stay you set last week. To clear something,
                    choose it explicitly — "No minimum", "Allowed", or "Back to the room type’s own total".
                </p>
                <p>
                    <strong class="font-medium text-gray-950 dark:text-white">Both dates count as nights.</strong>
                    A single night is the same date in both fields.
                </p>
                <p>
                    <strong class="font-medium text-gray-950 dark:text-white">Bookings are never touched.</strong>
                    Changing a rate does not change what an existing stay was priced at, and it cannot alter how
                    many units are already sold.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
