<?php

declare(strict_types=1);

namespace Magna\Content;

use Magna\Audit\AuditLog;
use Magna\Content\Http\Resources\EntryResource;

/**
 * The audit vocabulary of the entry lifecycle: one method per action, each
 * owning the exact snapshot shape that goes into audit_logs.before/after.
 *
 * Extracted from EntryController, where the same AuditLog::record() call
 * shape was written out six times. Centralising it also gives encrypted-
 * field redaction exactly one seam: snapshots are built from EntryResource,
 * which already reads secrets back as their placeholder — no caller can
 * forget that and archive a plaintext secret again.
 */
final class EntryAuditRecorder
{
    public function created(Entry $entry, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.created',
            actorId: $actorId,
            ip: $ip,
            subject: $entry,
            after: $this->snapshot($entry),
        );
    }

    /** @param array<string, mixed> $before */
    public function updated(Entry $entry, array $before, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.updated',
            actorId: $actorId,
            ip: $ip,
            subject: $entry,
            before: $before,
            after: $this->snapshot($entry),
        );
    }

    /** @param array<string, mixed> $before */
    public function deleted(array $before, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.deleted',
            actorId: $actorId,
            ip: $ip,
            before: $before,
        );
    }

    public function published(Entry $entry, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.published',
            actorId: $actorId,
            ip: $ip,
            subject: $entry,
            after: [
                'status' => $entry->status->value,
                'published_at' => $entry->published_at?->toIso8601String(),
            ],
        );
    }

    public function unpublished(Entry $entry, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.unpublished',
            actorId: $actorId,
            ip: $ip,
            subject: $entry,
        );
    }

    public function restored(Entry $entry, string $revisionId, ?string $actorId, ?string $ip): void
    {
        AuditLog::record(
            action: 'entry.restored',
            actorId: $actorId,
            ip: $ip,
            subject: $entry,
            after: ['restored_from_revision' => $revisionId],
        );
    }

    /**
     * The before/after payload: the entry as the management API serialises
     * it — encrypted fields redacted, reserved block keys stripped.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Entry $entry): array
    {
        return EntryResource::make($entry)->resolve();
    }
}
