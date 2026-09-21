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
use Magna\Auth\Http\Middleware\SecureSessionCookieMiddleware;
use Magna\Auth\Http\Middleware\SecurityHeadersMiddleware;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\Http\Middleware\RefreshDatabaseContentTypes;
use Magna\Install\Http\Middleware\RedirectIfNotInstalled;
use Magna\Install\Installer;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

// Before anything resolves a Magna class: a core update replaces src/Magna but
// never vendor/, so classes added by a release are missing from the classmap
// the site was installed with. See bootstrap/magna-autoload.php.
spl_autoload_register((require __DIR__.'/magna-autoload.php')(__DIR__.'/../src/Magna'));

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

        // Trusted proxies are configured in config/trustedproxy.php, not here.
        //
        // This callback runs when the HTTP kernel is resolved, which happens
        // before the config repository is bound — so the config() lookup that
        // used to stand here always returned null and trustProxies() was never
        // called. TRUSTED_PROXIES had no effect at all: behind the tunnel every
        // request's IP was the proxy's, which is the address the audit log
        // recorded and login throttling counted, and X-Forwarded-Proto was
        // ignored, so an https site looked plaintext to the application.
        // TrustProxies reads trustedproxy.proxies while handling a request,
        // when config is loaded, which is why the list lives in a config file.

        // Bound the hostnames this application will believe it is served on.
        // Host and X-Forwarded-Host are client-supplied, and Laravel builds
        // absolute URLs — password-reset links first among them — from
        // whatever they claim: without this, a spoofed header put an
        // attacker's domain into a victim's reset email. Trusted are
        // APP_URL's host with its subdomains (the middleware's default)
        // plus the explicit MAGNA_TRUSTED_HOSTS list for installs reached
        // at more than one address; anything else is refused with a 400
        // before a URL is built. The closure is evaluated per request by
        // the TrustHosts middleware, when config is loaded — the same
        // reason the proxy list lives in a config file (see above).
        $middleware->trustHosts(at: static function (): array {
            // A site that is not installed yet has no APP_URL to trust. The
            // operator supplies the canonical URL on the installer's Site step
            // (InstallController::storeSite writes it), so until then app.url
            // is config's `http://localhost` fallback — and enforcing the list
            // against that refuses the very request that would run the
            // installer. A fresh unzip therefore answered a bare Symfony 400
            // on every hostname except localhost, with no way forward short of
            // hand-editing .env. Trusting any host here costs nothing: nothing
            // pre-install builds a link or sends mail from the Host header,
            // and the lock file this checks needs no database.
            if (! Installer::isInstalled()) {
                return ['^.+$'];
            }

            $extra = config('magna.security.trusted_hosts');

            return array_values(array_filter(
                array_map(trim(...), explode(',', is_string($extra) ? $extra : '')),
                static fn (string $host): bool => $host !== '',
            ));
        });

        // Appended to the GLOBAL stack, and both halves of that placement
        // matter: appending lands it after TrustProxies (also global), so
        // request()->isSecure() sees X-Forwarded-Proto behind a proxy, and the
        // global stack runs before any route group's EncryptCookies /
        // StartSession, so session.secure is decided before the cookie is
        // built. Global rather than in the web group so it covers every
        // session-starting route no matter which groups a panel or plugin
        // chooses to include — the admin panel happens to include 'web'
        // today (see AdminPanelProvider), but nothing here depends on that.
        $middleware->append(SecureSessionCookieMiddleware::class);

        // S1-11: ForceHttpsMiddleware was registered as a middleware alias
        // but never actually attached to any route or group — toggling
        // "Force HTTPS" in Security Settings had zero runtime effect. Wired
        // in ahead of everything else in the web group, so a plain HTTP
        // request is redirected before CORS/security-header processing even
        // runs. DB-backed (reads SecuritySettings), which is safe here since
        // RedirectIfNotInstalled (prepended above) has already handled the
        // pre-install, DB-unavailable case.
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
        // api/* always answers JSON; everywhere else the client decides via
        // its Accept header (Laravel's own default, which this callback
        // REPLACES rather than extends — dropping expectsJson() here silently
        // turned every failed validate() on a web-group JSON client, e.g.
        // the Pages builder SPA, into a 302 redirect with session errors).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A schema rule violation on an API route is a 400 with the rule's
        // own message — rendered here once (Laravel-first: the framework's
        // renderable seam), instead of the byte-identical catch block that
        // used to sit in six EntryController actions. Non-API surfaces keep
        // their own handling (returning null falls through to the default).
        $exceptions->render(function (SchemaException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 400);
            }

            return null;
        });

        // An untrusted Host is a configuration fault far more often than an
        // attack, and Symfony's stock renderer says only "400 Bad Request" —
        // which names neither the header it refused nor the setting that would
        // accept it. An operator who moves a site to a second domain, or puts
        // it behind a proxy that rewrites Host, gets a page with nothing to act
        // on. Say what was rejected and what to change; the refusal itself is
        // unchanged, and the host is echoed escaped because it is attacker-
        // controlled by definition.
        //
        // Typed on BadRequestHttpException rather than the Symfony exception
        // itself: Handler::render() runs prepareException() BEFORE the render
        // callbacks, and that converts every RequestExceptionInterface into a
        // BadRequestHttpException — a callback typed on the original would
        // never fire. The untrusted-host case is identified by the preserved
        // previous exception, and anything else falls through to the default.
        $exceptions->render(function (BadRequestHttpException $e, Request $request) {
            if (! $e->getPrevious() instanceof SuspiciousOperationException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Bad hostname provided.'], 400);
            }

            return response()->view('magna-install::untrusted-host', [
                'host' => (string) $request->headers->get('host', ''),
                'configuredUrl' => (string) config('app.url', ''),
            ], 400);
        });

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
