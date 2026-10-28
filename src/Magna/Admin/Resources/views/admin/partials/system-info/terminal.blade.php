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
                @can('settings.manage')
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
                    wire:click="runMigrations"
                    wire:confirm="Run all pending database migrations (host and every enabled plugin) now?"
                    wire:loading.attr="disabled"
                    wire:target="runMigrations"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-amber-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="runMigrations">migrate:run</span>
                    <span wire:loading wire:target="runMigrations">migrating…</span>
                </button>
                <button
                    wire:click="rebuildCaches"
                    wire:loading.attr="disabled"
                    wire:target="rebuildCaches"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-sky-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="rebuildCaches">caches:rebuild</span>
                    <span wire:loading wire:target="rebuildCaches">rebuilding…</span>
                </button>
                <button
                    wire:click="publishAssets"
                    wire:loading.attr="disabled"
                    wire:target="publishAssets"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-teal-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="publishAssets">assets:publish</span>
                    <span wire:loading wire:target="publishAssets">publishing…</span>
                </button>
                <button
                    wire:click="restartQueue"
                    wire:loading.attr="disabled"
                    wire:target="restartQueue"
                    class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 text-xs font-bold font-mono text-orange-400 rounded-lg transition-all active:scale-[0.98] disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="restartQueue">queue:restart</span>
                    <span wire:loading wire:target="restartQueue">signalling…</span>
                </button>
                @endcan
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
                run maintenance commands with the buttons above — output appears here…
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
