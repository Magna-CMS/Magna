<?php

declare(strict_types=1);

/**
 * magna:theme:check v2 — the §C8 restricted-view audit. Real shipped
 * themes come out clean; the sketchy fixture trips every rule class: raw
 * PHP, forbidden calls, unallowlisted raw echo, script in a block view,
 * poisoned token values, and an addon shipping a layout.
 */

use Magna\Pages\Themes\ThemeAuditor;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function auditSetup(): void
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
}

it('passes the shipped themes and reference addon clean', function (): void {
    auditSetup();

    foreach (['magna/launch', 'magna/theme-studio', 'magna/studio-pages-kit'] as $theme) {
        $errors = array_filter(
            app(ThemeAuditor::class)->audit($theme),
            fn (array $f): bool => $f['level'] === 'error',
        );
        expect($errors)->toBe([], $theme.' should audit clean, got: '.json_encode(array_values($errors)));
    }
});

it('flags every restricted-view violation in the sketchy fixture', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $messages = array_column(app(ThemeAuditor::class)->audit('badvendor/sketchy'), 'message');
    $all = implode(' | ', $messages);

    expect($all)->toContain('Raw <?php')
        ->and($all)->toContain('file_get_contents()')
        ->and($all)->toContain('{!! $secrets !!}')
        ->and($all)->toContain('<script> element in a block view')
        ->and($all)->toContain("contains ';' or '('");

    // The layout-contract raw echoes are NOT flagged.
    expect($all)->not->toContain('{!! $tokensCss !!} —');
});

it('refuses an addon that ships a layout, and fails the command on errors', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $messages = array_column(app(ThemeAuditor::class)->audit('badvendor/sketchy-addon'), 'message');
    expect(implode(' ', $messages))->toContain('Addons may only ship block views');

    $this->artisan('magna:theme:check', ['theme' => 'badvendor/sketchy'])->assertFailed();
    $this->artisan('magna:theme:check', ['theme' => 'addonhost/base'])->assertSuccessful();
});
