<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Magna\Admin\Resources\Entry\CreateEntry;
use Magna\Admin\Resources\Entry\EditEntry;
use Magna\Admin\Resources\Entry\ListEntries;
use Magna\Admin\Resources\EntryResource;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryManager;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\Models\Revision;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Contracts\ExtendsEntryForm;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The content type reaches the entry screens as ?type= on the page URL, and
// only there. Every click after that is a POST to Livewire's update route,
// which carries a snapshot and no query string, so anything that reads the
// type off the request sees nothing on exactly the requests that do the work.
//
// Livewire::test() behaves the same way, and that is the point of it here:
// the query parameter goes to the mounting GET and is gone from each update.
// Do not "help" these tests with request()->query->set('type', ...) — that is
// how the screens stayed broken behind green tests.

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('magna'));
});

function entryActionsRegisterType(string $handle): void
{
    $registry = app(SchemaRegistry::class);
    $registry->register(ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Entry Actions Test',
        'localizable' => false,
        'draftable' => true,
        'fields' => [['handle' => 'title', 'type' => 'text']],
    ], app(FieldTypeRegistry::class)));
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

function entryActionsAdmin(): User
{
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

/** @param list<string> $permissions */
function entryActionsEditor(array $permissions): User
{
    $role = Role::factory()->create();
    $role->grant(...$permissions);

    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function entryActionsPublished(string $handle): Entry
{
    $manager = app(EntryManager::class);

    return $manager->publish($manager->create($handle, ['title' => 'Live page']));
}

function entryActionsReload(string $handle, Entry $entry): Entry
{
    return Entry::type($handle)->findOrFail($entry->id);
}

function entryActionsList(string $handle): Testable
{
    return Livewire::withQueryParams(['type' => $handle])->test(ListEntries::class);
}

function entryActionsEdit(string $handle, Entry $entry): Testable
{
    return Livewire::withQueryParams(['type' => $handle])->test(EditEntry::class, ['record' => $entry->id]);
}

/**
 * Proof the update really arrived the way a browser sends it. Without this a
 * test could pass by accident on a request that still carried the parameter.
 */
function entryActionsExpectNoTypeOnRequest(): void
{
    expect(request()->isMethod('POST'))->toBeTrue()
        ->and(request()->query('type'))->toBeNull();
}

// ── List screen ──────────────────────────────────────────────────────────────

it('unpublishes a row from the list on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_list_unpublish');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_list_unpublish');

    entryActionsList('wire_list_unpublish')
        ->callAction(TestAction::make('unpublish')->table($entry))
        ->assertOk();

    entryActionsExpectNoTypeOnRequest();
    expect(entryActionsReload('wire_list_unpublish', $entry)->status)->toBe(EntryStatus::Archived);
});

it('publishes a row from the list on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_list_publish');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_list_publish', ['title' => 'Draft page']);

    entryActionsList('wire_list_publish')
        ->callAction(TestAction::make('publish')->table($entry))
        ->assertOk();

    entryActionsExpectNoTypeOnRequest();
    expect(entryActionsReload('wire_list_publish', $entry)->status)->toBe(EntryStatus::Published);
});

it('opens a row\'s revision history on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_list_revisions');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_list_revisions');

    entryActionsList('wire_list_revisions')
        ->mountAction(TestAction::make('revisions')->table($entry))
        ->assertOk()
        ->assertMountedActionModalSee('Revision History');

    entryActionsExpectNoTypeOnRequest();
});

it('keeps the list\'s rows on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_list_rows');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_list_rows');

    entryActionsList('wire_list_rows')
        ->call('$refresh')
        ->assertOk()
        ->assertCanSeeTableRecords([$entry]);

    entryActionsExpectNoTypeOnRequest();
});

it('links each row to an edit screen that knows its type', function (): void {
    entryActionsRegisterType('wire_list_link');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_list_link');

    entryActionsList('wire_list_link')->assertActionHasUrl(
        TestAction::make('edit')->table($entry),
        EntryResource::getUrl('edit', ['record' => $entry, 'type' => 'wire_list_link']),
    );
});

