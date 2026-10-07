# Apps

Deployable applications that live in this repository but are not part of the PHP server. Each app has its own runtime, dependencies, Docker image, and release cadence.

| App | What it is | Image |
|-----|------------|-------|
| [console](console) | The Appwrite Console and appwrite.io, Bun + TanStack Start | `appwrite/new` |

## Apps, packages, and app

| Directory | Holds | Ships as |
|-----------|-------|----------|
| `app/` | The PHP server: entrypoints, config, controllers, views | `appwrite/appwrite` |
| `packages/` | Utopia PHP libraries, autoloaded by the server | Read-only mirrors at `utopia-php/<name>` |
| `apps/` | Standalone applications | Their own images |

`apps/console` is not `packages/console`: the latter is the `utopia-php/console` CLI library. Apps have no split mirror, no Composer autoload, and are excluded from the server's Docker build context.

## CI

The `changes` job in [.github/scripts/changes.sh](../.github/scripts/changes.sh) decides which suites a run needs. A pull request that only touches `apps/console` skips the server build and E2E suite, and a server-only pull request skips the console checks and E2E.

| Workflow | Runs |
|----------|------|
| [console.yml](../.github/workflows/console.yml) | Lint, unit tests, and build on every console change; E2E on same-repository pull requests |
| [console-staging.yml](../.github/workflows/console-staging.yml) | Builds `appwrite/new:<sha>` and deploys it to staging on pushes to `main` |
| [console-production.yml](../.github/workflows/console-production.yml) | Builds `appwrite/new:<version>` and `<version>-self-hosted` and deploys to production |

## Releasing the console

Push an `apps/console/<version>` tag:

```bash
git tag apps/console/1.2.44
git push origin apps/console/1.2.44
```

Console releases are tags only. GitHub releases on this repository belong to the server, and `console/<version>` is the release tag of `packages/console`.
