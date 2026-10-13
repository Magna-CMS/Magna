<?php

declare(strict_types=1);

/**
 * Plugin-owned sections travel in the site kit.
 *
 * The kit used to be a closed list of what Pages itself owns, so a site whose
 * pages reference another plugin's entities applied cleanly and rendered a
 * hole — the Lekha contact page shipped with a README'd manual step, which is
 * the instruction a kit exists to make unnecessary. A plugin registers a
 * SiteKitSection under a namespaced key; the kit exports, diffs, and applies
 * it alongside its own keys, and a key no enabled plugin claims is skipped
 * and reported rather than fatal.
 */

use Magna\Contracts\SiteKitSection;
use Magna\Pages\SiteKit\SiteKit;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;

uses(PluginTestCase::class);

/**
 * An in-memory section: rows keyed by handle, writes recorded, so the tests
 * exercise the kit's side of the contract without dragging a real plugin in.
 */
function sectionsFake(string $key = 'acme:things', array $local = []): SiteKitSection
{
    return new class($key, $local) implements SiteKitSection
    {
        public array $applied = [];

        public function __construct(private readonly string $sectionKey, private array $local) {}

        public function key(): string
        {
            return $this->sectionKey;
        }

        public function export(): array
        {
            return array_values($this->local);
        }

        public function diff(array $rows): array
        {
            $handles = array_map(fn (array $r): string => (string) ($r['handle'] ?? ''), $rows);

            return [
                'added' => array_values(array_diff($handles, array_keys($this->local))),
                'changed' => [],
                'localOnly' => array_values(array_diff(array_keys($this->local), $handles)),
            ];
        }

        public function apply(array $rows, ?string $actorId = null): int
        {
            $this->applied = $rows;

            return count($rows);
        }
    };
}

function sectionsKitUser(SiteKitSection ...$sections): void
{
    skipWithoutDevPlugin('magna-cms/pages');
    app(PluginManager::class)->enable('magna-cms/pages');
    app()->instance('magna.site_kit.sections', array_values($sections));
}

function sectionsBundle(array $extra = []): array
{
    return [
        'format' => 'magna-site-kit',
        'version' => SiteKit::VERSION,
        'pages' => [],
        'templates' => [],
        'menus' => [],
        'patterns' => [],
        'settings' => [],
        ...$extra,
    ];
}

it('exports a registered section under its own key', function (): void {
    $section = sectionsFake(local: ['one' => ['handle' => 'one', 'name' => 'One']]);
    sectionsKitUser($section);

    $kit = app(SiteKit::class)->build();

    expect($kit['acme:things'])->toBe([['handle' => 'one', 'name' => 'One']]);
});

it('round-trips a section through export and apply', function (): void {
    $source = sectionsFake(local: ['one' => ['handle' => 'one', 'name' => 'One']]);
    sectionsKitUser($source);

    $bundle = app(SiteKit::class)->build();

    // A second environment: same registration, nothing local yet.
    $target = sectionsFake();
    app()->instance('magna.site_kit.sections', [$target]);
    app()->forgetInstance(SiteKit::class);

    $counts = app(SiteKit::class)->apply($bundle);

    expect($counts['acme:things'])->toBe(1)
        ->and($target->applied)->toBe([['handle' => 'one', 'name' => 'One']]);
});

it('diffs a registered section alongside the kit\'s own buckets', function (): void {
    sectionsKitUser(sectionsFake(local: ['gone' => ['handle' => 'gone']]));

    $diff = app(SiteKit::class)->diff(sectionsBundle([
        'acme:things' => [['handle' => 'new']],
    ]));

    expect($diff['acme:things'])->toBe(['added' => ['new'], 'changed' => [], 'localOnly' => ['gone']]);
});

it('skips a section key no plugin claims, and says so', function (): void {
    sectionsKitUser();

    $kit = app(SiteKit::class);
    $bundle = sectionsBundle(['vendor:unknown' => [['handle' => 'x']]]);

    // Applying must not throw — an older installation reads a newer kit.
    $counts = $kit->apply($bundle);

    expect($counts)->not->toHaveKey('vendor:unknown')
        ->and($kit->unknownKeys($bundle))->toBe(['vendor:unknown']);
});

it('does not call a registered section the kit carries no key for', function (): void {
    $section = sectionsFake();
    $section->applied = ['untouched'];
    sectionsKitUser($section);

    $counts = app(SiteKit::class)->apply(sectionsBundle());

    // Absent means "this kit predates the section", not "delete everything".
    expect($counts)->not->toHaveKey('acme:things')
        ->and($section->applied)->toBe(['untouched']);
});

it('rejects a section key that could shadow the kit\'s own', function (): void {
    sectionsKitUser(sectionsFake(key: 'patterns'));

    app(SiteKit::class)->build();
})->throws(LogicException::class, 'patterns');

it('rejects two sections claiming one key', function (): void {
    sectionsKitUser(sectionsFake(), sectionsFake());

    app(SiteKit::class)->build();
})->throws(LogicException::class, 'registered twice');
