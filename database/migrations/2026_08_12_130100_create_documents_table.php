<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One document the team keeps: either an uploaded file or a page written in the
 * admin panel (see App\Enums\DocumentKind for why both live in one table).
 *
 * ## Why the file columns are not on the 'r2' disk
 *
 * Everything else the app stores as media is catalogue content meant to be
 * served to travellers, so it goes to the public 'r2' bucket. This is the
 * opposite kind of file — a partner contract, a rate sheet, an unsigned offer —
 * and Cloudflare R2 has no per-object visibility: a bucket is public or it is
 * not, and ours is. So an object put there is readable by anyone who guesses
 * its key, and "the key is a UUID" is not an access rule.
 *
 * Documents therefore go on the private 'local' disk and are only ever served
 * through App\Http\Controllers\DocumentDownloadController, which checks the
 * signed-in account first. The disk is recorded per row rather than assumed, so
 * moving to a genuinely private bucket later is a config change plus a
 * backfill, not a migration of every path. storage/app/private/documents is
 * included in the nightly backup (config/backup.php) — unlike R2, the app
 * server is not durable storage on its own.
 *
 * `author_*` and `editor_*` freeze the name beside the account id for the same
 * reason `notes` does: a deleted staff account must not turn last quarter's
 * filing anonymous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_category_id')
                ->constrained()
                // A folder with documents in it cannot be deleted out from
                // under them. The admin panel refuses first with an
                // explanation; this is the backstop behind that.
                ->restrictOnDelete();

            $table->string('kind')->default('file');
            $table->string('title');
            $table->text('description')->nullable();

            // Written pages only: HTML from the rich editor.
            $table->longText('body')->nullable();

            // Uploaded files only.
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name');
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('editor_name')->nullable();

            $table->timestamps();

            $table->index(['document_category_id', 'title']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
