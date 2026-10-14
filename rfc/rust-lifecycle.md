# RFC: The request lifecycle in Rust, proven against PHP

Status: in progress (2026-10-14). `bin/compat appwrite diff` runs the identity and origin matrices for the Users routes (section 7). Part of the migration in [rust.md](rust.md).

## 1. Why this comes next

Every API request passes through the same cross-cutting logic before and after its action: the request resources (project, mode, user, session, API key, impersonation, team, allowed hostnames), the `Http::init` hooks (router, formats, CORS, origin check, authentication, scopes, abuse, MFA and session freshness, console and project gates) and the `Http::shutdown` and `Http::error` hooks (events to realtime, functions and webhooks, audits, usage, session limits, onboarding, the response cache, error rendering). `crates/appwrite/hooks.json` inventories 46 of them, from `app/controllers/general.php`, `app/controllers/shared/api.php`, `app/init/resources/request.php` and the modules' own `Init` and `Shutdown` hooks.

Rust reproduces 10 of them in full, 13 in part and 23 not at all (the docs site's Appwrite migration page lists each one with its PHP source). That was enough for Users, whose routes use a small part of the lifecycle. It is not enough for any next service:

- A difference in a service is a bug in that service. A difference in the lifecycle is a bug in **every** endpoint Rust serves: a wrong role, a missing scope check, an event published twice, a usage metric that drifts, an audit with the wrong actor. Several are security boundaries.
- The service E2E suites exercise the lifecycle only along the paths their tests happen to take. Most combinations (an expired key on a route with an `abuse-limit`, admin mode with impersonation, a request filter for an old response format on a route with an event) are never sent.

So the lifecycle gets its own migration with a stricter bar than a service: it is complete before the next service moves, and it is proven by a tool built for it.

## 2. What "done" means

A hook is done when:

1. `appwrite-core` implements it, in PHP's order, driven by the same route labels and groups (`scope`, `event`, `audits.*`, `usage.*`, `abuse-*`, `cache`, `auth.type`, `mfaProtected`, `recentSession`, `session.allowActive`, ...), so a new service gets it by declaring its labels as in PHP;
2. its **matrix** (section 4.3) runs against PHP and Rust with zero differences, outside the masks and accepted deviations the tool declares;
3. `crates/appwrite/hooks.json` marks it `done`, naming its Rust code and its matrix, which the docs site shows.

The PHP E2E suites still run through Rust (`_APP_E2E_ENDPOINT`), and keep their role as the service contract.

## 3. The Rust pipeline

`appwrite-core::platform::lifecycle` already runs resources, `authenticate`, the action, `finalize` and the shutdown jobs. It becomes the full pipeline, one module per hook group, in PHP's order:

| Stage | PHP | Rust module |
|---|---|---|
| Resources | `app/init/resources/request.php` | `platform::resources` (project, mode, user, session, key, impersonation, team, hostnames) |
| Request init | `general.php` init | `platform::request` (router, formats and request filters, locale, HTTPS, CORS, origin, certificates) |
| API init | `shared/api.php` init, `shared/api/auth.php` | `platform::auth`, `platform::abuse`, `platform::gates` (console, project, organization, webhooks, GraphQL, `projects`) |
| Action | the route | service crates |
| API shutdown | `shared/api.php` shutdown | `platform::shutdown` (events, audits, usage, session limit, abuse reset, response cache, onboarding) |
| Error | `general.php` error | `platform::error` |
| Database listeners | `resources/request.php` `on(...)` | listeners on the project `Database` (events and usage of created and deleted documents) |

Rules carried over from rust.md: Cloud extends it through `platform::Hooks`, never a fork; a hook reads labels, not route names; side effects keep PHP's wire formats so PHP workers consume them unchanged.

## 4. Parity tooling: `bin/compat appwrite diff`

The library conversions are proven by running the same call in PHP and Rust and comparing everything observable. The lifecycle gets the same treatment one level up: the same HTTP request against both runtimes, from the same state, comparing everything a client or a worker can observe.

### 4.1 A run

A **scenario** is a list of setup requests and one request under test. For each runtime in turn the tool:

1. creates a fresh project from the scenario's setup, through PHP (the control plane is PHP's in both runs, so both start from the same state);
2. sends the request under test to that runtime, directly (`:9501` or `:9530`), and records the response;
3. collects the side effects (4.2);
4. tears the project down.

