<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's own website — the product behind the N$ 399/month offer.
 *
 * ## Why this owns its data instead of reading a listing
 *
 * The flyer promises that if the customer leaves, "we hand you your domain and
 * your content". That is only true if the content is theirs to hand over. A
 * page rendered live out of somebody else's `listings` row is not — it changes
 * when we change the listing and disappears when we delete it.
 *
 * The second reason is who this is sold to. Half the customers on the flyer are
 * a workshop, a shop, a restaurant with nothing bookable about it, and they
 * will never have a listing at all. A website that needs one has no data source
 * for them, and building a second path for those customers would double the
 * product for no gain.
 *
 * So a listing is an **import source at creation time**, recorded here in
 * `source_listing_id` for provenance and for the booking block, and nothing
 * else. Content is copied once and belongs to the site afterwards. Later
 * listing edits do not propagate in, and site edits never propagate back out.
 *
 * ## Host
 *
 * One column, matched exactly, rather than a subdomain pattern with custom
 * domains bolted on later. The flyer sells the domain as part of the monthly
 * fee, so a customer's own .com.na is the normal end state and not an
 * exception; making both the same lookup means moving a site onto its real
 * domain is an UPDATE. Nullable because a site exists before DNS does, and is
 * reviewed at /_sites/{slug} until then.
 *
 * ## Draft, and why a draft needs a token
 *
 * Sites are generated for prospecting, before anybody has agreed to anything —
 * that is the whole point of a draft. A draft is research about a business, and
 * publishing it under that business's name is something only they can agree to.
 * So a draft renders only for somebody holding `draft_token`, and carries
 * noindex regardless. The host alone is deliberately not enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            // Set where the customer is also a NamibWay partner. Null is a
            // perfectly ordinary state — see above, a shop has no partner —
            // which is why nothing here may be reached through this column.
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();

            // Where the content came from, once. Kept after the import for two
            // reasons: the booking block needs to know which property it is
            // selling, and a regeneration needs to find the same site again.
            // nullOnDelete rather than cascade: deleting a listing must never
            // take a paying customer's website with it.
            $table->foreignId('source_listing_id')->nullable()->constrained('listings')->nullOnDelete();

            $table->string('business_type');
            $table->string('name');
            $table->string('slug')->unique();

            $table->string('host')->nullable()->unique();

            $table->string('status')->default('draft');
            $table->string('draft_token', 64)->unique();
            $table->timestamp('published_at')->nullable();

            $table->string('accent')->default('copper');

            // The site's own language. A second language is a second page row
            // under the same site, not a translated payload — the flyer sells
            // "English and German, further languages on request", so the model
            // has to allow it even though slice 1 generates one.
            $table->string('default_locale', 5)->default('en');

            // Contact details live on the site rather than in a block payload:
            // the header, the contact block, the footer and the WhatsApp button
            // all render the same phone number, and three copies of it is three
            // places to forget when it changes.
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('social_links')->nullable();

            // What generation wrote, field by field, the last time it ran.
            // Re-running compares against this and leaves anything since edited
            // alone, reporting it instead of overwriting it — the same rule the
            // namibweb importer follows, and for the same reason: the point of
            // the draft is that somebody improves it afterwards.
            $table->json('imported')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
