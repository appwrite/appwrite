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
      docker run --rm --interactive --tty --volume "$(pwd)":/app composer update --ignore-platform-reqs --optimize-autoloader --no-scripts
      ```

   - Verify that `composer.lock` reflects the new SDK generator version

### Configure Docker Secrets

To enable SDK releases via GitHub, you need to mount SSH keys and configure GitHub authentication in your Docker environment.

#### Update Dockerfile

Add the following configuration to your `Dockerfile`:

```dockerfile
ARG GH_TOKEN
ENV GH_TOKEN=your_github_token_here
RUN git config --global user.email "your-email@example.com"
RUN apk add --update --no-cache openssh-client github-cli
```

Replace:
- `your_github_token_here` with your GitHub personal access token (with appropriate permissions)
- `your-email@example.com` with your Git email address

#### Update docker-compose.yml

Add the SSH key volume mount to the `appwrite` service in `docker-compose.yml`:

```yaml
services:
  appwrite:
    volumes:
      - ~/.ssh:/root/.ssh
      # ... other volumes
```

This mounts your SSH keys from the host machine, allowing the container to authenticate with GitHub.

### Updating specs

SDK generator script heavily relies on specs. So whenever you are adding a new endpoint, updating parameters or making any sort of API changes, you need to update the specs. You can do this by running the following command:

```bash
docker compose exec appwrite specs
```

Make sure to also run it for the current Appwrite version.

```bash
docker compose exec appwrite specs --version=1.8.x
```

### Running the SDK Release Script

Before running the SDK release script you need to make sure to update 2 things for the respective SDKs you plan to release:

1. Update the changelog in the respective SDK's `CHANGELOG.md` file.
2. Bump the version (patch, minor or major) in the `platforms.php` file.

Once you have done that, you can run the SDK release script using the following command:

```bash
docker compose exec appwrite sdks
```

The script will:
1. Prompt you to select the platform (client, server, console, or `*` for all)
2. Ask which SDK(s) to generate (or `*` for all)
3. Request the Appwrite version for which to generate the SDKs (For example: 1.8.x)
4. Guide you through git push options and PR creation

If you are releasing multiple SDKs that belong to different platforms, you can pass in the array of SDKs manually like this:

```bash
docker compose exec appwrite sdks --sdks=dart,flutter,cli,python
```

Once you have run the SDK release script, you will get a summary of the PRs made like this:

```text
Pull Request Summary
Dart: https://github.com/appwrite/dart-sdk/pull/123
Flutter: https://github.com/appwrite/flutter-sdk/pull/123
```

### Releasing the SDKs

If you are a maintainer at Appwrite, you can release the SDKs automatically by using the script. Before that make sure the PRs are reviewed and merged by a Lead at Appwrite.

```bash
docker compose exec appwrite sdks --release=yes
```

This will give a DRY RUN for how the releases will look like:

```text
[DRY RUN] Would create release for Dart SDK:
  Repository: appwrite/dart-sdk
  Version: 1.8.0
  Title: 1.8.0
  Target Branch: main
  Previous Version: 1.7.0
  Release Notes:
  ## What's Changed
  - Add new endpoint /users/:userId/logs
  - Add new endpoint /users/:userId/logs
```

After everything looks good, you can release the SDKs by running the following command:

```bash
docker compose exec appwrite sdks --release=yes --commit=yes
```

### Release Configuration Reference

SDK configurations are defined in:
- **Platform and SDK definitions**: `app/config/collections/platform.php`
- **SDK generation logic**: `src/Appwrite/Platform/Tasks/SDKs.php`

These files contain the SDK metadata, Git repository URLs, versions, and other configuration needed for the release process.

## Troubleshooting

If you encounter authentication issues:
- Verify your GitHub token has the correct permissions (repo access, workflow permissions)
- Ensure your SSH keys are properly configured in `~/.ssh/`
- Check that the Git email in the Dockerfile matches your GitHub account

If everything went well, you should see the SDKs being generated and pushed to their respective repositories.

Congrats! You have successfully released Appwrite SDKs. 🎉
