<?php

namespace App\Console\Commands;

use App\Sites\Import\SitePackage;
use App\Sites\Import\SitePackageImporter;
use Illuminate\Console\Command;

/**
 * The website package import from the shell - the same check-then-import as
 * /admin -> Content -> Import website, for a ZIP too large to upload there.
 * Without --apply it only checks. See SITE_PACKAGE.md.
 */
class ImportSitePackage extends Command
{
    protected $signature = 'sites:import-package
        {zip : Path to the website package}
        {--apply : Write it (without this, only the check runs)}';

    protected $description = 'Check or import a website package (ZIP)';

    public function handle(SitePackageImporter $importer): int
    {
        $path = (string) $this->argument('zip');

        if (! is_file($path)) {
            $this->error("No file at [{$path}].");

            return self::FAILURE;
        }

        $package = SitePackage::open($path);
        $plan = $this->option('apply') ? $importer->apply($package) : $importer->plan($package);

        foreach ($plan->toArray()['entities'] as $entity) {
            $this->line(sprintf('%s: %s (%s)', $entity['kind'], $entity['label'] ?? '-', $entity['action']));

            foreach ($entity['changes'] as $change) {
                $this->line('  '.$change['field'].': '.mb_strimwidth($change['new'], 0, 90, '...'));
            }
        }

        $this->line("Pictures: {$plan->imagesNew} new, {$plan->imagesUpdated} updated. Videos: {$plan->videos}.");
        $this->line("Listings: {$plan->listingsNew} new, {$plan->listingsUpdated} updated.");
        $this->line('Home page: '.implode(', ', array_map(fn (array $b): string => $b['type'].' ('.$b['action'].')', $plan->blocks)));

        foreach ($plan->pages as $page) {
            $this->line("Page /{$page['slug']} ({$page['action']}): ".implode(', ', array_column($page['blocks'], 'type')));
        }

        foreach ($plan->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($plan->errors as $error) {
            $this->error($error);
        }

        if ($plan->hasErrors()) {
            return self::FAILURE;
        }

        $this->info($plan->written ? "Imported - site id {$plan->siteId}." : 'Check passed - run again with --apply to import.');

        return self::SUCCESS;
    }
}
