<?php

declare(strict_types=1);

namespace Magna\Delivery\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Magna\Content\Entry;
use Magna\Content\SchemaRegistry;
use Magna\Delivery\PreviewTokenService;

final class PreviewTokenController extends Controller
{
    /**
     * S1-18: preview tokens are stateless HMACs — once minted, they cannot
     * be individually revoked before they expire. Without an upper bound, a
     * management-scope caller could request an effectively decades-long
     * "ttl_seconds" and end up with a non-revocable draft-content-access
     * token. 7 days comfortably covers real preview/review workflows.
     */
    private const MAX_TTL_SECONDS = 604_800;

    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly PreviewTokenService $previewTokens,
    ) {}

    public function __invoke(Request $request, string $type, string $id): JsonResponse
    {
        // A preview token IS draft read access to this type for whoever holds
        // it, so minting one demands the same ability that gates reading the
        // entry through the management API. Every other management endpoint
        // authorizes; this one silently didn't, which let any
        // management-scope token — whatever its holder was actually permitted
        // to see — mint week-long, non-revocable draft access.
        Gate::authorize("content.{$type}.view");

        $contentType = $this->schema->get($type);
        if ($contentType === null) {
            return response()->json(['message' => "Content type '{$type}' not found."], 404);
        }

        $entry = Entry::type($type)->where('id', $id)->first();
        if ($entry === null) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        $ttlRaw = $request->input('ttl_seconds', 3600);
        $ttl = min(self::MAX_TTL_SECONDS, max(1, is_numeric($ttlRaw) ? (int) $ttlRaw : 3600));
        $token = $this->previewTokens->mint($entry->id, $type, $ttl);

        return response()->json([
            'token' => $token,
            'entry_id' => $entry->id,
            'entry_type' => $type,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ]);
    }
}
