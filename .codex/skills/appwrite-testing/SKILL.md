---
name: appwrite-testing
description: Choose, write, and review tests in the Appwrite server repository. Use when adding or changing PHPUnit tests, deciding between unit and e2e coverage, testing API endpoints, workers, queues, permissions, auth/scopes, persistence, serialization, validators, or security regressions.
---

# Appwrite Testing

## Core Rule

E2E covers the HTTP/API surface (success and failure). Unit tests cover local `src/` libraries only. Do not unit-test route actions, CLI tasks, or workers.

Canonical policy: root `AGENTS.md` (Tests).

## Choose The Test Level

Use `tests/e2e/Services/{Service}` for:

- Every HTTP route: status codes, headers, cookies, response shape, SDK-visible contracts, and request validation through the real API path — **both success and failure**.
- Auth, scope, permission, project mode, platform, and side-specific behavior that depends on Appwrite's request lifecycle.
- Persistence, queue-visible behavior, worker and CLI-task integration, database adapter behavior, and DI/wiring.
- Cross-subsystem workflows that represent user-visible behavior.

Use `tests/unit/...` only for local src libraries (`src/Appwrite/Auth`, `Network`, `URL`, validators, mappers, parsers, filters, event builders):

- Pure branching logic and boundary serialization that can be asserted without HTTP, Docker, queues, or Swoole.
- Regression cases on library inputs and outputs with deterministic fakes.

Do **not** add unit tests for `Platform/Modules/**/Http` actions, `Platform/Tasks`, `Platform/Workers`, or module workers. Exercise those through e2e; unit-test the libraries they call.

## Appwrite Patterns

Structure tests as Arrange, Act, Assert. Test observable behavior, not the order of private calls or internal implementation details. Never use reflection to reach private members.

Write unit tests with `PHPUnit\Framework\TestCase` under the matching namespace in `tests/unit` (path mirrors `src/`). Use data providers for matrices. Prefer named fake classes over anonymous mocks when PHPStan clarity matters.

Write service e2e tests with `Tests\E2E\Client` and the existing scope traits such as `Scope`, `ProjectCustom`, `SideClient`, `SideServer`, or `ProjectConsole`. Reuse local `{Service}Base` traits. Group assertions under `Test for SUCCESS` / `Test for FAILURE` blocks.

Use deterministic test doubles for network, queue, mail, storage, database, and third-party boundaries in **unit** tests. Never call production third-party services from automated tests.

Generate unique IDs, emails, and names for e2e data to survive parallel runs. Cache setup data only when the test does not require precise counts or fresh state.

Keep assertions precise: status code, body fields, error type/message, permission outcome, or persisted value. Avoid asserting incidental full documents when a sparse behavior assertion is enough.

## Duplication Policy

If an e2e test finds a bug in a src library and no unit test fails, add a unit regression on that library.

Do not unit-test route/task/worker orchestration to avoid duplicating e2e. Keep tests readable even if that means some local duplication in setup.

## Swoole And Workers

Do not run Swoole coroutine integrations inside the shared unit process. Cover workers, queues, and full worker lifecycle through e2e.

Avoid sleeps and timing-sensitive assertions. If timing is unavoidable, isolate it, make it generous, and prefer polling helpers already used in the suite.

## Commands

Run focused checks first:

```bash
docker compose exec appwrite test tests/unit/
docker compose exec appwrite test tests/e2e/Services/[Service]
docker compose exec appwrite test tests/e2e/Services/[Service] --filter=[Method]
composer lint <file>
composer analyze
```

Use the narrowest command that validates the change, then broaden only when the touched behavior crosses module, API, worker, or shared-helper boundaries.
