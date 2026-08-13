<?php

declare(strict_types=1);

/**
 * The Loop block + RegistersDataSources (Phase C): plugin data onto pages.
 * A source returns presentation strings under conventional keys; the loop
 * renders them escaped; a vanished source renders a gap, never an error;
 * the resolver's clamp binds even a generous source.
 */

use Magna\Auth\Role;
use Magna\Blocks\DataSources\DataSource;
use Magna\Blocks\DataSources\DataSourceRegistry;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Pages\Blocks\DataSourceOptions;
use Magna\Plugins\PluginManager;
use Magna\Testing\PluginTestCase;
use Magna\Users\User;

uses(PluginTestCase::class);

final class FixtureJobsSource implements DataSource
{
    public function handle(): string
    {
        return 'fixture.jobs';
    }

    public function label(): string
    {
        return 'Open positions';
    }

    public function fetch(array $config): array
    {
        // Deliberately MORE than asked and with a markup payload: the
        // resolver must clamp, the view must escape.
        return array_map(fn (int $i): array => [
            'title' => "Engineer {$i} <script>alert(1)</script>",
            'url' => '/jobs/'.$i,
            'description' => 'Build things.',
            'date' => '2026-08-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        ], range(1, 20));
    }
}

function loopSetup(): User
{
    skipWithoutDevPlugin('magna/pages');
    app(PluginManager::class)->enable('magna/pages');
    app(DataSourceRegistry::class)->register(new FixtureJobsSource);

    $user = User::factory()->create();
    $role = Role::factory()->create();
    $role->grant('panel.access', 'pages.content', 'pages.layout', 'pages.publish');
    $user->assignRole($role);

    return $user;
}

function loopPage(User $author, string $slug, string $source, int $limit = 3): Entry
{
    $manager = app(EntryManager::class);

    $entry = $manager->create('page', [
        'title' => 'Loop page', 'slug' => $slug,
        'blocks_data' => [[
            'id' => 'sec-lp-'.$slug, 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'col-lp-'.$slug, 'span' => 12, 'settings' => [],
                'blocks' => [[
                    'id' => 'blk-lp-'.$slug, 'block' => 'loop', 'settings' => [],
                    'data' => ['heading' => 'Careers', 'source' => $source, 'limit' => $limit],
                ]],
            ]],
        ]],
    ], $author->id);

    return $manager->publish($entry, actorId: $author->id);
}

it('renders a plugin data source through the loop block, clamped and escaped', function (): void {
    $author = loopSetup();
    loopPage($author, 'careers', 'fixture.jobs', limit: 3);

    $html = $this->get('/careers')->assertOk()->getContent();

    // Clamped to the block's limit, not the source's generosity.
    expect(substr_count($html, 'Build things.'))->toBe(3)
        // Escaped: the markup arrives as text, never as a script element.
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('href="/jobs/1"')
        ->and($html)->toContain('Careers');
});

it('renders a gap when the source is unknown or its plugin vanished', function (): void {
    $author = loopSetup();
    loopPage($author, 'gone-source', 'vanished.plugin-source');

    // The page renders; the loop shows its heading and no items.
    $this->get('/gone-source')->assertOk()
        ->assertSee('Careers')
        ->assertDontSee('magna-loop__item');
});

it('lists registered sources in the picker options', function (): void {
    loopSetup();

    $options = app(DataSourceOptions::class)->options();

    expect($options)->toHaveKey('fixture.jobs')
        ->and($options['fixture.jobs'])->toBe('Open positions')
        // The plugin's own built-in source arrives through the same
        // RegistersDataSources wiring third-party plugins use.
        ->and($options)->toHaveKey('pages.latest')
        ->and($options['pages.latest'])->toBe('Latest pages');
});

it('loops over latest published pages via the built-in entries source', function (): void {
    $author = loopSetup();
    $manager = app(EntryManager::class);

    // Two published pages and one draft — the draft must never surface.
    foreach ([['About us', 'about'], ['Contact', 'contact']] as [$title, $slug]) {
        $entry = $manager->create('page', [
            'title' => $title, 'slug' => $slug, 'blocks_data' => [],
        ], $author->id);
        $manager->publish($entry, actorId: $author->id);
    }
    $manager->create('page', [
        'title' => 'Secret draft', 'slug' => 'secret-draft', 'blocks_data' => [],
    ], $author->id);

    loopPage($author, 'sitemap-ish', 'pages.latest', limit: 10);

    $html = $this->get('/sitemap-ish')->assertOk()->getContent();

    expect($html)->toContain('About us')
        ->and($html)->toContain('href="/about"')
        ->and($html)->toContain('Contact')
        ->and($html)->not->toContain('Secret draft');
});
