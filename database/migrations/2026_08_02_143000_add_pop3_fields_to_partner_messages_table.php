<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_messages', function (Blueprint $table) {
            // Dedup key for POP3 polling (the message's Message-ID header, or a
            // fallback hash) — POP3 has no reliable server-side "seen" flag across
            // polls, so we track what we've already imported ourselves instead of
            // deleting mail off the server.
            $table->string('source_uid')->nullable()->unique()->after('template');
            $table->timestamp('read_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('partner_messages', function (Blueprint $table) {
            $table->dropColumn(['source_uid', 'read_at']);
        });
    }
};
