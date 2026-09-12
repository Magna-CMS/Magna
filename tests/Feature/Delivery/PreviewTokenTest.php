<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Delivery\PreviewTokenService;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function previewDeliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('preview-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

function previewManagementToken(): string
{
    // Minting a preview token requires the same ability as reading the entry
    // through the management API (content.{type}.view) — a management-scope
    // token alone is not enough. See the authorization cases below.
    $role = Role::factory()->create();
    $role->grant('content.article.view');

    $user = User::factory()->create();
    $user->assignRole($role);

    $result = $user->createToken('preview-management', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function setupPreviewType(): void
{
    /** @var SchemaRegistry $registry */
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'article',
        'displayName' => 'Article',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

// ── Mint a preview token ──────────────────────────────────────────────────────

it('management token can mint a preview token for a draft entry', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Pending Draft',
        'slug' => 'pending',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $mgmt = previewManagementToken();

    $response = $this->postJson("/api/v1/content/article/{$draft->id}/preview-token", [], [
        'Authorization' => 'Bearer '.$mgmt,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['token', 'entry_id', 'entry_type', 'expires_at'])
        ->assertJsonPath('entry_id', $draft->id)
        ->assertJsonPath('entry_type', 'article');
});

// S1-18: previously ttl_seconds had no upper bound — a management caller
// could mint an effectively decades-long-lived, non-revocable (stateless
// HMAC) draft-access token.
it('clamps an excessive ttl_seconds to the maximum allowed', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Long TTL',
        'slug' => 'long-ttl',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $mgmt = previewManagementToken();

    $response = $this->postJson("/api/v1/content/article/{$draft->id}/preview-token", [
        'ttl_seconds' => 60 * 60 * 24 * 365 * 10, // 10 years
    ], [
        'Authorization' => 'Bearer '.$mgmt,
    ]);

    $response->assertStatus(200);

    $expiresAt = Carbon::parse($response->json('expires_at'));
    expect($expiresAt->diffInSeconds(now()))->toBeLessThanOrEqual(604_800 + 5); // 7 days + small margin
});

// A preview token IS draft read access to the type for whoever holds it, so
// minting one demands content.{type}.view. Before this check any
// management-scope token — whatever its holder was actually permitted to see
// — could mint week-long, non-revocable draft-access tokens.
it('refuses to mint for a management token whose holder cannot view the type', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Off Limits',
        'slug' => 'off-limits',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    // Management scope, but no content.article.view — exactly the caller the
    // missing check used to let through.
    $user = User::factory()->create();
    $result = $user->createToken('preview-unpermitted', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    $this->postJson("/api/v1/content/article/{$draft->id}/preview-token", [], [
        'Authorization' => 'Bearer '.$result->plainTextToken,
    ])->assertStatus(403);
});

it('delivery token cannot mint a preview token (403)', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Draft',
        'slug' => 'draft',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $delivery = previewDeliveryToken();

    $this->postJson("/api/v1/content/article/{$draft->id}/preview-token", [], [
        'Authorization' => 'Bearer '.$delivery,
    ])->assertStatus(403);
});

// ── Serve a draft via preview token ──────────────────────────────────────────

it('preview token allows reading a draft entry', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Draft Title',
        'slug' => 'draft-title',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    $token = app(PreviewTokenService::class)->mint($draft->id, 'article', 600);
    $delivery = previewDeliveryToken();

    $this->getJson("/api/v1/content/article/{$draft->id}?preview=1&preview_token={$token}", [
        'Authorization' => 'Bearer '.$delivery,
    ])->assertStatus(200)
        ->assertJsonPath('data.id', $draft->id)
        ->assertJsonPath('data.status', 'draft');
});

// ── Preview token is entry-scoped ─────────────────────────────────────────────

it('preview token for entry A cannot read entry B', function (): void {
    setupPreviewType();

    $draftA = Entry::type('article')->create([
        'title' => 'Draft A',
        'slug' => 'draft-a',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);
    $draftB = Entry::type('article')->create([
        'title' => 'Draft B',
        'slug' => 'draft-b',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    // Token is scoped to draftA
    $token = app(PreviewTokenService::class)->mint($draftA->id, 'article', 600);
    $delivery = previewDeliveryToken();

    $this->getJson("/api/v1/content/article/{$draftB->id}?preview=1&preview_token={$token}", [
        'Authorization' => 'Bearer '.$delivery,
    ])->assertStatus(403);
});

// ── Expired preview token ─────────────────────────────────────────────────────

it('an expired preview token is rejected', function (): void {
    setupPreviewType();

    $draft = Entry::type('article')->create([
        'title' => 'Draft',
        'slug' => 'draft',
        'status' => EntryStatus::Draft,
        'locale' => '',
    ]);

    // Mint with 1-second TTL, then travel past expiry
    $token = app(PreviewTokenService::class)->mint($draft->id, 'article', 1);
    $delivery = previewDeliveryToken();

    $this->travel(10)->seconds();

    $this->getJson("/api/v1/content/article/{$draft->id}?preview=1&preview_token={$token}", [
        'Authorization' => 'Bearer '.$delivery,
    ])->assertStatus(403);
});

// ── PreviewTokenService unit ───────────────────────────────────────────────────

it('PreviewTokenService validates a fresh token correctly', function (): void {
    $service = app(PreviewTokenService::class);

    $token = $service->mint('01JENTRYIDAAAAAAAAAAAAAAA', 'article');

    expect($service->validate($token, '01JENTRYIDAAAAAAAAAAAAAAA', 'article'))->toBeTrue();
    expect($service->validate($token, '01JENTRYIDAAAAAAAAAAAAAAA', 'page'))->toBeFalse();
    expect($service->validate($token, '01JENTRYIDBBBBBBBBBBBBBB', 'article'))->toBeFalse();
});

it('PreviewTokenService rejects a tampered token', function (): void {
    $service = app(PreviewTokenService::class);

    $token = $service->mint('01JENTRYIDAAAAAAAAAAAAAAA', 'article');
    $tampered = $token.'x';

    expect($service->validate($tampered, '01JENTRYIDAAAAAAAAAAAAAAA', 'article'))->toBeFalse();
});
