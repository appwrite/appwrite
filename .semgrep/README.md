# Security rules

In-repo Semgrep rules for Appwrite PHP coding standards: route declarations (scope, `api` group, abuse limits), authorization skips and global disables, credential responses and sensitive output, guest-reachable input validation (URL, redirect, map params), outbound client settings, client IP and forwarded headers, nested document permissions, secrets and randomness, and process / filesystem / SQL / XML / dynamic-code use in request-serving code.

These run in CI (`Checks / Rules` in `.github/workflows/ci.yml`, and the scheduled `Scan Rules` job in `.github/workflows/security-scan.yml`). They do not replace PHPStan or Trivy.

CI fails on **new ERROR** findings only. New WARNING findings are review signal: they are posted as a single sticky PR comment (`<!-- semgrep-rules-comment -->`) that is updated in place on each run. Findings already recorded in [`baseline.json`](baseline.json) neither fail the job nor appear in detail on the comment. Every finding, baselined or not, is still written to SARIF.

Rules are written as classes with allowlists, not per-file lists. When a class would flood the current tree it ships as WARNING with the existing sites in the baseline, rather than path-excluding the offending files.

## Run locally

Install Semgrep (pinned in CI to `1.179.0`):

```bash
pipx install semgrep==1.179.0
# or: python3 -m pip install --user semgrep==1.179.0
```

Validate fixtures and the known-bad / known-ok scratch proofs:

```bash
semgrep test .semgrep
bash .semgrep/prove.sh
```

CI-equivalent scan and baseline check (exits 1 on any ERROR not in the baseline, and lists every new finding):

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --exclude .semgrep \
  --json \
  --output semgrep.json \
  src/Appwrite app/controllers app/init
node .semgrep/baseline.js check semgrep.json
```

Human-readable output for every finding, ignoring the baseline:

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --exclude .semgrep \
  src/Appwrite app/controllers app/init
```

## Baseline

[`baseline.json`](baseline.json) records the findings on the current tree so that adding or tightening a rule does not fail unrelated PRs. Semgrep OSS only offers a commit-diff baseline (`--baseline-commit`), so `baseline.js` filters the JSON output itself.

Each entry is keyed on rule id, file path, and the matched source text with whitespace collapsed (extended to the next lines when the first line is short, such as a bare `$this`). When the match spans a module route chain, the `->setHttpPath(…)` line is appended, so the key names the route by method and path and a different route in the same file does not inherit its entry. The `line` field is informational. Edits elsewhere in the file that shift lines keep the entry matched, and identical lines in one file need one entry each. Editing the matched code itself makes it a new finding.

`node .semgrep/baseline.js check semgrep.json` prints the total, how many are baselined, and the new ERROR / WARNING counts. It also lists baseline entries that no longer match, which is fine to leave until the next regeneration. Only new ERRORs fail the job.

Regenerate after fixing baselined sites, or when a new rule or an intentional known site should be accepted:

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --exclude .semgrep \
  --json \
  --output semgrep.json \
  src/Appwrite app/controllers app/init
