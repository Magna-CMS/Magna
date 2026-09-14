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
