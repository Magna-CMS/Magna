{{-- Details modal: version, requirements, permissions and publisher links. Included by details-modal. --}}
{{-- Details sidebar --}}
<aside class="space-y-6 text-sm">
    <section>
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Details</h3>
        <dl class="mt-3 space-y-2.5">
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Version</dt><dd class="font-medium text-gray-900 dark:text-white">{{ ltrim((string) $d['version'], 'vV') }}</dd></div>
            @if ($released)
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Updated</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $released->toFormattedDateString() }}</dd></div>
            @endif
            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Requires</dt><dd class="font-mono text-xs font-medium text-gray-900 dark:text-white">Magna {{ $d['compat'] }}</dd></div>
            @if ($installsLabel !== null)
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Installs</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $installsLabel }}</dd></div>
            @endif
            <div class="flex flex-col gap-0.5"><dt class="text-gray-500 dark:text-gray-400">Package</dt><dd class="break-all font-mono text-xs text-gray-900 dark:text-white">{{ $d['package'] }}</dd></div>
        </dl>
    </section>

    @if (! $isTheme && ($d['permissions'] ?? []) !== [])
        <section>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Permissions</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">What this plugin may do on your site.</p>
            <ul class="mt-2 space-y-1">
                @foreach ($d['permissions'] as $permission)
                    <li class="rounded-md bg-gray-50 px-2 py-1 font-mono text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $permission }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @php
        $links = array_filter([
            'Website' => $d['website'] ?? null,
            'Documentation' => $d['docs_url'] ?? null,
            'Source' => $d['homepage'] ?? null,
            'Video' => $d['video_url'] ?? null,
        ]);
    @endphp
    @if ($links !== [] || ($d['support_email'] ?? null))
        <section>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Publisher links</h3>
            <ul class="mt-2 space-y-1.5">
                @foreach ($links as $label => $url)
                    <li>
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400">
                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3h6v6M17 3l-8 8M8 5H5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3"/></svg>
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
                @if ($d['support_email'] ?? null)
                    <li>
                        <a href="mailto:{{ $d['support_email'] }}" class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400">
                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h14v10H3zM3 5l7 6 7-6"/></svg>
                            {{ $d['support_email'] }}
                        </a>
                    </li>
                @endif
            </ul>
        </section>
    @endif
</aside>
