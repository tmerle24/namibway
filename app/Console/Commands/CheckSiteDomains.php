<?php

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Models\Site;
use App\Sites\Domains\DnsChecker;
use Illuminate\Console\Command;

/**
 * Watches for a customer's DNS to arrive, and records what it saw.
 *
 * This is the application's entire half of the custom-domain flow: look up an
 * A record, write down the answer. It issues nothing and touches no
 * configuration. The reconciler that does — running as root, on a systemd timer
 * — reads `sites:pending-certificates` and reports back through
 * `sites:domain-live`. See DEPLOYMENT.md, "Custom domains".
 *
 * Runs every five minutes. A customer changes an A record once and then waits,
 * so the useful behaviour is noticing within minutes rather than the next day.
 */
class CheckSiteDomains extends Command
{
    protected $signature = 'sites:check-domains {--domain= : Check one domain rather than everything waiting}';

    protected $description = 'Check whether customer domains point at this server yet';

    public function handle(DnsChecker $dns): int
    {
        if (DnsChecker::serverIp() === null) {
            $this->error('SITES_SERVER_IP is not set, so there is nothing to compare a DNS record against.');

            return self::FAILURE;
        }

        $sites = Site::query()
            ->whereNotNull('custom_domain')
            // Live domains are left alone: re-checking one would mean a
            // customer's momentary DNS blip could take their website off the
            // air, and nothing here can put it back.
            ->whereIn('domain_status', [DomainStatus::PendingDns->value, DomainStatus::Failed->value])
            ->when($this->option('domain'), fn ($query, $domain) => $query->where('custom_domain', $domain))
            ->get();

        if ($sites->isEmpty()) {
            $this->info('Nothing waiting on DNS.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $domain = (string) $site->custom_domain;
            $found = $dns->pointsAtUs($domain);

            $site->forceFill([
                'domain_status' => $found ? DomainStatus::DnsOk : DomainStatus::PendingDns,
                'domain_checked_at' => now(),
                'domain_message' => $found
                    ? null
                    : 'Not pointing here yet. Both '.$domain.' and www.'.$domain
                        .' need an A record for '.DnsChecker::serverIp().'.',
            ])->save();

            $this->line(($found ? '<info>ready</info>  ' : 'waiting ').$domain);
        }

        return self::SUCCESS;
    }
}
