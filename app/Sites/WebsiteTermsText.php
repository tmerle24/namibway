<?php

namespace App\Sites;

/**
 * The first version of our website terms.
 *
 * **This is a scaffold, not legal advice and not a finished contract.** It was
 * written to be the thing a lawyer marks up rather than the thing a lawyer
 * starts from — every clause describes how the product actually works today,
 * which is the part an outside lawyer cannot know and the part that takes the
 * longest to explain. What it is not is checked against Namibian law.
 *
 * The admin says the same in front of whoever edits it. It is deliberately not
 * said on the public page: a customer reading terms that announce their own
 * uncertainty has been told something useless and alarming at once.
 *
 * Where it is opinionated, it follows decisions already made and recorded in
 * PROJECT_STATUS.md — the customer owns and answers for the content, we answer
 * for the hosting and the technology; the site's content is theirs to take with
 * them; bookings taken through a site are between the guest and the business.
 */
class WebsiteTermsText
{
    public const VERSION = '1.0-draft';

    /**
     * Written as HTML rather than markdown because it is edited in the panel's
     * rich editor and rendered straight onto the page, and because a lawyer's
     * marked-up copy comes back as a document, not as markdown.
     */
    public static function scaffold(): string
    {
        $company = config('app.name');

        return <<<HTML
        <h2>1. Who these terms are between</h2>
        <p>These terms govern the website service provided by {$company} ("we", "us") to the
        business that subscribes to it ("you"). They apply from the moment the website is
        published under your name, or from the first payment, whichever happens first.</p>

        <h2>2. What the service is</h2>
        <p>We build and host a website for your business. It includes a set number of pages
        built from our own block library, hosting, a subdomain of ours, and — where you ask
        for it — the registration and configuration of your own domain name. We keep the
        software running, apply security updates, and renew the certificates that keep the
        site reachable over https.</p>
        <p>The service does not include search engine optimisation work, advertising,
        photography, copywriting beyond the first draft we prepare, or the development of
        features that are not part of the block library.</p>

        <h2>3. Your content</h2>
        <p>Everything you supply — text, photographs, logos, prices, opening hours — remains
        yours. You give us permission to publish it on your website for as long as the
        subscription runs, and to keep the copies needed to operate and back up the site.</p>
        <p>You confirm that you own that material or have the right to publish it, and that
        it is accurate. This matters most for photographs: a picture taken from somewhere
        else on the internet is not yours to publish, and we cannot check that for you.</p>
        <p>If you leave, you may take your content with us providing it in a usable form. We
        do not hold it hostage.</p>

        <h2>4. Our part</h2>
        <p>The design, the templates, the block library and the software behind them remain
        ours. Nothing in these terms transfers them to you, and a website built with them
        cannot be exported as a working copy of our software.</p>

        <h2>5. What you are responsible for</h2>
        <p>You answer for the content of your website: that it is lawful, accurate, and not
        misleading about what your business offers. That includes prices, availability, and
        any claim about facilities, licences or ratings.</p>
        <p>We answer for the hosting and the technology: that the site is served, that it is
        reachable, that it is kept up to date, and that the data on it is backed up.</p>

        <h2>6. Fees and payment</h2>
        <p>The subscription is billed monthly in advance at the rate agreed when you sign up.
        Fees are quoted in Namibian dollars. Where we register a domain name on your behalf,
        its cost is passed on at what it costs us, and the renewal continues for as long as
        the subscription runs.</p>
        <p>An invoice that stays unpaid for thirty days entitles us to suspend the website
        after giving you notice. Suspension does not delete anything.</p>

        <h2>7. Term and ending it</h2>
        <p>The subscription runs month to month. Either of us may end it with thirty days'
        notice, in writing. There is no minimum term and no cancellation fee.</p>
        <p>When it ends, the website is taken offline. We keep your content for sixty days
        afterwards so that you can ask for a copy, and delete it after that.</p>
        <p>A domain name registered on your behalf is yours. If you leave, we transfer it to
        you or to whoever you nominate, provided any registration cost already incurred has
        been paid.</p>

        <h2>8. Availability</h2>
        <p>We aim for the website to be available at all times, and we do not promise it. We
        depend on hosting providers, domain registries and network operators we do not
        control. Planned maintenance is announced in advance where it will be noticeable.</p>

        <h2>9. Bookings and enquiries</h2>
        <p>Where your website carries an enquiry or booking form, the resulting agreement is
        between you and your guest. We pass the enquiry on and, where you use our booking
        system, we record what was agreed. We are not a party to that agreement and do not
        guarantee that a guest arrives, pays, or is who they say they are.</p>

        <h2>10. Data protection</h2>
        <p>Enquiries submitted through your website contain personal data of the person
        making them. We process it on your instruction, in order to pass it to you and to
        keep a record of what was sent. We do not sell it, and we do not use it for
        advertising. You are responsible for what you then do with it.</p>

        <h2>11. Liability</h2>
        <p>Neither of us excludes liability for death, personal injury, or fraud. Beyond
        that, our total liability in any twelve-month period is limited to the fees you paid
        us in that period, and neither of us is liable to the other for indirect losses,
        lost profits or lost business.</p>

        <h2>12. Changes to these terms</h2>
        <p>We may change these terms. Where a change matters to you we will give thirty days'
        notice by email, and you may end the subscription before it takes effect if you do
        not accept it. The version in force is the one published on our website, and the
        version you accepted is recorded with the date you accepted it.</p>

        <h2>13. Law</h2>
        <p>These terms are governed by the law of the Republic of Namibia, and the courts of
        Namibia have jurisdiction over any dispute arising from them.</p>

        <h2>14. Reaching us</h2>
        <p>Write to us at the address published on our website. We answer in English.</p>
        HTML;
    }
}
