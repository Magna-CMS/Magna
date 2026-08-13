<?php

declare(strict_types=1);

/**
 * The authoring scaffolds: magna:theme-addon:make produces a package the
 * §C8 audit passes clean, and magna:block:check validates a block.json
 * with useful warnings.
 */

use Magna\Pages\Themes\ThemeAuditor;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;

uses(PluginTestCase::class);

function scaffoldSetup(): string
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $dir = storage_path('framework/testing/scaffold-themes-'.uniqid());
    mkdir($dir, 0755, true);
    config(['magna.themes_path' => $dir]);

    return $dir;
}

it('scaffolds an addon that audits clean and registers as an addon', function (): void {
    scaffoldSetup();

    $this->artisan('magna:theme-addon:make', [
        'name' => 'acme/launch-careers',
        '--extends' => ['magna/launch'],
        '--pairs-with' => ['acme/careers'],
    ])->assertSuccessful();

    $manifest = app(ThemeManager::class)->find('acme/launch-careers');
    expect($manifest)->not->toBeNull()
        ->and($manifest->isAddon())->toBeTrue()
        ->and($manifest->extends)->toBe('magna/launch')
        ->and($manifest->pairsWith)->toBe(['acme/careers']);

    $errors = array_filter(
        app(ThemeAuditor::class)->audit('acme/launch-careers'),
        fn (array $f): bool => $f['level'] === 'error',
    );
    expect($errors)->toBe([]);

    // Refuses an addon with nothing to pair with.
    $this->artisan('magna:theme-addon:make', ['name' => 'acme/pointless'])->assertFailed();
});

it('validates a block.json and warns on required-without-default', function (): void {
    scaffoldSetup();

    $dir = storage_path('framework/testing/block-check-'.uniqid());
    mkdir($dir.'/blocks', 0755, true);
    file_put_contents($dir.'/blocks/thing.json', json_encode([
        'handle' => 'thing',
        'label' => 'Thing',
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
        ],
    ]));

    $this->artisan('magna:block:check', ['path' => $dir.'/blocks/thing.json'])
        ->expectsOutputToContain('parses')
        ->expectsOutputToContain('required with no default')
        ->assertSuccessful();

    // Malformed handle fails outright.
    file_put_contents($dir.'/blocks/bad.json', json_encode(['handle' => 'Bad Handle!']));
    $this->artisan('magna:block:check', ['path' => $dir.'/blocks/bad.json'])->assertFailed();
});
