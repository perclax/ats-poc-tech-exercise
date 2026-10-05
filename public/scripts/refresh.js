const FILTERS = ['search', 'job', 'applicationStatus', 'enrichmentStatus'];

export function createRefresh(options = {}) {
    const document = options.document ?? globalThis.document;
    const window = options.window ?? globalThis.window;
    const fetchPage = options.fetch ?? window.fetch.bind(window);
    const setTimer = options.setTimeout ?? window.setTimeout.bind(window);
    const clearTimer = options.clearTimeout ?? window.clearTimeout.bind(window);
    const now = options.now ?? (() => Date.now());
    const hidden = options.isHidden ?? (() => document.hidden);
    let region = document.querySelector('#application-results, #application-analysis');
    const form = document.querySelector('.filters');
    const status = document.getElementById('refresh-status');
    const button = document.getElementById('refresh-button');
    if (!region || !status || !button) return null;
    const selector = `#${region.id}`;
    const identity = region.dataset.applicationId;
    let acceptedUrl = new URL(region.dataset.canonicalUrl, window.location.href);
    let desiredUrl = acceptedUrl;
    let generation = 0;
    let controller;
    let debounce;
    let requestTimer;
    let pollTimer;
    let deadlineTimer;
    let deadline = options.deadline ?? null;
    let requestIntent;
    let retryIntent = 'manual';
    let stopped = false;
    let composing = false;
    let state = 'idle';
    const listeners = [];
    const fallback = document.createElement('a');
    fallback.textContent = 'Open results page';
    fallback.hidden = true;
    button.after(fallback);

    function listen(target, type, callback) {
        target.addEventListener(type, callback);
        listeners.push(() => target.removeEventListener(type, callback));
    }

    function announce(text, nextState) {
        state = nextState;
        status.textContent = text;
        button.textContent = nextState === 'refresh-error' ? 'Try again' : 'Refresh results';
        fallback.hidden = nextState !== 'refresh-error';
        fallback.href = desiredUrl.href;
    }

    function cancel() {
        generation++;
        controller?.abort();
        clearTimer(requestTimer);
        clearTimer(debounce);
        clearTimer(pollTimer);
        region.removeAttribute('aria-busy');
    }

    function active() {
        return region.matches('[data-enrichment-status="pending"], [data-enrichment-status="processing"]')
            || Boolean(region.querySelector('[data-enrichment-status="pending"], [data-enrichment-status="processing"]'));
    }

    function expire() {
        clearTimer(pollTimer);
        clearTimer(deadlineTimer);
        if (requestIntent === 'poll' && state === 'refreshing') cancel();
        if (!stopped && active()) announce('Automatic refreshing stopped after 2 minutes. Refresh results to check again.', 'timed-out');
    }

    function schedule(newSession = false) {
        clearTimer(pollTimer);
        clearTimer(deadlineTimer);
        if (stopped || !active()) return;
        if (newSession || deadline === null) deadline = now() + 120000;
        if (now() >= deadline) { expire(); return; }
        deadlineTimer = setTimer(expire, deadline - now());
        if (hidden()) {
            announce('Automatic refreshing is paused while this tab is hidden.', 'paused');
            return;
        }
        state = 'scheduled';
        pollTimer = setTimer(() => {
            if (hidden()) { schedule(); return; }
            if (now() >= deadline) { expire(); return; }
            void refresh(acceptedUrl, 'poll');
        }, Math.min(3000, deadline - now()));
    }

    function formUrl() {
        const url = new URL(form.action, window.location.href);
        url.search = '';
        for (const name of FILTERS) {
            // Send raw text. PHP remains the authoritative normalizer.
            const value = form.elements.namedItem(name).value;
            if (value !== '') url.searchParams.set(name, value);
        }
        return url;
    }

    function restoreControls(url) {
        if (!form) return;
        for (const name of FILTERS) {
            form.elements.namedItem(name).value = url.searchParams.get(name) ?? '';
        }
    }

    function replaceRegion(next) {
        const scroll = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
        const focused = document.activeElement;
        const focusedCard = region.contains(focused) && focused.closest('[data-application-id]');
        const focusInside = region.contains(focused);
        region.replaceWith(next);
        region = next;
        window.scrollTo?.(scroll);
        if (focusInside) {
            const link = focusedCard && [...region.querySelectorAll('[data-application-id]')]
                .find((card) => card.dataset.applicationId === focusedCard.dataset.applicationId)
                ?.querySelector('[data-detail-link]');
            (link ?? region.querySelector('h2')).focus({ preventScroll: true });
        }
    }

    async function refresh(url = desiredUrl, intent = 'manual') {
        if (stopped || (intent === 'poll' && (hidden() || !active() || now() >= deadline))) return;
        cancel();
        requestIntent = intent;
        retryIntent = intent === 'filter' || intent === 'history' ? intent : 'manual';
        desiredUrl = new URL(url, window.location.href);
        if (desiredUrl.origin !== window.location.origin || desiredUrl.pathname !== acceptedUrl.pathname) return;
        const current = generation;
        controller = new AbortController();
        const requestController = controller;
        region.setAttribute('aria-busy', 'true');
        announce(form ? 'Updating applications…' : 'Updating Mock analysis…', 'refreshing');
        try {
            const timeout = new Promise((_, reject) => {
                requestTimer = setTimer(() => {
                    requestController.abort();
                    reject(new Error('Refresh timed out.'));
                }, intent === 'poll' ? Math.min(10000, deadline - now()) : 10000);
            });
            const { response, html } = await Promise.race([
                fetchPage(desiredUrl.href, { signal: requestController.signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } })
                    .then(async (response) => ({ response, html: await response.text() })),
                timeout,
            ]);
            if (stopped || current !== generation) return;
            const responseUrl = new URL(response.url || desiredUrl.href);
            if (!response.ok || responseUrl.origin !== window.location.origin || responseUrl.pathname !== desiredUrl.pathname || !response.headers.get('Content-Type')?.startsWith('text/html')) {
                throw new Error('Unexpected refresh response.');
            }
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            if (stopped || current !== generation) return;
            const next = parsed.querySelector(selector);
            if (!next || next.dataset.applicationId !== identity || next.querySelector('script')) throw new Error('Missing refresh region.');
            const canonical = new URL(next.dataset.canonicalUrl, window.location.href);
            if (canonical.origin !== window.location.origin || canonical.pathname !== desiredUrl.pathname) throw new Error('Invalid canonical URL.');
            replaceRegion(document.importNode(next, true));
            acceptedUrl = canonical;
            desiredUrl = canonical;
            retryIntent = 'manual';
            if (intent === 'filter' && canonical.href !== window.location.href) window.history.pushState(null, '', canonical.href);
            if (form) {
                for (const field of form.querySelectorAll('[aria-invalid]')) {
                    field.removeAttribute('aria-invalid');
                    field.removeAttribute('aria-describedby');
                }
                for (const error of form.querySelectorAll('.field-error')) error.remove();
            }
            announce(form ? 'Applications updated.' : 'Mock analysis updated.', 'idle');
            schedule(intent === 'filter' || intent === 'history');
        } catch {
            if (!stopped && current === generation) {
                announce('Could not refresh results. Previously loaded results are still shown. Try again or open the results page.', 'refresh-error');
            }
        } finally {
            if (current === generation) {
                clearTimer(requestTimer);
                region.removeAttribute('aria-busy');
            }
        }
    }

    function change(immediate) {
        cancel();
        state = 'idle';
        retryIntent = 'filter';
        desiredUrl = formUrl();
        if (immediate) void refresh(desiredUrl, 'filter');
        else debounce = setTimer(() => void refresh(desiredUrl, 'filter'), 300);
    }

    function start() {
        document.querySelector('.refresh-controls').hidden = false;
        listen(button, 'click', () => void refresh(desiredUrl, retryIntent));
        if (form) {
            listen(form, 'submit', (event) => { event.preventDefault(); change(true); });
            for (const select of form.querySelectorAll('select')) listen(select, 'change', () => change(true));
            const search = form.elements.namedItem('search');
            listen(search, 'compositionstart', () => { composing = true; retryIntent = 'manual'; cancel(); });
            listen(search, 'compositionend', () => { composing = false; change(false); });
            listen(search, 'input', () => { if (!composing) change(false); });
            const clear = form.querySelector('.clear-filters');
            listen(clear, 'click', (event) => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                restoreControls(new URL(form.action));
                change(true);
            });
            listen(window, 'popstate', () => {
                cancel();
                const url = new URL(window.location.href);
                restoreControls(url);
                void refresh(url, 'history');
            });
        }
        listen(document, 'visibilitychange', () => {
            if (hidden()) {
                clearTimer(pollTimer);
                if (requestIntent === 'poll' && state === 'refreshing') cancel();
                if (active() && !['refresh-error', 'timed-out'].includes(state)) announce('Automatic refreshing is paused while this tab is hidden.', 'paused');
            } else if (!['refresh-error', 'timed-out', 'refreshing'].includes(state)) {
                schedule();
            }
        });
        listen(window, 'pagehide', destroy);
        schedule();
    }

    function destroy() {
        stopped = true;
        cancel();
        clearTimer(deadlineTimer);
        listeners.splice(0).forEach((remove) => remove());
        fallback.remove();
        state = 'stopped';
    }

    return { start, destroy, refresh, get state() { return state; }, get deadline() { return deadline; } };
}
