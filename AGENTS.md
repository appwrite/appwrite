# AGENTS.md

Appwrite is an end-to-end backend server for web, mobile, native, and backend apps. This guide provides context and instructions for AI coding agents working on the Appwrite codebase.

## Project Overview

Appwrite is a self-hosted Backend-as-a-Service (BaaS) platform that provides developers with a set of APIs and tools to build secure, scalable applications. The project uses a hybrid monolithic-microservice architecture built with PHP, running on Swoole for high performance.

**Key Technologies:**
- **Backend:** PHP 8.3+, Swoole
- **Libraries:** Utopia PHP
- **Database:** MariaDB, Redis
- **Cache:** Redis
- **Queue:** Redis
- **Containers:** Docker

## Development Commands

```bash
# Run Appwite
docker compose up -d --force-recreate --build

# Run specific test
docker compose exec appwrite test /usr/src/code/tests/e2e/Services/[ServiceName] --filter=[FunctionName]

# Format code
composer format
```

## Code Style Guidelines

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard
- Use PSR-4 autoloading
- Strict type declarations where applicable
- Comprehensive PHPDoc comments

### Naming Conventions

#### `resourceType` Naming Rule

When a collection has a combination of `resourceType`, `resourceId`, and/or `resourceInternalId`, the value of `resourceType` MUST always be **plural** - for example: `functions`, `sites`, `deployments`.

Examples:
```php
'resourceType' => 'functions'
'resourceType' => 'sites'
'resourceType' => 'deployments'
```

## Security Considerations

### Critical Security Practices

- **Never hardcode credentials** - Use environment variables
- **Rate limiting** - Respect abuse prevention mechanisms

## Dependencies

Avoid introducing new dependencies other than utopia-php.

## Pull Request Guidelines
### Before Submitting

-  Run `composer format`
- Update documentation if adding features
- Add/update tests for your changes
- Check that Docker build succeeds
`docs/specs/authentication.drawio.svg`

## Known Issues and Gotchas

- **Hot Reload:** Code changes require container restart in some cases
- **Logging:** There is no central place for logs, so when debugging, ensure to check all possibly relevant containers
