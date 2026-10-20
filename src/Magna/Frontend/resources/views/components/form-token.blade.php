{{--
    A CSRF field that is safe on a cached page. The value is left empty —
    a token rendered here would be the first visitor's, baked into the copy
    every later guest receives — and filled from /_magna/form-token, this
    visitor's own, before the form can submit.
--}}
<input type="hidden" name="_token" value="" data-magna-form-token>
@once
<script>
(() => {
    const endpoint = @js(route('magna.form-token', [], false));
    let pending = null;

    const fetchToken = () => {
        pending ??= fetch(endpoint, {
            credentials: 'same-origin',
            // Marked as XHR so the session does not record the endpoint as
            // the visitor's previous URL, which back() would return them to.
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((body) => {
                document.querySelectorAll('input[data-magna-form-token]').forEach((input) => {
                    input.value = body.token;
                });
                return body.token;
            })
            .catch((error) => {
                pending = null;
                throw error;
            });

        return pending;
    };

    const tokenField = (form) => form instanceof HTMLFormElement
        ? form.querySelector('input[data-magna-form-token]')
        : null;

    document.addEventListener('focusin', (event) => {
        const form = event.target instanceof Element ? event.target.closest('form') : null;
        if (tokenField(form)) {
            fetchToken().catch(() => {});
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        const field = tokenField(form);
        if (!field || field.value !== '') {
            return;
        }

        event.preventDefault();
        fetchToken()
            .then(() => (form.requestSubmit ? form.requestSubmit(event.submitter ?? undefined) : form.submit()))
            .catch(() => form.submit());
    }, true);
})();
</script>
@endonce
