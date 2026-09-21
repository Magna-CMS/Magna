{{--
    Alpine $persist uses global localStorage keys ('isOpen', 'isOpenDesktop')
    shared across all Filament panels on the same origin. If another panel's
    sidebar was collapsed, those keys read 'false' and this panel opens with
    an icon-only sidebar. On the first page-load of each browser session,
    pre-set the keys and re-open via the store after Alpine boots — after
    $persist has read its values, before the user has interacted.
--}}
<script>
    (function () {
        var SESSION_KEY = 'magna_sb_session_v1';
        if (sessionStorage.getItem(SESSION_KEY)) {
            return; // respect user's collapsed preference within session
        }
        sessionStorage.setItem(SESSION_KEY, '1');
        // Pre-set localStorage so Alpine $persist reads 'true' on init
        try {
            localStorage.setItem('isOpen', 'true');
            localStorage.setItem('isOpenDesktop', 'true');
        } catch (e) {}
        // Belt-and-suspenders: also set via the store after Alpine boots
        document.addEventListener('alpine:initialized', function () {
            try {
                var store = window.Alpine && window.Alpine.store('sidebar');
                if (store && typeof store.open === 'function') {
                    store.open();
                }
            } catch (e) {}
        });
    })();
</script>
