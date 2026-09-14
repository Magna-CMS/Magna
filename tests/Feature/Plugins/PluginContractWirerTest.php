<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Magna\Contracts\CaptchaKeypair;
use Magna\Contracts\CaptchaSurface;
use Magna\Contracts\DecoratesDeliveryResponse;
use Magna\Contracts\RegistersCaptchaSurfaces;
use Magna\Contracts\RegistersLoginChecks;
use Magna\Plugins\Manifest;
use Magna\Plugins\Plugin;
use Magna\Plugins\PluginContractWirer;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Pins the container-accumulation semantics PluginContractWirer owns: the
 * list bindings (login checks, captcha surfaces, delivery decorators) are
 * created by the first contributing plugin and appended to — never
 * replaced — by every later one, in wiring order. Wiring one plugin must
 * not disturb a binding it does not contribute to.
 */

function contractWirerManifest(string $name): Manifest
{
    return Manifest::fromArray([
        'name' => $name,
        'displayName' => 'Wirer Test Plugin',
        'description' => 'A contract-wirer test plugin.',
        'version' => '1.0.0',
        'author' => 'Test Author',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'entry' => 'Test\\Plugin\\TestPlugin',
        'provides' => [],
        'permissions' => [],
    ]);
}

function contractWirerPlugin(string $name, array $loginChecks, array $captchaSurfaces): Plugin
{
    return new class(app(), sys_get_temp_dir(), contractWirerManifest($name), $loginChecks, $captchaSurfaces) extends Plugin implements DecoratesDeliveryResponse, RegistersCaptchaSurfaces, RegistersLoginChecks
    {
        public function __construct(
            Application $app,
            string $basePath,
            Manifest $manifest,
            private readonly array $checks,
            private readonly array $surfaces,
        ) {
            parent::__construct($app, $basePath, $manifest);
        }

        public function loginChecks(): array
        {
            return $this->checks;
        }

        public function captchaSurfaces(): array
        {
            return $this->surfaces;
        }

        public function decorateDeliveryEntry(string $contentType, string $entryId, array &$payload): void
        {
            $payload['decorated_by'][] = $this->manifest->name;
        }
    };
}

it('creates a list binding on the first contributing plugin and appends on the next, in order', function (): void {
    expect(app()->bound('magna.auth.login_checks'))->toBeFalse();

    $wirer = new PluginContractWirer(app());

    $wirer->wire(contractWirerPlugin('acme/one', ['CheckA', 'CheckB'], []));
    $wirer->wire(contractWirerPlugin('acme/two', ['CheckC'], []));

    expect(app()->make('magna.auth.login_checks'))->toBe(['CheckA', 'CheckB', 'CheckC']);
});

it('accumulates decorator instances without replacing earlier contributions', function (): void {
    $wirer = new PluginContractWirer(app());

    $first = contractWirerPlugin('acme/one', [], []);
    $second = contractWirerPlugin('acme/two', [], []);
    $wirer->wire($first);
    $wirer->wire($second);

    expect(app()->make('magna.delivery_decorators'))->toBe([$first, $second]);
});

it('leaves bindings a plugin does not contribute to untouched', function (): void {
    $surface = new CaptchaSurface('acme.one.login', 'Login form', 'Acme One', CaptchaKeypair::Portal);

    $wirer = new PluginContractWirer(app());
    $wirer->wire(contractWirerPlugin('acme/one', [], [$surface]));

    $before = app()->make('magna.captcha.surfaces');

    // A plugin with no captcha surfaces must not reset the accumulated list.
    $wirer->wire(contractWirerPlugin('acme/two', ['CheckA'], []));

    expect(app()->make('magna.captcha.surfaces'))->toBe($before)
        ->and($before)->toBe([$surface]);
});
