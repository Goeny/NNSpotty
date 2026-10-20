(() => {
    'use strict';
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-emoticon-code]');
        if (!button) return;
        const textarea = document.getElementById(button.dataset.emoticonTarget);
        if (!(textarea instanceof HTMLTextAreaElement)) return;
        const code = button.dataset.emoticonCode;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        if (textarea.maxLength >= 0 && textarea.value.length - (end - start) + code.length > textarea.maxLength) {
            textarea.setCustomValidity('De emoticon past niet meer in de reactie. Maak eerst ruimte vrij.');
            textarea.reportValidity();
            return;
        }
        textarea.setCustomValidity('');
        textarea.setRangeText(code, start, end, 'end');
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.focus();
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
