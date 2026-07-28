<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\TripFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AfterSalesController extends Controller
{
    public function support(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'message' => 'required|string|max:2000',
            'trip_id' => 'nullable|integer|exists:trips,id',
        ]);

        SupportMessage::create($validated);

        $adminEmail = config('mail.admin_address');

        if ($adminEmail) {
            Mail::raw(
                "Support request from {$validated['name']} <{$validated['email']}>\n\n{$validated['message']}",
                fn ($m) => $m
                    ->to($adminEmail)
                    ->replyTo($validated['email'], $validated['name'])
                    ->subject('NamibWay support: '.$validated['name']),
            );
        }

        return response()->json(['ok' => true]);
    }

    public function feedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'message' => 'required|string|max:2000',
            'rating' => 'nullable|integer|min:1|max:5',
            'trip_id' => 'nullable|integer|exists:trips,id',
        ]);

        TripFeedback::create($validated);

        return response()->json(['ok' => true]);
    }
}
