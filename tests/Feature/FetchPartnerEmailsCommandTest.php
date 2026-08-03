<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchPartnerEmailsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exits_cleanly_when_pop3_is_not_configured(): void
    {
        config(['services.pop3.host' => null]);

        $this->artisan('namibway:fetch-partner-emails')
            ->assertSuccessful();
    }

    public function test_dry_run_option_is_accepted(): void
    {
        config(['services.pop3.host' => null]);

        $this->artisan('namibway:fetch-partner-emails', ['--dry-run' => true])
            ->assertSuccessful();
    }
}
