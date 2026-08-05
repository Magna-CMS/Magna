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

it('has no plugin deriving its own install fingerprint', function (): void {
    $files = pluginSourceFiles();

    if ($files === []) {
        test()->markTestSkipped('No plugins-dev plugins in this checkout.');
    }

    $offenders = [];

    foreach ($files as $path) {
        // Comments must not count either way: this guard first read its own
        // explanatory docblock as if it were an implementation.
        $source = phpCodeWithoutComments((string) file_get_contents($path));

        // The client-side tell: hashing the application key into an identity.
        // (The marketplace plugin is the server half — it receives
        // fingerprints and hashes tokens, which is a different thing.)
        if (! str_contains($source, 'app.key')) {
            continue;
        }

        if (! preg_match('/\b(hash|md5|sha1|crc32)\s*\(/', $source)) {
            continue;
        }

        // Actual delegation, not a docblock that merely names the class —
        // that distinction matters: this guard was fooled by its own comment.
        if (preg_match('/InstallFingerprint::derive\s*\(/', $source)) {
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
