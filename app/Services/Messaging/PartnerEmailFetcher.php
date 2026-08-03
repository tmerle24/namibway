<?php

namespace App\Services\Messaging;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * Polls the shared POP3 mailbox (team@namibway.com) for partner/listing
 * replies and stores them as inbound PartnerMessage rows.
 *
 * Talks raw POP3S via Pop3Client (plain PHP streams, no extension) rather
 * than ext-imap: PHP 8.4 dropped imap from core, and it isn't guaranteed to
 * be installed on the server. Message parsing (MIME, encodings, charsets)
 * is handled by zbateson/mail-mime-parser, a pure-PHP library — hand-rolling
 * that part reliably across real-world mail clients isn't worth it.
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

        $client = $this->connect();

        if ($client === null) {
            return $stats;
        }

        try {
            $count = $client->countMessages();

            for ($messageNumber = 1; $messageNumber <= $count; $messageNumber++) {
                $stats['fetched']++;

                try {
                    $raw = $client->retrieve($messageNumber);

                    if ($raw === null) {
                        continue;
                    }

                    $this->processMessage($raw, $dryRun, $stats);
                } catch (Throwable $e) {
                    Log::warning('namibway:fetch-partner-emails failed to process message', [
                        'message_number' => $messageNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->quit();
        }

        return $stats;
    }

    /**
     * Pure matching logic, split out from the mailbox plumbing so it can be
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

    private function connect(): ?Pop3Client
    {
        $host = config('services.pop3.host');
        $username = config('services.pop3.username');
        $password = config('services.pop3.password');

        if (blank($host) || blank($username) || blank($password)) {
            Log::warning('namibway:fetch-partner-emails: POP3_HOST/POP3_USERNAME/POP3_PASSWORD not configured, skipping.');

            return null;
        }

        $client = new Pop3Client;

        if (! $client->connect((string) $host, (int) config('services.pop3.port', 995), (bool) config('services.pop3.validate_cert', true))) {
            Log::error('namibway:fetch-partner-emails: could not connect to POP3 mailbox', ['error' => $client->getLastError()]);

            return null;
        }

        if (! $client->login((string) $username, (string) $password)) {
            Log::error('namibway:fetch-partner-emails: POP3 login failed', ['error' => $client->getLastError()]);
            $client->quit();

            return null;
        }

        return $client;
    }

    /**
     * Parses and ingests one already-fetched raw RFC822 message. Public (not
     * just an implementation detail of fetch()) so the parsing/matching
     * pipeline is testable with a crafted raw message string, without a live
     * mailbox connection.
     *
     * @param  array{fetched: int, matched: int, unmatched: int, skipped_duplicate: int, rows: array<int, array{from: string, partner: string|null, listing: string|null, subject: string, status: string}>}  &$stats
     */
    public function processMessage(string $raw, bool $dryRun, array &$stats): void
    {
        $message = Message::from($raw, false);

        $fromAddress = $this->extractFromAddress($message);
        $subject = $message->getSubject() ?? '(no subject)';
        $sourceUid = $this->extractSourceUid($message, $fromAddress, $subject);

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

        $dateHeader = $message->getHeaderValue(HeaderConsts::DATE);
        $sentAt = filled($dateHeader) ? Carbon::parse($dateHeader) : now();

        $body = $message->getTextContent() ?? strip_tags($message->getHtmlContent() ?? '');

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listing?->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'source_uid' => $sourceUid,
            'subject' => $subject,
            'body' => trim($body),
            'sent_at' => $sentAt,
        ]);
    }

    private function extractFromAddress(IMessage $message): string
    {
        $header = $message->getHeader(HeaderConsts::FROM);

        if (! $header instanceof AddressHeader) {
            return '';
        }

        return strtolower(trim($header->getEmail() ?? ''));
    }

    private function extractSourceUid(IMessage $message, string $fromAddress, string $subject): string
    {
        $messageId = $message->getMessageId();

        if (filled($messageId)) {
            return trim($messageId, "<> \t\n\r\0\x0B");
        }

        return hash('sha256', $fromAddress.'|'.$subject.'|'.($message->getHeaderValue(HeaderConsts::DATE) ?? ''));
    }
}
