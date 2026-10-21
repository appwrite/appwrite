# Preventative security E2E

Dynamic counterpart to the Semgrep rules in [`.semgrep/`](../../../.semgrep). Those checks read source; this suite sends real HTTP requests at a running stack.

It does **not** replace bug-specific regressions. Known reports still get a hand-written test. This suite is the net that a new or changed route falls into automatically.

## What it covers

`Catalog` boots the same HTTP registrar the specs task uses (`app/controllers/general.php` + `Http::getRoutes()`) and keeps every `/v1` route except GraphQL and mocks. Aliases collapse onto the primary path.

Each route is offered to every class in [`Attack/`](Attack/). A class that does not apply returns immediately. Adding a class is one file in that directory — `Attacks` loads the folder.

| Class | Probe | Safe outcome |
| --- | --- | --- |
| `guest-access` | No session or key | 401/403 unless the route scope intersects the guest role |
| `cross-tenant` | Project A key (and session, when the route is not guest-callable) on project B | 401/403 |
| `idor` | User B session + user A's path IDs | 401/403/404, or 2xx that does not contain A's identifiers |
| `scope-least-privilege` | Key that holds only `locale.read` (plus the built-in key scopes) | 401/403 unless the route's declared scope is in that set |
| `console-role` | Developer session on scopes their role does not include; plus owner-only membership mutations | 401/403 |
| `header-spoof` | Reserved `x-appwrite-*` identity headers, mixed case, `X-Forwarded-For` | Must not authenticate; IP reflection is recorded when it happens |
| `ssrf-url` | Loopback / link-local / metadata URLs on URL-typed params | 400/401/403/404. Timeouts and 2xx on fetch endpoints (avatars, health certificate, migrations) are findings. Stored-only URLs that accept a value are not, because create does not connect. |
| `sensitive-fields` | GET as a normal user | `secret` / `password` / execution streams must be empty or absent |

## Run locally

```bash
docker compose up -d --wait
docker compose exec appwrite test tests/e2e/Security --group=security
```

Framework unit tests (no stack):

```bash
docker compose exec appwrite test tests/unit/Security
```

## Baseline

[`baseline.json`](baseline.json) is the allowlist. CI fails on:

1. A finding whose `attack + method + path + probe` key is not listed.
2. A listed finding whose `reason` is empty.
3. A new route whose `METHOD /v1/...` id is not in `routes`. The inventory is locked; an empty `routes` list is bootstrap-only and does not lock.

Stale entries (gone routes or fixed findings) are printed and do not fail the job.

## Adding a route to the inventory

When you add an HTTP endpoint, `Catalog` picks it up from `Http::getRoutes()` on the next run. CI fails until that `METHOD /path` id is in [`baseline.json`](baseline.json) `routes`.

1. Land the action (and any `httpAlias`; aliases collapse onto the primary `getPath()`).
2. Run the suite so the new surface is probed:

```bash
docker compose exec appwrite test tests/e2e/Security --group=security
```

3. Review any fresh findings. Then rewrite the allowlist and the route list together:

```bash
docker compose exec -e _APP_SECURITY_BASELINE=update \
  appwrite test tests/e2e/Security --group=security
```

Local compose mounts `./tests` via `docker-compose.override.yml`, so that write lands in the repo. Without a tests bind-mount (CI image, or compose without the override), the same command also writes `/storage/cache/security-baseline.json` — copy it out:

```bash
docker compose cp appwrite:/storage/cache/security-baseline.json tests/e2e/Security/baseline.json
```

Update refuses to write if `World::boot()` failed or if any attack class did not run (`--filter` is not a full update). A completed update replaces the findings list: rows that did not fire are dropped so a later recurrence is not still allowlisted. Reasons on findings that still fire are kept.

4. Open the `baseline.json` diff. Keep the new `routes` row. New finding rows come through with an empty `reason` — fill one in or the next `check` run fails. Commit the inventory change with the endpoint.

Do not hand-edit a route id unless you are matching `Catalog::ids()` (`METHOD` + primary path). `httpAlias` paths are not listed separately.

## Adding an attack class

1. Create `tests/e2e/Security/Attack/YourClass.php` implementing `Tests\E2E\Security\Attack`.
2. `getName()` is the stable baseline id (`kebab-case`).
3. `applies()` must be cheap and conservative. `probe()` returns `Finding` objects, not PHPUnit assertions.
4. Drop the file in the folder. `Attacks` discovers it.
5. Run the suite. Allowlist only with a reason.

## CI

Job `Tests / E2E / Security` in `.github/workflows/ci.yml`. One stack (default PostgreSQL, dedicated tables), sequential PHPUnit. Attack classes share one fixture world (one console organization, two projects, two users, a least-privilege key, a console developer). Self-hosted allows only one organization, so both projects share that team. ParaTest would rebuild that world per process and cost more than it saves; the request loop is the cheap part.

## Trade-offs

- Path parameters the world does not seed are filled with a placeholder. 404 is treated as isolation, not a bypass.
- SSRF does not flag a 2xx that merely *stores* a URL. Fetching endpoints and connect timeouts still fail.
- GraphQL is one fan-out route and is skipped; inner operations are the REST routes already in the catalog.
- Forwarded-IP spoofing from inside the compose network often hits `_APP_TRUSTED_PROXIES` defaults (loopback / RFC1918). That result is a finding so it cannot appear silently; expect to reason it in the baseline if the topology trusts the caller.
- `idor` only treats `userId` / `email` fields as a leak. A caller-chosen path `$id` echoing back (presence upsert) is not.
- First scan: guest-access, cross-tenant, scope-least-privilege, and ssrf-url were clean. See `baseline.json` for the remaining allowlisted rows. The locked `routes` list is the live catalog size at lock time.
