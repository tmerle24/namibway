<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Gives an address to sites that were built before there was one to give.
 *
 * A site takes its host from SITES_HOST_SUFFIX at the moment it is created, so
 * every site generated before that variable was set has none — it works at
 * /_sites/{slug} and its subdomain answers with the travel platform, which is a
 * 502 rather than anything that says "misconfigured".
 *
 * The reason this is a command and not a one-line `update()` in tinker: the
 * host→site map is cached indefinitely and invalidated by a model observer, and
 * a mass update fires no model events. Setting the column by hand leaves the
 * resolver believing the old map, so the site stays unreachable and the next
 * hour goes on working out why. Saving each model is slower and correct.
 */
class SiteHosts extends Command
{
    protected $signature = 'sites:hosts
        {--backfill : Give a host to every site that has none}
        {--pending : List sites whose host is not covered by the wildcard certificate}';

    protected $description = 'Show or backfill the addresses customer websites answer on';

    public function handle(): int
    {
        return $this->option('backfill') ? $this->backfill() : $this->show();
    }

    private function backfill(): int
    {
        $suffix = trim((string) config('sites.host_suffix'), '.');

        if ($suffix === '') {
            $this->error('SITES_HOST_SUFFIX is not set, so there is no host to give anybody.');
            $this->line('  See DEPLOYMENT.md → "Customer websites".');

            return self::FAILURE;
        }

        $sites = Site::whereNull('host')->get();

        if ($sites->isEmpty()) {
            $this->info('Every site already has a host.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $host = Site::defaultHostFor($site->slug);

            if ($host === null || Site::where('host', $host)->exists()) {
                $this->warn("  {$site->slug} — skipped, [{$host}] is taken");

                continue;
            }

            // save(), not update() on a query: the observer that clears the
            // host map only fires on a model save.
            $site->host = $host;
            $site->save();

            $this->line("  {$site->slug} → {$host}");
        }

        return self::SUCCESS;
    }

    private function show(): int
    {
        $sites = Site::orderBy('name')->get();

        if ($sites->isEmpty()) {
            $this->line('No sites yet.');

            return self::SUCCESS;
        }

        $suffix = trim((string) config('sites.host_suffix'), '.');

        $rows = $sites
            ->when($this->option('pending'), fn ($all) => $all->filter(
                fn (Site $site): bool => filled($site->host)
                    && ($suffix === '' || ! str_ends_with((string) $site->host, '.'.$suffix)),
            ))
            ->map(fn (Site $site) => [
                $site->slug,
                $site->host ?? '—',
                $site->status->value,
            ]);

        if ($rows->isEmpty()) {
            $this->info($this->option('pending')
                ? 'No site is on a host outside the wildcard — nothing needs its own certificate.'
                : 'Nothing to show.');

            return self::SUCCESS;
        }

        // --pending is what a certificate reconciler outside the application
        // would read: a custom domain is not covered by the wildcard and needs
        // its own certificate. Issuing it is not PHP's job — see
        // PROJECT_STATUS.md §4.
        $this->table(['Slug', 'Host', 'Status'], $rows->all());

        return self::SUCCESS;
    }
}
