<?php

declare(strict_types=1);

/**
 * Skeleton acceptance for the magna/pages plugin (Phase A item 5): the plugin
 * enables cleanly, ships the `page` content type with a blocks field, and its
 * settings class round-trips through the core Settings infrastructure.
 */

use Magna\Auth\PermissionRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\SchemaRegistry;
use Magna\Pages\PagesSettings;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Settings\SettingsRepository;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

function enablePagesPlugin(): PluginManager
{
    skipWithoutDevPlugin('magna/pages');

    $manager = app(PluginManager::class);
    $manager->enable('magna/pages');

    return $manager;
}

it('enables and registers the page content type', function (): void {
    enablePagesPlugin();

    expect(PluginRecord::query()->where('name', 'magna/pages')->where('enabled', true)->exists())->toBeTrue();

    $type = app(SchemaRegistry::class)->get('page');

    expect($type)->not->toBeNull()
        ->and($type->localizable)->toBeTrue()
        ->and($type->draftable)->toBeTrue()
        ->and($type->getField('blocks_data')?->type->typeName())->toBe('blocks');
});

it('creates a draft page with a block document through the normal entry path', function (): void {
    enablePagesPlugin();
    $user = User::factory()->create();

    $entry = app(EntryManager::class)->create('page', [
        'title' => 'Home',
        'slug' => 'home',
        'blocks_data' => [[
            'id' => 'sec-1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-1', 'span' => 12, 'settings' => [],
                'blocks' => [['id' => 'blk-1', 'block' => 'heading', 'settings' => [], 'data' => ['text' => 'Welcome']]],
            ]],
        ]],
    ], $user->id);

    $read = Entry::type('page')->where($entry->getKeyName(), $entry->getKey())->firstOrFail();

    expect($read->getAttribute('title'))->toBe('Home')
        ->and($read->getAttribute('blocks_data')[0]['columns'][0]['blocks'][0]['data']['text'])->toBe('Welcome');
});

it('round-trips PagesSettings through the settings repository', function (): void {
    enablePagesPlugin();

    $settings = PagesSettings::get();

    expect($settings->home_page_id)->toBeNull()
        ->and($settings->maintenance_mode)->toBeFalse()
        ->and(PagesSettings::group())->toBe('pages');

    $settings->home_page_id = '01HXHOMEPAGEULID0000000000';
    $settings->maintenance_mode = true;
    app(SettingsRepository::class)->persist($settings);

    $reloaded = PagesSettings::get();

    expect($reloaded->home_page_id)->toBe('01HXHOMEPAGEULID0000000000')
        ->and($reloaded->maintenance_mode)->toBeTrue();
});

it('registers the pages permission family', function (): void {
    enablePagesPlugin();

    $registry = app(PermissionRegistry::class);

    foreach (['pages.content', 'pages.layout', 'pages.design', 'pages.publish', 'pages.settings'] as $permission) {
        expect($registry->has($permission))->toBeTrue("missing permission {$permission}");
    }
});
