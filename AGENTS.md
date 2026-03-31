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
| `composer analyze` | Static analysis (PHPStan level 3) |
| `composer check` | Same as `analyze` |

## Stack

- PHP 8.3+, Swoole 6.x (async runtime, replaces PHP-FPM)
- Utopia PHP framework (HTTP routing, CLI, DI, queue)
- MongoDB (default), MariaDB, MySQL, PostgreSQL (adapters via utopia-php/database)
- Redis (cache, queue, pub/sub)
- Docker + Traefik (reverse proxy)
- PHPUnit 12, Pint (PSR-12), PHPStan level 3

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

File names in Http directories must only be `Get.php`, `Create.php`, `Update.php`, `Delete.php`, or `XList.php`. For non-CRUD operations, model the endpoint as a property update. For example, updating a team membership status lives at `Teams/Http/Memberships/Status/Update.php` (`PATCH /v1/teams/:teamId/memberships/:membershipId/status`).

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

## Cross-repo context

Appwrite is the base server for `appwrite/cloud`. Changes to the Action pattern, module structure, DI system, or response models affect cloud. The `feat-dedicated-db` feature spans cloud, edge, and console.
