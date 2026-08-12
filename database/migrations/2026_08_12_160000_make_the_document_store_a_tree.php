<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the flat folder list into a directory tree, and lets a document sit
 * outside a folder.
 *
 * Both changes come from the same decision: the store is browsed like a file
 * explorer, not read like a table. You are always somewhere — at the top or
 * inside a folder — and what you create is created *there*. That needs a parent
 * on a folder, and it needs "the top level" to be a real place a document can
 * be, rather than a level that only holds folders.
 *
 * The original migration said a tree could be added later with one nullable
 * column and that removing one would be the harder direction. This is that
 * column; nothing that exists has to move, because a folder with no parent is
 * exactly what every folder already was.
 *
 * `restrictOnDelete` on the parent for the same reason the documents FK has it:
 * deleting a folder must never quietly take a subtree with it. The panel empties
 * a folder first, and this is the backstop behind that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('document_categories')
                ->restrictOnDelete();

            // What one level of the explorer reads: the folders inside one
            // folder, by name.
            $table->index(['parent_id', 'name'], 'document_categories_level_index');
        });

        Schema::table('document_categories', function (Blueprint $table) {
            // Hand-ordering goes with the flat list it was made for. A tree is
            // read the way every file explorer reads one — alphabetically — and
            // a column nothing sorts by is a column that misleads the next
            // reader into thinking it does.
            $table->dropColumn('position');
        });

        Schema::table('documents', function (Blueprint $table) {
            // Null means the top level. A document has always had to be in a
            // folder, which made "put this somewhere for now" impossible — the
            // one thing a filing cabinet has to allow, or people file into
            // whatever folder is nearest and it is worse than the top level.
            $table->unsignedBigInteger('document_category_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_category_id')->nullable(false)->change();
        });

        Schema::table('document_categories', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0);
            $table->dropIndex('document_categories_level_index');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
