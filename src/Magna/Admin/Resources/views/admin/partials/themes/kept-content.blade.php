{{--
    The pages, parts and menus the last update of $theme left alone because
    this site had edited them (ThemeContentUpdates::kept). Each can take the
    theme's version; the replaced one stays in the page's history.

      $theme   — vendor/name
      $entries — list of ['key' => 'page:pricing@', 'label' => 'pricing']
--}}
@if ($entries !== [])
    <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-[12.5px] dark:border-white/10 dark:bg-white/5">
        <div class="flex items-center justify-between gap-2">
            <span class="font-semibold text-gray-800 dark:text-gray-200">
                Kept {{ count($entries) }} you edited
            </span>
            @if (count($entries) > 1)
                <button
                    wire:click="useThemeVersion('{{ $theme }}')"
                    wire:confirm="Replace all of these with the theme's version? Each replaced version stays in its history."
                    wire:loading.attr="disabled"
                    class="font-semibold text-primary-600 hover:underline dark:text-primary-400"
                >Use theme version for all</button>
            @endif
        </div>
        <p class="mt-0.5 text-gray-500 dark:text-gray-400">The update changed these too. Your version is live until you choose.</p>
        <ul class="mt-2 space-y-1">
            @foreach ($entries as $entry)
                <li class="flex items-center justify-between gap-2">
                    <span class="truncate font-mono text-[11.5px] text-gray-700 dark:text-gray-300">{{ $entry['label'] }}</span>
                    <button
                        wire:click="useThemeVersion('{{ $theme }}', '{{ $entry['key'] }}')"
                        wire:confirm="Replace your version of {{ $entry['label'] }} with the theme's? Yours stays in its history."
                        wire:loading.attr="disabled"
                        class="shrink-0 font-semibold text-primary-600 hover:underline dark:text-primary-400"
                    >Use theme version</button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
