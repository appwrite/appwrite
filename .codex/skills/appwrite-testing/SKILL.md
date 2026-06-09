---
name: appwrite-testing
description: Choose, write, and review tests in the Appwrite server repository using a pragmatic test pyramid. Use when adding or changing PHPUnit tests, deciding between unit and e2e coverage, moving duplicated high-level coverage lower, testing API endpoints, workers, queues, permissions, auth/scopes, persistence, serialization, retry logic, validators, security regressions, or Swoole-sensitive behavior.
---

# Appwrite Testing

## Core Rule

Keep the test suite pyramid-shaped: many fast unit tests, fewer service/API e2e tests, and very few broad workflow tests. Push each assertion to the lowest level that still proves the behavior.

Prefer fast feedback over formal labels. A narrow integration-style unit test with deterministic fakes is better than a slow e2e test when it proves the same behavior.

## Choose The Test Level

Use `tests/unit/...` for:

- Pure validators, helpers, mappers, parsers, filters, retry classifiers, event builders, URL/security checks, and branching logic.
- Worker building blocks that can be called with fake adapters, in-memory collaborators, or scripted test doubles.
- Regression cases where inputs and observable outputs can be asserted without HTTP, Docker services, queues, Swoole coroutines, or real network calls.
- Boundary serialization/deserialization when the external side can be represented with a deterministic fake response or document.

Use `tests/e2e/Services/{Service}` for:

- HTTP route behavior, status codes, headers, cookies, response shape, SDK-visible contracts, and request validation through the real API path.
- Auth, scope, permission, project mode, platform, and side-specific behavior that depends on Appwrite's request lifecycle.
- Persistence, queue-visible behavior, worker integration, database adapter behavior, and DI/wiring that unit tests cannot prove.
- A small number of high-value service workflows that represent user-visible behavior across multiple Appwrite subsystems.

Avoid broad e2e coverage for every edge case. Cover edge cases in unit tests and keep e2e focused on the integration contract that lower layers cannot exercise.

## Appwrite Patterns

Structure tests as Arrange, Act, Assert. Test observable behavior, not the order of private calls or internal implementation details.

Write unit tests with `PHPUnit\Framework\TestCase` under the matching namespace in `tests/unit`. Use data providers for matrices. Prefer named fake classes over anonymous mocks when PHPStan clarity matters.

Write service e2e tests with `Tests\E2E\Client` and the existing scope traits such as `Scope`, `ProjectCustom`, `SideClient`, `SideServer`, or `ProjectConsole`. Reuse local service base traits when they exist.

Use deterministic test doubles for network, queue, mail, storage, database, and third-party boundaries whenever the point of the test is local behavior. Never call production third-party services from automated tests.

Generate unique IDs, emails, and names for e2e data to survive parallel runs. Cache setup data only when the test does not require precise counts or fresh state.

Keep assertions precise: status code, body fields, error type/message, permission outcome, retry count, adapter call count, or persisted value. Avoid asserting incidental full documents when a sparse behavior assertion is enough.

## Duplication Policy

If a higher-level test finds a bug and no lower-level test fails, add a lower-level regression test where practical.

Do not repeat all conditional branches at e2e level once unit tests cover them. The e2e test should prove routing, auth, serialization, persistence, or wiring only.

Delete or avoid high-level tests that no longer add confidence beyond lower-level coverage. Keep tests readable even if that means some local duplication in setup.

## Swoole And Workers

Be careful with Swoole coroutines in unit tests. Do not run coroutine integrations inside the shared unit process unless existing nearby tests prove the pattern is safe.

For workers, unit-test pure pieces such as batching, classification, cursor construction, retry/backoff decisions, and adapter interaction with fakes. Cover coroutine scheduling, queues, and full worker lifecycle through e2e or existing integration patterns.

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
