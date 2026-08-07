<?php

declare(strict_types=1);

/**
 * The themed live-preview endpoint behind core's ProvidesDocumentPreview
 * contract: renders unsaved editor state through the exact production
 * pipeline (theme, tokens, resolve step), gated like the core preview.
 */

use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Blocks\Contracts\ProvidesDocumentPreview;
use Magna\Blocks\Livewire\BlockEditor;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Themes\ThemeManager;
use Magna\Users\User;

uses(PluginTestCase::class);

function livePreviewUser(array $permissions = ['panel.access', 'blocks.preview']): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $role = Role::factory()->create();
    $role->grant(...$permissions);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('binds the core preview contract when the plugin is enabled', function (): void {
    livePreviewUser();

    expect(app()->bound(ProvidesDocumentPreview::class))->toBeTrue()
        ->and(app(ProvidesDocumentPreview::class)->previewUrl())->toContain('pages-preview');
});

it('renders posted unsaved state through the active theme', function (): void {
    $user = livePreviewUser();
    app(ThemeManager::class)->activate('magna/launch');

    $blocksData = json_encode([[
        'id' => 'sec-1', 'type' => 'section', 'settings' => [],
        'columns' => [[
            'id' => 'col-1', 'span' => 12, 'settings' => [],
            'blocks' => [['id' => 'blk-1', 'block' => 'hero', 'settings' => [], 'data' => [
                'layout' => 'centered', 'headline' => 'Unsaved headline',
            ]]],
        ]],
    ]]);

    $response = $this->actingAs($user)
        ->post('/pages-preview', ['blocks_data' => $blocksData, 'title' => 'Draft']);

    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('l-header')          // themed shell, not debug chrome
        ->and($html)->toContain('b-hero')         // theme block view
        ->and($html)->toContain('Unsaved headline');
});

it('rejects invalid documents and unauthorized users', function (): void {
    $user = livePreviewUser();

    // Structural failure: unregistered handle.
    $this->actingAs($user)
        ->post('/pages-preview', ['blocks_data' => json_encode([[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'col-1', 'span' => 12, 'settings' => [], 'blocks' => [
                ['id' => 'b1', 'block' => 'no_such_block', 'settings' => [], 'data' => []],
            ]]],
        ]])])
        ->assertStatus(422);

    // Missing blocks.preview permission.
    $nobody = livePreviewUser(['panel.access']);
    $this->actingAs($nobody)
        ->post('/pages-preview', ['blocks_data' => '[]'])
        ->assertForbidden();
});

it('shows the preview pane in the editor only when the contract is bound', function (): void {
    $user = livePreviewUser();

    Livewire::actingAs($user)
        ->test(BlockEditor::class, ['blocksData' => '[]'])
        ->assertSee('data-preview-url', false)
        ->assertSee('Preview');
});
