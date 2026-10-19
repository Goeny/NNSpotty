(() => {
    'use strict';
    const panel = document.querySelector('[data-tvdb]');
    const content = document.querySelector('[data-tvdb-content]');
    if (!panel || !content) return;
    const provider = panel.dataset.providerName || 'TVDB';
    const endpoint = panel.dataset.endpoint || '/metadata';
    const choiceId = panel.dataset.choiceId || 'tvdb_id';
    const originalHTML = content.innerHTML;
    const status = panel.querySelector('[data-tvdb-status]');
    const csrf = panel.querySelector('[name="_csrf"]').value;
    let busy = false;
    let trigger = null;
    let closing = false;

    function close() {
        if (closing || !panel.open) return;
        closing = true;
        panel.classList.add('is-closing');
        setTimeout(() => {
            panel.close();
            panel.classList.remove('is-closing');
            closing = false;
        }, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200);
    }
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-tvdb-open]');
        if (!opener || closing) return;
        trigger = opener;
        panel.showModal();
    });
    panel.querySelector('[data-tvdb-close]').addEventListener('click', close);
    panel.addEventListener('cancel', event => {
        event.preventDefault();
        close();
    });
    panel.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        close();
    });
    panel.addEventListener('click', event => {
        if (event.target === panel) close();
    });
    panel.addEventListener('close', () => {
        const opener = trigger?.isConnected ? trigger : content.querySelector('[data-tvdb-open]');
        opener?.focus({preventScroll: true});
    });

    function replaceContent(html) {
        const restoreFocus = document.activeElement?.matches('[data-tvdb-open]');
        content.innerHTML = html;
        if (restoreFocus && !panel.open) content.querySelector('[data-tvdb-open]')?.focus({preventScroll: true});
    }

    async function request(action, values = {}) {
        if (busy) return;
        busy = true;
        const focused = panel.contains(document.activeElement) ? document.activeElement : null;
        const controls = [...panel.querySelectorAll('button:not([data-tvdb-close])')];
        controls.forEach(button => button.disabled = true);
        const opener = content.querySelector('[data-tvdb-open]');
        if (opener) {
            opener.classList.remove('text-warning');
            opener.removeAttribute('aria-label');
        }
        status.className = 'small text-body-secondary mb-2';
        status.textContent = `${provider}-aanvraag uitvoeren...`;
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 30000);
        try {
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({_csrf: csrf, id: panel.dataset.spot, action, ...values})
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Onverwacht antwoord. Herlaad de pagina of log opnieuw in.');
            const data = await response.json();
            if (!response.ok) {
                if (typeof data.html === 'string' && data.state === 'error') replaceContent(data.html);
                else if (response.status === 401 || response.status === 403) replaceContent(originalHTML);
                throw new Error(data.error || data.message || `${provider}-aanvraag mislukt (HTTP ${response.status}).`);
            }
            if (action === 'search') {
                if (!Array.isArray(data.candidates)) throw new Error(`Ongeldige ${provider}-resultaten.`);
                const list = panel.querySelector('[data-tvdb-candidates]');
                list.replaceChildren();
                for (const candidate of data.candidates) {
                    const label = document.createElement('label');
                    label.className = 'd-block my-2';
                    const input = document.createElement('input');
                    input.type = 'radio';
                    input.name = 'candidate';
                    input.value = String(candidate.id);
                    input.dataset.type = candidate.type;
                    input.required = true;
                    input.className = 'form-check-input me-2';
                    label.append(input, document.createTextNode(`${candidate.artist ? candidate.artist + ' — ' : ''}${candidate.name} (${candidate.year || 'jaar onbekend'})${candidate.note ? ' — ' + candidate.note : ''}${candidate.kind ? ' — ' + candidate.kind : ''} — ${provider} ${candidate.id}`));
                    list.append(label);
                }
                status.textContent = data.candidates.length ? 'Kies een resultaat en sla de gedeelde match op.' : 'Geen resultaten gevonden. Pas de zoekterm aan.';
            } else {
                if (typeof data.html !== 'string' || typeof data.message !== 'string') throw new Error(`Ongeldige ${provider}-presentatie.`);
                replaceContent(data.html);
                status.textContent = data.message;
            }
        } catch (error) {
            status.className = 'alert alert-warning mt-2';
            status.textContent = error.name === 'AbortError'
                ? `${provider} reageert te traag. De oorspronkelijke spot blijft beschikbaar. Probeer later opnieuw.`
                : error.message;
            const opener = content.querySelector('[data-tvdb-open]');
            if (opener) {
                opener.classList.add('text-warning');
                opener.setAttribute('aria-label', provider + ' link: ' + status.textContent);
            }
        } finally {
            clearTimeout(timeout);
            busy = false;
            controls.forEach(button => button.disabled = false);
            if (panel.open && focused?.isConnected && document.activeElement === document.body) focused.focus({preventScroll: true});
        }
    }
    panel.querySelector('[data-tvdb-load]').addEventListener('submit', event => {
        event.preventDefault();
        request('load');
    });
    panel.querySelector('[data-tvdb-search]')?.addEventListener('submit', event => {
        event.preventDefault();
        request('search', Object.fromEntries(new FormData(event.currentTarget)));
    });
    panel.querySelector('[data-tvdb-choice]')?.addEventListener('submit', event => {
        event.preventDefault();
        const chosen = event.currentTarget.querySelector('[name="candidate"]:checked');
        if (!chosen) {
            status.className = 'alert alert-warning mt-2';
            status.textContent = `Zoek en selecteer eerst een ${provider}-resultaat.`;
            return;
        }
        request('choose', {...Object.fromEntries(new FormData(event.currentTarget)), type: chosen.dataset.type, [choiceId]: chosen.value});
    });
    panel.querySelector('[data-tvdb-reset]')?.addEventListener('click', () => request('reset'));
    panel.querySelector('[data-tvdb-block]')?.addEventListener('click', () => request('block'));
    request('load');
})();
