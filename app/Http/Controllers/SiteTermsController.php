<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\WebsiteTerms;
use App\Sites\LegalText;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The business confirming its own website, from a link in an email.
 *
 * Publishing puts somebody's name on a page we wrote, so the confirmation
 * ought to come from them. Until this existed it was a checkbox in our own
 * panel, which records what we were told rather than what they did.
 *
 * Plain Blade rather than Inertia, unlike the partner confirm/decline pages:
 * this one is reached from an email by somebody who may never have seen the
 * platform, it has to render the legal text they are agreeing to, and a
 * JavaScript bundle adds nothing to a page whose whole job is to be read.
 *
 * The GET only shows. The POST is what acts — a mail client that prefetches
 * links must not be able to publish somebody's website by opening the message.
 */
class SiteTermsController extends Controller
{
    public function show(Site $site): Response
    {
        return response()->view('sites.confirm', $this->payload($site));
    }

    public function confirm(Request $request, Site $site): Response
    {
        $validated = $request->validate([
            'confirmed_by' => ['required', 'string', 'max:255'],
        ]);

        if ($site->terms_accepted_at !== null) {
            return response()->view('sites.confirm', $this->payload($site));
        }

        $terms = WebsiteTerms::current();

        // Recorded before the attempt, so a publish the gate refuses does not
        // lose the confirmation that was just given.
        $site->forceFill([
            'terms_accepted_at' => now(),
            'terms_accepted_by' => trim($validated['confirmed_by']),
            'terms_version' => $terms?->version,
        ])->save();

        $problem = null;

        try {
            app(PublishGate::class)->publish($site);
        } catch (CannotPublish $refusal) {
            // The confirmation stands and somebody at NamibWay has a site to
            // fix. Telling the owner which of our own checks failed would be
            // noise to them and an invitation to worry.
            $problem = implode('; ', $refusal->blockers);

            report(new \RuntimeException(
                "Site [{$site->id}] was confirmed by the business but could not be published: {$problem}"
            ));
        }

        return response()->view('sites.confirm', $this->payload($site->refresh(), justConfirmed: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Site $site, bool $justConfirmed = false): array
    {
        return [
            'site' => $site,
            'justConfirmed' => $justConfirmed,
            'termsUrl' => LegalText::termsUrl(),
            'privacy' => LegalText::privacy($site),
            'imprint' => LegalText::imprint($site),
            'copyright' => LegalText::copyright($site),
        ];
    }
}
