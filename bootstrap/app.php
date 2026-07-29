<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Magna\Admin\Notifications\NotificationRecipients;
use Magna\Auth\Http\Middleware\ApiKeyMiddleware;
use Magna\Auth\Http\Middleware\DenyManagementCrossOriginMiddleware;
use Magna\Auth\Http\Middleware\ForceHttpsMiddleware;
use Magna\Auth\Http\Middleware\MagnaApiMiddleware;
use Magna\Auth\Http\Middleware\SecurityHeadersMiddleware;
use Magna\Content\Http\Middleware\RefreshDatabaseContentTypes;
use Magna\Install\Http\Middleware\RedirectIfNotInstalled;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepend globally so the "not installed" gate runs before anything
        // else — in particular before the Filament panel's Authenticate
        // middleware, which (mounted at "/") would otherwise redirect guests
        // to /login before the installer redirect can fire. It only checks a
        // lock file, so it needs no session.
        $middleware->prepend(RedirectIfNotInstalled::class);

        // Behind a reverse proxy / load balancer / CDN the real client IP
        // arrives in X-Forwarded-*; without this, request()->ip() is the proxy
        // and login throttling + audit IPs are wrong. Off by default (trust
        // nothing — X-Forwarded-* is spoofable) and enabled per-deployment via
        // TRUSTED_PROXIES ('*' for a trusted single-hop proxy, or a CIDR list).
        // This closure can run before the config service is bound (e.g. during
        // `config:cache`), so guard the lookup; trusted proxies only matter for
        // real request handling, by which point config is available and cached.
        $trustedProxies = app()->bound('config') ? config('app.trusted_proxies') : null;
        if ($trustedProxies === '*') {
            $middleware->trustProxies(at: '*');
        } elseif (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(at: array_map('trim', explode(',', $trustedProxies)));
        }

        // S1-11: ForceHttpsMiddleware was registered as a middleware alias
        // but never actually attached to any route or group — toggling
        // "Force HTTPS" in Security Settings had zero runtime effect. Wired
        // in here, ahead of everything else in the web group, so a plain
        // HTTP request is redirected before CORS/security-header processing
        // even runs. DB-backed (reads SecuritySettings), safe to run this
        // late in the global stack since RedirectIfNotInstalled (prepended
        // above) has already handled the pre-install, DB-unavailable case.
        $middleware->web(prepend: [ForceHttpsMiddleware::class, HandleCors::class]);
        $middleware->web(append: [SecurityHeadersMiddleware::class]);

        // Binds each session to the password hash it was created under, so a
        // password change (reset, admin-forced) invalidates every OTHER live
        // session for that user on its next request — driver-agnostic, unlike
        // Magna\Auth\SessionInvalidator, which can only purge the database
        // driver's rows directly. Without this, an attacker holding a stolen
        // session cookie survives the victim's password reset.
        $middleware->web(append: [AuthenticateSession::class]);

        // Where an unauthenticated visitor is sent. Magna has no route named
        // "login" — the admin panel's is filament.magna.auth.login, and a
        // plugin may mount its own portal with its own — so Laravel's
        // default `route('login')` raises RouteNotFoundException instead of
        // redirecting. That turns a guard rejection into a 500, most visibly
        // when AuthenticateSession invalidates a session it no longer trusts
        // and tries to send the visitor somewhere safe.
        //
        // Resolved per request, and by route name rather than a hardcoded
        // path, so a panel mounted elsewhere keeps working.
        $middleware->redirectGuestsTo(static function (Request $request): ?string {
            if ($request->expectsJson()) {
                return null; // 401 JSON, not a redirect.
            }

            // A developer-portal visitor belongs at the portal's own sign-in,
            // not in the CMS admin.
            if ($request->is('developer', 'developer/*') && Route::has('developer.login')) {
                return route('developer.login');
            }

            return Route::has('filament.magna.auth.login')
                ? route('filament.magna.auth.login')
                : url('/');
        });

        $middleware->api(prepend: [HandleCors::class]);
        $middleware->api(append: [SecurityHeadersMiddleware::class]);

        // Authenticate before Laravel resolves route model bindings.
        //
        // SubstituteBindings lives in the api group and would otherwise run
        // first, while no user is set. Any model whose global scope narrows
        // rows by the current user — a tenant scope, a plugin's company scope —
        // is therefore inert at binding time, so a request for another
        // tenant's record still resolves it and only the policy stands in the
        // way. Running the API authenticators first makes those scopes apply to
        // bindings too, which is what their authors expect.
        //
        // Order matters and is built back-to-front below: the security-header
        // decorator has to wrap the authenticators so a 401 still carries the
        // headers, and the cross-origin guard has to answer before them so a
        // cross-site request gets 403 rather than a bare 401.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: MagnaApiMiddleware::class,
        );
        $middleware->prependToPriorityList(
            before: MagnaApiMiddleware::class,
            prepend: ApiKeyMiddleware::class,
        );
        $middleware->prependToPriorityList(
            before: ApiKeyMiddleware::class,
            prepend: DenyManagementCrossOriginMiddleware::class,
        );
        $middleware->prependToPriorityList(
            before: DenyManagementCrossOriginMiddleware::class,
            prepend: SecurityHeadersMiddleware::class,
        );

        // Under Octane the SchemaRegistry singleton persists across requests, so
        // content-type changes must be re-synced from the (cache-backed) DB at
        // the start of each request. No-op under php-fpm (fresh boot per
        // request). Prepended to both groups so it runs before the admin panel
        // and the delivery/management APIs read content types.
        $middleware->web(prepend: [RefreshDatabaseContentTypes::class]);
        $middleware->api(prepend: [RefreshDatabaseContentTypes::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Reporting must never be the thing that kills the request.
        //
        // Laravel's own reporter asks the container for `config` to work out
        // which log channel to use. If the container has not got that far —
        // a failure during bootstrap, or one raised after the application has
        // been flushed — that lookup throws, and the visitor is shown a fatal
        // about a missing class called "config" instead of whatever actually
        // went wrong. Every 404 on this site was arriving that way.
        //
        // Returning false stops the default logging for this exception, so the
        // request carries on to render a normal error response. The message
        // still goes to the server's error log, which is the only writer that
        // is certain to be available at this point.
        $exceptions->reportable(function (Throwable $e): bool {
            if (app()->bound('config') && app()->bound('log')) {
                return true;
            }

            error_log(sprintf(
                '[magna] %s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return false;
        });

        // Production-only: local/testing already surface errors directly
        // (debug page, test failures) — the bell is for catching things in
        // an environment nobody is actively watching a terminal for.
        $exceptions->reportable(function (Throwable $e): void {
            // `environment()` resolves the container's `env` binding, which does
            // not exist yet if the failure happened during bootstrap. Asking for
            // it there throws a BindingResolutionException *from the reporter*,
            // which replaces the real exception with an unreadable one about a
            // missing class called "env" — the exact masking the block below
            // was written to prevent.
            if (! app()->bound('env') || ! app()->environment('production')) {
                return;
            }

            // 4xx-class exceptions (validation, auth, 404, CSRF token
            // mismatch) are expected traffic noise, not something an admin
            // needs paged for — only genuine 5xx/unclassified failures
            // reach the bell.
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($statusCode < 500) {
                return;
            }

            // The bell itself must never mask the real failure. If the cache or
            // database backing this is unavailable (e.g. a misconfigured or
            // not-yet-installed site), swallow it so the operator sees the
            // original exception, not a secondary one from the reporter.
            try {
                // Rate-limited per distinct exception (class + message), not
                // per occurrence — a tight crash loop must not flood the bell
                // with hundreds of identical rows.
                $key = 'magna.admin.exception-notified.'.md5($e::class.'|'.$e->getMessage());
                if (Cache::has($key)) {
                    return;
                }
                Cache::put($key, true, now()->addHour());

                NotificationRecipients::notifyDashboard(
                    'Unexpected error',
                    $e->getMessage() !== '' ? $e->getMessage() : $e::class,
                    'danger',
                );
            } catch (Throwable $reporterError) {
                // Reporting is best-effort; never let it escalate. Leave a
                // debug breadcrumb so a broken notifier is still diagnosable.
                try {
                    Log::debug('Admin exception-notifier failed', [
                        'error' => $reporterError->getMessage(),
                    ]);
                } catch (Throwable) {
                    // Logging itself unavailable — nothing more we can safely do.
                }
            }
        });
    })->create();
