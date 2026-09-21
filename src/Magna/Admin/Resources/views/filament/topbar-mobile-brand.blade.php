{{-- Mobile-only topbar brand mark — see AdminPanelProvider's TOPBAR_START render
     hook registration for why this exists alongside the desktop .fi-topbar-start
     logo and the mobile sidebar drawer's own copy. Positioning (between the
     sidebar-toggle button and the search bar) and the lg+ display:none are in
     theme.css under "Mobile topbar brand mark". --}}
<div class="fi-topbar-mobile-brand">
    @include('magna::filament.brand')
</div>
