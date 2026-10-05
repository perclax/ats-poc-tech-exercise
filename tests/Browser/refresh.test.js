import { createRefresh } from '/scripts/refresh.js';
import { focusValidation } from '/scripts/application.js';

const assert = (condition, message) => { if (!condition) throw new Error(message); };
const settle = async () => {
    await new Promise((resolve) => {
        const channel = new MessageChannel();
        channel.port1.onmessage = () => { channel.port1.close(); channel.port2.close(); resolve(); };
        channel.port2.postMessage(null);
    });
    for (let i = 0; i < 30; i++) await Promise.resolve();
};

function htmlResponse(body, options = {}) {
    return { ok: (options.status ?? 200) < 400, url: '', headers: new Headers(options.headers), text: async () => body };
}

function clock() {
    let time = 0;
    let sequence = 0;
    const timers = new Map();
    return {
        now: () => time,
        setTimeout: (callback, delay) => { timers.set(++sequence, { at: time + delay, callback }); return sequence; },
        clearTimeout: (id) => timers.delete(id),
        async tick(duration) {
            const end = time + duration;
            for (;;) {
                const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
                if (!next) break;
                time = next[1].at;
                timers.delete(next[0]);
                next[1].callback();
                await settle();
            }
            time = end;
            await settle();
        },
        get size() { return timers.size; },
    };
}

function markup(state = 'completed', detail = false, canonical = detail ? '/applications/fictional-id' : '/applications') {
    const content = `<h2 tabindex="-1">Results</h2><p class="analysis-summary">Stored fictional summary</p><p class="analysis-score">0/100</p><time>2026-10-05 10:01:00</time>`;
    return detail
        ? `<section id="application-analysis" data-application-id="fictional-id" data-enrichment-status="${state}" data-canonical-url="${canonical}">${content}</section>`
        : `<section id="application-results" data-canonical-url="${canonical}"><h2 tabindex="-1">Results</h2>${state === 'empty' ? '<p>Empty</p>' : `<ul><li data-application-id="fictional-id" data-enrichment-status="${state}"><a data-detail-link href="/applications/fictional-id">Fictional candidate</a>${content}</li></ul>`}</section>`;
}

function fixture(state = 'completed', detail = false) {
    const iframe = document.createElement('iframe');
    iframe.title = 'Isolated fictional test fixture';
    document.getElementById('fixtures').append(iframe);
    const doc = iframe.contentDocument;
    const path = detail ? '/applications/fictional-id' : '/applications';
    const origin = location.origin;
    doc.body.innerHTML = `${detail ? '' : `<form class="filters" action="${origin}/applications"><input name="search"><select name="job"><option value="">All</option><option value="backend-developer">Backend</option></select><select name="applicationStatus"><option value="">All</option><option value="received">Received</option></select><select name="enrichmentStatus"><option value="">All</option><option value="pending">Pending</option></select><button>Apply filters</button><a class="clear-filters" href="${origin}/applications">Clear</a></form>`}<div class="refresh-controls" hidden><p id="refresh-status" role="status"></p><button type="button" id="refresh-button">Refresh results</button></div>${markup(state, detail)}`;
    const win = new EventTarget();
    win.location = { href: origin + path, origin };
    const history = [];
    win.history = { pushState: (_, __, url) => { history.push(url); win.location.href = url; } };
    const timer = clock();
    const requests = [];
    let nextState = state;
    let hidden = false;
    let responder = (url) => {
        const canonical = new URL(url);
        // Simulate the existing PHP trim character set in the controlled server double.
        const search = canonical.searchParams.get('search')?.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '');
        if (search) canonical.searchParams.set('search', search);
        else canonical.searchParams.delete('search');
        const attribute = (canonical.pathname + canonical.search).replaceAll('&', '&amp;').replaceAll('"', '&quot;');
        return Promise.resolve(htmlResponse(markup(nextState, detail, attribute), { headers: { 'Content-Type': 'text/html; charset=UTF-8' } }));
    };
    const app = createRefresh({ document: doc, window: win, ...timer, now: timer.now, setTimeout: timer.setTimeout, clearTimeout: timer.clearTimeout, isHidden: () => hidden,
        fetch: (url, options) => { requests.push({ url, options, at: timer.now() }); return responder(url, options); },
    });
    app.start();
    return {
        doc, win, timer, requests, history, app,
        search: doc.querySelector('[name="search"]'),
        input(value) { this.search.value = value; this.search.dispatchEvent(new Event('input', { bubbles: true })); },
        select(name, value) { const select = doc.querySelector(`[name="${name}"]`); select.value = value; select.dispatchEvent(new Event('change')); },
        set state(value) { nextState = value; },
        set responder(value) { responder = value; },
        set hidden(value) { hidden = value; doc.dispatchEvent(new Event('visibilitychange')); },
        destroy() { app.destroy(); iframe.remove(); },
    };
}

