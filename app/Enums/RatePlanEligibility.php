<?php

namespace App\Enums;

/**
 * Who may book a rate plan.
 *
 * Residency belongs here rather than being a discount on a guest, and that
 * distinction is the one most easily got wrong. A child rate is a share of
 * what an adult pays *within* a rate plan; a resident rate is a different
 * price for the whole room, published separately. Namibia Wildlife Resorts
 * prints Namibian-resident, SADC and international prices side by side — that
 * is three rate plans, and a system that models it as a per-guest discount
 * cannot represent them.
 *
 * Nothing enforces eligibility yet. It is recorded so that a rate sheet is
 * honest about who a price is for, and so the check has one place to live
 * when a booking engine needs it.
 */
enum RatePlanEligibility: string
{
    /** Anybody. The ordinary case. */
    case Public = 'public';

    /** Citizens or residents of the country the property is in. */
    case Resident = 'resident';

    /** Southern African Development Community. */
    case Sadc = 'sadc';

    /** Tour operators and travel agents, usually a nett rate. */
    case Agent = 'agent';

    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Anyone',
            self::Resident => 'Residents',
            self::Sadc => 'SADC',
            self::Agent => 'Agents and operators',
            self::Corporate => 'Corporate',
        };
    }

    /** Whether a rate under this plan may be shown to a traveller browsing the site. */
    public function isPubliclyBookable(): bool
    {
        return $this === self::Public;
    }
}
