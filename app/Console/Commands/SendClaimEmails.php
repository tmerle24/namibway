<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\Enrichment\ClaimInviteService;
use Illuminate\Console\Command;

class SendClaimEmails extends Command
{
    protected $signature = 'namibway:send-claim-emails
                            {--limit=50 : Max emails to send in this batch}
                            {--dry-run : Show recipients without sending}
                            {--force : Skip the confirmation prompt (for non-interactive/scripted runs)}';

    protected $description = 'Send "claim your listing" emails to unclaimed partners that have an email address';

    public function handle(ClaimInviteService $inviter): int
    {
        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $partners = Partner::whereNotNull('email')
            ->whereNotNull('claim_token')
            ->whereNull('claim_token_sent_at')
            ->whereNull('claimed_at')
            ->whereNull('claim_rejected_at')
            ->with('listings')
            ->limit($limit)
            ->get();

        if ($partners->isEmpty()) {
            $this->info('No partners to contact.');

            return self::SUCCESS;
        }

        $this->info("Preparing to contact {$partners->count()} partners (limit: {$limit})");

        if ($isDryRun) {
            $this->warn('DRY RUN — no emails will be sent');
            $this->table(['Name', 'Email', 'Claim URL'], $partners->map(fn ($p) => [
                $p->name,
                $p->email,
                $inviter->claimUrl($p),
            ])->toArray());

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Send {$partners->count()} emails?")) {
            return self::SUCCESS;
        }

        $sent = 0;
        $bar = $this->output->createProgressBar($partners->count());
        $bar->start();

        foreach ($partners as $partner) {
            try {
                if ($inviter->invite($partner)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Failed to send to {$partner->email}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Sent: {$sent} / {$partners->count()}");

        return self::SUCCESS;
    }
}
