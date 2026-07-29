<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Magna\Webhooks\Http\Resources\WebhookSubscriptionResource;
use Magna\Webhooks\Support\NotPrivateUrlRule;
use Magna\Webhooks\WebhookSubscription;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends ManagementController
{
    /** All event keys the system can emit (core + any plugin-registered). */
    public const CORE_EVENTS = [
        'entry.created',
        'entry.published',
        'entry.updated',
        'entry.unpublished',
        'entry.deleted',
        'media.created',
        'media.deleted',
    ];

    public function index(): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        /** @var Collection<int, WebhookSubscription> $subs */
        $subs = WebhookSubscription::query()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => WebhookSubscriptionResource::collection($subs),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048', new NotPrivateUrlRule],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $sub = WebhookSubscription::create([
            'url' => $validated['url'],
            'secret' => Str::random(32),
            'events' => $validated['events'],
            'description' => $validated['description'] ?? null,
            'active' => true,
        ]);

        return response()->json(['data' => WebhookSubscriptionResource::make($sub)], 201);
    }

    public function show(string $webhook): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $sub = $this->findOrFail(WebhookSubscription::query(), $webhook, 'Webhook');

        return response()->json(['data' => WebhookSubscriptionResource::make($sub)]);
    }

    public function update(Request $request, string $webhook): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $sub = $this->findOrFail(WebhookSubscription::query(), $webhook, 'Webhook');

        $validated = $request->validate([
            'url' => ['sometimes', 'url', 'max:2048', new NotPrivateUrlRule],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $sub->fill($validated);
        $sub->save();

        return response()->json(['data' => WebhookSubscriptionResource::make($sub)]);
    }

    public function destroy(string $webhook): Response
    {
        Gate::authorize('webhooks.manage');

        $sub = $this->findOrFail(WebhookSubscription::query(), $webhook, 'Webhook');

        $sub->delete();

        return response()->noContent();
    }
}
