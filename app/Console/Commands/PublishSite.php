<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Sites\Publishing\CannotPublish;
use App\Sites\Publishing\PublishGate;
use Illuminate\Console\Command;

/**
 * Take a draft live, or take a live site back to a draft.
 *
 * Publishing is the one transition with somebody else's name on it: until it
 * happens the page is research, reachable only by its token; afterwards it is a
 * business's public website. So it runs through PublishGate rather than setting
 * a column — the gate is what refuses to publish a page still leaning on a
 * photograph we may show while prospecting and may not hand over.
 */
class PublishSite extends Command
{
    protected $signature = 'sites:publish
        {site : Slug of the site}
        {--unpublish : Take it back to a draft instead}';

    protected $description = 'Publish a customer website, or take it back to a draft';

    public function handle(PublishGate $gate): int
    {
        $site = Site::where('slug', $this->argument('site'))->first();

        if ($site === null) {
            $this->error("No site with the slug [{$this->argument('site')}].");

            return self::FAILURE;
        }

        if ($this->option('unpublish')) {
            $site->forceFill(['status' => SiteStatus::Draft, 'published_at' => null])->save();

            $this->info("[{$site->name}] is a draft again — its address now needs the preview token.");

            return self::SUCCESS;
        }

        try {
            $gate->publish($site);
        } catch (CannotPublish $e) {
            $this->error('Not published:');

            foreach ($e->blockers as $blocker) {
                $this->line('  · '.$blocker);
            }

            return self::FAILURE;
        }

        $this->info("[{$site->name}] is live.");
        $this->line('  '.$site->publicUrl());

        if (blank($site->host)) {
            // Published without a host is not broken, just unreachable at a
            // pretty address — worth saying rather than leaving somebody to
            // wonder why the subdomain does nothing.
            $this->newLine();
            $this->warn('  This site has no host, so it is only reachable at the path above.');
            $this->line('  Set SITES_HOST_SUFFIX and run: php artisan sites:hosts --backfill');
        }

        return self::SUCCESS;
    }
}
