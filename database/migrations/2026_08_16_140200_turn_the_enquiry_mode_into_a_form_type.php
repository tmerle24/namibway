<?php

use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `mode` on the enquiry block becomes `form_type`.
 *
 * The old field held `stay` or `visit` and was set from the business type at
 * generation. It distinguished two of the five things a contact form can be —
 * see `App\Sites\Blocks\EnquiryFormType` — so the values map cleanly:
 *
 * - `stay`  → a reservation request (arrival, departure, guests)
 * - `visit` → a table reservation (date, time, party)
 *
 * A generated site is the only thing that ever wrote `visit`, and it wrote it
 * for restaurants and activity operators alike. A table booking is the right
 * reading for a restaurant and the near-enough one for an activity, which the
 * owner can now change to something that fits — which is the point of the
 * field being a choice rather than a derivation.
 *
 * `channel` is set to email for every existing block: that is what they all
 * rendered, and the WhatsApp button beside the form goes away with this change
 * rather than silently becoming the whole form.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('site_blocks')->where('type', 'enquiry')->get(['id', 'data']) as $block) {
            $data = json_decode((string) $block->data, true);

            if (! is_array($data)) {
                continue;
            }

            $mode = $data['mode'] ?? null;

            $data['form_type'] = $mode === 'visit'
                ? EnquiryFormType::TableReservation->value
                : EnquiryFormType::StayRequest->value;

            $data['channel'] = EnquiryBlock::CHANNEL_EMAIL;
            unset($data['mode']);

            DB::table('site_blocks')->where('id', $block->id)->update(['data' => json_encode($data)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('site_blocks')->where('type', 'enquiry')->get(['id', 'data']) as $block) {
            $data = json_decode((string) $block->data, true);

            if (! is_array($data)) {
                continue;
            }

            // Everything that is not a table booking collapses back to `stay` —
            // the old field had nowhere to put an order, which is why it is
            // being replaced.
            $data['mode'] = ($data['form_type'] ?? null) === EnquiryFormType::TableReservation->value
                ? 'visit'
                : 'stay';

            unset($data['form_type'], $data['channel'], $data['button_label']);

            DB::table('site_blocks')->where('id', $block->id)->update(['data' => json_encode($data)]);
        }
    }
};
