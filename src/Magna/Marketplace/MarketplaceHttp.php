<?php

declare(strict_types=1);

namespace Magna\Marketplace;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The one tail every hub API call shares: run the request, and yield its
 * JSON body only when the response succeeded and the body decodes to an
 * array — null for everything else, network failures included. Four clients
 * (AccountCentre x2, UpdateCheckClient, MarketplaceClient) each carried a
 * verbatim copy of this try/successful/json/is_array ladder; the request
 * building stays with each caller, where the differences actually are.
 */
final class MarketplaceHttp
{
    /**
     * @param  callable(): Response  $request
     * @return array<array-key, mixed>|null
     */
    public static function jsonOrNull(callable $request): ?array
    {
        try {
            $response = $request();

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (Throwable) {
            return null;
        }
    }
}
