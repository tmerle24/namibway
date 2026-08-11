<?php

namespace App\Sites;

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
     * @return array<string, int>
     */
    private function hostMap(): array
    {
        /** @var array<string, int> $map */
        $map = Cache::rememberForever(self::CACHE_KEY, fn () => Site::query()
            ->whereNotNull('host')
            ->pluck('id', 'host')
            ->all());

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
