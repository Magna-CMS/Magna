{{-- Add Block Modal. Scope from editor.blade: $availableBlocks, plus the
     Alpine addBlockModal/addBlockTarget state on the editor root. --}}
<div x-show="addBlockModal"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="addBlockModal = false">

    <div class="mx-4 w-full max-w-lg rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-white/10">
            <h2 class="font-semibold text-gray-900 dark:text-white">Add block</h2>
            <button type="button" @click="addBlockModal = false" class="text-gray-400 hover:text-gray-600">
                <x-heroicon-o-x-mark class="h-5 w-5"/>
            </button>
        </div>
        <div class="max-h-96 overflow-y-auto p-5">
            @foreach($availableBlocks as $category => $blocks)
                <div class="mb-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ ucfirst($category) }}</p>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach($blocks as $bDef)
                            <button type="button"
                                    @click="$wire.addBlock(addBlockTarget.si, addBlockTarget.ci, '{{ $bDef['handle'] }}'); addBlockModal = false"
                                    class="flex flex-col items-center rounded-lg border border-gray-100 px-2 py-3 text-xs font-medium text-gray-700 hover:border-indigo-400 hover:bg-indigo-50 dark:border-white/10 dark:text-gray-300 dark:hover:border-indigo-500 dark:hover:bg-indigo-950/30">
                                {{-- SVG from the IconRegistry (shipped, sanitized icon
                                     geometry) via RendersEditorChrome — never
                                     user-authored markup. --}}
                                {!! $this->blockIconSvg($bDef['icon'], 'mb-1.5 h-5 w-5 text-gray-500 dark:text-gray-400') !!}
                                {{ $bDef['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</div>
