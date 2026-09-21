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
            <div class="bg-white dark:bg-slate-900 border border-slate-200/50 dark:border-slate-800/50 rounded-3xl p-6 shadow-sm">
                <span class="text-[10px] uppercase font-bold text-amber-500 tracking-wider block font-mono">App Environment</span>
                <h4 class="text-md font-extrabold text-slate-900 dark:text-white mt-1">Environment Flag & Debug</h4>

                {{-- Debug status. The control opens a BOUNDED window and is
                     super-admin only: the hazard in APP_DEBUG is not switching
                     it on to chase a fault, it is forgetting to switch it off,
                     so the window closes itself (Magna\Support\DebugWindow). --}}
                <div class="mt-5 p-4 rounded-2xl bg-amber-500/5 border border-amber-500/10">
                    <div class="flex items-center gap-3">
                        <div class="w-2.5 h-2.5 rounded-full {{ $debug_mode ? 'pulse-amber' : '' }}" style="background:{{ $debug_mode ? '#f59e0b' : '#94a3b8' }}"></div>
                        <div>
                            <span class="text-xs font-bold text-slate-600 dark:text-slate-300 block">Debug Mode Status</span>
                            <span class="text-[11px] font-mono font-semibold uppercase {{ $debug_mode ? 'text-amber-500' : 'text-slate-400' }}">{{ $debug_mode ? 'ENABLED' : 'DISABLED' }}</span>
                        </div>
                    </div>
                    @if (auth()->user()?->isSuperAdmin())
                        <form method="POST" action="{{ route('magna.admin.debug-mode') }}" class="mt-3"
                              onsubmit="return {{ $debug_mode ? 'true' : "confirm('Turn debug mode on? Until it expires, stack traces, SQL and environment values are shown to EVERY visitor of this site, not just to you.')" }};">
                            @csrf
                            <button type="submit" class="text-[11px] font-semibold {{ $debug_mode ? 'text-emerald-600 hover:text-emerald-700 dark:text-emerald-400' : 'text-amber-600 hover:text-amber-700 dark:text-amber-400' }}">
                                {{ $debug_mode ? 'Turn debug mode off now' : 'Turn on for 30 minutes' }}
                            </button>
                        </form>
                        <p class="text-[11px] text-slate-400 mt-2 leading-normal">
                            A window opened here closes itself. Set <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">APP_DEBUG</code> in the server's <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">.env</code> to hold it open indefinitely.
                        </p>
                    @else
                        <p class="text-[11px] text-slate-400 mt-3 leading-normal">
                            Set <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">APP_DEBUG</code> in the server's <code class="bg-slate-100 dark:bg-slate-800 px-1 rounded text-[10px]">.env</code> file.
                        </p>
                    @endif
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
