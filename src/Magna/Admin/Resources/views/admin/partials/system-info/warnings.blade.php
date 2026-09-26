    {{-- ── Core update warnings (all environments) ─────────────────────────────── --}}
    @if(! empty($update_warnings))
    <div class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="msri text-orange-500 text-xl">system_update_alt</span>
            <h3 class="text-sm font-extrabold text-orange-700 dark:text-orange-400">This instance's core files need attention</h3>
        </div>
        <ul class="space-y-3">
            @foreach($update_warnings as $warning)
            <li class="text-sm">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $warning['label'] }}</p>
                <p class="text-gray-500 dark:text-gray-400 mt-0.5">{{ $warning['help'] }}</p>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- ── Security warnings (all environments) ───────────────────────────────── --}}
    @if(! empty($security_warnings))
    <div class="rounded-2xl border border-red-500/20 bg-red-500/5 p-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="msri text-red-500 text-xl">gpp_maybe</span>
            <h3 class="text-sm font-extrabold text-red-700 dark:text-red-400">A security control on this instance is not doing what you think it is</h3>
        </div>
        <ul class="space-y-3">
            @foreach($security_warnings as $warning)
            <li class="text-sm">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $warning['label'] }}</p>
                <p class="text-gray-500 dark:text-gray-400 mt-0.5">{{ $warning['help'] }}</p>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- ── Proactive performance warnings (production only) ───────────────────── --}}
    @if(! empty($performance_warnings))
    <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="msri text-amber-500 text-xl">warning</span>
            <h3 class="text-sm font-extrabold text-amber-700 dark:text-amber-400">This instance is running sub-optimally for production</h3>
        </div>
        <ul class="space-y-3">
            @foreach($performance_warnings as $warning)
            <li class="text-sm">
                <p class="font-semibold text-gray-900 dark:text-white">{{ $warning['label'] }}</p>
                <p class="text-gray-500 dark:text-gray-400 mt-0.5">{{ $warning['help'] }}</p>
            </li>
            @endforeach
        </ul>
        <a href="{{ \Magna\Admin\Pages\PerformanceSettingsPage::getUrl() }}" class="inline-flex items-center gap-1.5 mt-4 text-xs font-bold text-amber-700 dark:text-amber-400 hover:underline">
            <span class="msri text-sm">arrow_forward</span>
            Go to Performance settings
        </a>
    </div>
    @endif
