<?php

declare(strict_types=1);

/**
 * magna:theme:check v2 — the §C8 restricted-view audit. Real shipped
 * themes come out clean; the sketchy fixture trips every rule class: raw
 * PHP, forbidden calls, unallowlisted raw echo, script in a block view,
 * poisoned token values, queries and container pulls in views, and an
 * addon shipping a layout.
 *
 * Plus the one rule that reads two files rather than one: what an
 * overriding block view DROPS from the default view it replaces.
 */

use Illuminate\Support\Facades\View;
use Magna\Pages\Themes\ThemeAuditor;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function auditSetup(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
}

it('passes the shipped themes and reference addon clean', function (): void {
    auditSetup();

    foreach (['magna/launch', 'magna/nova', 'magna/theme-studio', 'magna/studio-pages-kit'] as $theme) {
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

it('flags queries and container pulls in theme views', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $messages = array_column(app(ThemeAuditor::class)->audit('badvendor/sketchy'), 'message');
    $all = implode(' | ', $messages);

    // "Views receive data; they never query" — the documented rule the
    // audit used to let straight through: @php(Entry::type(...)) passed
    // magna:theme:check clean, which made querying the cheap route around
    // a value the layout payload lacked.
    expect($all)->toContain('Entry::type() in a view')
        ->and($all)->toContain('::query() in a view')
        ->and($all)->toContain('DB:: in a view')
        ->and($all)->toContain('resolve() in a view')
        ->and($all)->toContain('app(...) in a view')
        ->and($all)->toContain('app()-> in a view');
});

it('keeps allowing the shipped lang-attribute idiom app()->getLocale()', function (): void {
    auditSetup();

    // The shipped themes all open with
    // `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">` —
    // the first test's clean audit is what pins that; this one documents
    // the carve-out explicitly against the fixture tree's host theme.
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    // Only the VIEW findings: the fixture's tokens.json is poisoned on
    // purpose for the token-filter tests and keeps its own errors.
    $errors = array_filter(
        app(ThemeAuditor::class)->audit('testv/fixture'),
        fn (array $f): bool => $f['level'] === 'error' && str_starts_with($f['file'], 'views/'),
    );

    expect(array_values($errors))->toBe([]);
});

it('refuses an addon that ships a layout, and fails the command on errors', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    $messages = array_column(app(ThemeAuditor::class)->audit('badvendor/sketchy-addon'), 'message');
    expect(implode(' ', $messages))->toContain('Addons may only ship block views');

    $this->artisan('magna:theme:check', ['theme' => 'badvendor/sketchy'])->assertFailed();
    $this->artisan('magna:theme:check', ['theme' => 'addonhost/base'])->assertSuccessful();
});

/**
 * What an override DROPS, which no other rule here looks at.
 *
 * The audit's other rules all read one file and judge it on its own. These
 * two compare an overriding block view against the default view it replaces,
 * because the sharpest theme failure found so far was invisible to every
 * single-file rule: scheme-toggle carried its un-hide, click handler and
 * stored choice in an @once script inside its own view, the rule above
 * refuses a theme's block view any script of its own, and so a theme that
 * overrode the view to restyle the button lost the button — silently, with
 * the markup still rendering `hidden` and nothing left to lift it.
 */
it('warns when an override drops the inline script its default view ships', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    // A default view that still carries its behaviour inline. Core has none
    // left — scheme-toggle's moved to block-behaviour/, which is the real
    // fix — so one is mounted behind the namespace here: the rule has to go
    // on catching the shape wherever it survives, plugin blocks included.
    View::addNamespace('magna', dirname(__DIR__, 2).'/Fixtures/views');

    $warnings = array_values(array_filter(
        app(ThemeAuditor::class)->audit('badvendor/sketchy'),
        fn (array $f): bool => $f['level'] === 'warning' && $f['file'] === 'views/blocks/scripty.blade.php',
    ));

    // Asserted on what the warning TELLS the author to do, not on its prose:
    // that core still ships the behaviour here, and that the theme must carry
    // it. The previous wording named the block-behaviour view as though the
    // move had happened, and a theme author deleted working wiring on the
    // strength of it.
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toContain('core still ships')
        ->and($warnings[0]['message'])->toContain('your theme has to carry it');
});

it('stops warning about a dropped script once core ships the behaviour apart', function (): void {
    // Same guard as every sibling here: the auditor comes from the Pages
    // plugin, so a checkout without plugins-dev (CI) must skip rather than
    // fail resolving it.
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    View::addNamespace('magna', dirname(__DIR__, 2).'/Fixtures/views');

    // The fixture namespace has blocks/scripty but no block-behaviour/scripty,
    // which is what makes the warning above fire. scheme-toggle is the handle
    // core HAS moved, so the same override shape draws nothing for it — the
    // warning retires itself per handle as the migration reaches it, instead
    // of having to be remembered and removed.
    $warnings = array_values(array_filter(
        app(ThemeAuditor::class)->audit('badvendor/sketchy'),
        fn (array $f): bool => $f['level'] === 'warning'
            && str_contains($f['message'], 'core still ships')
            && str_contains($f['file'], 'scheme-toggle'),
    ));

    expect($warnings)->toBe([]);
});

it('warns when an override drops the hook a block behaviour binds to', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    // The one way the repaired toggle can still be broken: the behaviour
    // ships, finds nothing to bind to, and the button stays hidden.
    $messages = array_column(
        array_filter(
            app(ThemeAuditor::class)->audit('badvendor/sketchy'),
            fn (array $f): bool => $f['file'] === 'views/blocks/scheme-toggle.blade.php',
        ),
        'message',
    );

    expect(implode(' | ', $messages))->toContain('without its `data-magna-scheme-toggle` attribute');
});

it('says nothing about an override that keeps what the behaviour needs', function (): void {
    auditSetup();
    config(['magna.themes_path' => dirname(__DIR__, 2).'/Fixtures/themes']);

    // testv/fixture restyles the switch and keeps the hook, the hidden
    // attribute and the two spans. A warning here would be noise, and noise
    // is how a real warning gets ignored.
    $findings = array_filter(
        app(ThemeAuditor::class)->audit('testv/fixture'),
        fn (array $f): bool => $f['file'] === 'views/blocks/scheme-toggle.blade.php',
    );

    expect($findings)->toBe([], 'got: '.json_encode(array_values($findings)));
});
