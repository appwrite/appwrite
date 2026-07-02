# Appwrite

Self-hosted Backend-as-a-Service platform. Hybrid monolithic-microservice architecture built with PHP 8.3+ on Swoole, delivered as Docker containers.

## Commands

| Command | Purpose |
|---------|---------|
| `docker compose up -d --force-recreate --build` | Build and start all services |
| `docker compose exec appwrite test tests/e2e/Services/[Service]` | Run E2E tests for a service |
| `docker compose exec appwrite test tests/e2e/Services/[Service] --filter=[Method]` | Run a single test method |
| `docker compose exec appwrite test tests/unit/` | Run unit tests |
| `composer format` | Auto-format code (Pint, PSR-12) |
| `composer format <file>` | Format a specific file |
| `composer lint <file>` | Check formatting of a file |
| `composer analyze` | Static analysis (PHPStan level 4) |
| `composer check` | Same as `analyze` |
| `composer refactor:check` | Rector dry-run over `tests/` (CI "Refactor" check) |
| `composer refactor` | Apply Rector fixes |

## Stack

- PHP 8.3+, Swoole 6.x (async runtime, replaces PHP-FPM)
- Utopia PHP framework (HTTP routing, CLI, DI, queue)
- MongoDB (default), MariaDB, MySQL, PostgreSQL (adapters via utopia-php/database)
- Redis (cache, queue, pub/sub)
- Docker + Traefik (reverse proxy)
- PHPUnit 12, Pint (PSR-12), PHPStan level 4, Rector

## Project layout

