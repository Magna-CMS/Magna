{{--
  Magna Block Editor — Section → Column → Block tree editor.
  Embedded in the Filament entry form as a Livewire component.
--}}

{{-- $documentPreviewUrl comes from BlockEditor::render(): non-null only when
     a renderer is bound (Magna Pages) — core never references a plugin route
     (§E1 contract seam). --}}
<div class="magna-block-editor"
     x-data="{ addBlockModal: false, addBlockTarget: null, cloudModal: false, previewOpen: false }"
     @if($documentPreviewUrl !== null)
         data-preview-url="{{ $documentPreviewUrl }}"
         data-preview-csrf="{{ csrf_token() }}"
     @endif
>

    {{-- Shared document lock (§E2): the visual builder holds this page. --}}
    @if($lockedBy !== null)
        <div class="mb-2 flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-200"
             role="alert">
            {{ $lockedBy }} is editing this page in the builder — changes here will not save.
        </div>
    @endif

    {{-- Header bar --}}
    <div class="flex items-center justify-between rounded-t-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-white/10 dark:bg-white/5">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Block Editor</span>
        <div class="flex items-center gap-3">
            @if($saveStatus)
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $saveStatus }}</span>
            @endif
            @if($documentPreviewUrl !== null)
                <button type="button"
                        @click="previewOpen = !previewOpen; if (previewOpen) window.magnaRefreshPreview && window.magnaRefreshPreview()"
                        class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:hover:bg-white/20">
                    <x-heroicon-o-eye class="h-3.5 w-3.5"/>
                    <span x-text="previewOpen ? 'Hide preview' : 'Preview'"></span>
                </button>
            @endif
            {{-- Cloud Library hook (Stage 19 wires this) --}}
            <button type="button"
                    @click="cloudModal = true"
                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/50 dark:text-indigo-300 dark:hover:bg-indigo-900/60">
                <x-heroicon-o-cloud-arrow-down class="h-3.5 w-3.5"/>
                Browse Cloud Library
            </button>
            <button type="button"
                    wire:click="save"
                    class="inline-flex items-center gap-1.5 rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5"/>
                Save
            </button>
        </div>
    </div>

    {{-- Section list --}}
    <div class="divide-y divide-gray-100 dark:divide-white/10">

        @forelse($sections as $si => $section)
            <div class="magna-block-editor__section group border border-t-0 border-gray-200 dark:border-white/10"
                 x-data="{ open: true, settingsOpen: false }">

                {{-- Section header --}}
                <div class="flex items-center gap-2 bg-white px-4 py-2 dark:bg-white/5">
                    <button type="button" @click="open = !open" class="flex-1 text-left">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Section {{ $si + 1 }}
                            @if(!empty($section['settings']['anchor']))
                                <span class="text-gray-400">#{{ $section['settings']['anchor'] }}</span>
                            @endif
                        </span>
                    </button>

                    <div class="flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <button type="button" wire:click="moveSectionUp({{ $si }})" title="Move up" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-white/10">
                            <x-heroicon-o-arrow-up class="h-3.5 w-3.5 text-gray-500"/>
                        </button>
                        <button type="button" wire:click="moveSectionDown({{ $si }})" title="Move down" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-white/10">
                            <x-heroicon-o-arrow-down class="h-3.5 w-3.5 text-gray-500"/>
                        </button>
                        <button type="button" @click="settingsOpen = !settingsOpen" title="Section settings" class="rounded p-1 hover:bg-gray-100 dark:hover:bg-white/10">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5 text-gray-500"/>
                        </button>
                        <button type="button" wire:click="removeSection({{ $si }})" title="Delete section"
                                wire:confirm="Delete this section and all its blocks?"
                                class="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30">
                            <x-heroicon-o-trash class="h-3.5 w-3.5"/>
                        </button>
                    </div>
                </div>

                {{-- Section settings panel --}}
                @include('magna::block-editor.partials.section-settings')

                {{-- Columns and their blocks --}}
                <div x-show="open" x-collapse>
                    <div class="flex gap-0 divide-x divide-gray-100 dark:divide-white/10">

                        @foreach($section['columns'] as $ci => $column)
                            <div class="magna-block-editor__column min-w-0 flex-1 p-3"
                                 style="flex: {{ $column['span'] }} {{ $column['span'] }} 0%">

                                {{-- Column label --}}
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-xs text-gray-400">Col {{ $ci + 1 }} (span {{ $column['span'] }})</span>
                                </div>

                                {{-- Blocks in this column --}}
                                @forelse($column['blocks'] as $bi => $block)
                                    @include('magna::block-editor.partials.block-card')
                                @empty
                                    <p class="mb-2 text-xs text-gray-400 italic">No blocks in this column.</p>
                                @endforelse

                                {{-- Add block button --}}
                                <button type="button"
                                        @click="addBlockModal = true; addBlockTarget = { si: {{ $si }}, ci: {{ $ci }} }"
                                        class="flex w-full items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-gray-200 py-2 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-600 dark:border-white/10 dark:hover:border-indigo-500">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5"/>
                                    Add block
                                </button>

                            </div>
                        @endforeach

                    </div>
                </div>

            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-gray-400">
                <x-heroicon-o-squares-2x2 class="mb-3 h-10 w-10 text-gray-300"/>
                <p class="text-sm">No sections yet. Add one below.</p>
            </div>
        @endforelse

    </div>

    {{-- Add section button --}}
    <div class="border border-t-0 border-gray-200 dark:border-white/10">
        <button type="button"
                wire:click="addSection"
                class="flex w-full items-center justify-center gap-2 rounded-b-lg py-3 text-sm text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5">
            <x-heroicon-o-plus-circle class="h-4 w-4"/>
            Add section
        </button>
    </div>

    {{-- Add Block Modal --}}
    @include('magna::block-editor.partials.add-block-modal')

    {{-- Cloud Library placeholder (Stage 19 replaces this) --}}
    <div x-show="cloudModal"
         x-transition
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
         @keydown.escape.window="cloudModal = false">
        <div class="mx-4 w-full max-w-sm rounded-xl border border-gray-200 bg-white p-8 text-center shadow-2xl dark:border-white/10 dark:bg-gray-900">
            <x-heroicon-o-cloud-arrow-down class="mx-auto mb-4 h-12 w-12 text-indigo-400"/>
            <h2 class="mb-2 font-semibold text-gray-900 dark:text-white">Cloud Library</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Cloud Library coming soon. Pre-designed sections will be available here in Stage 19.</p>
            <button type="button" @click="cloudModal = false" class="mt-5 rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-white">Close</button>
        </div>
    </div>

    {{-- Include the global media picker once --}}
    <livewire:magna-media-picker />

    @if($documentPreviewUrl !== null)
        {{-- Live preview: the REAL themed render of the current (unsaved)
             editor state — same pipeline as publishing, so no drift. --}}
        <div x-show="previewOpen" x-cloak class="border border-t-0 border-gray-200 dark:border-white/10">
            <div class="flex items-center justify-between bg-gray-50 px-4 py-1.5 dark:bg-white/5">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Live preview — rendered through the active theme</span>
                <button type="button"
                        @click="window.magnaRefreshPreview && window.magnaRefreshPreview()"
                        class="text-xs text-gray-400 hover:text-indigo-600">Refresh</button>
            </div>
            <iframe id="magna-preview-frame"
                    title="Page preview"
                    class="h-[36rem] w-full bg-white"
                    sandbox="allow-same-origin"></iframe>
        </div>
    @endif

