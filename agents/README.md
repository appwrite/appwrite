# Appwrite Development Guide for AI Agents

This file is the **primary source of reference** and persistent instructions for the AI agent (Antigravity) working on the Appwrite codebase.

## Quick Navigation

- **[Commands](commands.md)** - Build, test, and development commands
- **[Knowledge Base](knowledge-base.md)** - Knowledge base & best practices
- **[Architecture Overview](#architecture-overview)** - System structure and patterns

## Getting Started

> [!NOTE]
> Appwrite is a distributed system built with PHP and Swoole. It uses a microservices architecture managed via Docker Compose.

### Key Directories

- `src/Appwrite` - Core business logic and system components
- `app/` - Application entry points and configuration
- `bin/` - CLI scripts and workers
- `tests/` - Unit, integration, and E2E tests

## Architecture Overview

### Database Layer

- **Utopia Database** abstraction layer supporting MariaDB, PostgreSQL, MongoDB, etc.
- Metadata and collections defined in `app/config/collections/`

### API Layer

- **Utopia Framework** for routing and input validation
- Controllers in `app/controllers/`

### Frontend

- **Svelte** for the Appwrite Console
- Built using Vite

## Common Patterns

### Error Handling

- Use early returns to reduce nesting
- Throw descriptive exceptions using `Appwrite\Utopia\Exception`

### Performance

- Leverage Swoole's asynchronous capabilities where appropriate
- Optimize database queries using the Utopia Database Query API

## Pull Request Guidelines

### Weekly Limit

- ⚠️ **Rule**: Maximum 5 PRs per week. Do not exceed this limit.

### Atomic PRs

- ⚠️ **Rule**: One PR = One fix/feature. (Ek PR mein sirf ek hi fix rakhein).
- Do not combine multiple unrelated changes into a single PR.
- Each PR should be small, focused, and easy to review.
- This helps maintain high quality and prevents being blocked by maintainers.

- Split large PRs by feature boundaries
- Separate internal logic changes from public API changes
- Pattern: Logic → API → Console → Tests
