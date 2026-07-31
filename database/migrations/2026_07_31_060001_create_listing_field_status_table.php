<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_field_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('status'); // missing | ai_generated | complete | verified
            $table->timestamps();

            $table->unique(['listing_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_field_status');
    }
};
