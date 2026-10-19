{{--
    An expired session (419) is the commonest "Error while loading page" in
    production: the admin left the tab open past the session lifetime, came
    back, clicked, and every Livewire request failed with the same generic
    toast. The panel's 419 error notification says what happened; this
    reloads the page so the login screen appears on its own instead of the
    admin pressing F5 on a dead tab. The delay lets the toast paint first.
--}}
<script data-magna-session-reload>
    document.addEventListener('livewire:init', () => {
        Livewire.interceptRequest(({ onError }) => {
            onError(({ response }) => {
                if (response?.status === 419) {
                    setTimeout(() => window.location.reload(), 1200)
                }
            })
        })
    })
</script>
