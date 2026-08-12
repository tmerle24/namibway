<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own notifications table, so the panels can tell somebody what
 * happened while they were not looking.
 *
 * Added because of a concrete failure: pressing "Create website" queued a job
 * and said "we will email you". When that job then skipped every photograph,
 * nothing in the panel said so — the button had reported success, the site was
 * empty, and the only account of why was in an email nobody had read yet. A
 * background job that can partly fail needs somewhere to say so that is not the
 * queue log.
 *
 * Standard shape, straight from `php artisan notifications:table`, so anything
 * else in either panel can use it without a second mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            // `jsonb`, not the `text` Laravel's own stub writes. Filament's
            // notification bell queries `data->>'format'`, and Postgres has no
            // `->>` operator on text — the panel 500s on every page the moment
            // the bell is switched on. The stock stub is written for MySQL,
            // where it happens not to matter.
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
