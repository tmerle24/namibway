<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ListingEnrichmentStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        // Queried via the plain query builder (not Eloquent) — these aggregate
        // aliases aren't real Listing columns, so a stdClass result avoids
        // static analysis treating them as (missing) model attributes.
        $totals = DB::table('listings')->selectRaw(<<<'SQL'
            count(*) as total,
            count(*) filter (where enrichment_score >= 90) as complete,
            count(*) filter (where website is null or website = '') as missing_website,
            count(*) filter (where contact_email is null or contact_email = '') as missing_email,
            count(*) filter (where image is null or image = '') as missing_photos,
            count(*) filter (where description->>'en' is null or description->>'en' = '') as missing_description,
            count(*) filter (where claim_status = 'claimed') as claimed,
            coalesce(avg(enrichment_score), 0) as average_completion
        SQL)->first();

        return [
            Stat::make('Total Listings', number_format($totals->total)),
            Stat::make('Complete Listings', number_format($totals->complete))->color('success'),
            Stat::make('Missing Website', number_format($totals->missing_website))->color('danger'),
            Stat::make('Missing Email', number_format($totals->missing_email))->color('danger'),
            Stat::make('Missing Photos', number_format($totals->missing_photos))->color('danger'),
            Stat::make('Missing Description', number_format($totals->missing_description))->color('danger'),
            Stat::make('Claimed Listings', number_format($totals->claimed))->color('success'),
            Stat::make('Average Completion', round((float) $totals->average_completion).'%'),
        ];
    }
}
