<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's own domain, and how far it has got.
 *
 * Separate from `host` rather than replacing it. The subdomain we issue is
 * permanent: it is what the draft was reviewed on, what a link in an old email
 * still points at, and what keeps working on the day a customer lets their own
 * registration lapse. Their domain is an addition to it, not a substitute.
 *
 * `domain_status` is the state machine both halves of this read - the
 * application, which only ever looks up DNS and writes what it saw, and the
 * root-side reconciler, which is the only thing allowed to run certbot and
 * touch nginx. See DEPLOYMENT.md, "Custom domains".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->unique()->after('host');
            $table->string('domain_status', 32)->nullable()->after('custom_domain');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_status');
            $table->text('domain_message')->nullable()->after('domain_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['custom_domain', 'domain_status', 'domain_checked_at', 'domain_message']);
        });
    }
};
