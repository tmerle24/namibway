<?php

namespace App\Sites\Rendering;

use App\Models\Site;
use App\Models\SiteBlock;
use Illuminate\Support\Collection;

/**
 * The business card behind a QR code: one screen with the few things somebody
 * does after scanning it — call, message, mail, save the contact, open the
 * site, ask for a quote.
 *
 * Everything on it is already on the site (contact channels, logo, the enquiry
 * band), so there is nothing to fill in and nothing that can go stale on a
 * printed card: the QR points at a page, and the page reads the site.
 */
final class BusinessCard
{
    /** @param list<array{key: string, icon: string, label: string, href: string, external: bool}> $actions */
    private function __construct(
        public readonly Site $site,
        public readonly string $name,
        public readonly ?string $organisation,
        public readonly ?string $address,
        public readonly array $actions,
    ) {}

    public static function for(Site $site): self
    {
        // The person, where the listing names one — a card is handed over by
        // somebody. The business is then the line underneath.
        $person = trim((string) $site->sourceListing?->contact_person);
        $business = $site->brandName();

        return new self(
            site: $site,
            name: $person !== '' ? $person : $business,
            organisation: $person !== '' ? $business : null,
            address: filled($site->address) ? trim(preg_replace('/\s*\n\s*/', ', ', (string) $site->address) ?? '') : null,
            actions: self::actions($site),
        );
    }

    public function url(): string
    {
        return $this->site->pageUrl('card');
    }

    public function filename(): string
    {
        return $this->site->slug.'.vcf';
    }

    /**
     * vCard 3.0 — the version every phone still reads. Kept to the fields a
     * contact app actually shows; the photo is left out because a card saved
     * from a page should not pull an image from our bucket months later.
     */
    public function vcard(): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'N:'.self::escape($this->name).';;;;';
        $lines[] = 'FN:'.self::escape($this->name);
        $lines[] = 'ORG:'.self::escape($this->organisation ?? $this->name);

        $seen = [];

        foreach (['contact_phone' => 'TEL;TYPE=WORK,VOICE:', 'whatsapp' => 'TEL;TYPE=CELL:'] as $field => $prefix) {
            $number = self::number((string) $this->site->{$field});

            // Most businesses give one mobile for both; twice in a contact card
            // is a duplicate somebody has to clean up on their phone.
            if ($number !== null && ! in_array($number, $seen, true)) {
                $lines[] = $prefix.$number;
                $seen[] = $number;
            }
        }

        if (filled($this->site->contact_email)) {
            $lines[] = 'EMAIL;TYPE=INTERNET,WORK:'.self::escape((string) $this->site->contact_email);
        }

        if ($this->address !== null) {
            $lines[] = 'ADR;TYPE=WORK:;;'.self::escape($this->address).';;;;';
        }

        $lines[] = 'URL:'.self::escape($this->site->pageUrl());
        $lines[] = 'END:VCARD';

        // CRLF: the spec says so, and Outlook is the one that notices.
        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return list<array{key: string, icon: string, label: string, href: string, external: bool}>
     */
    private static function actions(Site $site): array
    {
        $actions = [];
        $phone = self::number((string) $site->contact_phone);

        if ($phone !== null) {
            $actions[] = ['key' => 'call', 'icon' => 'phone', 'label' => 'Call', 'href' => 'tel:'.$phone, 'external' => false];
        }

        if ($whatsapp = SafeLink::whatsapp($site->whatsapp)) {
            $actions[] = ['key' => 'whatsapp', 'icon' => 'whatsapp', 'label' => 'WhatsApp', 'href' => $whatsapp, 'external' => true];
        }

        if (filled($site->contact_email)) {
            $actions[] = ['key' => 'mail', 'icon' => 'mail', 'label' => 'Email', 'href' => 'mailto:'.$site->contact_email, 'external' => false];
        }

        $actions[] = ['key' => 'save', 'icon' => 'save', 'label' => 'Save contact', 'href' => $site->pageUrl('card/vcf'), 'external' => false];
        $actions[] = ['key' => 'globe', 'icon' => 'globe', 'label' => 'Website', 'href' => $site->pageUrl(), 'external' => false];

        if ($enquiry = self::enquiryUrl($site)) {
            $actions[] = ['key' => 'enquiry', 'icon' => 'quote', 'label' => self::enquiryLabel($site), 'href' => $enquiry, 'external' => false];
        }

        return $actions;
    }

    /** The enquiry band on the home page, addressed from another page. */
    private static function enquiryUrl(Site $site): ?string
    {
        $blocks = self::homeBlocks($site);
        $anchor = SiteActions::for($site, $blocks)->enquiryHref();

        return $anchor === null ? null : $site->pageUrl().$anchor;
    }

    private static function enquiryLabel(Site $site): string
    {
        return SiteActions::enquiryLabel($site, self::homeBlocks($site)) ?? 'Send an enquiry';
    }

    /** @return Collection<int, SiteBlock> */
    private static function homeBlocks(Site $site): Collection
    {
        $page = $site->pages()->where('is_home', true)->first();

        return $page === null ? collect() : $page->renderableBlocks()->get();
    }

    /** Digits and one leading plus — what a `tel:` link and a phone's dialler want. */
    private static function number(string $value): ?string
    {
        $clean = preg_replace('/(?!^\+)[^0-9]/', '', trim($value)) ?? '';

        return strlen(preg_replace('/\D/', '', $clean) ?? '') >= 6 ? $clean : null;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], trim($value));
    }
}
