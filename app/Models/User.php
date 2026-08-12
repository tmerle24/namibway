<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property string|null $preferred_currency
 * @property array<string, mixed>|null $panel_preferences
 * @property int|null $partner_id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'provider', 'provider_id', 'preferred_currency', 'partner_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'panel_preferences' => 'array',
        ];
    }

    /**
     * How this person likes to look at a screen — the view they left a page on,
     * so they do not have to set it again tomorrow.
     *
     * Deliberately not fillable and not a form field: these are written by the
     * screens themselves as somebody uses them, never posted.
     */
    public function preference(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->panel_preferences ?? [], $key, $default);
    }

    /**
     * Remember a choice. Null forgets it, so a screen can be put back to
     * working out its own default rather than being pinned to a stale one.
     *
     * Writes only on an actual change: the toolbar buttons that call this are
     * mostly pressed on the option already showing.
     */
    public function rememberPreference(string $key, mixed $value): void
    {
        $preferences = $this->panel_preferences ?? [];

        if (Arr::get($preferences, $key) === $value) {
            return;
        }

        if ($value === null) {
            Arr::forget($preferences, $key);
        } else {
            Arr::set($preferences, $key, $value);
        }

        $this->panel_preferences = $preferences;
        $this->save();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_admin,
            'partner' => $this->partner_id !== null,
            default => false,
        };
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
