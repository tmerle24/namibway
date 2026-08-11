<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Partner;
use App\Services\Demo\DemoTenantBuilder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Puts a prospect's own lodge into the booking system before the meeting
 * starts, so the conversation begins with their calendar rather than with an
 * empty grid and an explanation.
 *
 * Running it twice on the same lodge wipes that sandbox and rebuilds it —
 * prospects make a mess, and the next meeting starts clean.
 */
class CreateDemoTenant extends Command
{
    protected $signature = 'booking:demo-tenant
        {listing? : Slug or id of the real listing to build the demo from}
        {--all : Rebuild every demo tenant that already exists}
        {--destroy : Remove the demo tenant instead of rebuilding it}
        {--list : Show the demo tenants that exist and do nothing else}
        {--weeks= : Weeks of activity to seed (default from config/booking.php)}
        {--password= : Sign-in password to set, instead of generating one}';

    protected $description = 'Create, rebuild or remove a sandbox partner built from a real listing';

    public function handle(DemoTenantBuilder $builder): int
    {
        if ($this->option('list')) {
            return $this->showExisting($builder);
        }

        if ($this->option('all')) {
            return $this->handleAll($builder);
        }

        $reference = $this->argument('listing');

        if (blank($reference)) {
            $this->error('Name a listing to build the demo from, or pass --all to rebuild every existing demo tenant.');
            $this->line('  php artisan booking:demo-tenant okonjima-bush-camp');

            return self::INVALID;
        }

        $source = $this->resolveListing((string) $reference);

        if ($source === null) {
            $this->error("No listing matches [{$reference}].");

            return self::FAILURE;
        }

        if ($source->partner?->is_demo === true) {
            $this->error(
                "[{$source->name}] is itself a demo listing. Build the demo from the real listing it was copied from."
            );

            return self::INVALID;
        }

        return $this->option('destroy')
            ? $this->destroyFor($builder, $source)
            : $this->buildFor($builder, $source);
    }

    private function buildFor(DemoTenantBuilder $builder, Listing $source): int
    {
        $this->line("Building a demo tenant from [{$source->name}] …");

        try {
            $tenant = $builder->build(
                $source,
                $this->option('weeks') === null ? null : (int) $this->option('weeks'),
                $this->option('password') === null ? null : (string) $this->option('password'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Demo tenant ready.');
        $this->newLine();

        $this->table(['', ''], [
            ['Partner', $tenant->partner->name],
            ['Property', $tenant->listing->name],
            ['Copied from', $tenant->source->name.' (untouched)'],
            ['Room types', (string) $tenant->roomTypes],
            ['Stays seeded', (string) $tenant->stays],
            ['Blocks seeded', (string) $tenant->blocks],
        ]);

        $this->newLine();
        $this->comment('Hand this over in the meeting:');
        $this->line('  Sign-in link  '.$tenant->signInUrl);
        $this->line('  Email         '.$tenant->user->email);
        $this->line('  Password      '.$tenant->password);
        $this->newLine();
        $this->line('  The link expires in '.config('booking.demo.sign_in_link_days').' days.');
        $this->line('  Re-run this command to wipe whatever the prospect did and rebuild it clean.');

        return self::SUCCESS;
    }

    private function destroyFor(DemoTenantBuilder $builder, Listing $source): int
    {
        $partner = Partner::query()
            ->where('is_demo', true)
            ->where('demo_source_listing_id', $source->id)
            ->first();

        if ($partner === null) {
            $this->warn("No demo tenant exists for [{$source->name}].");

            return self::SUCCESS;
        }

        $builder->destroy($partner);
        $this->info("Removed the demo tenant for [{$source->name}].");

        return self::SUCCESS;
    }

    private function handleAll(DemoTenantBuilder $builder): int
    {
        $tenants = $builder->existing();

        if ($tenants->isEmpty()) {
            $this->warn('There are no demo tenants yet.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($tenants as $partner) {
            $source = $partner->demoSourceListing;

            if ($source === null) {
                $this->warn("[{$partner->name}] has no source listing left; skipping. Remove it with --destroy.");
                $failed++;

                continue;
            }

            if ($this->option('destroy')) {
                $builder->destroy($partner);
                $this->line("Removed [{$partner->name}].");

                continue;
            }

            try {
                $tenant = $builder->build($source, $this->option('weeks') === null ? null : (int) $this->option('weeks'));
                $this->line("Rebuilt [{$tenant->partner->name}] — {$tenant->stays} stays. Password: {$tenant->password}");
            } catch (Throwable $exception) {
                $this->error("[{$partner->name}]: ".$exception->getMessage());
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function showExisting(DemoTenantBuilder $builder): int
    {
        $tenants = $builder->existing();

        if ($tenants->isEmpty()) {
            $this->warn('There are no demo tenants yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Partner', 'Built from', 'Property'],
            $tenants->map(fn (Partner $partner) => [
                $partner->name,
                $partner->demoSourceListing?->name ?? '— source listing gone —',
                $partner->listings()->first()?->name ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** A slug is what someone reads off the admin panel; an id is what a script has. */
    private function resolveListing(string $reference): ?Listing
    {
        $query = Listing::query()->with('partner');

        return ctype_digit($reference)
            ? $query->find((int) $reference)
            : $query->where('slug', $reference)->first();
    }
}
