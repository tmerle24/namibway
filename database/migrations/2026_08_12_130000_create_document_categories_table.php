<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The folders of the team's own document store — "Marketing", "Business",
 * "Brand assets".
 *
 * Flat rather than a tree on purpose. A nesting level costs a parent column and
 * gains nothing until somebody actually has forty folders; a small team filing
 * contracts and logos does not, and a two-level path is a worse way to find
 * something than a search box over a flat list. If it ever needs a tree, adding
 * a nullable `parent_id` here is a smaller change than removing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Heroicon name, so a folder can be recognised at a glance in a
            // list that is otherwise all text.
            $table->string('icon')->nullable();

            // Hand-ordered: the useful order of folders is the team's, not the
            // alphabet's.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['position', 'name']);
        });

        // The three the store was asked for, so the first person to file
        // something does not have to invent a filing system first. They are
        // ordinary rows — rename them, reorder them, delete the ones nobody
        // uses.
        $now = now();

        DB::table('document_categories')->insert([
            [
                'name' => 'Marketing',
                'slug' => 'marketing',
                'description' => 'Flyers, one-pagers, presentations and anything else shown to a prospect.',
                'icon' => 'heroicon-o-megaphone',
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Contracts, offers, registrations and the paperwork behind them.',
                'icon' => 'heroicon-o-briefcase',
                'position' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Brand & logos',
                'slug' => 'brand-logos',
                'description' => 'The compass mark, wordmarks, colours and how they may be used.',
                'icon' => 'heroicon-o-photo',
                'position' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
