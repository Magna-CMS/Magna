<?php

declare(strict_types=1);

/**
 * The entry form's notice names Pages' route and types as strings, because
 * core may not depend on the plugin. This is what holds the two together: if
 * the builder's route is renamed, the link would otherwise just stop showing.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Magna\Admin\Resources\Entry\EditEntry;
use Magna\Auth\Role;
use Magna\Content\EntryManager;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

beforeEach(function (): void {
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    $role = Role::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $admin->assignRole($role);
    test()->actingAs($admin);
});

it('links the entry form to the real builder for both document types', function (string $type, array $fields): void {
    $entry = app(EntryManager::class)->create($type, ['title' => 'Home', ...$fields]);

    Livewire::withQueryParams(['type' => $type])
        ->test(EditEntry::class, ['record' => $entry->id])
        ->assertSee('Open page builder')
        ->assertSeeHtml(route('pages.builder.edit', ['id' => $entry->id]));
})->with([
    'a page' => ['page', ['slug' => 'home']],
    'a template' => ['pages_template', ['slug' => 'main-header', 'kind' => 'part']],
]);
