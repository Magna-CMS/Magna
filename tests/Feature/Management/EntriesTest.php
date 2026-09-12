<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\Exceptions\SchemaException;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function mgmtToken(User $user): string
{
    $result = $user->createToken('mgmt', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function editorUser(): User
{
    $role = Role::factory()->create();
    $role->grant('content.*', 'settings.view');

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function viewerUser(): User
{
    $role = Role::factory()->create();
    $role->grant('content.*.view');

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function setupBlogType(): ContentType
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'blog',
        'displayName' => 'Blog Post',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
        ],
    ], app(FieldTypeRegistry::class));

    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    return $type;
}

// ── 401 without token ─────────────────────────────────────────────────────────

it('returns 401 when no token is provided', function (): void {
    setupBlogType();

    $this->getJson('/api/v1/manage/entries/blog')->assertStatus(401);
});

// ── 403 with wrong scope ──────────────────────────────────────────────────────

it('returns 403 when delivery token used on management endpoint', function (): void {
    setupBlogType();
    $user = User::factory()->create();
    $result = $user->createToken('del', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    $this->withToken($result->plainTextToken)
        ->getJson('/api/v1/manage/entries/blog')
        ->assertForbidden();
});

// ── 403 for viewer on write endpoints ────────────────────────────────────────

it('returns 403 when viewer tries to create an entry', function (): void {
    setupBlogType();
    $user = viewerUser();

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog', ['title' => 'Test', 'slug' => 'test'])
        ->assertForbidden();
});

// ── 404 for unknown type ──────────────────────────────────────────────────────

it('returns 403 for unknown content type when the type was never registered (S1-17: masks type existence)', function (): void {
    // A content type handle that was never registered has no matching
    // content.{type}.* permission key registered either — Gate::before()
    // fails closed for unregistered abilities regardless of the caller's
    // grants, so even a content.* wildcard holder gets 403, not 404. This
    // prevents a caller with zero real content access from distinguishing
    // "type doesn't exist" from "type exists, forbidden" by probing handles.
    $user = editorUser();

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/ghost')
        ->assertForbidden();
});

it('returns 404 for an unknown content type to a super admin (who bypasses the permission check entirely)', function (): void {
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/ghost')
        ->assertNotFound();
});

// ── CRUD flow ─────────────────────────────────────────────────────────────────

it('creates an entry and returns 201', function (): void {
    setupBlogType();
    $user = editorUser();

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog', ['title' => 'Hello', 'slug' => 'hello'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'blog')
        ->assertJsonPath('data.status', 'draft');
});

it('lists entries', function (): void {
    setupBlogType();
    $user = editorUser();

    Entry::type('blog')->create([
        'title' => 'First',
        'slug' => 'first',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    $response = $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/blog')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('shows a single entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Solo',
        'slug' => 'solo',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/blog/'.$entry->id)
        ->assertOk()
        ->assertJsonPath('data.id', $entry->id);
});

it('updates an entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Old',
        'slug' => 'old',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $this->withToken(mgmtToken($user))
        ->putJson('/api/v1/manage/entries/blog/'.$entry->id, ['title' => 'New', 'slug' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.title', 'New');
});

it('deletes an entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Bye',
        'slug' => 'bye',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $this->withToken(mgmtToken($user))
        ->deleteJson('/api/v1/manage/entries/blog/'.$entry->id)
        ->assertNoContent();

    expect(Entry::type('blog')->find($entry->id))->toBeNull();
});

// ── Publish / Unpublish ───────────────────────────────────────────────────────

it('publishes an entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Draft',
        'slug' => 'draft',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog/'.$entry->id.'/publish')
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect(Entry::type('blog')->find($entry->id)?->status)->toBe(EntryStatus::Published);
});

it('unpublishes a published entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Live',
        'slug' => 'live',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog/'.$entry->id.'/unpublish')
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');
});

it('returns 422 when trying to unpublish a non-published entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Draft2',
        'slug' => 'draft2',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog/'.$entry->id.'/unpublish')
        ->assertStatus(422);
});

// ── Revisions ─────────────────────────────────────────────────────────────────

it('lists revisions for an entry', function (): void {
    setupBlogType();
    $user = editorUser();

    $entry = Entry::type('blog')->create([
        'title' => 'Rev',
        'slug' => 'rev',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    // Update to generate a revision
    $this->withToken(mgmtToken($user))
        ->putJson('/api/v1/manage/entries/blog/'.$entry->id, ['title' => 'Rev 2', 'slug' => 'rev-2'])
        ->assertOk();

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/blog/'.$entry->id.'/revisions')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('viewer can list entries but not modify them', function (): void {
    setupBlogType();
    $user = viewerUser();

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/blog')
        ->assertOk();

    $this->withToken(mgmtToken($user))
        ->deleteJson('/api/v1/manage/entries/blog/'.str_repeat('0', 26))
        ->assertForbidden();
});

// ── Error-shape pins ──────────────────────────────────────────────────────────
// These three shapes are public API for management clients. They are pinned
// here BEFORE the error paths were consolidated (throwing type resolution,
// SchemaException rendered centrally) so the consolidation provably changed
// nothing a client can see.

it('answers an unknown content type with the exact 404 shape', function (): void {
    // The pin is the PRODUCTION shape: with debug on, the exception
    // renderer appends trace fields to every thrown 404.
    config(['app.debug' => false]);

    // Super admin: bypasses S1-17's fail-closed permission check (which
    // answers 403 to everyone else, so type handles cannot be enumerated)
    // and reaches the type resolution itself.
    $role = Role::factory()->create(['is_super_admin' => true]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->withToken(mgmtToken($user))
        ->getJson('/api/v1/manage/entries/nonsense_type')
        ->assertStatus(404)
        ->assertExactJson(['message' => "Content type 'nonsense_type' not found."]);

    $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/nonsense_type', ['title' => 'x'])
        ->assertStatus(404)
        ->assertExactJson(['message' => "Content type 'nonsense_type' not found."]);
});

it('answers a validation failure with the errors-only 422 shape', function (): void {
    setupBlogType();
    $user = editorUser();

    // No 'message' key: this controller has always answered ['errors' => …]
    // alone, unlike the framework default — clients parse it that way.
    $response = $this->withToken(mgmtToken($user))
        ->postJson('/api/v1/manage/entries/blog', ['slug' => 'no-title'])
        ->assertStatus(422);

    expect($response->json())->toHaveKey('errors')
        ->and($response->json())->not->toHaveKey('message');
});

it('renders a SchemaException from a management route as the message 400 shape', function (): void {
    // No natural request reaches the manager with a schema violation the
    // controller has not already pre-checked, so the rendering contract is
    // pinned directly: any SchemaException escaping an api route must
    // become {"message": …} with status 400.
    Route::middleware('api')->get('/api/v1/manage/_schema-exception-probe', function (): never {
        throw new SchemaException('The schema said no.');
    });

    $this->getJson('/api/v1/manage/_schema-exception-probe')
        ->assertStatus(400)
        ->assertExactJson(['message' => 'The schema said no.']);
});
