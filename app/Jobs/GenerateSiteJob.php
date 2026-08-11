<?php

namespace App\Jobs;

use App\Mail\SiteReady;
use App\Models\Listing;
use App\Sites\Generation\SiteGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * One click, and a website exists.
 *
 * ## Why this is a job and not a controller action
 *
 * Generation copies photographs between bucket objects, and a lodge with a full
 * gallery is a dozen round trips to R2. That is a few seconds — long enough that
 * a button which waits for it feels broken, and long enough that a request
 * timeout would leave a half-built site behind.
 *
 * So the click returns immediately and the mail arrives when the site is
 * actually there. That is also what was promised: press the button, get a
 * message with everything in it shortly afterwards.
 *
 * ## Rerunning is safe
 *
 * SiteGenerator refreshes what it wrote and never touches what has been edited
 * since, so a second click on the same listing is not a second site and not a
 * lost correction — it is a refresh, and the mail says what it left alone.
 */
class GenerateSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param  string|null  $notify  where to send the "it is ready" mail, or null to send none
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly ?string $notify = null,
    ) {}

    public function handle(): void
    {
        $generator = new SiteGenerator;
        $site = $generator->fromListing($this->listing);

        if ($this->notify === null) {
            return;
        }

        Mail::to($this->notify)->send(new SiteReady($site, $generator->report()));
    }
}
