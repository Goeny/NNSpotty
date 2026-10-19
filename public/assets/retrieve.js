(() => {
    'use strict';

    const control = document.querySelector('[data-retrieve-control]');
    if (!control) return;

    const form = control.querySelector('[data-retrieve-form]');
    const button = form?.querySelector('button[type="submit"]');
    const indicator = control.querySelector('[data-retrieve-indicator]');
    const lastRun = control.querySelector('[data-retrieve-last]');
    const message = control.querySelector('[data-retrieve-state]');
    let running = false;
    let submitting = false;
    let pollTimer = null;

    function setMessage(text, isError = false) {
        message.textContent = text;
        message.classList.toggle('text-danger', isError);
        message.classList.toggle('text-body-secondary', !isError);
    }

    function displayDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) throw new Error('De server stuurde een ongeldige retrieve-datum.');
        const parts = new Intl.DateTimeFormat('nl-NL', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(date);
        const part = type => parts.find(item => item.type === type)?.value ?? '';
        return `${part('day')}-${part('month')}-${part('year')} ${part('hour')}:${part('minute')}`;
    }

    function applyStatus(data) {
        if (typeof data.running !== 'boolean'
            || (data.last_run_at !== null && typeof data.last_run_at !== 'string')
            || (data.last_status !== null && !['completed', 'failed'].includes(data.last_status))) {
            throw new Error('De server stuurde een ongeldige retrieve-status.');
        }
        running = data.running;
        lastRun.textContent = data.last_run_at
            ? `Laatste retrieve: ${displayDate(data.last_run_at)}`
            : 'Laatste retrieve: nog niet uitgevoerd';
        if (running) {
            setMessage('Retrieve is bezig...');
        } else if (!submitting) {
            setMessage(data.last_status === 'failed' ? 'Laatste retrieve is mislukt.' : '');
        }
        if (button) button.disabled = running || submitting;
        if (indicator) indicator.hidden = !running && !submitting;
        schedulePoll();
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        if (document.hidden) return;
        pollTimer = setTimeout(() => refreshStatus(), running ? 4000 : 15000);
    }

    async function refreshStatus() {
        try {
            const response = await fetch('/retrieve/status', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            if (!response.ok) throw new Error(`Retrieve-status ophalen mislukt (HTTP ${response.status}).`);
            applyStatus(await response.json());
        } catch (exception) {
            setMessage(exception.message, true);
            console.error('Retrieve-status ophalen mislukt.', exception);
            schedulePoll();
        }
    }

    if (form) {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (running || submitting) return;
            submitting = true;
            button.disabled = true;
            indicator.hidden = false;
            setMessage('Retrieve wordt gestart...');
            let resultMessage = '';
            let errorMessage = '';
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                    body: new FormData(form)
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || `Retrieve starten mislukt (HTTP ${response.status}).`);
                resultMessage = result.message || 'Retrieve afgerond.';
            } catch (exception) {
                errorMessage = exception.message;
                console.error('Handmatige retrieve mislukt.', exception);
            } finally {
                submitting = false;
                await refreshStatus();
                if (resultMessage) setMessage(resultMessage);
                if (errorMessage) setMessage(errorMessage, true);
                if (indicator) indicator.hidden = !running;
                if (button) button.disabled = running;
            }
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && running) refreshStatus();
    });
    refreshStatus();
})();
