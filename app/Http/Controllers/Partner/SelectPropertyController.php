<?php

namespace App\Http\Controllers\Partner;

use App\Filament\Partner\Support\SelectedProperty;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The partner panel's property switcher submits here. A plain form post rather
 * than a Livewire component: the switcher sits in the panel's topbar on every
 * page, and a full round trip is the one behaviour that is identical whichever
 * page it is submitted from.
 *
 * Ownership is checked inside SelectedProperty::select(), which only ever
 * stores an id belonging to the signed-in user's partner.
 */
class SelectPropertyController extends Controller
{
    public function __invoke(Request $request, SelectedProperty $properties): RedirectResponse
    {
        $properties->select($request->integer('property') ?: null);

        return back();
    }
}
