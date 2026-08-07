<?php

declare(strict_types=1);

/**
 * translation_group: stable locale-variant identity
 * (10-REVIEW-RESOLUTIONS §A2). The previous convention — locale variants =
 * rows of the same type sharing the same slug — silently severed linkage the
 * moment a slug was translated. Groups survive divergent slugs.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Content\TableGenerator;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function registerLocalizedPageType(string $handle = 'tg_page'): ContentType
{
    $schemaRegistry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'TG Page',
        'localizable' => true,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true, 'localizable' => true],
            ['handle' => 'slug', 'type' => 'slug', 'localizable' => true],
            ['handle' => 'accent_color', 'type' => 'text', 'localizable' => false],
        ],
    ], app(FieldTypeRegistry::class));

    $schemaRegistry->register($type);
    app(SchemaSyncer::class)->syncAll($schemaRegistry, allowDestructive: true);

    return $type;
}

it('roots a new entry in its own translation group', function (): void {
    registerLocalizedPageType();
    $user = User::factory()->create();

    $entry = app(EntryManager::class)->create('tg_page', [
        'title' => 'About us',
        'slug' => 'about-us',
        'locale' => 'en',
    ], $user->id);

    expect($entry->getAttribute('translation_group'))->toBe($entry->getKey());
});

it('shares the group across translations even when slugs diverge', function (): void {
    registerLocalizedPageType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $en = $manager->create('tg_page', [
        'title' => 'About us',
        'slug' => 'about-us',
        'locale' => 'en',
    ], $user->id);

    $de = $manager->createTranslation($en, 'de', $user->id);
    // Translate the slug — the exact operation that severed the old linkage.
    $manager->update($de, ['title' => 'Über uns', 'slug' => 'ueber-uns'], $user->id);

    $de->refresh();

    expect($de->getAttribute('translation_group'))->toBe($en->getAttribute('translation_group'))
        ->and($de->getAttribute('slug'))->toBe('ueber-uns');
});

it('syncs non-localizable fields across the group despite divergent slugs', function (): void {
    registerLocalizedPageType();
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $en = $manager->create('tg_page', [
        'title' => 'About us',
        'slug' => 'about-us',
        'locale' => 'en',
    ], $user->id);
    $de = $manager->createTranslation($en, 'de', $user->id);
    $manager->update($de, ['slug' => 'ueber-uns'], $user->id);

    // Non-localizable change on EN must reach DE even though slugs differ —
    // under the old same-slug identity this silently no-oped.
    $manager->update($en, ['accent_color' => '#ff0000'], $user->id);

    $de->refresh();

    expect($de->getAttribute('accent_color'))->toBe('#ff0000');
});

it('backfills existing tables preserving same-slug clusters', function (): void {
    $type = registerLocalizedPageType('tg_legacy');

    // Simulate a pre-column table: drop the index then the column, insert
    // legacy rows.
    Schema::table('magna_entries_tg_legacy', function ($table): void {
        $table->dropIndex(['translation_group', 'locale']);
    });
    Schema::table('magna_entries_tg_legacy', function ($table): void {
        $table->dropColumn('translation_group');
    });

    $mk = fn (string $id, string $locale, string $slug) => DB::table('magna_entries_tg_legacy')->insert([
        'id' => $id, 'status' => 'published', 'locale' => $locale,
        'title' => $slug, 'slug' => $slug, 'accent_color' => null,
        'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // One logical entry in two locales (shared slug), plus an unrelated entry.
    $mk('01AAAAAAAAAAAAAAAAAAAAAAAA', 'en', 'shared-slug');
    $mk('01BBBBBBBBBBBBBBBBBBBBBBBB', 'de', 'shared-slug');
    $mk('01CCCCCCCCCCCCCCCCCCCCCCCC', 'en', 'lonely-slug');

    app(TableGenerator::class)->addTranslationGroupColumn($type);

    $groups = DB::table('magna_entries_tg_legacy')->pluck('translation_group', 'id');

    expect($groups['01AAAAAAAAAAAAAAAAAAAAAAAA'])->toBe('01AAAAAAAAAAAAAAAAAAAAAAAA')
        ->and($groups['01BBBBBBBBBBBBBBBBBBBBBBBB'])->toBe('01AAAAAAAAAAAAAAAAAAAAAAAA') // joined the oldest row's group
        ->and($groups['01CCCCCCCCCCCCCCCCCCCCCCCC'])->toBe('01CCCCCCCCCCCCCCCCCCCCCCCC');
});

it('exposes the backfill through the artisan command', function (): void {
    registerLocalizedPageType('tg_cmd');

    $this->artisan('magna:content:add-translation-groups')
        ->expectsOutputToContain('magna_entries_tg_cmd')
        ->assertSuccessful();
});

it('new tables index (translation_group, locale)', function (): void {
    registerLocalizedPageType('tg_indexed');

    expect(Schema::hasColumn('magna_entries_tg_indexed', 'translation_group'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('magna_entries_tg_indexed'))
        ->map(fn (array $index): array => $index['columns']);

    expect($indexes->contains(fn (array $columns): bool => $columns === ['translation_group', 'locale']))->toBeTrue();
});

it('adopts a pre-backfill source into a group on first translation', function (): void {
    $type = registerLocalizedPageType('tg_adopt');
    $user = User::factory()->create();
    $manager = app(EntryManager::class);

    $en = $manager->create('tg_adopt', [
        'title' => 'Legacy', 'slug' => 'legacy', 'locale' => 'en',
    ], $user->id);

    // Simulate a row created before groups existed.
    DB::table('magna_entries_tg_adopt')->where('id', $en->getKey())->update(['translation_group' => null]);
    $en->refresh();

    $de = $manager->createTranslation($en, 'de', $user->id);
    $en->refresh();

    expect($en->getAttribute('translation_group'))->toBe($en->getKey())
        ->and($de->getAttribute('translation_group'))->toBe($en->getKey());
});
