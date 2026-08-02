<?php

namespace App\Providers;

use App\Models\ApiClient;
use App\Models\Inquiry;
use App\Models\Review;
use App\Models\User;
use App\Observers\InquiryObserver;
use App\Observers\ReviewObserver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Inquiry::observe(InquiryObserver::class);
        Review::observe(ReviewObserver::class);

        Event::listen(function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->save();
            }
        });

        // Backup notification mails must be English regardless of config('app.locale')
        // (production's is 'de', a leftover from the RentalHandover scaffold — see
        // CLAUDE.md). Console commands don't run the SetLocale/ForceAdminLocale HTTP
        // middleware, so without this the spatie/laravel-backup mails render in German.
        Event::listen(function (CommandStarting $event) {
            if (in_array($event->command, ['backup:run', 'backup:clean', 'backup:monitor'], true)) {
                App::setLocale('en');
            }
        });

        RateLimiter::for('api', function (Request $request) {
            /** @var ApiClient|null $user */
            $user = $request->user();

            $tokenId = $user instanceof ApiClient ? $user->currentAccessToken()->id : null;

            return Limit::perMinute(60)->by($tokenId ?: $request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
