# Coding Standards

## PHP

Appwrite follows [PHP-FIG standards](https://www.php-fig.org/), specifically **PSR-12** for coding style and **PSR-0** for autoloading.

### Formatting

Run formatter on your changes before committing:

```bash
# Format all files
composer format

# Format a specific file or folder
composer format <your file path>
```

### Linting

Run the linter to catch remaining issues:

```bash
# Lint all files
composer lint

# Lint a specific file or folder
composer lint <your file path>

# Get a diff of expected changes
composer lint --report=diff <your file path>
```

## File & Module Structure

HTTP endpoint files follow this structure:

```
src/Appwrite/Platform/Modules/[service]/Http/[resource]/[action].php
```

- Action can only be: `Get`, `Create`, `Update`, `Delete`, or `XList`
- If no resource exists, use the service name as the resource
- Multiple resources use nested folders

## Container Naming

- Workers: `appwrite-worker-X` → `src/Appwrite/Platform/Workers/*`
- Tasks: `appwrite-task-X` → `src/Appwrite/Platform/Tasks/*`
- Other containers: use their service name (e.g., `redis`)

## JavaScript / Frontend

- Uses [Prettier](https://prettier.io/) for formatting
- UI is built with **Svelte** / **SvelteKit** in the [Appwrite Console](https://github.com/appwrite/console) repo

## Security

- Never expose unnecessary service ports (only 80 and 443 publicly)
- Avoid introducing new dependencies without team approval
- Follow Appwrite's [Auth and ACL](https://github.com/appwrite/appwrite/blob/master/docs/specs/authentication.drawio.svg) design
