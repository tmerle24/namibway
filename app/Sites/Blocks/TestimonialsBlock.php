<?php

namespace App\Sites\Blocks;

/**
 * What guests said, in their words and under their names.
 *
 * Only quotes the business actually received — a Facebook review, a guest
 * book, an email — and never written for them: an invented testimonial on a
 * page we host is a false statement under our certificate. The editor says so
 * where the quote is typed. No star ratings either; five gold stars beside
 * every quote read as decoration rather than evidence. Added 2026-09-10.
 *
 * `sample`: a placeholder quote to show a prospect what the band will look
 * like before the business has sent real ones. It carries a visible "Sample"
 * tag and PublishGate refuses to publish while one is on the page.
 */
class TestimonialsBlock extends BlockDefinition
{
    public const MAX_ITEMS = 9;

    public function type(): string
    {
        return 'testimonials';
    }

    public function label(): string
    {
        return 'Guest reviews';
    }

    public function navDefault(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.quote' => ['required', 'string', 'max:600'],
            'items.*.name' => ['required', 'string', 'max:80'],
            // Where they came from, or what they did: "Germany", "Etosha day
            // trip, May 2026". Whatever makes the quote a person's.
            'items.*.origin' => ['nullable', 'string', 'max:80'],
            'items.*.sample' => ['nullable', 'boolean'],
        ], $this->navRules());
    }

    /** @param array<string, mixed> $data */
    public static function hasSamples(array $data): bool
    {
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item) && ! empty($item['sample'])) {
                return true;
            }
        }

        return false;
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'items');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'items' => []];
    }
}
