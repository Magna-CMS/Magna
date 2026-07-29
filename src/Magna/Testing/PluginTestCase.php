<?php

declare(strict_types=1);

namespace Magna\Testing;

use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Auth\PermissionRegistry;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

/**
 * Base test case for Magna plugin tests.
 *
 * Usage (Pest):
 *   uses(PluginTestCase::class);
 *   beforeEach(fn () => $this->enablePlugin('acme/my-plugin'));
 *
 * Usage (PHPUnit class-based):
 *   class MyPluginTest extends PluginTestCase {
 *       protected string $plugin = 'acme/my-plugin';
 *   }
 */
abstract class PluginTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Override in subclasses to automatically enable a plugin before each test.
     */
    protected string $plugin = '';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->plugin !== '') {
            $this->enablePlugin($this->plugin);
        }
    }

    /**
     * Enable a plugin for the current test. Call this from Pest's beforeEach().
     */
    public function enablePlugin(string $name): void
    {
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);
        $manager->enable($name);
    }

    /**
     * Disable a plugin for the current test.
     */
    public function disablePlugin(string $name): void
    {
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);
        $manager->disable($name);
    }

    /** Assert the named plugin has an enabled record. */
    public function assertPluginEnabled(string $name): void
    {
        $this->assertTrue(
            PluginRecord::query()->where('name', $name)->where('enabled', true)->exists(),
            "Failed asserting that plugin [{$name}] is enabled.",
        );
    }

    /** Assert the named plugin exists but is disabled. */
    public function assertPluginDisabled(string $name): void
    {
        $this->assertTrue(
            PluginRecord::query()->where('name', $name)->where('enabled', false)->exists(),
            "Failed asserting that plugin [{$name}] is disabled.",
        );
    }

    /** Assert the plugin is live in the current request (registered + booted). */
    public function assertPluginBooted(string $name): void
    {
        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);

        $this->assertArrayHasKey(
            $name,
            $manager->getEnabled(),
            "Failed asserting that plugin [{$name}] is booted.",
        );
    }

    /** Assert a permission key is registered in the PermissionRegistry. */
    public function assertPermissionRegistered(string $key): void
    {
        /** @var PermissionRegistry $registry */
        $registry = $this->app->make(PermissionRegistry::class);

        $this->assertTrue(
            $registry->has($key),
            "Failed asserting that permission [{$key}] is registered.",
        );
    }

    /** Assert a route is registered for the given URI (method-agnostic). */
    public function assertRouteRegistered(string $uri): void
    {
        /** @var Registrar $router */
        $router = $this->app->make('router');
        $needle = ltrim($uri, '/');

        $found = false;
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $needle) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Failed asserting that a route for URI [{$uri}] is registered.");
    }
}
