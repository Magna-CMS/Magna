{{--
    Files owned by a plugin, listed for the operator.

    No thumbnails, no storage URLs, no delete: the plugin holding these files is
    the only thing that knows who may open one, so the library shows what exists
    and hands the operator to the plugin's own screen. For a confidential source
    that is not a limitation to work around — it is the reason the inventory can
    be complete without turning the library into a way to read everybody's
    identity documents.
--}}
<div class="space-y-4">

    <div class="flex items-start gap-3 p-4 rounded-2xl border border-slate-200/70 dark:border-slate-800/70 bg-slate-50 dark:bg-slate-950/40">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 {{ $activeSource->isConfidential() ? 'bg-amber-100 dark:bg-amber-950/30 text-amber-500' : 'bg-violet-100 dark:bg-violet-950/30 text-violet-500' }}">
            <span class="mli-msri text-lg">{{ $activeSource->isConfidential() ? 'lock' : 'folder_shared' }}</span>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $activeSource->label() }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                {{ $sourceTotal }} {{ Str::plural('file', $sourceTotal) }} held by a plugin, outside the media library.
                @if($activeSource->isConfidential())
                Names and sizes only — open a file from the plugin that owns it.
                @endif
            </p>
        </div>
    </div>

    @if($sourceItems === [])
    <div class="flex flex-col items-center justify-center py-20 bg-white dark:bg-slate-900 border border-dashed border-slate-200 dark:border-slate-800 rounded-3xl">
        <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-3">
            <span class="mli-msri text-2xl text-slate-400">folder_open</span>
        </div>
        <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">
            {{ $gallerySearch !== '' ? 'No files match your search.' : 'This source holds no files yet.' }}
        </p>
    </div>
    @else

    <div class="overflow-x-auto bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 dark:border-slate-800">
                    <th class="text-left px-4 py-3 text-[10px] uppercase font-bold tracking-widest text-slate-400">File</th>
                    <th class="text-left px-4 py-3 text-[10px] uppercase font-bold tracking-widest text-slate-400">Belongs to</th>
                    <th class="text-left px-4 py-3 text-[10px] uppercase font-bold tracking-widest text-slate-400">Type</th>
                    <th class="text-right px-4 py-3 text-[10px] uppercase font-bold tracking-widest text-slate-400">Size</th>
                    <th class="text-right px-4 py-3 text-[10px] uppercase font-bold tracking-widest text-slate-400">Uploaded</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($sourceItems as $item)
                <tr wire:key="source-item-{{ $activeSource->key() }}-{{ $item->id }}" class="border-b border-slate-50 dark:border-slate-800/50 last:border-0">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2.5 min-w-0">
                            @if($item->thumbnailUrl !== null)
                            <img src="{{ $item->thumbnailUrl }}" alt="" class="w-8 h-8 rounded-lg object-cover flex-shrink-0">
                            @else
                            <span class="mli-msri text-lg text-slate-400 flex-shrink-0">description</span>
                            @endif
                            <span class="font-medium text-slate-700 dark:text-slate-200 truncate">{{ $item->name }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $item->ownerLabel ?? '—' }}</td>
                    <td class="px-4 py-3 text-slate-400 font-mono text-xs">{{ $item->mimeType }}</td>
                    <td class="px-4 py-3 text-right text-slate-500 dark:text-slate-400 tabular-nums">
                        {{ $item->sizeBytes > 0 ? \Illuminate\Support\Number::fileSize($item->sizeBytes, precision: 1) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-slate-400 tabular-nums text-xs">
                        {{ $item->uploadedAt?->format('j M Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($item->manageUrl !== null)
                        <a href="{{ $item->manageUrl }}" class="inline-flex items-center gap-1 text-xs font-bold text-violet-500 hover:underline">
                            Open
                            <span class="mli-msri text-sm">arrow_forward</span>
                        </a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Page links are driven by the source's own total, since these rows never
         pass through the query builder that produces a paginator. --}}
    @php $sourcePages = (int) ceil($sourceTotal / 24); @endphp
    @if($sourcePages > 1)
    <div class="flex items-center justify-center gap-2">
        <button
            wire:click="previousPage('mpage')"
            @disabled($sourcePage <= 1)
            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed"
        >Previous</button>
        <span class="text-xs text-slate-400 tabular-nums">Page {{ $sourcePage }} of {{ $sourcePages }}</span>
        <button
            wire:click="nextPage('mpage')"
            @disabled($sourcePage >= $sourcePages)
            class="px-3 py-1.5 text-xs font-bold rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 disabled:opacity-40 disabled:cursor-not-allowed"
        >Next</button>
    </div>
    @endif

    @endif
</div>
