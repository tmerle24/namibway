<?php

namespace Tests\Feature\Sites;

use App\Enums\DomainStatus;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Domains\DnsChecker;
use App\Sites\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer pointing their own domain at us.
 *
 * The application's whole part in this is looking up an A record and writing
 * down what it saw; the certificate and the nginx server block are issued by a
 * reconciler running as root on a timer, because a web process that can run
 * certbot is a web process with root. What is asserted here is the half that
 * lives in the application, and in particular the three states it must never
 * confuse: waiting, ready for a certificate, and actually being served.
 */
class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $attributes = []): Site
    {
        $site = Site::factory()->published()->onHost('lodge.websites.test')->create($attributes);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'about',
            'data' => ['heading' => 'About us', 'body' => 'Words.'],
            'sort' => 0,
        ]);

        SiteResolver::forget();

        return $site;
    }

    private function fakeDns(callable $lookup): void
    {
        $this->app->instance(DnsChecker::class, new DnsChecker($lookup));
    }

    public function test_a_domain_that_does_not_point_here_stays_waiting(): void
    {
        config(['sites.server_ip' => '198.51.100.10']);

        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::PendingDns,
        ]);

        $this->fakeDns(fn (string $domain): array => []);

        $this->artisan('sites:check-domains')->assertSuccessful();

        $this->assertSame(DomainStatus::PendingDns, $site->refresh()->domain_status);
        $this->assertStringContainsString('198.51.100.10', (string) $site->domain_message);
    }

    /**
     * A certificate covering the apex but not `www` fails in front of a guest
     * rather than here, and every customer types `www`.
     */
    public function test_the_apex_alone_is_not_enough(): void
    {
        config(['sites.server_ip' => '198.51.100.10']);

        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::PendingDns,
        ]);

        $this->fakeDns(fn (string $domain): array => $domain === 'duneedge.test'
            ? ['198.51.100.10']
            : ['203.0.113.9']);

        $this->artisan('sites:check-domains')->assertSuccessful();

        $this->assertSame(DomainStatus::PendingDns, $site->refresh()->domain_status);
    }

    public function test_both_records_make_it_ready_for_a_certificate(): void
    {
        config(['sites.server_ip' => '198.51.100.10']);

        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::PendingDns,
        ]);

        $this->fakeDns(fn (string $domain): array => ['198.51.100.10']);

        $this->artisan('sites:check-domains')->assertSuccessful();

        $this->assertSame(DomainStatus::DnsOk, $site->refresh()->domain_status);

        // The list the root-side reconciler reads. One domain per line and
        // nothing else, because anything decorative here is a parsing bug there.
        $this->artisan('sites:pending-certificates')
            ->expectsOutput('duneedge.test')
            ->assertSuccessful();
    }

    /**
     * Until the certificate exists the domain cannot be reached over https, so
     * answering on it early would mean the first thing the customer sees when
     * they test their new domain is a browser warning.
     */
    public function test_a_domain_is_only_served_once_it_is_live(): void
    {
        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::DnsOk,
        ]);

        $resolver = app(SiteResolver::class);

        $this->assertNull($resolver->forHost('duneedge.test'));
        $this->assertNotNull($resolver->forHost('lodge.websites.test'));

        $this->artisan('sites:domain-live', ['domain' => 'duneedge.test'])->assertSuccessful();
        SiteResolver::forget();

        $this->assertNotNull($resolver->forHost('duneedge.test'));
        // And the form every customer actually types.
        $this->assertNotNull($resolver->forHost('www.duneedge.test'));
        // The subdomain never stops working: old links point at it, and it is
        // what still answers if they let their own registration lapse.
        $this->assertNotNull($resolver->forHost('lodge.websites.test'));

        $this->assertSame('https://duneedge.test', $site->refresh()->publicUrl());
    }

    /**
     * A momentary DNS failure must not be able to take a live website off the
     * air — nothing in the application could put it back.
     */
    public function test_a_live_domain_is_never_rechecked(): void
    {
        config(['sites.server_ip' => '198.51.100.10']);

        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::Live,
        ]);

        $this->fakeDns(fn (string $domain): array => []);

        $this->artisan('sites:check-domains')->assertSuccessful();

        $this->assertSame(DomainStatus::Live, $site->refresh()->domain_status);
    }

    public function test_the_reconciler_can_report_a_failure_with_its_reason(): void
    {
        $site = $this->site([
            'custom_domain' => 'duneedge.test',
            'domain_status' => DomainStatus::DnsOk,
        ]);

        $this->artisan('sites:domain-live', [
            'domain' => 'duneedge.test',
            '--failed' => 'certbot: rate limited',
        ])->assertSuccessful();

        $site->refresh();

        $this->assertSame(DomainStatus::Failed, $site->domain_status);
        $this->assertSame('certbot: rate limited', $site->domain_message);
        $this->assertNull(app(SiteResolver::class)->forHost('duneedge.test'));
    }

    /**
     * Comparing an A record against nothing either passes everything or fails
     * everything, and both are worse than saying it is not configured.
     */
    public function test_the_whole_flow_is_off_without_a_server_ip(): void
    {
        config(['sites.server_ip' => '']);

        $this->site(['custom_domain' => 'duneedge.test', 'domain_status' => DomainStatus::PendingDns]);

        $this->artisan('sites:check-domains')->assertFailed();
    }
}