It then normalizes both records (4.4) and compares them. A difference names the scenario, the hook matrix it came from, and the first differing path, as `bin/compat` does for libraries.

### 4.2 What is compared

| Observable | How it is captured |
|---|---|
| Response | status, every header (sorted; `Date`, `Server` timing masked), body as JSON or bytes |
| Database | the project's tables and the console rows that reference the project (`projects`, `keys`, `platforms`, `rules`, ...), snapshotted before and after the request; the comparison is on the **change**, row by row, through the existing `snapshot_postgres` runner |
| Queues | every message pushed to a `utopia-queue.queue.*` list, compared per queue, in order, with its full payload (events, functions, webhooks, audits, usage, deletes, mails, certificates, ...) |
| Realtime | every `PUBLISH`, with its channels, roles and payload |
| Cache and locks | every key the request purged or released (`DEL`, `UNLINK`, `HDEL`), compared as a set: purges are idempotent, so their order is not observable, but a missing one is a stale read in the other runtime |

All three come from one `MONITOR` connection, bracketed by marker commands the tool sends before and after the request, and kept only for the commands sent from the runtime's own container, so the workers can keep running (they are PHP for both runtimes: comparing the messages they receive is comparing what they do). Commands a Lua script runs are logged before the `EVAL` that ran them and are credited to that `EVAL`'s client. Work a runtime does after the response (Rust's shutdown jobs) is waited for before the closing marker.

### 4.3 The corpus

Three sources, each scenario tagged with the hooks it exercises:

1. **Hook matrices.** Generated per route from its labels, the cartesian product (pairwise where it explodes) of the dimensions a hook depends on. Every route Rust serves runs every matrix that applies to it:
   - *identity*: none; session by cookie, header or fallback cookie; JWT (valid, expired, other project); API key (standard, ephemeral, expired, missing the scope, other project, account and organization keys); console admin mode (member, non-member, missing role); impersonation by ID, email and phone; MFA pending and verified;
   - *project*: header, query, path (`/v1/projects/:projectId`), missing, unknown, console;
   - *origin*: no origin, allowed platform, unknown origin with and without a key, preflight;
   - *format*: each `X-Appwrite-Response-Format` version with a filter, none, a future one; locale;
   - *limits*: below and at the `abuse-limit`, with `_APP_OPTIONS_ABUSE` on and off, and the `abuse-reset` statuses;
   - *freshness*: an MFA challenge and a session inside and outside the recent windows;
   - *outcome*: the route's success, its validation failure, a not-found, a server error (to compare the error hook and the usage of failed requests).
2. **Recorded E2E traffic.** `bin/compat appwrite record <Suite>` runs a PHP E2E suite through a recording proxy at `_APP_E2E_ENDPOINT` and stores each test's requests as a scenario (the earlier requests of the test become its setup). This brings in the real flows: sessions created then used, keys created then revoked, a password changed then a sign-in.
3. **Fuzzed parameters.** For each route, values generated from its parameters' validators (the SDK spec has their types), so the error hook and validation messages are compared on inputs nobody wrote by hand.

### 4.4 Normalization

Generated values are aligned, not ignored: the first ID, secret, timestamp or sequence generated in the PHP run is paired with the first one in the Rust run, and every later occurrence must match that pairing (alpha-renaming). A value that appears in one run and not the other is still a difference. Timestamps are compared for format and for ordering against the request time. Masks are declared per path with a reason, in `tests/compat/appwrite/masks.json`; known PHP quirks Rust reproduces need no mask, and the few behaviours Rust deliberately does not reproduce go in `tests/compat/appwrite/deviations.json`, with the reason and the issue that fixes PHP, as for libraries.

### 4.5 Where it runs

- Locally: `bin/compat appwrite diff [--hook <id>] [--route <path>] [--suite <Suite>]` on the dev stack with the `diff` compose profile (Rust on, workers off).
- In CI: a `appwrite-diff` shard on every PR that touches `crates/appwrite`, the PHP lifecycle files or `hooks.json`, running the matrices for every route Rust serves, plus the recorded traffic of the suites of those routes.
- On the docs site: each hook shows its matrix size and its last result next to its status.

## 5. Order of work

1. **Tool first.** `appwrite diff` with the response, queue and database observables, the identity and project matrices, and the Users routes. It must find the known gaps (account keys, request filters before 1.5.0, `usage` country, preflight usage) before anything is fixed; that is its own acceptance test.
2. **Close the gaps on the routes Rust serves**: account and organization keys, request filters before 1.5.0, CORS from custom domain `rules`, usage country and query keys, disabled metrics of a key, usage of failed requests and preflights, the database listeners as listeners.
3. **The generic hooks the next services need**: abuse limits and reset, `session-active` and `session-limit`, `mfa-fresh`, `recent-session`, `auth-methods`, the console, project, organization and webhooks gates, `projects-console`, `database-timeout`, the response cache.
4. **The rest**: GraphQL switch and schema refresh, S3 hooks, certificates, mock routes.
5. Then the next service (Teams, then Account), which should need no lifecycle work at all.

Each step lands with its matrices green and `hooks.json` updated.

## 6. Risks

- **Ordering.** PHP's hook order decides which error a client sees. The matrices put several failing conditions in one request on purpose.
- **Time.** Freshness windows, key expiry and abuse windows depend on the clock; scenarios set times relative to the request and the tool runs both runtimes against the same database clock.
- **Shared state.** Both runtimes share caches and locks in production. Besides the per-runtime runs, a cross-runtime mode runs setup through one runtime and the request through the other, to catch cache entries one writes and the other misreads.
- **Cloud.** Cloud adds hooks of its own. The `Hooks` trait is the only extension point, and the matrices run with the CE implementation; Cloud runs them with its own.

## 7. First results

The first version of `appwrite diff` (identity × origin matrices over eight Users routes: 240 scenarios) started at 0 identical scenarios and found, before anything was fixed:

| Difference | Where | Fixed in Rust |
|---|---|---|
| Every error reported `version` 2.4.0, PHP 2.5.0 | `appwrite-core` `VERSION` | Bumped, and a unit test pins it to `APP_VERSION_STABLE` |
| An error raised before `general.php` sets its headers (a key for another project, admin mode on the console, an invalid JWT) carried `Server: Appwrite` and `X-Content-Type-Options`; PHP sends Swoole's `Server` header and no `nosniff` | lifecycle | `Context::secured`, set where PHP adds the headers |
| An unknown project skipped the user resource, so no `X-Debug-Fallback` headers; an invalid JWT sent them once, PHP three times (a resource that throws is resolved again by the error hook and after it) | lifecycle | User resolution runs for unknown projects; a failing user resource repeats the headers |
| Realtime missed the action-suffixed account channels (`account.update`, `account.{id}.update`) PHP added | `event::realtime_target` | Ported, with the nested-event exclusion |
| Function messages carried a hand-picked subset of the project and `platform: []`; PHP sends the whole project document and `app/config/platform.php` | `Project::message_payload`, `Config::platform` | The project keeps its document; the platform config is built once |
| Usage messages had an empty `country` | shutdown | The IP's geo lookup, after the response |
| `network.inbound` counted the request body; PHP's `getSize()` reads `php://input`, which Swoole leaves empty | shutdown | Kept PHP's count, flagged as a PHP bug to fix in both |
| The `encrypt` envelope escaped `/` (PHP writes it with `JSON_UNESCAPED_SLASHES`) | `crypto::encrypt` | `serde_json`, with a regression test |
| Argon2 salts were 16 random bytes; PHP's `password_hash()` uses 16 characters of `./0-9A-Za-z` | `utopia-auth` | PHP's salt generation |

| A preflight lacked PHP's `Content-Type: text/html` and the user resource's `X-Debug-Fallback` headers | lifecycle | Added |
| No request filters: a 1.4 client's query strings reached the validators unconverted | `appwrite_core::request` | V17 and V27, V17 proven on 405 queries PHP's own filter converted |
| An action's explicit event payload followed the client's response format; PHP renders it before the format filters | `response::current` | Explicit payloads render with the latest format |

A variants dimension (preflight, an older and a newer response format, a locale) brought the matrix to 280 scenarios.

It also needed declared masks: the development error fields (`file`, `line`, `trace`), `Content-Length`, `Date`, `X-Debug-Speed` and the `network.outbound` bytes it feeds, and the twins' generated values (IDs, sequences, secrets, salts, IVs, timestamps), which are aligned rather than ignored. All 280 scenarios are now identical, in dedicated and shared tables.
