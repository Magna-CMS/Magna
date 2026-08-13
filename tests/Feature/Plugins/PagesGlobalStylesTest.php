<?php

declare(strict_types=1);

/**
 * Site token overrides (Design tab, 03-BUILDER §4): only variables the
 * active theme declares, values through the injection filter, every save
 * revisioned, and the published page actually restyles.
 */

use Illuminate\Support\Facades\DB;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function stylesUser(string ...$permissions): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    // The Launch theme ships tokens; activate it so overrides have targets.
    app(ThemeManager::class)->activate('magna/launch');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant(...($permissions === [] ? ['pages.content', 'pages.design'] : $permissions));
    $user->assignRole($role);

    return $user;
}

it('lists theme variables and accepts a valid override', function (): void {
    $user = stylesUser();

    $show = $this->actingAs($user)->getJson(url('/pages-builder/styles'))->assertOk()->json();
    expect($show['theme'])->toHaveKey('--color-primary');

    $this->actingAs($user)->putJson(url('/pages-builder/styles'), [
        'tokens' => ['--color-primary' => '#ff0044'],
    ])->assertOk()->assertJsonPath('effective.--color-primary', '#ff0044');

    // Saved: a fresh read agrees, and a revision row exists.
    $this->actingAs($user)->getJson(url('/pages-builder/styles'))
        ->assertJsonPath('overrides.--color-primary', '#ff0044');
    expect(DB::table('pages_style_revisions')->count())->toBe(1);
});

it('refuses unknown variables and expression values', function (): void {
    $user = stylesUser();

    $this->actingAs($user)->putJson(url('/pages-builder/styles'), [
        'tokens' => ['--not-a-theme-token' => '#fff'],
    ])->assertStatus(422);

    $this->actingAs($user)->putJson(url('/pages-builder/styles'), [
        'tokens' => ['--color-primary' => 'url(javascript:alert(1))'],
    ])->assertStatus(422);
});

it('needs the design permission to write but not to read', function (): void {
    $reader = stylesUser('pages.content');

    $this->actingAs($reader)->getJson(url('/pages-builder/styles'))->assertOk();
    $this->actingAs($reader)->putJson(url('/pages-builder/styles'), [
        'tokens' => ['--color-primary' => '#123456'],
    ])->assertForbidden();
});

it('restyles the published site', function (): void {
    $user = stylesUser();

    $manager = app(EntryManager::class);
    $entry = $manager->create('page', [
        'title' => 'Styled', 'slug' => 'styled',
        'blocks_data' => [[
            'id' => 'sec-s', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-s', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-s', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Styled']]],
            ]],
        ]],
    ], $user->id);
    $manager->publish($entry, actorId: $user->id);

    $this->actingAs($user)->putJson(url('/pages-builder/styles'), [
        'tokens' => ['--color-primary' => '#ff0044'],
    ])->assertOk();

    expect($this->get('/styled')->assertOk()->getContent())
        ->toContain('--color-primary:#ff0044');
});
