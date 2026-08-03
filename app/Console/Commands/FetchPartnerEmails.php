<?php

namespace App\Console\Commands;

use App\Services\Messaging\PartnerEmailFetcher;
use Illuminate\Console\Command;

class FetchPartnerEmails extends Command
{
    protected $signature = 'namibway:fetch-partner-emails
                            {--dry-run : Parse and match without saving anything}';

    protected $description = 'Poll the shared POP3 mailbox for partner/listing replies and log them as inbound messages';

    public function handle(PartnerEmailFetcher $fetcher): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $stats = $fetcher->fetch($isDryRun);

        if ($stats['fetched'] === 0) {
            $this->info('No messages found (or the mailbox is not configured — check the log).');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN — nothing was saved');
        }

        $this->table(
            ['From', 'Partner', 'Listing', 'Subject', 'Status'],
            array_map(fn (array $row) => [
                $row['from'],
                $row['partner'] ?? '—',
                $row['listing'] ?? '—',
                $row['subject'],
                $row['status'],
            ], $stats['rows'])
        );

        $this->info("Fetched: {$stats['fetched']} · Matched: {$stats['matched']} · Unmatched: {$stats['unmatched']} · Already imported: {$stats['skipped_duplicate']}");

        return self::SUCCESS;
    }
}
