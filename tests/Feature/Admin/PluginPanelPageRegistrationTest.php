<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Admin\PluginPanelSurface;
use Magna\Plugins\PluginRecord;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A plugin installed as dropped files is not in Composer's autoload maps.
// AdminPanelProvider registers the panel long before PluginManager boots
// plugins, so it used to ask class_exists() on an entry class nothing could
// load yet, skip the plugin, and register no routes for its settings pages —
// while the plugin's dashboard widget and nav item still rendered. The first
// link to one of those pages then threw
//
//     Route [filament.magna.pages.roya-license] not defined.
//
// on EVERY admin request, with no admin left to disable the plugin from.

function droppedFilePlugin(string $suffix): string
{
    $base = sys_get_temp_dir().'/magna-panel-plugin-'.$suffix;
    @mkdir($base.'/src/Filament/Pages', 0777, true);

    file_put_contents($base.'/composer.json', json_encode([
        'name' => 'acme/panel-'.strtolower($suffix),
        'autoload' => ['psr-4' => ['PanelProbe'.$suffix.'\\' => 'src/']],
    ]));

    // Nowdoc + str_replace: a heredoc would swallow the `$` of every property
    // declaration in the generated source.
    $page = <<<'PHP'
    <?php

    namespace PanelProbeSUFFIX\Filament\Pages;

    class ProbeSettingsPage extends \Filament\Pages\Page
    {
        protected static ?string $slug = 'probe-settings-suffix';

        protected string $view = 'magna::admin.dashboard';
    }
    PHP;

    $plugin = <<<'PHP'
    <?php

    namespace PanelProbeSUFFIX;

    class ProbePlugin extends \Magna\Plugins\Plugin implements \Magna\Contracts\RegistersSettingsPages
    {
        public function settingsPages(): array
        {
            return [\PanelProbeSUFFIX\Filament\Pages\ProbeSettingsPage::class];
        }
    }
    PHP;

    file_put_contents(
        $base.'/src/Filament/Pages/ProbeSettingsPage.php',
        str_replace(['SUFFIX', 'suffix'], [$suffix, strtolower($suffix)], $page),
    );

    file_put_contents($base.'/src/ProbePlugin.php', str_replace('SUFFIX', $suffix, $plugin));

    return $base;
}

/** A plugin that implements the contract and throws from inside it. */
function brokenPlugin(string $suffix): string
{
    $base = sys_get_temp_dir().'/magna-panel-plugin-'.$suffix;
    @mkdir($base.'/src', 0777, true);

    file_put_contents($base.'/composer.json', json_encode([
        'name' => 'acme/broken-'.strtolower($suffix),
        'autoload' => ['psr-4' => ['PanelProbe'.$suffix.'\\' => 'src/']],
    ]));

    $plugin = <<<'PHP'
    <?php

    namespace PanelProbeSUFFIX;

    class BoomPlugin extends \Magna\Plugins\Plugin implements \Magna\Contracts\RegistersSettingsPages
    {
        public function settingsPages(): array
        {
            throw new \RuntimeException('this plugin is broken');
        }
    }
    PHP;

    file_put_contents($base.'/src/BoomPlugin.php', str_replace('SUFFIX', $suffix, $plugin));

    return $base;
}

it('registers the settings pages of a plugin Composer cannot autoload', function (): void {
    $suffix = 'A'.bin2hex(random_bytes(3));
    $base = droppedFilePlugin($suffix);
    $entry = 'PanelProbe'.$suffix.'\\ProbePlugin';

    // Precondition: nothing can load this class yet — exactly a dropped-file
    // install as the panel sees it.
    expect(class_exists($entry, false))->toBeFalse();

    PluginRecord::query()->create([
        'name' => 'acme/panel-probe-'.strtolower($suffix),
        'display_name' => 'Panel Probe',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => $base,
        'manifest' => [
            'name' => 'acme/panel-probe-'.strtolower($suffix),
            'displayName' => 'Panel Probe',
            'description' => 'Probe.',
            'version' => '1.0.0',
            'author' => 'Acme',
            'license' => 'proprietary',
            'entry' => $entry,
            'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
            'permissions' => [],
            'provides' => [],
        ],
    ]);

    $pages = (new ReflectionClass(PluginPanelSurface::class))
        ->getMethod('pages');
    $pages->setAccessible(true);

    $resolved = $pages->invoke(new PluginPanelSurface(app()));

    // The regression itself: the panel made the plugin loadable before
    // deciding whether it exists…
    expect(class_exists($entry, false))->toBeTrue()
        // …so its settings page is registered, and therefore gets a route.
        ->and($resolved)->toContain('PanelProbe'.$suffix.'\\Filament\\Pages\\ProbeSettingsPage');
});

// A single try/catch around the whole loop meant the first plugin that threw
// took every plugin after it down with it — silently. Their settings pages
// then got no routes, and their nav entries and widgets went on rendering
// links to routes that do not exist: a 500 on every admin request, caused by
// a DIFFERENT plugin than the broken one.
it('registers a healthy plugin even when another plugin is broken', function (): void {
    $suffix = 'B'.bin2hex(random_bytes(3));
    $healthy = droppedFilePlugin($suffix);

    $manifest = static fn (string $name, string $entry, string $base): array => [
        'name' => $name,
        'display_name' => 'Probe',
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => $base,
        'manifest' => [
            'name' => $name,
            'displayName' => 'Probe',
            'description' => 'Probe.',
            'version' => '1.0.0',
            'author' => 'Acme',
            'license' => 'proprietary',
            'entry' => $entry,
            'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
            'permissions' => [],
            'provides' => [],
        ],
    ];

    // Inserted first, so it is reached first and its failure would swallow
    // everything after it. It implements the contract and throws from inside
    // it — the case a contract check cannot filter out.
    PluginRecord::query()->create($manifest(
        'acme/broken-'.strtolower($suffix),
        'PanelProbe'.$suffix.'Broken\\BoomPlugin',
        brokenPlugin($suffix.'Broken'),
    ));

    PluginRecord::query()->create($manifest(
        'acme/panel-probe-'.strtolower($suffix),
        'PanelProbe'.$suffix.'\\ProbePlugin',
        $healthy,
    ));

    $pages = (new ReflectionClass(PluginPanelSurface::class))->getMethod('pages');
    $pages->setAccessible(true);

    expect($pages->invoke(new PluginPanelSurface(app())))
        ->toContain('PanelProbe'.$suffix.'\\Filament\\Pages\\ProbeSettingsPage');
});
