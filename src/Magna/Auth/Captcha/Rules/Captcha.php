<?php

declare(strict_types=1);

namespace Magna\Auth\Captcha\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Magna\Contracts\CaptchaAttempt;
use Magna\Contracts\CaptchaProvider;

/**
 * Validation rule that verifies a captcha token for a server-authored surface.
 *
 * The single integration point for JSON/SPA logins (Roya ERP, Embhas, …):
 *
 *   'captcha_token' => [new \Magna\Auth\Captcha\Rules\Captcha('roya.erp.login')],
 *
 * Lives in core, not the SDK, because it resolves the bound CaptchaProvider
 * from the container — the dependency-free SDK cannot. Every consumer already
 * requires core at runtime, and core is not the Defence plugin, so a site
 * without any captcha plugin still resolves the null provider and this rule
 * passes silently.
 *
 * Invariant (see docs): only attach this rule to a route that carries
 * route-level rate limiting. Validation runs before any service-level throttle,
 * so without a route limiter a locked-out attacker would burn one provider
 * round-trip per request.
 */
final class Captcha implements ValidationRule
{
    /** Turnstile tokens are ~2KB; reject anything larger before any network call. */
    private const MAX_TOKEN_BYTES = 2048;

    public function __construct(private readonly string $surface) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var CaptchaProvider $provider */
        $provider = app(CaptchaProvider::class);

        // Fail open: an unconfigured or disabled provider never blocks a login.
        if (! $provider->enabled()) {
            return;
        }

        $token = is_string($value) ? $value : null;

        if ($token !== null && strlen($token) > self::MAX_TOKEN_BYTES) {
            $fail(__('Verification failed. Please try again.'));

            return;
        }

        $result = $provider->verify(new CaptchaAttempt($token, $this->surface, request()->ip()));

        if (! $result->passed) {
            // Generic message on purpose — the provider's error codes are an
            // oracle for tuning bypasses and are logged server-side only.
            $fail(__('Verification failed. Please try again.'));
        }
    }
}
