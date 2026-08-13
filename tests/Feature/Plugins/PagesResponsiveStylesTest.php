<?php

declare(strict_types=1);

/**
 * Per-device style values (builder redesign step 13).
 *
 * The owner's condition for building this at all was that it cannot break
 * existing pages or the page cache. Both are asserted here, because they
 * are the reason the design looks the way it does:
 *
 * - the renderer emits every breakpoint into one body, so the bytes are
 *   identical for every visitor and the SHARED cache still applies;
 * - a document with no per-device value renders byte-identically to how
 *   it rendered before this existed.
 */

use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Render\ResponsiveStyles;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function responsiveUser(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

/** @param array<string, mixed> $style */
function responsivePage(User $author, string $slug, array $style, array $columnStyle = []): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Responsive', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-resp', 'type' => 'section',
            'settings' => $style === [] ? [] : ['style' => $style],
            'columns' => [[
                'id' => 'col-resp', 'span' => 12,
                'settings' => $columnStyle === [] ? [] : ['style' => $columnStyle],
                'blocks' => [['id' => 'blk-resp', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Responsive']]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);

    return $entry;
}

it('emits every breakpoint into one body and stays cacheable', function (): void {
    $author = responsiveUser();
    responsivePage($author, 'responsive-page', [
        'paddingTop' => ['$responsive' => ['base' => '96px', 'tablet' => '48px', 'mobile' => '24px']],
    ]);

    $html = $this->get('/responsive-page')->assertOk()
        ->assertHeader('X-Magna-Cache', 'miss')
        ->getContent();

    // Base rides the style attribute as before; the narrower widths ride a
    // stylesheet, because the renderer cannot know the viewport.
    expect($html)->toContain('padding-top:96px')
        ->and($html)->toContain('@media (max-width: 1023.98px)')
        ->and($html)->toContain('padding-top:48px')
        ->and($html)->toContain('@media (max-width: 767.98px)')
        ->and($html)->toContain('padding-top:24px')
        ->and($html)->toContain('magna-n-sec-resp');

    // The second visit is a cache HIT: per-device values did not make the
    // page per-visitor, which is the whole safety argument.
    $second = $this->get('/responsive-page')->assertOk()->assertHeader('X-Magna-Cache', 'hit');

    expect($second->getContent())->toBe($html);
});

it('leaves a page with no per-device value exactly as it was', function (): void {
    $author = responsiveUser();
    responsivePage($author, 'plain-responsive', ['paddingTop' => '32px']);

    $html = $this->get('/plain-responsive')->assertOk()->getContent();

    // No node class and no override rule — nothing of this feature reaches
    // a document that does not use it. (The page still carries the
    // visibility stylesheet, which predates this and shares a breakpoint.)
    expect($html)->toContain('padding-top:32px')
        ->and($html)->not->toContain('magna-n-')
        ->and($html)->not->toContain('!important;padding-top');
});

it('overrides a column as well as a section', function (): void {
    $author = responsiveUser();
    responsivePage($author, 'responsive-column', [], [
        'paddingLeft' => ['$responsive' => ['base' => '40px', 'mobile' => '8px']],
    ]);

    $html = $this->get('/responsive-column')->assertOk()->getContent();

    expect($html)->toContain('magna-n-col-resp')
        ->and($html)->toContain('padding-left:8px');
});

it('beats the style attribute it is overriding', function (): void {
    // A stylesheet rule loses to a style attribute at any specificity, so
    // an override that did not say !important would render as nothing.
    $css = ResponsiveStyles::rulesFor(
        'sec-1',
        ['paddingTop' => ['$responsive' => ['base' => '96px', 'mobile' => '24px']]],
        'section',
    );

    expect($css)->toContain('!important');
});

it('reads a scalar as the base value and nothing else', function (): void {
    expect(ResponsiveStyles::valueAt('20px', 'base'))->toBe('20px')
        ->and(ResponsiveStyles::valueAt('20px', 'mobile'))->toBeNull()
        ->and(ResponsiveStyles::isResponsive(['paddingTop' => '20px']))->toBeFalse();
});

it('treats a sentinel with only a base as not responsive', function (): void {
    // Nothing to override means nothing to emit — no class, no rule.
    $style = ['paddingTop' => ['$responsive' => ['base' => '20px']]];

    expect(ResponsiveStyles::isResponsive($style))->toBeFalse()
        ->and(ResponsiveStyles::rulesFor('sec-1', $style, 'section'))->toBe('');
});

it('refuses a value that would escape the stylesheet', function (): void {
    // The stylesheet is unescaped output, so the sanitizer standing behind
    // it is load-bearing: a value carrying markup must not survive.
    $css = ResponsiveStyles::rulesFor(
        'sec-1',
        ['paddingTop' => ['$responsive' => ['mobile' => '1px}</style><script>alert(1)</script>']]],
        'section',
    );

    expect($css)->not->toContain('<script>')
        ->and($css)->not->toContain('</style>');
});

it('keeps a node id from becoming a selector of its own', function (): void {
    // Everything outside [a-z0-9-] is stripped, so an id can never carry a
    // selector, a brace, or a closing tag into the stylesheet.
    expect(ResponsiveStyles::nodeClass('sec .evil{}'))->toBe('magna-n-secevil');
});
