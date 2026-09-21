<?php

declare(strict_types=1);

use Magna\Settings\Attributes\Secret;
use Magna\Settings\SettingValueCodec;

// Tests\TestCase + RefreshDatabase come from the folder mapping in Pest.php.

/*
 * The codec is what makes #[Secret] mean anything: SMTP passwords, S3
 * secrets and API tokens live in the settings table only because this
 * class transparently encrypts them. Zero tests pinned that until now.
 */

final class SettingValueCodecFixture
{
    #[Secret]
    public ?string $token = null;

    public string $plain = '';
}

function codecProp(string $name): ReflectionProperty
{
    return new ReflectionProperty(SettingValueCodecFixture::class, $name);
}

it('encrypts a #[Secret] property at rest and round-trips it', function (): void {
    $codec = new SettingValueCodec;

    $stored = $codec->encode(codecProp('token'), 'hunter2');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain('hunter2')
        ->and($codec->decode(codecProp('token'), $stored))->toBe('hunter2');
});

it('leaves an untagged property exactly as it was', function (): void {
    $codec = new SettingValueCodec;

    expect($codec->encode(codecProp('plain'), 'not-a-secret'))->toBe('not-a-secret')
        ->and($codec->decode(codecProp('plain'), 'not-a-secret'))->toBe('not-a-secret');
});

/*
 * Regression: a secret column holding a value Crypt cannot open — written
 * before the property was tagged #[Secret], or under a rotated APP_KEY —
 * used to throw DecryptException out of decode(), taking every read of the
 * whole settings aggregate down with it. Unreadable means unset: a secret
 * is re-enterable, a 500 on the settings page is not.
 */
it('treats an undecryptable secret as unset instead of failing the read', function (): void {
    $codec = new SettingValueCodec;

    expect($codec->decode(codecProp('token'), 'plaintext-from-before-encryption'))->toBeNull();
});