- **src/Appwrite/Platform/Modules/** -- feature modules (Account, Avatars, Compute, Console, Databases, Functions, Health, Project, Projects, Proxy, Sites, Storage, Teams, Tokens, VCS, Webhooks)
- **src/Appwrite/Platform/Workers/** -- background job workers
- **src/Appwrite/Platform/Tasks/** -- CLI tasks
- **app/init.php** -- bootstrap (registers services, resources, listeners)
- **app/init/** -- configs, constants, locales, models, registers, resources, span, database filters/formats
- **bin/** -- CLI entry points: `worker-*` (14 workers), `schedule-*`, `queue-*`, plus `doctor`, `install`, `migrate`, `realtime`, `upgrade`, `ssl`, `vars`, `maintenance`, `interval`, `specs`, `sdks`, etc.
- **tests/e2e/** -- end-to-end tests per service
- **tests/unit/** -- unit tests
- **public/** -- static assets and generated SDKs

## Module structure

Each module under `src/Appwrite/Platform/Modules/{Name}/` contains:

```
Module.php           -- registers all services for the module
Services/Http.php    -- registers HTTP endpoints
Services/Workers.php -- registers background workers
Services/Tasks.php   -- registers CLI tasks
Http/{Service}/      -- endpoint actions (Create.php, Get.php, Update.php, Delete.php, XList.php)
Workers/             -- worker implementations
Tasks/               -- CLI task implementations
```

HTTP endpoint nesting reflects the URL path. Sub-resources get subdirectories. For example, within the Functions module:
`Http/Deployments/Template/Create.php` -> `POST /v1/functions/:functionId/deployments/template`

File names in Http directories must only be `Get.php`, `Create.php`, `Update.php`, `Delete.php`, or `XList.php`. For non-CRUD operations, model the endpoint as a property update. For example, updating a team membership status lives at `Teams/Http/Memberships/Status/Update.php` (`PATCH /v1/teams/:teamId/memberships/:membershipId/status`). RPC-style action names (`verifyDomain`, `blockUser`) are not permitted -- see [Naming conventions](#naming-conventions).

Register new modules in `src/Appwrite/Platform/Appwrite.php`. Detailed module guide: `src/Appwrite/Platform/AGENTS.md`.

## Action pattern (HTTP endpoints)

```php
class Create extends Action
{
    public static function getName(): string { return 'createTeam'; }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/teams')
            ->desc('Create team')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].create')
            ->label('scope', 'teams.write')
            ->param('teamId', '', new CustomId(), 'Team ID.')
            ->param('name', null, new Text(128), 'Team name.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $teamId,
        string $name,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        // implementation
    }
}
```

Common injections: `$response`, `$request`, `$dbForProject`, `$dbForPlatform`, `$user`, `$project`, `$queueForEvents`, `$queueForMails`, `$queueForDeletes`.

## Conventions

- PSR-12 formatting enforced by Pint. PSR-4 autoloading.
- `resourceType` values are always **plural**: `'functions'`, `'sites'`, `'deployments'`.
- When updating documents, pass only changed attributes as a sparse Document:
  ```php
  // correct
  $dbForProject->updateDocument('users', $user->getId(), new Document([
      'name' => $name,
  ]));
  // incorrect -- passing full document is inefficient
  $user->setAttribute('name', $name);
  $dbForProject->updateDocument('users', $user->getId(), $user);
  ```
  Exceptions: migrations, `array_merge()` with `getArrayCopy()`, updates where nearly all attributes change, complex nested relationship logic requiring full document state.
- Avoid introducing dependencies outside the `utopia-php` ecosystem.
- Never hardcode credentials -- use environment variables.
- Code changes may require container restart. No central log location -- check relevant containers.

## Naming conventions

Follow PSR-12/PSR-4 defaults unless noted below. These principles apply everywhere -- code, paths, labels, tests, and configuration:

1. **Minimum viable length.** A name should be as short as clarity allows and as long as clarity requires. Include only the words needed to identify the thing at its level of abstraction.
2. **Prefer single words.** Use one word when an existing term is unambiguous in context. Reserve compound or concatenated names for cases where no single word communicates the meaning without ambiguity.
3. **Do not repeat enclosing context.** Parent namespaces already establish scope -- HTTP path segments, directory layout, module name, class namespace, route prefix, or container/module registration. Local names must not restate that context.
4. **Prefer established terms.** When several names are equally clear, choose the one that matches conventions already used in the same module, service, or layer. Consistency across the codebase outweighs local preference.
5. **REST verbs only on the HTTP surface.** Routes, action files, `getName()` identifiers, and SDK method names must map to standard REST operations: `create`, `get`, `list`, `update`, `delete`. Do not introduce imperative or RPC-style verbs (`verify`, `block`, `send`, `confirm`). Express the behavior as a resource or property and apply a REST verb to it.

When in doubt, match the nearest existing module that follows these rules. Legacy code may not conform -- **do not copy outdated patterns by default**. If existing code in the area you are editing violates these principles, call it out in review or in your change description rather than extending the inconsistency. New code should follow this document; opportunistic clean-up of nearby naming is welcome when scope allows.

**Examples**

| Context already provides | Avoid | Prefer |
|--------------------------|-------|--------|
| `Modules/Teams/Http/Teams/` | `createTeam`, `listTeams` | `create`, `list` (via `getName()` / SDK `name`) |
| `Modules/Functions/Http/Deployments/` | `createFunctionDeployment` | `createDeployment` |
| `Modules/Project/Http/Project/Platforms/Android/` | `createProjectAndroidPlatform` | `create` |
| SDK label with `namespace: 'teams'` | `name: 'createTeam'` | `name: 'create'` |
| Handler injected with `$project` | `$projectDocument`, `$currentProject` | `$project` |
| Domain verification flow | `verifyDomain`, `Verify.php` | `createVerification`, `updateVerification` (`Verification/Create.php`, `Verification/Update.php`) |
| User verification flow | `verifyUser` | `createVerification`, `updateVerification` |
| Session expiry change | `expireSession`, `logoutSession` | `updateSession` (`PATCH .../sessions/:sessionId`) |

Add qualifiers only when they disambiguate: `createStringColumn` (type-specific), `:deploymentId` (multiple IDs in one route), `publisherForBuilds` (several publishers in one handler).

### REST-only HTTP and SDK operations

HTTP routes and generated SDK methods **strictly follow REST**. The operation is always one of five verbs applied to a **noun** (resource or property) -- never a custom action verb.

| REST verb | HTTP method | Action file | SDK `name` | Example route |
|-----------|-------------|-------------|------------|---------------|
| `create` | `POST` | `Create.php` | `create` | `POST /v1/account/verifications` |
| `get` | `GET` | `Get.php` | `get` | `GET /v1/teams/:teamId` |
| `list` | `GET` | `XList.php` | `list` | `GET /v1/teams` |
| `update` | `PATCH` / `PUT` | `Update.php` | `update` | `PATCH /v1/account/sessions/:sessionId` |
| `delete` | `DELETE` | `Delete.php` | `delete` | `DELETE /v1/teams/:teamId` |

**Allowed** -- verb + resource/property noun: `createVerification`, `updateVerification`, `updateSession`, `updateStatus`, `createDeployment`.

**Not allowed** -- custom imperative verbs: `verifyDomain`, `verifyUser`, `blockUser`, `sendEmail`, `confirmPhone`. Refactor these into a resource or property under a REST verb:

- `verifyDomain` → `createVerification` or `updateVerification` on a `verification` sub-resource (`POST` / `PATCH .../domains/:domainId/verification`)
- `blockUser` → `updateStatus` on a `status` property (`PATCH .../users/:userId/status`)

The URL path, directory nesting, action class, `getName()`, and SDK `Method` label must all describe the **same** REST operation. Reject proposals that add new action file names, route segments, or SDK methods outside this set.

### PHP code

| Kind | Style | Examples |
|------|-------|----------|
| Namespaces | PSR-4, mirrors `src/` path | `Appwrite\Platform\Modules\Teams\Http\Teams` |
| Classes | PascalCase | `Create`, `Functions`, `Variable` |
| Methods & variables | camelCase | `getName()`, `$teamId`, `$dbForProject` |
| Class constants | `SCREAMING_SNAKE_CASE` | `MODEL_TEAM`, `HTTP_REQUEST_METHOD_POST` |

### Modules, directories, and files

- Module directories: **PascalCase**, prefer one word (`Teams`, `Storage`, `Functions`). Standard abbreviations are fine (`VCS`, `JWT`, `SMTP`).
- HTTP directories nest resources and properties to mirror the URL. Do not repeat the module or parent segment in child directory names when context is already clear. Action files are limited to `Get.php`, `Create.php`, `Update.php`, `Delete.php`, and `XList.php` (`XList` because `List` is a PHP reserved word). Model non-CRUD operations as property updates (`Memberships/Status/Update.php`, not `Block.php`). See `src/Appwrite/Platform/AGENTS.md` for full HTTP layout rules.
- Worker and task classes: PascalCase, domain noun (`Functions`, `Builds`).
- `bin/` entry points: **kebab-case** with a role prefix (`worker-functions`, `schedule-executions`).

### Action, worker, and task identifiers

`getName()` values are registered identifiers -- keep them stable.

- **HTTP actions**: camelCase `{restVerb}` or `{restVerb}{Resource}` where `{restVerb}` is one of `create`, `get`, `list`, `update`, `delete`. Examples: `createVerification`, `updateSession`, `updateStatus`. Never use RPC verbs (`verifyDomain`, `blockUser`). Add a resource qualifier when the verb alone is ambiguous within the module (`createStringColumn`, `createTemplateDeployment`).
- **Workers**: lowercase plural noun -- `functions`, `executions`, `messaging`.
- **Tasks**: lowercase, matching the bin script -- check siblings in `src/Appwrite/Platform/Tasks/`.

### HTTP API

- **Paths**: lowercase, **kebab-case** where needed, plural resources -- `/v1/teams/:teamId/memberships`. Paths name **resources and properties**, not actions.
- **Route params**: camelCase with `Id` suffix when multiple IDs appear in one handler -- `:teamId`, `:deploymentId`. Omit the resource prefix when a segment already identifies it and only one ID is in scope.
- **Request/response JSON**: camelCase (`teamId`, `documentSecurity`). System fields keep Appwrite prefixes (`$id`, `$createdAt`).
- **Scopes**: `{resource}.{read\|write}` (`teams.write`, `documents.read`); special scopes (`account`, `public`).
- **Events**: dot-separated path with bracketed placeholders -- `teams.[teamId].create`. Each segment adds hierarchy; do not repeat a parent segment in a child label.
- **Audit labels**: `audits.event` as `{resource}.{action}`; `audits.resource` as `{type}/{id}`.
- **SDK `Method` labels**: `namespace` and `group` carry service context (usually lowercase plural); `name` must be a REST verb only (`create`, `get`, `list`, `update`, `delete`) -- never `verify`, `block`, or other RPC verbs.

### Database and documents

- **Collection IDs**: lowercase plural nouns (`users`, `teams`, `deployments`).
- **Document attributes**: camelCase (`databaseId`, `enabled`). Do not prefix with the collection name (`teamName` on a team document → `name`).
- **`resourceType` values**: plural lowercase -- `'functions'`, `'sites'`, `'deployments'`.

### Dependency injection

Use `{role}For{Target}` only when multiple resources of that role coexist in one handler:

- `dbForProject`, `dbForPlatform`; `queueForEvents`, `queueForWebhooks`; `publisherForBuilds`, `publisherForDatabase`; `deviceForLocal`, `deviceForSites`

Register new injections alongside existing ones in `app/init/resources.php` and `app/init/resources/request.php`.

### Response models

- Model classes: PascalCase (`Variable`, `ColumnURL`); `getName()` returns the same string.
- `Response::MODEL_*` constants: `SCREAMING_SNAKE_CASE`; values are camelCase -- singular (`'team'`), `{name}List` for lists (`'teamList'`).

### Environment variables

- Prefix `_APP_`, **SCREAMING_SNAKE_CASE** -- `_APP_REDIS_HOST`, `_APP_FUNCTIONS_QUEUE_NAME`.

### Tests

- **E2E**: `tests/e2e/Services/{Service}/` mirrors the API service. Shared logic in `{Service}Base` traits; suites as `{Feature}{ConsoleClientTest|CustomClientTest|CustomServerTest}`.
- **E2E methods**: `test{Verb}` or `test{Verb}{Qualifier}` -- `testCreateTeam`, `testListProjectsQuerySelect`. Omit the service name when the test class already implies it.
- **Unit**: path mirrors source under `tests/unit/`; class `{ClassUnderTest}Test`.

### Tracing span keys

Use `snake_case`; dots only for parent/child relationships (`project.id`, `storage.bucket.id`). See [Tracing with Utopia Span](#tracing-with-utopia-span) for full rules.

## Tracing with Utopia Span

In handlers, only call `Span::add($key, $value)`. **Never** call `Span::init`, `setError`, or `Span::finish` -- lifecycle is owned by the entry-point harness (`app/http.php`, `app/worker.php`, `app/realtime.php`, `Bus::dispatch`). For selective export, filter in the sampler in `app/init/span.php`.

Keys are `snake_case` with dots only for child relationships: `project.id` (id of project), `storage.bucket.id`. No dot otherwise: `inbound_bytes`, not `inbound.bytes`. No camelCase, no bare top-level keys (`function.id`, not `functionId`).

Cross-cutting identifiers (`project.id`, `function.id`, `user.id`) live at the top level, not under a subsystem (no `realtime.project.id`). The trace sampler and downstream filters look them up by the canonical key.

## Patch release process

For bumping patch versions (e.g., `1.9.0` -> `1.9.1`), follow the checklist in `.claude/skills/patch-release-checklist/SKILL.md`. It covers the 4 files that must be updated, console image bumps, CHANGES.md updates, and common pitfalls to avoid.

## Cross-repo context

Appwrite is the base server for `appwrite/cloud`. Changes to the Action pattern, module structure, DI system, or response models affect cloud. The `feat-dedicated-db` feature spans cloud, edge, and console.
