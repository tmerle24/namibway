<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripPlan extends Model
{
    protected $fillable = ['token', 'plan_data', 'expires_at'];

    protected $casts = [
        'plan_data' => 'array',
        'expires_at' => 'datetime',
    ];
}
