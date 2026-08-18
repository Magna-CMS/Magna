<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Plugins\PluginRecord;
use Magna\Plugins\PluginSettingsPurger;
use Magna\Settings\Setting;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function purgerRecord(array $settingsGroups): PluginRecord
{
    return new PluginRecord([
        'name' => 'acme/widget',
        'manifest' => ['uninstall' => ['settingsGroups' => $settingsGroups]],
    ]);
}

it('deletes the settings rows for the groups a plugin declares', function () {
    Setting::query()->create(['group' => 'acme_widget', 'key' => 'token', 'value' => 'secret']);
    Setting::query()->create(['group' => 'acme_widget', 'key' => 'mode', 'value' => 'on']);

    (new PluginSettingsPurger)->purge(purgerRecord(['acme_widget']));

    expect(Setting::query()->where('group', 'acme_widget')->count())->toBe(0);
});

it('refuses to purge a core settings group even if the manifest names one', function () {
    Setting::query()->create(['group' => 'security', 'key' => 'force_https', 'value' => '1']);

    // A malformed or hostile manifest must not be able to wipe core config.
    (new PluginSettingsPurger)->purge(purgerRecord(['security']));

    expect(Setting::query()->where('group', 'security')->count())->toBe(1);
});

it('does nothing when the manifest declares no settings groups', function () {
    Setting::query()->create(['group' => 'acme_widget', 'key' => 'token', 'value' => 'secret']);

    (new PluginSettingsPurger)->purge(new PluginRecord([
        'name' => 'acme/widget',
        'manifest' => ['uninstall' => ['tables' => ['acme_widgets']]],
    ]));

    expect(Setting::query()->where('group', 'acme_widget')->count())->toBe(1);
});
