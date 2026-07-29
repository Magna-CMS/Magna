<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\Auth\Role;
use Magna\Content\ContentType;
use Magna\Content\Entry;
use Magna\Content\EntryStatus;
use Magna\Content\FieldTypeRegistry;
use Magna\Content\SchemaRegistry;
use Magna\Content\SchemaSyncer;
use Magna\Users\User;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\Support\WebhookSender;
use Magna\Webhooks\Support\WebhookUrlBlockedException;
use Magna\Webhooks\Support\WebhookUrlGuard;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('security');

// ── Helpers ───────────────────────────────────────────────────────────────────

function securityDeliveryToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('delivery-test', ['delivery'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'delivery'])->save();

    return $result->plainTextToken;
}

function securityManagementToken(): string
{
    $user = User::factory()->create();
    $result = $user->createToken('management-test', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function securitySuperAdminManagementToken(): string
{
    $role = Role::factory()->create(['is_super_admin' => true]);

    $user = User::factory()->create();
    $user->assignRole($role);

    $result = $user->createToken('management-super-admin', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function securityEditorManagementToken(): string
{
    $role = Role::factory()->create();
    $role->grant('content.*', 'settings.view');

    $user = User::factory()->create();
    $user->assignRole($role);

    $result = $user->createToken('management-editor', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

function securityRegisterType(string $handle, array $fields = []): ContentType
{
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => $handle,
        'displayName' => 'Security Test',
        'localizable' => false,
        'draftable' => false,
        'fields' => $fields ?: [
            ['handle' => 'title', 'type' => 'text'],
        ],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    return $type;
}

// ── CORS — management API must deny cross-origin requests ─────────────────────

it('management API returns 403 for cross-origin browser requests', function (): void {
    $token = securityManagementToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Origin' => 'https://evil.example.com',
    ])->getJson('/api/v1/manage/entries/article');

    $response->assertStatus(403);
});

it('management API allows same-origin requests', function (): void {
    // Super-admin token: bypasses the S1-17 permission-before-resolve gate
    // (Gate::before short-circuits for super admins), so a plain 404 for a
    // nonexistent type is still a clean signal that the request reached the
    // controller — a non-super-admin token now correctly gets 403 here
    // regardless of CORS, since content.nonexistent_type.view was never
    // registered (see the S1-17 tests in EntriesTest.php).
    $token = securitySuperAdminManagementToken();
    $appUrl = config('app.url');

    // Same-origin: Origin matches APP_URL — should not be blocked by CORS middleware
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Origin' => $appUrl,
    ])->getJson('/api/v1/manage/entries/nonexistent_type');

    // 404 (type not found) proves it passed the CORS check
    $response->assertStatus(404);
});

it('delivery API carries CORS headers on content routes', function (): void {
    securityRegisterType('cors_article');

    $token = securityDeliveryToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Origin' => 'https://mysite.example.com',
    ])->get('/api/v1/content/cors_article');

    $response->assertOk();
    // HandleCors adds Access-Control-Allow-Origin for matching paths
    $response->assertHeader('Access-Control-Allow-Origin');
});

