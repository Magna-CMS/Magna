<?php

declare(strict_types=1);

use Magna\Auth\Captcha\Rules\Captcha;
use Magna\Captcha\Testing\FakeCaptcha;
use Magna\Contracts\CaptchaProvider;

/**
 * @return array{failed: bool}
 */
function runCaptchaRule(CaptchaProvider $provider, mixed $token): array
{
    app()->instance(CaptchaProvider::class, $provider);

    $failed = false;
    (new Captcha('roya.erp.login'))->validate(
        'captcha_token',
        $token,
        function () use (&$failed) {
            $failed = true;
        },
    );

    return ['failed' => $failed];
}

it('passes silently when no provider is configured', function () {
    expect(runCaptchaRule((new FakeCaptcha)->disabled(), 'token')['failed'])->toBeFalse();
});

it('passes when the provider verifies the token', function () {
    expect(runCaptchaRule(new FakeCaptcha, 'token')['failed'])->toBeFalse();
});

it('fails when an enabled provider rejects the token', function () {
    expect(runCaptchaRule((new FakeCaptcha)->shouldFail(), 'token')['failed'])->toBeTrue();
});

it('fails an oversized token without asking the provider', function () {
    expect(runCaptchaRule(new FakeCaptcha, str_repeat('a', 3000))['failed'])->toBeTrue();
});
