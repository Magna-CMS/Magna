<?php

declare(strict_types=1);

namespace Magna\Plugins;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Magna\Auth\PermissionRegistry;

/**
 * Wires an enabled plugin into the HTTP surface: its API/web routes and the
 * permissions its manifest declares. Extracted from PluginManager so route
 * mounting (with its reserved-slug shadowing guard) and permission
 * registration are one focused responsibility, separate from lifecycle
 * orchestration.
 */
final class PluginRouteRegistrar
{
    /**
     * S1-07: core module route slugs, reserved at manifest-validation time
     * by ManifestValidator — re-checked here too as defense in depth, since
     * a plugin installed by an older SDK version (before that check
     * existed) could still be sitting in the `plugins` table with a
     * reserved slug. Registering its routes under that slug would let it
     * permanently shadow the matching core module's `api/v1/{slug}/*`
     * routes (Laravel's route table is a plain overwritable array keyed by
     * method+URI, and this module boots after every reserved core module —
     * see MagnaServiceProvider::register()).
     *
     * @var list<string>
     */
    private const RESERVED_ROUTE_SLUGS = [
        'admin', 'audit', 'auth', 'blocks', 'content', 'delivery', 'install',
        'management', 'media', 'plugins', 'privacy', 'settings', 'users', 'webhooks',
    ];

    public function __construct(private readonly Application $app) {}

    public function loadRoutes(Plugin $plugin): void
    {
        $slug = Arr::last(explode('/', $plugin->getManifest()->name));

        if (in_array($slug, self::RESERVED_ROUTE_SLUGS, true)) {
            Log::warning(
                "Plugin \"{$plugin->getManifest()->name}\" uses a reserved route slug \"{$slug}\" — refusing to register its routes to avoid shadowing a core module.",
            );

            return;
        }

        $apiRoutesFile = $plugin->getBasePath().'/routes/api.php';
        if (file_exists($apiRoutesFile)) {
            Route::middleware('api')
                ->prefix('api/v1/'.$slug)
                ->group($apiRoutesFile);
        }

        $webRoutesFile = $plugin->getBasePath().'/routes/web.php';
        if (file_exists($webRoutesFile)) {
            $this->loadWebRoutes($plugin, $webRoutesFile);
        }
    }

    /**
     * Plugin web routes mount at the application root — a plugin may declare
     * `/`, `/login`, or `/dashboard`, and Laravel's route table is a plain
     * array keyed by method+URI, so the last registration silently wins.
     * The reserved-slug list above only guards the `api/v1/{slug}` prefix; it
     * does nothing for web paths.
     *
     * Prefixing every plugin web route with its slug would break every plugin
     * that already publishes a public URL, so this detects and reports the
     * shadowing rather than preventing it: snapshot what is registered, load
     * the plugin's routes, and report any signature now bound to a different
     * action than before. Core boots first, so a collision means a plugin has
     * taken over a core (or earlier plugin) URL.
     *
     * Detection, not prevention — Laravel's route collection has no supported
     * way to un-replace an entry mid-boot. What it buys is that the failure
     * stops being invisible: a plugin quietly owning `/login` currently
     * produces no signal at all.
     */
    private function loadWebRoutes(Plugin $plugin, string $webRoutesFile): void
    {
        $router = $this->app->make('router');
        $before = $this->routeSignatures($router);

        Route::middleware('web')->group($webRoutesFile);

        $collisions = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $signature = $method.' '.$route->uri();

                // Same signature seen before this plugin loaded AND now bound
                // to a different action: this registration displaced something.
                if (isset($before[$signature]) && $before[$signature] !== $this->actionSignature($route)) {
                    $collisions[] = $signature;
                }
            }
        }

        if ($collisions === []) {
            return;
        }

        Log::critical(
            "Plugin \"{$plugin->getManifest()->name}\" registered web route(s) that now shadow pre-existing routes: "
            .implode(', ', array_unique($collisions))
            .'. Requests to those paths reach the plugin, not the original handler. Move the plugin\'s routes under its own path prefix, or disable the plugin.',
        );
    }

    /**
     * @return array<string, string> "METHOD uri" => action signature
     */
    private function routeSignatures(Router $router): array
    {
        $signatures = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $signatures[$method.' '.$route->uri()] = $this->actionSignature($route);
            }
        }

        return $signatures;
    }

    private function actionSignature(\Illuminate\Routing\Route $route): string
    {
        $action = $route->getAction('uses');

        return is_string($action) ? $action : ($route->getName() ?? 'closure');
    }

    public function registerPermissions(Manifest $manifest): void
    {
        if ($manifest->permissions === []) {
            return;
        }

        /** @var PermissionRegistry $registry */
        $registry = $this->app->make(PermissionRegistry::class);
        foreach ($manifest->permissions as $permission) {
            $registry->register($permission);
        }
    }
}
