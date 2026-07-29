<?php

declare(strict_types=1);

namespace Magna\Users;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Magna\Auth\Concerns\HasRoles;
use Magna\Auth\SuspendedAccessRevoker;

/**
 * @property string $id ULID primary key (HasUlids)
 * @property UserStatus $status
 * @property Carbon|null $email_verified_at
 * @property string|null $avatar_path
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
#[Fillable(['name', 'email', 'password', 'status', 'widget_order', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlids;
    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            // Encrypted at rest, not merely hidden from serialization: a
            // plaintext TOTP secret turns any database read — a leaked dump, a
            // stolen backup, SQL injection in a plugin — into the ability to
            // generate valid second factors for every admin, which is the one
            // thing 2FA exists to prevent.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'widget_order' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Stage 10 (A-1): without this, Filament defaults to allowing ANY
     * authenticated 'web'-guard user into the panel, including a
     * self-registered account with zero roles (reachable whenever
     * GeneralSettings::registration_enabled is on) or a suspended one. A
     * user needs at least one role to have any legitimate business in the
     * panel at all — everything they'd see is gated behind role-derived
     * permissions anyway, so a roleless account has nothing to do here.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActive() && $this->roles()->exists();
    }

    /**
     * Avatar shown in the Filament user menu and topbar. Returns null when no
     * photo is set so Filament falls back to the generated initials avatar.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected static function booted(): void
    {
        // Stage 10 (A-4): "suspended" was previously enforced only at
        // login (LoginController::attempt()) — an already-issued Sanctum
        // token, and an already-established Filament browser session, both
        // kept working indefinitely after an admin suspended the account.
        // Centralized here (rather than in EditUser/UserController
        // individually) so every current and future write path gets it
        // automatically. Only fires on an actual transition into
        // Suspended, not on every save while already suspended.
        static::updated(function (self $user): void {
            if ($user->wasChanged('status') && $user->status === UserStatus::Suspended) {
                app(SuspendedAccessRevoker::class)->revoke($user);
            }
        });
    }
}
