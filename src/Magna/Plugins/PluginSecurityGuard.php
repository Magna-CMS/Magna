<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Magna\Audit\Listeners\RecordLoginFailure;
use Magna\Audit\Listeners\RecordLoginSuccess;
use Magna\Auth\PermissionRegistry;

/**
 * Detects and reverts security-critical tampering performed by a plugin's
 * register()/boot() and reports it as critical.
 *
 * A plugin's register()/boot() runs with the full Application container and
 * Router in scope — nothing in PHP or Laravel stops it from:
 *
 *  - Stage 13 (S1-07): re-aliasing a security-critical middleware name
 *    (e.g. `magna.api` to a no-op class, defanging every Management API
 *    route) or rebinding a security-critical singleton (e.g.
 *    PermissionRegistry, making every permission check pass).
 *  - Stage 13 (S1-09): calling `Event::forget(Login::class)` (or
 *    `Failed::class`) to silently unregister RecordLoginSuccess /
 *    RecordLoginFailure, so every subsequent login on every guard (the
 *    Filament admin panel's own built-in Login page included — a separate
 *    call site that dispatches the same core Illuminate\Auth\Events\Login /
 *    Failed events) goes unaudited with no error or trace.
 *
 * This can't be prevented outright without a real sandbox, which PHP doesn't
 * have — but it CAN be detected and reverted every time plugins boot, closing
 * the "silently persists for the rest of the request/process" window even
 * though it can't close the instant-of-tampering window itself. Capture a
 * snapshot before booting plugins, verify it after.
 *
 * The residual window is real and must be stated plainly to anyone evaluating
 * the plugin security model (docs/plugin-security.md says the same): between
 * the moment a plugin's register()/boot() tampers and the moment this class
 * runs its check, the tampered state IS in effect. Nothing dispatched inside
 * that window is protected. This is a containment control, not an isolation
 * boundary — installing a plugin remains a decision to run its author's code.
 */
final class PluginSecurityGuard
{
    /** @var list<string> */
    private const PROTECTED_MIDDLEWARE_ALIASES = [
        'magna.api', 'magna.api.key', 'magna.security-headers',
        'magna.admin-csp', 'magna.two-factor', 'magna.two-factor-enrolled',
        'magna.force-https',
    ];

    public function __construct(private readonly Application $app) {}

    /**
     * @return array{middleware: array<string, string>, permissionRegistry: PermissionRegistry, loginListenerActive: bool, failedListenerActive: bool}
     */
    public function capture(): array
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $currentAliases = $router->getMiddleware();

        $middleware = [];
        foreach (self::PROTECTED_MIDDLEWARE_ALIASES as $alias) {
            if (isset($currentAliases[$alias])) {
                $middleware[$alias] = $currentAliases[$alias];
            }
        }

        return [
            'middleware' => $middleware,
            'permissionRegistry' => $this->app->make(PermissionRegistry::class),
            'loginListenerActive' => Event::hasListeners(Login::class),
            'failedListenerActive' => Event::hasListeners(Failed::class),
        ];
    }

    /**
     * @param  array{middleware: array<string, string>, permissionRegistry: PermissionRegistry, loginListenerActive: bool, failedListenerActive: bool}  $snapshot
     */
    public function verify(array $snapshot): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $currentAliases = $router->getMiddleware();

        foreach ($snapshot['middleware'] as $alias => $expectedClass) {
            $actualClass = $currentAliases[$alias] ?? null;
            if ($actualClass !== $expectedClass) {
                Log::critical("A plugin changed the protected middleware alias \"{$alias}\" from {$expectedClass} to ".($actualClass ?? '(removed)').' — reverted.');
                $router->aliasMiddleware($alias, $expectedClass);
            }
        }

        if ($this->app->make(PermissionRegistry::class) !== $snapshot['permissionRegistry']) {
            Log::critical('A plugin rebound the PermissionRegistry singleton — reverted. Every permission check would otherwise have run against the plugin-supplied replacement for the rest of this process.');
            $this->app->instance(PermissionRegistry::class, $snapshot['permissionRegistry']);
        }

        if ($snapshot['loginListenerActive'] && ! Event::hasListeners(Login::class)) {
            Log::critical('A plugin unregistered all Login event listeners (Event::forget) — the login audit trail re-registered. Every successful login between the unregister and this check went unaudited.');
            Event::listen(Login::class, RecordLoginSuccess::class);
        }

        if ($snapshot['failedListenerActive'] && ! Event::hasListeners(Failed::class)) {
            Log::critical('A plugin unregistered all Failed (login) event listeners (Event::forget) — the login audit trail re-registered. Every failed login between the unregister and this check went unaudited.');
            Event::listen(Failed::class, RecordLoginFailure::class);
        }
    }
}