it('management API does NOT carry CORS allow-origin headers', function (): void {
    // Management routes are not in config/cors.php paths — no CORS headers added
    $token = securityManagementToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/manage/entries/nonexistent_type');

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

// ── Token scope escalation ────────────────────────────────────────────────────

it('delivery token cannot access management endpoints', function (): void {
    $token = securityDeliveryToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/manage/entries/article');

    // 403: token is valid but wrong scope for this route
    $response->assertStatus(403);
});

it('management token cannot be used as delivery token', function (): void {
    $token = securityManagementToken();

    securityRegisterType('scope_article');

    // Management token on a delivery route — scope mismatch should be rejected
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/content/scope_article');

    // 403: token is valid but wrong scope for this route
    $response->assertStatus(403);
});

// ── Field-level encryption ────────────────────────────────────────────────────

it('encrypted fields are stored as ciphertext in the database', function (): void {
    securityRegisterType('secure_type', [
        ['handle' => 'public_title', 'type' => 'text'],
        ['handle' => 'secret_note', 'type' => 'text', 'encrypted' => true],
    ]);

    Entry::type('secure_type')->create([
        'public_title' => 'Hello',
        'secret_note' => 'top secret',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    // Raw DB value must NOT be the plaintext string
    $raw = DB::table('magna_entries_secure_type')
        ->value('secret_note');

    expect($raw)->not->toBe('top secret');
    expect($raw)->toBeString();
    expect(strlen((string) $raw))->toBeGreaterThan(30); // ciphertext is longer than plaintext
});

it('encrypted fields are decrypted correctly when read back via Eloquent', function (): void {
    securityRegisterType('secure_type2', [
        ['handle' => 'title', 'type' => 'text'],
        ['handle' => 'secret', 'type' => 'text', 'encrypted' => true],
    ]);

    Entry::type('secure_type2')->create([
        'title' => 'Test',
        'secret' => 'my secret value',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    $entry = Entry::type('secure_type2')->first();
    expect($entry->secret)->toBe('my secret value');
});

it('encrypted fields are excluded from delivery API filter parameters', function (): void {
    securityRegisterType('secure_type3', [
        ['handle' => 'title', 'type' => 'text'],
        ['handle' => 'private_bio', 'type' => 'text', 'encrypted' => true],
    ]);

    $token = securityDeliveryToken();

    // Attempting to filter on an encrypted field returns 400 (disallowed column)
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/content/secure_type3?filter[private_bio][eq]=anything');

    $response->assertStatus(400);
});

// ── Mass-assignment probe — management API ────────────────────────────────────

it('management entry creation ignores protected system fields in POST body', function (): void {
    // draftable: true so entries start as Draft — attacker tries to force Published
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'mass_assign_type',
        'displayName' => 'Mass Assign Test',
        'localizable' => false,
        'draftable' => true,
        'fields' => [['handle' => 'title', 'type' => 'text']],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    // Editor token has content.* permissions required to create entries
    $token = securityEditorManagementToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/manage/entries/mass_assign_type', [
        'title' => 'Legitimate',
        'status' => 'published',    // attacker tries to force status
        'author_id' => 'arbitrary', // attacker tries to forge author
    ]);

    // Should succeed (201) but the status must be set by the controller logic, not user input
    $response->assertStatus(201);

    $entry = Entry::type('mass_assign_type')->first();
    // Status is Draft (default for new entries via management API), NOT 'published'
    expect($entry->status)->toBe(EntryStatus::Draft);
    // author_id set from the authenticated user, not from the POST body
    expect($entry->author_id)->not->toBe('arbitrary');
});

// ── Security headers on all response classes ──────────────────────────────────

it('security headers are present on JSON API 401 responses', function (): void {
    $response = $this->getJson('/api/v1/content/any_type');

    $response->assertUnauthorized();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Strict-Transport-Security');
});

it('security headers are present on JSON API 400 responses', function (): void {
    securityRegisterType('header_check_type');
    $token = securityDeliveryToken();

    // A filter on a non-existent column returns 400 — verify headers are present on error responses
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/content/header_check_type?filter[bad_col][eq]=x');

    $response->assertStatus(400);
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

// ── GDPR — privacy export and erase ──────────────────────────────────────────

it('magna:privacy:export writes a JSON archive containing the user profile', function (): void {
    Storage::fake('local');

    $user = User::factory()->create([
        'name' => 'Jane Export',
        'email' => 'jane@example.com',
    ]);

    $result = Artisan::call('magna:privacy:export', ['identifier' => 'jane@example.com']);

    expect($result)->toBe(0);

    // Find the written file
    $files = Storage::disk('local')->files('privacy');
    expect($files)->not->toBeEmpty();

    $contents = Storage::disk('local')->get($files[0]);
    expect($contents)->toBeString();

    $data = json_decode((string) $contents, true);
    expect($data)->toBeArray();
    expect($data['core']['profile']['email'])->toBe('jane@example.com');
    expect($data['core']['profile']['name'])->toBe('Jane Export');
});

it('magna:privacy:erase anonymises the user and revokes all tokens', function (): void {
    $user = User::factory()->create([
        'name' => 'Jane Erase',
        'email' => 'delete-me@example.com',
    ]);
    $user->createToken('test', ['delivery'], now()->addDay());

    expect($user->tokens()->count())->toBe(1);

    $result = Artisan::call('magna:privacy:erase', ['identifier' => 'delete-me@example.com']);

    expect($result)->toBe(0);

    $user->refresh();
    expect($user->name)->toBe('Deleted User');
    expect($user->email)->toContain('@invalid');
    expect($user->tokens()->count())->toBe(0);
});

// ── .well-known/security.txt ──────────────────────────────────────────────────

it('/.well-known/security.txt returns plain text with Contact field', function (): void {
    $response = $this->get('/.well-known/security.txt');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->getContent())->toContain('Contact:');
    expect($response->getContent())->toContain('Policy:');
    expect($response->getContent())->toContain('Expires:');
});

// ── A-08: security.txt Expires is valid RFC 3339 UTC ─────────────────────────

it('security.txt Expires is RFC 3339 UTC with Z suffix', function (): void {
    $response = $this->get('/.well-known/security.txt');
    $response->assertOk();

    $body = $response->getContent();
    preg_match('/^Expires:\s*(.+)$/m', (string) $body, $matches);
    expect($matches)->not->toBeEmpty('Expires field must be present');

    $expires = trim($matches[1]);

    // Must end with literal 'Z' (UTC), not a timezone offset
    expect($expires)->toEndWith('Z');

    // Must be parseable as a valid datetime
    $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $expires);
    expect($dt)->not->toBeFalse('Expires must parse as RFC 3339');

    // Must be in the future
    expect($dt->getTimestamp())->toBeGreaterThan(time());
});

// ── A-06: erased user password cannot be empty ───────────────────────────────

it('privacy:erase sets an unguessable random password, not bcrypt empty string', function (): void {
    $user = User::factory()->create(['email' => 'erase-pw-test@invalid']);
    $result = Artisan::call('magna:privacy:erase', ['identifier' => 'erase-pw-test@invalid']);
    expect($result)->toBe(0);

    $user->refresh();

    // The erased password must NOT be the hash of an empty string
    expect(Hash::check('', $user->password))->toBeFalse();

    // The password must be a non-empty hash (the random-string bcrypt hash)
    expect($user->password)->toBeString()->not->toBeEmpty();
});

// ── A-04: auth token endpoint must deny cross-origin browser requests ─────────

it('auth token endpoint returns 403 for cross-origin browser requests', function (): void {
    $response = $this->withHeaders([
        'Origin' => 'https://evil.example.com',
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/tokens', [
        'email' => 'nobody@example.com',
        'password' => 'wrong',
        'device_name' => 'test',
    ]);

    // DenyManagementCrossOriginMiddleware must block cross-origin requests
    // before authentication even runs
    $response->assertStatus(403);
});

// ── A-03: schema:encrypt skips already-encrypted values (no double-encrypt) ──

it('magna:schema:encrypt skips values that are already encrypted', function (): void {
    securityRegisterType('encrypt_idempotent', [
        ['handle' => 'notes', 'type' => 'text', 'encrypted' => true],
    ]);

    // Insert a value that is already encrypted (simulating a previous run)
    $alreadyEncrypted = Crypt::encryptString('secret payload');

    DB::table('magna_entries_encrypt_idempotent')->insert([
        'id' => strtolower((string) Str::ulid()),
        'status' => 'published',
        'locale' => '',
        'notes' => $alreadyEncrypted,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = Artisan::call('magna:schema:encrypt', [
        '--type' => 'encrypt_idempotent',
        '--field' => 'notes',
    ]);
    expect($result)->toBe(0);

    // The raw DB value must still decrypt cleanly to the original plaintext
    $raw = DB::table('magna_entries_encrypt_idempotent')->value('notes');
    expect($raw)->toBeString();

    $decrypted = Crypt::decryptString((string) $raw);
    expect($decrypted)->toBe('secret payload');
});

// ── A-07: locale='' sentinel reaches pre-locale content ───────────────────────

it('delivery API serves content with locale="" as last-resort fallback', function (): void {
    $registry = app(SchemaRegistry::class);
    $type = ContentType::fromArray([
        'handle' => 'locale_fallback_test',
        'displayName' => 'Locale Fallback',
        'localizable' => true,
        'draftable' => false,
        'fields' => [['handle' => 'title', 'type' => 'text']],
    ], app(FieldTypeRegistry::class));
    $registry->register($type);
    app(SchemaSyncer::class)->syncAll($registry, allowDestructive: true);

    // Insert an entry with locale='' (pre-localisation content)
    Entry::type('locale_fallback_test')->create([
        'title' => 'Fallback Entry',
        'status' => EntryStatus::Published,
        'locale' => '',
        'published_at' => now(),
    ]);

    $token = securityDeliveryToken();

    // Request with a locale that doesn't exist — should fall back to locale=''
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
    ])->getJson('/api/v1/content/locale_fallback_test?locale=fr');

    $response->assertOk();
    expect($response->json('data.0.title'))->toBe('Fallback Entry');
});

// ── S1-02: 'roles.manage' alone must not allow granting super-admin ──────────

it('rejects assigning a super-admin role via the API without super-admin privilege', function (): void {
    $superAdminRole = Role::factory()->create(['name' => 'security_super_admin_role', 'is_super_admin' => true]);

    $managerRole = Role::factory()->create(['name' => 'security_role_manager']);
    $managerRole->grant('roles.manage');

    $actor = User::factory()->create();
    $actor->assignRole($managerRole);

    $target = User::factory()->create();

    $result = $actor->createToken('role-escalation-test', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result->plainTextToken,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/manage/users/{$target->id}/roles", [
        'role' => $superAdminRole->name,
    ]);

    $response->assertStatus(403);
    expect($target->fresh()->isSuperAdmin())->toBeFalse();
});

it('allows an existing super admin to assign a super-admin role via the API', function (): void {
    $superAdminRole = Role::factory()->create(['name' => 'security_super_admin_role2', 'is_super_admin' => true]);
    $actorRole = Role::factory()->create(['name' => 'security_actor_super_admin', 'is_super_admin' => true]);

    $actor = User::factory()->create();
    $actor->assignRole($actorRole);

    $target = User::factory()->create();

    $result = $actor->createToken('role-escalation-allowed-test', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result->plainTextToken,
        'Content-Type' => 'application/json',
    ])->postJson("/api/v1/manage/users/{$target->id}/roles", [
        'role' => $superAdminRole->name,
    ]);

    $response->assertOk();
    expect($target->fresh()->isSuperAdmin())->toBeTrue();
});

// ── S1-04: webhook SSRF guard ──────────────────────────────────────────────────

function securityWebhookManagerToken(): string
{
    $role = Role::factory()->create(['name' => 'security_webhook_manager_'.Str::random(8)]);
    $role->grant('webhooks.manage');

    $user = User::factory()->create();
    $user->assignRole($role);

    $result = $user->createToken('webhook-manager-test', ['management'], now()->addDay());
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    return $result->plainTextToken;
}

it('rejects creating a webhook subscription targeting the cloud metadata IP', function (): void {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.securityWebhookManagerToken(),
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/manage/webhooks', [
        'url' => 'http://169.254.169.254/latest/meta-data/',
        'events' => ['entry.published'],
    ]);

    $response->assertStatus(422);
    expect(WebhookSubscription::count())->toBe(0);
});