it('bulk deletes through the manager, taking the entry\'s revisions with it', function (): void {
    entryActionsRegisterType('wire_list_bulk');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_list_bulk');
    app(EntryManager::class)->update($entry, ['title' => 'Second version']);

    expect(Revision::historyFor('wire_list_bulk', $entry->id))->not->toBeEmpty();

    entryActionsList('wire_list_bulk')
        ->selectTableRecords([$entry->id])
        ->callAction(TestAction::make('delete')->table()->bulk())
        ->assertOk();

    entryActionsExpectNoTypeOnRequest();
    expect(Entry::type('wire_list_bulk')->find($entry->id))->toBeNull()
        ->and(Revision::historyFor('wire_list_bulk', $entry->id))->toBeEmpty();
});

it('still refuses a list update from someone who cannot view the type', function (): void {
    entryActionsRegisterType('wire_list_mine');
    entryActionsRegisterType('wire_list_theirs');
    $this->actingAs(entryActionsEditor(['content.wire_list_mine.view']));

    entryActionsList('wire_list_theirs')->assertForbidden();
});

it('hides the row\'s Unpublish from someone without the publish permission', function (): void {
    entryActionsRegisterType('wire_list_viewer');
    $this->actingAs(entryActionsEditor(['content.wire_list_viewer.view']));
    $entry = entryActionsPublished('wire_list_viewer');

    entryActionsList('wire_list_viewer')
        ->call('$refresh')
        ->assertOk()
        ->assertActionHidden(TestAction::make('unpublish')->table($entry));

    expect(entryActionsReload('wire_list_viewer', $entry)->status)->toBe(EntryStatus::Published);
});

it('will not let the browser swap the list onto a type it was never authorised for', function (): void {
    entryActionsRegisterType('wire_swap_mine');
    entryActionsRegisterType('wire_swap_theirs');
    $this->actingAs(entryActionsEditor(['content.wire_swap_mine.view']));

    entryActionsList('wire_swap_mine')->set('type', 'wire_swap_theirs');
})->throws(CannotUpdateLockedPropertyException::class);

// ── Edit screen ──────────────────────────────────────────────────────────────

it('unpublishes from the edit screen on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_edit_unpublish');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_edit_unpublish');

    entryActionsEdit('wire_edit_unpublish', $entry)
        ->callAction('unpublish')
        ->assertOk();

    entryActionsExpectNoTypeOnRequest();
    expect(entryActionsReload('wire_edit_unpublish', $entry)->status)->toBe(EntryStatus::Archived);
});

it('saves the edit form on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_edit_save');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_edit_save', ['title' => 'Before']);

    entryActionsEdit('wire_edit_save', $entry)
        ->fillForm(['title' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    entryActionsExpectNoTypeOnRequest();
    expect(entryActionsReload('wire_edit_save', $entry)->getAttribute('title'))->toBe('After');
});

it('saves a draft from the edit screen\'s header', function (): void {
    entryActionsRegisterType('wire_edit_draft');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_edit_draft', ['title' => 'Before']);

    entryActionsEdit('wire_edit_draft', $entry)
        ->fillForm(['title' => 'After'])
        ->callAction('save_draft')
        ->assertHasNoFormErrors();

    entryActionsExpectNoTypeOnRequest();
    expect(entryActionsReload('wire_edit_draft', $entry)->getAttribute('title'))->toBe('After');
});

it('publishes from the edit screen, keeping what was typed', function (): void {
    entryActionsRegisterType('wire_edit_publish');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_edit_publish', ['title' => 'Before']);

    entryActionsEdit('wire_edit_publish', $entry)
        ->fillForm(['title' => 'After'])
        ->callAction('publish')
        ->assertHasNoFormErrors();

    entryActionsExpectNoTypeOnRequest();

    $fresh = entryActionsReload('wire_edit_publish', $entry);
    expect($fresh->status)->toBe(EntryStatus::Published)
        ->and($fresh->getAttribute('title'))->toBe('After');
});

