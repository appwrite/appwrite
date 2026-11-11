# API benchmarks

Every Appwrite endpoint has a benchmark scenario: one k6 script per service
(`<service>.js`, named after the service id the docs site uses) runs a weighted
mix of a scenario per endpoint against the PHP API or the Rust API, so both can
be compared endpoint by endpoint on latency, and per service on throughput, CPU
time per request and memory.

```bash
tests/benchmarks/api/compare.sh users                     # PHP and Rust, the default load ladder
TARGETS=php tests/benchmarks/api/compare.sh teams         # PHP only, while Rust does not serve the service
RESULTS=tests/benchmarks/results/users.json tests/benchmarks/api/compare.sh users
```

`compare.sh` needs the dev stack with the Rust API
(`COMPOSE_PROFILES=postgresql,rust docker compose up -d --wait`), k6 on `PATH`,
and, once the instance has an organization, `APPWRITE_ADMIN_EMAIL` set to an
owner of it (password `benchmark-password`). Committed as
`tests/benchmarks/results/<service>.json`, a run is what `bin/compat appwrite`
publishes on the docs site.

To try a script, or a few of its scenarios, at low load:

```bash
APPWRITE_ADMIN_EMAIL=... APPWRITE_BENCHMARK_SEED=40 APPWRITE_BENCHMARK_RATE=20 APPWRITE_BENCHMARK_DURATION=15s \
  APPWRITE_BENCHMARK_ONLY=users.get,users.list k6 run tests/benchmarks/api/users.js
```

A run fails (k6 exits non-zero, `failures` > 0) when any request answers with a
status its scenario does not expect: the scenario is wrong. The log names the
scenario, the request and the response. A server error (5xx, or no response)
is not a failure but an `error`: what the API does under that load. It ends
the scenario's iteration, is counted per step and per endpoint, and is
published with the results; the run fails only when errors pass
`APPWRITE_BENCHMARK_ERROR_BUDGET` (1% of requests by default), so a broken
stack is never published.

## Writing a service's script

```js
import { define, pick, unique } from './lib.js';

const bench = define({
    service: 'teams',
    scopes: ['teams.read', 'teams.write'],
    seed(api, context) {           // once, unmeasured: what the scenarios act on
        const teams = [];
        for (let i = 0; i < 50; i++) {
            teams.push(api.prep('POST', '/teams', { teamId: 'unique()', name: `Team ${i}` }, [201]).json('$id'));
        }
        return { teams };
    },
    scenarios: [
        { name: 'teams.get', route: 'GET /v1/teams/:teamId', weight: 10, run: (api, d) => api.call('GET', `/teams/${pick(d.teams)}`) },
        {
            name: 'teams.delete', route: 'DELETE /v1/teams/:teamId',
            run(api) {
                const id = api.prep('POST', '/teams', { teamId: 'unique()', name: 'Gone' }, [201]).json('$id');
                api.call('DELETE', `/teams/${id}`, null, [204]);
            },
        },
    ],
    skipped: [
        { skipped: 'POST /v1/teams/:teamId/example', reason: 'calls a production third-party service' },
    ],
});

export const options = bench.options;
export const setup = bench.setup;
export const mix = bench.mix;
export const handleSummary = bench.handleSummary;
```

- **One scenario per endpoint**, at least. `route` is the endpoint exactly as
  the API declares it (method and path with `:params`, as on the docs site),
  written as a single-quoted literal: `bin/compat appwrite` reads the routes
  from the script. A route can have several scenarios (a list and a search).
- **`api.call()`** is the one measured request of a scenario, with the statuses
  it must answer (`[200]` by default). **`api.prep()`** is any other request to
  the API under test (state to act on, cleanup); **`api.control()`** sends one
  to another service through the PHP API, which serves them all (the Rust API
  serves only migrated services). `api.form(method, path, fields, codes, { headers, measure })`
  sends a multipart request (uploads: `fields` holds `http.file(...)` values).
  `api.request(method, url, body, codes, params)` is a measured request to any
  URL with k6 params as given (routes outside /v1 via `ROOT`, signed requests,
  `redirects: 0` to measure a redirect itself).
  `api.fail(label, message)` records a failed check that is not a status
  (a rejected body, a missing mail).
  `api.session(secret)` gives the headers of a
  client request with a session; pass headers as the last argument of any call.
  `context.admin` (in `seed`) holds the console session's headers, for console
  routes: return it in the seed data to use it in scenarios.
- **Leave the data as you found it** where a scenario creates: delete what it
  made in a `prep` after the measured call, so a long run measures the same
  database throughout.
- **Measure the success path.** When the state an endpoint acts on cannot be
  created from a test (a WebAuthn passkey, an OAuth2 identity), measure its
  not-found path and say so in `note` (shown on the site). `note` is also for
  anything else a reader needs to read the number (`hashes the password with
  Argon2`).
- **`skipped`** only for an endpoint a benchmark cannot run without calling a
  production third-party service, with the reason (a single-quoted literal too).
  Local services of the dev stack (the executor, mail catcher, MinIO) are fine.
- **Weights** model the service's traffic (reads dominate); every scenario
  runs often enough to measure (weight 1 is about 1% of a mix of 50 scenarios).

`cargo test -p compat --test benchmarks` (run in CI) fails when a service has
no script, when an endpoint has no scenario and is not skipped with a reason,
or when a scenario's route is not an endpoint of its service. A new endpoint
needs a scenario in the same change.

Shared code for several scripts goes in `support/`: every `.js` file directly
in this directory is a service's script.
