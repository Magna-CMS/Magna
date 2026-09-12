<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\ContentTypeRecord;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Settings\GeneralSettings;
use Magna\Settings\LocalizationSettings;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ────────────────────────────────────────────────────────────────────

function registerLocalizableType(string $handle = 'article'): ContentType
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => ucfirst($handle),
        'localizable' => true,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
            ['handle' => 'sku', 'type' => 'text', 'localizable' => false],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    return $type;
}

function createLocaleEntry(string $handle, string $locale, string $title = 'Hello'): Entry
{
    $entry = Entry::makeInstance($handle, app(SchemaRegistry::class));
    $entry->title = $title;
    $entry->slug = str_replace(' ', '-', strtolower($title)).'-'.$locale;
    $entry->locale = $locale;
    $entry->status = EntryStatus::Published;
    $entry->published_at = now();
    $entry->save();

    return $entry;
}

// ── Field-level localizable flag ───────────────────────────────────────────────

it('parses localizable: false on a field', function (): void {
    $type = ContentType::fromArray([
        'handle' => 'product',
        'displayName' => 'Product',
        'localizable' => true,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text'],
            ['handle' => 'sku', 'type' => 'text', 'localizable' => false],
        ],
    ], app(FieldTypeRegistry::class));

    $titleField = $type->getField('title');
    $skuField = $type->getField('sku');

    expect($titleField?->localizable)->toBeTrue()
        ->and($skuField?->localizable)->toBeFalse();
});

it('returns non-localizable fields via nonLocalizableFields()', function (): void {
    $type = ContentType::fromArray([
        'handle' => 'product',
        'displayName' => 'Product',
        'localizable' => true,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text'],
            ['handle' => 'sku', 'type' => 'text', 'localizable' => false],
            ['handle' => 'price', 'type' => 'number', 'localizable' => false],
        ],
    ], app(FieldTypeRegistry::class));

    $handles = array_map(fn ($f) => $f->handle, $type->nonLocalizableFields());
    expect($handles)->toContain('sku')->toContain('price')->not->toContain('title');
});

// ── unpublish_at column ────────────────────────────────────────────────────────

it('creates entry tables with unpublish_at column', function (): void {
    registerLocalizableType('page');
    expect(Schema::hasColumn('magna_entries_page', 'unpublish_at'))->toBeTrue();
});

it('scheduleUnpublish() sets unpublish_at on the entry', function (): void {
    registerLocalizableType('page');
    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    $entry = createLocaleEntry('page', 'en', 'My Page');
    $at = now()->addDays(7);
    $manager->scheduleUnpublish($entry, $at);

    $entry->refresh();
    expect($entry->unpublish_at)->not->toBeNull()
        ->and($entry->unpublish_at?->timestamp)->toBe($at->timestamp);
});

it('magna:publish:scheduled auto-unpublishes entries past unpublish_at', function (): void {
    registerLocalizableType('page');
    $entry = createLocaleEntry('page', 'en', 'Expiring Page');
    $entry->unpublish_at = now()->subMinute();
    $entry->save();

    expect($entry->status)->toBe(EntryStatus::Published);

    Artisan::call('magna:publish:scheduled');

    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Archived);
});

it('does not auto-unpublish entries with future unpublish_at', function (): void {
    registerLocalizableType('page');
    $entry = createLocaleEntry('page', 'en', 'Future Page');
    $entry->unpublish_at = now()->addDay();
    $entry->save();

    Artisan::call('magna:publish:scheduled');

    $entry->refresh();
    expect($entry->status)->toBe(EntryStatus::Published);
});

// ── createTranslation() ────────────────────────────────────────────────────────

/** A translation may only target a locale the site has enabled. */
function enableLocalesForTranslation(string ...$locales): void
{
    $settings = LocalizationSettings::get();
    $settings->available_locales = $locales;
    $settings->save();
}

it('createTranslation() creates a new locale row as draft', function (): void {
    registerLocalizableType();
    enableLocalesForTranslation('en', 'fr');
    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    $en = createLocaleEntry('article', 'en', 'Hello World');
    $fr = $manager->createTranslation($en, 'fr');

    expect($fr->locale)->toBe('fr')
        ->and($fr->status)->toBe(EntryStatus::Draft)
        ->and($fr->title)->toBe('Hello World');
});

