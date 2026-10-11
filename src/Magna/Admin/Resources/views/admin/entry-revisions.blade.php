{{--
    Revision history for one entry, opened as a modal from the entry list and
    from the entry editor. Read-only: revisions are append-only (see the
    Revision model, which refuses update and delete), and restoring one goes
    through EntryManager rather than this screen.

    Both call sites pass `revisions` already loaded — the view never queries,
    and that is the only thing it is given: resolving an entry's display title
    is logic that lives in the resource, not here.

    Each kind carries its colour classes written out in full rather than
    composed from a tone name. Tailwind builds its stylesheet by scanning
    source text, so a class assembled at runtime ("bg-{$tone}-100") is never
    emitted and the badge renders colourless.

    The icon ligatures are literals for the same kind of reason: the panel's
    Material Symbols font is a SUBSET built from the ligatures found in these
    views, so one chosen at runtime is absent from the font and renders as its
    own name in plain text. One icon serves every row — the kind is already
    carried by the colour and the wording beside it — and both it and the empty
    state's are in the subset the installation ships.
--}}
@php
    $kinds = [
        \Magna\Content\Models\Revision::KIND_PUBLISH => [
            'label' => 'Published',
            'classes' => 'bg-emerald-100 dark:bg-emerald-950/30 text-emerald-500',
        ],
        \Magna\Content\Models\Revision::KIND_RESTORE_POINT => [
            'label' => 'Restore point',
            'classes' => 'bg-amber-100 dark:bg-amber-950/30 text-amber-500',
        ],
        \Magna\Content\Models\Revision::KIND_AUTOSAVE => [
            'label' => 'Autosave',
            'classes' => 'bg-slate-100 dark:bg-slate-800/50 text-slate-400',
        ],
        \Magna\Content\Models\Revision::KIND_SAVE => [
            'label' => 'Saved',
            'classes' => 'bg-sky-100 dark:bg-sky-950/30 text-sky-500',
        ],
    ];
@endphp

<div class="space-y-3">
    @if ($revisions->isNotEmpty())
        <p class="text-xs text-slate-400">Newest first.</p>
    @endif

    @forelse ($revisions as $revision)
        @php
            $kind = $kinds[$revision->kind] ?? [
                'label' => $revision->kind,
                'classes' => 'bg-slate-100 dark:bg-slate-800/50 text-slate-400',
            ];
        @endphp

        <div class="flex items-start gap-3 bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-xl p-4">
            <div class="p-2 rounded-lg shrink-0 {{ $kind['classes'] }}">
                <span class="msri text-lg">history_edu</span>
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-3">
                    <h4 class="text-sm font-bold text-slate-900 dark:text-white truncate">
                        {{ $revision->label ?: $kind['label'] }}
                    </h4>
                    <time
                        class="text-[11px] text-slate-400 font-mono shrink-0"
                        datetime="{{ $revision->created_at->toIso8601String() }}"
                        title="{{ $revision->created_at->toDayDateTimeString() }}"
                    >{{ $revision->created_at->diffForHumans() }}</time>
                </div>

                <p class="text-[11px] text-slate-400 mt-1 font-mono">
                    {{ $kind['label'] }}
                    @if ($revision->schema_version)
                        &middot; schema {{ $revision->schema_version }}
                    @endif
                </p>
            </div>
        </div>
    @empty
        <div class="bg-white dark:bg-slate-900 border border-dashed border-slate-200 dark:border-slate-800 rounded-xl p-6 text-center">
            <span class="msri text-2xl text-slate-300 dark:text-slate-700">pending_actions</span>
            <p class="text-sm text-slate-400 mt-2">No revisions recorded for this entry yet.</p>
        </div>
    @endforelse
</div>