</div>

{{-- Autosave: 3-second debounce after any editor change --}}
<script>
(function () {
    var timer = null;
    document.addEventListener('livewire:update', function (e) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            // Target only the block editor root element (not the outer Filament page component)
            var editorEl = document.querySelector('.magna-block-editor[wire\\:id]');
            if (!editorEl) { return; }
            var editor = Livewire.find(editorEl.getAttribute('wire:id'));
            if (editor && typeof editor.save === 'function') {
                editor.save();
            }
        }, 3000);
    });
})();
</script>

{{-- Live preview: render current editor state through the themed pipeline --}}
<script>
(function () {
    var refreshTimer = null;

    function editorRoot() {
        return document.querySelector('.magna-block-editor[wire\\:id][data-preview-url]');
    }

    window.magnaRefreshPreview = function () {
        var root = editorRoot();
        var frame = document.getElementById('magna-preview-frame');
        if (!root || !frame) { return; }

        var editor = Livewire.find(root.getAttribute('wire:id'));
        if (!editor || typeof editor.serialise !== 'function') { return; }

        Promise.resolve(editor.serialise()).then(function (blocksData) {
            var body = new FormData();
            body.append('blocks_data', blocksData);
            body.append('_token', root.getAttribute('data-preview-csrf'));

            return fetch(root.getAttribute('data-preview-url'), { method: 'POST', body: body });
        }).then(function (response) {
            return response.text();
        }).then(function (html) {
            frame.srcdoc = html;
        }).catch(function () { /* preview is best-effort; editing must never break */ });
    };

    // Auto-refresh (debounced) while the pane is open.
    document.addEventListener('livewire:update', function () {
        var frame = document.getElementById('magna-preview-frame');
        if (!frame || frame.offsetParent === null) { return; } // pane hidden
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(window.magnaRefreshPreview, 900);
    });
})();
</script>
