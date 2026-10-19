(() => {
    'use strict';
    const navigation = document.querySelector('[data-facets-url]');
    if (!navigation) return;
    const status = navigation.querySelector('[data-facets-status]');
    const retry = navigation.querySelector('[data-facets-retry]');
    let loading = false;

    async function load() {
        if (loading) return;
        loading = true;
        retry.hidden = true;
        status.textContent = 'Nieuwtellers laden...';
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(navigation.dataset.facetsUrl, {
                credentials: 'same-origin', headers: {'Accept': 'application/json'}, signal: controller.signal
            });
            if (!response.ok) {
                throw new Error(response.status === 401
                    ? 'Je sessie is verlopen. Log opnieuw in.'
                    : `Nieuwtellers ophalen mislukt (HTTP ${response.status}).`);
            }
            const data = await response.json();
            if (!data || typeof data !== 'object' || !data.counts || typeof data.counts !== 'object' || Array.isArray(data.counts)
                || Object.values(data.counts).some(value => !Number.isSafeInteger(value) || value < 0)) {
                throw new Error('De server gaf ongeldige nieuwtellers terug.');
            }
            navigation.querySelectorAll('[data-facet-count]').forEach(badge => {
                const count = data.counts[badge.dataset.facetCount] ?? 0;
                badge.textContent = String(count);
                badge.title = `${count} nieuw sinds je vorige bezoek`;
                badge.setAttribute('aria-label', badge.title);
            });
            status.textContent = 'Nieuwtellers bijgewerkt.';
        } catch (exception) {
            if (exception.name === 'AbortError') {
                status.textContent = 'Nieuwtellers ophalen duurde te lang. Je kunt de filters al gebruiken.';
            } else if (exception instanceof SyntaxError) {
                status.textContent = 'De server gaf ongeldige nieuwtellers terug.';
            } else if (exception instanceof TypeError) {
                status.textContent = 'Nieuwtellers ophalen mislukt. Controleer je verbinding.';
            } else {
                status.textContent = exception.message;
            }
            retry.hidden = false;
        } finally {
            clearTimeout(timeout);
            loading = false;
        }
    }

    retry.addEventListener('click', load);
    load();
})();
