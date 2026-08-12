<?php

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * What the reconciler calls back once a domain really answers.
 *
 * Marking it live is what puts the domain into the host map, so the site starts
 * being served on it — which is why this is a separate step rather than
 * something the application assumes after seeing DNS. The certificate has to
 * exist first, and only the thing that issued it knows that it does.
 *
 * `--failed` is the other outcome, with the reason, so a person reading the
 * admin sees what a machine saw at 3am rather than a domain stuck on "waiting"
 * for a week.
 */
class MarkSiteDomainLive extends Command
{
    protected $signature = 'sites:domain-live {domain} {--failed= : Record a failure with this message instead}';

    protected $description = 'Record that a customer domain is live, or why it is not';

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->argument('domain')));

        $site = Site::where('custom_domain', $domain)->first();

        if ($site === null) {
            $this->error('No site has the domain '.$domain.'.');

            return self::FAILURE;
        }

        $failure = $this->option('failed');

        $site->forceFill([
            'domain_status' => $failure ? DomainStatus::Failed : DomainStatus::Live,
            'domain_checked_at' => now(),
            'domain_message' => $failure ?: null,
        ])->save();

        $this->info($domain.' is now '.$site->domain_status?->label().'.');

        return self::SUCCESS;
    }
}
