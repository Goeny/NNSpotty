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
    let previewTimer = null;
    let lastTrigger = null;
    let observer = null;
    let restoring = false;
    let restoreTarget = null;
    more.hidden = !more.dataset.next;

    const historyKey = 'nnspottyBrowse';
    const saved = history.state?.[historyKey];
    if (saved && browser.dataset.browseUser && saved.user === browser.dataset.browseUser && saved.url === location.href
        && Number.isSafeInteger(saved.count) && saved.count >= 0
        && [saved.listTop, saved.contentTop, saved.pageTop, saved.anchorOffset].every(Number.isFinite)
        && (saved.anchor === null || typeof saved.anchor === 'string')) {
        restoreTarget = saved;
    }

    function rememberVisit() {
        // Store only position/cursor depth, never private spot HTML in browser history.
        if (restoreTarget) return;
        const top = Math.max(0, scroll.getBoundingClientRect().top);
        const bottom = Math.min(innerHeight, scroll.getBoundingClientRect().bottom);
        const anchor = [...rows.querySelectorAll('[data-spot-id]')].find(row => {
            const rect = row.getBoundingClientRect();
            return rect.bottom > top && rect.top < bottom;
        });
        try {
            history.replaceState({...history.state, [historyKey]: {
                user: browser.dataset.browseUser, url: location.href,
                count: rows.querySelectorAll('[data-spot-id]').length,
                listTop: scroll.scrollTop, contentTop: document.getElementById('content').scrollTop,
                pageTop: window.scrollY, anchor: anchor?.dataset.spotId ?? null,
                anchorOffset: anchor ? anchor.getBoundingClientRect().top - top : 0
            }}, '');
        } catch (exception) {
            console.error('De plek in de spotlijst kon niet worden bewaard.', exception);
            error.textContent = 'Je browser kon de plek in de spotlijst niet bewaren. Teruggaan kan daardoor bovenaan beginnen.';
            error.hidden = false;
        }
    }
    window.addEventListener('pagehide', rememberVisit);

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
        clearTimeout(previewTimer);
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
        clearTimeout(previewTimer);
        if (event.detail > 1) return;
        const trigger = row.querySelector('[data-preview]');
        if (event.detail === 0) {
            openPreview(trigger);
        } else {
            // Let a double-click finish before the drawer can cover the clicked row.
            previewTimer = setTimeout(() => openPreview(trigger), 500);
        }
    });
    rows.addEventListener('dblclick', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const row = event.target.closest('[data-spot-id]');
        if (!row) return;
        event.preventDefault();
        clearTimeout(previewTimer);
        previewRequest?.abort();
        window.location.assign(row.querySelector('[data-preview]').href);
    });
    close.addEventListener('click', closePreview);
    document.addEventListener('keydown', event => {
        if (document.querySelector('dialog[open]')) return;
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) closePreview();
    });

    async function loadMore() {
        if (loading || !more.dataset.next) return false;
        loading = true;
        failed = false;
        more.disabled = true;
        error.hidden = true;
        status.textContent = restoring
            ? `Je plek in de lijst herstellen: ${rows.querySelectorAll('[data-spot-id]').length} van ${restoreTarget.count} resultaten...`
            : 'Volgende resultaten laden...';
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
            if (data.next === url) throw new Error('De resultaatbatch verwijst opnieuw naar dezelfde pagina.');
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
            status.textContent = restoring
                ? `Je plek in de lijst herstellen: ${rows.querySelectorAll('[data-spot-id]').length} van ${restoreTarget.count} resultaten...`
                : (data.next === null ? 'Alle resultaten geladen.' : '');
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
        return !failed;
    }

    async function restoreVisit() {
        if (restoring || !restoreTarget) return;
        restoring = true;
        browser.setAttribute('aria-busy', 'true');
        try {
            while (rows.querySelectorAll('[data-spot-id]').length < restoreTarget.count && more.dataset.next) {
                if (!await loadMore()) return;
            }
            const target = restoreTarget;
            // Layout must include all restored rows before nested or mobile scrolling.
            await new Promise(resolve => requestAnimationFrame(resolve));
            document.getElementById('content').scrollTop = target.contentTop;
            scroll.scrollTop = target.listTop;
            window.scrollTo({top: target.pageTop, behavior: 'instant'});
            const anchor = [...rows.querySelectorAll('[data-spot-id]')].find(row => row.dataset.spotId === target.anchor);
            if (anchor) {
                const delta = anchor.getBoundingClientRect().top - Math.max(0, scroll.getBoundingClientRect().top) - target.anchorOffset;
                if (['auto', 'scroll'].includes(getComputedStyle(scroll).overflowY)) scroll.scrollTop += delta;
                else window.scrollBy({top: delta, behavior: 'instant'});
            }
            restoreTarget = null;
            status.textContent = more.dataset.next ? '' : 'Alle resultaten geladen.';
        } finally {
            restoring = false;
            browser.removeAttribute('aria-busy');
            if (!restoreTarget) requestAnimationFrame(checkNearEnd);
        }
    }

    function checkNearEnd() {
        if (restoreTarget || restoring || failed || loading || !more.dataset.next) return;
        const root = scroll.getBoundingClientRect();
        const sentinel = document.getElementById('load-sentinel').getBoundingClientRect();
        if (sentinel.top < root.bottom + 400 && sentinel.top < innerHeight + 400) loadMore();
    }

    function observeEnd() {
        observer?.disconnect();
        const internal = ['auto', 'scroll'].includes(getComputedStyle(scroll).overflowY);
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(entries => {
                if (!restoreTarget && !restoring && !failed && entries.some(entry => entry.isIntersecting)) loadMore();
            }, {root: internal ? scroll : null, rootMargin: '400px 0px'});
            observer.observe(document.getElementById('load-sentinel'));
        }
        checkNearEnd();
    }
    more.addEventListener('click', () => restoreTarget ? restoreVisit() : loadMore());
    observeEnd();
    window.addEventListener('resize', observeEnd);
    window.addEventListener('pageshow', event => {
        if (event.persisted) observeEnd();
    });
    restoreVisit();
})();
