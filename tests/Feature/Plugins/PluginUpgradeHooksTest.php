<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Magna\Plugins\PluginManager;
use Magna\Plugins\PluginRecord;
use Magna\Plugins\PluginUpgradeHooks;
use Magna\Updater\Events\CoreUpdated;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * When the core has just changed under a plugin, the plugin is told — its
 * own migrations run, its upgrade() hook runs, and CoreUpdated fires — and
 * one plugin's mistake stops none of the others hearing about it.
 */
function upgradeHookPlugin(string $suffix, string $upgradeBody): array
{
    $base = sys_get_temp_dir().'/magna-upgrade-hook-'.$suffix;
    $namespace = 'UpgradeHookProbe'.$suffix;
    mkdir($base.'/src', 0777, true);
    mkdir($base.'/database/migrations', 0777, true);

    file_put_contents($base.'/composer.json', (string) json_encode([
        'name' => 'acme/upgrade-hook-'.strtolower($suffix),
        'autoload' => ['psr-4' => [$namespace.'\\' => 'src/']],
    ]));

    file_put_contents($base.'/src/Plugin.php', <<<PHP
<?php

namespace {$namespace};

class Plugin extends \\Magna\\Plugins\\Plugin
{
    public static array \$calls = [];

    public function upgrade(string \$from, string \$to): void
    {
        {$upgradeBody}
    }
}
PHP);

    $table = 'upgrade_hook_'.strtolower($suffix);
    file_put_contents($base.'/database/migrations/2026_01_01_000000_create_'.$table.'_table.php', <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table): void {
            \$table->id();
        });
    }
};
PHP);

    $manifest = [
        'name' => 'acme/upgrade-hook-'.strtolower($suffix),
        'displayName' => 'Upgrade Hook '.$suffix,
        'description' => 'Probe.',
        'version' => '1.0.0',
        'author' => 'Acme',
        'license' => 'MIT',
        'compat' => ['magna' => '^1.0', 'php' => '^8.3'],
        'entry' => $namespace.'\\Plugin',
        'permissions' => [],
    ];
    file_put_contents($base.'/magna.json', (string) json_encode($manifest));

    PluginRecord::query()->create([
        'name' => $manifest['name'],
        'display_name' => $manifest['displayName'],
        'version' => '1.0.0',
        'enabled' => true,
        'base_path' => $base,
        'manifest' => $manifest,
    ]);

    return [$base, $namespace.'\\Plugin', $table];
}

it('runs each enabled plugin\'s migrations and upgrade hook, then announces the update', function (): void {
    Event::fake([CoreUpdated::class]);

    $suffix = 'A'.bin2hex(random_bytes(3));
    [$base, $class, $table] = upgradeHookPlugin($suffix, 'static::$calls[] = [$from, $to];');

    try {
        app(PluginManager::class)->bootEnabledPlugins();

        $report = app(PluginUpgradeHooks::class)->run('1.4.3', '1.4.4');

        expect($report['migrated'])->toContain('acme/upgrade-hook-'.strtolower($suffix))
            ->and($report['upgraded'])->toContain('acme/upgrade-hook-'.strtolower($suffix))
            ->and($report['failed'])->toBe([])
            ->and($class::$calls)->toBe([['1.4.3', '1.4.4']])
            ->and(Schema::hasTable($table))->toBeTrue();

        Event::assertDispatched(CoreUpdated::class, fn (CoreUpdated $event): bool => $event->from === '1.4.3' && $event->to === '1.4.4');
    } finally {
        (new Filesystem)->remove($base);
    }
});

it('reports a hook that throws and still tells the others', function (): void {
    Event::fake([CoreUpdated::class]);

    $bad = 'B'.bin2hex(random_bytes(3));
    $good = 'C'.bin2hex(random_bytes(3));
    [$badBase] = upgradeHookPlugin($bad, 'throw new \RuntimeException("this plugin cannot cope");');
    [$goodBase, $goodClass] = upgradeHookPlugin($good, 'static::$calls[] = $to;');

    try {
        app(PluginManager::class)->bootEnabledPlugins();

        $report = app(PluginUpgradeHooks::class)->run('1.4.3', '1.4.4');

        expect($report['failed'])->toHaveCount(1)
            ->and($report['failed'][0])->toContain('this plugin cannot cope')
            ->and($report['upgraded'])->toContain('acme/upgrade-hook-'.strtolower($good))
            ->and($goodClass::$calls)->toBe(['1.4.4']);

        Event::assertDispatched(CoreUpdated::class);
    } finally {
        (new Filesystem)->remove([$badBase, $goodBase]);
    }
});