const scenarios = [
    ['Select filters refresh immediately', async (f) => {
        for (const [name, value] of [['job', 'backend-developer'], ['applicationStatus', 'received'], ['enrichmentStatus', 'pending']]) { f.select(name, value); await settle(); }
        assert(f.requests.length === 3, 'Each select must issue an immediate request');
    }],
    ['Search debounces for 300 ms and keeps focus', async (f) => {
        f.search.focus(); f.input('first'); await f.timer.tick(200); f.input('second'); await f.timer.tick(299);
        assert(f.requests.length === 0, 'Search fired before debounce'); await f.timer.tick(1);
        assert(f.requests.length === 1 && f.doc.activeElement === f.search, 'Debounce or focus preservation failed');
    }],
    ['Raw search reaches server; accepted URL uses canonical metadata', async (f) => {
        f.input(' \tfictional '); await f.timer.tick(300);
        assert(new URL(f.requests[0].url).searchParams.get('search') === ' \tfictional ', 'Client silently normalized text');
        assert(new URL(f.win.location.href).searchParams.get('search') === 'fictional', 'Canonical URL not accepted');
        f.input('\u00a0fictional\u00a0'); await f.timer.tick(300);
        assert(new URL(f.win.location.href).searchParams.get('search') === '\u00a0fictional\u00a0', 'Non-PHP whitespace was trimmed');
    }],
    ['Empty search removes its parameter', async (f) => { f.input('fictional'); await f.timer.tick(300); f.input(''); await f.timer.tick(300); assert(!new URL(f.win.location.href).searchParams.has('search'), 'Empty search parameter remains'); }],
    ['IME composition waits; Enter bypasses debounce', async (f) => {
        f.search.dispatchEvent(new Event('compositionstart')); f.input('fictional'); await f.timer.tick(400); assert(f.requests.length === 0, 'Request during composition');
        f.search.dispatchEvent(new Event('compositionend')); f.doc.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true })); await settle(); assert(f.requests.length === 1, 'Submit did not bypass debounce');
    }],
    ['Aborted late responses cannot overwrite newer input', async (f) => {
        let resolve; f.responder = () => new Promise(r => { resolve = r; });
        f.input('older'); await f.timer.tick(300); const old = resolve;
        f.input('newer'); assert(f.requests[0].options.signal.aborted, 'Older request not aborted');
        f.responder = () => Promise.resolve(htmlResponse(markup('completed', false, '/applications?search=newer'), { headers: { 'Content-Type': 'text/html' } }));
        await f.timer.tick(300); old(htmlResponse(markup('failed', false, '/applications?search=older'), { headers: { 'Content-Type': 'text/html' } })); await settle();
        assert(f.doc.querySelector('[data-enrichment-status]').dataset.enrichmentStatus === 'completed' && f.win.location.href.endsWith('search=newer'), 'Late result replaced newer result');
    }],
    ['History pushes accepted filters and restores Back/Forward', async (f) => {
        f.input('one'); await f.timer.tick(300); f.input('two'); await f.timer.tick(300); assert(f.history.length === 2, 'Missing filter history');
        f.win.location.href = f.history[0]; f.win.dispatchEvent(new Event('popstate')); await settle();
        assert(f.search.value === 'one' && f.history.length === 2, 'Back did not restore controls or added history');
        f.win.location.href = f.history[1]; f.win.dispatchEvent(new Event('popstate')); await settle(); assert(f.search.value === 'two', 'Forward failed');
    }],
    ['Loading, network error, retained results, and retry', async (f) => {
        let reject; f.responder = () => new Promise((_, r) => { reject = r; }); f.select('job', 'backend-developer');
        assert(f.doc.querySelector('#application-results').getAttribute('aria-busy') === 'true', 'Missing busy state');
        reject(new Error('Controlled offline failure')); await settle();
        assert(f.app.state === 'refresh-error' && f.doc.body.textContent.includes('Previously loaded') && f.doc.querySelector('[data-detail-link]'), 'Missing error or retained results');
        f.responder = () => Promise.resolve(htmlResponse(markup(), { headers: { 'Content-Type': 'text/html' } }));
        f.doc.querySelector('#refresh-button').click(); await settle(); assert(f.app.state === 'idle', `Retry failed: ${f.app.state}`);
    }],
    ['Failed filter retry pushes canonical URL once and starts a new active session', async (f) => {
        const previous = f.doc.querySelector('#application-results').outerHTML;
        const previousUrl = f.win.location.href;
        f.responder = () => Promise.reject(new Error('Controlled filter failure'));
        f.select('job', 'backend-developer'); await settle();
        assert(f.win.location.href === previousUrl && f.history.length === 0 && f.doc.querySelector('#application-results').outerHTML === previous, 'Failed filters changed URL or results');
        await f.timer.tick(5000);
        f.responder = (url) => Promise.resolve(htmlResponse(markup('pending', false, new URL(url).pathname + new URL(url).search), { headers: { 'Content-Type': 'text/html' } }));
        f.doc.querySelector('#refresh-button').click(); await settle();
        assert(f.requests[1].url === f.requests[0].url && new URL(f.win.location.href).searchParams.get('job') === 'backend-developer', 'Retry lost desired filter URL');
        assert(f.history.length === 1 && f.doc.querySelector('[data-enrichment-status]').dataset.enrichmentStatus === 'pending', 'Retry did not accept filtered results exactly once');
        assert(f.app.deadline === 125000, 'Filter retry did not establish new polling session');
        await f.timer.tick(3000); assert(f.requests.length === 3 && f.history.length === 1, 'New session did not poll or added history');
    }],
    ['Failed history retry restores results without duplicate history', async (f) => {
        f.input('one'); await f.timer.tick(300); f.input('two'); await f.timer.tick(300);
        f.responder = () => Promise.reject(new Error('Controlled history failure'));
        f.win.location.href = f.history[0]; f.win.dispatchEvent(new Event('popstate')); await settle();
        const previous = f.doc.querySelector('#application-results').outerHTML;
        f.responder = (url) => Promise.resolve(htmlResponse(markup('pending', false, new URL(url).pathname + new URL(url).search), { headers: { 'Content-Type': 'text/html' } }));
        f.doc.querySelector('#refresh-button').click(); await settle();
        assert(f.history.length === 2 && f.search.value === 'one' && f.doc.querySelector('#application-results').outerHTML !== previous, 'History retry duplicated entry or failed to restore results');
        assert(f.app.deadline === 120600, 'History retry lost history session semantics');
    }],
    ['Failed poll retry is manual and retains remaining budget without history', async () => {
        const f = fixture('pending');
        try {
            f.responder = () => Promise.reject(new Error('Controlled poll failure')); await f.timer.tick(3000);
            await f.timer.tick(5000);
            f.responder = () => Promise.resolve(htmlResponse(markup('pending'), { headers: { 'Content-Type': 'text/html' } }));
            f.doc.querySelector('#refresh-button').click(); await settle();
            assert(f.history.length === 0 && f.app.deadline === 120000, 'Poll retry added history or reset deadline');
            await f.timer.tick(3000); assert(f.requests.length === 3, 'Poll retry did not use remaining budget');
        } finally { f.destroy(); }
    }],
    ['New filter input supersedes stored history retry intent', async (f) => {
        f.responder = () => Promise.reject(new Error('Controlled history failure'));
        f.win.location.href += '?search=old'; f.win.dispatchEvent(new Event('popstate')); await settle();
        f.input('new'); await f.timer.tick(300);
        f.responder = (url) => Promise.resolve(htmlResponse(markup('completed', false, new URL(url).pathname + new URL(url).search), { headers: { 'Content-Type': 'text/html' } }));
        f.doc.querySelector('#refresh-button').click(); await settle();
        assert(f.history.length === 1 && new URL(f.win.location.href).searchParams.get('search') === 'new', 'New filter retained history retry intent');
    }],
    ['Filter edits supersede history retry even before debounce expires', async (f) => {
        f.responder = () => Promise.reject(new Error('Controlled history failure'));
        f.win.location.href += '?search=old'; f.win.dispatchEvent(new Event('popstate')); await settle();
        f.input('new');
        f.responder = (url) => Promise.resolve(htmlResponse(markup('completed', false, new URL(url).pathname + new URL(url).search), { headers: { 'Content-Type': 'text/html' } }));
        f.doc.querySelector('#refresh-button').click(); await settle(); await f.timer.tick(300);
        assert(f.history.length === 1 && f.requests.length === 2 && new URL(f.win.location.href).searchParams.get('search') === 'new', 'Retry during debounce used stale intent or issued duplicate request');
    }],
    ['Failed ordinary manual refresh stays manual without resetting budget', async () => {
        const f = fixture('pending');
        try {
            f.responder = () => Promise.reject(new Error('Controlled manual failure')); await f.app.refresh();
            await f.timer.tick(5000);
            f.responder = () => Promise.resolve(htmlResponse(markup('pending'), { headers: { 'Content-Type': 'text/html' } }));
            f.doc.querySelector('#refresh-button').click(); await settle();
            assert(f.history.length === 0 && f.app.deadline === 120000, 'Manual retry changed intent');
        } finally { f.destroy(); }
    }],
    ['Unexpected HTML/error status is never inserted', async (f) => {
        f.responder = () => Promise.resolve(htmlResponse('<p>Unexpected error page</p>', { status: 400, headers: { 'Content-Type': 'text/html' } }));
        await f.app.refresh(); assert(f.app.state === 'refresh-error' && !f.doc.body.textContent.includes('Unexpected error page'), 'Unsafe response inserted');
    }],
    ['Completed/failed/empty list does not poll', async () => {
        for (const state of ['completed', 'failed', 'empty']) { const f = fixture(state); try { await f.timer.tick(125000); assert(f.requests.length === 0, `${state} list polled`); } finally { f.destroy(); } }
    }],
    ['Active list first polls at 3 seconds and stops on completion', async () => {
        const f = fixture('pending'); try { await f.timer.tick(2999); assert(f.requests.length === 0, 'First poll too early'); f.state = 'completed'; await f.timer.tick(1); assert(f.requests.length === 1 && f.requests[0].at === 3000, 'First poll timing wrong'); await f.timer.tick(125000); assert(f.requests.length === 1, 'Completion did not stop polling'); } finally { f.destroy(); }
    }],
    ['Active detail preserves stored zero summary and timestamp', async () => {
        const f = fixture('processing', true); try { f.state = 'completed'; await f.timer.tick(3000); assert(f.doc.querySelector('.analysis-score').textContent === '0/100' && f.doc.querySelector('.analysis-summary').textContent === 'Stored fictional summary' && f.doc.querySelector('time').textContent === '2026-10-05 10:01:00', 'Stored zero result changed'); await f.timer.tick(120000); assert(f.requests.length === 1, 'Completed detail still polls'); } finally { f.destroy(); }
    }],
    ['Failed detail stops polling', async () => { const f = fixture('pending', true); try { f.state = 'failed'; await f.timer.tick(3000); await f.timer.tick(125000); assert(f.requests.length === 1, 'Failed detail still polls'); } finally { f.destroy(); } }],
    ['Hidden tabs pause and resume within the original budget', async () => {
        const f = fixture('pending'); try { f.hidden = true; await f.timer.tick(30000); assert(f.requests.length === 0, 'Hidden tab polled'); f.hidden = false; await f.timer.tick(3000); assert(f.requests.length === 1 && f.app.deadline === 120000, 'Resume changed budget'); } finally { f.destroy(); }
    }],
    ['Slow polls never overlap and network timeout pauses work', async () => {
        const f = fixture('pending'); try { f.responder = () => new Promise(() => {}); await f.timer.tick(3000); await f.timer.tick(9999); assert(f.requests.length === 1, 'Overlapping polls'); await f.timer.tick(1); assert(f.app.state === 'refresh-error' && f.requests[0].options.signal.aborted, 'Request timeout failed'); await f.timer.tick(10000); assert(f.requests.length === 1, 'Error kept polling'); } finally { f.destroy(); }
    }],
    ['Fixed deadline stops polling; manual refresh does not restart it', async () => {
        const f = fixture('pending'); try { await f.timer.tick(120000); const count = f.requests.length; assert(f.app.state === 'timed-out' && f.doc.body.textContent.includes('2 minutes'), 'Missing bounded timeout'); await f.timer.tick(10000); assert(f.requests.length === count, 'Polling continued after timeout'); f.doc.querySelector('#refresh-button').click(); await settle(); await f.timer.tick(10000); assert(f.requests.length === count + 1 && f.app.state === 'timed-out', 'Manual refresh restarted expired polling'); } finally { f.destroy(); }
    }],
    ['Hidden time and network failures consume polling budget', async () => {
        const f = fixture('pending'); try { f.responder = () => Promise.reject(new Error('Offline')); await f.timer.tick(3000); f.hidden = true; await f.timer.tick(117000); f.hidden = false; await f.timer.tick(5000); assert(f.app.state === 'timed-out' && f.requests.length === 1, 'Errors or visibility extended budget'); } finally { f.destroy(); }
    }],
    ['Filter changes cancel polls and establish a new bounded session', async () => {
        const f = fixture('pending'); try { f.responder = () => new Promise(() => {}); await f.timer.tick(3000); f.input('fictional'); assert(f.requests[0].options.signal.aborted, 'Poll not cancelled for filter input'); f.responder = () => Promise.resolve(htmlResponse(markup('pending', false, '/applications?search=fictional'), { headers: { 'Content-Type': 'text/html' } })); await f.timer.tick(300); assert(f.app.deadline === 123300, 'New filter session budget wrong'); await f.timer.tick(3000); assert(f.requests.length === 3, 'Polling did not resume for new filters'); } finally { f.destroy(); }
    }],
    ['Navigation cleans up timers and ignores late responses', async () => {
        const f = fixture('pending'); try { let resolve; f.responder = () => new Promise(r => { resolve = r; }); await f.timer.tick(3000); f.win.dispatchEvent(new Event('pagehide')); resolve(htmlResponse(markup('completed'), { headers: { 'Content-Type': 'text/html' } })); await settle(); await f.timer.tick(130000); assert(f.app.state === 'stopped' && f.timer.size === 0 && f.doc.querySelector('[data-enrichment-status]').dataset.enrichmentStatus === 'pending', 'Navigation cleanup failed'); } finally { f.destroy(); }
    }],
    ['Focused result links survive replacement and missing rows focus the heading', async (f) => {
        const link = f.doc.querySelector('[data-detail-link]'); link.focus();
        await f.app.refresh(); assert(f.doc.activeElement.matches('[data-detail-link]'), 'Result focus was lost');
        f.state = 'empty'; await f.app.refresh(); assert(f.doc.activeElement.matches('#application-results h2'), 'Missing result did not focus heading');
    }],
    ['Empty results stop an active list session', async () => {
        const f = fixture('pending'); try { f.state = 'empty'; await f.timer.tick(3000); await f.timer.tick(125000); assert(f.requests.length === 1, 'Empty results kept polling'); } finally { f.destroy(); }
    }],
    ['Completed and failed details never start polling', async () => {
        for (const state of ['completed', 'failed']) { const f = fixture(state, true); try { await f.timer.tick(125000); assert(f.requests.length === 0, 'Terminal detail polled'); } finally { f.destroy(); } }
    }],
    ['Response body timeout aborts and retains displayed results', async (f) => {
        f.responder = () => Promise.resolve({ ok: true, url: '', headers: new Headers({ 'Content-Type': 'text/html' }), text: () => new Promise(() => {}) });
        const request = f.app.refresh(); await f.timer.tick(10000); await request;
        assert(f.app.state === 'refresh-error' && f.requests[0].options.signal.aborted && f.doc.querySelector('[data-detail-link]'), 'Body timeout failed');
    }],
    ['Restored pages retain their deadline without duplicate fallback links', async () => {
        const f = fixture('pending');
        const deadline = f.app.deadline;
        f.app.destroy();
        assert(f.doc.querySelectorAll('.refresh-controls a').length === 0, 'Fallback link was not cleaned up');
        const restored = createRefresh({ document: f.doc, window: f.win, fetch: async () => htmlResponse(markup('pending'), { headers: { 'Content-Type': 'text/html' } }), now: f.timer.now, setTimeout: f.timer.setTimeout, clearTimeout: f.timer.clearTimeout, isHidden: () => false, deadline });
        try {
            restored.start();
            await f.timer.tick(120000);
            assert(restored.state === 'timed-out' && restored.deadline === deadline && f.doc.querySelectorAll('.refresh-controls a').length === 1, 'Restoration changed deadline or duplicated controls');
        } finally { restored.destroy(); f.destroy(); }
    }],
    ['Validation summary focuses and links focus fields', async (f) => {
        f.doc.body.innerHTML = '<div id="validation-summary" tabindex="-1"><a href="#field">Correct field</a></div><input id="field">';
        focusValidation(f.doc); assert(f.doc.activeElement.id === 'validation-summary', 'Summary not focused'); f.doc.querySelector('a').click(); assert(f.doc.activeElement.id === 'field', 'Summary link did not focus field');
    }],
];

let failures = 0;
for (const [name, test] of scenarios) {
    const item = document.createElement('li');
    item.textContent = `${name}: running`;
    document.getElementById('scenarios').append(item);
    const f = fixture();
    try { await test(f); item.textContent = `PASS — ${name}`; item.dataset.result = 'pass'; }
    catch (error) { failures++; item.textContent = `FAIL — ${name}: ${error.message}`; item.dataset.result = 'fail'; console.error(`FAIL — ${name}`, error); }
    finally { f.destroy(); }
}
const summary = document.getElementById('summary');
summary.textContent = `${scenarios.length - failures}/${scenarios.length} scenarios passed; ${failures} failed.`;
summary.dataset.complete = 'true';
summary.dataset.failures = String(failures);
