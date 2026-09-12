<?php

declare(strict_types=1);

/**
 * Encrypted schema fields (`encrypted: true`) must never leave in plaintext.
 *
 * The `encrypted` cast decrypts transparently on attribute access, so before
 * this rule every serializer that looped columnFields() emitted the decrypted
 * value: the delivery API, the management resource, and — via the resource's
 * snapshots — audit_logs.before/after, which archived every secret ever
 * edited. DeliveryQueryBuilder had already named exposure "a disclosure risk"
 * and excluded these fields from filters; the values themselves still went
 * out. Now: delivery omits the field, management reads back a placeholder
 * (write-only secret semantics), the audit trail stores the placeholder, and
 * a placeholder resubmitted on write means "unchanged", never a new value.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Audit\AuditLog;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\EncryptedFieldRedactor;
use Magna\Content\Entry;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const REDACTION_SECRET = 'sk-live-4242424242424242';

function redactionRegisterType(): void
{
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'integration',
        'displayName' => 'Integration',
        'localizable' => false,
        'draftable' => true,
        'fields' => [
            ['handle' => 'title', 'type' => 'text', 'required' => true],
            ['handle' => 'slug', 'type' => 'slug', 'from' => 'title'],
            ['handle' => 'api_key', 'type' => 'text', 'encrypted' => true],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);
}

function redactionManagementToken(): string
{
    $role = Role::factory()->create();
    $role->grant('content.*');

    $user = User::factory()->create();
    $user->assignRole($role);

    $result = $user->createToken('redaction-mgmt', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function redactionDeliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('redaction-delivery', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

// ── The value is stored encrypted and readable where it should be ───────────

it('stores the secret encrypted at rest and decrypts on model access', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Stripe', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->assertStatus(201)->json('data.id');

    $entry = Entry::type('integration')->where('id', $id)->firstOrFail();

    // The model (admin-side seam) still decrypts; the raw column does not
    // contain the plaintext.
    expect($entry->getAttribute('api_key'))->toBe(REDACTION_SECRET)
        ->and((string) $entry->getRawOriginal('api_key'))->not->toContain(REDACTION_SECRET);
});

// ── Management API: write-only secret ───────────────────────────────────────

it('reads the secret back as a placeholder on every management response', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Stripe', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    $show = $this->getJson("/api/v1/manage/entries/integration/{$id}", ['Authorization' => 'Bearer '.$mgmt])
        ->assertStatus(200);
    $index = $this->getJson('/api/v1/manage/entries/integration', ['Authorization' => 'Bearer '.$mgmt])
        ->assertStatus(200);

    expect($show->json('data.api_key'))->toBe(EncryptedFieldRedactor::PLACEHOLDER)
        ->and($index->json('data.0.api_key'))->toBe(EncryptedFieldRedactor::PLACEHOLDER)
        ->and($show->getContent())->not->toContain(REDACTION_SECRET)
        ->and($index->getContent())->not->toContain(REDACTION_SECRET);
});

it('keeps an unset secret as null, not a fake placeholder', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'No key yet',
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    $this->getJson("/api/v1/manage/entries/integration/{$id}", ['Authorization' => 'Bearer '.$mgmt])
        ->assertJsonPath('data.api_key', null);
});

// ── Audit trail stores the placeholder, never the plaintext ────────────────

it('never writes the plaintext secret into the audit trail', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Stripe', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    $this->putJson("/api/v1/manage/entries/integration/{$id}", [
        'title' => 'Stripe (renamed)',
    ], ['Authorization' => 'Bearer '.$mgmt])->assertStatus(200);

    $logs = AuditLog::query()->get();
    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        $row = json_encode([$log->before, $log->after]);
        expect($row)->not->toContain(REDACTION_SECRET);
    }
});

// ── Placeholder round-trip means "unchanged" ────────────────────────────────

it('treats a resubmitted placeholder as unchanged, never as a new value', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Stripe', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    // The read-modify-write a real client performs: GET, tweak the title,
    // PATCH the whole payload back — placeholder included.
    $payload = $this->getJson("/api/v1/manage/entries/integration/{$id}", ['Authorization' => 'Bearer '.$mgmt])
        ->json('data');
    $payload['title'] = 'Stripe (round trip)';

    $this->putJson("/api/v1/manage/entries/integration/{$id}", [
        'title' => $payload['title'],
        'api_key' => $payload['api_key'],
    ], ['Authorization' => 'Bearer '.$mgmt])->assertStatus(200);

    $entry = Entry::type('integration')->where('id', $id)->firstOrFail();
    expect($entry->getAttribute('api_key'))->toBe(REDACTION_SECRET)
        ->and($entry->getAttribute('title'))->toBe('Stripe (round trip)');
});

it('still lets a real new value replace the secret', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Stripe', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    $this->putJson("/api/v1/manage/entries/integration/{$id}", [
        'api_key' => 'sk-live-rotated',
    ], ['Authorization' => 'Bearer '.$mgmt])->assertStatus(200);

    $entry = Entry::type('integration')->where('id', $id)->firstOrFail();
    expect($entry->getAttribute('api_key'))->toBe('sk-live-rotated');
});

// ── Delivery API: the field does not exist ──────────────────────────────────

it('omits the encrypted field entirely from the delivery payload', function (): void {
    redactionRegisterType();
    $mgmt = redactionManagementToken();

    $id = $this->postJson('/api/v1/manage/entries/integration', [
        'title' => 'Public thing', 'api_key' => REDACTION_SECRET,
    ], ['Authorization' => 'Bearer '.$mgmt])->json('data.id');

    $this->postJson("/api/v1/manage/entries/integration/{$id}/publish", [], ['Authorization' => 'Bearer '.$mgmt])
        ->assertStatus(200);

    $delivery = redactionDeliveryToken();
    $response = $this->getJson("/api/v1/content/integration/{$id}", ['Authorization' => 'Bearer '.$delivery])
        ->assertStatus(200);

    expect($response->json('data'))->not->toHaveKey('api_key')
        ->and($response->getContent())->not->toContain(REDACTION_SECRET);
});
