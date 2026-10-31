(() => {
    'use strict';
    const insert = (textarea, code, tooLong) => {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        if (textarea.maxLength >= 0 && textarea.value.length - (end - start) + code.length > textarea.maxLength) {
            textarea.setCustomValidity(tooLong);
            textarea.reportValidity();
            return;
        }
        textarea.setCustomValidity('');
        textarea.setRangeText(code, start, end, 'end');
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.focus();
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-emoticon-code]');
        if (!button) return;
        const textarea = document.getElementById(button.dataset.emoticonTarget);
        if (!(textarea instanceof HTMLTextAreaElement)) return;
        insert(textarea, button.dataset.emoticonCode, 'De emoticon past niet meer in de reactie. Maak eerst ruimte vrij.');
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-comment-quote]');
        if (!button) return;
        const textarea = document.getElementById('comment');
        if (!(textarea instanceof HTMLTextAreaElement)) return;
        // Quote on its own lines, followed by an empty line for the reply.
        const before = textarea.value.slice(0, textarea.selectionStart);
        const prefix = before === '' || before.endsWith('\n\n') ? '' : (before.endsWith('\n') ? '\n' : '\n\n');
        textarea.scrollIntoView({block: 'center', behavior: 'smooth'});
        insert(textarea, prefix + button.dataset.commentQuote + '\n\n', 'Het citaat past niet meer in de reactie. Maak eerst ruimte vrij of kort het citaat in.');
    });
    document.addEventListener('input', event => {
        if (event.target.matches('[data-comment-form] textarea')) event.target.setCustomValidity('');
    });
    for (const form of document.querySelectorAll('[data-comment-form]')) {
        form.addEventListener('submit', event => {
            if (form.dataset.sending === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.sending = '1';
            form.querySelector('[data-comment-submit]').disabled = true;
            form.querySelector('[data-comment-progress]').hidden = false;
        });
    }
    addEventListener('pageshow', () => {
        for (const form of document.querySelectorAll('[data-comment-form]')) {
            delete form.dataset.sending;
            form.querySelector('[data-comment-submit]').disabled = false;
            form.querySelector('[data-comment-progress]').hidden = true;
        }
    });
})();
document.addEventListener('input', (event) => {
    if (!(event.target instanceof HTMLTextAreaElement) || event.target.id !== 'comment') return;
    const form = event.target.closest('[data-comment-form]');
    const preview = form?.querySelector('[data-comment-preview]');
    const signature = form?.querySelector('[data-comment-signature]');
    if (preview) preview.textContent = event.target.value.trim() + (signature?.textContent ? '\n\n-- \n' + signature.textContent : '');
});
(() => {
    'use strict';
    let rounds = 0;
    const load = async form => {
        const button = form.querySelector('[data-comment-load-button]');
        const progress = form.querySelector('[data-comment-load-progress]');
        const error = form.querySelector('[data-comment-load-error]');
        button.disabled = true;
        progress.hidden = false;
        error.hidden = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: {Accept: 'application/json'},
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || typeof data.html !== 'string') throw new Error(data.error || 'Reacties laden mislukt. Herlaad de pagina.');
            const list = document.querySelector('[data-comment-list]');
            if (list) list.innerHTML = data.html;
            for (const count of document.querySelectorAll('[data-comment-count]')) count.textContent = String(data.count);
            const template = document.createElement('template');
            template.innerHTML = data.loader.trim();
            const next = template.content.firstElementChild;
            form.replaceWith(...template.content.childNodes);
            // Continue automatically for spots with many comments, but never loop indefinitely.
            if (next?.matches('form[data-comment-loader]') && ++rounds < 5) load(next);
        } catch (exception) {
            progress.hidden = true;
            button.disabled = false;
            error.textContent = exception instanceof Error ? exception.message : 'Reacties laden mislukt.';
            error.hidden = false;
        }
    };
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-comment-loader]');
        if (!form || !window.fetch) return;
        event.preventDefault();
        rounds = 0;
        load(form);
    });
    const initial = document.querySelector('form[data-comment-loader]');
    if (initial && window.fetch) load(initial);
})();
