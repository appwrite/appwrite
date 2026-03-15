# Knowledge Base - Appwrite Domain-Specific Information

## Development

- Appwrite uses a microservices architecture.
- Core services include: `traefik`, `appwrite`, `worker-audits`, `worker-deletes`, `worker-functions`, etc.

## Database Setup

- Utopia Database handles multiple adapters.
- Primary storage is MariaDB by default.
- In-memory caching uses Redis.

## PR Requirements

> [!IMPORTANT]
> All code must be covered by appropriate tests (Unit or Integration).

- ⚠️ **Atomic PRs Mandatory**: See **[PR Guidelines in README.md](README.md#pull-request-guidelines)**.
- Follow Conventional Commits.
- Ensure all tests pass.
- Update documentation if public APIs are changed.

## Error Handling

> [!NOTE]
> Appwrite uses unified error handling. Always use standard Utopia exceptions.

- Use `Appwrite\Log\Log` for system-wide logging.
