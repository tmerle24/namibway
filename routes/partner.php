<?php

use App\Http\Controllers\PartnerController;
use Illuminate\Support\Facades\Route;

Route::get('/partner/inquiries/{inquiry}/confirm', [PartnerController::class, 'confirm'])
    ->name('partner.inquiries.confirm')
    ->middleware('signed');

Route::get('/partner/inquiries/{inquiry}/cancel', [PartnerController::class, 'cancel'])
    ->name('partner.inquiries.cancel')
    ->middleware('signed');
