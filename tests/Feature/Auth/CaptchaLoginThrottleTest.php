<?php

declare(strict_types=1);

use Magna\Auth\LoginThrottle;

it('counts a captcha failure against the IP scope only, never the identity', function () {
    config(['magna.login.max_attempts' => 5]);

    // Exactly max_attempts captcha failures for one identity. If hitIp() also
    // touched the identity scope, this would lock the account — the exact
    // denial-of-service (lock out a known admin by spamming garbage tokens)
    // that keeping captcha failures on the IP scope prevents. The IP ceiling is
    // 4x higher, so five hits never trips it either.
    $throttle = new LoginThrottle;
    for ($i = 0; $i < 5; $i++) {
        $throttle->hitIp('victim@example.com');
    }

    expect($throttle->isLocked('victim@example.com'))->toBeFalse();
});

it('still locks the identity on ordinary credential failures', function () {
    config(['magna.login.max_attempts' => 5]);

    $throttle = new LoginThrottle;
    for ($i = 0; $i < 5; $i++) {
        $throttle->hit('someone@example.com');
    }

    expect($throttle->isLocked('someone@example.com'))->toBeTrue();
});
