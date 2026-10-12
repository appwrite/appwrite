// The API benchmark harness: one k6 script per service (tests/benchmarks/api/<service>.js)
// declares a scenario for every endpoint of the service, and this file turns it
// into a k6 test that runs the same weighted mix against the PHP or the Rust API.
// See tests/benchmarks/api/README.md.
//
//   APPWRITE_ENDPOINT          API under test (PHP: http://localhost:9501/v1, Rust: http://localhost:9530/v1)
//   APPWRITE_CONTROL_ENDPOINT  PHP API used for console setup (default http://localhost:9501/v1)
//   APPWRITE_ADMIN_EMAIL       console account (password "benchmark-password") that owns the
//                              instance's organization; required once one exists (self-hosted allows one)
//   APPWRITE_BENCHMARK_MODE    "rate" (fixed arrival rate, default) or "vus" (closed loop, max throughput)
//   APPWRITE_BENCHMARK_RATE    iterations/s in "rate" mode (default 100)
//   APPWRITE_BENCHMARK_VUS     virtual users (default 50)
//   APPWRITE_BENCHMARK_DURATION  e.g. "60s" (default)
//   APPWRITE_BENCHMARK_SEED    records seeded during setup (default 200)
//   APPWRITE_BENCHMARK_HASHING "true" to hash plaintext passwords where a scenario offers it
//   APPWRITE_BENCHMARK_ONLY    comma-separated scenario names to run alone (smoke tests)
//   APPWRITE_BENCHMARK_ERROR_BUDGET  share of requests that may fail with a server error
//                              (5xx or no response) before the run fails (default 0.01)
//   APPWRITE_BENCHMARK_SUMMARY_PATH  JSON summary output

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

export const ENDPOINT = __ENV.APPWRITE_ENDPOINT || 'http://localhost:9501/v1';
export const CONTROL = __ENV.APPWRITE_CONTROL_ENDPOINT || 'http://localhost:9501/v1';
/** The API's host, for routes outside /v1 (`/versions`, `/robots.txt`, console redirects). */
export const ROOT = ENDPOINT.endsWith('/v1') ? ENDPOINT.slice(0, -3) : ENDPOINT;
export const HASHING = __ENV.APPWRITE_BENCHMARK_HASHING === 'true';
export const SEED = parseInt(__ENV.APPWRITE_BENCHMARK_SEED || '200', 10);
export const PASSWORD = 'benchmark-password';

const MODE = __ENV.APPWRITE_BENCHMARK_MODE || 'rate';
const RATE = parseInt(__ENV.APPWRITE_BENCHMARK_RATE || '100', 10);
const VUS = parseInt(__ENV.APPWRITE_BENCHMARK_VUS || '50', 10);
const DURATION = __ENV.APPWRITE_BENCHMARK_DURATION || '60s';
const ONLY = (__ENV.APPWRITE_BENCHMARK_ONLY || '').split(',').filter(Boolean);

const duration = new Trend('appwrite_duration', true);
const failures = new Counter('appwrite_failures');
// Server errors (5xx, or no response in time): what an API does under load,
// published with the results; a run fails only past the budget.
const errors = new Counter('appwrite_errors');
const errorRate = new Rate('appwrite_error_rate');
const ERROR_BUDGET = parseFloat(__ENV.APPWRITE_BENCHMARK_ERROR_BUDGET || '0.01');

/** Ends a scenario's iteration after a server error: what follows needs that request's result. */
export class Interrupted extends Error {}

/** A uniform number in [0, 1) from the platform's CSPRNG (ids, emails and secrets derive from it). */
export function random() {
    // 52 random bits as the mantissa of a double in [1, 2), minus 1: uniform,
    // with no division or modulo to bias it. Bytes, because k6 fills wider
    // typed arrays with one byte per element.
    const bits = crypto.getRandomValues(new Uint8Array(7));
    const view = new DataView(new ArrayBuffer(8));
    view.setUint8(0, 0x3f);
    view.setUint8(1, 0xf0 | (bits[0] & 0x0f));
    for (let i = 1; i < 7; i++) {
        view.setUint8(i + 1, bits[i]);
    }
    return view.getFloat64(0) - 1;
}

export function unique(prefix) {
    const random = Array.from(crypto.getRandomValues(new Uint8Array(4)), (b) => b.toString(16).padStart(2, '0')).join('');
    return `${prefix}-${Date.now().toString(36)}-${random}`.slice(0, 36);
}

export function pick(list) {
    return list[Math.floor(random() * list.length)];
}

function send(base, method, path, body, headers, tag) {
    return http.request(method, `${base}${path}`, body === null || body === undefined ? null : JSON.stringify(body), {
        headers,
        tags: { name: tag },
    });
}

/**
 * What a scenario calls the API with. `call()` is the measured request of the
 * scenario's endpoint; `prep()` is any other request the scenario needs (state
 * to act on, cleanup). Both go to the API under test, and both fail the run
 * when the status is not one of `codes`.
 */
class Api {
    constructor(name, headers) {
        this.name = name;
        this.headers = headers;
    }

