<?php

namespace App\Services\Messaging;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use IMAP\Connection;

/**
 * Polls the shared POP3 mailbox (team@namibway.com) for partner/listing
 * replies and stores them as inbound PartnerMessage rows. Uses PHP's
 * built-in ext-imap (which also speaks POP3) rather than a Composer
 * package — no mail-parsing library was already in use in this codebase.
 *
 * POP3 has no reliable server-side "seen" flag across separate polls, and
 * deleting a partner's email off the server automatically is not something
 * to do without an explicit decision to do so — so messages are never
 * deleted; de-duplication instead happens via PartnerMessage.source_uid.
 */
class PartnerEmailFetcher
{
    /**
     * @return array{fetched: int, matched: int, unmatched: int, skipped_duplicate: int, rows: array<int, array{from: string, partner: string|null, listing: string|null, subject: string, status: string}>}
     */
    public function fetch(bool $dryRun = false): array
    {
        $stats = [
            'fetched' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'skipped_duplicate' => 0,
            'rows' => [],
        ];

        $connection = $this->connect();

        if ($connection === null) {
            return $stats;
        }

        try {
            $count = imap_num_msg($connection);

            for ($messageNumber = 1; $messageNumber <= $count; $messageNumber++) {
                $stats['fetched']++;

                try {
                    $this->processMessage($connection, $messageNumber, $dryRun, $stats);
                } catch (\Throwable $e) {
                    Log::warning('namibway:fetch-partner-emails failed to process message', [
                        'message_number' => $messageNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            imap_close($connection);
        }

        return $stats;
    }

    /**
     * Pure matching logic, split out from the IMAP/POP3 plumbing so it can be
     * unit-tested without a real mailbox connection.
     *
     * Resolution order:
     * 1. Listing.contact_email matches → both listing and its partner.
     * 2. Else Partner.email matches → partner; listing only if that partner
     *    has exactly one listing (otherwise ambiguous, left null).
     * 3. Else → no match.
     *
     * @return array{partner: Partner|null, listing: Listing|null}
     */
    public function resolveRecipients(string $fromAddress): array
    {
        $fromAddress = trim(strtolower($fromAddress));

        if ($fromAddress === '') {
            return ['partner' => null, 'listing' => null];
        }

        $listing = Listing::whereRaw('LOWER(contact_email) = ?', [$fromAddress])->first();

        if ($listing !== null) {
            return ['partner' => $listing->partner, 'listing' => $listing];
        }

        $partner = Partner::whereRaw('LOWER(email) = ?', [$fromAddress])->first();

        if ($partner === null) {
            return ['partner' => null, 'listing' => null];
        }

        $listings = $partner->listings()->limit(2)->get();

        return [
            'partner' => $partner,
            'listing' => $listings->count() === 1 ? $listings->first() : null,
        ];
    }

    private function connect(): ?Connection
    {
        $host = config('services.pop3.host');
        $username = config('services.pop3.username');
        $password = config('services.pop3.password');

        if (blank($host) || blank($username) || blank($password)) {
            Log::warning('namibway:fetch-partner-emails: POP3_HOST/POP3_USERNAME/POP3_PASSWORD not configured, skipping.');

            return null;
        }

        $port = config('services.pop3.port', 995);
        $encryption = config('services.pop3.encryption', 'ssl');
        $validateCert = config('services.pop3.validate_cert', true);

        $flags = '/pop3/'.$encryption.($validateCert ? '' : '/novalidate-cert');
        $mailbox = "{{$host}:{$port}{$flags}}INBOX";

        $connection = @imap_open($mailbox, (string) $username, (string) $password);

        if ($connection === false) {
            Log::error('namibway:fetch-partner-emails: could not connect to POP3 mailbox', [
                'error' => imap_last_error() ?: 'unknown error',
            ]);

            return null;
        }

        return $connection;
    }

    /**
     * @param  array{fetched: int, matched: int, unmatched: int, skipped_duplicate: int, rows: array<int, array{from: string, partner: string|null, listing: string|null, subject: string, status: string}>}  &$stats
     */
    private function processMessage(Connection $connection, int $messageNumber, bool $dryRun, array &$stats): void
    {
        $header = imap_headerinfo($connection, $messageNumber);

        if ($header === false) {
            return;
        }

        $fromAddress = $this->extractFromAddress($header);
        $subject = mb_decode_mimeheader($header->subject ?? '(no subject)');
        $sourceUid = $this->extractSourceUid($header, $fromAddress);

        if (PartnerMessage::where('source_uid', $sourceUid)->exists()) {
            $stats['skipped_duplicate']++;

            return;
        }

        ['partner' => $partner, 'listing' => $listing] = $this->resolveRecipients($fromAddress);

        if ($partner === null) {
            $stats['unmatched']++;
            $stats['rows'][] = ['from' => $fromAddress, 'partner' => null, 'listing' => null, 'subject' => $subject, 'status' => 'unmatched'];

            Log::warning('namibway:fetch-partner-emails: no partner match for sender', ['from' => $fromAddress]);

            return;
        }

        $stats['matched']++;
        $stats['rows'][] = ['from' => $fromAddress, 'partner' => $partner->name, 'listing' => $listing?->name, 'subject' => $subject, 'status' => 'matched'];

        if ($dryRun) {
            return;
        }

        $sentAt = isset($header->udate) ? Carbon::createFromTimestamp($header->udate) : now();

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listing?->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'source_uid' => $sourceUid,
            'subject' => $subject,
            'body' => $this->extractBody($connection, $messageNumber),
            'sent_at' => $sentAt,
        ]);
    }

    private function extractFromAddress(object $header): string
    {
        if (empty($header->from[0])) {
            return '';
        }

        $from = $header->from[0];

        return ($from->mailbox ?? '').'@'.($from->host ?? '');
    }

    private function extractSourceUid(object $header, string $fromAddress): string
    {
        $messageId = $header->message_id ?? null;

        if (filled($messageId)) {
            return trim((string) $messageId, "<> \t\n\r\0\x0B");
        }

        return hash('sha256', $fromAddress.'|'.($header->subject ?? '').'|'.($header->date ?? ''));
    }

    private function extractBody(Connection $connection, int $messageNumber): string
    {
        $structure = imap_fetchstructure($connection, $messageNumber);

        if ($structure === false) {
            return '';
        }

        $plain = $this->findTextPart($structure, 'PLAIN');

        if ($plain !== null) {
            $fetched = imap_fetchbody($connection, $messageNumber, $plain['section']);

            return trim($this->decodeEncodedPart($fetched === false ? '' : $fetched, $plain['encoding']));
        }

        $html = $this->findTextPart($structure, 'HTML');

        if ($html !== null) {
            $fetched = imap_fetchbody($connection, $messageNumber, $html['section']);
            $decoded = $this->decodeEncodedPart($fetched === false ? '' : $fetched, $html['encoding']);

            return trim(html_entity_decode(strip_tags($decoded)));
        }

        // Not multipart and no explicit TEXT part found — the whole body is the message.
        $body = imap_body($connection, $messageNumber);

        return trim($this->decodeEncodedPart($body === false ? '' : $body, $structure->encoding ?? 0));
    }

    /**
     * Walks a MIME structure (as returned by imap_fetchstructure) looking for a
     * TEXT/$subtype part, returning its section path (e.g. "1.1") for
     * imap_fetchbody. type 0 = TEXT, type 1 = MULTIPART per the imap extension.
     *
     * @return array{section: string, encoding: int}|null
     */
    private function findTextPart(object $structure, string $subtype, string $sectionPrefix = ''): ?array
    {
        if (($structure->type ?? null) === 0 && strtoupper($structure->subtype ?? '') === $subtype) {
            return ['section' => $sectionPrefix !== '' ? $sectionPrefix : '1', 'encoding' => $structure->encoding ?? 0];
        }

        if (($structure->type ?? null) === 1 && ! empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $section = $sectionPrefix !== '' ? $sectionPrefix.'.'.($index + 1) : (string) ($index + 1);
                $found = $this->findTextPart($part, $subtype, $section);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function decodeEncodedPart(string $data, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($data), // ENCBASE64
            4 => quoted_printable_decode($data), // ENCQUOTEDPRINTABLE
            default => $data,
        };
    }
}
