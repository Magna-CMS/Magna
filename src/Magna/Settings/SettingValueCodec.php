<?php

declare(strict_types=1);

namespace Magna\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Magna\Settings\Attributes\Secret;
use ReflectionProperty;

/**
 * Encodes/decodes a single Settings property's value for storage.
 * Properties tagged #[Secret] are transparently encrypted at rest.
 */
class SettingValueCodec
{
    public function isSecret(ReflectionProperty $prop): bool
    {
        return $prop->getAttributes(Secret::class) !== [];
    }

    /** Turn a stored (possibly encrypted) raw value back into a PHP value. */
    public function decode(ReflectionProperty $prop, mixed $rawValue): mixed
    {
        if ($this->isSecret($prop) && is_string($rawValue)) {
            try {
                return json_decode(Crypt::decryptString($rawValue), true);
            } catch (DecryptException) {
                // A stored value that cannot be decrypted — written before
                // the property was tagged #[Secret], or under a rotated
                // APP_KEY — must not take every read of the whole settings
                // aggregate down with it. A secret is re-enterable:
                // unreadable means unset, never a 500.
                return null;
            }
        }

        return $rawValue;
    }

    /** Turn a PHP value into the form that should be written to storage. */
    public function encode(ReflectionProperty $prop, mixed $phpValue): mixed
    {
        if ($this->isSecret($prop)) {
            return Crypt::encryptString(json_encode($phpValue, JSON_THROW_ON_ERROR));
        }

        return $phpValue;
    }
}
