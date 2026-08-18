# Appwrite

Self-hosted Backend-as-a-Service. Hybrid monolithic-microservice architecture on PHP 8.3+ and Swoole, delivered as Docker containers.

## Commands

| Command | Purpose |
|---------|---------|
| `docker compose up -d --force-recreate --build` | Build and start all services (combined worker + scheduler by default) |
| `docker compose -f docker-compose.yml -f docker-compose.separate.yml --profile separate up -d` | Start per-queue worker and scheduler containers instead |
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

`composer check` / `composer analyze` over the whole project is very slow. Prefer specific files during development.

## Stack

- PHP 8.3+, Swoole 6.x (async runtime, replaces PHP-FPM)
- Utopia PHP (HTTP routing, CLI, DI, queue)
- PostgreSQL (default); adapters: postgresql, mariadb, mongodb via utopia-php/database
- Redis (cache, queue, pub/sub); Docker + Traefik
- PHPUnit 12, Pint (PSR-12), PHPStan level 4, Rector

## Layout

- **src/Appwrite/Platform/Modules/** -- feature modules. Register new ones in `src/Appwrite/Platform/Appwrite.php`. HTTP nesting and hooks: [`src/Appwrite/Platform/AGENTS.md`](src/Appwrite/Platform/AGENTS.md)
- **src/Appwrite/Platform/Workers/** -- shared background workers
- **src/Appwrite/Platform/Tasks/** -- CLI tasks
- **src/Utopia/** -- Composer PSR-4 overrides of Utopia libraries (currently `Bus` only)
- **app/init.php** -- bootstrap; **app/init/** -- configs, constants, locales, models, registers, resources, span, database filters/formats
- **bin/** -- CLI entry points (`worker`, `worker-*`, `schedule`, `schedule-*`, `queue-*`, plus `doctor`, `install`, `migrate`, `realtime`, …)
- **tests/e2e/**, **tests/unit/** -- tests; **public/** -- static assets and generated SDKs

## HTTP actions

Action files must be `Get.php`, `Create.php`, `Update.php`, `Delete.php`, or `XList.php` (`List` is reserved). Model non-CRUD work as a property update (`Teams/Http/Memberships/Status/Update.php` → `PATCH /v1/teams/:teamId/memberships/:membershipId/status`). Never RPC-style files (`Verify.php`, `Block.php`).

| REST verb | HTTP | File | SDK `name` | Example |
|-----------|------|------|------------|---------|
| `create` | POST | `Create.php` | `create` | `POST /v1/account/verifications` |
| `get` | GET | `Get.php` | `get` | `GET /v1/teams/:teamId` |
| `list` | GET | `XList.php` | `list` | `GET /v1/teams` |
| `update` | PATCH / PUT | `Update.php` | `update` | `PATCH /v1/account/sessions/:sessionId` |
| `delete` | DELETE | `Delete.php` | `delete` | `DELETE /v1/teams/:teamId` |

Path, directory, action class, `getName()`, and SDK `Method` `name` describe the same REST operation. SDK `name` is a REST verb, plus a qualifier when needed (`createStringColumn`, `updateStatus`). Never RPC verbs (`verify`, `block`, `send`). `getName()` is a stable registered id (legacy values like `createTeam` exist); new code should match the REST verb (+ qualifier).

Skeleton (full example: `src/Appwrite/Platform/Modules/Teams/Http/Teams/Create.php`):

```php
class Create extends Action
{
    public static function getName(): string { return 'create'; }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/teams')
            ->label('scope', 'teams.write')
            ->inject('response')->inject('dbForProject')
            ->callback($this->action(...));
    }
}
```

Common injections: `$response`, `$request`, `$dbForProject`, `$dbForPlatform`, `$user`, `$project`, `$queueForEvents`, `$queueForMails`, `$queueForDeletes`.

## Conventions

- PSR-12 (Pint), PSR-4 autoloading. Avoid dependencies outside the `utopia-php` ecosystem. Never hardcode credentials — use env vars. Code changes may require a container restart; logs live on the relevant container.
- When updating documents, pass only changed attributes as a sparse Document:

```php
// correct
$dbForProject->updateDocument('users', $user->getId(), new Document([
    'name' => $name,
]));
// incorrect -- passing the full document is inefficient
$user->setAttribute('name', $name);
$dbForProject->updateDocument('users', $user->getId(), $user);
```

  Exceptions: migrations, `array_merge()` with `getArrayCopy()`, updates where nearly all attributes change, complex nested relationship logic requiring full document state.

Follow PSR-12/PSR-4 unless noted. These apply to code, paths, labels, tests, and configuration:

1. **Minimum viable length.** As short as clarity allows, as long as clarity requires.
2. **Prefer single words** when unambiguous; compound names only to avoid ambiguity.
3. **Do not repeat enclosing context.** Parent namespaces, paths, and modules already establish scope.
4. **Prefer established terms** already used in the same module or layer. Do not copy outdated nearby patterns; new code follows this document.
5. **REST verbs only on the HTTP surface** — see [HTTP actions](#http-actions).

| Context | Avoid | Prefer |
|---------|-------|--------|
| `Modules/Teams/Http/Teams/` | `createTeam`, `listTeams` | `create`, `list` |
| SDK `namespace: 'teams'` | `name: 'createTeam'` | `name: 'create'` |
| Injected `$project` | `$projectDocument` | `$project` |
| Domain/user verification | `verifyDomain`, `Verify.php` | `createVerification` / `updateVerification` |

- **PHP:** namespaces PSR-4 mirroring `src/`; classes PascalCase; methods/variables camelCase; constants `SCREAMING_SNAKE_CASE`.
- **Modules:** PascalCase, prefer one word (`Teams`, `Storage`); standard abbreviations OK (`VCS`, `JWT`, `SMTP`). Workers/tasks: PascalCase domain noun. `bin/` entry points: kebab-case with a role prefix (`worker-functions`).
- **Identifiers:** HTTP `getName()` is `{restVerb}` or `{restVerb}{Resource}`. Workers: lowercase plural (`functions`). Tasks: lowercase, matching the bin script.
- **Paths:** lowercase, kebab-case where needed, plural resources. Route params camelCase; `Id` suffix when multiple IDs appear (`:teamId`, `:deploymentId`). JSON camelCase; system fields keep `$` prefixes (`$id`, `$createdAt`).
- **Scopes:** `{resource}.{read|write}`; special scopes `account`, `public`. **Events:** `teams.[teamId].create`. **Audits:** `audits.event` `{resource}.{action}`; `audits.resource` `{type}/{id}`.
- **Collections:** lowercase plural. Attributes camelCase; do not prefix with the collection name. `resourceType` is usually plural (`functions`, `sites`, `deployments`).
- **DI:** `{role}For{Target}` only when multiple of that role coexist (`dbForProject` / `dbForPlatform`). Register new injections in `app/init/resources.php` and `app/init/resources/request.php`.
- **Models:** class PascalCase; `getName()` matches. `Response::MODEL_*` constants `SCREAMING_SNAKE_CASE`; values camelCase singular (`team`) or `{name}List`.
- **Env:** `_APP_` + `SCREAMING_SNAKE_CASE`.
- **Tests:** E2E under `tests/e2e/Services/{Service}/`; methods `test{Verb}` or `test{Verb}{Qualifier}`. Unit path mirrors source; class `{ClassUnderTest}Test`. Never use reflection to reach private members.
- **Spans:** in handlers only `Span::add($key, $value)` — never `Span::init`, `setError`, or `Span::finish`. Keys `snake_case`; dots only for child relationships (`project.id`, `storage.bucket.id`). Cross-cutting ids (`project.id`, `function.id`, `user.id`) stay at top level, not under a subsystem.

## SDK specs

Two independent ways to keep an endpoint out of a generated SDK (both lifted when `_APP_SDK_PREVIEW=enabled`):

1. `exclude` in `app/config/sdks.php` — per-SDK `services` / `methods` lists.
2. `hide:` on `Appwrite\SDK\Method` — `true` drops from every spec; an array drops listed platforms only.

Preview builds set the flag on **both** the `specs` and `sdks` steps in `.github/workflows/sdk-preview.yml`. Read the flag inline at each `hide:` call site with a comment, never behind a helper: `hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled'`. `->label('docs', false)` is separate (mocks, OAuth callbacks) and stays unconditional.

## See also

- Modules (HTTP nesting, hooks, registration): [`src/Appwrite/Platform/AGENTS.md`](src/Appwrite/Platform/AGENTS.md)
- Testing pyramid: [`.codex/skills/appwrite-testing/SKILL.md`](.codex/skills/appwrite-testing/SKILL.md)
- Patch version bump: [`.claude/skills/patch-release-checklist/SKILL.md`](.claude/skills/patch-release-checklist/SKILL.md)
- Self-hosted RC / release gates: [`.agents/skills/self-hosted-release/SKILL.md`](.agents/skills/self-hosted-release/SKILL.md)
- Human contributor setup: [`CONTRIBUTING.md`](CONTRIBUTING.md)

Appwrite is the base server for `appwrite/cloud`. Changes to the Action pattern, module structure, DI system, or response models affect cloud.
