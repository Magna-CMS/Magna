<x-filament-panels::page>
{{--
    Computed variables injected by PluginsPage::getViewData() (called on every render):
      $filteredInstalled  — installed plugins after status filter + search
      $filteredAvailable  — available plugins after search
      $counts             — ['all', 'active', 'inactive', 'update'] plugin counts
      $filteredNames      — names of currently visible installed plugins
      $allSelected        — bool: every visible row is checked
--}}

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-1">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ $this->activeTab === 'installed' ? 'Plugins' : 'Add New Plugin' }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            @if ($this->activeTab === 'installed')
                Manage the plugins installed in this panel.
            @else
                Discover plugins from Composer and your local <code class="font-mono text-xs">plugins-dev/</code> directory.
            @endif
        </p>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        @if ($this->activeTab === 'installed')
            <button
                wire:click="setTab('addnew')"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 shadow-sm transition-colors"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Add New Plugin
            </button>
        @else
            <button
                wire:click="setTab('installed')"
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 19l-7-7 7-7"/></svg>
                Back to Installed
            </button>
        @endif
    </div>
</div>

{{-- ── Tabs ─────────────────────────────────────────────────────────────────── --}}
<div class="flex gap-6 border-b border-gray-200 dark:border-white/10 mt-6 mb-6 text-sm">
    <button
        wire:click="setTab('installed')"
        class="pb-3 border-b-2 font-semibold transition-colors
               {{ $this->activeTab === 'installed'
                    ? 'border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
    >Installed Plugins</button>
    <button
        wire:click="setTab('addnew')"
        class="pb-3 border-b-2 font-semibold transition-colors
               {{ $this->activeTab === 'addnew'
                    ? 'border-primary-600 text-primary-700 dark:text-primary-400'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
    >Add New Plugin</button>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════════
     INSTALLED PANEL
     ═══════════════════════════════════════════════════════════════════════════ --}}
@if ($this->activeTab === 'installed')

    {{-- Installed panel: status filters, bulk actions, plugins table. --}}
    @include('magna::admin.partials.plugins.installed-panel')


{{-- ═══════════════════════════════════════════════════════════════════════════
     ADD NEW PANEL
     ═══════════════════════════════════════════════════════════════════════════ --}}
@else

    {{-- Catalog panel: install queue, search, marketplace cards. --}}
    @include('magna::admin.partials.plugins.catalog-panel')


@endif

@include('magna::admin.partials.checkout')

<x-filament-actions::modals />

</x-filament-panels::page>
