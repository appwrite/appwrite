const totals = {
    calls: 0,
    dependency: 0,
    fallback: 0,
    shortCircuits: 0,
};

let autoTimer = null;
let runningScenario = false;

const els = {
    stateBadge: document.querySelector('#stateBadge'),
    activeKey: document.querySelector('#activeKey'),
    failures: document.querySelector('#failures'),
    successes: document.querySelector('#successes'),
    threshold: document.querySelector('#threshold'),
    nextRetry: document.querySelector('#nextRetry'),
    latency: document.querySelector('#latency'),
    latencyValue: document.querySelector('#latencyValue'),
    burstCalls: document.querySelector('#burstCalls'),
    failureRate: document.querySelector('#failureRate'),
    autoInterval: document.querySelector('#autoInterval'),
    key: document.querySelector('#key'),
    configThreshold: document.querySelector('#configThreshold'),
    configTimeout: document.querySelector('#configTimeout'),
    configSuccessThreshold: document.querySelector('#configSuccessThreshold'),
    scenario: document.querySelector('#scenario'),
    scenarioState: document.querySelector('#scenarioState'),
    totalCalls: document.querySelector('#totalCalls'),
    dependencyCalls: document.querySelector('#dependencyCalls'),
    fallbackCalls: document.querySelector('#fallbackCalls'),
    shortCircuits: document.querySelector('#shortCircuits'),
    events: document.querySelector('#events'),
    lastResult: document.querySelector('#lastResult'),
    grafanaLink: document.querySelector('#grafanaLink'),
    prometheusLink: document.querySelector('#prometheusLink'),
    autoTraffic: document.querySelector('#autoTraffic'),
};

function numberValue(element, fallback) {
    const value = Number.parseInt(element.value, 10);
    return Number.isFinite(value) ? value : fallback;
}

function latency() {
    return numberValue(els.latency, 80);
}

function configPayload() {
    return {
        key: els.key.value.trim() || 'local-api',
        threshold: numberValue(els.configThreshold, 3),
        timeout: numberValue(els.configTimeout, 8),
        successThreshold: numberValue(els.configSuccessThreshold, 2),
    };
}

function requestPayload(extra = {}) {
    return { ...configPayload(), ...extra };
}

function statusPath() {
    return `/api/status?${new URLSearchParams(configPayload()).toString()}`;
}

function setBusy(element, busy) {
    element.disabled = busy;
    element.setAttribute('aria-busy', String(busy));
}

function addEvent(title, detail) {
    const item = document.createElement('li');
    const titleNode = document.createElement('strong');
    const detailNode = document.createElement('span');
    const timeNode = document.createElement('small');

    titleNode.textContent = title;
    detailNode.textContent = detail;
    timeNode.textContent = new Date().toLocaleTimeString();

    item.append(titleNode, detailNode, timeNode);
    els.events.prepend(item);

    while (els.events.children.length > 18) {
        els.events.lastElementChild.remove();
    }
}

function render(payload) {
    els.stateBadge.dataset.state = payload.state;
    els.stateBadge.textContent = payload.stateLabel;
    els.activeKey.textContent = payload.config?.key ?? configPayload().key;
    els.failures.textContent = payload.failures;
    els.successes.textContent = payload.successes;
    els.threshold.textContent = payload.threshold;
    els.nextRetry.textContent = payload.nextRetryIn ?? 0;

    if (typeof payload.grafanaUrl === 'string' && payload.grafanaUrl !== '') {
        els.grafanaLink.href = payload.grafanaUrl;
    }
    if (typeof payload.prometheusUrl === 'string' && payload.prometheusUrl !== '') {
        els.prometheusLink.href = payload.prometheusUrl;
    }
}

function renderTotals() {
    els.totalCalls.textContent = totals.calls;
    els.dependencyCalls.textContent = totals.dependency;
    els.fallbackCalls.textContent = totals.fallback;
    els.shortCircuits.textContent = totals.shortCircuits;
}

function recordCall(call) {
    totals.calls += 1;

    if (call.path === 'dependency') {
        totals.dependency += 1;
    }

    if (call.path === 'fallback') {
        totals.fallback += 1;
    }

    if (call.before === 'open') {
        totals.shortCircuits += 1;
    }

    renderTotals();
    addEvent(call.mode, `${call.before} -> ${call.after}, ${call.path}`);
}

async function request(path, options = {}) {
    const response = await fetch(path, {
        headers: { 'Content-Type': 'application/json' },
        ...options,
    });

    if (!response.ok) {
        throw new Error(`Request failed with HTTP ${response.status}`);
    }

    return response.json();
}

