(() => {
    'use strict';
    const dialog = document.getElementById('image-lightbox');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const image = dialog.querySelector('img');
    const title = dialog.querySelector('#lightbox-title');
    const status = dialog.querySelector('[data-lightbox-status]');
    const error = dialog.querySelector('[data-lightbox-error]');
    let trigger = null;

    document.addEventListener('click', event => {
        const link = event.target.closest('[data-image-lightbox]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        trigger = link;
        title.textContent = link.dataset.imageTitle || 'Afbeelding';
        image.alt = `Afbeelding bij ${title.textContent}`;
        image.hidden = true;
        status.hidden = false;
        error.hidden = true;
        dialog.showModal();
        image.src = link.href;
    });
    image.addEventListener('load', () => {
        if (!dialog.open) return;
        status.hidden = true;
        image.hidden = false;
    });
    image.addEventListener('error', () => {
        if (!dialog.open) return;
        status.hidden = true;
        image.hidden = true;
        error.hidden = false;
    });
    dialog.querySelector('[data-close-lightbox]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') event.stopPropagation();
    });
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        if (trigger?.isConnected) trigger.focus({preventScroll: true});
    });
})();

(() => {
    'use strict';
    document.querySelectorAll('.key-confirm').forEach(dialog => {
        dialog.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            dialog.close();
        });
    });
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-confirm-dialog]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.confirmDialog);
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        }
        const closer = event.target.closest('[data-close-confirm]');
        if (closer) closer.closest('dialog').close();
    });
})();
