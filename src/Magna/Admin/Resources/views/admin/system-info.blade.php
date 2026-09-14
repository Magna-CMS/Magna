<x-filament-panels::page>

@assets
@include('magna::admin.partials.material-symbols-font')
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


    {{-- Core update in progress --}}
    @include('magna::admin.partials.system-info.update-banner')

    {{-- Security warnings (all environments) + performance warnings (production) --}}
    @include('magna::admin.partials.system-info.warnings')

    {{-- 4 stats cards --}}
    @include('magna::admin.partials.system-info.stats-cards')

    {{-- Software versions / infrastructure / environment / plugins panels --}}
    @include('magna::admin.partials.system-info.runtime-panels')

    {{-- Performance panel (full width) --}}
    @include('magna::admin.partials.system-info.performance')

    {{-- Terminal console --}}
    @include('magna::admin.partials.system-info.terminal')

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
