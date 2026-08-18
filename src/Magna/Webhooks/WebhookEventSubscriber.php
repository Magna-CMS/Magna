<?php

declare(strict_types=1);

namespace Magna\Webhooks;

use Illuminate\Events\Dispatcher;
use Magna\Content\Events\EntryCreated;
use Magna\Content\Events\EntryDeleted;
use Magna\Content\Events\EntryPublished;
use Magna\Content\Events\EntryUnpublished;
use Magna\Content\Events\EntryUpdated;
use Magna\Media\Events\MediaCreated;
use Magna\Media\Events\MediaDeleted;

/**
 * Bridges core content/media events to the webhook delivery pipeline via the
 * shared WebhookDispatcher.
 */
class WebhookEventSubscriber
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            EntryPublished::class => 'handleEntryPublished',
            EntryUpdated::class => 'handleEntryUpdated',
            EntryUnpublished::class => 'handleEntryUnpublished',
            EntryDeleted::class => 'handleEntryDeleted',
            EntryCreated::class => 'handleEntryCreated',
            MediaCreated::class => 'handleMediaCreated',
            MediaDeleted::class => 'handleMediaDeleted',
        ];
    }

    public function handleEntryPublished(EntryPublished $event): void
    {
        $this->dispatch('entry.published', [
            'entry_id' => $event->entry->id,
            'entry_type' => $event->entry->getHandle(),
            'status' => $event->entry->status->value,
        ]);
    }

    public function handleEntryUpdated(EntryUpdated $event): void
    {
        $this->dispatch('entry.updated', [
            'entry_id' => $event->entry->id,
            'entry_type' => $event->entry->getHandle(),
            'status' => $event->entry->status->value,
        ]);
    }

    public function handleEntryUnpublished(EntryUnpublished $event): void
    {
        $this->dispatch('entry.unpublished', [
            'entry_id' => $event->entry->id,
            'entry_type' => $event->entry->getHandle(),
            'status' => $event->entry->status->value,
        ]);
    }

    public function handleEntryDeleted(EntryDeleted $event): void
    {
        $this->dispatch('entry.deleted', [
            'entry_id' => $event->entry->id,
            'entry_type' => $event->entry->getHandle(),
        ]);
    }

    public function handleEntryCreated(EntryCreated $event): void
    {
        $this->dispatch('entry.created', [
            'entry_id' => $event->entry->id,
            'entry_type' => $event->entry->getHandle(),
            'status' => $event->entry->status->value,
        ]);
    }

    public function handleMediaCreated(MediaCreated $event): void
    {
        $this->dispatch('media.created', [
            'media_id' => $event->media->id,
            'mime_type' => $event->media->mime_type,
            'filename' => $event->media->original_filename,
        ]);
    }

    public function handleMediaDeleted(MediaDeleted $event): void
    {
        $this->dispatch('media.deleted', [
            'media_id' => $event->media->id,
            'filename' => $event->media->original_filename,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function dispatch(string $eventKey, array $data): void
    {
        $this->dispatcher->fire($eventKey, $data);
    }
}
