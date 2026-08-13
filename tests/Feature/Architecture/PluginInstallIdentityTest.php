<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

// Every install has exactly one identity: Magna\Support\InstallFingerprint.
// Account Centre registers the site with the marketplace under it, and the
// marketplace resolves an owned licence against the account connected to THAT
// fingerprint. A plugin that derives its own value is checked against a row
// that cannot exist, so activating a corporate licence on a correctly
// connected site fails with
//
//     This license is assigned to a different Magna Account.
//
// roya/erp shipped exactly that bug (a private sha256('roya-license|'.$key)),
// and no amount of reconnecting the account could fix it from the outside.

/** @return list<string> */
function pluginSourceFiles(): array
{
    $root = base_path('plugins-dev');

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        // Tests may name the discouraged derivation to assert it is gone.
        if (str_contains($path, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $files[] = $path;
    }

    return $files;
}

/** The executable half of a PHP file — comments dropped. */
function phpCodeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * Whether this source derives an install identity itself.
 *
 * Extracted from the sweep so the RULE can be tested directly: a guard
 * that only ever runs over whatever happens to be in plugins-dev is a
 * guard whose precision nobody can check.
 */
function derivesInstallIdentity(string $php): bool
{
    $source = phpCodeWithoutComments($php);

    // The client-side tell: hashing the application key into an identity.
    // (The marketplace plugin is the server half — it receives fingerprints
    // and hashes tokens, which is a different thing.)
    if (! str_contains($source, 'app.key')) {
        return false;
    }

    // Actual delegation, not a docblock that merely names the class — that
    // distinction matters: this guard was once fooled by its own comment.
    if (preg_match('/InstallFingerprint::derive\s*\(/', $source) === 1) {
        return false;
    }

    // HMAC is SIGNING, not identity: the key is a parameter by construction
    // and what comes out is per-message. A plugin signing its own OAuth
    // state with the app key uses Laravel's key the way Laravel intends,
    // and flagging it taught the only lesson a false positive ever teaches —
    // how to ignore the guard.
    $withoutSigning = (string) preg_replace('/\bhash_hmac\s*\(/', 'SIGNING(', $source);

    // What is left is an identity tell only when the app key is among what
    // is being hashed. Checked over the STATEMENT rather than with one
    // balanced-parenthesis pattern, because the key usually arrives through
    // a call of its own — `hash('sha256', config('app.key').gethostname())`
    // — and a pattern that tried to span that swallowed the key with it.
    foreach (preg_split('/;/', $withoutSigning) ?: [] as $statement) {
        if (preg_match('/\b(hash|md5|sha1|crc32)\s*\(/i', $statement) === 1
            && preg_match('/app\.key|appKey/i', $statement) === 1
        ) {
            return true;
        }
    }

    return false;
}

it('has no plugin deriving its own install fingerprint', function (): void {
    $files = pluginSourceFiles();

    if ($files === []) {
        test()->markTestSkipped('No plugins-dev plugins in this checkout.');
    }

    $offenders = [];

    foreach ($files as $path) {
        if (! derivesInstallIdentity((string) file_get_contents($path))) {
            continue;
        }

        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    expect($offenders)->toBe(
        [],
        'These plugin files derive an install identity themselves. Call '
        .'Magna\Support\InstallFingerprint::derive() instead — the marketplace '
        .'matches licences against the fingerprint Account Centre registered.'
    );
});

/**
 * The guard was narrowed after it flagged a plugin for HMAC-signing its own
 * OAuth state with the app key — signing, not identity. A narrowed guard is
 * worth nothing unless it still catches what it was written for, so both
 * shapes are asserted here directly rather than left to whatever happens to
 * be in plugins-dev today.
 */
it('still catches a plugin hashing the app key into an identity', function (): void {
    $offending = <<<'PHP'
    <?php
    class Fingerprint {
        public function id(): string {
            return hash('sha256', config('app.key').gethostname());
        }
    }
    PHP;

    expect(derivesInstallIdentity($offending))->toBeTrue();
});

it('does not flag a plugin signing with the app key', function (): void {
    $signing = <<<'PHP'
    <?php
    class State {
        public function sign(string $payload): string {
            return hash_hmac('sha256', $payload, (string) config('app.key'));
        }
        public function cacheKey(string $nonce): string {
            return 'plugin:state:'.hash('sha256', $nonce);
        }
    }
    PHP;

    expect(derivesInstallIdentity($signing))->toBeFalse();
});

it('does not flag a plugin that delegates to the shared helper', function (): void {
    $delegating = <<<'PHP'
    <?php
    class Licence {
        public function id(): string {
            return \Magna\Support\InstallFingerprint::derive(config('app.key'));
        }
    }
    PHP;

    expect(derivesInstallIdentity($delegating))->toBeFalse();
});
