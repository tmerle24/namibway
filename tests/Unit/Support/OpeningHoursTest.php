<?php

namespace Tests\Unit\Support;

use App\Support\OpeningHours;
use Tests\TestCase;

/**
 * Opening hours are the one thing on a supply point a traveller acts on
 * directly — they drive to it — so the parser's job is as much to refuse what
 * it cannot read as to read what it can. Both halves are asserted here.
 */
class OpeningHoursTest extends TestCase
{
    public function test_a_blank_is_not_recorded_rather_than_always_closed(): void
    {
        $this->assertNull(OpeningHours::parse(null));
        $this->assertNull(OpeningHours::parse('   '));
    }

    public function test_it_reads_the_round_the_clock_shorthand(): void
    {
        $hours = OpeningHours::parse('24/7');

        $this->assertNotNull($hours);
        $this->assertTrue($hours->isAlwaysOpen());
        $this->assertSame('24/7', $hours->raw());
    }

    public function test_it_expands_a_day_range_into_the_days_it_covers(): void
    {
        $hours = OpeningHours::parse('Mo-Fr 07:00-18:00');

        $this->assertNotNull($hours);
        $this->assertFalse($hours->isAlwaysOpen());
        $this->assertSame([[
            'days' => ['mo', 'tu', 'we', 'th', 'fr'],
            'ranges' => [['07:00', '18:00']],
        ]], $hours->toArray()['rules']);
    }

    public function test_a_range_may_wrap_around_the_end_of_the_week(): void
    {
        $hours = OpeningHours::parse('Sa-Mo 08:00-13:00');

        $this->assertNotNull($hours);
        $this->assertSame(['sa', 'su', 'mo'], $hours->toArray()['rules'][0]['days']);
    }

    public function test_it_reads_several_rules_and_an_explicit_closure(): void
    {
        $hours = OpeningHours::parse('Mo-Fr 07:00-18:00; Sa 08:00-13:00; Su off');

        $this->assertNotNull($hours);

        $rules = $hours->toArray()['rules'];

        $this->assertCount(3, $rules);
        $this->assertSame(['sa'], $rules[1]['days']);
        // An empty range list is the whole point of "off": a day that is named
        // and closed says more than a day nobody mentioned.
        $this->assertSame(['su'], $rules[2]['days']);
        $this->assertSame([], $rules[2]['ranges']);
    }

    public function test_times_without_days_apply_to_the_whole_week(): void
    {
        $hours = OpeningHours::parse('06:00-22:00');

        $this->assertNotNull($hours);
        $this->assertSame(OpeningHours::DAYS, $hours->toArray()['rules'][0]['days']);
    }

    public function test_a_day_may_be_open_twice_over_a_lunch_break(): void
    {
        $hours = OpeningHours::parse('Mo 08:00-13:00,14:00-17:00');

        $this->assertNotNull($hours);
        $this->assertSame(
            [['08:00', '13:00'], ['14:00', '17:00']],
            $hours->toArray()['rules'][0]['ranges'],
        );
    }

    public function test_midnight_at_the_end_of_the_day_is_a_time_and_half_past_it_is_not(): void
    {
        $this->assertNotNull(OpeningHours::parse('Mo 06:00-24:00'));
        $this->assertNull(OpeningHours::parse('Mo 06:00-24:30'));
        $this->assertNull(OpeningHours::parse('Mo 06:00-25:00'));
    }

    /**
     * The deviation written down in the class docblock: this understands a
     * subset, and everything outside it is refused whole rather than
     * half-read. A traveller drives on what this says.
     */
    public function test_it_refuses_what_it_cannot_read_in_full(): void
    {
        $this->assertNull(OpeningHours::parse('Mo-Fr sunrise-sunset'));
        $this->assertNull(OpeningHours::parse('Jan-Mar 08:00-17:00'));
        $this->assertNull(OpeningHours::parse('PH off'));
        $this->assertNull(OpeningHours::parse('Mondays'));
        $this->assertNull(OpeningHours::parse('whenever the owner is in'));
        // One unreadable rule invalidates the string rather than being
        // quietly dropped — the reader would otherwise be shown a shorter
        // week than the sign on the door.
        $this->assertNull(OpeningHours::parse('Mo-Fr 07:00-18:00; Su sunrise-sunset'));
    }

    /**
     * The number the importer ranks a town's forecourts by — see
     * App\Console\Commands\ImportSupplyHours, which picks the most generous
     * real element rather than merging several into a string no sign says.
     */
    public function test_it_counts_how_many_minutes_a_week_this_is_open(): void
    {
        $this->assertSame(10080, OpeningHours::parse('24/7')?->weeklyMinutes());
        $this->assertSame(2700, OpeningHours::parse('Mo-Fr 08:00-17:00')?->weeklyMinutes());
        $this->assertSame(6720, OpeningHours::parse('Mo-Su 06:00-22:00')?->weeklyMinutes());
        $this->assertSame(480, OpeningHours::parse('Mo 08:00-13:00,14:00-17:00')?->weeklyMinutes());
        $this->assertSame(4620, OpeningHours::parse('Mo-Sa 07:00-19:00; Su 08:00-13:00')?->weeklyMinutes());
    }

    /**
     * A later rule replaces an earlier one for the days it names, which is
     * what the standard means: Wednesday here is closed, not open for nine
     * hours and closed at the same time.
     */
    public function test_a_later_rule_replaces_an_earlier_one_rather_than_adding_to_it(): void
    {
        $this->assertSame(2160, OpeningHours::parse('Mo-Fr 08:00-17:00; We off')?->weeklyMinutes());
        $this->assertSame(2340, OpeningHours::parse('Mo-Fr 08:00-17:00; We 09:00-12:00')?->weeklyMinutes());
    }

    public function test_validity_is_the_same_question_the_admin_field_asks(): void
    {
        $this->assertTrue(OpeningHours::isValid('Mo-Sa 07:00-19:00'));
        $this->assertFalse(OpeningHours::isValid('Mo-Sa 7-19'));
    }
}
