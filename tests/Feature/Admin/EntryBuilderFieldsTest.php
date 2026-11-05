<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Magna\Admin\Resources\Entry\CreateEntry;
use Magna\Admin\Resources\Entry\EditEntry;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// A built page reaches the entry form with two columns the form must not
// damage: the block document, which it cannot show, and the settings object,
// which it shows as text. Every assertion that matters here reads the raw
// column, because the array cast hides exactly what these tests are after:
// `{}` and `[]` are the same PHP value and different bytes.

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));

    $role = Role::factory()->create(['is_super_admin' => true]);
    $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $admin->assignRole($role);
    test()->actingAs($admin);
});

function entryFieldsRegisterType(string $handle = 'page'): void
{
    $registry = app(SchemaRegistry::class);
    $registry->register(ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Entry Fields Test',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text'],
            ['handle' => 'blocks_data', 'type' => 'blocks'],
            ['handle' => 'page_settings', 'type' => 'json'],
        ],
    ], app(FieldTypeRegistry::class)));
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

/** An entry whose two document columns hold exactly these bytes. */
function entryFieldsSeed(?string $blocks, ?string $settings, string $handle = 'page'): Entry
{
    $entry = app(EntryManager::class)->create($handle, ['title' => 'Home']);

    DB::table($entry->getTable())->where('id', $entry->id)->update([
        'blocks_data' => $blocks,
        'page_settings' => $settings,
    ]);

    return Entry::type($handle)->findOrFail($entry->id);
}

/** @return array<string, mixed> */
function entryFieldsRaw(Entry $entry): array
{
    return (array) DB::table($entry->getTable())->where('id', $entry->id)->first();
}

function entryFieldsEdit(Entry $entry, string $handle = 'page'): Testable
{
    return Livewire::withQueryParams(['type' => $handle])
        ->test(EditEntry::class, ['record' => $entry->id]);
}

function entryFieldsRegisterBuilderRoute(): void
{
    Route::get('/pages-builder/edit/{id}', static fn (): string => 'builder')->name('pages.builder.edit');
    app('router')->getRoutes()->refreshNameLookups();
}

const ENTRY_FIELDS_DOCUMENT = '[{"id":"s1","type":"section","settings":[],"columns":[]}]';

// ── The block document ───────────────────────────────────────────────────────

it('tells the owner where sections are edited instead of showing an empty editor', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, null);

    entryFieldsEdit($entry)
        ->assertSee('Sections are not edited on this screen')
        ->assertDontSee('No sections yet')
        ->assertDontSeeHtml('magna-block-editor');
});

it('links to the builder for a saved page while the builder is installed', function (): void {
    entryFieldsRegisterType();
    entryFieldsRegisterBuilderRoute();
    $entry = entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, null);

    entryFieldsEdit($entry)
        ->assertSee('Open page builder')
        ->assertSeeHtml('/pages-builder/edit/'.$entry->id);
});

it('offers no link the site could not follow', function (): void {
    // No builder on this site.
    entryFieldsRegisterType();
    entryFieldsEdit(entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, null))
        ->assertSee('The page builder in Magna Pages edits them')
        ->assertDontSee('Open page builder');

    // A builder, but one that would 404 on this content type.
    entryFieldsRegisterBuilderRoute();
    entryFieldsRegisterType('landing');
    entryFieldsEdit(entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, null, 'landing'), 'landing')
        ->assertDontSee('Open page builder');

    // Nothing to open yet.
    Livewire::withQueryParams(['type' => 'page'])
        ->test(CreateEntry::class)
        ->assertSee('Sections are not edited on this screen')
        ->assertDontSee('Open page builder');
});

it('gives the document no place in the form state Save reads', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, '{"a":1}');

    $handed = new ArrayObject;
    app()->bind(EntryManager::class, fn () => new class($handed)
    {
        /** @param ArrayObject<string, mixed> $handed */
        public function __construct(private ArrayObject $handed) {}

        /** @param array<string, mixed> $data */
        public function update(Entry $entry, array $data, ?string $actorId = null): Entry
        {
            $this->handed->exchangeArray($data);

            return $entry;
        }
    });

    entryFieldsEdit($entry)->fillForm(['title' => 'Renamed'])->call('save');

    expect($handed->getArrayCopy())->toHaveKey('title')
        ->not->toHaveKey('blocks_data')
        ->not->toHaveKey('page_settings');
});

// ── Save, byte for byte ──────────────────────────────────────────────────────

it('keeps both columns byte-identical through a Save of another field', function (string $blocks, ?string $settings): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed($blocks, $settings);
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $after = entryFieldsRaw($entry);

    expect($after['title'])->toBe('Renamed')
        ->and($after['blocks_data'])->toBe($before['blocks_data'])
        ->and($after['page_settings'])->toBe($before['page_settings']);
})->with([
    'plain' => [ENTRY_FIELDS_DOCUMENT, '{"background":{"type":"color","value":"#fff"}}'],
    'nothing stored' => ['[]', null],
    'empty object, which re-encodes as a list' => [ENTRY_FIELDS_DOCUMENT, '{}'],
    'empty object nested' => [ENTRY_FIELDS_DOCUMENT, '{"a":{},"b":[]}'],
    'escapes a re-encode would change' => [ENTRY_FIELDS_DOCUMENT, '{"url":"https:\/\/x.test\/a","name":"Héllo ₹"}'],
    'spacing and key order' => ['[ {"id":"s1", "type":"section"} ]', '{ "zz": 1,  "aa": [ ] }'],
    'floats' => [ENTRY_FIELDS_DOCUMENT, '{"ratio":1.0,"big":1e3}'],
    'unsanitised rich text the sanitizer would rewrite' => [
        '[{"id":"s1","type":"section","settings":[],"columns":[{"id":"c1","span":12,"settings":[],"blocks":[{"id":"b1","block":"text","settings":[],"data":{"body":"<p onclick=\"x()\">Raw</p><script>y()</script>"}}]}]}]',
        '{"a":1}',
    ],
]);