it('rejects creating a webhook subscription targeting a loopback/private IP', function (): void {
    $token = securityWebhookManagerToken();

    foreach (['http://127.0.0.1:6379/', 'http://10.0.0.5/hook', 'http://192.168.1.5/hook'] as $url) {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/manage/webhooks', [
            'url' => $url,
            'events' => ['entry.published'],
        ]);

        $response->assertStatus(422);
    }
});

it('allows creating a webhook subscription targeting a public IP', function (): void {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.securityWebhookManagerToken(),
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/manage/webhooks', [
        // IP literal — avoids a real DNS lookup in the test environment.
        'url' => 'http://8.8.8.8/webhook',
        'events' => ['entry.published'],
    ]);

    $response->assertStatus(201);
    expect(WebhookSubscription::count())->toBe(1);
});

it('rejects updating a webhook subscription to target a private IP', function (): void {
    $token = securityWebhookManagerToken();

    $create = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ])->postJson('/api/v1/manage/webhooks', [
        'url' => 'http://8.8.8.8/webhook',
        'events' => ['entry.published'],
    ]);
    $id = $create->json('data.id');

    $update = $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ])->putJson("/api/v1/manage/webhooks/{$id}", [
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ]);

    $update->assertStatus(422);
    expect(WebhookSubscription::find($id)->url)->toBe('http://8.8.8.8/webhook');
});

