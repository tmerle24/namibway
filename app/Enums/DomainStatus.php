<?php

namespace App\Enums;

/**
 * How far a customer's own domain has got.
 *
 * Four states, and the order matters because the whole point is that nobody has
 * to be told what to do next: each one names who is waiting for whom.
 */
enum DomainStatus: string
{
    /** We are waiting for the customer's DNS to point at us. */
    case PendingDns = 'pending_dns';

    /** DNS arrived. We are waiting for the certificate, which is the machine's job. */
    case DnsOk = 'dns_ok';

    /** Certificate issued, vhost written, the domain answers. */
    case Live = 'live';

    /** Something needs a person. `domain_message` says what. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PendingDns => 'Waiting for DNS',
            self::DnsOk => 'DNS found, issuing certificate',
            self::Live => 'Live',
            self::Failed => 'Needs attention',
        };
    }

    /**
     * Whether a site should actually be served on this domain.
     *
     * Only when it is live: before the certificate exists the domain cannot be
     * reached over https at all, and answering on it would mean handing out a
     * link that shows a browser warning.
     */
    public function isServable(): bool
    {
        return $this === self::Live;
    }
}
