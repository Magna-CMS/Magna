<?php

declare(strict_types=1);

/**
 * A provisioned worktree can run the gates it was created to run.
 *
 * Both properties below were paid for by a build that got 40 minutes in before
 * failing, in a worktree, on something the provisioning had put there:
 *
 *   - the copied composer.lock carries a `content-hash` of composer.json, and
 *     the provisioning rewrote composer.json's path repositories without
 *     recomputing it, so `composer validate` — gate 1 of 7 — failed by
 *     construction;
 *   - wiring the plugins without copying them leaves phpunit.xml's
 *     FirstPartyPlugins directories missing (Pest aborts before its first
 *     test) and leaves vendor/magna-cms/* symlinked outside the worktree root,
 *     where PluginSource::resolve correctly refuses it and
 *     CorePluginManagerListingTest fails.
 *
 * Asserted against the script's text, like ReleaseGatesOrderTest: executing it
 * means a git worktree and a full composer install, which is not a unit test.
 */
it('recomputes the lock content-hash for the manifest it rewrote', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/session-worktree.php');

    expect($script)->toContain('function worktree_content_hash(')
        ->toContain('worktree_write($target.\'/composer.lock\', $patched);')
        // Deleting the lock is the fix that looks obvious and does not work:
        // Composer then re-resolves, and a copied plugin without its own .git
        // inherits the detached root's dev-<sha>, which no `dev-main`
        // constraint matches.
        ->not->toContain("@unlink(\$target.'/composer.lock')");
});

/**
 * The reimplementation is only as good as its agreement with Composer, so the
 * script checks itself against the main checkout's real composer.json/lock pair
 * before touching the worktree's, and leaves the lock alone when it cannot
 * reproduce the known-good value. This test is that this check exists; the
 * algorithm itself was verified against the live pair when it was written.
 */
it('verifies its own hash algorithm against a known-good pair first', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/session-worktree.php');

    expect($script)->toContain('$mainHash = worktree_content_hash($mainJson);')
        ->toContain('$match[1] === $mainHash')
        ->toContain('could not reproduce');
});

it('brings the plugin sources along with the plugin wiring', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/session-worktree.php');

    expect($script)->toContain('if ($withWiring && $pluginVendors === null) {')
        ->toContain("\$pluginVendors = ['magna'];");
});

it('names the first-party suite the plugin copies exist for', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/session-worktree.php');

    // The closing hint is the only instruction most sessions read.
    expect($script)->toContain('--testsuite=Unit,Feature,FirstPartyPlugins');
});