    call(method, path, body = null, codes = [200], headers = this.headers) {
        const res = send(ENDPOINT, method, path, body, headers, this.name);
        duration.add(res.timings.duration, { name: this.name });
        return this.expect(res, codes, this.name);
    }

    /**
     * A measured multipart request (file uploads): `fields` is sent as k6
     * encodes an object holding `http.file()` values, without the JSON
     * content type. `measure: false` makes it a `prep` instead.
     */
    form(method, path, fields, codes = [201], { headers = this.headers, measure = true } = {}) {
        const plain = Object.fromEntries(Object.entries(headers).filter(([k]) => k.toLowerCase() !== 'content-type'));
        const res = http.request(method, `${ENDPOINT}${path}`, fields, { headers: plain, tags: { name: measure ? this.name : 'prep' } });
        if (measure) {
            duration.add(res.timings.duration, { name: this.name });
        }
        return this.expect(res, codes, measure ? this.name : `${this.name} prep ${method} ${path}`);
    }

    /**
     * A request `call()` cannot express: any `url` (routes outside /v1, signed
     * S3 requests), the body as given, and k6 `params` as given (`headers`,
     * `redirects: 0` to measure a redirect itself). Measured unless
     * `measure: false`.
     */
    request(method, url, body, codes = [200], { measure = true, ...params } = {}) {
        const res = http.request(method, url, body, { ...params, tags: { name: measure ? this.name : 'prep' } });
        if (measure) {
            duration.add(res.timings.duration, { name: this.name });
        }
        return this.expect(res, codes, measure ? this.name : `${this.name} prep ${method} ${url}`);
    }

    prep(method, path, body = null, codes = [200, 201, 204], headers = this.headers) {
        return this.expect(send(ENDPOINT, method, path, body, headers, 'prep'), codes, `${this.name} prep ${method} ${path}`);
    }

    /** A request to another service, through the PHP API (APPWRITE_CONTROL_ENDPOINT), which serves them all. */
    control(method, path, body = null, codes = [200, 201, 204], headers = this.headers) {
        return this.expect(send(CONTROL, method, path, body, headers, 'prep'), codes, `${this.name} control ${method} ${path}`);
    }

    /**
     * Checks the status. One the scenario does not expect is a failure (the
     * scenario is wrong, and the run fails), unless it is a server error (5xx
     * or no response): that is counted as an error and ends the iteration.
     */
    expect(res, codes, label) {
        const ok = check(res, { [`${label} ${codes.join('|')}`]: (r) => codes.includes(r.status) });
        const server = !ok && (res.status === 0 || res.status >= 500);
        errorRate.add(server);
        if (server) {
            errors.add(1, { name: this.name });
            throw new Interrupted(`${label}: ${res.status}`);
        }
        if (!ok) {
            failures.add(1, { name: this.name });
            console.warn(`${label}: ${res.status} ${String(res.body).slice(0, 300)}`);
        }
        return res;
    }

    /** Headers of a client (end user) request with the session's secret. */
    session(secret) {
        return { 'Content-Type': 'application/json', 'X-Appwrite-Project': this.headers['X-Appwrite-Project'], 'X-Appwrite-Session': secret };
    }
}

/** A console session, an organization, a project and an API key with `scopes`, through the PHP API. */
function project(scopes) {
    const run = unique('bench');
    const email = __ENV.APPWRITE_ADMIN_EMAIL || `${run}@example.com`;
    const console = { 'Content-Type': 'application/json', 'X-Appwrite-Project': 'console' };
    const account = send(CONTROL, 'POST', '/account', { userId: unique('admin'), email, password: PASSWORD, name: 'Bench' }, console, 'setup');
    if (![201, 409].includes(account.status)) {
        throw new Error(`account: ${account.status} ${account.body}`);
    }
    const session = send(CONTROL, 'POST', '/account/sessions/email', { email, password: PASSWORD }, console, 'setup');
    const cookie = session.headers['Set-Cookie'] || session.headers['set-cookie'] || '';
    const admin = { ...console, Cookie: cookie };
    // Self-hosted allows one organization per instance: once it exists, use the
    // one APPWRITE_ADMIN_EMAIL belongs to.
    let teamId;
    const team = send(CONTROL, 'POST', '/teams', { teamId: unique('team'), name: run }, admin, 'setup');
    if (team.status === 201) {
        teamId = team.json('$id');
    } else if (team.status === 403 && team.json('type') === 'organization_creation_prohibited') {
        const teams = send(CONTROL, 'GET', '/teams', null, admin, 'setup');
        teamId = teams.status === 200 && teams.json('total') > 0 ? teams.json('teams.0.$id') : undefined;
    }
    if (!teamId) {
        throw new Error(`team: ${team.status} ${team.body} (set APPWRITE_ADMIN_EMAIL to an organization owner)`);
    }
    const created = send(CONTROL, 'POST', '/projects', {
        projectId: unique('project'), name: run, teamId, region: __ENV._APP_REGION || 'default',
    }, admin, 'setup');
    if (created.status !== 201) {
        throw new Error(`project: ${created.status} ${created.body}`);
    }
    const projectId = created.json('$id');
    const key = send(CONTROL, 'POST', `/projects/${projectId}/keys`, { keyId: unique('key'), name: 'bench', scopes }, admin, 'setup');
    if (key.status !== 201) {
        throw new Error(`key: ${key.status} ${key.body}`);
    }
    return {
        run,
        projectId,
        admin,
        headers: { 'Content-Type': 'application/json', 'X-Appwrite-Project': projectId, 'X-Appwrite-Key': key.json('secret') },
    };
}

