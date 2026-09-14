{{-- One placed block: header, controls and its data fields.
     Scope from editor.blade: $si, $ci, $bi, $block. --}}
<div class="magna-block-editor__block mb-2 rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5"
     x-data="{ blockOpen: true }">

    {{-- Block header --}}
    <div class="flex items-center gap-2 px-3 py-2">
        <button type="button" @click="blockOpen = !blockOpen"
                class="flex-1 text-left text-xs font-medium text-gray-700 dark:text-gray-300">
            {{ ucfirst($block['block']) }}
        </button>
        <div class="flex items-center gap-1">
            <button type="button" wire:click="moveBlockUp({{ $si }}, {{ $ci }}, {{ $bi }})" title="Move up" class="rounded p-0.5 hover:bg-gray-100 dark:hover:bg-white/10">
                <x-heroicon-o-arrow-up class="h-3 w-3 text-gray-400"/>
            </button>
            <button type="button" wire:click="moveBlockDown({{ $si }}, {{ $ci }}, {{ $bi }})" title="Move down" class="rounded p-0.5 hover:bg-gray-100 dark:hover:bg-white/10">
                <x-heroicon-o-arrow-down class="h-3 w-3 text-gray-400"/>
            </button>
            <button type="button" wire:click="duplicateBlock({{ $si }}, {{ $ci }}, {{ $bi }})" title="Duplicate" class="rounded p-0.5 hover:bg-gray-100 dark:hover:bg-white/10">
                <x-heroicon-o-document-duplicate class="h-3 w-3 text-gray-400"/>
            </button>
            <button type="button"
                    wire:click="removeBlock({{ $si }}, {{ $ci }}, {{ $bi }})"
                    wire:confirm="Delete this block?"
                    title="Delete"
                    class="rounded p-0.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30">
                <x-heroicon-o-trash class="h-3 w-3"/>
            </button>
        </div>
    </div>

    {{-- Block data fields (simple key-value inputs) --}}
    <div x-show="blockOpen" x-collapse class="border-t border-gray-100 px-3 pb-3 pt-2 dark:border-white/10">
        @php
            $blockDef = $this->blockDefinition($block['block']);
        @endphp
        @if($blockDef)
            @foreach($blockDef->fields as $bField)
                <div class="mb-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                        {{ $bField->label }}
                        @if($bField->required)<span class="text-red-500">*</span>@endif
                    </label>
                    @if($bField->type === 'select')
                        <select wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                                class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                            @foreach($bField->resolveOptions() as $optVal => $optLabel)
                                <option value="{{ $optVal }}">{{ $optLabel }}</option>
                            @endforeach
                        </select>
                    @elseif($bField->type === 'boolean')
                        <input type="checkbox"
                               wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                               class="rounded border-gray-300">
                    @elseif($bField->type === 'media')
                        @php
                            $mediaValue = $block['data'][$bField->handle] ?? null;
                            $mediaThumb = $this->mediaThumbUrl($mediaValue);
                            $mediaName = $this->mediaLabel($mediaValue);
                        @endphp
                        <div class="flex items-center gap-2">
                            @if($mediaThumb)
                                <img src="{{ $mediaThumb }}" alt=""
                                     class="h-10 w-10 rounded border border-gray-200 object-cover dark:border-white/10">
                            @elseif($mediaName)
                                <span class="max-w-[8rem] truncate text-[10px] text-gray-500">{{ $mediaName }}</span>
                            @endif
                            <button type="button"
                                    @click="$dispatch('magna:open-media-picker', { target: 'block-field:{{ $si }}:{{ $ci }}:{{ $bi }}:{{ $bField->handle }}' })"
                                    class="rounded border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-white/10 dark:text-gray-300 dark:hover:border-indigo-500">
                                {{ $mediaName !== null ? 'Change' : 'Choose media' }}
                            </button>
                            @if(is_string($mediaValue) && $mediaValue !== '')
                                <button type="button"
                                        wire:click="clearMediaField({{ $si }}, {{ $ci }}, {{ $bi }}, '{{ $bField->handle }}')"
                                        class="text-xs text-gray-400 hover:text-red-500">
                                    Remove
                                </button>
                            @endif
                        </div>
                    @elseif($bField->type === 'alignment')
                        <select wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                                class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>
                    @elseif($bField->type === 'color')
                        <div class="flex items-center gap-2">
                            @php $colorValue = $block['data'][$bField->handle] ?? null; @endphp
                            <input type="color"
                                   value="{{ is_string($colorValue) && str_starts_with($colorValue, '#') ? substr($colorValue, 0, 7) : '#000000' }}"
                                   wire:change="updateBlockData({{ $si }}, {{ $ci }}, {{ $bi }}, '{{ $bField->handle }}', $event.target.value)"
                                   class="h-7 w-9 cursor-pointer rounded border border-gray-200 dark:border-white/10">
                            <input type="text"
                                   wire:model.live.debounce.400ms="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                                   placeholder="#rrggbb or token:primary"
                                   class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                        </div>
                    @elseif($bField->type === 'icon' || $bField->type === 'link')
                        <input type="text"
                               wire:model.live.debounce.400ms="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                               placeholder="{{ $bField->type === 'icon' ? 'heroicon-o-sparkles' : 'https://… or /page-path' }}"
                               class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                    @elseif($bField->type === 'repeater')
                        @php
                            $repeaterItems = $block['data'][$bField->handle] ?? [];
                            $repeaterItems = is_array($repeaterItems) ? $repeaterItems : [];
                        @endphp
                        <div class="space-y-2">
                            @foreach($repeaterItems as $ri => $repeaterItem)
                                <div class="rounded border border-gray-200 p-2 dark:border-white/10">
                                    <div class="mb-1 flex items-center justify-between">
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Item {{ $ri + 1 }}</span>
                                        <button type="button"
                                                wire:click="removeRepeaterItem({{ $si }}, {{ $ci }}, {{ $bi }}, '{{ $bField->handle }}', {{ $ri }})"
                                                class="text-xs text-gray-400 hover:text-red-500">Remove</button>
                                    </div>
                                    @foreach($bField->fields as $riField)
                                        <div class="mb-1.5">
                                            <label class="mb-0.5 block text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ $riField->label }}</label>
                                            @if($riField->type === 'textarea' || $riField->type === 'richtext')
                                                <textarea wire:model.live.debounce.400ms="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}.{{ $ri }}.{{ $riField->handle }}"
                                                          rows="2"
                                                          class="w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white"></textarea>
                                            @elseif($riField->type === 'select')
                                                <select wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}.{{ $ri }}.{{ $riField->handle }}"
                                                        class="w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                                                    @foreach($riField->resolveOptions() as $optVal => $optLabel)
                                                        <option value="{{ $optVal }}">{{ $optLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif($riField->type === 'boolean')
                                                <input type="checkbox"
                                                       wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}.{{ $ri }}.{{ $riField->handle }}"
                                                       class="rounded border-gray-300">
                                            @else
                                                <input type="{{ $riField->type === 'number' ? 'number' : 'text' }}"
                                                       wire:model.live.debounce.400ms="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}.{{ $ri }}.{{ $riField->handle }}"
                                                       class="w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                            <button type="button"
                                    wire:click="addRepeaterItem({{ $si }}, {{ $ci }}, {{ $bi }}, '{{ $bField->handle }}')"
                                    class="flex w-full items-center justify-center gap-1 rounded border border-dashed border-gray-200 py-1.5 text-xs text-gray-400 hover:border-indigo-400 hover:text-indigo-600 dark:border-white/10 dark:hover:border-indigo-500">
                                <x-heroicon-o-plus class="h-3 w-3"/>
                                Add item
                            </button>
                        </div>
                    @elseif($bField->type === 'textarea' || $bField->type === 'richtext' || $bField->type === 'json')
                        <textarea wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                                  rows="3"
                                  class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white"></textarea>
                    @else
                        <input type="{{ $bField->type === 'number' ? 'number' : 'text' }}"
                               wire:model.live="sections.{{ $si }}.columns.{{ $ci }}.blocks.{{ $bi }}.data.{{ $bField->handle }}"
                               class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-white/10 dark:text-white">
                    @endif
                </div>
            @endforeach
        @else
            <p class="text-xs text-amber-600">Block type "{{ $block['block'] }}" is not registered (plugin may be disabled).</p>
        @endif
    </div>

</div>
