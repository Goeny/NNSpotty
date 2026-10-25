(() => {
    'use strict';
    const notice = document.querySelector('[data-update-notice]');
    if (notice) {
        fetch('/system/update/status', {headers: {Accept: 'application/json'}, cache: 'no-store'})
            .then(async response => {
                const status = await response.json();
                if (!response.ok) throw new Error(status.error || 'Releasecontrole mislukt.');
                notice.textContent = status.error ? 'Releasecontrole mislukt'
                    : status.recovery ? 'Update controleren / herstellen'
                    : status.available ? `Nieuwe release: ${status.release.version}` : 'Releases controleren';
                notice.classList.toggle('text-warning-emphasis', Boolean(status.available || status.recovery || status.error));
                notice.classList.toggle('fw-semibold', Boolean(status.available || status.recovery));
            })
            .catch(error => {
                console.error('Releasecontrole:', error);
                notice.textContent = 'Releasecontrole mislukt';
            });
    }
    const form = document.querySelector('[data-system-update]');
    if (!form) return;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const result = document.querySelector('[data-update-result]');
        button.disabled = true;
        result.hidden = false;
        result.className = 'alert alert-info mt-3';
        result.textContent = 'Release installeren... Sluit deze pagina niet. Dit kan meerdere minuten duren.';
        try {
            const response = await fetch(form.getAttribute('action'), {method: 'POST', body: new URLSearchParams(new FormData(form)),
                headers: {Accept: 'application/json'}, cache: 'no-store'});
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Update mislukt.');
            result.className = 'alert alert-success mt-3';
            result.textContent = payload.message;
            location.assign('/system/update');
        } catch (error) {
            console.error('Systeemupdate:', error);
            result.className = 'alert alert-danger mt-3';
            result.textContent = `${error.message} Bij een verbroken verbinding: controleer eerst of het proces nog loopt en bekijk var/update.log voordat je opnieuw probeert.`;
            button.disabled = false;
        }
    });
})();
