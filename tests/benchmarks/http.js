/*
 * Run locally:
 * Requires k6 and a running Appwrite instance.
 *
 * tests/benchmarks/http-local.sh
 *
 * Open http://127.0.0.1:5665 while the benchmark is running.
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import encoding from 'k6/encoding';
import { Counter, Trend } from 'k6/metrics';

const ENDPOINT = (__ENV.APPWRITE_ENDPOINT || 'http://localhost/v1').replace(/\/+$/, '');
const CONSOLE_PROJECT = __ENV.APPWRITE_CONSOLE_PROJECT || 'console';
const REGION = __ENV.APPWRITE_REGION || 'default';
const REDIRECT_URL = __ENV.APPWRITE_BENCHMARK_REDIRECT_URL || 'http://localhost';
const PASSWORD = __ENV.APPWRITE_BENCHMARK_PASSWORD || 'Password123!';
const WORKER_TIMEOUT_MS = Number(__ENV.APPWRITE_WORKER_TIMEOUT_MS || 120000);
const ITERATIONS = Number(__ENV.APPWRITE_BENCHMARK_ITERATIONS || 1);
const VUS = Number(__ENV.APPWRITE_BENCHMARK_VUS || 1);
const SUMMARY_PATH = __ENV.APPWRITE_BENCHMARK_SUMMARY_PATH || '/tmp/appwrite-k6-summary.json';
const PREVIOUS_SUMMARY_PATH = __ENV.APPWRITE_BENCHMARK_PREVIOUS_SUMMARY_PATH || '';
const PREVIOUS_SUMMARY = PREVIOUS_SUMMARY_PATH ? loadPreviousSummary(PREVIOUS_SUMMARY_PATH) : null;

export const httpWaiting = new Trend('appwrite_http_waiting', true);
export const apiDuration = new Trend('appwrite_api_duration', true);
export const apiWaiting = new Trend('appwrite_api_waiting', true);
export const flowFailures = new Counter('appwrite_benchmark_flow_failures');

export const options = {
    scenarios: {
        curated_flows: {
            executor: 'shared-iterations',
            exec: 'curatedFlows',
            vus: VUS,
            iterations: ITERATIONS,
            maxDuration: __ENV.APPWRITE_BENCHMARK_MAX_DURATION || '30m',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],
        appwrite_api_duration: ['p(95)<2000'],
        appwrite_benchmark_flow_failures: ['count<1'],
    },
};

const API_SCOPES = [
    'sessions.write',
    'users.read',
    'users.write',
    'teams.read',
    'teams.write',
    'databases.read',
    'databases.write',
    'collections.read',
    'collections.write',
    'tables.read',
    'tables.write',
    'attributes.read',
    'attributes.write',
    'columns.read',
    'columns.write',
    'indexes.read',
    'indexes.write',
    'documents.read',
    'documents.write',
    'rows.read',
    'rows.write',
    'files.read',
    'files.write',
    'buckets.read',
    'buckets.write',
    'functions.read',
    'functions.write',
    'log.read',
    'log.write',
    'execution.read',
    'execution.write',
    'locale.read',
    'avatars.read',
    'rules.read',
    'rules.write',
    'migrations.read',
    'migrations.write',
    'vcs.read',
    'vcs.write',
    'assistant.read',
    'tokens.read',
    'tokens.write',
    'platforms.read',
    'platforms.write',
    'oauth2.read',
    'oauth2.write',
];

const BASE_PERMISSIONS = [
    'read("any")',
    'create("any")',
    'update("any")',
    'delete("any")',
];

const ITEM_PERMISSIONS = [
    'read("any")',
    'update("any")',
    'delete("any")',
];

export function setup() {
    const runId = unique('run');
    const consoleEmail = __ENV.APPWRITE_ADMIN_EMAIL || `bench-admin-${runId}@example.com`;
    const consolePassword = __ENV.APPWRITE_ADMIN_PASSWORD || PASSWORD;

    const consoleHeaders = {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': CONSOLE_PROJECT,
    };

    const account = rawRequest('POST', '/account', {
        userId: unique('admin'),
        email: consoleEmail,
        password: consolePassword,
        name: 'Benchmark Admin',
    }, consoleHeaders, 'setup.account.create');

    if (![201, 409].includes(account.status)) {
        failResponse(account, 'Unable to create or reuse the benchmark console account');
    }

    const session = rawRequest('POST', '/account/sessions/email', {
        email: consoleEmail,
        password: consolePassword,
    }, consoleHeaders, 'setup.account.session');

    assertStatus(session, [201], 'console session created');

    const consoleSessionHeaders = {
        ...consoleHeaders,
        Cookie: cookieHeader(session),
    };

    const team = setupApi('POST', '/teams', {
        teamId: unique('team'),
        name: `Benchmark Team ${runId}`,
    }, consoleSessionHeaders, [201], 'setup.teams.create');

    const teamId = team.json('$id');
    const project = setupApi('POST', '/projects', {
        projectId: unique('project'),
        name: `Benchmark Project ${runId}`,
        teamId,
        region: REGION,
    }, consoleSessionHeaders, [201], 'setup.projects.create');

    const projectId = project.json('$id');
    const key = setupApi('POST', `/projects/${projectId}/keys`, {
        keyId: unique('key'),
        name: 'Benchmark API key',
        scopes: API_SCOPES,
    }, consoleSessionHeaders, [201], 'setup.projects.keys.create');

    const apiHeaders = {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': projectId,
        'X-Appwrite-Key': key.json('secret'),
    };

    const platform = setupApi('POST', '/project/platforms/web', {
        platformId: unique('web'),
        name: 'Benchmark web',
        hostname: hostnameFromUrl(REDIRECT_URL),
    }, apiHeaders, [201, 409], 'setup.project.platforms.web.create');

    const tablesDb = setupTablesDb(apiHeaders);

    return {
        runId,
        teamId,
        projectId,
        databaseId: tablesDb.databaseId,
        tableId: tablesDb.tableId,
        consoleSessionHeaders,
        apiHeaders,
        platformStatus: platform.status,
    };
}

function setupTablesDb(apiHeaders) {
    const databaseId = unique('tdb');
    const tableId = unique('tbl');

    setupApi('POST', '/tablesdb', { databaseId, name: 'Benchmark TablesDB' }, apiHeaders, [201], 'setup.tablesdb.create');
    setupApi('POST', `/tablesdb/${databaseId}/tables`, {
        tableId,
        name: 'Benchmark Table',
        permissions: BASE_PERMISSIONS,
        rowSecurity: false,
    }, apiHeaders, [201], 'setup.tablesdb.tables.create');

    const columns = [
        ['string', 'title', { size: 128 }],
        ['integer', 'quantity', { min: 0, max: 100000 }],
        ['email', 'email', {}],
        ['boolean', 'active', {}],
    ];

    for (const [type, key, extra] of columns) {
        setupApi('POST', `/tablesdb/${databaseId}/tables/${tableId}/columns/${type}`, {
            key,
            required: false,
            array: false,
            ...extra,
        }, apiHeaders, [202], `setup.tablesdb.columns.${type}.create`);
        waitForStatus(`/tablesdb/${databaseId}/tables/${tableId}/columns/${key}`, apiHeaders, 'available', WORKER_TIMEOUT_MS, `setup.tablesdb.columns.${type}.wait`);
    }

    return { databaseId, tableId };
}

export function curatedFlows(data) {
    const ctx = { ...data };

    try {
        group('account flow', () => accountFlow(ctx));
        group('tablesdb rows flow', () => tablesDbFlow(ctx));
        group('storage files and tokens flow', () => storageFlow(ctx));
        group('functions control-plane flow', () => computeFlow(ctx));
    } catch (error) {
        flowFailures.add(1);
        throw error;
    }
}

export function teardown(data) {
    if (data && data.projectId && data.consoleSessionHeaders) {
        rawRequest('DELETE', `/projects/${data.projectId}`, null, data.consoleSessionHeaders, 'teardown.projects.delete');
    }

    if (data && data.teamId && data.consoleSessionHeaders) {
        rawRequest('DELETE', `/teams/${data.teamId}`, null, data.consoleSessionHeaders, 'teardown.teams.delete');
    }
}

function accountFlow(ctx) {
    const userId = unique('user');
    const email = `bench-user-${unique('mail')}@example.com`;
    const headers = projectHeaders(ctx.projectId);

    api('POST', '/account', {
        userId,
        email,
        password: PASSWORD,
        name: 'Benchmark User',
    }, headers, [201], 'account.create');

    const session = api('POST', '/account/sessions/email', {
        email,
        password: PASSWORD,
    }, headers, [201], 'account.sessions.email.create');

    const sessionHeaders = {
        ...headers,
        Cookie: cookieHeader(session),
    };

    ctx.userId = userId;
    ctx.userEmail = email;
    ctx.sessionHeaders = sessionHeaders;

    api('GET', '/account', null, sessionHeaders, [200], 'account.get');
    api('GET', '/account/logs', null, sessionHeaders, [200], 'account.logs.list');
    api('PATCH', '/account/prefs', { prefs: { benchmark: true, runId: ctx.runId } }, sessionHeaders, [200], 'account.prefs.update');
    api('PATCH', '/account/name', { name: 'Benchmark User Updated' }, sessionHeaders, [200], 'account.name.update');
    api('PATCH', '/account/password', { password: `${PASSWORD}2`, oldPassword: PASSWORD }, sessionHeaders, [200], 'account.password.update');
}

function tablesDbFlow(ctx) {
    requireSession(ctx, 'tablesDbFlow');

    const databaseId = ctx.databaseId;
    const tableId = ctx.tableId;
    const rowId = unique('row');

    api('POST', `/tablesdb/${databaseId}/tables/${tableId}/rows`, {
        rowId,
        data: tablePayload(),
        permissions: ITEM_PERMISSIONS,
    }, ctx.sessionHeaders, [201], 'tablesdb.rows.create');
    api('GET', `/tablesdb/${databaseId}/tables/${tableId}/rows`, null, ctx.sessionHeaders, [200], 'tablesdb.rows.list');
    api('GET', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, null, ctx.sessionHeaders, [200], 'tablesdb.rows.get');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, {
        data: { title: 'Benchmark Row Updated' },
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.update');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}/quantity/increment`, {
        value: 1,
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.increment');
    api('PATCH', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}/quantity/decrement`, {
        value: 1,
    }, ctx.sessionHeaders, [200], 'tablesdb.rows.decrement');
    api('DELETE', `/tablesdb/${databaseId}/tables/${tableId}/rows/${rowId}`, null, ctx.sessionHeaders, [204], 'tablesdb.rows.delete');
}

function storageFlow(ctx) {
    requireSession(ctx, 'storageFlow');

    const bucketId = unique('bucket');
    const fileId = unique('file');

    api('POST', '/storage/buckets', {
        bucketId,
        name: 'Benchmark Bucket',
        permissions: BASE_PERMISSIONS,
        fileSecurity: false,
        enabled: true,
        maximumFileSize: 30000000,
        allowedFileExtensions: [],
        compression: 'none',
        encryption: false,
        antivirus: false,
    }, ctx.apiHeaders, [201], 'storage.buckets.create');

    const multipartHeaders = { ...ctx.sessionHeaders };
    delete multipartHeaders['Content-Type'];

    const upload = http.post(`${ENDPOINT}/storage/buckets/${bucketId}/files`, {
        fileId,
        file: http.file(onePixelPng(), 'benchmark.png', 'image/png'),
        ...flattenMultipartArray('permissions', ITEM_PERMISSIONS),
    }, {
        headers: multipartHeaders,
        tags: { name: 'storage.files.create' },
    });

    httpWaiting.add(upload.timings.waiting, { name: 'storage.files.create' });
    apiDuration.add(upload.timings.duration, { name: 'storage.files.create' });
    apiWaiting.add(upload.timings.waiting, { name: 'storage.files.create' });
    assertStatus(upload, [201], 'storage file created');

    api('GET', `/storage/buckets/${bucketId}/files`, null, ctx.sessionHeaders, [200], 'storage.files.list');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}`, null, ctx.sessionHeaders, [200], 'storage.files.get');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/view`, null, ctx.sessionHeaders, [200], 'storage.files.view');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/download`, null, ctx.sessionHeaders, [200], 'storage.files.download');
    api('GET', `/storage/buckets/${bucketId}/files/${fileId}/preview`, null, ctx.sessionHeaders, [200], 'storage.files.preview');
    api('PUT', `/storage/buckets/${bucketId}/files/${fileId}`, {
        name: 'benchmark-renamed.png',
        permissions: ITEM_PERMISSIONS,
    }, ctx.sessionHeaders, [200], 'storage.files.update');

    const token = api('POST', `/tokens/buckets/${bucketId}/files/${fileId}`, {}, ctx.apiHeaders, [201], 'tokens.files.create');
    api('GET', `/tokens/buckets/${bucketId}/files/${fileId}`, null, ctx.apiHeaders, [200], 'tokens.files.list');
    api('GET', `/tokens/${token.json('$id')}`, null, ctx.apiHeaders, [200], 'tokens.get');
    api('PATCH', `/tokens/${token.json('$id')}`, { expire: null }, ctx.apiHeaders, [200], 'tokens.update');
    api('DELETE', `/tokens/${token.json('$id')}`, null, ctx.apiHeaders, [204], 'tokens.delete');

    api('DELETE', `/storage/buckets/${bucketId}/files/${fileId}`, null, ctx.sessionHeaders, [204], 'storage.files.delete');
    api('DELETE', `/storage/buckets/${bucketId}`, null, ctx.apiHeaders, [204], 'storage.buckets.delete');
}

function computeFlow(ctx) {
    requireSession(ctx, 'computeFlow');

    const functionId = unique('fn');
    let functionVariableId;

    api('POST', '/functions', {
        functionId,
        name: 'Benchmark Function',
        runtime: __ENV.APPWRITE_BENCHMARK_RUNTIME || 'node-22',
        execute: ['any'],
        events: [],
        schedule: '',
        timeout: 15,
        enabled: true,
        logging: true,
        entrypoint: 'index.js',
        commands: 'npm install',
        scopes: ['users.read'],
    }, ctx.apiHeaders, [201], 'functions.create');
    api('GET', '/functions/runtimes', null, ctx.sessionHeaders, [200], 'functions.runtimes.list');
    api('GET', '/functions/specifications', null, ctx.apiHeaders, [200], 'functions.specifications.list');
    const functionVariable = api('POST', `/functions/${functionId}/variables`, {
        key: 'BENCHMARK',
        value: 'true',
        secret: false,
    }, ctx.apiHeaders, [201], 'functions.variables.create');
    functionVariableId = functionVariable.json('$id');

    api('PUT', `/functions/${functionId}/variables/${functionVariableId}`, {
        key: 'BENCHMARK',
        value: 'updated',
        secret: false,
    }, ctx.apiHeaders, [200], 'functions.variables.update');
    api('GET', `/functions/${functionId}/variables/${functionVariableId}`, null, ctx.apiHeaders, [200], 'functions.variables.get');
    api('DELETE', `/functions/${functionId}/variables/${functionVariableId}`, null, ctx.apiHeaders, [204], 'functions.variables.delete');
    api('DELETE', `/functions/${functionId}`, null, ctx.apiHeaders, [204], 'functions.delete');
}

function api(method, path, body, headers, expected, name) {
    const response = rawRequest(method, path, body, headers, name);
    apiDuration.add(response.timings.duration, { name });
    apiWaiting.add(response.timings.waiting, { name });
    assertStatus(response, expected, name);
    return response;
}

function setupApi(method, path, body, headers, expected, name) {
    const response = rawRequest(method, path, body, headers, name);
    assertStatus(response, expected, name);
    return response;
}

function rawRequest(method, path, body, headers, name) {
    const params = {
        headers,
        tags: { name },
    };
    const payload = body === null || body === undefined ? null : JSON.stringify(body);
    const response = http.request(method, `${ENDPOINT}${path}`, payload, params);
    httpWaiting.add(response.timings.waiting, { name });

    return response;
}

function waitForStatus(path, headers, wantedStatus, timeoutMs, name) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        const response = rawRequest('GET', path, null, headers, name);
        if (response.status === 200) {
            const status = response.json('status');
            if (status === wantedStatus) {
                return response;
            }
            if (status === 'failed') {
                throw new Error(`${path} failed while waiting for ${wantedStatus}`);
            }
        }
        sleep(0.5);
    }

    throw new Error(`Timed out waiting for ${path} to become ${wantedStatus}`);
}

function assertStatus(response, expected, name) {
    const ok = check(response, {
        [`${name} status ${expected.join('|')}`]: (r) => expected.includes(r.status),
    });

    if (!ok) {
        failResponse(response, `${name} returned an unexpected status`);
    }
}

function failResponse(response, message) {
    throw new Error(`${message}. Status: ${response.status}. Body: ${response.body}`);
}

function cookieHeader(response) {
    return response.headers['Set-Cookie'] || response.headers['set-cookie'] || '';
}

function projectHeaders(projectId) {
    return {
        'Content-Type': 'application/json',
        'X-Appwrite-Project': projectId,
    };
}

function requireSession(ctx, flow) {
    if (!ctx.sessionHeaders || typeof ctx.sessionHeaders !== 'object') {
        throw new Error(`accountFlow must run before ${flow}`);
    }
}

function tablePayload() {
    return {
        title: 'Benchmark Row',
        quantity: 1,
        email: 'row@example.com',
        active: true,
    };
}

function onePixelPng() {
    return encoding.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAXpeqz8AAAAASUVORK5CYII=', 'std', 'b');
}

function flattenMultipartArray(key, values) {
    const output = {};
    values.forEach((value, index) => {
        output[`${key}[${index}]`] = value;
    });
    return output;
}

function unique(prefix) {
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .slice(0, 36);
}

function hostnameFromUrl(value) {
    return value.replace(/^https?:\/\//, '').split('/')[0].split(':')[0];
}

export function handleSummary(data) {
    const lines = [
        'Appwrite curated benchmark review',
        '',
        'Before',
        '',
        summaryTable(PREVIOUS_SUMMARY),
        '',
        'After',
        '',
        summaryTable(data),
        '',
        'Delta',
        '',
        deltaTable(PREVIOUS_SUMMARY, data),
        '',
    ];

    return {
        stdout: `${lines.join('\n')}\n`,
        [SUMMARY_PATH]: JSON.stringify(data, null, 2),
    };
}

function summaryTable(data) {
    return [
        '| Scenario | P50 (ms) | P95 (ms) | Requests | RPS |',
        '| --- | ---: | ---: | ---: | ---: |',
        summaryRow(data, 'API total', 'appwrite_api_duration'),
    ].join('\n');
}

function summaryRow(data, label, metric, iterationsMetric = null, rpsMetric = null) {
    const values = data && data.metrics[metric] && data.metrics[metric].values;
    if (!values || values.count === 0) {
        return `| ${label} | n/a | n/a | n/a | n/a |`;
    }

    const iterations = iterationsMetric
        ? trendMetric(data, iterationsMetric, 'count')
        : values.count;
    const rps = rpsMetric ? trendMetric(data, rpsMetric, 'rate') : null;

    return `| ${label} | ${formatDetailValue(values.med)} | ${formatDetailValue(values['p(95)'])} | ${formatCount(iterations)} | ${formatRate(rps)} |`;
}

function loadPreviousSummary(path) {
    let contents;
    try {
        contents = open(path);
    } catch (error) {
        console.warn(`Missing benchmark summary at ${path}: ${error.message}`);
        return null;
    }

    try {
        return JSON.parse(contents);
    } catch (error) {
        console.warn(`Invalid benchmark summary at ${path}: ${error.message}`);
        return null;
    }
}

function deltaTable(before, after) {
    return [
        '| Scenario | P95 delta (ms) |',
        '| --- | ---: |',
        ...[
            ['API total', 'appwrite_api_duration'],
        ].map(([label, metric]) => {
            const beforeP95 = trendMetric(before, metric, 'p(95)');
            const afterP95 = trendMetric(after, metric, 'p(95)');
            return `| ${label} | ${formatDelta(beforeP95, afterP95)} |`;
        }),
    ].join('\n');
}

function trendMetric(data, metric, stat) {
    return data && data.metrics[metric] && data.metrics[metric].values
        ? data.metrics[metric].values[stat]
        : null;
}

function formatDetailValue(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return `${Number(value).toFixed(2)}`;
}

function formatDelta(before, after) {
    if (before === null || before === undefined || after === null || after === undefined || Number.isNaN(before) || Number.isNaN(after)) {
        return 'n/a';
    }

    const delta = round(after - before);
    const sign = delta > 0 ? '+' : '';
    return `${sign}${delta}`;
}

function formatCount(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return `${Math.round(value)}`;
}

function formatRate(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return 'n/a';
    }

    return `${Number(value).toFixed(2)}`;
}

function round(value) {
    return Math.round((value || 0) * 100) / 100;
}
