<?php

namespace App\Providers;

use App\Filament\Partner\Support\SelectedProperty;
use App\Models\ApiClient;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Review;
use App\Models\Site;
use App\Models\User;
use App\Observers\InquiryObserver;
use App\Observers\ListingObserver;
use App\Observers\ReviewObserver;
use App\Observers\SiteObserver;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\FileUpload;
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
        // Resolved once per request: the partner panel's topbar and the
        // lodge-facing pages all ask which property is selected while
        // rendering the same page.
        $this->app->scoped(SelectedProperty::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Inquiry::observe(InquiryObserver::class);
        Listing::observe(ListingObserver::class);
        Review::observe(ReviewObserver::class);
        Site::observe(SiteObserver::class);

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

        // Cap what lands in R2. Every FileUpload in both panels is an image
        // upload, and none of them had a size limit — a partner uploading a
        // straight-from-the-phone photo stored several MB as the one and only
        // copy we serve. Nothing renders wider than the top of the thumbnail
        // ladder (config/media.php), so 2000px is already generous headroom.
        // 'contain' preserves the aspect ratio; upscaling stays off so small
        // images are left exactly as they are.
        FileUpload::configureUsing(function (FileUpload $upload): void {
            $upload->imageResizeMode('contain')
                ->imageResizeTargetWidth('2000')
                ->imageResizeTargetHeight('2000')
                ->imageResizeUpscale(false);
        });

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
