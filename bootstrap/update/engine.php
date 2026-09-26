<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Magna release engine — API 1
|--------------------------------------------------------------------------
|
| This file travels inside every release archive and is executed by the
| updater the site ALREADY runs, after that updater has verified the archive,
| read its manifest, and built the context below. It is how the NEW release
| decides what an update does, instead of the old one remembering.
|
| Rules, enforced by Magna\Updater\Engine\EngineLoader before it is required:
| a closure only — no class, function, interface, trait, enum or namespace
| declaration, because the old side's own classes are already loaded; no
| Magna\ symbol, because that would resolve to the OLD release's copy; no
| include, eval, shell or direct filesystem call, because everything the
| engine may do is on `$ctx`. Its sha256 is stated in the manifest.
|
| $ctx is Magna\Updater\Engine\EngineContext (API 1): manifest(), refuse(),
| stage(), beginSwitch(), swap(), remove(), preserveDrop(), vendorStrategy(),
| vendorMatches(), foreignPackages(), foreignRepositories(),
| composerAvailable(), recordVendorDecision(), stageVendor(), swapVendor(),
| composerRequire(), writeFootprintDraft(), markFinalizePending(),
| progress(), log().
*/

return static function (object $ctx): void {
    $manifest = $ctx->manifest();
    $paths = is_array($manifest['paths'] ?? null) ? $manifest['paths'] : [];
    $owned = is_array($paths['core_owned'] ?? null) ? $paths['core_owned'] : [];
    $removed = is_array($paths['removed'] ?? null) ? $paths['removed'] : [];

    // 0. Vendor: the release says what it wants, the site says what it
    //    costs. Decided before anything is staged, so a refusal costs
    //    nothing — the site keeps its release and the admin reads why.
    $replaceVendor = false;
    $putBack = [];

    if ($ctx->vendorStrategy() !== 'replace') {
        $ctx->recordVendorDecision('keep', 'the release leaves vendor/ to the site');
    } elseif ($ctx->vendorMatches()) {
        $ctx->recordVendorDecision('keep', 'the site already holds every package this release was built with');
    } else {
        $putBack = $ctx->foreignPackages();
        $repositories = $ctx->foreignRepositories();
        $replaceVendor = true;

        if ($putBack === []) {
            // The site's composer.json goes with its vendor/. A repository it
            // named that served nothing the release lacks goes with it.
            $ctx->recordVendorDecision('replace', 'nothing in vendor/ is the site\'s own, so the release\'s vendor/ replaces it whole'
                .($repositories !== [] ? '; the site\'s composer.json also named '.implode(', ', $repositories).', which served nothing of its own' : ''));
        } elseif ($repositories !== []) {
            $ctx->refuse(
                'This release replaces core dependencies, and this site asks Composer for packages of its own ('
                .implode(', ', array_keys($putBack)).') through repositories an update cannot carry across ('
                .implode(', ', $repositories).'). Move those plugins to the licensed download path, or remove the repositories, then update again.'
            );
        } elseif ($ctx->composerAvailable()) {
            $ctx->recordVendorDecision('composer', 'the release\'s vendor/ replaces the site\'s, then Composer puts back the site\'s own packages: '.implode(', ', array_keys($putBack)));
        } else {
            $ctx->refuse(
                'This release replaces core dependencies, and this site asks Composer for packages of its own ('
                .implode(', ', array_keys($putBack)).') that replacing vendor/ would drop. Composer is not available here to put them back. '
                .'Install Composer on the server, or move those plugins to the licensed download path, then update again.'
            );
        }
    }

    // 1. Stage every path beside its live counterpart. Slow, and the site
    //    keeps serving throughout.
    $ctx->progress('Staging the release beside the live files…', 64);

    foreach ($owned as $relative) {
        if (is_string($relative)) {
            $ctx->stage($relative);
        }
    }

    if ($replaceVendor) {
        $ctx->progress('Staging the release\'s dependencies…', 68);
        $ctx->stageVendor();
    }

    // 2. The switch: maintenance mode on, then two renames per path. The
    //    displaced directories are the rollback point until the new code
    //    has proven it boots.
    $ctx->progress('Switching to v'.$ctx->toVersion().'…', 74);
    $ctx->beginSwitch();

    foreach ($owned as $relative) {
        if (is_string($relative)) {
            $ctx->swap($relative);
        }
    }

    foreach ($removed as $relative) {
        if (is_string($relative)) {
            $ctx->remove($relative);
        }
    }

    if ($replaceVendor) {
        $ctx->swapVendor();
    }

    if ($putBack !== []) {
        $ctx->progress('Putting the site\'s own packages back with Composer…', 77);
        $ctx->composerRequire($putBack);
    }

    // Compiled caches are regenerated by the new code, never carried over.
    $ctx->preserveDrop('bootstrap/cache');

    // 3. Hand the rest to the release that is now on disk.
    $ctx->writeFootprintDraft();
    $ctx->markFinalizePending(['boot_health', 'migrate', 'plugins', 'clear_caches', 'classmap', 'commit']);
};
