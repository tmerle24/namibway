<?php

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The legal notice at /legal — see App\Support\LegalNotice.
 *
 * What is worth holding down here is not the prose. It is that the page never
 * invents a fact: an operator nobody has entered is reported as missing rather
 * than rendered half-empty, and the photo credits are read out of the photo
 * configuration itself, so they cannot describe a picture the site no longer
 * shows.
 */
class LegalNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unconfigured_operator_is_absent_rather_than_half_filled(): void
    {
        config(['legal.operator' => ['name' => null, 'email' => 'hello@example.test']]);

        $this->get('/legal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Legal')->where('operator', null));
    }

    public function test_the_operator_block_renders_what_was_entered_and_nothing_else(): void
    {
        config(['legal.operator' => [
            'name' => 'NamibWay GmbH',
            'address' => ['Beispielstraße 1', '10115 Berlin'],
            'country' => 'Germany',
            'represented_by' => ['Till Merlé'],
            'email' => 'hello@namibway.test',
            'vat_id' => null,
        ]]);

        $this->get('/legal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('operator.name', 'NamibWay GmbH')
                ->where('operator.address', ['Beispielstraße 1', '10115 Berlin'])
                ->where('operator.represented_by', ['Till Merlé'])
                ->where('operator.email', 'hello@namibway.test')
                // Not entered, so not asserted as an empty string somewhere on
                // the page — a blank register line reads as "we have none".
                ->where('operator.vat_id', null)
                ->where('operator.register', null));
    }

    public function test_an_address_may_be_written_as_one_block_of_text(): void
    {
        config(['legal.operator' => [
            'name' => 'NamibWay',
            'address' => "Beispielstraße 1\n10115 Berlin",
        ]]);

        $this->get('/legal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('operator.address', ['Beispielstraße 1', '10115 Berlin']));
    }

    public function test_the_credits_come_from_the_photographs_themselves(): void
    {
        config([
            'hero.photos' => [[
                'slug' => 'test-dune',
                'file' => 'images/hero/test-dune.jpg',
                'title' => 'A test dune',
                'photographer' => 'A Photographer',
                'license' => 'CC BY 4.0',
                'source' => 'https://example.test/file',
            ]],
            'legal.image_credits' => [[
                'title' => 'Something else entirely',
                'photographer' => 'Another Photographer',
                'license' => 'CC0 1.0',
                'source' => 'https://example.test/other',
            ]],
        ]);

        $this->get('/legal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('imageCredits.0.title', 'A test dune')
                ->where('imageCredits.0.photographer', 'A Photographer')
                ->where('imageCredits.0.license', 'CC BY 4.0')
                ->where('imageCredits.0.source', 'https://example.test/file')
                ->where('imageCredits.1.title', 'Something else entirely')
                ->count('imageCredits', 2));
    }

    public function test_every_hero_photograph_records_where_it_came_from(): void
    {
        // The rule the credits page depends on: a photograph whose provenance
        // nobody wrote down is a photograph we cannot show we may use. Held
        // against the real configuration, not a fixture.
        foreach ((array) config('hero.photos') as $photo) {
            foreach (['title', 'photographer', 'license', 'source'] as $field) {
                $this->assertNotEmpty(
                    $photo[$field] ?? null,
                    "Hero photo '{$photo['slug']}' has no {$field} — see config/hero.php.",
                );
            }
        }
    }

    public function test_a_licence_that_asks_for_attribution_is_credited_on_the_hero_itself(): void
    {
        // A credit three clicks away does not satisfy "credit the author", so
        // anything that is not a no-attribution licence has to carry its
        // credit on the image.
        $noAttribution = ['CC0 1.0', 'Public domain'];

        foreach ((array) config('hero.photos') as $photo) {
            if (in_array($photo['license'] ?? null, $noAttribution, true)) {
                continue;
            }

            $this->assertTrue(
                ($photo['credit_on_hero'] ?? false) === true,
                "Hero photo '{$photo['slug']}' is {$photo['license']} but is not credited on the hero.",
            );
        }
    }

    public function test_the_footer_link_to_the_legal_notice_resolves(): void
    {
        $this->get(route('legal'))->assertOk();
    }
}
