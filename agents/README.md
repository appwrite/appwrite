# Appwrite Agent Documentation Index

- **[../CONTRIBUTING.md](../CONTRIBUTING.md)** – Contribution guide (setup, PR process, coding standards)
- **[best_practices.md](best_practices.md)** – PR best practices (⚠️ Atomic PRs rule)
- **[coding-standards.md](coding-standards.md)** – PHP PSR-12, module structure, naming conventions
- **[commands.md](commands.md)** – Build, test, lint, and development commands
- **[knowledge-base.md](knowledge-base.md)** – Architecture, file locations, tech stack

## Quick Reference

### ⚠️ Atomic PRs – Core Rule

> **One PR = One fix or feature.**
> Split unrelated changes into separate branches and PRs.

### Start Development

```bash
git submodule update --init
docker compose build
docker compose up -d
```

### Format & Lint

```bash
composer format <file>
composer lint <file>
```

### Run Tests

```bash
docker compose exec appwrite test
```

### Tech Stack

| Area | Tech |
|------|------|
| Backend | PHP 8+ / Utopia PHP |
| Frontend | Svelte / SvelteKit |
| Database | MariaDB |
| Cache | Redis |
| Container | Docker |
