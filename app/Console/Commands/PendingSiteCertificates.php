<?php

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The domains whose DNS has arrived and whose certificate has not.
 *
 * One domain per line, nothing else — this is read by a shell script running as
 * root, and anything decorative here becomes a parsing bug there.
 *
 * The application deliberately stops at printing this list. Issuing the
 * certificate and writing the server block happen outside it; see DEPLOYMENT.md
 * for why, and the 2026-08-11 entry in CLAUDE.md for what happens when an nginx
 * file goes in wrong.
 */
class PendingSiteCertificates extends Command
{
    protected $signature = 'sites:pending-certificates';

    protected $description = 'List customer domains that are ready for a certificate';

    public function handle(): int
    {
        Site::query()
            ->whereNotNull('custom_domain')
            ->where('domain_status', DomainStatus::DnsOk)
            ->orderBy('custom_domain')
            ->pluck('custom_domain')
            ->each(fn (string $domain) => $this->output->writeln($domain));

        return self::SUCCESS;
    }
}
