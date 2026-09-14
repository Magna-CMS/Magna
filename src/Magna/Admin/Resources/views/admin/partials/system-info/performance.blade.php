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
