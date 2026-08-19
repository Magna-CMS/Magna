<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
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

/*
 * The rule has to be implicit, or it is not a gate at all. Consumers attach it
 * as ['nullable', 'string', new Captcha(...)] so that a site with captcha
 * disabled accepts requests without the field — but Laravel skips non-implicit
 * rules entirely for a missing or null attribute. Without implicitness, a bot
 * that simply OMITS captcha_token from the POST body bypasses verification
 * while every honest browser solves a challenge.
 */
it('still runs when the field is missing from the request entirely', function () {
    $provider = (new FakeCaptcha)->shouldFail();
    app()->instance(CaptchaProvider::class, $provider);

    $validator = Validator::make(
        [], // no captcha_token at all — the bypass shape
        ['captcha_token' => ['nullable', 'string', new Captcha('roya.erp.login')]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($provider->wasVerified('roya.erp.login'))->toBeTrue();
});

it('accepts a missing field when the provider is disabled', function () {
    app()->instance(CaptchaProvider::class, (new FakeCaptcha)->disabled());

    $validator = Validator::make(
        [],
        ['captcha_token' => ['nullable', 'string', new Captcha('roya.erp.login')]],
    );

    expect($validator->fails())->toBeFalse();
});
