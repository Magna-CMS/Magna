<?php

declare(strict_types=1);

namespace Magna\Frontend\Forms;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /_magna/form-token — this visitor's CSRF token, for a form rendered
 * on a cached page.
 *
 * A cached page is the same bytes for every guest, so a token baked into
 * it is the first visitor's: everyone after them posts a token their own
 * session never issued and gets a 419. The form renders an empty `_token`
 * instead and fetches the real one from here (<x-magna::form-token/>).
 *
 * Same-origin by construction — no CORS header is sent, so another site
 * cannot read the answer — and never stored by any cache along the way.
 */
final class FormTokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => $request->session()->token()])
            ->setPrivate()
            ->header('Cache-Control', 'no-store, private, max-age=0');
    }
}
