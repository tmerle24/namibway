<?php

namespace App\Sites;

use App\Models\Site;

/**
 * The first version of a site's Privacy, Legal and copyright text.
 *
 * ## Why this exists at all, given the rule next to it
 *
 * The footer partial says the system writes no legal wording, because generated
 * wording that reads like advice is advice with nobody behind it. That rule
 * still holds and this does not break it: what is written here is a *factual
 * description of how this website works* — what the enquiry form collects,
 * where it goes, that there is no tracking and no advertising cookie. Those are
 * things we know because we built it, not opinions about somebody's obligations
 * under Namibian law.
 *
 * It is a starting point, not a finished document. The business owns all three
 * texts, edits them from the Website tab, and confirms them at the moment the
 * site is published — which is also when they accept our terms. Everything the
 * page says about the business itself comes off the site record, so a blank
 * address produces a shorter page rather than an invented one.
 */
class LegalText
{
    /**
     * The pages a site has beyond its own, by slug.
     *
     * @return array<string, string>
     */
    public static function pages(): array
    {
        return [
            'privacy' => 'Privacy',
            'legal' => 'Legal notice',
        ];
    }

    public static function isLegalPage(string $slug): bool
    {
        return array_key_exists($slug, self::pages());
    }

    public static function copyright(Site $site): string
    {
        return filled($site->legal_copyright)
            ? (string) $site->legal_copyright
            : $site->name;
    }

    public static function privacy(Site $site): string
    {
        if (filled($site->legal_privacy)) {
            return (string) $site->legal_privacy;
        }

        return self::defaultPrivacy($site);
    }

    public static function imprint(Site $site): string
    {
        if (filled($site->legal_imprint)) {
            return (string) $site->legal_imprint;
        }

        return self::defaultImprint($site);
    }

    /**
     * What the site actually does with a visitor's details, in plain words.
     */
    public static function defaultPrivacy(Site $site): string
    {
        $name = $site->name;
        $contact = filled($site->contact_email)
            ? $site->contact_email
            : 'the address given in the legal notice';

        $paragraphs = [
            "This website is operated by {$name}. This page explains what happens to your details when you use it.",

            'What we collect. Nothing is collected while you read these pages: there is no advertising, no tracking, '
                .'and no analytics cookie. If you send an enquiry, the form collects the name, email address, telephone '
                .'number, dates, party size and message you type into it — and nothing else.',

            "What happens to it. An enquiry is sent to {$name} so that it can be answered, and is kept for as long as "
                .'is needed to deal with your request and to keep a record of the booking it may become. It is not sold, '
                .'and it is not passed to anybody who is not involved in answering you.',

            'Who else is involved. This website is hosted and maintained for '.$name.' by NamibWay '
                .'(namibway.com), which processes enquiries on its behalf and under its instructions. Your web server '
                .'requests are logged in the ordinary way that any website is served.',

            'Your choices. You can ask what is held about you, ask for it to be corrected, or ask for it to be deleted, '
                ."by writing to {$contact}.",
        ];

        return implode("\n\n", $paragraphs);
    }

    /**
     * Who is behind the site. Every line comes off the record — a business that
     * has not given an address gets a shorter page, not an invented one.
     */
    public static function defaultImprint(Site $site): string
    {
        $blocks = ['Responsible for the content of this website:', $site->name];

        if (filled($site->address)) {
            $blocks[] = (string) $site->address;
        }

        $contact = array_filter([
            filled($site->contact_phone) ? 'Telephone: '.$site->contact_phone : null,
            filled($site->contact_email) ? 'Email: '.$site->contact_email : null,
        ]);

        if ($contact !== []) {
            $blocks[] = implode("\n", $contact);
        }

        $blocks[] = 'Content and photographs. The text and photographs on this site belong to '.$site->name
            .' unless stated otherwise, and may not be reproduced without permission.';

        $blocks[] = 'External links. Where this site links to another website, that site is the responsibility of '
            .'whoever runs it. We check a link when we add it and have no influence over what it shows afterwards.';

        $blocks[] = 'Hosting. This website is built and hosted by NamibWay (namibway.com), which is responsible for '
            .'the technical operation of the site and not for the content above.';

        return implode("\n\n", $blocks);
    }

    /**
     * Our own terms for the website product, if a published address exists yet.
     *
     * Left unset rather than pointed at a page that has not been written: a
     * Terms link that 404s in front of a prospective customer is worse than no
     * link at all.
     */
    public static function termsUrl(): ?string
    {
        $url = trim((string) config('sites.terms_url', ''));

        return $url === '' ? null : $url;
    }
}
