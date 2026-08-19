<x-filament-panels::page>

@assets
@include('magna::admin.partials.material-symbols-font')
<style>
.mli-msri {
    font-family: 'Material Symbols Rounded';
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    display: inline-block; line-height: 1; vertical-align: -3px; user-select: none;
}
.mli-thumb { aspect-ratio: 1; object-fit: cover; }
.mli-card:hover .mli-overlay { opacity: 1; }
.mli-overlay { opacity: 0; transition: opacity .18s ease; }
[x-cloak] { display: none !important; }
</style>
@endassets

{{-- ─── Toolbar ──────────────────────────────────────────────────────────────── --}}
<div
    x-data="{
        viewMode: localStorage.getItem('magna_media_view') ?? 'grid',
        setView(v) { this.viewMode = v; localStorage.setItem('magna_media_view', v); },
        showFolders: localStorage.getItem('magna_media_folders') === '1',
        toggleFolders() { this.showFolders = ! this.showFolders; localStorage.setItem('magna_media_folders', this.showFolders ? '1' : '0'); },
        copiedId: null,
        copyUrl(url, id) {
            const fallback = () => {
                const el = document.createElement('textarea');
                el.value = url; el.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(el); el.focus(); el.select();
                document.execCommand('copy'); el.remove();
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {}).catch(fallback);
            } else {
                fallback();
            }
            this.copiedId = id;
            setTimeout(() => { this.copiedId = null; }, 1500);
        }
    }"
    class="space-y-6"
