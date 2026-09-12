# Utopia VCS

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/vcs`](https://github.com/utopia-php/monorepo/tree/main/packages/vcs) — please open issues and pull requests there.

[![Packagist Version](https://img.shields.io/packagist/v/utopia-php/vcs.svg)](https://packagist.org/packages/utopia-php/vcs)
![Packagist Downloads](https://img.shields.io/packagist/dt/utopia-php/vcs.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia VCS is a simple and lite library for interacting with version control systems (VCS) in Utopia-PHP using adapters for different providers like GitHub, GitLab etc. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

Install using Composer:
```bash
composer require utopia-php/vcs
```

Init in your application:
```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\VCS\Adapter\Git\GitHub;

// Initialise your adapter
$github = new GitHub();

// Your GitHub app private key. You can generate this from your GitHub App settings.
$privateKey = 'your-github-app-private-key';

// Your GitHub App ID. You can find this in the GitHub App dashboard.
$githubAppId = 'your-github-app-id';

// Your GitHub App installation ID. You can find this in the GitHub App installation settings.
$installationId = 'your-github-app-installation-id';

// Initialise variables
$github->initializeVariables($installationId, $privateKey, $githubAppId);

// Perform the actions that you want, ex: create repository
$owner = '<repository-owner>';
$name = '<repository-name>';
$isPrivate = true; // Set to false if you want to create a public repository
$repository = $github->createRepository($owner, $name, $private);
```

### Environment variables
To configure your GitHub App, set the following environment variables in your environment or configuration file. The adapter authenticates with the GitHub API on behalf of your app with them.

1. *PRIVATE_KEY*: generate this from your GitHub App settings. The adapter accepts the PEM as is, base64-encoded, or on one line with newlines escaped as `\n`.
```bash
PRIVATE_KEY = your-github-app-private-key
```
2. *GITHUB_APP_ID*: find this in the GitHub App dashboard.
```bash
GITHUB_APP_ID = your-github-app-id
```
3. *INSTALLATION_ID*: find this in the GitHub App installation settings after installation.
```bash
INSTALLATION_ID = your-github-app-installation-id
```

Replace the placeholders with the actual values from your GitHub App configuration. Reading them from the environment keeps the credentials out of your codebase.

### Supported adapters

| Adapter | Status |
|---------|---------|
| GitHub | ✅ |
| GitLab | ✅ |
| Bitbucket | ✅ |
| Gitea | ✅ |
| Forgejo | ✅ |
| Gogs | ✅ |
| Origin (Cursor) | ✅ |
| Azure DevOps |  |

`✅  - supported, 🛠  - work in progress`

## System requirements

Utopia VCS requires PHP 8.4 or later.


## Adding an adapter

See [docs/add-new-vcs-adapter.md](docs/add-new-vcs-adapter.md).

## Tests

The unit tier parses webhook deliveries and verifies their signatures, and needs
nothing running:

```bash
composer test
```

The e2e tier drives the adapters against real providers. Gitea, Forgejo, Gogs,
and GitLab come from `docker-compose.yml`, each published on an offset host port
and bootstrapped with an access token the tests read from `tests/.tokens`:

```bash
docker compose up -d --wait   # GitLab alone takes a few minutes to come up
composer test:e2e
docker compose down -v
```

`bin/monorepo test vcs` runs both tiers, stack included. The GitHub and
Bitbucket suites talk to the hosted products, so they skip unless their
credentials are in the environment: `TESTS_GITHUB_PRIVATE_KEY`,
`TESTS_GITHUB_APP_IDENTIFIER`, and `TESTS_GITHUB_INSTALLATION_ID` for GitHub,
`TESTS_BITBUCKET_ACCESS_TOKEN` and `TESTS_BITBUCKET_WORKSPACE` for Bitbucket.

## License

MIT
