    {{-- ── 4 Stats Cards ────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">

        {{-- PHP Engine --}}
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-violet-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Engine PHP</span>
                <div class="p-2 rounded-lg bg-violet-100 dark:bg-violet-950/30 text-violet-500">
                    <span class="msri text-lg">memory</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-slate-900 dark:text-white">v{{ $php_version }}</h3>
                <p class="text-[11px] text-emerald-500 flex items-center gap-1 mt-1 font-medium">
                    <span class="msri text-xs">done_all</span>
                    Active Production Ready
                </p>
            </div>
        </div>

        {{-- Database --}}
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-sky-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Database Driver</span>
                <div class="p-2 rounded-lg bg-sky-100 dark:bg-sky-950/30 text-sky-500">
                    <span class="msri text-lg">database</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-slate-900 dark:text-white capitalize">{{ $db_driver }}</h3>
                <p class="text-[11px] text-slate-400 mt-1 font-mono">Client Engine v{{ $db_version }}</p>
            </div>
        </div>

        {{-- Environment --}}
        @php
            // Debug on in production is the one combination that leaks stack
            // traces, environment variables and query bindings to whoever
            // triggers an error, so it is called out in red — not shown in the
            // same tone as a developer machine with debug on, which is fine.
            //
            // Class names are written out in full rather than interpolated:
            // Tailwind scans the source for literal strings, so a class built
            // as "bg-{$tone}-100" is never compiled and the card renders with
            // no colour at all.
            $debug_is_dangerous = $debug_mode && $environment === 'production';

            $env_classes = match (true) {
                $debug_is_dangerous => [
                    'hover' => 'hover:border-red-500/30',
                    'chip' => 'bg-red-100 dark:bg-red-950/30 text-red-500',
                    'text' => 'text-red-500',
                    'dot' => 'bg-red-500',
                ],
                $environment === 'production' => [
                    'hover' => 'hover:border-emerald-500/30',
                    'chip' => 'bg-emerald-100 dark:bg-emerald-950/30 text-emerald-500',
                    'text' => 'text-emerald-500',
                    'dot' => 'bg-emerald-500',
                ],
                default => [
                    'hover' => 'hover:border-amber-500/30',
                    'chip' => 'bg-amber-100 dark:bg-amber-950/30 text-amber-500',
                    'text' => 'text-amber-500',
                    'dot' => 'bg-amber-500',
                ],
            };
        @endphp
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm {{ $env_classes['hover'] }} transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Environment Target</span>
                <div class="p-2 rounded-lg {{ $env_classes['chip'] }}">
                    <span class="msri text-lg">{{ $debug_is_dangerous ? 'gpp_maybe' : 'verified_user' }}</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono {{ $env_classes['text'] }} uppercase">{{ $environment }}</h3>
                @if($debug_mode)
                <p class="text-[11px] {{ $env_classes['text'] }} font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 {{ $env_classes['dot'] }} rounded-full animate-ping"></span>
                    Debug Mode Active{{ $debug_is_dangerous ? ' — turn it off' : '' }}
                </p>
                @else
                <p class="text-[11px] text-slate-400 flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                    Debug Mode Off
                </p>
                @endif
            </div>
        </div>

        {{-- Cache --}}
        @if($cache_status === 'ok')
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-emerald-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Cache Service</span>
                <div class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-950/30 text-emerald-500">
                    <span class="msri text-lg">cached</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-slate-900 dark:text-white capitalize">{{ $cache_driver }}</h3>
                <p class="text-[11px] text-emerald-500 font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full pulse-green"></span>
                    Status: OK
                </p>
            </div>
        </div>
        @else
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-red-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Cache Service</span>
                <div class="p-2 rounded-lg bg-red-100 dark:bg-red-950/30 text-red-500">
                    <span class="msri text-lg">cached</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-slate-900 dark:text-white capitalize">{{ $cache_driver }}</h3>
                <p class="text-[11px] text-red-500 font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full pulse-red"></span>
                    Status: ERROR
                </p>
            </div>
        </div>
        @endif
    </div>
