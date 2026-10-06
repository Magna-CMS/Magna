<?php

declare(strict_types=1);

/**
 * A theme says what a freshly inserted block starts with.
 *
 * Core computes a seed from the schema, which is the only side that knows what
 * an `optionsFrom` select offers on this install — a builder seeding its own
 * would insert blocks the save then refuses. But that seed is generic by
 * construction, and a theme's blocks are a design vocabulary: dropping
 * `features` into a timeline band seeded three cards about nothing, which an
 * author deleted before writing the real ones.
 *
 * Merged OVER the registry seed, never substituted — a theme states the keys
 * it wants to differ and keeps whatever the schema decided for the rest,
 * including anything a later core release adds.
 */

use Magna\Blocks\BlockRegistry;
use Magna\Pages\Builder\BuilderBootstrap;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;

uses(PluginTestCase::class);

function seedFor(string $handle): array
{
    foreach (app(BuilderBootstrap::class)->registryPayload(app(BlockRegistry::class)) as $block) {
        if ($block['handle'] === $handle) {
            return is_array($block['seed']) ? $block['seed'] : [];
        }
    }

    return [];
}

function seedTheme(array $blockSeeds): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    $root = sys_get_temp_dir().'/magna-seed-themes-'.bin2hex(random_bytes(4));
    $dir = $root.'/seedvendor/seeded';
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/theme.json', json_encode([
        'name' => 'seedvendor/seeded',
        'displayName' => 'Seeded',
        'version' => '1.0.0',
        'compat' => ['magna' => '^1.0'],
        'blockSeeds' => $blockSeeds,
    ], JSON_THROW_ON_ERROR));

    config(['magna.themes_path' => $root]);
    app(ThemeManager::class)->activate('seedvendor/seeded');
}

it('starts a block with what the theme asked for', function (): void {
    seedTheme(['heading' => ['text' => 'Section title', 'level' => 'h3']]);

    expect(seedFor('heading'))
        ->toMatchArray(['text' => 'Section title', 'level' => 'h3']);
});

it('keeps the schema seed for keys the theme said nothing about', function (): void {
    // Only `text` is stated, so `align` must still be whatever the schema's
    // default decided — a theme opts into the keys it cares about, and a key
    // a later core release adds keeps arriving.
    $core = seedFor('heading');

    seedTheme(['heading' => ['text' => 'Only this']]);

    $themed = seedFor('heading');

    expect($themed['text'])->toBe('Only this');

    foreach ($core as $key => $value) {
        if ($key !== 'text') {
            expect($themed[$key] ?? null)->toBe($value);
        }
    }
});

it('leaves blocks the theme did not mention alone', function (): void {
    $core = seedFor('text');

    seedTheme(['heading' => ['text' => 'Only heading']]);

    expect(seedFor('text'))->toBe($core);
});

it('ignores a seed that is a value where a field map belongs', function (): void {
    // `{"heading": ["a", "b"]}` is a theme author writing a list where a map
    // of field handles goes. Keys no field answers to must not reach the
    // builder as a seed.
    $core = seedFor('heading');

    seedTheme(['heading' => ['a', 'b']]);

    expect(seedFor('heading'))->toBe($core);
});

it('ignores a seed whose handle is not a block', function (): void {
    seedTheme(['heading' => ['text' => 'Real'], '' => ['text' => 'Nameless']]);

    expect(seedFor('heading')['text'])->toBe('Real');
});
