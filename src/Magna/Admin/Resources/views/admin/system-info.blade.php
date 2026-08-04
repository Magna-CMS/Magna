<x-filament-panels::page>

@assets
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0..1,0" rel="stylesheet">
<style>
.msri {
    font-family: 'Material Symbols Rounded';
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    display: inline-block; line-height: 1; vertical-align: -3px;
}
.pulse-green  { box-shadow: 0 0 0 0 rgba(16,185,129,.7);  animation: syi-pg 1.8s infinite; }
.pulse-amber  { box-shadow: 0 0 0 0 rgba(245,158,11,.7);  animation: syi-pa 1.8s infinite; }
.pulse-red    { box-shadow: 0 0 0 0 rgba(239,68,68,.7);   animation: syi-pr 1.8s infinite; }
@keyframes syi-pg { 70% { box-shadow: 0 0 0 8px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
@keyframes syi-pa { 70% { box-shadow: 0 0 0 8px rgba(245,158,11,0); } 100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); } }
@keyframes syi-pr { 70% { box-shadow: 0 0 0 8px rgba(239,68,68,0);  } 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0);  } }
/* Own colours rather than Tailwind opacity utilities, so the bar can't come out
   invisible if the panel's compiled stylesheet lacks a given amber/opacity pair. */
.syi-uptrack { height: 8px; width: 100%; overflow: hidden; border-radius: 99px; background: rgba(245,158,11,.18); }
.syi-upbar {
    position: relative; height: 100%; border-radius: 99px; background: #f59e0b;
    min-width: 6px; transition: width .7s cubic-bezier(.4,0,.2,1);
}
.syi-upbar::after {
    content: ''; position: absolute; inset: 0; border-radius: 99px;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent);
    animation: syi-shimmer 1.4s linear infinite;
}
@keyframes syi-shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
.syi-upstep { animation: syi-stepin .35s ease-out; }
@keyframes syi-stepin { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
.syi-uppulse { animation: syi-blink 1.2s ease-in-out infinite; }
@keyframes syi-blink { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
@media (prefers-reduced-motion: reduce) {
    .syi-upbar::after, .syi-upstep, .syi-uppulse { animation: none; }
}
#sysTerminal::-webkit-scrollbar       { width: 5px; }
#sysTerminal::-webkit-scrollbar-thumb { background: #334155; border-radius: 99px; }
</style>
@endassets

<div class="space-y-8">

    {{-- ── Core update in progress ─────────────────────────────────────────────── --}}
    @if($updating)
    @php
        $updateProgress = \Magna\Updater\CoreUpdater::progress();
        $updateTitle    = 'Updating Magna CMS'.($updateProgress['version'] ? ' v'.$updateProgress['version'] : '');
        $updatePercent  = max(1, $updateProgress['percent']);
        $updateSteps    = array_slice($updateProgress['log'], -4);
    @endphp
    <div wire:poll.2s="pollCoreUpdate" class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-5">
        <div class="flex items-center gap-3">
            <svg class="w-4 h-4 shrink-0 animate-spin text-amber-500" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-sm font-bold text-amber-700 dark:text-amber-400">{{ $updateTitle }}…</p>
                    <p class="text-xs font-bold tabular-nums text-amber-700 dark:text-amber-400">{{ $updatePercent }}%</p>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $updateProgress['message'] ?: 'Starting…' }}</p>
            </div>
        </div>

        {{-- Percentage is step-based, not byte-based: the download's size isn't known
             up front, so the bar carries a shimmer to show work is still happening
             between steps rather than implying it has stalled. --}}
        <div class="syi-uptrack mt-4" role="progressbar"
             aria-valuenow="{{ $updatePercent }}" aria-valuemin="0" aria-valuemax="100"
             aria-label="Core update progress">
            <div class="syi-upbar" style="width: {{ $updatePercent }}%"></div>
        </div>

        @if($updateSteps !== [])
        <ul class="mt-3 space-y-1">
            @foreach($updateSteps as $stepIndex => $step)
            @php $isCurrent = $stepIndex === array_key_last($updateSteps); @endphp
            <li wire:key="syi-step-{{ $step['percent'] }}-{{ md5($step['message']) }}"
                class="syi-upstep flex items-center gap-2 text-xs {{ $isCurrent ? 'text-gray-700 dark:text-gray-200 font-medium' : 'text-gray-400 dark:text-gray-500' }}">
                @if($isCurrent)
                <span class="msri text-amber-500 text-sm syi-uppulse">radio_button_checked</span>
                @else
                <span class="msri text-emerald-500 text-sm">check_circle</span>
                @endif
                <span class="truncate">{{ $step['message'] }}</span>
            </li>
            @endforeach
        </ul>
        @endif
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
        @if($environment === 'production')
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-emerald-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Environment Target</span>
                <div class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-950/30 text-emerald-500">
                    <span class="msri text-lg">shield_with_house</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-emerald-500 uppercase">{{ $environment }}</h3>
                <p class="text-[11px] text-emerald-500 font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>
                    Debug Mode Off
                </p>
            </div>
        </div>
        @else
        <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-2xl p-5 shadow-sm hover:border-amber-500/30 transition-all">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Environment Target</span>
                <div class="p-2 rounded-lg bg-amber-100 dark:bg-amber-950/30 text-amber-500">
                    <span class="msri text-lg">shield_with_house</span>
                </div>
            </div>
            <div class="mt-4">
                <h3 class="text-2xl font-black font-mono text-amber-500 uppercase">{{ $environment }}</h3>
                @if($debug_mode)
                <p class="text-[11px] text-amber-500 font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-ping"></span>
                    Debug Mode Active
                </p>
                @else
                <p class="text-[11px] text-slate-400 flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 bg-slate-400 rounded-full"></span>
                    Debug Mode Off
                </p>
                @endif
            </div>
        </div>
        @endif

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

    {{-- ── Primary Layout (2/3 + 1/3) ──────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

        {{-- Left column (2/3) --}}
        <div class="lg:col-span-2 space-y-8">

            {{-- Software Versions panel --}}
            <section class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 lg:p-8 shadow-sm">
                <div class="flex items-center justify-between pb-6 border-b border-slate-100 dark:border-slate-800/80">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-violet-500 tracking-widest font-mono">Software Spec</span>
                        <h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Framework Runtime Versions</h3>
                    </div>
                    <span class="msri text-slate-300 dark:text-slate-600 text-2xl">deployed_code</span>
                </div>

                <div class="divide-y divide-slate-100 dark:divide-slate-800/40 font-medium">
                    {{-- Magna CMS --}}
                    <div class="py-4 flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                        <span class="text-sm text-slate-400 flex items-center gap-2">
                            <span class="w-2 h-2 rounded bg-violet-500 flex-shrink-0"></span>
                            <span>Magna CMS Edition</span>
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-1 font-mono text-xs font-bold rounded-lg bg-violet-50 dark:bg-violet-950/40 text-violet-600 dark:text-violet-400 border border-violet-100 dark:border-violet-900/30">{{ $magna_version }}</span>
                            <span class="text-[11px] font-bold text-slate-400 font-mono">Active Dev Node</span>
                        </div>
                    </div>
                    {{-- Laravel --}}
                    <div class="py-4 flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                        <span class="text-sm text-slate-400 flex items-center gap-2">
                            <span class="w-2 h-2 rounded bg-rose-500 flex-shrink-0"></span>
                            <span>Laravel Framework</span>
                        </span>
                        <div class="flex items-center gap-2 font-mono">
                            <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $laravel_version }}</span>
                            <span class="text-xs text-slate-400">(LTS)</span>
                        </div>
                    </div>
                    {{-- PHP --}}
                    <div class="py-4 flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                        <span class="text-sm text-slate-400 flex items-center gap-2">
                            <span class="w-2 h-2 rounded bg-indigo-500 flex-shrink-0"></span>
                            <span>PHP Engine</span>
                        </span>
                        <div class="flex items-center gap-2 font-mono">
                            <span class="text-sm font-bold text-slate-800 dark:text-slate-100">{{ $php_version }}</span>
                            <span class="text-[10px] px-2 py-0.5 bg-emerald-500/10 text-emerald-500 rounded font-semibold">cli</span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Infrastructure panel --}}
            <section class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 lg:p-8 shadow-sm">
                <div class="flex items-center justify-between pb-6 border-b border-slate-100 dark:border-slate-800/80">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-sky-500 tracking-widest font-mono">Infrastructure Components</span>
                        <h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Database & Services Node Configuration</h3>
                    </div>
                    <span class="msri text-slate-300 dark:text-slate-600 text-2xl">settings_suggest</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                    {{-- DB Driver --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-500">
                                <span class="msri text-lg">database</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">DB Driver</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize">{{ $db_driver }}</span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold">Driver</span>
                    </div>

                    {{-- DB Version --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-500">
                                <span class="msri text-lg">history_edu</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">DB Version</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">{{ $db_version }}</span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold">v{{ explode('.', $db_version)[0] ?? '?' }}</span>
                    </div>

                    {{-- Cache --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-500">
                                <span class="msri text-lg">cloud_sync</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Cache Connection</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize">{{ $cache_driver }}</span>
                            </div>
                        </div>
                        @if($cache_status === 'ok')
                        <span class="text-xs px-2 py-0.5 bg-emerald-500/15 text-emerald-500 rounded font-mono font-bold">OK</span>
                        @else
                        <span class="text-xs px-2 py-0.5 bg-red-500/15 text-red-500 rounded font-mono font-bold">ERR</span>
                        @endif
                    </div>

                    {{-- Queue --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-violet-500/10 text-violet-500">
                                <span class="msri text-lg">reorder</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Queue Connection</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize">{{ $queue_connection }}</span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold capitalize">{{ $queue_connection }}</span>
                    </div>

                    {{-- Backup --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl {{ $backup_health['color'] === 'ok' ? 'bg-emerald-500/10 text-emerald-500' : ($backup_health['color'] === 'warning' ? 'bg-amber-500/10 text-amber-500' : 'bg-slate-400/10 text-slate-400') }}">
                                <span class="msri text-lg">backup</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Last Successful Backup</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">{{ $backup_health['label'] }}</span>
                            </div>
                        </div>
                        @if($backup_health['color'] === 'ok')
                        <span class="text-xs px-2 py-0.5 bg-emerald-500/15 text-emerald-500 rounded font-mono font-bold">OK</span>
                        @elseif($backup_health['color'] === 'warning')
                        <a href="{{ \Magna\Admin\Pages\BackupSettingsPage::getUrl() }}" class="text-xs px-2 py-0.5 bg-amber-500/15 text-amber-500 rounded font-mono font-bold">CHECK</a>
                        @else
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold">OFF</span>
                        @endif
                    </div>

                    {{-- Octane --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between md:col-span-2">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl {{ $octane_running ? 'bg-emerald-500/10 text-emerald-500' : 'bg-slate-400/10 text-slate-400' }}">
                                <span class="msri text-lg">bolt</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Octane Runtime</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize">
                                    {{ $octane_running ? 'Running ('.$octane_server.')' : ($octane_installed ? 'Installed, not running' : 'Not installed') }}
                                </span>
                            </div>
                        </div>
                        @if($octane_running)
                        <span class="text-xs px-2 py-0.5 bg-emerald-500/15 text-emerald-500 rounded font-mono font-bold">ON</span>
                        @else
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold">OFF</span>
                        @endif
                    </div>

                    {{-- Storage --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between md:col-span-2">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-indigo-500/10 text-indigo-500">
                                <span class="msri text-lg">hard_drive</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Storage Disk</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize">{{ $storage_disk }}://{{ storage_path() }}</span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-0.5 bg-indigo-500/15 text-indigo-500 rounded font-mono font-bold capitalize">{{ $storage_disk }}</span>
                    </div>
                </div>
            </section>

        </div>

        {{-- Right column (1/3) --}}
        <div class="space-y-8">

            {{-- Environment Flag panel --}}
            <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 shadow-sm"
                 x-data="{ debugOn: @js($debug_mode) }">
                <span class="text-[10px] uppercase font-bold text-amber-500 tracking-wider block font-mono">App Environment</span>
                <h4 class="text-md font-extrabold text-slate-900 dark:text-white mt-1">Environment Flag & Debug</h4>

                {{-- Debug toggle --}}
                <div class="mt-5 p-4 rounded-2xl bg-amber-500/5 border border-amber-500/10">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full {{ $debug_mode ? 'pulse-amber' : '' }}" style="background:{{ $debug_mode ? '#f59e0b' : '#94a3b8' }}"></div>
                            <div>
                                <span class="text-xs font-bold text-slate-600 dark:text-slate-300 block">Debug Mode Status</span>
                                <span class="text-[11px] font-mono font-semibold uppercase {{ $debug_mode ? 'text-amber-500' : 'text-slate-400' }}">{{ $debug_mode ? 'ENABLED' : 'DISABLED' }}</span>
                            </div>
                        </div>
                        <button @click="debugOn = !debugOn; $wire.toggleDebugMode()"
                            class="w-12 h-6 rounded-full p-0.5 transition-all relative flex items-center focus:outline-none"
                            :class="debugOn ? 'bg-amber-500' : 'bg-slate-300 dark:bg-slate-700'">
                            <span class="w-5 h-5 rounded-full bg-white shadow-md transition-all duration-300"
                                  :class="debugOn ? 'translate-x-6' : 'translate-x-0'"></span>
                        </button>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-3 leading-normal">
                        Writes <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">APP_DEBUG</code> to <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">.env</code> — active immediately.
                    </p>
                </div>

                {{-- Env details --}}
                <div class="mt-5 space-y-3.5 pt-5 border-t border-slate-100 dark:border-slate-800/40">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-medium">Environment Value:</span>
                        <span class="font-mono font-bold text-slate-800 dark:text-slate-100">{{ $environment }}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-medium">Domain Node Host:</span>
                        <span class="font-mono font-bold text-slate-800 dark:text-slate-100">{{ $app_url }}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-medium">Session Lifetime:</span>
                        <span class="font-mono font-bold text-slate-800 dark:text-slate-100">{{ $session_lifetime }} minutes</span>
                    </div>
                </div>
            </div>

            {{-- Plugins panel --}}
            <section class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 shadow-sm">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800/80">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block font-mono">Modularity System</span>
                        <h3 class="text-md font-extrabold text-slate-900 dark:text-white">Active Plugin Modules</h3>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500">
                        <span class="msri text-lg">extension</span>
                    </div>
                </div>

                {{-- Plugin counts --}}
                <div class="grid grid-cols-3 gap-2 text-center mt-6">
                    <div class="p-3 bg-slate-50 dark:bg-slate-950/40 rounded-2xl border border-slate-200/10">
                        <span class="text-xl font-black block font-mono text-slate-900 dark:text-white">{{ $plugins_total }}</span>
                        <span class="text-[9px] uppercase tracking-wider text-slate-400 font-bold block mt-1">Installed</span>
                    </div>
                    <div class="p-3 bg-slate-50 dark:bg-slate-950/40 rounded-2xl border border-slate-200/10">
                        <span class="text-xl font-black block font-mono text-emerald-500">{{ $plugins_enabled }}</span>
                        <span class="text-[9px] uppercase tracking-wider text-slate-400 font-bold block mt-1">Enabled</span>
                    </div>
                    <div class="p-3 bg-slate-50 dark:bg-slate-950/40 rounded-2xl border border-slate-200/10">
                        <span class="text-xl font-black block font-mono text-slate-400">{{ $plugins_disabled }}</span>
                        <span class="text-[9px] uppercase tracking-wider text-slate-400 font-bold block mt-1">Disabled</span>
                    </div>
                </div>

                <div class="mt-5 pt-5 border-t border-slate-100 dark:border-slate-800/40">
                    <a href="{{ \Magna\Admin\Pages\PluginsPage::getUrl() }}" class="w-full flex items-center justify-center gap-2 py-2.5 bg-violet-600/10 hover:bg-violet-600/20 text-violet-600 dark:text-violet-400 font-bold text-xs rounded-xl transition-all border border-violet-500/15">
                        <span class="msri text-sm">open_in_new</span>
                        <span>Manage Plugins</span>
                    </a>
                </div>
            </section>
        </div>
    </div>

    {{-- ── Performance panel (full width) ──────────────────────────────────── --}}
    <section class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 lg:p-8 shadow-sm">
        <div class="flex items-center justify-between pb-6 border-b border-slate-100 dark:border-slate-800/80">
            <div>
                <span class="text-[10px] uppercase font-bold text-emerald-500 tracking-widest font-mono">Runtime Metrics</span>
                <h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Performance</h3>
            </div>
            <span class="msri text-slate-300 dark:text-slate-600 text-2xl">speed</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">

            {{-- Left: metrics (2/3) --}}
            <div class="lg:col-span-2 space-y-4">
                {{-- Configuration recap: what's actually driving the runtime numbers
                     below. Cache/Queue driver names and the Octane server choice
                     already appear individually in Infrastructure Components above;
                     repeated here so this section reads standalone without having
                     to cross-reference another panel. --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30">
                        <span class="text-xs text-slate-400 block font-semibold uppercase">Cache Driver</span>
                        <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize mt-1 block">{{ $cache_driver }}</span>
                    </div>
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30">
                        <span class="text-xs text-slate-400 block font-semibold uppercase">Queue Connection</span>
                        <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize mt-1 block">{{ $queue_connection }}</span>
                    </div>
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30">
                        <span class="text-xs text-slate-400 block font-semibold uppercase">Octane Server (configured)</span>
                        <span class="text-sm font-bold font-mono text-slate-800 dark:text-white capitalize mt-1 block">{{ $octane_server }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Boot time --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl {{ $boot_time_ms < 50 ? 'bg-emerald-500/10 text-emerald-500' : ($boot_time_ms < 200 ? 'bg-amber-500/10 text-amber-500' : 'bg-red-500/10 text-red-500') }}">
                                <span class="msri text-lg">timer</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Framework Boot Time</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">{{ $boot_time_ms }} ms</span>
                            </div>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded font-mono font-bold {{ $octane_running ? 'bg-emerald-500/15 text-emerald-500' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300' }}">
                            {{ $octane_running ? 'OCTANE' : 'PER-REQUEST' }}
                        </span>
                    </div>

                    {{-- Memory usage --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-500">
                                <span class="msri text-lg">memory</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Memory (current / peak)</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">{{ $memory_current_mb }} / {{ $memory_peak_mb }} MB</span>
                            </div>
                        </div>
                    </div>

                    {{-- OPcache --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl {{ $opcache['enabled'] ? 'bg-emerald-500/10 text-emerald-500' : 'bg-slate-400/10 text-slate-400' }}">
                                <span class="msri text-lg">bolt</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">PHP OPcache</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">
                                    @if($opcache['enabled'])
                                        Enabled{{ $opcache['hit_rate'] !== null ? ' · '.$opcache['hit_rate'].'% hits' : '' }}
                                    @else
                                        Disabled
                                    @endif
                                </span>
                            </div>
                        </div>
                        @if($opcache['enabled'])
                        <span class="text-xs px-2 py-0.5 bg-emerald-500/15 text-emerald-500 rounded font-mono font-bold">ON</span>
                        @else
                        <span class="text-xs px-2 py-0.5 bg-slate-200 dark:bg-slate-800 text-slate-400 dark:text-slate-300 rounded font-mono font-bold">OFF</span>
                        @endif
                    </div>

                    {{-- Cache latency --}}
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 rounded-xl bg-violet-500/10 text-violet-500">
                                <span class="msri text-lg">cloud_sync</span>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block font-semibold uppercase">Cache Round-Trip</span>
                                <span class="text-sm font-bold font-mono text-slate-800 dark:text-white">{{ $cache_latency_ms !== null ? $cache_latency_ms.' ms' : 'unavailable' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: Queue Backlog (1/3, stretches to the left column's full height) --}}
            <div class="lg:col-span-1 h-full flex flex-col rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200/30 dark:border-slate-700/30 p-5">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-500">
                        <span class="msri text-lg">pending_actions</span>
                    </div>
                    <div>
                        <span class="text-xs text-slate-400 block font-semibold uppercase">Queue Backlog</span>
                        <span class="text-[11px] font-mono font-bold text-slate-400 capitalize">{{ $queue_connection }} driver</span>
                    </div>
                </div>

                <div class="flex-1 flex flex-col justify-center gap-4 mt-6">
                    {{-- Pending --}}
                    <div class="p-4 rounded-2xl bg-amber-500/5 border border-amber-500/10">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Pending</span>
                            @if($queue_pending)
                            <span class="w-2 h-2 rounded-full bg-amber-500 pulse-amber"></span>
                            @endif
                        </div>
                        <span class="text-3xl font-black font-mono text-slate-900 dark:text-white block mt-2">
                            {{ $queue_pending !== null ? $queue_pending : '—' }}
                        </span>
                        <span class="text-[11px] text-slate-400 mt-1 block">
                            {{ $queue_pending !== null ? 'job(s) waiting to run' : 'not available for "'.$queue_connection.'" driver' }}
                        </span>

                        {{-- A count alone cannot tell a busy queue from a dead
                             one. Fifteen minutes of waiting can: no worker is
                             consuming this queue, and nothing queued will run
                             until one does. --}}
                        @if (($queue_oldest_minutes ?? null) !== null && $queue_oldest_minutes >= 15)
                            @php
                                $waited = $queue_oldest_minutes >= 2880
                                    ? floor($queue_oldest_minutes / 1440).' day(s)'
                                    : ($queue_oldest_minutes >= 120
                                        ? floor($queue_oldest_minutes / 60).' hour(s)'
                                        : $queue_oldest_minutes.' minutes');
                            @endphp
                            <div class="mt-3 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3">
                                <p class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">No queue worker is running</p>
                                <p class="mt-1 text-[11px] leading-relaxed text-amber-700/80 dark:text-amber-300/80">
                                    The oldest job has waited {{ $waited }}. Magna drains the queue automatically every minute
                                    through the cron scheduler — a backlog this old means the scheduler cron is not running either.
                                    Add <code class="font-mono">* * * * * php artisan schedule:run</code> to cron, or run a
                                    dedicated worker with <code class="font-mono">php artisan queue:work</code>.
                                </p>
                            </div>
                        @endif
                    </div>

                    {{-- Failed --}}
                    <div class="p-4 rounded-2xl {{ $queue_failed > 0 ? 'bg-red-500/5 border border-red-500/10' : 'bg-slate-100/60 dark:bg-slate-800/30 border border-slate-200/20 dark:border-slate-700/20' }}">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold {{ $queue_failed > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400' }} uppercase tracking-wider">Failed</span>
                            @if($queue_failed > 0)
                            <span class="w-2 h-2 rounded-full bg-red-500 pulse-red"></span>
                            @endif
                        </div>
                        <span class="text-3xl font-black font-mono {{ $queue_failed > 0 ? 'text-red-500' : 'text-slate-900 dark:text-white' }} block mt-2">{{ $queue_failed }}</span>
                        <span class="text-[11px] text-slate-400 mt-1 block">job(s) failed permanently</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Terminal Console ─────────────────────────────────────────────────── --}}
    <section class="bg-slate-950 text-slate-100 rounded-3xl p-6 lg:p-8 shadow-xl border border-slate-800/80">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-800">
            <div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full bg-rose-500 flex-shrink-0"></span>
                    <span class="w-3 h-3 rounded-full bg-amber-500 flex-shrink-0"></span>
                    <span class="w-3 h-3 rounded-full bg-emerald-500 flex-shrink-0"></span>
                    <span class="text-xs font-mono text-slate-500 ml-2">magna-cms@cli-engine:~</span>
                </div>
                <h3 class="text-lg font-extrabold text-white mt-2">Active Dev Console & Diagnostics</h3>
            </div>

            {{-- Console action buttons --}}
            <div class="flex flex-wrap items-center gap-2">
                <button
                    wire:click="runDiagnostics"
                    wire:loading.attr="disabled"
                    wire:target="runDiagnostics"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-emerald-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="runDiagnostics">diagnostics:run</span>
                    <span wire:loading wire:target="runDiagnostics">running…</span>
                </button>
                <button
                    wire:click="clearCache"
                    wire:loading.attr="disabled"
                    wire:target="clearCache"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-violet-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="clearCache">cache:clear</span>
                    <span wire:loading wire:target="clearCache">clearing…</span>
                </button>
                <button
                    wire:click="clearTerminal"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-rose-400 rounded-lg transition-all active:scale-[0.98]"
                >terminal:clear</button>
            </div>
        </div>

        {{-- Terminal output --}}
        <div id="sysTerminal" class="mt-6 font-mono text-xs space-y-2 h-64 overflow-y-auto p-4 bg-black/30 rounded-2xl border border-slate-900 leading-relaxed text-slate-300">
            <p class="text-slate-600">// Magna CMS Shell Client Interface initialized.</p>
            <p class="text-slate-600">// Running on {{ $environment }} environment — PHP {{ $php_version }} / Laravel {{ $laravel_version }}.</p>
            @if(empty($terminalLines))
            <p class="text-slate-500">
                <span class="text-violet-500">magna-cms$</span>
                type commands below or click mock triggers to run diagnostic operations…
            </p>
            @endif
            @foreach($terminalLines as $line)
            <p>
                @if($line['type'] === 'cmd')
                    <span class="text-slate-500 font-bold">magna-cms$</span>
                    <span class="text-violet-400">{{ $line['text'] }}</span>
                @elseif($line['type'] === 'init')
                    <span class="text-slate-500 font-bold">magna-cms$</span>
                    <span class="text-sky-400">{{ $line['text'] }}</span>
                @elseif($line['type'] === 'success')
                    <span class="text-slate-500 font-bold">magna-cms$</span>
                    <span class="text-emerald-400">{{ $line['text'] }}</span>
                @elseif($line['type'] === 'error')
                    <span class="text-slate-500 font-bold">magna-cms$</span>
                    <span class="text-red-400">{{ $line['text'] }}</span>
                @else
                    <span class="text-slate-500 font-bold">magna-cms$</span>
                    <span class="text-slate-300">{{ $line['text'] }}</span>
                @endif
            </p>
            @endforeach
        </div>
    </section>
</div>

@script
<script>
document.addEventListener('livewire:update', () => {
    const el = document.getElementById('sysTerminal');
    if (el) el.scrollTop = el.scrollHeight;
});
</script>
@endscript

</x-filament-panels::page>
