<?php

namespace App\Observers;

use App\Models\Site;
use App\Sites\SiteResolver;

/**
 * Keeps the host map honest.
 *
 * The map is cached forever precisely so a request for the travel platform
 * never pays for a query, which means every write that could change it has to
 * say so. Forgetting the whole key rather than patching it is deliberate: the
 * map is rebuilt by one indexed query, and a patch that got the delete case
 * wrong would leave a released host answering.
 */
class SiteObserver
{
    public function saved(Site $site): void
    {
        SiteResolver::forget();
    }

    public function deleted(Site $site): void
    {
        SiteResolver::forget();
    }
}
