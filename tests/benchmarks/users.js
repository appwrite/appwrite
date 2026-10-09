// Users API benchmark: the same realistic /v1/users workload against the PHP
// API and the Rust API, so both can be compared on throughput, latency and
// (with users-compare.sh) CPU time and memory per request.
//
//   APPWRITE_ENDPOINT          API under test (PHP: http://localhost:9501/v1, Rust: http://localhost:9530/v1)
//   APPWRITE_CONTROL_ENDPOINT  PHP API used for console setup (default http://localhost:9501/v1)
//   APPWRITE_ADMIN_EMAIL       console account (password "benchmark-password") that owns the
//                              instance's organization; required once one exists (self-hosted allows one)
//   APPWRITE_BENCHMARK_MODE    "rate" (fixed arrival rate, default) or "vus" (closed loop, max throughput)
//   APPWRITE_BENCHMARK_RATE    requests/s per iteration group in "rate" mode (default 100)
//   APPWRITE_BENCHMARK_VUS     virtual users (default 50)
//   APPWRITE_BENCHMARK_DURATION  e.g. "60s" (default)
//   APPWRITE_BENCHMARK_USERS   users seeded during setup (default 200)
//   APPWRITE_BENCHMARK_HASHING "true" to include plaintext-password creates (Argon2 cost)
//   APPWRITE_BENCHMARK_SUMMARY_PATH  JSON summary output

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const ENDPOINT = __ENV.APPWRITE_ENDPOINT || 'http://localhost:9501/v1';
const CONTROL = __ENV.APPWRITE_CONTROL_ENDPOINT || 'http://localhost:9501/v1';
const MODE = __ENV.APPWRITE_BENCHMARK_MODE || 'rate';
const RATE = parseInt(__ENV.APPWRITE_BENCHMARK_RATE || '100', 10);
const VUS = parseInt(__ENV.APPWRITE_BENCHMARK_VUS || '50', 10);
const DURATION = __ENV.APPWRITE_BENCHMARK_DURATION || '60s';
const SEED_USERS = parseInt(__ENV.APPWRITE_BENCHMARK_USERS || '200', 10);
const HASHING = __ENV.APPWRITE_BENCHMARK_HASHING === 'true';
const PASSWORD = 'benchmark-password';

const apiDuration = new Trend('appwrite_users_duration', true);
const ENDPOINTS = [
    'users.get', 'users.list', 'users.search', 'users.prefs.get', 'users.prefs.update', 'users.name.update',
    'users.targets.list', 'users.create', 'users.delete', 'users.sessions.create', 'users.sessions.delete', 'users.tokens.create',
];
const failures = new Counter('appwrite_users_failures');

