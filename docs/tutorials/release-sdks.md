# Releasing Appwrite SDKs

This document is part of the Appwrite contributors' guide. Before you continue reading this document, make sure you have read the [Code of Conduct](https://github.com/appwrite/.github/blob/main/CODE_OF_CONDUCT.md) and the [Contributing Guide](https://github.com/appwrite/appwrite/blob/master/CONTRIBUTING.md).

## Getting Started

### Agenda

This tutorial will cover how to properly release one or multiple Appwrite SDKs. The SDK release process involves updating the SDK generator, configuring Docker secrets, and running the release script.

### Prerequisites

Before releasing SDKs, you need to:

1. **Release a new SDK generator version** - Create a PR in the [sdk-generator](https://github.com/appwrite/sdk-generator) repository with your respective sdk's changes. Wait for the PR to get merged and be released.

2. **Update the SDK generator dependency**
   - Update composer dependencies to use the new SDK generator version:
      ```bash
      docker run --rm --interactive --tty --volume "$(pwd)":/app composer update appwrite/sdk-generator --ignore-platform-reqs --optimize-autoloader --no-scripts
      ```

   - Verify that `composer.lock` reflects the new SDK generator version

### Authenticate the release environment

Install Git and the GitHub CLI in the environment that runs the release command. Authenticate with `gh auth login` or supply `GH_TOKEN` through the runtime environment or your CI secret store. Configure Git's commit identity and verify SSH access to the target repositories before pushing.

Do not put a token in a Dockerfile, image build argument, committed environment file, or command copied into shell history. If you run releases in Docker, pass credentials only at runtime and use an SSH agent or a dedicated read-only key mount instead of copying keys into the image.

### Updating Specs

The SDK generator script heavily relies on API specification files (specs). Whenever you are adding a new endpoint, updating parameters, or making any API changes, you need to update the specs.

Generate specs for the latest version:

```bash
docker compose exec appwrite specs
```

Also generate specs for the current stable Appwrite version:

```bash
docker compose exec appwrite specs --version=1.8.x
```

### Keep documentation examples aligned

Regenerate examples with the released generator and the intended server specification. Review the generated API and response-format changes against the published SDK before pushing. A security-only release should preserve the existing API contract; do not replace a released spec with an unreleased server snapshot.

Publish the reviewed specs and examples to `appwrite/specs` before updating the `@appwrite.io/specs` commit in `apps/console/package.json`. A newer generator lock alone does not update the website's reference snippets: `apps/console/scripts/generate-specs.ts` copies examples from that pinned package. After updating the package and Bun lockfile, run `bun run generate:specs` in `apps/console` and build the website to copy its reference artifacts.

Keep human quick starts and their `prompt.md` AI instructions synchronized. Check supported interpreter versions, dependency pins, SDK catalog entries, and upgrade instructions. Preserve historical self-hosted compatibility rows unless the replacement SDK was validated against that server version.

### Running the SDK Release Script

Before running the SDK release script, ensure you update the following for each SDK you plan to release:

1. **Update the changelog** - Add release notes to the SDK's `CHANGELOG.md` file (located in `docs/sdks/<sdk-name>/CHANGELOG.md`)
2. **Bump the version** - Update the version number (patch, minor, or major) in `app/config/sdks.php`

Once you have completed these updates, run the SDK release script:

```bash
docker compose exec appwrite sdks
```

The script will prompt you for:
1. **Platform** - Select client, server, console, or `*` for all platforms
2. **SDK(s)** - Choose specific SDK(s) or `*` for all
3. **Appwrite version** - Specify the version (e.g., `1.8.x`)
4. **Git options** - Configure push settings and PR creation

#### Releasing Multiple SDKs

If you are releasing multiple SDKs across different platforms, you can specify them directly:

```bash
docker compose exec appwrite sdks --sdks=dart,flutter,cli,python
```

#### Pull Request Summary

After the script completes, you'll receive a summary of created pull requests:

```text
Pull Request Summary
Dart: https://github.com/appwrite/sdk-for-dart/pull/123
Flutter: https://github.com/appwrite/sdk-for-flutter/pull/124
CLI: https://github.com/appwrite/sdk-for-cli/pull/125
```

### Creating GitHub Releases

> **Note:** This section is for Appwrite maintainers only.

After the PRs have been reviewed and merged by an Appwrite Lead, you can create GitHub releases automatically.

#### Dry Run

First, perform a dry run to preview the releases:

```bash
docker compose exec appwrite sdks --release=yes
```

This will display what releases would be created:

```text
[DRY RUN] Would create release for Dart SDK:
  Repository: appwrite/sdk-for-dart
  Version: 13.0.0
  Title: 13.0.0
  Target Branch: main
  Previous Version: 12.0.2
  Release Notes:
  ## What's Changed
  - Added support for new Users API endpoints
  - Fixed authentication token handling
  - Updated dependencies
```

#### Execute Release

After verifying the dry run output, create the actual releases:

```bash
docker compose exec appwrite sdks --release=yes --commit=yes
```

## Reference

### Configuration Files

SDK configurations are defined in the following files:

- **`app/config/sdks.php`** - Platform and SDK definitions, including metadata, Git repository URLs, versions, and enabled/disabled status
- **`src/Appwrite/Platform/Tasks/SDKs.php`** - SDK generation and release logic
- **`docs/sdks/<sdk-name>/CHANGELOG.md`** - Changelog files for each SDK

## Troubleshooting

### Authentication Issues

If you encounter authentication problems:
- **GitHub token** - Verify your token has the correct permissions (repo access, workflow permissions)
- **SSH keys** - Ensure your SSH keys are properly configured in `~/.ssh/` and added to your GitHub account
- **Git configuration** - Check that the Git email in the Dockerfile matches your GitHub account

### Common Issues

- **"Release already exists"** - The script automatically skips releases that already exist for the specified version
- **"No changes detected"** - Ensure you've updated the specs and that there are actual API changes to generate
- **Permission denied** - Verify that your GitHub token and SSH keys have write access to the SDK repositories

## Summary

Congrats! You've successfully learned how to release Appwrite SDKs. Remember to:

1. Update SDK generator and run `composer update`
2. Configure Docker secrets (GitHub token and SSH keys)
3. Update specs for both latest and stable versions
4. Update changelogs and bump versions in `platforms.php`
5. Run the SDK script and create PRs
6. (Maintainers only) Create GitHub releases after PR approval

Happy releasing! 🎉
