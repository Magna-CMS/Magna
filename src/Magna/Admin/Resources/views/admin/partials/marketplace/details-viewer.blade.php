{{-- Details modal: the full-size screenshot viewer (Alpine state `shot`/`shots` from details-modal). --}}
{{-- Screenshot viewer --}}
<template x-if="shot !== null">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/90 p-4" x-on:click.self="shot = null">
        <img :src="shots[shot]" alt="" class="max-h-[88vh] max-w-full rounded-lg shadow-2xl">
        <button type="button" x-on:click="shot = null" class="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="Close screenshot">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <button type="button" x-show="shots.length > 1" x-on:click="shot = (shot - 1 + shots.length) % shots.length" class="absolute left-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" aria-label="Previous screenshot">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <button type="button" x-show="shots.length > 1" x-on:click="shot = (shot + 1) % shots.length" class="absolute right-4 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20" aria-label="Next screenshot">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 text-xs text-white/70" x-text="(shot + 1) + ' / ' + shots.length"></div>
    </div>
</template>
