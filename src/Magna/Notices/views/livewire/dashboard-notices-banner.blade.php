<div>
@if ($notice)
<div class="mb-6">
    @if ($notice->isSystemUpgrade())
        {{-- ==================== SYSTEM UPGRADE BANNER ==================== --}}
        <div wire:key="notice-{{ $notice->id }}" class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950/40 p-6 md:p-8 backdrop-blur-md shadow-2xl transition-all duration-300 hover:border-indigo-500/30 group">
            <div class="absolute -top-24 -left-24 h-48 w-48 rounded-full bg-indigo-500/10 blur-3xl group-hover:bg-indigo-500/20 transition-all duration-500"></div>
            <div class="absolute -bottom-24 -right-24 h-48 w-48 rounded-full bg-emerald-500/10 blur-3xl group-hover:bg-emerald-500/20 transition-all duration-500"></div>

            <button type="button" wire:click="dismiss({{ $notice->id }})"
                class="absolute top-4 right-4 z-10 rounded-full p-1 text-slate-500 hover:text-slate-200 hover:bg-white/5 transition-colors" aria-label="Dismiss">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <div class="relative flex flex-col md:flex-row items-center md:items-start gap-6 md:gap-8">
                <div class="w-24 h-24 md:w-28 md:h-28 rounded-2xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-cyan-400 p-0.5 shadow-xl shadow-indigo-500/10 flex-shrink-0 animate-pulse">
                    <div class="w-full h-full bg-slate-900 rounded-[14px] flex items-center justify-center overflow-hidden relative">
                        @if ($notice->image_url)
                            <img src="{{ $notice->image_url }}" alt="" class="w-full h-full object-cover">
                        @else
                            <svg class="w-12 h-12 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        @endif
                        <span class="absolute top-2 right-2 flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                    </div>
                </div>

                <div class="flex-1 text-center md:text-left space-y-2 min-w-0"
                     x-data="{ expanded: false, clamped: false }"
                     x-init="$nextTick(() => clamped = $refs.upgradeBody.scrollHeight > $refs.upgradeBody.clientHeight + 4)">
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold tracking-wide bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">System Upgrade</span>
                        @if ($notice->category_description)
                            <span class="text-xs text-slate-500 font-medium">{{ $notice->category_description }}</span>
                        @endif
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-white tracking-tight">{{ $notice->title }}</h2>
                    <p x-ref="upgradeBody"
                       class="text-sm md:text-base text-slate-400 max-w-xl leading-relaxed whitespace-pre-line break-words"
                       :class="expanded ? '' : 'line-clamp-3'">{{ $notice->description }}</p>
                    <button type="button" x-show="clamped || expanded" x-cloak x-on:click="expanded = !expanded"
                        class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-400 hover:text-indigo-300 transition-colors">
                        <span x-text="expanded ? 'Show less' : 'Read more'"></span>
                        <svg class="w-3.5 h-3.5 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                </div>

                <div class="w-full md:w-auto flex-shrink-0">
                    <a href="{{ $systemInfoUrl }}" wire:navigate
                        class="block text-center w-full md:w-auto px-6 py-3 rounded-xl font-semibold text-sm bg-gradient-to-r from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white shadow-lg shadow-indigo-500/20 hover:shadow-indigo-600/30 transform hover:-translate-y-0.5 transition-all active:translate-y-0 duration-200">
                        Update Now
                    </a>
                </div>
            </div>
        </div>
    @elseif ($notice->isWelcome())
        {{-- ==================== WELCOME BANNER ==================== --}}
        @php $mwbId = str()->random(8); @endphp
        <div wire:key="notice-{{ $notice->id }}" class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950/50 p-6 md:p-8 backdrop-blur-md shadow-2xl transition-all duration-300 hover:border-violet-500/20 group text-center">

            <button type="button" wire:click="dismiss({{ $notice->id }})" aria-label="Dismiss welcome banner"
                class="absolute top-4 right-4 z-10 p-1.5 rounded-xl bg-slate-900/60 border border-slate-800 text-slate-400 hover:text-white hover:border-slate-700 hover:bg-slate-800 transition duration-200">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <div class="absolute -top-20 -left-20 h-56 w-56 rounded-full bg-violet-600/10 blur-3xl group-hover:bg-violet-600/15 transition-all duration-500"></div>
            <div class="absolute -bottom-20 -right-20 h-56 w-56 rounded-full bg-fuchsia-600/10 blur-3xl group-hover:bg-fuchsia-600/15 transition-all duration-500"></div>

            <div class="relative flex flex-col items-center space-y-5">

                {{-- Application branding — the real animated Magna mark (see resources/views/filament/magna/brand.blade.php), not a placeholder icon. --}}
                <div class="w-20 h-20 md:w-24 md:h-24 rounded-2xl bg-gradient-to-tr from-violet-600 via-fuchsia-500 to-amber-400 p-0.5 shadow-xl shadow-violet-500/10 flex-shrink-0">
                    <div class="w-full h-full bg-slate-900 rounded-[14px] flex items-center justify-center overflow-hidden relative">
                        <svg class="w-14 h-14" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                            <defs>
                                <linearGradient id="mwb-grad-{{ $mwbId }}" x1="0" y1="0" x2="34" y2="34">
                                    <stop stop-color="#8b5cf6"/>
                                    <stop offset="1" stop-color="#d946ef"/>
                                </linearGradient>
                                <linearGradient id="mwb-neon-{{ $mwbId }}" x1="0" y1="0" x2="34" y2="34">
                                    <stop offset="0%" stop-color="#00f2fe"/>
                                    <stop offset="50%" stop-color="#4facfe"/>
                                    <stop offset="100%" stop-color="#f355da"/>
                                </linearGradient>
                                <style>
                                    .mwb-line-{{ $mwbId }} {
                                        stroke-dasharray: 28 56;
                                        stroke-dashoffset: 84;
                                        animation: mwb-chase 2s cubic-bezier(0.25, 1, 0.5, 1) infinite;
                                    }
                                    @keyframes mwb-chase {
                                        0%   { stroke-dasharray: 15 69; stroke-dashoffset: 84; }
                                        40%  { stroke-dasharray: 38 46; }
                                        100% { stroke-dasharray: 15 69; stroke-dashoffset: 0; }
                                    }
                                </style>
                            </defs>
                            <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mwb-grad-{{ $mwbId }})" stroke-width="2.4" fill="rgba(139,92,246,.08)"/>
                            <path class="mwb-line-{{ $mwbId }}" d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mwb-neon-{{ $mwbId }})" stroke-width="2.6" stroke-linecap="round"/>
                            <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mwb-grad-{{ $mwbId }})" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                        </svg>
                    </div>
                </div>

                <div class="space-y-4 w-full flex flex-col items-center">
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold tracking-wider bg-violet-500/10 text-violet-400 border border-violet-500/20 uppercase">Release Active</span>
                        @if ($notice->category_description)
                            <span class="text-xs text-slate-500 font-semibold bg-slate-800/60 px-2 py-0.5 rounded-md border border-slate-700/50">{{ $notice->category_description }}</span>
                        @endif
                    </div>

                    <div class="space-y-2">
                        <h2 class="text-2xl font-extrabold tracking-tight">
                            <span class="bg-clip-text text-transparent bg-gradient-to-r from-violet-400 via-fuchsia-400 to-amber-300">{{ $notice->title }}</span>
                        </h2>
                        <p class="text-sm text-slate-400 max-w-2xl leading-relaxed mx-auto">{{ $notice->description }}</p>
                    </div>

                    @php $links = $notice->welcomeLinks(); @endphp
                    @if (count($links) > 0)
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 pt-2 w-full max-w-2xl">
                            @foreach ($links as $link)
                                <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
                                    class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl bg-slate-900/60 border border-slate-800 hover:border-slate-700 hover:bg-slate-800 text-xs font-medium text-slate-300 hover:text-white transition">
                                    @switch($link['icon'])
                                        @case('github')
                                            <svg class="w-4 h-4 text-slate-400" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.166 6.839 9.489.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.603-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.462-1.11-1.462-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.831.092-.646.35-1.086.636-1.336-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.579.688.481C19.137 20.162 22 16.418 22 12c0-5.523-4.477-10-10-10z"/></svg>
                                            @break
                                        @case('docs')
                                            <svg class="w-4 h-4 text-violet-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                                            @break
                                        @case('blog')
                                            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                            @break
                                        @case('community')
                                            <svg class="w-4 h-4 text-fuchsia-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                            @break
                                        @case('themes')
                                            <svg class="w-4 h-4 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M4.098 19.902a3.75 3.75 0 015.304 0l6.401-6.402M4.098 19.902l9.158-9.157M10.334 9.666l6.402-6.402a2.651 2.651 0 113.75 3.75l-6.403 6.402M10.334 9.666L4.098 15.903m6.236-6.237L20.03 3.333" /></svg>
                                            @break
                                        @case('plugins')
                                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                            @break
                                    @endswitch
                                    {{ $link['label'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- ==================== ANNOUNCEMENT BANNER ==================== --}}
        <div wire:key="notice-{{ $notice->id }}" x-data="{ expanded: false, clamped: false }"
            x-init="$nextTick(() => clamped = $refs.annBody.scrollHeight > $refs.annBody.clientHeight + 4)"
            class="relative overflow-hidden rounded-3xl border border-slate-800 bg-slate-950/40 p-6 md:p-8 backdrop-blur-md shadow-2xl transition-all duration-300 hover:border-amber-500/30 group">
            <div class="absolute -top-24 -right-24 h-48 w-48 rounded-full bg-amber-500/5 blur-3xl group-hover:bg-amber-500/15 transition-all duration-500"></div>

            <button type="button" wire:click="dismiss({{ $notice->id }})"
                class="absolute top-4 right-4 z-10 rounded-full p-1 text-slate-500 hover:text-slate-200 hover:bg-white/5 transition-colors" aria-label="Dismiss">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <div class="relative flex flex-col md:flex-row items-center md:items-start gap-6 md:gap-8">
                <div class="w-24 h-24 md:w-28 md:h-28 rounded-2xl bg-gradient-to-tr from-amber-500 via-orange-500 to-yellow-400 p-0.5 shadow-xl shadow-amber-500/10 flex-shrink-0">
                    <div class="w-full h-full bg-slate-900 rounded-[14px] flex items-center justify-center overflow-hidden">
                        @if ($notice->image_url)
                            <img src="{{ $notice->image_url }}" alt="" class="w-full h-full object-cover">
                        @else
                            <svg class="w-12 h-12 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.63 8.41a14.98 14.98 0 00-6.16 12.12c1 .04 1.99-.18 2.91-.65l1.62-1.62a4.83 4.83 0 015.84-1.12l1.62-1.62zm0 0l-1.62 1.62m4.84-8.87a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
                            </svg>
                        @endif
                    </div>
                </div>

                <div class="flex-1 text-center md:text-left space-y-2">
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold tracking-wide bg-amber-500/10 text-amber-400 border border-amber-500/20">Announcement</span>
                        @if ($notice->category_description)
                            <span class="text-xs text-slate-500 font-medium">{{ $notice->category_description }}</span>
                        @endif
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-white tracking-tight">{{ $notice->title }}</h2>
                    <p x-ref="annBody"
                       class="text-sm md:text-base text-slate-400 max-w-xl leading-relaxed whitespace-pre-line break-words"
                       :class="expanded ? '' : 'line-clamp-3'">{{ $notice->description }}</p>
                </div>

                <div class="w-full md:w-auto flex-shrink-0" x-show="clamped || expanded" x-cloak>
                    <button type="button" x-on:click="expanded = !expanded"
                        class="w-full md:w-auto px-6 py-3 rounded-xl font-semibold text-sm bg-slate-800 hover:bg-slate-700 text-amber-400 border border-slate-700/80 hover:border-amber-500/30 hover:text-white transition-all duration-200 flex items-center justify-center gap-2 group/btn shadow-md">
                        <span x-text="expanded ? 'Show less' : 'Read More'"></span>
                        <svg class="w-4 h-4 transform transition-transform" :class="expanded ? '-rotate-90' : 'group-hover/btn:translate-x-1'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
@endif
</div>
