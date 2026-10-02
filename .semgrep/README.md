# Custom Semgrep rules

In-repo rules for Appwrite PHP coding standards around authorization skips, token response fields, outbound request maps, header filtering, and nested document permissions.

These run in CI (`Checks / Rules` in `.github/workflows/ci.yml`, and the scheduled `Scan Rules` job in `.github/workflows/security-scan.yml`). They do not replace PHPStan or Trivy.

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

Scan the tree the same way CI does (ERROR severity fails the job):

```bash
semgrep scan \
  --config .semgrep \
  --metrics=off \
  --error \
  --severity ERROR \
  --exclude .semgrep \
  src/Appwrite app/controllers
```

The screenshots action is path-excluded from the last two rules so current `main` stays green. Copying those patterns into a new action fails the job.

## Rules

| ID | Severity | What it flags |
| --- | --- | --- |
| `php.appwrite.skip-sensitive-collection` | ERROR | Unconditional `Authorization::skip` / `$authorization->skip` when loading `transactions` or `sessions`. The house pattern is a ternary gated on API-key / privileged callers. |
| `php.appwrite.account-token-secret-scope-gate` | ERROR | Account token / recovery handlers that inject `?Key $apiKey` and return `MODEL_TOKEN` without clearing `secret` for keys that lack `users.write`. |
| `php.appwrite.related-permissions-helper` | ERROR | Nested relationship `$permissions` handling that does not call `validateRelatedPermissions()`. |
| `php.appwrite.unbounded-map-to-outbound` | ERROR | Guest-reachable surfaces (`public`, `avatars.read`, `sessions.write`) that accept an `Assoc` `headers` / `query` map and forward it outbound without an allowlist. Existing screenshots route is path-excluded. |
| `php.appwrite.header-blocklist-filter` | ERROR | Header / host filtering implemented as a short `BLOCKED_*` list instead of an allowlist (`FUNCTION_ALLOWLIST_HEADERS_*`). Existing screenshots route is path-excluded. |

## Fixtures

Each `*.yml` rule has a sibling `*.php` file with `// ruleid:` and `// ok:` annotations. `semgrep test .semgrep` checks those, not production code.

## Deferred

- Broad `skip()` around collection / database metadata loads (too common and usually followed by enabled / existence checks).
- Verification and confirmation `MODEL_TOKEN` responses that do not inject `$apiKey` (session-scoped, not key-minted).
- Inventing new product helpers; rules only enforce helpers that already exist on `main`.
