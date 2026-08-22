<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Magna\AccountCentre\AccountCentreSettings;
use Magna\Licensing\LicenseEntry;
use Magna\Licensing\LicenseGuard;
use Magna\Licensing\LicenseInstaller;
use Magna\Licensing\LicenseState;
use Magna\Licensing\LicenseStore;
use Magna\Licensing\TokenVerifier;

/**
 * A refused activation token repairs itself from the connected account.
 *
 * Found on a live installation. The marketplace stopped recognising a site's
 * token; every daily heartbeat for four days got the refusal, read it as an
 * outage — verify() answered the same null for both — and settled into the
 * grace window. Nothing logged, nothing on any screen, and the reactivator
 * that could have minted a fresh token in one call had NO call sites at all:
 * the self-repair this architecture promises existed only as an unwired
 * class. The operator found out when an update failed with
 *
 *   The licence server did not authorise a download for embhas/embhas:
 *   Activation token invalid or revoked. (invalid_token)
 *
 * and the fix was a manual ritual — re-typing a licence key entered weeks
 * before. These tests pin the two repairs: the heartbeat repairs on refusal,
 * and the update path repairs and retries before showing anybody an error.
 *
 * An outage must NOT trigger repair: a down marketplace is covered by grace,
 * and a site must not spend its hourly repair attempt on a network blip. A
 * dead licence must not trigger it either — re-activating cannot resurrect
 * an expired entitlement, and pretending otherwise papers over a fact the
 * operator needs to see.
 */
beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->signingSecret = sodium_crypto_sign_secretkey($pair);
    config(['magna.licensing.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

    $settings = AccountCentreSettings::get();
    $settings->connected = true;
    $settings->token = 'site-account-token';
    $settings->save();
});

/** The signed envelope the marketplace returns. */
function repairEnvelope(array $payload, string $secret): array
{
    $data = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

    return [
        'data' => $data,
        'signature' => base64_encode(sodium_crypto_sign_detached($data, $secret)),
        'algorithm' => 'ed25519',
    ];
}

function repairEntry(string $token = 'stale-token-0000'): LicenseEntry
{
    $entry = new LicenseEntry(
        productSlug: 'embhas/embhas',
        token: $token,
        status: 'active',
        licenseType: 'corporate',
        expiresAt: null,
        updateEntitled: true,
        serverTime: Carbon::now()->subDays(2),
        lastVerifiedAt: Carbon::now()->subDays(2),
    );

    app(LicenseStore::class)->put($entry);

    return $entry;
}

function repairWallet(): array
{
    return ['licenses' => [[
        'id' => 11,
        'product_slug' => 'embhas/embhas',
        'licensable_type' => 'plugin',
        'license_type' => 'corporate',
        'status' => 'active',
    ]]];
}

it('replaces a refused token from the connected account on the heartbeat', function (): void {
    $entry = repairEntry();

    Http::fake([
        // The refusal the live site sat on for four days.
        '*/license/verify' => Http::sequence()
            ->push(['error' => 'invalid_token', 'message' => 'Activation token invalid or revoked.'], 401)
            // Verification of the FRESH token succeeds.
            ->push(repairEnvelope([
                'valid' => true,
                'status' => 'active',
                'license_type' => 'corporate',
                'update_entitled' => true,
                'server_time' => Carbon::now()->toIso8601String(),
            ], $this->signingSecret)),
        '*/account/licenses/11/install' => Http::response(repairEnvelope([
            'token' => 'fresh-token-1111',
            'license' => ['status' => 'active', 'license_type' => 'corporate'],
            'server_time' => Carbon::now()->toIso8601String(),
        ], $this->signingSecret)),
        '*/account/licenses' => Http::response(repairWallet()),
    ]);

    $state = app(LicenseGuard::class)->check('embhas/embhas');

    $stored = app(LicenseStore::class)->get('embhas/embhas');

    expect($state)->toBe(LicenseState::Valid)
        ->and($stored?->token)->toBe('fresh-token-1111');
});

