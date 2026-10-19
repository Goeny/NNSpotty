(() => {
    'use strict';
    const pending = new Set();
    const changed = new Map();
    const notice = document.querySelector('[data-favorite-notice]');
    if (!notice) return;
    const message = notice.querySelector('[data-favorite-message]');
    let noticeTimer;
    notice.querySelector('[data-favorite-dismiss]').addEventListener('click', () => { notice.hidden = true; });

    function notify(text, warning = false) {
        clearTimeout(noticeTimer);
        notice.className = `alert alert-${warning ? 'warning' : 'info'} alert-dismissible favorite-notice`;
        message.textContent = text;
        notice.hidden = false;
        if (!warning) noticeTimer = setTimeout(() => { notice.hidden = true; }, 4500);
    }

    function update(button, favorite) {
        button.classList.toggle('is-favorite', favorite);
        button.setAttribute('aria-pressed', String(favorite));
        const label = favorite ? 'Verwijderen uit favorieten' : 'Toevoegen aan favorieten';
        button.setAttribute('aria-label', label);
        button.title = label;
    }

    function sync() {
        document.querySelectorAll('[data-favorite]').forEach(button => {
            const id = button.dataset.favorite;
            if (changed.has(id)) update(button, changed.get(id));
            button.disabled = pending.has(id);
        });
    }

    new MutationObserver(sync).observe(document.getElementById('content'), {childList: true, subtree: true});
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-favorite]');
        if (!button) return;
        event.preventDefault();
        const id = button.dataset.favorite;
        if (pending.has(id)) return;
        const hadFocus = document.activeElement === button;
        const favorite = button.getAttribute('aria-pressed') !== 'true';
        const token = document.querySelector('[name="_csrf"]')?.value;
        if (!token) {
            notify('Beveiligingstoken ontbreekt. Herlaad de pagina voordat je favorieten wijzigt.', true);
            return;
        }
        pending.add(id);
        sync();
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch('/favorites', {
                method: 'POST', credentials: 'same-origin',
                signal: controller.signal,
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({_csrf: token, id, favorite: favorite ? '1' : '0'})
            });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Onverwacht serverantwoord. Herlaad de pagina of log opnieuw in.');
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || `Favoriet opslaan mislukt (HTTP ${response.status}).`);
            if (data.id !== id || typeof data.favorite !== 'boolean') throw new Error('De server gaf een ongeldige favorietstatus. Herlaad de pagina.');
            changed.set(id, data.favorite);
            notify(data.favorite ? 'Spot toegevoegd aan je favorieten.' : 'Spot verwijderd uit je favorieten.');
            if (!data.favorite && document.querySelector('[data-favorites-only="1"]')) {
                const row = document.querySelector(`[data-spot-id="${id}"]`);
                if (row) {
                    const focused = row.contains(document.activeElement) || (hadFocus && row.contains(button) && document.activeElement === document.body);
                    const next = row.nextElementSibling?.querySelector('[data-favorite]')
                        || row.previousElementSibling?.querySelector('[data-favorite]');
                    row.remove();
                    const count = document.getElementById('loaded-count');
                    count.textContent = String(document.querySelectorAll('#spot-rows [data-spot-id]').length);
                    if (focused) (next || document.querySelector('h1'))?.focus({preventScroll: true});
                    if (count.textContent === '0') {
                        const status = document.getElementById('load-status');
                        status.textContent = document.getElementById('load-more').dataset.next
                            ? 'Geen favorieten meer in deze batch. Laad meer resultaten.' : 'Geen favorieten voor deze filters.';
                    }
                }
            }
        } catch (exception) {
            console.error('Favoriet opslaan mislukt.', exception);
            notify(exception.name === 'AbortError'
                ? 'Opslaan duurde te lang. Probeer opnieuw; dezelfde actie kan veilig worden herhaald.' : exception.message, true);
        } finally {
            clearTimeout(timeout);
            pending.delete(id);
            sync();
            if (hadFocus && button.isConnected && document.activeElement === document.body && !button.closest('[inert]')) {
                button.focus({preventScroll: true});
            }
        }
    });
})();
