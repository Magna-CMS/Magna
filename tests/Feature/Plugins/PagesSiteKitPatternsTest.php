<?php

declare(strict_types=1);

/**
 * Patterns travel in the site kit.
 *
 * A pattern is how a composite stops being something an editor rebuilds by
 * hand — a mocked-up terminal is a container, a bar, a body and five leaf
 * blocks, and wanting a second one meant assembling all of it again. Until
 * this key existed a pattern could only be born from a user's own selection,
 * so a theme could ship the markup for a composite and no way to reuse it.
 *
 * Keyed by name, because a kit crosses environments and an id is an
 * environment fact: re-syncing updates the pattern an editor already has
 * rather than leaving two that look identical.
 */

use Magna\Pages\Builder\Pattern;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function patternKitUser(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
}

/** A one-block section, which is the smallest thing the validator accepts. */
function patternSection(string $text = 'Terminal'): array
{
    return [
        'id' => 'sec-pattern',
        'type' => 'section',
        'settings' => [],
        'columns' => [[
            'id' => 'col-pattern',
            'span' => 12,
            'settings' => [],
            'blocks' => [[
                'id' => 'blk-pattern',
                'type' => 'block',
                'block' => 'text',
                'data' => ['body' => $text],
                'settings' => [],
            ]],
        ]],
    ];
}

function patternKit(array $patterns): array
{
    return [
        'format' => 'magna-site-kit',
        'version' => SiteKit::VERSION,
        'pages' => [],
        'templates' => [],
        'menus' => [],
        'patterns' => $patterns,
        'settings' => [],
    ];
}

it('carries saved patterns out in the bundle', function (): void {
    patternKitUser();

    Pattern::query()->create([
        'name' => 'POS terminal',
        'kind' => Pattern::KIND_SECTION,
        'document' => patternSection(),
    ]);

    $kit = app(SiteKit::class)->build();

    expect($kit['patterns'])->toHaveCount(1)
        ->and($kit['patterns'][0]['name'])->toBe('POS terminal')
        ->and($kit['patterns'][0]['kind'])->toBe('section');
});

it('creates a pattern the kit brought', function (): void {
    patternKitUser();

    $counts = app(SiteKit::class)->apply(patternKit([
        ['name' => 'POS terminal', 'kind' => 'section', 'document' => patternSection()],
    ]));

    expect($counts['patterns'])->toBe(1)
        ->and(Pattern::query()->where('name', 'POS terminal')->exists())->toBeTrue();
});

it('updates the pattern an editor already has rather than duplicating it', function (): void {
    patternKitUser();

    $kit = patternKit([
        ['name' => 'POS terminal', 'kind' => 'section', 'document' => patternSection('First')],
    ]);

    app(SiteKit::class)->apply($kit);
    app(SiteKit::class)->apply(patternKit([
        ['name' => 'POS terminal', 'kind' => 'section', 'document' => patternSection('Second')],
    ]));

    expect(Pattern::query()->where('name', 'POS terminal')->count())->toBe(1);
});

it('skips a pattern that will not validate, and applies the rest', function (): void {
    patternKitUser();

    $counts = app(SiteKit::class)->apply(patternKit([
        ['name' => 'Broken', 'kind' => 'section', 'document' => ['not' => 'a section']],
        ['name' => 'Good', 'kind' => 'section', 'document' => patternSection()],
    ]));

    // A kit is a file somebody can edit, so it earns no more trust than the
    // builder does — but one bad pattern must not cost the whole sync.
    expect($counts['patterns'])->toBe(1)
        ->and(Pattern::query()->where('name', 'Good')->exists())->toBeTrue()
        ->and(Pattern::query()->where('name', 'Broken')->exists())->toBeFalse();
});

it('accepts a bundle with no patterns key at all', function (): void {
    patternKitUser();

    // Every kit written before this existed.
    $kit = patternKit([]);
    unset($kit['patterns']);

    $counts = app(SiteKit::class)->apply($kit);

    expect($counts['patterns'])->toBe(0);
});
