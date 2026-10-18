(() => {
    'use strict';
    const panel = document.querySelector('[data-tvdb]');
    const content = document.querySelector('[data-tvdb-content]');
    if (!panel || !content) return;
    const originalHTML = content.innerHTML;
    const status = panel.querySelector('[data-tvdb-status]');
    const csrf = panel.querySelector('[name="_csrf"]').value;
    let busy = false;

    async function request(action, values = {}) {
        if (busy) return;
        busy = true;
        const controls = [...panel.querySelectorAll('button')];
        controls.forEach(button => button.disabled = true);
        status.className = 'small text-body-secondary mb-2';
        status.textContent = 'TVDB-aanvraag uitvoeren...';
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 30000);
        try {
            const response = await fetch('/metadata', {
                method: 'POST', credentials: 'same-origin', signal: abort.signal,
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({_csrf: csrf, id: panel.dataset.spot, action, ...values})
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Onverwacht antwoord. Herlaad de pagina of log opnieuw in.');
            const data = await response.json();
            if (!response.ok) {
                if (typeof data.html === 'string' && data.state === 'error') content.innerHTML = data.html;
                else if (response.status === 401 || response.status === 403) content.innerHTML = originalHTML;
                throw new Error(data.error || data.message || `TVDB-aanvraag mislukt (HTTP ${response.status}).`);
            }
            if (action === 'search') {
                if (!Array.isArray(data.candidates)) throw new Error('Ongeldige TVDB-resultaten.');
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
                    label.append(input, document.createTextNode(`${candidate.name} (${candidate.year || 'jaar onbekend'}) — TVDB ${candidate.id}`));
                    list.append(label);
                }
                status.textContent = data.candidates.length ? 'Kies een resultaat en sla de gedeelde match op.' : 'Geen resultaten gevonden. Pas de zoekterm aan.';
            } else {
                if (typeof data.html !== 'string' || typeof data.message !== 'string') throw new Error('Ongeldige TVDB-presentatie.');
                content.innerHTML = data.html;
                status.textContent = data.message;
            }
        } catch (error) {
            status.className = 'alert alert-warning mt-2';
            status.textContent = error.name === 'AbortError'
                ? 'TVDB reageert te traag. De oorspronkelijke spot blijft beschikbaar. Probeer later opnieuw.'
                : error.message;
        } finally {
            clearTimeout(timeout);
            busy = false;
            controls.forEach(button => button.disabled = false);
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
            status.textContent = 'Zoek en selecteer eerst een TVDB-resultaat.';
            return;
        }
        request('choose', {...Object.fromEntries(new FormData(event.currentTarget)), type: chosen.dataset.type, tvdb_id: chosen.value});
    });
    panel.querySelector('[data-tvdb-reset]')?.addEventListener('click', () => request('reset'));
    panel.querySelector('[data-tvdb-block]')?.addEventListener('click', () => request('block'));
    request('load');
})();
