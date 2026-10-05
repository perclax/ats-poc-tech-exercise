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

let refresh;
function initialize() {
    const deadline = refresh?.deadline;
    refresh?.destroy();
    if (!window.fetch || !window.AbortController || !window.DOMParser || !window.history?.pushState) return;
    refresh = createRefresh({ deadline });
    refresh?.start();
}
initialize();
window.addEventListener('pageshow', (event) => { if (event.persisted) initialize(); });
