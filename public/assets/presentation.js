(() => {
    'use strict';
    const dialog = document.getElementById('image-lightbox');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    let image = dialog.querySelector('img');
    const title = dialog.querySelector('#lightbox-title');
    const status = dialog.querySelector('[data-lightbox-status]');
    const error = dialog.querySelector('[data-lightbox-error]');
    const navigation = dialog.querySelector('[data-lightbox-navigation]');
    const previous = dialog.querySelector('[data-lightbox-previous]');
    const next = dialog.querySelector('[data-lightbox-next]');
    const counter = dialog.querySelector('[data-lightbox-counter]');
    const retry = dialog.querySelector('[data-lightbox-retry]');
    let trigger = null;
    let items = [];
    let index = 0;
    let touch = null;

    function show(position) {
        index = (position + items.length) % items.length;
        status.hidden = false;
        error.hidden = true;
        retry.hidden = true;
        counter.textContent = `${index + 1} / ${items.length}`;
        navigation.hidden = items.length < 2;
        dialog.classList.toggle('has-gallery', items.length > 1);
        const replacement = image.cloneNode(false);
        replacement.removeAttribute('src');
        replacement.hidden = true;
        replacement.alt = `Afbeelding ${index + 1} van ${items.length} bij ${title.textContent}`;
        replacement.addEventListener('load', () => {
            if (!dialog.open || image !== replacement) return;
            status.hidden = true;
            replacement.hidden = false;
        }, {once: true});
        replacement.addEventListener('error', () => {
            if (!dialog.open || image !== replacement) return;
            status.hidden = true;
            replacement.hidden = true;
            error.hidden = false;
            retry.hidden = false;
        }, {once: true});
        image.replaceWith(replacement);
        image = replacement;
        replacement.src = items[index];
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('[data-image-lightbox]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        trigger = link;
        title.textContent = link.dataset.imageTitle || 'Afbeelding';
        const gallery = link.dataset.imageGallery;
        const template = gallery && [...(link.closest('.metadata-hero')?.querySelectorAll('template[data-image-gallery-items]') || [])]
            .find(item => item.dataset.imageGalleryItems === gallery);
        items = template ? [...new Set([...template.content.querySelectorAll('a[href]')].map(item => item.href))] : [];
        if (!items.length) items = [link.href];
        if (!dialog.open) dialog.showModal();
        show(0);
    });
    previous.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    retry.addEventListener('click', () => show(index));
    dialog.querySelector('[data-close-lightbox]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') event.stopPropagation();
        if (items.length < 2 || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            event.stopPropagation();
            show(index + (event.key === 'ArrowLeft' ? -1 : 1));
        }
    });
    dialog.addEventListener('touchstart', event => {
        touch = event.touches.length === 1 ? {x: event.touches[0].clientX, y: event.touches[0].clientY} : null;
    }, {passive: true});
    dialog.addEventListener('touchend', event => {
        if (!touch || items.length < 2 || event.changedTouches.length !== 1) return;
        const dx = event.changedTouches[0].clientX - touch.x;
        const dy = event.changedTouches[0].clientY - touch.y;
        touch = null;
        if (Math.abs(dx) >= 60 && Math.abs(dx) > Math.abs(dy) * 1.5) show(index + (dx < 0 ? 1 : -1));
    }, {passive: true});
    dialog.addEventListener('touchcancel', () => { touch = null; });
    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        items = [];
        touch = null;
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
