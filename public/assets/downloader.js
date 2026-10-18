(() => {
    'use strict';
    const dialog = document.getElementById('download-drawer');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const connection = dialog.querySelector('#sab-connection');
    const message = dialog.querySelector('[data-sab-message]');
    const confirm = dialog.querySelector('[data-sab-confirm]');
    const queue = dialog.querySelector('[data-sab-queue]');
    const history = dialog.querySelector('[data-sab-history]');
    const previous = dialog.querySelector('[data-sab-prev]');
    const next = dialog.querySelector('[data-sab-next]');
    let trigger = null;
    let pendingSpot = null;
    let prepared = null;
    let busy = false;
    let closing = false;
    let timer = null;
    let request = null;
    let generation = 0;
    let offset = 0;
    let samples = [];

    const notice = (text, error = false) => {
        message.textContent = text;
        message.className = error ? 'alert alert-warning' : 'alert alert-info';
    };
    const clearQueue = () => {
        queue.replaceChildren();
        dialog.querySelector('[data-sab-state]').textContent = 'Niet verbonden';
        dialog.querySelector('[data-sab-speed]').textContent = '--';
        dialog.querySelector('[data-sab-chart]').setAttribute('points', '');
        dialog.querySelector('[data-sab-dot]').setAttribute('hidden', '');
        dialog.querySelector('[data-sab-peak]').textContent = '--';
        dialog.querySelector('[data-sab-total]').textContent = '';
        previous.disabled = true;
        next.disabled = true;
    };
    const clearData = () => {
        clearQueue();
        history.replaceChildren();
    };
    const stop = () => {
        generation++;
        clearTimeout(timer);
        request?.abort();
        request = null;
    };
    async function api(parameters, form = null, signal = undefined) {
        const url = '/downloader?' + new URLSearchParams(parameters);
        const response = await fetch(url, {
            method: form ? 'POST' : 'GET', body: form,
            credentials: 'same-origin', cache: 'no-store', signal,
            headers: {'Accept': 'application/json'}
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || data.message || 'SABnzbd-aanvraag mislukt.');
        return data;
    }
    function card(title, detail) {
        const item = document.createElement('article');
        item.className = 'download-item';
        const heading = document.createElement('h4');
        heading.className = 'h6';
        heading.textContent = title;
        const text = document.createElement('p');
        text.className = 'small text-body-secondary mb-2';
        text.textContent = detail;
        item.append(heading, text);
        return item;
    }
    function render(data) {
        if (!Array.isArray(data.slots) || !Array.isArray(data.history) || typeof data.speed !== 'number' || !Number.isFinite(data.speed)) {
            throw new Error('Onvolledige downloaderstatus.');
        }
        dialog.querySelector('[data-sab-state]').textContent = data.status;
        dialog.querySelector('[data-sab-speed]').textContent = (data.speed / 1048576).toLocaleString('nl-NL', {maximumFractionDigits: 2}) + ' MiB/s';
        const now = Date.now();
        samples.push({time: now, speed: data.speed});
        samples = samples.filter(sample => sample.time >= now - 300000).slice(-61);
        const peak = Math.max(1, ...samples.map(sample => sample.speed));
        dialog.querySelector('[data-sab-peak]').textContent = (peak / 1048576).toLocaleString('nl-NL', {maximumFractionDigits: 2}) + ' MiB/s';
        const dot = dialog.querySelector('[data-sab-dot]');
        dot.removeAttribute('hidden');
        dot.setAttribute('cy', String(98 - data.speed / peak * 94));
        dialog.querySelector('[data-sab-chart]').setAttribute('points',
            samples.map(sample => `${480 - (now - sample.time) / 300000 * 480},${98 - sample.speed / peak * 94}`).join(' '));
        queue.replaceChildren();
        for (const slot of data.slots) {
            const item = card(slot.title, `${slot.status} · ${slot.category || 'Standaard'} · ${slot.eta} resterend`);
            const progress = document.createElement('progress');
            progress.max = 100;
            progress.value = slot.percentage;
            progress.setAttribute('aria-label', `${slot.title}: ${slot.percentage}%`);
            item.append(progress);
            queue.append(item);
        }
        if (data.slots.length === 0) queue.textContent = 'Geen downloads op deze wachtrijpagina.';
        previous.disabled = offset === 0;
        next.disabled = offset + data.slots.length >= data.total;
        dialog.querySelector('[data-sab-total]').textContent = `${data.total} ${data.total === 1 ? 'download' : 'downloads'}`;
        renderHistory(data.history);
    }
    function renderHistory(entries) {
        if (!Array.isArray(entries)) throw new Error('Onvolledige verzendhistorie.');
        history.replaceChildren();
        const statuses = {sent: 'Verzonden', failed: 'Geweigerd', unknown: 'Uitkomst onbekend', sending: 'Verzending gestart; uitkomst nog niet bevestigd'};
        for (const entry of entries) {
            history.append(card(entry.title, `${statuses[entry.status] || entry.status} · ${entry.category || 'Standaard'} · ${entry.created_at} UTC`));
        }
        if (entries.length === 0) history.textContent = 'Nog geen verzendpogingen vanuit NNSpotty.';
    }
    async function refresh(preserveNotice = false) {
        stop();
        if (!dialog.open || document.hidden || busy || closing) return;
        const current = generation;
        request = new AbortController();
        try {
            const local = await api({action: 'history', connection: connection.value}, null, request.signal);
            if (current !== generation || !dialog.open) return;
            renderHistory(local.history);
            const data = await api({connection: connection.value, offset}, null, request.signal);
            if (current !== generation || !dialog.open) return;
            render(data);
            if (!pendingSpot && !preserveNotice) notice('Live verbonden met ' + connection.selectedOptions[0].textContent + '.');
            timer = setTimeout(() => refresh(), 5000);
        } catch (error) {
            if (error.name !== 'AbortError' && current === generation) {
                notice(error.message + ' Gebruik Vernieuwen om opnieuw te proberen.', true);
                clearQueue();
            }
        }
    }
    async function prepare() {
        prepared = null;
        confirm.hidden = true;
        if (!pendingSpot) return;
        stop();
        const current = generation;
        request = new AbortController();
        notice('Download voorbereiden...');
        try {
            const data = await api({action: 'prepare', connection: connection.value, id: pendingSpot}, null, request.signal);
            if (current !== generation || !dialog.open) return;
            prepared = data;
            dialog.querySelector('[data-sab-spot-title]').textContent = data.title;
            dialog.querySelector('[data-sab-category]').textContent = data.category;
            confirm.hidden = false;
            notice('Controleer de koppeling en categorie voordat je verzendt.');
        } catch (error) {
            if (error.name !== 'AbortError' && current === generation) notice(error.message, true);
        }
    }
    function open(opener, spot = null) {
        trigger = opener;
        closing = false;
        pendingSpot = spot;
        prepared = null;
        offset = 0;
        samples = [];
        clearData();
        if (opener.dataset.sabOpen) connection.value = opener.dataset.sabOpen;
        if (!dialog.open) dialog.showModal();
        if (spot) prepare();
        else {
            confirm.hidden = true;
            notice('Wachtrij laden...');
            refresh();
        }
    }
    function close() {
        if (busy || closing) return;
        stop();
        closing = true;
        dialog.classList.add('is-closing');
        setTimeout(() => {
            dialog.close();
            dialog.classList.remove('is-closing');
            closing = false;
        }, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200);
    }
    document.addEventListener('click', event => {
        const send = event.target.closest('[data-sab-send]');
        const opener = event.target.closest('[data-sab-open]');
        if (send || opener) open(send || opener, send?.dataset.sabSend || null);
    });
    dialog.querySelector('[data-sab-close]').addEventListener('click', close);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    });
    dialog.addEventListener('close', () => {
        stop();
        clearData();
        pendingSpot = null;
        prepared = null;
        if (trigger?.isConnected) trigger.focus({preventScroll: true});
    });
    connection.addEventListener('change', () => {
        samples = [];
        offset = 0;
        clearData();
        if (pendingSpot) prepare();
        else refresh();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else if (dialog.open && !pendingSpot) refresh();
    });
    dialog.querySelector('[data-sab-refresh]').addEventListener('click', () => {
        if (!busy) pendingSpot ? prepare() : refresh();
    });
    previous.addEventListener('click', () => { offset = Math.max(0, offset - 30); refresh(); });
    next.addEventListener('click', () => { offset += 30; refresh(); });
    dialog.querySelector('[data-sab-cancel]').addEventListener('click', () => {
        if (busy) return;
        pendingSpot = null;
        prepared = null;
        confirm.hidden = true;
        refresh();
    });
    confirm.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !prepared || !pendingSpot) return;
        stop();
        busy = true;
        const form = new FormData(confirm);
        form.set('connection', connection.value);
        form.set('action', 'send');
        form.set('id', pendingSpot);
        form.set('token', prepared.token);
        form.set('revision', prepared.revision);
        const submit = confirm.querySelector('[type=submit]');
        submit.disabled = true;
        connection.disabled = true;
        dialog.querySelector('[data-sab-close]').disabled = true;
        notice('NZB ophalen en versturen... Sluit dit venster nog niet.');
        try {
            const data = await api({}, form);
            notice(data.message);
        } catch (error) {
            notice(error.message + ' Controleer bij twijfel eerst de SABnzbd-wachtrij; verstuur niet blind opnieuw.', true);
        } finally {
            pendingSpot = null;
            prepared = null;
            confirm.hidden = true;
            submit.disabled = false;
            connection.disabled = false;
            dialog.querySelector('[data-sab-close]').disabled = false;
            busy = false;
            refresh(true);
        }
    });
    document.addEventListener('click', async event => {
        const test = event.target.closest('[data-sab-test]');
        if (!test) return;
        const form = test.closest('form');
        const result = form.querySelector('[data-sab-test-result]');
        const body = new FormData();
        body.set('_csrf', form.querySelector('[name=_csrf]').value);
        body.set('connection', test.dataset.sabTest);
        body.set('action', 'test');
        test.disabled = true;
        result.textContent = 'Verbinding testen...';
        try {
            const data = await api({}, body);
            result.textContent = data.message + ' ' + data.categories.join(', ');
            result.className = 'alert alert-success mt-3';
        } catch (error) {
            result.textContent = error.message;
            result.className = 'alert alert-warning mt-3';
        } finally {
            test.disabled = false;
        }
    });
})();

(() => {
    'use strict';
    const navigation = document.querySelector('[data-admin-navigation]');
    if (!navigation) return;
    const panels = [...document.querySelectorAll('[data-admin-panel]')];
    const aliases = {'spot-content': 'appearance', columns: 'appearance'};
    function select() {
        const hash = location.hash.slice(1);
        const selected = panels.find(panel => panel.dataset.adminPanel === (aliases[hash] || hash))?.dataset.adminPanel || 'usenet';
        for (const panel of panels) panel.hidden = panel.dataset.adminPanel !== selected;
        for (const link of navigation.querySelectorAll('a')) {
            const active = link.hash === '#' + selected;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        }
    }
    addEventListener('hashchange', select);
    select();
})();
