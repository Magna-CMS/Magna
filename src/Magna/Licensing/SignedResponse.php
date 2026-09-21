<?php

declare(strict_types=1);

namespace Magna\Licensing;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Turns one response from the licence server into a verified payload.
 *
 * Two rules live here because they are halves of the same decision.
 *
 * A failed signature is indistinguishable from an outage, deliberately (see
 * LicenseClient's class docblock): a forged response must never read as a
 * refusal, or anyone able to answer for the server could lock a paying
 * customer out of software they own.
 *
 * A refusal the server put its name to is the opposite case. It carries no
 * licence data, so it is not signed — and it was therefore discarded along
 * with its message, which is why every refusal reached the admin as "the
 * licence server did not authorise this install". A full licence refuses
 * with the domains holding its seats; surfacing that is the difference
 * between a problem the customer can fix and a support ticket.
 */
final class SignedResponse
{
    /**
     * @return array<string, mixed>|null the verified payload, or null when
     *                                   the server could not be reached, said
     *                                   nothing useful, or answered with
     *                                   something that failed verification
     *
     * @throws RuntimeException when the server refused and explained why
     */
    public static function open(?Response $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if (! $response->successful()) {
            $message = $json['message'] ?? null;

            if (is_string($message) && $message !== '') {
                throw new RuntimeException($message);
            }

            return null;
        }

        return $json === [] ? null : SignedPayload::open($json);
    }
}