node .semgrep/baseline.js update semgrep.json
```

Review the `baseline.json` diff like code: an added ERROR entry should come with a reason in the PR. Fixing a baselined site and dropping its entry is always welcome.

## Writing rules

- Function-call patterns need both spellings (`unserialize(...)` and `\unserialize(...)`); Semgrep does not treat them as the same call, and this codebase uses the leading backslash.
- For route-level conditions, match the whole chain (`$ROUTE->callback(...)` / `$ROUTE->action(...)`) and put positive conditions in `metavariable-regex` on `$ROUTE`. A top-level `pattern-regex` narrows the finding to the regex match, after which `pattern-not-regex` can no longer see the rest of the chain.

## Trigger matrix

| Pattern | Result |
| --- | --- |
| Ungated `$auth->skip` / `Authorization::skip` + `getDocument`/`find`/`findOne` of a non-allowlisted name (`files`, `transactions`, or any new name) | **ERROR** `skip-ungated-load` |
| Same skip gated on API-key / privileged callers (ternary or `if`), of allowlisted metadata / lookup / subquery / video-child collections, of a variable collection, or a `videos` skip followed by `assertFileAccess` | ok |
| `$authorization->disable()` / `setDefaultStatus(false)` in a module or `app/controllers/api` handler | **ERROR** `global-authorization-disable` |
| `/v1` route (module or `Http::*`) with no `->label('scope', …)` | **ERROR** `route-without-scope` |
| `/v1` route with a non-public scope and no `api` (or `graphql`) group | **ERROR** `route-without-api-group` |
| Public-scope forwarders, `mock`, `graphql`, non-`/v1` paths | ok |
| `Key` / `?Key` closure or `action()` (any position) returns `MODEL_TOKEN` / `MODEL_SESSION` / `MODEL_JWT` with no preceding `users.write` check | **ERROR** `account-token-secret-scope-gate` |
| Same output inside `$queueForEvents->setPayload(…)`, or after the `users.write` check | ok |
| `showSensitive()` outside `->setPayload(…)` | **ERROR** `show-sensitive-outside-payload` |
| Related-document `$permissions` (`$rel*`, `$nested*`, `$child*`, `$peer*`, `$linked*`) without `validateRelatedPermissions()` | **ERROR** `related-permissions-helper` |
| ROLE_GUESTS-scoped URL-like param (`url`, `endpoint`, `webhook`, `*Url`, …) with bare `URL()` / `Text()` | **ERROR** `guest-url-without-publicurl` |
| Same param with `PublicURL` / `PublicDomain` / injected `redirectValidator`, or a privileged scope | ok |
| `success` / `failure` / `redirect` / `returnUrl` / `next` param with bare `URL()` / `Text()` on any route | **ERROR** `redirect-param-without-validator` |
| ROLE_GUESTS-scoped `Assoc` param of any name, in a class with no `ALLOWED_*` constant | **ERROR** `unbounded-map-to-outbound` |
| Same param in a class declaring `ALLOWED_HEADERS` / `ALLOWED_QUERY` / `ALLOWED_COOKIES` / `ALLOWED_OPTIONS` / `ALLOWED_KEYS` | ok |
| `BLOCKED_*` / `DENIED_*` / `DISALLOWED_*` / `FORBIDDEN_*` header or host list | **ERROR** `header-blocklist-filter` |
| Guest-scoped POST/PUT/PATCH/DELETE with no `abuse-limit` (mock and `/v1/vcs/*/events` allowlisted) | **WARNING** `guest-write-without-abuse-limit` |
| Any read of `X-Forwarded-For`, `X-Real-IP`, `Forwarded`, `CF-Connecting-IP`, `True-Client-IP`, `X-Forwarded-Host` / `-Proto`, … (`getHeader`, `getHeaderLine`, `getHeaders()[…]`, `getServer`, Swoole `header[…]`) | **ERROR** `client-ip-header` |
| `Request::getIP()`, unrelated `x-*` headers | ok |
| Placeholder secret literal (`your-secret-key`, `your-api-key`, `changeme`, `insecure-secret`, …) outside Doctor, or another class's `PLACEHOLDER` as a `getEnv` default | **ERROR** `default-secret-placeholder` |
| `const PLACEHOLDER = '<placeholder>'`, comparing against it (`===` / `!==`), naming it in `Console::*` / `throw new …` | ok |
| `getEnv('*SECRET*' / '*KEY*' / '*PASS*' / '*TOKEN*', '<non-empty>')` (DSN / URL / host / TTL names excluded) | **WARNING** `weak-secret-env-default` |
| `rand` / `mt_rand` / `lcg_value` / `str_shuffle` / seeding, or `md5` / `sha1` / `hash` of `uniqid` / `time` / `microtime` | **ERROR** `insecure-random` |
| `random_int`, `random_bytes`, `array_rand` for shard selection, `md5($content)` | ok |
| Secret / OTP / signature / `code` compared with `==` / `===` / `!=` / `!==` / `strcmp` (not against null, bool, number, literal, or class constant) | **WARNING** `secret-compare-timing` |
| `addCookie(…, httponly: false)` (positional or named) or native `setcookie` | **ERROR** `insecure-cookie-flags` |
| `CURLOPT_SSL_VERIFYPEER` / `VERIFYHOST` off, stream `verify_peer(_name) => false`, `allow_self_signed => true` | **ERROR** `tls-verification-disabled` |
| `CURLOPT_FOLLOWLOCATION` on, `setMaxRedirects(n > 0)` | **WARNING** `outbound-follow-redirects` |
| `eval`, `create_function`, `assert('string')`, `extract`, one-arg `parse_str`, `unserialize` without `allowed_classes => false` | **ERROR** `unsafe-dynamic-code` |
| `exec` / `shell_exec` / `system` / `passthru` / `popen` / `proc_open` / `pcntl_exec` / backticks in modules, controllers, init | **ERROR** `shell-exec-in-handler` |
| `$_GET` / `$_POST` / `$_REQUEST` / `$_COOKIE` / `$_FILES` / `$_SERVER` / `$_ENV` in modules, controllers, init | **ERROR** `superglobal-in-handler` |
| `var_dump`, `print_r` / `var_export` without return, `phpinfo`, native `header()` in modules, controllers, init | **ERROR** `debug-output-in-handler` |
| SQL keyword string with concatenation / interpolation / `sprintf` passed to `query` / `exec` / `prepare` | **ERROR** `raw-sql-interpolation` |
| `LIBXML_NOENT` / `LIBXML_DTDLOAD` / `LIBXML_DTDATTR`, `libxml_disable_entity_loader(false)` | **ERROR** `xml-external-entities` |
| Taint: `$request->getParam/getQuery/getHeader/getCookie/getPayload/getURI` or a `string` action / route-closure param reaching `include` / `require` / `file_get_contents` / `fopen` / `unlink` / `readfile` / `rename` / `copy` / … without `basename` / `ID::custom`. `realpath()` alone stays tainted; the result is clean only after `if (!str_starts_with($real, $base)) { throw / return }` (or the `substr` + `strlen` form), or inside `if (str_starts_with($real, $base)) { … }` | **ERROR** `request-path-to-filesystem` |
| `Permission::write/update/delete/create(Role::any() / Role::guests())` (migration history and mock excluded) | **WARNING** `permissive-write-permission` |

## Rules

| ID | Severity | What it flags |
| --- | --- | --- |
| `php.appwrite.skip-ungated-load` | ERROR | Ungated skip + named-collection load outside the allowlist (video children / profiles and play-path `assertFileAccess` exempt). |
| `php.appwrite.global-authorization-disable` | ERROR | Request-wide authorization disable in handlers. |
| `php.appwrite.route-without-scope` | ERROR | `/v1` route with no scope label. |
| `php.appwrite.route-without-api-group` | ERROR | Non-public `/v1` route outside the `api` hook group. |
| `php.appwrite.account-token-secret-scope-gate` | ERROR | Key-injected token/session/JWT response without a `users.write` gate. |
| `php.appwrite.show-sensitive-outside-payload` | ERROR | `showSensitive()` used for anything but an event payload. |
| `php.appwrite.related-permissions-helper` | ERROR | Related-document `$permissions` without `validateRelatedPermissions()`. |
| `php.appwrite.guest-url-without-publicurl` | ERROR | Guest URL-like param with bare `URL()` / `Text()`. |
| `php.appwrite.redirect-param-without-validator` | ERROR | Redirect-target param with bare `URL()` / `Text()`. |
| `php.appwrite.unbounded-map-to-outbound` | ERROR | Guest `Assoc` param in a class with no `ALLOWED_*` allowlist. |
| `php.appwrite.client-ip-header` | ERROR | Forwarded client IP / host / proto headers read directly. |
| `php.appwrite.default-secret-placeholder` | ERROR | Placeholder secret literals (Doctor excluded). |
| `php.appwrite.insecure-random` | ERROR | Non-cryptographic randomness or time-seeded hashes. |
| `php.appwrite.insecure-cookie-flags` | ERROR | Non-httponly cookies or native `setcookie`. |
| `php.appwrite.tls-verification-disabled` | ERROR | TLS peer / host verification turned off. |
| `php.appwrite.unsafe-dynamic-code` | ERROR | `eval`, `extract`, unsafe `unserialize`, and similar. |
| `php.appwrite.shell-exec-in-handler` | ERROR | Process execution in request-serving code. |
| `php.appwrite.superglobal-in-handler` | ERROR | PHP superglobals in request-serving code. |
| `php.appwrite.debug-output-in-handler` | ERROR | Debug dumps / native `header()` in request-serving code. |
| `php.appwrite.raw-sql-interpolation` | ERROR | Concatenated or interpolated SQL to `query` / `exec` / `prepare`. |
| `php.appwrite.xml-external-entities` | ERROR | libxml entity / DTD loading enabled. |
| `php.appwrite.header-blocklist-filter` | ERROR | Blocklist-style header / host filters. Prefer an allowlist. |
| `php.appwrite.request-path-to-filesystem` | ERROR | Request-derived value reaching filesystem / include calls (taint). |
| `php.appwrite.guest-write-without-abuse-limit` | WARNING | Guest-reachable writes with no abuse limit. |
| `php.appwrite.weak-secret-env-default` | WARNING | Secret env vars with a non-empty literal default. |
| `php.appwrite.secret-compare-timing` | WARNING | Secret comparisons without `hash_equals()`. |
| `php.appwrite.permissive-write-permission` | WARNING | Write / update / delete granted to `any` or `guests`. |
| `php.appwrite.outbound-follow-redirects` | WARNING | Outbound clients that follow redirects. |

## Fixtures

Each `*.yml` rule has a sibling `*.php` file with `// ruleid:` and `// ok:` annotations. `semgrep test .semgrep` checks those, not production code.

`bash .semgrep/prove.sh` writes short-lived snippets under `src/Appwrite/Platform/Modules/Databases/_semgrep_prove/` (gitignored, removed on exit). It runs an ERROR-only scan (no baseline) on one known-bad snippet per ERROR rule, asserts the scan fails **with that rule id**, then asserts a file of house patterns (gated skip, allowlisted metadata skip, `PublicURL`, `redirectValidator`, `ALLOWED_HEADERS`, `getIP()`, `random_bytes`, safe `unserialize`, bound SQL, `basename`, httponly cookie, event-payload `showSensitive`) produces no ERROR.

## PR comment

On `pull_request`, `Checks / Rules` writes `semgrep.json` and upserts one comment marked `<!-- semgrep-rules-comment -->`. Re-runs edit that comment. Same-repo PRs only (forks have no write token).

Only findings outside the baseline are listed in detail. Baselined findings are summarized as per-rule counts in a collapsed `<details>` block. With no new findings the comment becomes an all-clear plus that summary. The comment step runs even when an earlier step fails; if `semgrep.json` is missing or unreadable (install, fixtures, proofs, or the scan failed), the comment says the scan did not complete and links the run instead of posting an all-clear.

Findings are grouped by rule (full rule message once per group). `semgrep.json` carries no source lines or metavariables without a Semgrep login, so `.github/workflows/semgrep-comment.js` reads the matched file from the checkout and, per finding, prints:

- a `file:line` link to `blob/<GITHUB_SHA>/…#Lstart-Lend`, the commit that was scanned;
- where it is: HTTP method + path + scope for module actions and `Http::*` routes, the shared hook and its groups, `Class::method()`, or the `app/init` resource name;
- **What's wrong** and **How to fix** for that finding, built per rule from the matched code (for example the exact `hash_equals(...)` replacement, the env var and its default, or the route's missing label).

New rules should add an entry to `explainers` in that script; without one the finding still lists with its location and the rule message.

## Deferred

- Flagging allowlisted lookup / subquery skips (`users`, `sessions`, `tokens`, …) or every metadata skip. Those are existing house patterns; new non-allowlisted names still fail.
- Dynamic table names (`database_*_collection_*`, `bucket_*`) and variable collection arguments. Common, usually followed by document ACL on the loaded row.
- Cross-site request checks on console routes. Origin and platform validation is centralized in `app/controllers/general.php`, not per route, so there is no per-route shape to match.
- Taint from a guest `url` param to a distant outbound client call. The param-level `guest-url-without-publicurl` and `redirect-param-without-validator` rules cover the declaration instead.
- Promoting the WARNING classes to ERROR. Each has live debt on the current tree (tracked in `baseline.json`); promote once that debt is cleared.
