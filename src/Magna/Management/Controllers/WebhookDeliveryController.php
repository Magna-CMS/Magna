<?php

declare(strict_types=1);

namespace Magna\Management\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Magna\Settings\ApiSettings;
use Magna\Webhooks\Http\Resources\WebhookDeliveryResource;
use Magna\Webhooks\Jobs\DispatchWebhookJob;
use Magna\Webhooks\WebhookDelivery;
use Magna\Webhooks\WebhookSubscription;

class WebhookDeliveryController extends ManagementController
{
    public function index(Request $request, string $webhook): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $sub = $this->findOrFail(WebhookSubscription::query(), $webhook, 'Webhook');

        $apiSettings = ApiSettings::get();
        $perPage = min(max($request->integer('per_page', $apiSettings->default_per_page), 1), $apiSettings->max_per_page);
        $paginator = WebhookDelivery::query()
            ->where('subscription_id', $sub->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => WebhookDeliveryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function retry(Request $request, string $webhook, string $delivery): JsonResponse
    {
        Gate::authorize('webhooks.manage');

        $sub = $this->findOrFail(WebhookSubscription::query(), $webhook, 'Webhook');

        $deliveryQuery = WebhookDelivery::query()->where('subscription_id', $sub->id);

        $record = $this->findOrFail($deliveryQuery, $delivery, 'Delivery');

        if ($record->isDelivered()) {
            return response()->json(['message' => 'Delivery already succeeded.'], 422);
        }

        $record->forceFill(['status' => 'pending', 'attempts' => 0])->save();

        DispatchWebhookJob::dispatch($record->id);

        return response()->json(['message' => 'Delivery re-queued.', 'data' => WebhookDeliveryResource::make($record)]);
    }
}
