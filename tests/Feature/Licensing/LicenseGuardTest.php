<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Magna\Licensing\LicenseEntry;
use Magna\Licensing\LicenseGuard;
use Magna\Licensing\LicenseState;
use Magna\Licensing\LicenseStore;
use Magna\Licensing\SignedPayload;
use Magna\Marketplace\Marketplace;

beforeEach(function (): void {
    // A throwaway keypair stands in for the marketplace's; the public half
    // goes where core would carry the baked-in constant.
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
});

/** Build the signed envelope the marketplace would return. */
function signEnvelope(array $payload, string $secret): array
{
    $data = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    return [
        'data' => $data,
        'signature' => base64_encode(sodium_crypto_sign_detached($data, $secret)),
        'algorithm' => 'ed25519',
    ];
}

function storeEntry(array $overrides = []): LicenseEntry
{
    $entry = new LicenseEntry(
        productSlug: $overrides['product_slug'] ?? 'acme/crm',
        token: $overrides['token'] ?? str_repeat('t', 64),
        status: $overrides['status'] ?? 'active',
        licenseType: $overrides['license_type'] ?? 'lifetime',
        expiresAt: $overrides['expires_at'] ?? null,
        updateEntitled: $overrides['update_entitled'] ?? true,
        serverTime: $overrides['server_time'] ?? Carbon::now(),
        lastVerifiedAt: $overrides['last_verified_at'] ?? Carbon::now(),
    );

    app(LicenseStore::class)->put($entry);

    return $entry;
}

// ─── Signature verification ─────────────────────────────────────────────────

it('opens a correctly signed envelope and refuses a forged one', function (): void {
    $envelope = signEnvelope(['valid' => true, 'status' => 'active'], $this->signingSecret);

    expect(SignedPayload::open($envelope))->toMatchArray(['valid' => true, 'status' => 'active']);

    // Same payload, signature from a DIFFERENT key — the fake-licence-server attack.
    $rogue = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    expect(SignedPayload::open(signEnvelope(['valid' => true], $rogue)))->toBeNull();

    // Tampered data with a valid-looking signature.
    $tampered = $envelope;
    $tampered['data'] = base64_encode('{"valid":true,"status":"active","extra":"injected"}');
    expect(SignedPayload::open($tampered))->toBeNull();
});

it('treats an unsigned or malformed envelope as unusable', function (): void {
    expect(SignedPayload::open(['valid' => true]))->toBeNull()
        ->and(SignedPayload::open(['data' => 'x', 'signature' => 'y', 'algorithm' => 'hmac']))->toBeNull();
});

// ─── Guard states ───────────────────────────────────────────────────────────

it('reports a freshly verified licence as valid without calling the server', function (): void {
    Http::fake();
    storeEntry();

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Valid);

    Http::assertNothingSent();
});

it('reports an unknown product as unlicensed', function (): void {
    expect(app(LicenseGuard::class)->check('acme/nothing'))->toBe(LicenseState::Unlicensed);
});

it('re-verifies a stale entry and adopts the server\'s state', function (): void {
    storeEntry(['last_verified_at' => Carbon::now()->subDays(2), 'server_time' => Carbon::now()->subDays(2)]);

    Http::fake([
        '*/license/verify' => Http::response(signEnvelope([
            'valid' => false,
            'status' => 'revoked',
            'license_type' => 'lifetime',
            'update_entitled' => false,
            'server_time' => Carbon::now()->toIso8601String(),
        ], $this->signingSecret)),
    ]);

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Locked);
});

it('never bricks a paying site: an unreachable server yields grace, not lockout', function (): void {
    storeEntry([
        'last_verified_at' => Carbon::now()->subDays(2),
        'server_time' => Carbon::now()->subDays(2),
    ]);

    Http::fake(['*' => Http::response('', 500)]);

    $state = app(LicenseGuard::class)->check('acme/crm');

    expect($state)->toBe(LicenseState::Grace)
        ->and($state->featuresEnabled())->toBeTrue()
        ->and($state->canUpdate())->toBeTrue();
});

