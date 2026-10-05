<?php

declare(strict_types=1);

/**
 * A rendered page carries settings that were true when it was cached.
 *
 * The site name is the case that forced this seam: Magna Pages renders it into
 * every page title and caches the result for an hour
 * (PageCache::DEFAULT_TTL_SECONDS), so renaming the site from core's settings
 * screen left the old name on the public site long after the admin had moved
 * on — and core cannot flush that cache, because it belongs to a plugin core
 * must not know about. Core now says WHICH group changed; the plugin decides
 * whether that matters to anything it cached.
 */

use Illuminate\Support\Facades\Event;
use Magna\Pages\Cache\PageCache;
use Magna\Plugins\PluginManager;
use Magna\Settings\ContentSettings;
use Magna\Settings\Events\SettingsSaved;
use Magna\Settings\GeneralSettings;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

function cacheSitePages(): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');

    app(PageCache::class)->put('/', '<title>Home · Magna CMS</title>', ['page:1']);
}

it('announces which settings group was written', function (): void {
    Event::fake([SettingsSaved::class]);

    $general = GeneralSettings::get();
    $general->site_name = 'Lekha';
    $general->save();

    Event::assertDispatched(
        SettingsSaved::class,
        fn (SettingsSaved $e): bool => $e->group === 'general',
    );
});

it('drops the rendered site when a general setting changes', function (): void {
    cacheSitePages();

    expect(app(PageCache::class)->get('/'))->not->toBeNull();

    $general = GeneralSettings::get();
    $general->site_name = 'Lekha';
    $general->save();

    // Not "the cache is stale but will expire": a visitor arriving one second
    // after the rename must be told the new name.
    expect(app(PageCache::class)->get('/'))->toBeNull();
});

it('leaves the rendered site alone for a group it does not render', function (): void {
    cacheSitePages();

    $content = ContentSettings::get();
    $content->revision_limit = 25;
    $content->save();

    // Flushing on every settings write would make the whole site cold because
    // somebody changed a revision limit. The listener reads the group for
    // exactly this reason.
    expect(app(PageCache::class)->get('/'))->not->toBeNull();
});