it('leaves the token alone on an outage', function (): void {
    repairEntry();

    Http::fake([
        '*/license/verify' => Http::response(null, 500),
        '*' => Http::response(['unexpected' => true], 500),
    ]);

    $state = app(LicenseGuard::class)->check('embhas/embhas');

    // Grace, exactly as before — and no wallet or install call was spent on
    // what waiting will fix.
    expect($state)->toBe(LicenseState::Grace)
        ->and(app(LicenseStore::class)->get('embhas/embhas')?->token)->toBe('stale-token-0000');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/account/licenses'));
});

it('does not try to repair a licence the marketplace says is dead', function (): void {
    repairEntry();

    Http::fake([
        '*/license/verify' => Http::response(['error' => 'license_expired', 'message' => 'This license has expired.'], 403),
        '*' => Http::response(['unexpected' => true], 500),
    ]);

    app(LicenseGuard::class)->check('embhas/embhas');

    // Re-activating cannot resurrect an expired entitlement; attempting it
    // would only hide the state the operator has to act on.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/account/licenses'));
});

it('repairs the token and retries when an update download is refused', function (): void {
    repairEntry();

    Http::fake([
        // First grant with the stale token: the exact refusal from the live
        // site. Second, with the fresh token: a refusal again — what matters
        // here is WHICH token the retry presented, not a full install.
        '*/license/download-url' => Http::sequence()
            ->push(['error' => 'invalid_token', 'message' => 'Activation token invalid or revoked.'], 401)
            ->push(['error' => 'package_unavailable', 'message' => 'No downloadable package.'], 404),
        '*/account/licenses/11/install' => Http::response(repairEnvelope([
            'token' => 'fresh-token-1111',
            'license' => ['status' => 'active', 'license_type' => 'corporate'],
            'server_time' => Carbon::now()->toIso8601String(),
        ], $this->signingSecret)),
        '*/account/licenses' => Http::response(repairWallet()),
    ]);

    try {
        app(LicenseInstaller::class)->update('embhas/embhas');
    } catch (RuntimeException $e) {
        // The second refusal is expected — and it must be the SECOND reason,
        // proving the retry happened with the repaired token rather than the
        // original refusal being rethrown.
        expect($e->getMessage())->toContain('package_unavailable');
    }

    $sent = [];
    Http::assertSent(function ($request) use (&$sent): bool {
        if (str_contains($request->url(), '/license/download-url')) {
            $sent[] = $request['token'];
        }

        return true;
    });

    expect($sent)->toBe(['stale-token-0000', 'fresh-token-1111'])
        ->and(app(LicenseStore::class)->get('embhas/embhas')?->token)->toBe('fresh-token-1111');
});

it('does not repair-and-retry an update refused for entitlement reasons', function (): void {
    repairEntry();

    Http::fake([
        '*/license/download-url' => Http::response(['error' => 'license_expired', 'message' => 'This license has expired.'], 403),
        '*' => Http::response(['unexpected' => true], 500),
    ]);

    expect(fn () => app(LicenseInstaller::class)->update('embhas/embhas'))
        ->toThrow(RuntimeException::class, 'license_expired');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/account/licenses'));
});

it('distinguishes refusal from outage in the verifier', function (): void {
    Http::fake(['*/license/verify' => Http::sequence()
        ->push(['error' => 'invalid_token'], 401)
        ->push(null, 500)
        // A signed-looking body that fails verification: a forged refusal
        // must not be able to talk a site into abandoning its token.
        ->push(['data' => base64_encode('{}'), 'signature' => base64_encode('x'), 'algorithm' => 'ed25519']),
    ]);

    $verifier = new TokenVerifier;

    $refused = $verifier->outcome('t');
    expect($refused->refused())->toBeTrue()
        ->and($refused->repairable())->toBeTrue()
        ->and($refused->outage())->toBeFalse();

    $outage = $verifier->outcome('t');
    expect($outage->outage())->toBeTrue()
        ->and($outage->refused())->toBeFalse();

    $forged = $verifier->outcome('t');
    expect($forged->outage())->toBeTrue();
});