it('DispatchWebhookJob refuses to deliver and marks the delivery dead when the target URL is currently private', function (): void {
    // Bypass controller validation via direct model write, simulating a URL
    // that has become private since the subscription was created (e.g. DNS
    // rebinding) — this is exactly the TOCTOU window the dispatch-time
    // re-validation in DispatchWebhookJob::handle() exists to close.
    $subscription = WebhookSubscription::create([
        'url' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'secret' => Str::random(32),
        'events' => ['entry.published'],
        'active' => true,
    ]);

    $delivery = WebhookDelivery::create([
        'subscription_id' => $subscription->id,
        'event' => 'entry.published',
        'payload' => ['test' => true],
        'status' => 'pending',
    ]);

    app()->call([new DispatchWebhookJob($delivery->id), 'handle']);

    $delivery->refresh();
    expect($delivery->status)->toBe('dead')
        ->and($delivery->response_body)->toContain('Blocked');
});

// ── S1-15: Sanctum abilities are enforced, not just the custom scope column ──

it('rejects a token whose Sanctum abilities do not include the required scope, even if the scope column claims otherwise', function (): void {
    $user = User::factory()->create();
    $result = $user->createToken('desynced', ['delivery'], now()->addDay());
    // Simulate a future bug/desync: scope column says management, but the
    // Sanctum abilities array was never updated to match.
    $result->accessToken->forceFill(['scope' => 'management'])->save();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result->plainTextToken,
    ])->getJson('/api/v1/manage/entries/article');

    $response->assertStatus(403);
});

