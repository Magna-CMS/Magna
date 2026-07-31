<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Magna\Auth\AuthServiceProvider;

/**
 * A production install served over plain HTTP must still be signable-in.
 *
 * The session cookie's Secure flag used to be forced on for every production
 * request once the installer had finished. Browsers discard a Secure cookie
 * that arrives from an http:// origin, so the install completed and then no
 * one could ever log in: each attempt authenticated, wrote a session row with
 * the user id, lost the cookie, and redirected back to the form — with nothing
 * in the log, because nothing threw.
 */
it('does not force the secure cookie flag on a plain HTTP production request', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    // The test app's default request is built from APP_URL, which is https —
    // so this has to be stated explicitly or the plain-HTTP case is never
    // actually exercised and the assertion passes for the wrong reason.
    $this->app['request'] = Request::create('http://example.test/login');

    app()->register(AuthServiceProvider::class, force: true);

    expect(config('session.secure'))->not->toBeTrue();
});

it('forces the secure cookie flag when the production request is over TLS', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['session.secure' => null]);

    // https:// request — what a real TLS deployment always looks like.
    $this->app['request'] = Request::create('https://example.test/login');

    app()->register(AuthServiceProvider::class, force: true);

    expect(config('session.secure'))->toBeTrue();
});

it('still honours an explicit SESSION_SECURE_COOKIE opt-in', function (): void {
    // An operator terminating TLS upstream sets this so the flag survives even
    // though the PHP process only ever sees plaintext requests.
    config(['session.secure' => true]);

    app()->detectEnvironment(fn (): string => 'production');
    app()->register(AuthServiceProvider::class, force: true);

    expect(config('session.secure'))->toBeTrue();
});
