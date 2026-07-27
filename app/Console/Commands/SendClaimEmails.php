<?php

namespace App\Console\Commands;

use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendClaimEmails extends Command
{
    protected $signature = 'namibway:send-claim-emails
                            {--limit=50 : Max emails to send in this batch}
                            {--dry-run : Show recipients without sending}';

    protected $description = 'Send "claim your listing" emails to unclaimed partners that have an email address';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $partners = Partner::whereNotNull('email')
            ->whereNull('claim_token_sent_at')
            ->whereNull('claimed_at')
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
                $this->claimUrl($p),
            ])->toArray());
            return self::SUCCESS;
        }

        if (!$this->confirm("Send {$partners->count()} emails?")) {
            return self::SUCCESS;
        }

        $sent = 0;
        $bar = $this->output->createProgressBar($partners->count());
        $bar->start();

        foreach ($partners as $partner) {
            try {
                Mail::send(
                    'emails.claim-listing',
                    [
                        'partner'  => $partner,
                        'claimUrl' => $this->claimUrl($partner),
                    ],
                    function ($message) use ($partner) {
                        $message
                            ->to($partner->email, $partner->name)
                            ->from(config('mail.from.address'), 'NamibWay')
                            ->subject('Your property on NamibWay — claim your free listing');
                    }
                );

                $partner->update(['claim_token_sent_at' => now()]);
                $sent++;
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

    private function claimUrl(Partner $partner): string
    {
        return url("/claim/{$partner->claim_token}");
    }
}
