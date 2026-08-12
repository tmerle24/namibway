<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our own terms for the website product.
 *
 * A row per version rather than a settings row that gets edited, because these
 * are the terms somebody agreed to. `sites.terms_accepted_at` records the
 * moment; without a version beside it that record says nothing — "they accepted
 * the terms" is only meaningful if the text they accepted still exists.
 *
 * The current version is the most recently published one. An unpublished row is
 * a draft being worked on, which matters here: this text goes to a lawyer
 * before it means anything, and half-edited terms must not be what a customer
 * is shown in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_terms', function (Blueprint $table) {
            $table->id();

            // Shown to the customer and stored beside their acceptance, so a
            // question about what was agreed has an answer. Free text: legal
            // versions are "1.0", "1.1", "2.0-draft", not integers.
            $table->string('version', 20);

            $table->text('body');

            // The date the version takes effect, which is not the date it was
            // typed and not the date it was published.
            $table->date('effective_at')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_terms');
    }
};
