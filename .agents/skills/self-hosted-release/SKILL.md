---
name: self-hosted-release
description: Enforce Appwrite self-hosted release readiness checks. Use when validating a self-hosted RC or final release, running fresh Docker Compose install tests, testing realistic e2e workflows, discovering the previous stable release baseline, upgrading seeded data from the previous self-hosted release, verifying migrations, compatibility filters, release metadata, installer behavior, or deciding whether a self-hosted release is safe to publish.
compatibility: Requires an Appwrite server repository checkout, Docker Compose, network access to fetch tags/images, and the normal Appwrite test environment.
---

# Self-Hosted Release

## Purpose

Use this skill as a release gate for Appwrite self-hosted RCs and final releases. Do not treat container health or metadata review as enough. A release is not ready until a fresh self-hosted stack works with realistic workflows and an upgrade from the previous stable self-hosted release preserves seeded data through migrations.

## Release Context

1. Confirm the current branch and target version:
   - Check the branch name, `app/init/constants.php`, release notes, Docker image tags, and the user's stated target.
   - If those disagree, stop and resolve the target version before validating.
2. Determine the previous stable self-hosted baseline automatically:
   - Prefer remote tags so stale local tags do not mislead the gate.
   - Use stable semver tags lower than the target version; ignore RC, beta, alpha, dev, and other prerelease tags.
   - Pick the highest stable version lower than the target as the upgrade and compatibility baseline.
   - Only ask the user for a baseline if no stable lower tag can be discovered or the target version is ambiguous.
3. Record the target version, target commit SHA, previous stable baseline, database adapter(s), Compose profile(s), and any release-specific risks before starting validation.

Useful commands:

```sh
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
rg -n "APP_VERSION_STABLE|APP_CACHE_BUSTER" app/init/constants.php
git ls-remote --tags origin "refs/tags/*"
```

## Fresh Install Gate

Build and run the target release with the local Docker Compose stack, then test it like a real self-hosted install.

1. Start from a clean stack and build the target:

```sh
docker compose down -v
docker compose up -d --force-recreate --build --wait
docker compose exec -T appwrite vars
curl -fsS http://localhost/v1/health/version
```

2. Inspect runtime health before running tests:
   - Check `docker compose ps` and `docker compose logs` for crash loops, missing env vars, failed mounts, worker failures, executor/runtime failures, migration errors, queue errors, and warnings that indicate broken self-hosted wiring.
   - Verify required services are reachable through the public HTTP surface, not only from inside containers.
   - If a route is advertised by specs, docs, install templates, or release notes, verify the route actually exists and returns the expected auth/validation behavior.

3. Run the Appwrite test suite inside Docker:

```sh
docker compose exec -T appwrite test /usr/src/code/tests/unit
docker compose exec -T appwrite test /usr/src/code/tests/e2e/General
docker compose exec -T appwrite sh -lc 'vendor/bin/paratest --processes "$(nproc)" /usr/src/code/tests/e2e/Services --exclude-group abuseEnabled --exclude-group screenshots'
```

When validating a risky release, broaden the matrix with the same database adapters and modes CI uses. At minimum, cover the default self-hosted adapter and every adapter touched by migrations, storage, query behavior, relationships, or installer changes.

4. Exercise realistic workflows with real-ish data:
   - Create console users, projects, API keys, platforms, webhooks, teams, memberships, and sessions.
   - Create databases/tables/columns/indexes/relationships and rows with varied permissions, sizes, types, pagination, filters, and updates.
   - Create storage buckets and files, then read, update, preview/download, and delete them through public APIs.
   - Create and execute functions/sites when compute, runtimes, builds, domains, or executor behavior changed.
   - Exercise messaging, realtime, domains, auth providers, abuse/rate-limit behavior, and background workers when they are in scope.
   - Use public APIs and SDK-like calls wherever practical; direct database inspection is only supporting evidence.

Block the release for any missing env var, broken container dependency, failing public route, unexpected 5xx, queue/worker failure, test failure, or workflow that only works with unrealistic empty data.

## Upgrade Gate

Validate upgrade from the previous stable self-hosted release to the target release using the same persisted volumes and configuration.

1. Install the previous stable baseline:
   - Use the discovered baseline tag/image, not a hardcoded version.
   - Start with the documented self-hosted install path and a clean volume set.
   - Confirm `/v1/health/version` reports the baseline version.

