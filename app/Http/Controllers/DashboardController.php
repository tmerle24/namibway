<?php

namespace App\Http\Controllers;

use App\Models\SavedPlan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $plans = SavedPlan::query()
            ->where('user_id', $request->user()?->id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'token', 'title', 'created_at']);

        return Inertia::render('Dashboard', [
            'plans' => $plans,
        ]);
    }
}
