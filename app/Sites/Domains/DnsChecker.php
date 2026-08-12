<?php

namespace App\Sites\Domains;

/**
 * Does this domain point at us yet?
 *
 * The whole of the application's involvement in a custom domain is this one
 * question, asked repeatedly. Everything that follows from a yes — issuing the
 * certificate, writing the server block, reloading nginx — happens outside the
 * application, because a queue worker allowed to run certbot and write into
 * /etc/nginx is a web process with root. The outage of 2026-08-11 is what one
 * bad nginx file costs on this box.
 *
 * Wrapped in a class rather than called inline so a test can answer without a
 * network, and so the one place that decides "points at us" is findable.
 */
class DnsChecker
{
    /** @var callable(string): array<int, string> */
    private $lookup;

    /**
     * @param  (callable(string): array<int, string>)|null  $lookup
     */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? self::systemLookup(...);
    }

    /**
     * The addresses this domain resolves to right now.
     *
     * @return array<int, string>
     */
    public function addressesFor(string $domain): array
    {
        return ($this->lookup)($domain);
    }

    /**
     * Whether the domain — and its www form, which every customer types —
     * resolves to this server.
     *
     * Both have to arrive. A domain whose apex points at us while www does not
     * would get a certificate that covers half of what the customer will hand
     * out, and they will find that out in front of a guest rather than here.
     */
    public function pointsAtUs(string $domain): bool
    {
        $ip = self::serverIp();

        if ($ip === null) {
            return false;
        }

        return in_array($ip, $this->addressesFor($domain), true)
            && in_array($ip, $this->addressesFor('www.'.$domain), true);
    }

    /** The address a customer is told to point their A record at. */
    public static function serverIp(): ?string
    {
        $ip = trim((string) config('sites.server_ip', ''));

        return $ip === '' ? null : $ip;
    }

    /**
     * @return array<int, string>
     */
    private static function systemLookup(string $domain): array
    {
        // @ rather than a try: a domain that does not exist yet is the normal
        // state of this whole feature, not an exception worth reporting.
        $records = @dns_get_record($domain, DNS_A);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => isset($record['ip']) ? (string) $record['ip'] : null,
            $records,
        )));
    }
}
