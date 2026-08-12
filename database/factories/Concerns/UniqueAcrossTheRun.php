<?php

namespace Database\Factories\Concerns;

/**
 * A name that cannot collide with a row somebody else already committed.
 *
 * Faker's `unique()` remembers what it has handed out, but that memory belongs
 * to one Faker generator, and the generator is rebuilt with the application on
 * every test. The database is not: the two DatabaseTruncation suites
 * (ConcurrentBookingTest, InvoiceNumberingConcurrencyTest) fork real processes
 * and therefore have to *commit*, and both deliberately leave `regions` and
 * `cities` out of their truncation lists, because those tables hold reference
 * data seeded by migrations that RefreshDatabase does not put back.
 *
 * So their factory-made regions and cities outlive them and are visible to
 * every later test — while each later test starts with an empty unique memory
 * and no idea those names exist. Faker's city pool is finite, so eventually a
 * draw repeats one and `regions_name_unique` refuses it. That is a test
 * failing hundreds of tests away from the code that caused it, at random, and
 * it happened in CI on 2026-08-12: "duplicate key value violates unique
 * constraint regions_name_unique, Key (name)=(Wilkinsonport) already exists".
 *
 * The fix is not to remember harder. It is to stop asking a per-test memory to
 * satisfy a database-wide constraint: the suffix below is unique by
 * construction, per process and per row, so it holds across forks and across
 * anything already committed. Names come out as "Wilkinsonport 1134-7", which
 * is ugly and correct — a test that needs a real name uses the seeded ones.
 */
trait UniqueAcrossTheRun
{
    /**
     * Per class, because a trait's static property is not shared between the
     * classes that use it. Forks inherit the parent's value and then diverge,
     * which is why the process id is in there too.
     */
    private static int $namesDrawn = 0;

    protected function neverSeenBefore(string $name): string
    {
        return $name.' '.getmypid().'-'.(++self::$namesDrawn);
    }
}
