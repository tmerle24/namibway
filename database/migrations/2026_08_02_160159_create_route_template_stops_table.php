<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('route_template_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_template_id')->constrained()->cascadeOnDelete();
            $table->string('region');
            $table->unsignedInteger('min_nights')->default(1);
            $table->unsignedInteger('max_nights')->default(1);
            $table->string('highlights')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_template_stops');
    }
};
