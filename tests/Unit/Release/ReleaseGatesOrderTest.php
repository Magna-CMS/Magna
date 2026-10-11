<?php

declare(strict_types=1);

/**
 * What the release gates run, and in what order.
 *
 * Both properties were paid for. 1.4.7 was built four times and published
 * never; each attempt was refused by a real defect, and every one of those
 * defects lived in core, which means every one of them was reachable in
 * minutes. Instead they surfaced after the plugin suites had run — twice after
 * more than two hours. So there are two suite gates now, and the cheap one has
 * to come first or it is not a pre-flight at all, merely a second full run.
 *
 * Then the scope. A 1.4.8 build was refused by 660 failures that were all
 * `lekha_test` on a Postgres server that was not running — a different product,
 * reached through the `Plugins` glob over everything in plugins-dev. A core
 * release cannot answer for that, so the gate names the plugins core ships
 * alongside instead.
 *
 * Asserted against the script's text, the way RootForwarderTest pins the
 * forwarder heredoc: the gate list is a literal inside a function, and reading
 * it is cheaper and more honest than executing a release build to find out.
 */
it('runs the cheap core suite before the complete one', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/support/release-gates.php');

    $fast = strpos($script, "'core suite (fast)' =>");
    $full = strpos($script, "'full test suite' =>");

    expect($fast)->not->toBeFalse('The fast core suite gate is gone.')
        ->and($full)->not->toBeFalse('The full test suite gate is gone.')
        ->and($fast)->toBeLessThan($full);
});

it('scopes the fast gate to core and the full gate away from other products', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/support/release-gates.php');

    expect($script)->toContain("'core suite (fast)' => \$lock.' '.\$php.' artisan test --testsuite=Unit,Feature'")
        ->toContain('--testsuite=Unit,Feature,FirstPartyPlugins')
        // A bare `artisan test` would take the Plugins glob back, and with it
        // every client product under plugins-dev.
        ->not->toContain("\$php.' artisan test',");
});

it('puts both suite gates behind the machine-wide lock', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/bin/support/release-gates.php');

    $lock = strpos($script, 'suite-lock.php');

    expect($lock)->not->toBeFalse('The suite gates no longer take the lock.')
        ->and($lock)->toBeLessThan((int) strpos($script, "'core suite (fast)' =>"));
});

it('keeps the release suite a named list, never a glob', function (): void {
    $phpunit = (string) file_get_contents(dirname(__DIR__, 3).'/phpunit.xml');

    $start = strpos($phpunit, '<testsuite name="FirstPartyPlugins">');

    expect($start)->not->toBeFalse('The FirstPartyPlugins suite is gone; the release gate has nothing to point at.');

    /** @var int $start */
    $end = (int) strpos($phpunit, '</testsuite>', $start);
    $body = substr($phpunit, $start, $end - $start);

    // The whole point of naming them: a client product dropped under
    // plugins-dev must not be able to start gating core releases by existing.
    expect($body)->not->toContain('*')
        ->and($body)->toContain('plugins-dev/magna/marketplace/tests');

    foreach (['lekha', 'roya', 'embhas', 'restaurant-finance'] as $separateProduct) {
        expect($body)->not->toContain($separateProduct);
    }
});
