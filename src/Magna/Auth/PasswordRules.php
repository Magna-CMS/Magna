<?php

declare(strict_types=1);

namespace Magna\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rules\Password;

/**
 * The one place the password policy is defined.
 *
 * It used to be `min:8` copy-pasted into the registration form and the reset
 * form independently, which is both below baseline for an account that can
 * install code on the server, and the kind of duplication where one copy gets
 * strengthened and the other quietly does not.
 *
 * `uncompromised()` checks the k-anonymity Have I Been Pwned range API: only a
 * 5-character hash prefix leaves the server, never the password. It is opt-out
 * for installs with no outbound network access, where the check would add a
 * timeout to every password change.
 */
final class PasswordRules
{
    public static function defaults(): Password
    {
        $rule = Password::min(Config::integer('magna.password.min_length', 12))
            ->letters()
            ->numbers();

        if (Config::boolean('magna.password.require_symbols', false)) {
            $rule = $rule->symbols();
        }

        if (Config::boolean('magna.password.check_compromised', true)) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }
}
