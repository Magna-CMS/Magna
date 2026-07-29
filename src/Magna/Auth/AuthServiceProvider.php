<?php

declare(strict_types=1);

namespace Magna\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Magna\Auth\Console\PermissionsListCommand;
use Magna\Auth\Http\Middleware\AdminCspMiddleware;
use Magna\Auth\Http\Middleware\ApiKeyMiddleware;
use Magna\Auth\Http\Middleware\DenyManagementCrossOriginMiddleware;
use Magna\Auth\Http\Middleware\EnsureTwoFactorAuthenticated;
use Magna\Auth\Http\Middleware\EnsureTwoFactorEnrolled;
use Magna\Auth\Http\Middleware\ForceHttpsMiddleware;
use Magna\Auth\Http\Middleware\MagnaApiMiddleware;
use Magna\Auth\Http\Middleware\SecurityHeadersMiddleware;
use Magna\Install\Installer;
use Magna\Settings\SecuritySettings;
use Magna\Users\User;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(TwoFactorService::class);
        $this->app->singleton(LoginThrottle::class);
        $this->app->singleton(ApiKeyService::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(MagnaToken::class);

        $this->applySecurityConfig();
        $this->registerCorePermissions();
        $this->registerGateResolution();
        $this->registerRoutes();
        $this->registerViews();
        $this->registerMiddlewareAliases();

        if ($this->app->runningInConsole()) {
            $this->commands([PermissionsListCommand::class]);
        }
    }

    /**
     * Override session.lifetime from the SecuritySettings so the value stored
     * in the database takes effect before StartSession middleware runs.
     * Wrapped in try/catch so a missing DB during the installer does not
     * crash the boot sequence.
     */
    private function applySecurityConfig(): void
    {
        // S1-10: force the session cookie's Secure flag in production
        // regardless of what SESSION_SECURE_COOKIE is set to in .env — a
        // deployment that copies .env.example verbatim and forgets to set
        // it explicitly would otherwise ship a session cookie that can
        // legally be sent over a plaintext HTTP connection. Not gated on
        // runningInConsole() since it doesn't touch the DB.
        //
        // Relaxed only for a genuine pre-install HTTP request: a fresh unzip
        // defaults APP_ENV to production, but the installer may run over plain
        // HTTP before TLS exists — forcing Secure there makes the session
        // cookie never return and every installer POST 419s. On HTTPS (or once
        // installed) production always enforces Secure, so an install performed
        // over TLS keeps a Secure cookie throughout.
        if ($this->app->environment('production') && (Installer::isInstalled() || request()->isSecure())) {
            config(['session.secure' => true]);
        }

        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $lifetime = SecuritySettings::get()->session_lifetime;
            config(['session.lifetime' => $lifetime]);
        } catch (\Throwable) {
            // DB not yet available (installer first run) — leave Laravel default.
        }
    }

    private function registerCorePermissions(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        $registry->registerMany([
            // The admin panel's front door. Holding a role is not the same as
            // having business in the CMS back end: a plugin that seeds roles
            // for its own users — a client portal, a storefront — would
            // otherwise hand every one of them the panel. Every major CMS gates
            // this explicitly (Craft's accessCp, Statamic's `access cp`,
            // Drupal's `access administration pages`) for exactly that reason.
            'panel.access' => 'Sign in to the Magna admin panel',
            'users.view' => 'View users',
            'users.manage' => 'Create, update, suspend, and delete users',
            'roles.view' => 'View roles and their granted permissions',
            'roles.manage' => 'Create, update, and delete roles; grant and revoke permissions',
            'settings.view' => 'View system settings',
            'settings.manage' => 'Change system settings',
            // Separate from settings.manage because these actions download and
            // enable third-party code on this server — the highest-impact thing
            // the panel can do, and not something every settings administrator
            // should inherit by default.
            'licensing.manage' => 'Redeem, activate, update, and release product licences',
            'plugins.view' => 'View installed plugins',
            'plugins.manage' => 'Enable, disable, and uninstall plugins',
            'audit.view' => 'View the audit log',
            'tokens.manage' => 'Create, list, and revoke API tokens',
            'blocks.preview' => 'Render the block-editor live preview',
            'blocks.raw_html' => 'Use the html/text block types, which render content unescaped',
        ]);
    }

    /**
     * Route every dotted ability through the RBAC engine.
     *
     * Convention: abilities containing a dot are permission keys and are
     * resolved exclusively here — unregistered keys are denied and logged.
     * Dot-free abilities (model policies, closures) fall through untouched.
     * Super admins bypass all checks, including policies.
     */
    private function registerGateResolution(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->isSuperAdmin()) {
                return true;
            }

            if (! str_contains($ability, '.')) {
                return null;
            }

            $registry = $this->app->make(PermissionRegistry::class);

            if (! $registry->has($ability)) {
                Log::warning('Denied authorization check for unregistered permission key.', [
                    'key' => $ability,
                    'user_id' => $user->getKey(),
                ]);

                return false;
            }

            return $user->hasPermissionGrant($ability);
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware('web')
            ->prefix('auth')
            ->group(__DIR__.'/routes/web.php');

        Route::middleware(['api', DenyManagementCrossOriginMiddleware::class])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes/api.php');

        // RFC 9116 security.txt — no prefix, no auth
        Route::middleware('web')
            ->group(__DIR__.'/routes/well-known.php');
    }

    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'magna');
    }

    private function registerMiddlewareAliases(): void
    {
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('magna.api', MagnaApiMiddleware::class);
        $router->aliasMiddleware('magna.api.key', ApiKeyMiddleware::class);
        $router->aliasMiddleware('magna.security-headers', SecurityHeadersMiddleware::class);
        $router->aliasMiddleware('magna.admin-csp', AdminCspMiddleware::class);
        $router->aliasMiddleware('magna.two-factor', EnsureTwoFactorAuthenticated::class);
        $router->aliasMiddleware('magna.two-factor-enrolled', EnsureTwoFactorEnrolled::class);
        $router->aliasMiddleware('magna.force-https', ForceHttpsMiddleware::class);
    }
}
