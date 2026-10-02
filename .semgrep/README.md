# Custom Semgrep rules

In-repo rules for Appwrite PHP coding standards around authorization skips, credential responses, outbound request maps, header filtering, nested document permissions, client IP, and URL fetch.

These run in CI (`Checks / Rules` in `.github/workflows/ci.yml`, and the scheduled `Scan Rules` job in `.github/workflows/security-scan.yml`). They do not replace PHPStan or Trivy.

CI fails on **ERROR** findings only. WARNING rules are review signal: they are written to SARIF and posted as a single sticky PR comment (`<!-- semgrep-rules-comment -->`) that is updated in place on each run.

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

CI-equivalent ERROR scan:

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --error \
  --severity ERROR \
  --exclude .semgrep \
  src/Appwrite app/controllers app/init
```

Include WARNING rules:

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --exclude .semgrep \
  src/Appwrite app/controllers app/init
```

## Trigger matrix

| Pattern | Result |
| --- | --- |
| Ungated `$auth->skip` / `Authorization::skip` + `getDocument`/`find`/`findOne` of a non-allowlisted name (`files`, `transactions`, or any new name such as `privateKeys`) | **ERROR** `skip-ungated-load` |
| Same skip, gated on API-key / privileged callers (ternary or `if`) | ok |
| Same skip of allowlisted metadata / generated tables (`databases`, `indexes`, `cache`, `certificates`, `transactionLogs`, `bucket_*`, `database_*`, …) | ok |
| Same skip of allowlisted lookup / subquery collections (`users`, `teams`, `memberships`, `identities`, `targets`, `subscribers`, `sessions`, `tokens`, `challenges`, `authenticators`, `insights`, `resourceTokens`, `pushLedger`) | ok |
| Same skip of a variable collection (`$collection`) | ok |
| `Key` / `?Key` handler returns `MODEL_TOKEN` / `MODEL_SESSION` / `MODEL_JWT` without a `users.write` secret-clearing gate | **ERROR** `account-token-secret-scope-gate` |
| Same handler with the `users.write` gate, or no Key inject | ok |
| Related-document `$permissions` (`$rel*`, `$nested*`, `$child*`, `$peer*`, `$linked*`) without `validateRelatedPermissions()` | **ERROR** `related-permissions-helper` |
| Top-level `$document` / `$data` / `$collection` `$permissions` | ok |
| Guest-reachable `url` param with bare `URL()` | **ERROR** `guest-url-without-publicurl` |
| Same param with `PublicURL` / `PublicDomain` / `redirectValidator`, or a privileged scope | ok |
| Direct `X-Forwarded-For` / `X-Real-IP` / `X-Client-IP` read | **ERROR** `client-ip-header` |
| `Request::getIP()` | ok |
| `your-secret-key` in production PHP (not Doctor) | **ERROR** `default-secret-placeholder` |
| Guest-reachable `Assoc` headers/query/cookies/options map | **WARNING** `unbounded-map-to-outbound` (screenshots is known debt) |
| `BLOCKED_*` / `DENIED_*` / `DISALLOWED_*` / `FORBIDDEN_*` header or host list | **WARNING** `header-blocklist-filter` (screenshots is known debt) |

## Rules

| ID | Severity | What it flags |
| --- | --- | --- |
| `php.appwrite.skip-ungated-load` | ERROR | Ungated skip + named-collection load outside the allowlist. |
| `php.appwrite.account-token-secret-scope-gate` | ERROR | Key-injected token/session/JWT response without a `users.write` gate. |
| `php.appwrite.related-permissions-helper` | ERROR | Related-document `$permissions` without `validateRelatedPermissions()`. |
| `php.appwrite.guest-url-without-publicurl` | ERROR | Guest `url` param with bare `URL()`. |
| `php.appwrite.client-ip-header` | ERROR | Client IP headers instead of `Request::getIP()`. |
| `php.appwrite.default-secret-placeholder` | ERROR | `your-secret-key` in production PHP (Doctor excluded). |
| `php.appwrite.unbounded-map-to-outbound` | WARNING | ROLE_GUESTS scopes plus an `Assoc` headers/query/cookies/options map. TODO: Avatars screenshots currently matches. |
| `php.appwrite.header-blocklist-filter` | WARNING | `BLOCKED_*` / `DENIED_*` / `DISALLOWED_*` / `FORBIDDEN_*` header or host lists. Prefer an allowlist. TODO: Avatars screenshots currently matches. |

## Fixtures

Each `*.yml` rule has a sibling `*.php` file with `// ruleid:` and `// ok:` annotations. `semgrep test .semgrep` checks those, not production code. `bash .semgrep/prove.sh` writes short-lived snippets under `src/Appwrite/Platform/Modules/_semgrep_prove/` (removed on exit) to show a known-bad skip fails the ERROR scan and a gated skip does not.

## PR comment

On `pull_request`, `Checks / Rules` writes `semgrep.json` and upserts one comment marked `<!-- semgrep-rules-comment -->` listing ERROR and WARNING findings (`file:line`, rule id, message). Re-runs edit that comment. Zero findings updates it to an all-clear. Same-repo PRs only (forks have no write token).

## Deferred

- Flagging allowlisted lookup / subquery skips (`users`, `sessions`, `tokens`, …) or every metadata skip. Those are existing house patterns; new non-allowlisted names still fail.
- Dynamic table names (`database_*_collection_*`, `bucket_*`) and variable collection arguments. Common, usually followed by document ACL on the loaded row.
- Taint-style `url` param + distant `sendRequest`. Semgrep cannot AND those two sites without emptying the match range. The guest-route `param('url', …, new URL())` rule covers the declaration instead.
- Changing the screenshots header filter in product code (out of scope). WARNING rules surface it instead of path-excluding it.
