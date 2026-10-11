<?php

declare(strict_types=1);

/**
 * The entry editor and the entry list both open a "Revision History" modal
 * whose body is the magna::admin.entry-revisions view.
 *
 * That view did not exist. Neither call site had ever rendered — clicking
 * Revisions on either screen threw "View [magna::admin.entry-revisions] not
 * found" — and nothing failed, because nothing rendered the modal body in a
 * test and the static analyser of the day accepted any string as a view name.
 * It surfaced only when a newer larastan began resolving view names against
 * the views that actually exist, and reported the name as not being a
 * view-string.
 *
 * So the guard is deliberately the cheap, obvious one that was missing:
 * render it. A view that cannot be rendered cannot pass this.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Magna\Content\Models\Revision;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRevision(string $entryId, string $kind, ?string $label = null, int $minutesAgo = 0): Revision
{
    $revision = new Revision([
        'entry_type' => 'article',
        'entry_id' => $entryId,
        'payload' => ['title' => 'Something'],
        'kind' => $kind,
        'label' => $label,
        'schema_version' => '3',
        'author_id' => null,
    ]);

    $revision->created_at = now()->subMinutes($minutesAgo);
    $revision->save();

    return $revision;
}

it('renders the revision history modal body', function (): void {
    makeRevision('entry-1', Revision::KIND_PUBLISH, minutesAgo: 1);
    makeRevision('entry-1', Revision::KIND_SAVE, minutesAgo: 2);
    makeRevision('entry-1', Revision::KIND_RESTORE_POINT, 'Before the rewrite', 3);

    $html = view('magna::admin.entry-revisions', [
        'revisions' => Revision::historyFor('article', 'entry-1'),
    ])->render();

    expect($html)->toContain('Published')
        ->toContain('Saved')
        // A label wins over the kind's own wording, which is the whole point of
        // naming a restore point.
        ->toContain('Before the rewrite')
        ->toContain('schema 3');
});

it('renders an empty history without failing', function (): void {
    $html = view('magna::admin.entry-revisions', [
        'revisions' => Revision::historyFor('article', 'no-such-entry'),
    ])->render();

    expect($html)->toContain('No revisions recorded for this entry yet.');
});

it('names an unknown kind rather than dropping the row', function (): void {
    makeRevision('entry-2', 'imported');

    $html = view('magna::admin.entry-revisions', [
        'revisions' => Revision::historyFor('article', 'entry-2'),
    ])->render();

    expect($html)->toContain('imported');
});

it('returns one entry history newest first, capped', function (): void {
    foreach (range(1, 4) as $i) {
        makeRevision('entry-3', Revision::KIND_SAVE, "save {$i}", 10 - $i);
    }

    // A second entry's revisions must not leak into the first entry's history.
    makeRevision('entry-4', Revision::KIND_SAVE, 'other entry');

    $history = Revision::historyFor('article', 'entry-3', limit: 3);

    expect($history->pluck('label')->all())->toBe(['save 4', 'save 3', 'save 2']);
});