it('createTranslation() deep-copies blocks_data from source', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'page',
        'displayName' => 'Page',
        'localizable' => true,
        'draftable' => false,
        'fields' => [
            ['handle' => 'title', 'type' => 'text'],
            ['handle' => 'blocks_data', 'type' => 'json'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    enableLocalesForTranslation('en', 'fr');

    $blocksJson = json_encode([['type' => 'section', 'id' => 'aaa', 'columns' => []]]);

    $en = Entry::makeInstance('page', $registry);
    $en->title = 'Home';
    $en->blocks_data = $blocksJson;
    $en->locale = 'en';
    $en->status = EntryStatus::Published;
    $en->published_at = now();
    $en->save();

    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);
    $fr = $manager->createTranslation($en, 'fr');

    expect($fr->blocks_data)->toBe($blocksJson);
});

it('refuses a locale the site has not enabled, on create and on translate', function (): void {
    // locale reaches create() from raw request input and used to be stored
    // verbatim — any string became a permanent row discriminator.
    registerLocalizableType();
    enableLocalesForTranslation('en', 'fr');
    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    expect(fn () => $manager->create('article', ['title' => 'Nope', 'locale' => 'xx']))
        ->toThrow(ValidationException::class, "Locale 'xx' is not enabled");

    $en = createLocaleEntry('article', 'en', 'Hello');
    expect(fn () => $manager->createTranslation($en, 'zz'))
        ->toThrow(ValidationException::class, "Locale 'zz' is not enabled");
});

it('createTranslation() throws on non-localizable type', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'tag',
        'displayName' => 'Tag',
        'localizable' => false,
        'draftable' => false,
        'fields' => [['handle' => 'name', 'type' => 'text']],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    $entry = Entry::makeInstance('tag', $registry);
    $entry->name = 'PHP';
    $entry->locale = 'en';
    $entry->status = EntryStatus::Published;
    $entry->published_at = now();
    $entry->save();

    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    expect(fn () => $manager->createTranslation($entry, 'fr'))
        ->toThrow(SchemaException::class);
});

// ── Non-localizable field sync ─────────────────────────────────────────────────

it('updating a non-localizable field syncs to all locale rows via slug identity', function (): void {
    registerLocalizableType('product');
    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    // Create en entry
    $en = Entry::makeInstance('product', app(SchemaRegistry::class));
    $en->title = 'Widget';
    $en->slug = 'widget';
    $en->sku = 'WIDGET-001';
    $en->locale = 'en';
    $en->status = EntryStatus::Published;
    $en->published_at = now();
    $en->save();

    // Create fr entry with same slug
    $fr = Entry::makeInstance('product', app(SchemaRegistry::class));
    $fr->title = 'Gadget FR';
    $fr->slug = 'widget';
    $fr->sku = 'WIDGET-001';
    $fr->locale = 'fr';
    $fr->status = EntryStatus::Published;
    $fr->published_at = now();
    $fr->save();

    // Update sku on the en entry — should sync to fr
    $manager->update($en, ['sku' => 'WIDGET-999']);

    $fr->refresh();
    expect($fr->sku)->toBe('WIDGET-999');
});

it('updating a localizable field does NOT sync to other locales', function (): void {
    registerLocalizableType('product');
    /** @var EntryManager $manager */
    $manager = app(EntryManager::class);

    $en = Entry::makeInstance('product', app(SchemaRegistry::class));
    $en->title = 'Widget EN';
    $en->slug = 'widget';
    $en->locale = 'en';
    $en->status = EntryStatus::Published;
    $en->published_at = now();
    $en->save();

    $fr = Entry::makeInstance('product', app(SchemaRegistry::class));
    $fr->title = 'Widget FR';
    $fr->slug = 'widget';
    $fr->locale = 'fr';
    $fr->status = EntryStatus::Published;
    $fr->published_at = now();
    $fr->save();

    $manager->update($en, ['title' => 'Widget EN Updated']);

    $fr->refresh();
    expect($fr->title)->toBe('Widget FR');
});

// ── Delivery API ?locale= + fallback chain ─────────────────────────────────────

