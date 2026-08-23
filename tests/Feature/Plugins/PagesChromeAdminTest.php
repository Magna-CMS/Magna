<?php

declare(strict_types=1);

/**
 * Making a header, from the screen an editor is actually on.
 *
 * The resolution chain was built and tested before this existed, and it
 * was unreachable: creating a part set no role, and a part with no role is
 * invisible to the chrome pickers. A header nobody can choose is a header
 * nobody made, so the on-ramp gets its own tests.
 */

use Livewire\Livewire;
use Magna\Auth\Role;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Filament\Pages\PagesIndexPage;
use Magna\Pages\Templates\TemplatePartResolver;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function chromeAdmin(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.design', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

it('creates a part that says what it is for', function (): void {
    $user = chromeAdmin();

    Livewire::actingAs($user)
        ->test(PagesIndexPage::class)
        ->set('newPartTitle', 'Brand header')
        ->set('newPartRole', 'header')
        ->call('createPart', app(EntryManager::class));

    $entry = Entry::type('pages_template')->where('title', 'Brand header')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->getAttribute('kind'))->toBe('part')
        ->and($entry->getAttribute('role'))->toBe('header');
});

it('defaults to a generic part, which is what a part has always been', function (): void {
    $user = chromeAdmin();

    Livewire::actingAs($user)
        ->test(PagesIndexPage::class)
        ->set('newPartTitle', 'Sidebar')
        ->call('createPart', app(EntryManager::class));

    $entry = Entry::type('pages_template')->where('title', 'Sidebar')->first();

    expect($entry?->getAttribute('role'))->toBe('generic')
        // And a generic part is offered to neither picker.
        ->and(app(TemplatePartResolver::class)->chromeChoices('header'))->toBe([]);
});

it('refuses a role it has never heard of', function (): void {
    $user = chromeAdmin();

    Livewire::actingAs($user)
        ->test(PagesIndexPage::class)
        ->set('newPartTitle', 'Odd one')
        ->set('newPartRole', 'sidebar-widget-thing')
        ->call('createPart', app(EntryManager::class));

    // The vocabulary is fixed and small, so anything else is generic
    // rather than a value the resolver would later have to reason about.
    expect(Entry::type('pages_template')->where('title', 'Odd one')->first()?->getAttribute('role'))
        ->toBe('generic');
});

it('toggles sticky on chrome, and only on chrome', function (): void {
    $user = chromeAdmin();
    $manager = app(EntryManager::class);

    $header = $manager->create('pages_template', [
        'title' => 'Top bar', 'slug' => 'top-bar', 'kind' => 'part',
        'role' => 'header', 'blocks_data' => [],
    ], $user->id);

    $generic = $manager->create('pages_template', [
        'title' => 'Aside', 'slug' => 'aside', 'kind' => 'part',
        'role' => 'generic', 'blocks_data' => [],
    ], $user->id);

    $page = Livewire::actingAs($user)->test(PagesIndexPage::class);

    $page->call('toggleSticky', (string) $header->getKey());
    expect(Entry::type('pages_template')->find($header->getKey())?->getAttribute('behaviour'))
        ->toBe(['sticky' => true]);

    // Off again: a toggle that only turns on is a switch with one position.
    $page->call('toggleSticky', (string) $header->getKey());
    expect(Entry::type('pages_template')->find($header->getKey())?->getAttribute('behaviour'))
        ->toBe(['sticky' => false]);

    // Sticky is chrome behaviour. A generic part has no slot to stick to.
    $page->call('toggleSticky', (string) $generic->getKey());
    expect(Entry::type('pages_template')->find($generic->getKey())?->getAttribute('behaviour'))
        ->toBeNull();
});

it('lists a part with the role and stickiness it has', function (): void {
    $user = chromeAdmin();

    app(EntryManager::class)->create('pages_template', [
        'title' => 'Sticky bar', 'slug' => 'sticky-bar', 'kind' => 'part',
        'role' => 'header', 'behaviour' => ['sticky' => true], 'blocks_data' => [],
    ], $user->id);

    Livewire::actingAs($user)
        ->test(PagesIndexPage::class)
        ->assertSee('Sticky bar')
        ->assertSee('header')
        ->assertSee('Sticky');
});
