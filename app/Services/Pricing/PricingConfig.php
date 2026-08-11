<?php

namespace App\Services\Pricing;

/**
 * A pricing strategy's own parameters, read safely.
 *
 * The rate plan stores these as JSON so that a new strategy is an extension
 * rather than a schema change — see the migration. This is the other half of
 * that bargain: nothing reads the raw array. A strategy asks for the number it
 * needs, by name, and gets a float or null, whatever a form or an import
 * happened to put in the column.
 *
 * Deliberately not validated against a schema per strategy. A parameter a
 * strategy does not recognise is ignored, which is what lets a partner-specific
 * value ride along without every reader knowing about it.
 */
final class PricingConfig
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private readonly array $values) {}

    public static function from(mixed $values): self
    {
        if (! is_array($values)) {
            return new self([]);
        }

        /** @var array<string, mixed> $values */
        return new self($values);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * A number, or null when it was never set. An empty string counts as never
     * set: that is what a form field somebody cleared arrives as, and treating
     * it as nought would silently make a supplement free.
     */
    public function float(string $key): ?float
    {
        $value = $this->values[$key] ?? null;

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public function has(string $key): bool
    {
        return $this->float($key) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