2. Seed broad, realistic data on the baseline before upgrading:
   - Include multiple projects, users, teams, memberships, API keys, sessions/tokens, databases/tables/rows, attributes, indexes, relationships, permissions, buckets, files, functions/sites, executions, webhooks, messaging/realtime resources, domains, and project settings as applicable.
   - Include edge-shaped data that migrations commonly break: empty values, long strings, arrays, enums, relationships, large files, many rows, deleted/disabled resources, and mixed permissions.
   - Capture enough IDs and API calls to verify the same resources after upgrade.

3. Upgrade the baseline stack to the target release:
   - Reuse the same volumes and configuration.
   - Replace the Appwrite image/tag with the target release build.
   - Run the documented upgrade path and required migration command(s).
   - Capture logs for appwrite, workers, databases, redis, executor, and proxy services during the first boot.

4. Verify migration behavior:
   - Migration completes without fatal errors, partial failures, or skipped projects.
   - Running the migration again is safe and idempotent.
   - A failed or interrupted migration can be retried without data loss when that scenario is relevant to the changed migration.
   - Existing volumes can be reused after restart.

5. Verify seeded data after upgrade through public APIs:
   - Fetch and mutate seeded projects, users, teams, permissions, rows, files, functions/sites, executions, domains, webhooks, messaging/realtime resources, and settings.
   - Confirm indexes, relationships, file contents, execution history, auth/session behavior, and background worker side effects still work.
   - Run focused e2e tests against the upgraded stack for every service touched by migration, compatibility, installer, or public API changes.

Block the release for migration failure, non-idempotency, data loss, changed permissions, broken reads/writes on upgraded data, failed workers/queues after upgrade, or any behavior that only works on fresh installs.

## Metadata And Compatibility Gate

Review release metadata after the runtime gates, not instead of them:

- `app/init/constants.php`: `APP_VERSION_STABLE` is the target version and `APP_CACHE_BUSTER` is incremented.
- `docker-compose.yml` and `app/views/install/compose.phtml`: Appwrite and console image tags are correct for the target release.
- `README.md` and `README-CN.md`: self-hosted install snippets use the target release.
- `src/Appwrite/Migration/Migration.php`: the target version maps to the correct migration class.
- `CHANGES.md`: the target version has user-facing notes for notable changes, fixes, migrations, compatibility notes, and self-hosted operator impact.
- Generated/public outputs are refreshed when API specs, SDKs, install templates, Docker Compose output, or metadata-driven docs changed.

For public API changes, compare against the previous stable baseline:

- Look for renamed/removed fields, new required inputs, enum changes, response model changes, status-code changes, route behavior changes, permission changes, and SDK-visible serialization changes.
- Request filters live in `src/Appwrite/Utopia/Request/Filters/V*.php`.
- Response filters live in `src/Appwrite/Utopia/Response/Filters/V*.php`.
- Filters must be registered in `app/controllers/general.php` for the correct `x-appwrite-response-format` boundary.
- Every compatibility filter needs focused unit coverage under `tests/unit/Utopia/Request/Filters` or `tests/unit/Utopia/Response/Filters`.
- Add service e2e coverage with `x-appwrite-response-format` when behavior depends on routing, auth, persistence, validation, or serialization.

Block the release when a public breaking change lacks a compatibility filter and tests, unless the release owner explicitly accepts the break and documents it in release notes.

## Release Decision

Do not approve an RC or final self-hosted release until all gates pass:

- Fresh install stack builds, starts, and survives realistic workflows.
- Full relevant tests pass inside Docker.
- No missing env vars, broken mounts, failed workers, broken routes, or unexplained runtime errors remain.
- Previous stable release is installed, seeded with broad data, upgraded to the target, migrated, restarted, and verified through public APIs.
- Migrations are complete, idempotent, retryable where relevant, and preserve seeded data.
- Compatibility filters and tests cover unintended public breaking changes.
- Version constants, image tags, install snippets, migration mapping, generated outputs, and changelog are current.

For every RC/final validation, produce a short release evidence summary with: target version, commit SHA, previous stable baseline, tested database adapters and modes, fresh install scenarios, upgrade scenarios, seeded data categories, test commands and results, blockers found, fixes applied, and remaining owner-approved risks.
