<?php

declare(strict_types=1);

/**
 * Styling a block.
 *
 * A block renders its own markup, so its styles reach it through a class
 * MERGED into whatever element the block already produced, plus a rule in
 * the page stylesheet. The merge is the whole point: a second `class` (or
 * `style`) attribute would be resolved by the parser in our favour and
 * against the block's own, so a block that styles itself would lose its
 * styling the moment an editor set a padding on it.
 */

use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Pages\Render\BlockStyleMarkup;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function blockStyleUser(): User
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
function blockStylePage(User $author, string $slug, array $style): void
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Block styles', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-bs', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-bs', 'span' => 12, 'settings' => [],
                'blocks' => [[
                    'id' => 'blk-bs', 'block' => 'heading',
                    'settings' => $style === [] ? [] : ['style' => $style],
                    'data' => ['text' => 'Styled block'],
                ]],
            ]],
        ]],
    ], $author->id);
    $manager->publish($entry, actorId: $author->id);
}

describe('merging the class', function (): void {
    it('extends a class attribute the block already had', function (): void {
        expect(BlockStyleMarkup::withClass('<div class="magna-heading">Hi</div>', 'magna-n-x'))
            ->toBe('<div class="magna-heading magna-n-x">Hi</div>');
    });

    it('handles single quotes and extra attributes', function (): void {
        expect(BlockStyleMarkup::withClass("<a href='/x' class='btn' id='y'>Hi</a>", 'magna-n-x'))
            ->toBe("<a href='/x' class='btn magna-n-x' id='y'>Hi</a>");
    });

    it('adds one when the element has none', function (): void {
        expect(BlockStyleMarkup::withClass('<p>Hi</p>', 'magna-n-x'))
            ->toBe('<p class="magna-n-x">Hi</p>');
        expect(BlockStyleMarkup::withClass('<img src="a.png"/>', 'magna-n-x'))
            ->toBe('<img class="magna-n-x" src="a.png"/>');
    });

    it('is not fooled by the word class inside another attribute', function (): void {
        // `data-x="class=y"` is not a class attribute, and treating it as
        // one would corrupt the block's data.
        $html = '<div data-x="class=y">Hi</div>';

        expect(BlockStyleMarkup::withClass($html, 'magna-n-x'))
            ->toBe('<div class="magna-n-x" data-x="class=y">Hi</div>');
    });

    it('skips a leading comment to find the real element', function (): void {
        expect(BlockStyleMarkup::withClass('<!-- note --><p>Hi</p>', 'magna-n-x'))
            ->toBe('<!-- note --><p class="magna-n-x">Hi</p>');
    });

    it('leaves a fragment with no element alone', function (): void {
        // Wrapping it would change the DOM shape the canvas promises to
        // keep identical to the published page.
        expect(BlockStyleMarkup::withClass('just text', 'magna-n-x'))->toBe('just text');
        expect(BlockStyleMarkup::withClass('<p>Hi</p>', ''))->toBe('<p>Hi</p>');
    });
});

it('renders a styled block through the stylesheet', function (): void {
    $author = blockStyleUser();
    blockStylePage($author, 'styled-block', [
        'fontSize' => '42px',
        'color' => 'var(--color-primary)',
        'paddingTop' => ['$responsive' => ['base' => '40px', 'mobile' => '12px']],
    ]);

    $html = $this->get('/styled-block')->assertOk()->getContent();

    expect($html)->toContain('magna-n-blk-bs')
        ->and($html)->toContain('font-size:42px')
        ->and($html)->toContain('color:var(--color-primary)')
        ->and($html)->toContain('padding-top:40px')
        ->and($html)->toContain('@media (max-width: 767.98px)')
        ->and($html)->toContain('padding-top:12px !important');

    // The block keeps the class its own view rendered.
    expect($html)->toContain('magna-heading');
});

it('leaves an unstyled block exactly as its view rendered it', function (): void {
    $author = blockStyleUser();
    blockStylePage($author, 'plain-block', []);

    $html = $this->get('/plain-block')->assertOk()->getContent();

    expect($html)->toContain('Styled block')
        ->and($html)->not->toContain('magna-n-blk-bs');
});

it('stays cacheable', function (): void {
    $author = blockStyleUser();
    blockStylePage($author, 'cached-block', ['fontSize' => '30px']);

    $first = $this->get('/cached-block')->assertOk()->assertHeader('X-Magna-Cache', 'miss')->getContent();
    $second = $this->get('/cached-block')->assertOk()->assertHeader('X-Magna-Cache', 'hit')->getContent();

    expect($second)->toBe($first);
});

it('offers block controls to the builder', function (): void {
    $author = blockStyleUser();
    $manager = app(EntryManager::class);
    $entry = $manager->create('page', ['title' => 'Controls', 'slug' => 'block-controls', 'blocks_data' => []], $author->id);

    $payload = $this->actingAs($author)
        ->getJson(url('/pages-builder/'.$entry->getKey()))
        ->assertOk()
        ->json();

    $keys = array_column($payload['styleControls']['block'], 'key');

    expect($keys)->toContain('fontSize')
        ->and($keys)->toContain('paddingTop')
        // Row layout belongs to a row, not to the thing sitting in it.
        ->and($keys)->not->toContain('justifyContent');
});
