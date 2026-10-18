(() => {
    'use strict';
    const browser = document.querySelector('[data-spot-browser]');
    if (!browser) return;

    const rows = document.getElementById('spot-rows');
    const scroll = document.getElementById('spot-scroll');
    const more = document.getElementById('load-more');
    const status = document.getElementById('load-status');
    const error = document.getElementById('load-error');
    const drawer = document.getElementById('preview');
    const content = document.getElementById('preview-content');
    const close = document.getElementById('close-preview');
    let loading = false;
    let failed = false;
    let previewRequest = null;
    let lastTrigger = null;
    let observer = null;
    more.hidden = !more.dataset.next;

    async function getJson(url, signal) {
        const response = await fetch(url, {credentials: 'same-origin', signal, headers: {'Accept': 'application/json'}});
        if (!response.ok) throw new Error(response.status === 401 ? 'Je sessie is verlopen. Log opnieuw in.' : `Ophalen mislukt (HTTP ${response.status}).`);
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Onverwacht serverantwoord. Herlaad de pagina of log opnieuw in.');
        return response.json();
    }

    function updateSelection(id) {
        rows.querySelectorAll('[data-spot-id]').forEach(row => {
            const selected = row.dataset.spotId === id;
            row.classList.toggle('selected', selected);
            row.querySelector('[data-preview]').setAttribute('aria-expanded', String(selected));
        });
    }

    function closePreview() {
        previewRequest?.abort();
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.inert = true;
        content.removeAttribute('aria-busy');
        updateSelection(null);
        if (lastTrigger?.isConnected) lastTrigger.focus({preventScroll: true});
    }

    async function openPreview(trigger) {
        previewRequest?.abort();
        const request = new AbortController();
        previewRequest = request;
        lastTrigger = trigger;
        const id = trigger.dataset.preview;
        updateSelection(id);
        drawer.inert = false;
        drawer.setAttribute('aria-hidden', 'false');
        drawer.classList.add('is-open');
        content.setAttribute('aria-busy', 'true');
        content.innerHTML = '<p class="text-body-secondary" role="status">Spotdetails laden...</p>';
        close.focus({preventScroll: true});
        try {
            const data = await getJson(`/spots/preview?key=${encodeURIComponent(id)}`, request.signal);
            if (request.signal.aborted) return;
            if (typeof data.html !== 'string') throw new Error('De server gaf ongeldige spotdetails terug.');
            content.innerHTML = data.html;
            content.scrollTop = 0;
        } catch (exception) {
            if (exception.name === 'AbortError') return;
            content.replaceChildren();
            const alert = document.createElement('div');
            alert.className = 'alert alert-warning';
            alert.setAttribute('role', 'alert');
            alert.textContent = exception.message;
            const retry = document.createElement('button');
            retry.className = 'btn btn-sm btn-outline-primary';
            retry.textContent = 'Opnieuw proberen';
            retry.addEventListener('click', () => openPreview(trigger), {once: true});
            content.append(alert, retry);
        } finally {
            if (previewRequest === request) content.removeAttribute('aria-busy');
        }
    }

    rows.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const row = event.target.closest('[data-spot-id]');
        if (!row) return;
        event.preventDefault();
        openPreview(row.querySelector('[data-preview]'));
    });
    close.addEventListener('click', closePreview);
    document.addEventListener('keydown', event => {
        if (document.getElementById('image-lightbox')?.open) return;
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) closePreview();
    });

    async function loadMore() {
        if (loading || !more.dataset.next) return;
        loading = true;
        failed = false;
        more.disabled = true;
        error.hidden = true;
        status.textContent = 'Volgende resultaten laden...';
        const url = more.dataset.next;
        try {
            const data = await getJson(url);
            if (typeof data.html !== 'string' || !Number.isInteger(data.count) || data.count < 0
                || (data.next !== null && (typeof data.next !== 'string' || !data.next.startsWith('/spots?')))) {
                throw new Error('De server gaf een ongeldige resultaatbatch terug.');
            }
            const template = document.createElement('template');
            template.innerHTML = `<table><tbody>${data.html}</tbody></table>`;
            const newRows = template.content.querySelectorAll('tr[data-spot-id]');
            if (newRows.length !== data.count) throw new Error('De resultaatbatch heeft een onjuist aantal regels.');
            const ids = new Set([...rows.querySelectorAll('[data-spot-id]')].map(row => row.dataset.spotId));
            for (const row of newRows) {
                if (!ids.has(row.dataset.spotId)) {
                    rows.append(row);
                    ids.add(row.dataset.spotId);
                }
            }
            more.dataset.next = data.next ?? '';
            more.hidden = data.next === null;
            document.getElementById('loaded-count').textContent = String(rows.querySelectorAll('[data-spot-id]').length);
            status.textContent = data.next === null ? 'Alle resultaten geladen.' : '';
        } catch (exception) {
            failed = true;
            error.textContent = `${exception.message} Gebruik de knop om opnieuw te proberen.`;
            error.hidden = false;
            status.textContent = 'Bijladen onderbroken.';
        } finally {
            loading = false;
            more.disabled = false;
            if (!failed) requestAnimationFrame(checkNearEnd);
        }
    }

    function checkNearEnd() {
        if (failed || loading || !more.dataset.next) return;
        const root = scroll.getBoundingClientRect();
        const sentinel = document.getElementById('load-sentinel').getBoundingClientRect();
        if (sentinel.top < root.bottom + 400 && sentinel.top < innerHeight + 400) loadMore();
    }

    function observeEnd() {
        observer?.disconnect();
        const internal = ['auto', 'scroll'].includes(getComputedStyle(scroll).overflowY);
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(entries => {
                if (!failed && entries.some(entry => entry.isIntersecting)) loadMore();
            }, {root: internal ? scroll : null, rootMargin: '400px 0px'});
            observer.observe(document.getElementById('load-sentinel'));
        }
        checkNearEnd();
    }
    more.addEventListener('click', loadMore);
    observeEnd();
    window.addEventListener('resize', observeEnd);
})();
