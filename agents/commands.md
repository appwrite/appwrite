# Build, Test & Development Commands

## Development Setup

```bash
# Clone and start services
git clone git@github.com:[YOUR_FORK]/appwrite.git
cd appwrite
git submodule update --init
docker compose build
docker compose up -d
```

## Composer Commands

```bash
# Install dependencies (via Docker if Composer not installed locally)
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer update --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist
```

## Formatting & Linting

```bash
# Format all PHP files
composer format

# Format a specific file
composer format <your file path>

# Lint all PHP files
composer lint

# Lint a specific file
composer lint <your file path>

# Show diff of lint fixes
composer lint --report=diff <your file path>
```

## Testing

```bash
# Run ALL tests
docker compose exec appwrite test

# Run unit tests only
docker compose exec appwrite test /usr/src/code/tests/unit

# Run end-to-end tests only
docker compose exec appwrite test /usr/src/code/tests/e2e

# Run E2E tests for a specific service
docker compose exec appwrite test /usr/src/code/tests/e2e/Services/[ServiceName]

# Run one specific test by function name
docker compose exec appwrite vendor/bin/phpunit --filter [FunctionName]
```

## Cache

```bash
# Clear all Redis cache
docker compose exec redis redis-cli FLUSHALL
```

## Build

```bash
# Build a new release version
bash ./build.sh X.X.X

# Multi-platform Docker build
docker buildx build --platform linux/amd64,linux/arm64,linux/arm/v6,linux/arm/v7,linux/arm64/v8,linux/ppc64le,linux/s390x -t appwrite/appwrite:dev --push .
```

## SDK Generation

```bash
# Generate specs (run inside running appwrite container)
php app/cli.php specs version=<version-number> mode=normal

# Generate all SDKs
php app/cli.php sdks

# Build console web SDK
cd app/sdks/console-web
npm run build
cp iife/sdk.js appwrite.js
```

## Ports

| Port | Use |
|------|-----|
| 80 | HTTP API & Console |
| 443 | HTTPS API & Console |
| 9500–9504 | Debug ports (dev mode only) |