async function refresh() {
    render(await request(statusPath()));
}

async function call(mode) {
    const payload = await request('/api/call', {
        method: 'POST',
        body: JSON.stringify(requestPayload({ mode, latency: latency() })),
    });

    render(payload);
    recordCall(payload.call);
    els.lastResult.textContent = payload.call.path;
    return payload;
}

async function burst() {
    const payload = await request('/api/burst', {
        method: 'POST',
        body: JSON.stringify(requestPayload({
            calls: numberValue(els.burstCalls, 12),
            failureRate: numberValue(els.failureRate, 40),
            latency: latency(),
        })),
    });

    render(payload);
    payload.results.forEach(recordCall);
    els.lastResult.textContent = `${payload.results.length} calls`;
    return payload;
}

async function reset() {
    const payload = await request('/api/reset', {
        method: 'POST',
        body: JSON.stringify(configPayload()),
    });

    render(payload);
    els.lastResult.textContent = 'Reset';
    addEvent('reset', 'state cleared');
    return payload;
}

function sleep(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function runScenario() {
    if (runningScenario) {
        return;
    }

    runningScenario = true;
    els.scenarioState.textContent = 'Running';
    const scenario = els.scenario.value;
    const threshold = numberValue(els.configThreshold, 3);
    const successThreshold = numberValue(els.configSuccessThreshold, 2);
    const timeout = numberValue(els.configTimeout, 8);

    try {
        if (scenario === 'trip') {
            await reset();
            for (let i = 0; i < threshold; i++) {
                await call('failure');
            }
            await call('success');
        }

        if (scenario === 'recover') {
            await reset();
            for (let i = 0; i < threshold; i++) {
                await call('failure');
            }
            addEvent('wait', `${timeout + 1}s until half-open`);
            await sleep((timeout + 1) * 1000);
            for (let i = 0; i < successThreshold; i++) {
                await call('success');
            }
        }

        if (scenario === 'noisy') {
            await burst();
            await sleep(400);
            await burst();
            await sleep(400);
            await call('success');
        }
    } catch (error) {
        addEvent('error', error.message);
    } finally {
        runningScenario = false;
        els.scenarioState.textContent = 'Ready';
        await refresh().catch((error) => addEvent('error', error.message));
    }
}

function autoMode() {
    return Math.random() * 100 < numberValue(els.failureRate, 40) ? 'failure' : 'success';
}

function toggleAutoTraffic() {
    if (autoTimer !== null) {
        window.clearInterval(autoTimer);
        autoTimer = null;
        els.autoTraffic.textContent = 'Start auto';
        els.autoTraffic.setAttribute('aria-pressed', 'false');
        addEvent('auto', 'stopped');
        return;
    }

    const interval = Math.max(250, Math.min(5000, numberValue(els.autoInterval, 1000)));
    autoTimer = window.setInterval(() => {
        call(autoMode()).catch((error) => addEvent('error', error.message));
    }, interval);
    els.autoTraffic.textContent = 'Stop auto';
    els.autoTraffic.setAttribute('aria-pressed', 'true');
    addEvent('auto', `started every ${interval}ms`);
}

document.querySelectorAll('[data-action]').forEach((button) => {
    button.addEventListener('click', () => {
        setBusy(button, true);
        call(button.dataset.action)
            .catch((error) => addEvent('error', error.message))
            .finally(() => setBusy(button, false));
    });
});

document.querySelector('#burst').addEventListener('click', () => {
    burst().catch((error) => addEvent('error', error.message));
});

document.querySelector('#reset').addEventListener('click', () => {
    reset().catch((error) => addEvent('error', error.message));
});

document.querySelector('#refresh').addEventListener('click', () => {
    refresh().catch((error) => addEvent('error', error.message));
});

document.querySelector('#runScenario').addEventListener('click', () => {
    runScenario();
});

document.querySelector('#clearEvents').addEventListener('click', () => {
    els.events.replaceChildren();
});

els.autoTraffic.addEventListener('click', toggleAutoTraffic);

els.latency.addEventListener('input', () => {
    els.latencyValue.textContent = `${latency()}ms`;
});

[els.key, els.configThreshold, els.configTimeout, els.configSuccessThreshold].forEach((input) => {
    input.addEventListener('change', () => {
        refresh().catch((error) => addEvent('error', error.message));
    });
});

refresh()
    .then(() => {
        renderTotals();
        addEvent('ready', 'local-api');
    })
    .catch((error) => addEvent('error', error.message));
