import { createRefresh } from './refresh.js';

export function focusValidation(document) {
    const summary = document.getElementById('validation-summary');
    if (!summary) return;
    summary.focus();
    summary.addEventListener('click', (event) => {
        const link = event.target.closest('a[href^="#"]');
        const field = link && document.getElementById(link.hash.slice(1));
        if (field) {
            event.preventDefault();
            field.focus();
        }
    });
}

focusValidation(document);

if (window.fetch && window.DOMParser) {
    createRefresh()?.start();
}
