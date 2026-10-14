# Apps

Deployable applications that live in this repository but are not part of the PHP server. Each app has its own runtime, dependencies, Docker image, and release cadence.

| App                | What it is                                                 | Image          |
| ------------------ | ---------------------------------------------------------- | -------------- |
| [console](console) | The Appwrite Console and appwrite.io, Bun + TanStack Start | `appwrite/new` |

## Apps, packages, and app

| Directory   | Holds                                                   | Ships as                                 |
| ----------- | ------------------------------------------------------- | ---------------------------------------- |
| `app/`      | The PHP server: entrypoints, config, controllers, views | `appwrite/appwrite`                      |
| `packages/` | Utopia PHP libraries, autoloaded by the server          | Read-only mirrors at `utopia-php/<name>` |
| `apps/`     | Standalone applications                                 | Their own images                         |

`apps/console` is not `packages/console`: the latter is the `utopia-php/console` CLI library. Apps have no split mirror, no Composer autoload, and are excluded from the server's Docker build context.

## CI

The `changes` job in [.github/scripts/changes.sh](../.github/scripts/changes.sh) decides which suites a run needs. A pull request that only touches `apps/console` skips the server build and E2E suite, and a server-only pull request skips the console checks and E2E.

| Workflow                                                              | Runs                                                                                      |
| --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| [console.yml](../.github/workflows/console.yml)                       | Lint, unit tests, and build on every console change; E2E on same-repository pull requests |
| [console-staging.yml](../.github/workflows/console-staging.yml)       | Builds `appwrite/new:<sha>` and deploys it to staging on pushes to `main`                 |
| [console-production.yml](../.github/workflows/console-production.yml) | Builds `appwrite/new:<version>` and `<version>-self-hosted` and deploys to production when an `apps/console/<version>` GitHub Release is published |

## Releasing the console

Publish a GitHub Release whose tag is `apps/console/<version>`. That starts [console-production.yml](../.github/workflows/console-production.yml) after the `console-production` environment approval, and is what the GitHub→Discord integration posts to #releases.

Do this from a human machine or a PAT. A Release created by a workflow using `GITHUB_TOKEN` does not start other workflows.

`--latest=false` is required. The repository Latest release is the self-hosted server (`X.Y.Z`); `doctor` and `bin/release` read `/releases/latest` and `apps/console/*` tags respectively. `console/<version>` is the release tag of `packages/console`, not this app.

```bash
# Prefers the helper: always sets --latest=false and scopes notes to the previous console tag.
bin/release console 1.2.44

# Same thing by hand:
gh release create apps/console/1.2.44 \
  --title "Console 1.2.44" \
  --latest=false \
  --generate-notes \
  --notes-start-tag apps/console/1.2.43
```

Pushing an `apps/console/<version>` tag by itself no longer deploys production. `workflow_dispatch` on console-production.yml can redeploy an existing version; it does not create a Release or a Discord announcement.