>
    {{-- Search + view toggle toolbar --}}
    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        {{-- Search --}}
        <div class="relative flex-grow max-w-md">
            <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none text-slate-400">
                <span class="mli-msri text-lg">search</span>
            </div>
            <input
                wire:model.live.debounce.350ms="gallerySearch"
                type="text"
                placeholder="Search files by name or title…"
                class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-violet-500/40 focus:border-violet-500 transition"
            >
            @if($gallerySearch !== '')
            <button wire:click="$set('gallerySearch', '')" class="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                <span class="mli-msri text-lg">close</span>
            </button>
            @endif
        </div>

        {{-- Spacer + item count --}}
        @php $toolbarCount = $activeSource === null ? $galleryItems->total() : $sourceTotal; @endphp
        <p class="text-sm text-slate-400 dark:text-slate-500 hidden sm:block flex-shrink-0">
            {{ $toolbarCount }} {{ Str::plural('file', $toolbarCount) }}
        </p>

        {{-- View toggle --}}
        <div class="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800 rounded-xl flex-shrink-0">
            <button
                @click="setView('grid')"
                :class="viewMode === 'grid'
                    ? 'bg-white dark:bg-slate-700 text-violet-600 dark:text-violet-400 shadow-sm'
                    : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'"
                class="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all"
                title="Grid view"
            >
                <span class="mli-msri text-lg">grid_view</span>
                <span class="hidden sm:inline">Grid</span>
            </button>
            <button
                @click="setView('list')"
                :class="viewMode === 'list'
                    ? 'bg-white dark:bg-slate-700 text-violet-600 dark:text-violet-400 shadow-sm'
                    : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'"
                class="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all"
                title="List view"
            >
                <span class="mli-msri text-lg">list</span>
                <span class="hidden sm:inline">List</span>
            </button>

            {{-- Folders toggle: show/hide the folders section --}}
            @if($galleryFolders->isNotEmpty())
            <button
                @click="toggleFolders()"
                :class="showFolders
                    ? 'bg-white dark:bg-slate-700 text-violet-600 dark:text-violet-400 shadow-sm'
                    : 'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300'"
                class="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all"
                title="Show folders"
            >
                <span class="mli-msri text-lg">folder</span>
                <span class="hidden sm:inline">Folders</span>
            </button>
            @endif
        </div>
    </div>

    {{-- ─── Sources ─────────────────────────────────────────────────────────────
         Files a plugin stores on its own disk are listed here alongside the
         library's own. Listed, not served: a confidential source hands over
         names and sizes, and the operator follows the link into the plugin to
         actually open anything. Without this strip the library silently
         under-reported what the installation was holding. --}}
    @if(! empty($mediaSources))
    <div class="flex items-center gap-2 overflow-x-auto pb-1">
        <button
            wire:click="selectSource(null)"
            @class([
                'flex-shrink-0 px-3.5 py-2 text-xs font-bold rounded-xl border transition-all',
                'bg-violet-500 text-white border-violet-500' => $activeSource === null,
                'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-violet-400/40' => $activeSource !== null,
            ])
        >
            Library
        </button>
        @foreach($mediaSources as $source)
        <button
            wire:key="media-source-{{ $source->key() }}"
            wire:click="selectSource('{{ $source->key() }}')"
            @class([
                'flex-shrink-0 inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold rounded-xl border transition-all',
                'bg-violet-500 text-white border-violet-500' => $activeSource !== null && $activeSource->key() === $source->key(),
                'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-violet-400/40' => $activeSource === null || $activeSource->key() !== $source->key(),
            ])
        >
            @if($source->isConfidential())
            <span class="mli-msri text-sm" title="Confidential — listed here, opened in the owning plugin">lock</span>
            @endif
            {{ $source->label() }}
        </button>
        @endforeach
    </div>
    @endif

    @if($activeSource !== null)
    @include('magna::admin.media-source-panel')
    @else

    {{-- ─── Folders (toggled from the toolbar) ──────────────────────────────── --}}
    @if($galleryFolders->isNotEmpty())
    <div x-show="showFolders" x-cloak>
        <p class="text-[10px] uppercase font-bold tracking-widest text-slate-400 dark:text-slate-500 mb-3">Folders</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach($galleryFolders as $folder)
            <div class="group flex flex-col items-center gap-2 p-4 bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl hover:border-violet-400/40 dark:hover:border-violet-600/40 hover:shadow-md transition-all cursor-pointer">
                <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-950/30 flex items-center justify-center text-amber-500">
                    <span class="mli-msri text-2xl">folder</span>
                </div>
                <div class="text-center min-w-0 w-full">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate">{{ $folder->name }}</p>
                    <p class="text-[10px] text-slate-400 mt-0.5">{{ $folder->media_count }} {{ Str::plural('file', $folder->media_count) }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ─── Grid View ────────────────────────────────────────────────────────── --}}
    <div x-show="viewMode === 'grid'">

        @if($galleryItems->isEmpty())
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-24 bg-white dark:bg-slate-900 border border-dashed border-slate-200 dark:border-slate-800 rounded-3xl">
            <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                <span class="mli-msri text-3xl text-slate-400">photo_library</span>
            </div>
            <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                {{ $gallerySearch !== '' ? 'No files match your search.' : 'No media uploaded yet.' }}
            </p>
            @if($gallerySearch === '')
            <p class="text-xs text-slate-400 mt-1">Click "Upload media" to add your first file.</p>
            @endif
        </div>
        @else

        {{-- Files grid --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
            @foreach($galleryItems as $item)
            @php
                // $url stays the true original (used for "Copy URL" and the edit
                // link); $thumbUrl is the small 150x150 derivative for the grid
                // tile image only — falls back to the original automatically if
                // no conversion exists yet.
                $url  = \Magna\Admin\Resources\Media\ListMedia::mediaUrl($item);
                $thumbUrl = $item->isImage() ? \Magna\Admin\Resources\Media\ListMedia::mediaUrl($item, 'thumb') : $url;
                $isImg = $item->isImage();
                $ext  = strtoupper(pathinfo($item->filename, PATHINFO_EXTENSION));
                $isVid = str_starts_with($item->mime_type, 'video/');
                $isPdf = $item->mime_type === 'application/pdf';
                $isAud = str_starts_with($item->mime_type, 'audio/');
                $sizeFmt = $item->size >= 1_048_576
                    ? number_format($item->size / 1_048_576, 2) . ' MB'
                    : number_format($item->size / 1_024, 1) . ' KB';
                // Prefer the title given at upload; fall back to the file name.
                $displayName = filled($item->title) ? $item->title : $item->original_filename;
            @endphp
            <div class="mli-card group relative bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:border-violet-400/40 dark:hover:border-violet-700/40 transition-all">

                {{-- Thumbnail area --}}
                <div class="relative bg-slate-100 dark:bg-slate-800" style="aspect-ratio:1">
                    @if($isImg)
                        <img
                            src="{{ $thumbUrl }}"
                            alt="{{ $item->alt ?? $item->original_filename }}"
                            class="mli-thumb w-full h-full"
                            loading="lazy"
                        >
                    @else
                        <div class="w-full h-full flex flex-col items-center justify-center gap-2">
                            @if($isVid)
                                <span class="mli-msri text-4xl text-slate-400">movie</span>
                            @elseif($isPdf)
                                <span class="mli-msri text-4xl text-red-400">picture_as_pdf</span>
                            @elseif($isAud)
                                <span class="mli-msri text-4xl text-purple-400">music_note</span>
                            @else
                                <span class="mli-msri text-4xl text-slate-400">insert_drive_file</span>
                            @endif
                            <span class="text-[10px] font-bold font-mono text-slate-400 uppercase">{{ $ext }}</span>
                        </div>
                    @endif

                    {{-- Hover overlay --}}
                    <div class="mli-overlay absolute inset-0 bg-slate-900/60 backdrop-blur-[2px] flex items-center justify-center gap-2">
                        {{-- Copy URL --}}
                        <button
                            @click.stop="copyUrl('{{ $url }}', '{{ $item->id }}')"
                            class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 flex items-center justify-center text-white transition-all"
                            title="Copy URL"
                        >
                            <span class="mli-msri text-lg" x-text="copiedId === '{{ $item->id }}' ? 'check' : 'link'"></span>
                        </button>
                        {{-- In-CMS preview --}}
                        <button
                            wire:click="previewGalleryItem('{{ $item->id }}')"
                            class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 flex items-center justify-center text-white transition-all"
                            title="Preview"
                        >
                            <span class="mli-msri text-lg">visibility</span>
                        </button>
                        {{-- Edit --}}
                        <a
                            href="{{ \Magna\Admin\Resources\MediaResource::getUrl('edit', ['record' => $item]) }}"
                            class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 flex items-center justify-center text-white transition-all"
                            title="Edit details"
                        >
                            <span class="mli-msri text-lg">edit</span>
                        </a>
                        {{-- Delete --}}
                        <button
                            wire:click="deleteGalleryItem('{{ $item->id }}')"
                            wire:confirm="Move '{{ addslashes($item->original_filename) }}' to recycle bin?"
                            class="w-9 h-9 rounded-xl bg-red-500/20 hover:bg-red-500/40 border border-red-400/30 flex items-center justify-center text-red-300 transition-all"
                            title="Delete"
                        >
                            <span class="mli-msri text-lg">delete</span>
                        </button>
                    </div>
                </div>

                {{-- File info --}}
                <div class="p-3">
                    {{-- Trimmed to ~12 chars; full name on hover via the title tooltip. --}}
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate cursor-default" title="{{ $displayName }}">
                        {{ Str::limit($displayName, 12, '…') }}
                    </p>
                    <div class="flex items-center gap-1.5 mt-1.5">
                        <span class="text-[10px] font-mono text-slate-400">{{ $sizeFmt }}</span>
                        <span class="text-slate-200 dark:text-slate-700 select-none">·</span>
                        <span class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded-md
                            @if($isImg) bg-violet-100 dark:bg-violet-950/40 text-violet-500
                            @elseif($isVid) bg-sky-100 dark:bg-sky-950/40 text-sky-500
                            @elseif($isPdf) bg-red-100 dark:bg-red-950/40 text-red-500
                            @elseif($isAud) bg-purple-100 dark:bg-purple-950/40 text-purple-500
                            @else bg-slate-100 dark:bg-slate-800 text-slate-400
                            @endif">
                            {{ $ext }}
                        </span>
                    </div>
                    @if($item->width && $item->height)
                    <p class="text-[10px] text-slate-300 dark:text-slate-600 mt-1 font-mono">{{ $item->width }}×{{ $item->height }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($galleryItems->hasPages())
        <div class="mt-6 flex justify-center">
            {{ $galleryItems->onEachSide(1)->links() }}
        </div>
        @endif

        @endif {{-- end isEmpty check --}}
    </div>

    {{-- ─── List View ────────────────────────────────────────────────────────── --}}
    <div x-show="viewMode === 'list'">
        {{ $this->table }}
    </div>

    @endif {{-- end plugin-source check --}}

</div>

{{-- In-CMS preview modal --}}
@if($galleryPreview)
<div
    class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm"
    wire:click.self="closeGalleryPreview"
    role="dialog" aria-modal="true"
>
    <div class="relative w-full max-w-3xl bg-white dark:bg-slate-900 rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

        {{-- Header --}}
        <div class="flex items-center justify-between gap-4 px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex-shrink-0">
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200 truncate">
                {{ $galleryPreview['filename'] }}
            </p>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a
                    href="{{ $galleryPreview['originalUrl'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-xs text-slate-400 hover:text-violet-500 transition-colors"
                    title="Open full size"
                >
                    <span class="mli-msri text-base">open_in_new</span>
                </a>
                <button
                    wire:click="closeGalleryPreview"
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-600 dark:hover:text-slate-200 transition-all"
                    title="Close"
                >
                    <span class="mli-msri text-xl">close</span>
                </button>
            </div>
        </div>

        {{-- Content --}}
        <div class="flex-1 overflow-auto p-5 flex items-center justify-center bg-slate-50 dark:bg-slate-950/50">
            @if(str_starts_with($galleryPreview['mime_type'], 'image/'))
                <img
                    src="{{ $galleryPreview['url'] }}"
                    alt="{{ $galleryPreview['alt'] }}"
                    class="max-w-full max-h-[60vh] rounded-lg object-contain shadow-sm"
                >
            @elseif(str_starts_with($galleryPreview['mime_type'], 'video/'))
                <video
                    src="{{ $galleryPreview['url'] }}"
                    controls
                    class="max-w-full max-h-[60vh] rounded-lg"
                ></video>
            @elseif(str_starts_with($galleryPreview['mime_type'], 'audio/'))
                <audio src="{{ $galleryPreview['url'] }}" controls class="w-full"></audio>
            @elseif($galleryPreview['mime_type'] === 'application/pdf')
                <iframe
                    src="{{ $galleryPreview['url'] }}"
                    class="w-full rounded-lg border border-slate-200 dark:border-slate-700"
                    style="height: 60vh;"
                ></iframe>
            @else
                <div class="flex flex-col items-center gap-4 py-8 text-center">
                    <span class="mli-msri text-5xl text-slate-300">insert_drive_file</span>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Preview not available for this file type.</p>
                    <a
                        href="{{ $galleryPreview['originalUrl'] }}"
                        download
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-violet-600 text-white text-sm font-medium hover:bg-violet-700 transition-colors"
                    >
                        <span class="mli-msri text-base">download</span>
                        Download file
                    </a>
                </div>
            @endif
        </div>

        @if($galleryPreview['width'] && $galleryPreview['height'])
        <div class="px-5 py-3 border-t border-slate-200 dark:border-slate-800 flex-shrink-0">
            <p class="text-xs text-slate-400 font-mono">{{ $galleryPreview['width'] }} × {{ $galleryPreview['height'] }} px</p>
        </div>
        @endif
    </div>
</div>
@endif

<x-filament-actions::modals />

@script
<script>
document.addEventListener('livewire:update', () => {
    /* After Livewire re-renders, re-show the correct view.
       Alpine state is preserved across Livewire morphing, so no action needed. */
});
</script>
@endscript

</x-filament-panels::page>