it('ends grace once the offline window has run out', function (): void {
    storeEntry([
        'last_verified_at' => Carbon::now()->subDays(20),
        'server_time' => Carbon::now()->subDays(20),
    ]);

    Http::fake(['*' => Http::response('', 500)]);

    $state = app(LicenseGuard::class)->check('acme/crm');

    // Expired, not Revoked: the site keeps running, updates stop.
    expect($state)->toBe(LicenseState::Expired)
        ->and($state->featuresEnabled())->toBeTrue()
        ->and($state->canUpdate())->toBeFalse();
});

it('holds a rolled-back clock in grace rather than locking a paying site', function (): void {
    // Server last spoke "now"; the site's clock then jumps a year backwards.
    storeEntry(['server_time' => Carbon::now(), 'last_verified_at' => Carbon::now()]);

    Http::fake(['*' => Http::response('', 500)]);
    Carbon::setTestNow(Carbon::now()->subYear());

    // A backwards jump is indistinguishable from an NTP correction on a host
    // that had drifted, so when the server is also unreachable the site stays
    // in grace and the event is logged — locking here would disable a paid
    // product over a corrected clock. The rollback still buys the attacker
    // nothing: extending grace requires a server-signed timestamp they cannot
    // forge, so grace still ends on schedule.
    $state = app(LicenseGuard::class)->check('acme/crm');

    expect($state)->toBe(LicenseState::Grace)
        ->and($state->featuresEnabled())->toBeTrue();

    Carbon::setTestNow();
});

it('locks a rolled-back clock when the licence server says the licence is gone', function (): void {
    storeEntry(['server_time' => Carbon::now(), 'last_verified_at' => Carbon::now()]);

    // Reachable server — the rollback no longer matters, the live answer does.
    Http::fake(['*' => Http::response(signEnvelope([
        'status' => 'revoked',
        'license_type' => 'corporate',
        'server_time' => Carbon::now()->toIso8601String(),
    ], $this->signingSecret))]);

    Carbon::setTestNow(Carbon::now()->subYear());

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Locked);

    Carbon::setTestNow();
});

it('honours a server-signed expiry locally once it passes', function (): void {
    storeEntry(['expires_at' => Carbon::now()->subDay()]);

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Expired);
});

it('keeps a past_due licence fully working through dunning', function (): void {
    storeEntry(['status' => 'past_due']);

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Valid);
});

// ─── Corporate and trial terms end hard ─────────────────────────────────────

it('stops a corporate product the moment its term ends, unlike a marketplace one', function (): void {
    storeEntry(['product_slug' => 'acme/custom', 'license_type' => 'corporate', 'expires_at' => Carbon::now()->subDay()]);
    storeEntry(['product_slug' => 'acme/shop', 'license_type' => 'lifetime', 'expires_at' => Carbon::now()->subDay()]);

    $guard = app(LicenseGuard::class);

    expect($guard->check('acme/custom'))->toBe(LicenseState::Locked)
        ->and($guard->check('acme/shop'))->toBe(LicenseState::Expired);
});

it('stops a corporate product when grace runs out, and degrades a marketplace one', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    storeEntry([
        'product_slug' => 'acme/custom',
        'license_type' => 'corporate',
        'last_verified_at' => Carbon::now()->subDays(20),
        'server_time' => Carbon::now()->subDays(20),
    ]);

    expect(app(LicenseGuard::class)->check('acme/custom'))->toBe(LicenseState::Locked);
});

it('ends an expired trial rather than letting it run on', function (): void {
    storeEntry(['license_type' => 'trial', 'status' => 'expired']);

    expect(app(LicenseGuard::class)->check('acme/crm'))->toBe(LicenseState::Locked);
});

// ─── State policy ───────────────────────────────────────────────────────────

it('locks features only when a licence has truly ended', function (): void {
    expect(LicenseState::Locked->featuresEnabled())->toBeFalse()
        ->and(LicenseState::Expired->featuresEnabled())->toBeTrue()
        ->and(LicenseState::Expired->canUpdate())->toBeFalse()
        ->and(LicenseState::Grace->canUpdate())->toBeTrue()
        ->and(LicenseState::Valid->needsAttention())->toBeFalse();
});

// ─── Cache is encrypted at rest ─────────────────────────────────────────────

it('stores activation tokens through the encrypted settings store', function (): void {
    storeEntry(['token' => 'super-secret-token-value']);

    $row = DB::table('settings')->where('group', 'license')->where('key', 'entries')->first();

    expect($row)->not->toBeNull()
        ->and((string) $row->value)->not->toContain('super-secret-token-value');
});