it('keeps both columns byte-identical through a Save that changes nothing', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, '{ "zz": 1, "aa": {} }');
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)->call('save')->assertHasNoFormErrors();

    $after = entryFieldsRaw($entry);

    expect($after['blocks_data'])->toBe($before['blocks_data'])
        ->and($after['page_settings'])->toBe($before['page_settings']);
});

it('keeps both columns byte-identical through Publish', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed(ENTRY_FIELDS_DOCUMENT, '{ "a": {} }');
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)->callAction(TestAction::make('publish'));

    $after = entryFieldsRaw($entry);

    expect(Entry::type('page')->findOrFail($entry->id)->status)->toBe(EntryStatus::Published)
        ->and($after['blocks_data'])->toBe($before['blocks_data'])
        ->and($after['page_settings'])->toBe($before['page_settings']);
});

// ── The settings box ─────────────────────────────────────────────────────────

it('shows stored JSON as JSON text, not as an object the browser prints', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"style":{"background":"#fff"},"url":"https:\/\/x.test","name":"₹"}');

    $shown = entryFieldsEdit($entry)->get('data.page_settings');

    expect($shown)->toBeString()
        ->and(json_decode($shown, true))->toEqual([
            'style' => ['background' => '#fff'],
            'url' => 'https://x.test',
            'name' => '₹',
        ])
        ->and($shown)->toContain('https://x.test')->toContain('₹')->toContain("\n");
});

it('stores an edit to the box as JSON, not as a string', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"style":{"background":"#fff"}}');

    entryFieldsEdit($entry)
        ->fillForm(['page_settings' => '{"style":{"background":"#000"}}'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(json_decode((string) entryFieldsRaw($entry)['page_settings'], true))
        ->toBe(['style' => ['background' => '#000']]);
});

it('treats reformatted text with the same meaning as no change', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"a":1,"b":{}}');
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)
        ->fillForm(['page_settings' => "{\n\n  \"a\": 1,   \"b\": []\n}"])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(entryFieldsRaw($entry)['page_settings'])->toBe($before['page_settings']);
});

it('refuses to empty the box while something is stored', function (string $blank): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"style":{"background":"#fff"}}');
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)
        ->fillForm(['page_settings' => $blank, 'title' => 'Renamed'])
        ->call('save')
        ->assertHasFormErrors(['page_settings' => 'required']);

    $after = entryFieldsRaw($entry);

    expect($after['page_settings'])->toBe($before['page_settings'])
        ->and($after['title'])->toBe($before['title']);
})->with(['empty' => '', 'spaces' => "  \n "]);

it('lets that refusal reach the owner instead of a browser tooltip', function (): void {
    entryFieldsRegisterType();

    // The form has no novalidate, so an HTML required attribute would stop
    // Save in the browser and the explanation below would never be shown.
    entryFieldsEdit(entryFieldsSeed('[]', '{"a":1}'))
        ->assertFormFieldExists('page_settings', static fn (Textarea $box): bool => ! $box->isRequired())
        ->fillForm(['page_settings' => ''])
        ->call('save')
        ->assertSee('To do that on purpose, enter {} instead.');
});

it('empties the column when {} is entered on purpose', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"style":{"background":"#fff"}}');

    entryFieldsEdit($entry)
        ->fillForm(['page_settings' => '{}'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(json_decode((string) entryFieldsRaw($entry)['page_settings'], true))->toBe([]);
});

it('leaves a NULL column NULL when the empty box is saved', function (): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', null);

    entryFieldsEdit($entry)
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(entryFieldsRaw($entry)['page_settings'])->toBeNull();
});

it('refuses text that is not a JSON object or list', function (string $typed): void {
    entryFieldsRegisterType();
    $entry = entryFieldsSeed('[]', '{"a":1}');
    $before = entryFieldsRaw($entry);

    entryFieldsEdit($entry)
        ->fillForm(['page_settings' => $typed])
        ->call('save')
        ->assertHasFormErrors(['page_settings']);

    expect(entryFieldsRaw($entry)['page_settings'])->toBe($before['page_settings']);
})->with([
    'what the old box displayed' => '[object Object]',
    'broken' => '{"a":',
    'a number' => '5',
    'a string' => '"text"',
    'null' => 'null',
]);

it('creates an entry from the box, and with the box left empty', function (): void {
    entryFieldsRegisterType();

    Livewire::withQueryParams(['type' => 'page'])
        ->test(CreateEntry::class)
        ->fillForm(['title' => 'Filled', 'page_settings' => '{"a":1}'])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::withQueryParams(['type' => 'page'])
        ->test(CreateEntry::class)
        ->fillForm(['title' => 'Empty'])
        ->call('create')
        ->assertHasNoFormErrors();

    $filled = Entry::type('page')->where('title', 'Filled')->firstOrFail();
    $empty = Entry::type('page')->where('title', 'Empty')->firstOrFail();

    expect(json_decode((string) entryFieldsRaw($filled)['page_settings'], true))->toBe(['a' => 1])
        ->and(entryFieldsRaw($empty)['page_settings'])->toBeNull();
});