/**
 * A service's benchmark: `define({ service, scopes, seed, scenarios, skipped })`.
 *
 * - `scopes`: the API key's scopes.
 * - `seed(api, context)`: runs once, unmeasured; returns the data scenarios use.
 * - `scenarios`: one per endpoint, `{ name, route, weight, note, run(api, data) }`.
 *   `route` is the endpoint as the API declares it (`GET /v1/users/:userId`);
 *   `note` says how the scenario differs from the endpoint's common use (for
 *   example, measured on its not-found path).
 * - `skipped`: `{ skipped: route, reason }` for an endpoint that cannot be
 *   benchmarked (it would call a production third-party service).
 */
export function define(spec) {
    const all = spec.scenarios.filter((s) => !ONLY.length || ONLY.includes(s.name));
    if (!all.length) {
        throw new Error(`no scenarios match APPWRITE_BENCHMARK_ONLY=${ONLY.join(',')}`);
    }
    const total = all.reduce((sum, s) => sum + (s.weight ?? 1), 0);
    const names = all.map((s) => s.name);
    const executor = MODE === 'vus'
        ? { executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'mix' }
        : { executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: DURATION, preAllocatedVUs: VUS, maxVUs: VUS * 4, exec: 'mix' };
    return {
        options: {
            discardResponseBodies: false,
            setupTimeout: '10m',
            summaryTrendStats: ['count', 'avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
            scenarios: { [spec.service]: executor },
            thresholds: {
                appwrite_failures: ['count<1'],
                appwrite_error_rate: [`rate<=${ERROR_BUDGET}`],
                // No-op thresholds so k6 keeps a submetric per scenario for the summary.
                ...Object.fromEntries(names.map((name) => [`appwrite_duration{name:${name}}`, ['max>=0']])),
                ...Object.fromEntries(names.map((name) => [`appwrite_errors{name:${name}}`, ['count>=0']])),
            },
        },
        setup() {
            const context = project(spec.scopes);
            const data = spec.seed ? spec.seed(new Api('setup', context.headers), context) : {};
            return { headers: context.headers, run: context.run, data };
        },
        mix(state) {
            let r = random() * total;
            const scenario = all.find((s) => (r -= s.weight ?? 1) < 0) || all[all.length - 1];
            const pass = (e) => {
                if (!(e instanceof Interrupted)) {
                    throw e;
                }
            };
            try {
                const pending = scenario.run(new Api(scenario.name, state.headers), state.data, state.run);
                if (pending && typeof pending.then === 'function') {
                    return pending.catch(pass);
                }
            } catch (e) {
                pass(e);
            }
        },
        handleSummary(data) {
            return summary(spec.service, all, data);
        },
    };
}

function summary(service, scenarios, data) {
    const metric = data.metrics.appwrite_duration || { values: {} };
    const reqs = data.metrics.http_reqs || { values: {} };
    const stats = (values) => ({
        count: values.count,
        avg: values.avg,
        p50: values.med,
        p90: values['p(90)'],
        p95: values['p(95)'],
        p99: values['p(99)'],
        max: values.max,
    });
    const endpoints = {};
    for (const s of scenarios) {
        const sub = data.metrics[`appwrite_duration{name:${s.name}}`];
        if (sub && sub.values.count) {
            const failed = data.metrics[`appwrite_errors{name:${s.name}}`];
            endpoints[s.name] = {
                route: s.route,
                ...(s.note ? { note: s.note } : {}),
                ...stats(sub.values),
                ...(failed && failed.values.count ? { errors: failed.values.count } : {}),
            };
        }
    }
    const out = {
        service,
        endpoint: ENDPOINT,
        mode: MODE,
        requests: reqs.values.count || 0,
        rps: reqs.values.rate || 0,
        // Workload only (setup excluded): measured calls over the scenario.
        workload: {
            requests: metric.values.count || 0,
            duration: DURATION,
            dropped: (data.metrics.dropped_iterations || { values: { count: 0 } }).values.count,
        },
        latency: stats(metric.values),
        endpoints,
        run_seconds: data.state.testRunDurationMs / 1000,
        failures: (data.metrics.appwrite_failures || { values: { count: 0 } }).values.count,
        errors: (data.metrics.appwrite_errors || { values: { count: 0 } }).values.count,
    };
    delete out.latency.count;
    const files = { stdout: JSON.stringify(out, null, 2) + '\n' };
    if (__ENV.APPWRITE_BENCHMARK_SUMMARY_PATH) {
        files[__ENV.APPWRITE_BENCHMARK_SUMMARY_PATH] = JSON.stringify(out);
    }
    return files;
}
