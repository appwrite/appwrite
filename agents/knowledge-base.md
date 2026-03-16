# Knowledge Base – Domain & Architecture

This file contains Appwrite-specific domain knowledge. For coding guidelines, see [coding-standards.md](coding-standards.md).

## Architecture Overview

Appwrite combines a **Monolithic API** container with **Microservice** worker/task containers.

- The main API runs as a single PHP application (using [Utopia PHP](https://github.com/utopia-php/framework))
- All other services (workers, schedulers, caches) run as isolated Docker containers
- Services communicate over a private TCP network; only ports **80** and **443** are publicly exposed

## Key File Locations

| Path | Purpose |
|------|---------|
| `app/controllers/api/` | Legacy API controllers (avoid adding new code here) |
| `src/Appwrite/Platform/Modules/` | New HTTP module structure |
| `src/Appwrite/Platform/Workers/` | Background worker implementations |
| `src/Appwrite/Platform/Tasks/` | CLI task implementations |
| `app/config/` | Configuration files (collections, specs, locale, etc.) |
| `app/init.php` | Bootstrap and usage metric constants |
| `tests/unit/` | Unit tests |
| `tests/e2e/` | End-to-end tests |

## HTTP Module Pattern

New endpoints should follow the module pattern:

```
src/Appwrite/Platform/Modules/[Service]/Http/[Resource]/[Action].php
```

Actions: `Get`, `Create`, `Update`, `Delete`, `XList`

## Usage Metrics

Metrics are defined as constants in `app/init.php`:

```php
const METRIC_FUNCTIONS  = 'functions';
const METRIC_DEPLOYMENTS  = 'deployments';
```

Metrics aggregate over 3 scopes: **Daily**, **Monthly**, **Infinity**.

- **API metrics**: added via `$queueForStatsUsage->addMetric(...)` in `app/controllers/shared/api.php`
- **Worker metrics**: inject `queueForStatsUsage` in the worker's constructor and call `->addMetric(...)` + `->trigger()`

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend API | PHP 8+ / Utopia PHP Framework |
| UI Console | Svelte + SvelteKit + Pink Design |
| Cache | Redis |
| Database | MariaDB |
| Metrics | InfluxDB + Statsd (Telegraf) |
| Storage scanning | ClamAV |
| Image processing | ImageMagick / WebP |
| Containerization | Docker / Docker Compose |

## Debugging

- Appwrite uses **XDebug** (port `9005`)
- VS Code: use PHP Debug extension, set `pathMappings: { "/usr/src/code": "${workspaceRoot}" }`
- PHPStorm: Settings → Languages & Frameworks → PHP → Debug → Xdebug port `9005`

## Preview Domains (Functions)

Function preview domains follow `[ID].functions.localhost`.

On cloud workspaces (Gitpod / Codespaces), append as a URL param:

```
https://<workspace-url>/ping?preview=<function-id>.functions.localhost
```