it('WebhookUrlGuard blocks private/reserved ranges and allows public addresses', function (): void {
    expect(WebhookUrlGuard::isSafe('http://169.254.169.254/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://127.0.0.1/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://10.0.0.5/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://192.168.1.5/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://172.16.5.5/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://[::1]/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('ftp://8.8.8.8/'))->toBeFalse()
        ->and(WebhookUrlGuard::isSafe('http://8.8.8.8/'))->toBeTrue();
});

// ── S1-04b: DNS-rebinding pin ──────────────────────────────────────────────────

it('resolvePinnedTarget returns the validated IP, host and port to pin the connection to', function (): void {
    expect(WebhookUrlGuard::resolvePinnedTarget('http://8.8.8.8/webhook'))
        ->toBe(['host' => '8.8.8.8', 'port' => 80, 'ip' => '8.8.8.8']);

    expect(WebhookUrlGuard::resolvePinnedTarget('https://1.1.1.1/hook'))
        ->toBe(['host' => '1.1.1.1', 'port' => 443, 'ip' => '1.1.1.1']);

    expect(WebhookUrlGuard::resolvePinnedTarget('https://1.1.1.1:9443/hook'))
        ->toBe(['host' => '1.1.1.1', 'port' => 9443, 'ip' => '1.1.1.1']);
});

it('resolvePinnedTarget can never yield an internal or reserved address (throws instead)', function (): void {
    foreach ([
        'http://169.254.169.254/',   // cloud metadata
        'http://127.0.0.1/',         // loopback
        'http://10.0.0.5/',          // private
        'http://192.168.1.5/',       // private
        'http://[::1]/',             // IPv6 loopback
        'ftp://8.8.8.8/',            // disallowed scheme
    ] as $url) {
        expect(fn () => WebhookUrlGuard::resolvePinnedTarget($url))
            ->toThrow(WebhookUrlBlockedException::class);
    }
});

it('WebhookSender pins the request to the validated target and disables redirects', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $pin = WebhookUrlGuard::resolvePinnedTarget('http://8.8.8.8/webhook');
    $result = app(WebhookSender::class)->send('http://8.8.8.8/webhook', '{}', 'sig', time(), $pin);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === 'http://8.8.8.8/webhook'
        && $request->hasHeader('X-Magna-Signature-256'));
});
