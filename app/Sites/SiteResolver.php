<?php

namespace App\Sites;

use App\Enums\DomainStatus;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Which site, if any, a host belongs to.
 *
 * Every request to the whole application passes through this — the travel
 * platform's included — so the negative answer has to be free. It is: one
 * cached array of host => id, which for the foreseeable number of customers is
 * a few hundred bytes, and a miss returns without touching the database.
 *
 * The map is cached forever and invalidated when a site is written
 * (App\Observers\SiteObserver) rather than given a TTL. A stale entry here does
 * not mean slightly old data; it means a customer's website is unreachable, or
 * worse, that a host somebody released still answers.
 */
class SiteResolver
{
    public const CACHE_KEY = 'sites.host_map';

    public function forHost(string $host): ?Site
    {
        $host = $this->normalise($host);

        if ($host === '' || in_array($host, $this->reservedHosts(), true)) {
            return null;
        }

        $id = $this->hostMap()[$host] ?? null;

        return $id === null ? null : Site::find($id);
    }

    /**
     * Hosts that must never resolve to a customer site whatever the database
     * says. A row claiming namibway.com would otherwise replace the travel
     * platform with somebody's restaurant — an unlikely typo with an
     * unmissable blast radius, so it is checked rather than trusted.
     *
     * @return array<int, string>
     */
    public function reservedHosts(): array
    {
        return array_map($this->normalise(...), (array) config('sites.reserved_hosts', []));
    }

    /**
     * Both addresses a site can answer on: the subdomain we issued, and the
     * customer's own domain once it is actually live.
     *
     * A domain that is only pending is deliberately absent. Until the
     * certificate exists it cannot be reached over https anyway, and answering
     * on it early would mean the first thing the customer sees when they test
     * their new domain is a browser warning.
     *
     * @return array<string, int>
     */
    private function hostMap(): array
    {
        /** @var array<string, int> $map */
        $map = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = Site::query()
                ->whereNotNull('host')
                ->pluck('id', 'host')
                ->all();

            $custom = Site::query()
                ->whereNotNull('custom_domain')
                ->where('domain_status', DomainStatus::Live)
                ->pluck('id', 'custom_domain')
                ->all();

            // Normalised on the way in, because `www.` is stripped on the way
            // out and a row stored with it would otherwise never be found.
            foreach ($custom as $domain => $id) {
                $map[$this->normalise((string) $domain)] = $id;
            }

            return $map;
        });

        return $map;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * `www.` is stripped rather than stored twice: a customer who buys
     * example.com.na expects both forms to work, and nobody is going to
     * remember to add the second row.
     */
    private function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
