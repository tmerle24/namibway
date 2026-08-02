<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row table (see MessageSettings::current()) — admin-configurable
        // sign-off appended to outgoing "Contact owner" messages.
        Schema::create('message_settings', function (Blueprint $table) {
            $table->id();
            $table->text('signature')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_settings');
    }
};
