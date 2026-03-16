# Coding Standards & Best Practices

## Import Guidelines

### PHP Namespaces

- Use full qualified namespaces or import them at the top.
- Follow PSR-4 naming conventions.

## Code Structure

### Early Returns

> [!TIP]
> Prefer early returns to reduce nesting: `if (!$document) return null;`

### Composition

- Use services and traits to keep controllers thin.

### Security Rules

> [!CAUTION]
> Never expose sensitive data in API responses.

- Always perform permission checks using the Utopia Authorization service.
