<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'phone')) {
                $table->string('phone')->nullable()->after('contact_email');
            }
            if (! Schema::hasColumn('listings', 'address')) {
                $table->string('address')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('listings', 'scrape_source')) {
                $table->string('scrape_source')->nullable()->after('address');
            }
            if (! Schema::hasColumn('listings', 'scraped_at')) {
                $table->timestamp('scraped_at')->nullable()->after('scrape_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $cols = array_filter(['phone', 'address', 'scrape_source', 'scraped_at'], fn ($c) => Schema::hasColumn('listings', $c));
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