it('delivery API returns the requested locale when it exists', function (): void {
    registerLocalizableType();

    $settings = GeneralSettings::get();
    $settings->default_locale = 'en';
    $settings->save();

    $locSettings = LocalizationSettings::get();
    $locSettings->available_locales = ['en', 'fr'];
    $locSettings->fallback_locale = 'en';
    $locSettings->save();

    createLocaleEntry('article', 'en', 'Hello');
    createLocaleEntry('article', 'fr', 'Bonjour');

    $token = User::factory()->create()->createToken('localization-test', ['delivery'], now()->addDay());

    $response = $this->withToken($token->plainTextToken)->getJson('/api/v1/content/article?locale=fr');
    $response->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['locale'])->toBe('fr');
});

it('delivery API falls back to the default locale when the requested locale has no entries', function (): void {
    registerLocalizableType();

    $settings = GeneralSettings::get();
    $settings->default_locale = 'en';
    $settings->save();

    $locSettings = LocalizationSettings::get();
    $locSettings->available_locales = ['en', 'de'];
    $locSettings->fallback_locale = 'en';
    $locSettings->save();

    createLocaleEntry('article', 'en', 'Hello');

    $token = User::factory()->create()->createToken('localization-test', ['delivery'], now()->addDay());

    // Request 'de' but no 'de' entries exist → should fall back to 'en'
    $response = $this->withToken($token->plainTextToken)->getJson('/api/v1/content/article?locale=de');
    $response->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['locale'])->toBe('en');
});

it('locale surrogate key is included in delivery response for localizable types', function (): void {
    registerLocalizableType();

    $settings = GeneralSettings::get();
    $settings->default_locale = 'en';
    $settings->save();

    $locSettings = LocalizationSettings::get();
    $locSettings->available_locales = ['en'];
    $locSettings->fallback_locale = 'en';
    $locSettings->save();

    createLocaleEntry('article', 'en', 'Hello');

    $token = User::factory()->create()->createToken('localization-test', ['delivery'], now()->addDay());
    $response = $this->withToken($token->plainTextToken)->getJson('/api/v1/content/article?locale=en');
    $response->assertOk();

    $surrogateKey = $response->headers->get('Surrogate-Key', '');
    expect($surrogateKey)->toContain('locale:en');
});

// ── magna:schema:export ─────────────────────────────────────────────────────────

it('magna:schema:export writes a loadable JSON schema for each DB-defined type', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);

    $type = ContentType::fromArray([
        'handle' => 'post',
        'displayName' => 'Post',
        'localizable' => true,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'body', 'type' => 'textarea'],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    // Mark as database-defined so schema:export picks it up.
    ContentTypeRecord::where('handle', 'post')->update(['is_database_defined' => true]);

    $dir = sys_get_temp_dir().'/magna-schema-export-'.uniqid();

    Artisan::call('magna:schema:export', [
        '--dir' => $dir,
        '--force' => true,
    ]);

    $filePath = $dir.'/post.json';
    expect(file_exists($filePath))->toBeTrue();

    $loaded = json_decode(file_get_contents($filePath), true);
    expect($loaded)->toBeArray()
        ->and($loaded['handle'])->toBe('post')
        ->and($loaded['displayName'])->toBe('Post')
        ->and($loaded['localizable'])->toBeTrue();

    // Cleanup
    @unlink($filePath);
    @rmdir($dir);
});

it('magna:schema:export round-trip: exported JSON can be re-loaded by SchemaRegistry', function (): void {
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    /** @var FieldTypeRegistry $fieldTypeRegistry */
    $fieldTypeRegistry = app(FieldTypeRegistry::class);

    $original = ContentType::fromArray([
        'handle' => 'news',
        'displayName' => 'News',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'headline', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'headline'],
        ],
    ], $fieldTypeRegistry);

    ContentTypeRecord::create([
        'handle' => 'news',
        'display_name' => 'News',
        'is_database_defined' => true,
        'schema' => $original->toArray(),
    ]);

    $dir = sys_get_temp_dir().'/magna-schema-rt-'.uniqid();
    Artisan::call('magna:schema:export', [
        '--dir' => $dir,
        '--force' => true,
    ]);

    $filePath = $dir.'/news.json';
    $loaded = ContentType::fromFile($filePath, $fieldTypeRegistry);

    expect($loaded->handle)->toBe('news')
        ->and($loaded->displayName)->toBe('News')
        ->and($loaded->localizable)->toBeFalse()
        ->and($loaded->draftable)->toBeTrue()
        ->and(count($loaded->fields))->toBe(2);

    @unlink($filePath);
    @rmdir($dir);
});
