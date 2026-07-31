<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('source'); // website | google_places | ai_extract | ai_description | manual
            $table->boolean('success')->default(false);
            $table->text('log')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->decimal('cost_estimate', 8, 4)->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_jobs');
    }
};
