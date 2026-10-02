# Custom Semgrep rules

In-repo rules for Appwrite PHP coding standards around authorization skips, credential responses, outbound request maps, header filtering, nested document permissions, client IP, and URL fetch.

These run in CI (`Checks / Rules` in `.github/workflows/ci.yml`, and the scheduled `Scan Rules` job in `.github/workflows/security-scan.yml`). They do not replace PHPStan or Trivy.

CI fails on **ERROR** findings only. WARNING rules are review signal (SARIF) and do not fail the job.

## Run locally

Install Semgrep (pinned in CI to `1.179.0`):

```bash
pipx install semgrep==1.179.0
# or: python3 -m pip install --user semgrep==1.179.0
```

Validate fixtures:

```bash
semgrep test .semgrep
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

## Rules

| ID | Severity | What it flags |
| --- | --- | --- |
| `php.appwrite.skip-sensitive-collection` | ERROR | Ungated `skip()` around `getDocument`/`find`/`findOne` of `transactions`, `sessions`, `tokens`, or `files`. House pattern is a ternary gated on API-key / privileged callers. |
| `php.appwrite.skip-ungated-userdata` | WARNING | Same shape for `users`, `memberships`, `identities`, `targets`. Many current sites are intentional (token verify, subscriber lookup). |
| `php.appwrite.account-token-secret-scope-gate` | ERROR | Any handler that injects `Key`/`?Key` and returns `MODEL_TOKEN` or `MODEL_SESSION` without a `users.write` gate that clears `secret`. |
| `php.appwrite.related-permissions-helper` | ERROR | `$permissions` on `$relation` / `$related` / `$nested` / `$child` / `$peer` without `validateRelatedPermissions()`. |
| `php.appwrite.unbounded-map-to-outbound` | WARNING | Guest-reachable scopes plus an `Assoc` headers/query/cookies/options map that is forwarded to `sendRequest` / `Client` / curl. |
| `php.appwrite.header-blocklist-filter` | WARNING | `BLOCKED_*` / `DENIED_*` / `DISALLOWED_*` / `FORBIDDEN_*` header or host constants. Prefer an allowlist. |
| `php.appwrite.client-ip-header` | ERROR | Direct reads of `X-Forwarded-For` / `X-Real-IP` / `X-Client-IP`. Use `Request::getIP()`. |
| `php.appwrite.default-secret-placeholder` | WARNING | `your-secret-key` literals in production PHP (Doctor is excluded). |

## Fixtures

Each `*.yml` rule has a sibling `*.php` file with `// ruleid:` and `// ok:` annotations. `semgrep test .semgrep` checks those, not production code.

## Deferred

- Flagging every ungated `skip()` + metadata load (`databases`, `indexes`, `attributes`, `projects`, …). Hundreds of intentional sites.
- Dynamic table names (`database_*_collection_*`, `bucket_*`) without a skip gate. Common, usually followed by document ACL on the loaded row.
- `url` param + distant `sendRequest` without `PublicURL`. Semgrep cannot AND those two sites in one function/class without emptying the match range; stored URL params (`new URL()`) would false-positive.
- Changing the screenshots header filter in product code (out of scope). WARNING rules surface it instead of path-excluding it.
