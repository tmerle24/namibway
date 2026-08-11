<?php

namespace App\Support;

use App\Models\BookableUnit;
use App\Models\Listing;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Resolves the country-specific settings a booking needs — currency,
 * timezone, tax, cancellation window — for a given property.
 *
 * The chain is listings.city_id -> cities.region_id -> regions.country_code,
 * falling back to config('region.default_country') when a listing has no city
 * (the column is nullable and plenty of scraped listings have none). The
 * attributes themselves live in config/region.php.
 *
 * The point of this class is that nothing in the booking domain reaches for a
 * Namibian constant. `config('currencies.default')` is a *display* default
 * for travellers and must not be used as a property's billing currency; a
 * room type's own `currency` column wins wherever it exists, because that is
 * what the lodge actually charges in.
 */
class CountrySettings
{
    /**
     * @param  array<string, mixed>  $settings
     */
    private function __construct(
        public readonly string $countryCode,
        private readonly array $settings,
    ) {}

    public static function for(Listing $listing): self
    {
        return self::forCountry(self::countryCodeFor($listing));
    }

    public static function forCountry(?string $countryCode): self
    {
        $default = (string) config('region.default_country', 'NA');
        $code = strtoupper($countryCode ?: $default);

        /** @var array<string, array<string, mixed>> $countries */
        $countries = config('region.countries', []);

        // An unknown code falls back rather than throwing: a country can be
        // typed into the database before anyone adds it to the config, and a
        // booking screen going blank is a worse outcome than sane defaults.
        $settings = $countries[$code] ?? $countries[$default] ?? [];

        return new self($code, $settings);
    }

    /**
     * The country a property sits in, by region. Returns null when nothing in
     * the chain answers, so callers can tell "unknown" from "the default".
     */
    public static function countryCodeFor(Listing $listing): ?string
    {
        $listing->loadMissing('city.region');

        return $listing->city?->region?->country_code;
    }

    public function currency(): string
    {
        return (string) ($this->settings['currency'] ?? config('currencies.default', 'NAD'));
    }

    public function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) ($this->settings['timezone'] ?? config('app.timezone', 'UTC')));
    }

    /**
     * The property's own current date. A stay's arrival, a no-show and an
     * occupancy figure are all statements about the local calendar day, and
     * the server's day is not it.
     */
    public function today(): Carbon
    {
        return Carbon::now($this->timezone())->startOfDay();
    }

    /**
     * @return array{name: string, rate: float, tourism_levy_rate: float}
     */
    public function tax(): array
    {
        /** @var array<string, mixed> $tax */
        $tax = $this->settings['tax'] ?? [];

        return [
            'name' => (string) ($tax['name'] ?? 'Tax'),
            'rate' => (float) ($tax['rate'] ?? 0.0),
            'tourism_levy_rate' => (float) ($tax['tourism_levy_rate'] ?? 0.0),
        ];
    }

    /**
     * Days before arrival inside which a cancellation counts as late.
     */
    public function cancellationPenaltyDays(): int
    {
        /** @var array<string, mixed> $cancellation */
        $cancellation = $this->settings['cancellation'] ?? [];

        return (int) ($cancellation['penalty_days'] ?? 0);
    }

    /**
     * Whether cancelling a stay arriving on $checkIn is inside the penalty
     * window, judged against the property's own date rather than the
     * server's.
     */
    public function isLateCancellation(CarbonInterface $checkIn): bool
    {
        $arrival = Carbon::parse($checkIn)->startOfDay();

        return $this->today()->diffInDays($arrival, false) < $this->cancellationPenaltyDays();
    }

    /**
     * The currency a room type is sold in. The room type's own column is the
     * truth where it is set — a lodge quoting in USD is a real case, and the
     * country default must not silently overwrite it.
     */
    public static function currencyForBookableUnit(BookableUnit $bookableUnit): string
    {
        if (filled($bookableUnit->currency)) {
            return $bookableUnit->currency;
        }

        $listing = $bookableUnit->listing;

        return $listing !== null
            ? self::for($listing)->currency()
            : self::forCountry(null)->currency();
    }
}