export const options = {
    discardResponseBodies: false,
    setupTimeout: '5m',
    summaryTrendStats: ['count', 'avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    scenarios: MODE === 'vus'
        ? { users: { executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'mix' } }
        : {
            users: {
                executor: 'constant-arrival-rate',
                rate: RATE,
                timeUnit: '1s',
                duration: DURATION,
                preAllocatedVUs: VUS,
                maxVUs: VUS * 4,
                exec: 'mix',
            },
        },
    thresholds: {
        appwrite_users_failures: ['count<1'],
        // No-op thresholds so k6 keeps a per-endpoint submetric for the summary.
        ...Object.fromEntries(ENDPOINTS.map((name) => [`appwrite_users_duration{name:${name}}`, ['max>=0']])),
    },
};

function unique(prefix) {
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`.slice(0, 36);
}

function request(base, method, path, body, headers, name) {
    const res = http.request(method, `${base}${path}`, body === null ? null : JSON.stringify(body), {
        headers,
        tags: { name },
    });
    return res;
}

function expect(res, codes, name) {
    const ok = check(res, { [`${name} ${codes.join('|')}`]: (r) => codes.includes(r.status) });
    if (!ok) {
        failures.add(1, { name });
    }
    apiDuration.add(res.timings.duration, { name });
    return res;
}

export function setup() {
    const run = unique('bench');
    const email = __ENV.APPWRITE_ADMIN_EMAIL || `${run}@example.com`;
    const console = { 'Content-Type': 'application/json', 'X-Appwrite-Project': 'console' };
    const account = request(CONTROL, 'POST', '/account', { userId: unique('admin'), email, password: PASSWORD, name: 'Bench' }, console, 'setup');
    if (![201, 409].includes(account.status)) {
        throw new Error(`account: ${account.status} ${account.body}`);
    }
    const session = request(CONTROL, 'POST', '/account/sessions/email', { email, password: PASSWORD }, console, 'setup');
    const cookie = session.headers['Set-Cookie'] || session.headers['set-cookie'] || '';
    const admin = { ...console, Cookie: cookie };
    // Self-hosted allows one organization per instance: once it exists, use the
    // one APPWRITE_ADMIN_EMAIL belongs to.
    let teamId;
    const team = request(CONTROL, 'POST', '/teams', { teamId: unique('team'), name: run }, admin, 'setup');
    if (team.status === 201) {
        teamId = team.json('$id');
    } else if (team.status === 403 && team.json('type') === 'organization_creation_prohibited') {
        const teams = request(CONTROL, 'GET', '/teams', null, admin, 'setup');
        teamId = teams.status === 200 && teams.json('total') > 0 ? teams.json('teams.0.$id') : undefined;
    }
    if (!teamId) {
        throw new Error(`team: ${team.status} ${team.body} (set APPWRITE_ADMIN_EMAIL to an organization owner)`);
    }
    const project = request(CONTROL, 'POST', '/projects', {
        projectId: unique('project'), name: run, teamId, region: __ENV._APP_REGION || 'default',
    }, admin, 'setup');
    if (project.status !== 201) {
        throw new Error(`project: ${project.status} ${project.body}`);
    }
    const projectId = project.json('$id');
    const key = request(CONTROL, 'POST', `/projects/${projectId}/keys`, {
        keyId: unique('key'), name: 'bench', scopes: ['users.read', 'users.write', 'sessions.read', 'sessions.write', 'targets.read', 'targets.write'],
    }, admin, 'setup');
    const headers = { 'Content-Type': 'application/json', 'X-Appwrite-Project': projectId, 'X-Appwrite-Key': key.json('secret') };

    // Seed users with pre-hashed (md5) passwords so setup stays fast.
    const users = [];
    for (let i = 0; i < SEED_USERS; i++) {
        const res = request(ENDPOINT, 'POST', '/users/md5', {
            userId: 'unique()', email: `seed-${i}-${run}@example.com`, password: '144fa7eaa4904e8ee120651997f70dcc', name: `Seed User ${i}`,
        }, headers, 'setup');
        if (res.status === 201) {
            users.push(res.json('$id'));
        }
    }
    return { headers, users, run };
}

function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
}

// Weighted mix modelled on server-side Users traffic: reads dominate.
export function mix(data) {
    const h = data.headers;
    const id = pick(data.users);
    const r = Math.random() * 100;
    if (r < 35) {
        expect(request(ENDPOINT, 'GET', `/users/${id}`, null, h, 'users.get'), [200], 'users.get');
    } else if (r < 55) {
        expect(request(ENDPOINT, 'GET', '/users?queries[]=' + encodeURIComponent('{"method":"limit","values":[25]}'), null, h, 'users.list'), [200], 'users.list');
    } else if (r < 63) {
        expect(request(ENDPOINT, 'GET', `/users?search=Seed+User+${Math.floor(Math.random() * 100)}`, null, h, 'users.search'), [200], 'users.search');
    } else if (r < 71) {
        expect(request(ENDPOINT, 'PATCH', `/users/${id}/prefs`, { prefs: { theme: pick(['dark', 'light']), n: Math.random() } }, h, 'users.prefs.update'), [200], 'users.prefs.update');
    } else if (r < 76) {
        expect(request(ENDPOINT, 'PATCH', `/users/${id}/name`, { name: `Seed User ${Math.floor(Math.random() * 1000)}` }, h, 'users.name.update'), [200], 'users.name.update');
    } else if (r < 81) {
        expect(request(ENDPOINT, 'GET', `/users/${id}/prefs`, null, h, 'users.prefs.get'), [200], 'users.prefs.get');
    } else if (r < 86) {
        expect(request(ENDPOINT, 'GET', `/users/${id}/targets`, null, h, 'users.targets.list'), [200], 'users.targets.list');
    } else if (r < 91) {
        const body = { userId: 'unique()', email: `${unique('new')}@example.com`, name: 'New User' };
        if (HASHING) {
            body.password = PASSWORD;
        }
        const created = expect(request(ENDPOINT, 'POST', '/users', body, h, 'users.create'), [201], 'users.create');
        if (created.status === 201) {
            expect(request(ENDPOINT, 'DELETE', `/users/${created.json('$id')}`, null, h, 'users.delete'), [204], 'users.delete');
        }
    } else if (r < 96) {
        const session = expect(request(ENDPOINT, 'POST', `/users/${id}/sessions`, null, h, 'users.sessions.create'), [201], 'users.sessions.create');
        if (session.status === 201) {
            expect(request(ENDPOINT, 'DELETE', `/users/${id}/sessions/${session.json('$id')}`, null, h, 'users.sessions.delete'), [204], 'users.sessions.delete');
        }
    } else {
        expect(request(ENDPOINT, 'POST', `/users/${id}/tokens`, null, h, 'users.tokens.create'), [201], 'users.tokens.create');
    }
}

export function handleSummary(data) {
    const metric = data.metrics.appwrite_users_duration || { values: {} };
    const reqs = data.metrics.http_reqs || { values: {} };
    const pick = (values) => ({
        count: values.count,
        avg: values.avg,
        p50: values.med,
        p90: values['p(90)'],
        p95: values['p(95)'],
        p99: values['p(99)'],
        max: values.max,
    });
    const endpoints = {};
    for (const name of ENDPOINTS) {
        const sub = data.metrics[`appwrite_users_duration{name:${name}}`];
        if (sub && sub.values.count) {
            endpoints[name] = pick(sub.values);
        }
    }
    const seconds = data.state.testRunDurationMs / 1000;
    const summary = {
        endpoint: ENDPOINT,
        mode: MODE,
        requests: reqs.values.count || 0,
        rps: reqs.values.rate || 0,
        // Workload only (setup excluded): measured calls and their rate over the scenario.
        workload: {
            requests: metric.values.count || 0,
            duration: DURATION,
            dropped: (data.metrics.dropped_iterations || { values: { count: 0 } }).values.count,
        },
        latency: {
            avg: metric.values.avg,
            p50: metric.values.med,
            p90: metric.values['p(90)'],
            p95: metric.values['p(95)'],
            p99: metric.values['p(99)'],
            max: metric.values.max,
        },
        endpoints,
        run_seconds: seconds,
        failures: (data.metrics.appwrite_users_failures || { values: { count: 0 } }).values.count,
    };
    const out = { stdout: JSON.stringify(summary, null, 2) + '\n' };
    if (__ENV.APPWRITE_BENCHMARK_SUMMARY_PATH) {
        out[__ENV.APPWRITE_BENCHMARK_SUMMARY_PATH] = JSON.stringify(summary);
    }
    return out;
}