it('opens the revision history from the edit screen', function (): void {
    entryActionsRegisterType('wire_edit_revisions');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_edit_revisions');

    entryActionsEdit('wire_edit_revisions', $entry)
        ->mountAction('revisions')
        ->assertOk()
        ->assertMountedActionModalSee('Revision History');

    entryActionsExpectNoTypeOnRequest();
});

it('deletes from the edit screen on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_edit_delete');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_edit_delete', ['title' => 'Going']);

    entryActionsEdit('wire_edit_delete', $entry)
        ->callAction('delete')
        ->assertRedirect(EntryResource::getUrl('index', ['type' => 'wire_edit_delete']));

    entryActionsExpectNoTypeOnRequest();
    expect(Entry::type('wire_edit_delete')->find($entry->id))->toBeNull();
});

it('hides publishing from an editor who holds update but not publish', function (): void {
    entryActionsRegisterType('wire_edit_nopublish');
    $this->actingAs(entryActionsEditor(['content.wire_edit_nopublish.view', 'content.wire_edit_nopublish.update']));
    $draft = app(EntryManager::class)->create('wire_edit_nopublish', ['title' => 'Draft']);
    $live = entryActionsPublished('wire_edit_nopublish');

    entryActionsEdit('wire_edit_nopublish', $draft)
        ->assertActionHidden('publish')
        ->assertActionHidden('schedule');

    entryActionsEdit('wire_edit_nopublish', $live)
        ->assertActionHidden('unpublish')
        ->assertActionHidden('schedule');
});

it('saves a plugin\'s entry form fields on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_edit_plugin');
    $this->actingAs(entryActionsAdmin());
    $entry = app(EntryManager::class)->create('wire_edit_plugin', ['title' => 'Page']);

    // The shape Magna SEO uses: a field kept out of the entry's own payload,
    // saved against the record once the entry itself has been written.
    $saved = new stdClass;
    app()->instance('magna.entry_form_plugins', [new class($saved) implements ExtendsEntryForm
    {
        public function __construct(private stdClass $saved) {}

        public function entryFormExtensions(string $typeHandle): array
        {
            return [
                TextInput::make('plugin_field')
                    ->dehydrated(false)
                    ->saveRelationshipsUsing(function (Entry $record, Get $get): void {
                        $this->saved->handle = $record->getHandle();
                        $this->saved->value = $get('plugin_field');
                    }),
            ];
        }
    }]);

    entryActionsEdit('wire_edit_plugin', $entry)
        ->fillForm(['plugin_field' => 'From the plugin'])
        ->call('save')
        ->assertHasNoFormErrors();

    entryActionsExpectNoTypeOnRequest();
    expect($saved->handle ?? null)->toBe('wire_edit_plugin')
        ->and($saved->value ?? null)->toBe('From the plugin');
});

it('still refuses an edit update from someone who cannot update the type', function (): void {
    entryActionsRegisterType('wire_edit_viewer');
    $this->actingAs(entryActionsEditor(['content.wire_edit_viewer.view']));
    $entry = entryActionsPublished('wire_edit_viewer');

    entryActionsEdit('wire_edit_viewer', $entry)->assertForbidden();
});

it('will not let the browser swap the edit screen onto another type', function (): void {
    entryActionsRegisterType('wire_edit_swap');
    entryActionsRegisterType('wire_edit_swap_other');
    $this->actingAs(entryActionsAdmin());
    $entry = entryActionsPublished('wire_edit_swap');

    entryActionsEdit('wire_edit_swap', $entry)->set('type', 'wire_edit_swap_other');
})->throws(CannotUpdateLockedPropertyException::class);

// ── Create screen ────────────────────────────────────────────────────────────

it('creates an entry on an update with no type in the URL', function (): void {
    entryActionsRegisterType('wire_create');
    $this->actingAs(entryActionsAdmin());

    Livewire::withQueryParams(['type' => 'wire_create'])
        ->test(CreateEntry::class)
        ->fillForm(['title' => 'Brand new'])
        ->call('create')
        ->assertHasNoFormErrors();

    entryActionsExpectNoTypeOnRequest();
    expect(Entry::type('wire_create')->where('title', 'Brand new')->exists())->toBeTrue();
});
