# Build, Test & Development Commands

## Development Commands

- `docker-compose up -d` - Start the Appwrite stack
- `php bin/install.php` - Run the installation script

## Build Commands

- `docker-compose build` - Build the Docker images
- `npm run build` - Build the console (inside the console container)

## Lint & Type Check

- `composer lint` - Run PHP linting
- `npm run lint` - Run linting for the console

## Testing Commands

### Unit Tests

> [!TIP]
> Running unit tests is fast and should be done frequently during development.

- `vendor/bin/phpunit tests/unit` - Run unit tests

### Integration Tests

- `vendor/bin/phpunit tests/integration` - Run integration tests

## Useful Development Patterns

### Running Single Tests

```bash
# Run specific test file
vendor/bin/phpunit tests/unit/Appwrite/Auth/OAuth2/YahooTest.php
```

### Environment Setup

- Generate `.env` file using the install script.
- Configure `_APP_ENV` to `development`.
