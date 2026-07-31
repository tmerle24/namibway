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
            count(*) filter (where pending_image is not null and pending_image != '') as pending_photos,
            count(*) filter (where description->>'en' is null or description->>'en' = '') as missing_description,
            count(*) filter (where claim_status = 'claimed') as claimed,
            coalesce(avg(enrichment_score), 0) as average_completion
        SQL)->first();

        // Same plain-query-builder reasoning as $totals above — these are aggregates
        // over enrichment_jobs, not real model attributes.
        $costs = DB::table('enrichment_jobs')->selectRaw(<<<'SQL'
            coalesce(sum(cost_estimate) filter (where created_at >= current_date), 0) as ai_today,
            coalesce(sum(places_cost_estimate) filter (where created_at >= current_date), 0) as places_today,
            coalesce(sum(cost_estimate) filter (where created_at >= current_date - interval '7 days'), 0) as ai_7d,
            coalesce(sum(places_cost_estimate) filter (where created_at >= current_date - interval '7 days'), 0) as places_7d
        SQL)->first();

        return [
            Stat::make('Total Listings', number_format($totals->total)),
            Stat::make('Complete Listings', number_format($totals->complete))->color('success'),
            Stat::make('Missing Website', number_format($totals->missing_website))->color('danger'),
            Stat::make('Missing Email', number_format($totals->missing_email))->color('danger'),
            Stat::make('Missing Photos', number_format($totals->missing_photos))->color('danger'),
            Stat::make('Photos Pending Approval', number_format($totals->pending_photos))
                ->description('Found on the listing\'s own website, awaiting owner/admin sign-off')
                ->color('warning'),
            Stat::make('Missing Description', number_format($totals->missing_description))->color('danger'),
            Stat::make('Claimed Listings', number_format($totals->claimed))->color('success'),
            Stat::make('Average Completion', round((float) $totals->average_completion).'%'),
            Stat::make('Enrichment Cost (Today)', '$'.number_format($costs->ai_today + $costs->places_today, 2))
                ->description('Claude $'.number_format($costs->ai_today, 2).' · Places $'.number_format($costs->places_today, 2)),
            Stat::make('Enrichment Cost (7 Days)', '$'.number_format($costs->ai_7d + $costs->places_7d, 2))
                ->description('Claude $'.number_format($costs->ai_7d, 2).' · Places $'.number_format($costs->places_7d, 2)),
        ];
    }
}
