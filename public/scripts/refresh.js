const FILTERS = ['search', 'job', 'applicationStatus', 'enrichmentStatus'];
const POLL_INTERVAL = 3000;
const MAX_POLLS = 40; // Stop automatic refreshing after about two minutes.
const ACTIVE = '[data-enrichment-status="pending"], [data-enrichment-status="processing"]';

export function createRefresh() {
    let region = document.querySelector('#application-results, #application-analysis');
    const form = document.querySelector('.filters');
    const status = document.getElementById('refresh-status');
    const button = document.getElementById('refresh-button');
    if (!region || !status || !button) return null;

    let url = new URL(region.dataset.canonicalUrl, window.location.href);
    let controller;
    let debounce;
    let pollTimer;
    let polls = 0;

    const hasActiveAnalysis = () => region.matches(ACTIVE) || region.querySelector(ACTIVE) !== null;

    function schedulePoll() {
        clearTimeout(pollTimer);
        if (!hasActiveAnalysis()) return;
        if (polls >= MAX_POLLS) {
            status.textContent = 'Automatic refreshing stopped. Use Refresh results to check again.';
            return;
        }
        pollTimer = setTimeout(() => { polls++; void load(url); }, POLL_INTERVAL);
    }

    async function load(target, pushHistory = false) {
        clearTimeout(pollTimer);
        controller?.abort();
        controller = new AbortController();
        region.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(target, { signal: controller.signal, headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById(region.id);
            if (!next) throw new Error('Missing results region.');
            region.replaceWith(next);
            region = next;
            url = new URL(next.dataset.canonicalUrl, window.location.href);
            if (pushHistory && url.href !== window.location.href) window.history.pushState(null, '', url);
            status.textContent = '';
            schedulePoll();
        } catch (error) {
            if (error.name === 'AbortError') return;
            status.textContent = 'Could not refresh results. The previous results are still shown.';
        } finally {
            region.removeAttribute('aria-busy');
        }
    }

    function formUrl() {
        const target = new URL(form.action, window.location.href);
        for (const name of FILTERS) {
            const value = form.elements.namedItem(name).value;
            if (value !== '') target.searchParams.set(name, value);
        }
        return target;
    }

    function filter(delay) {
        clearTimeout(debounce);
        polls = 0;
        debounce = setTimeout(() => void load(formUrl(), true), delay);
    }

    function start() {
        document.querySelector('.refresh-controls').hidden = false;
        button.addEventListener('click', () => { polls = 0; void load(url); });
        if (form) {
            form.addEventListener('submit', (event) => { event.preventDefault(); filter(0); });
            form.querySelectorAll('select').forEach((select) => select.addEventListener('change', () => filter(0)));
            form.elements.namedItem('search').addEventListener('input', () => filter(300));
            window.addEventListener('popstate', () => {
                const current = new URL(window.location.href);
                for (const name of FILTERS) form.elements.namedItem(name).value = current.searchParams.get(name) ?? '';
                void load(current);
            });
        }
        schedulePoll();
    }

    return { start };
}
