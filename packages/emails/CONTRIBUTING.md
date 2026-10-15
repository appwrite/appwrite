# Contributing to Utopia Emails

Thank you for your interest in contributing to Utopia Emails! This document provides guidelines and information for contributors.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/your-username/emails.git`
3. Create a new branch: `git checkout -b feature/your-feature-name`
4. Make your changes
5. Run tests: `composer test`
6. Run linting: `composer lint`
7. Run static analysis: `composer check`
8. Commit your changes: `git commit -m "Add your feature"`
9. Push to your fork: `git push origin feature/your-feature-name`
10. Create a Pull Request

## Development Setup

### Prerequisites

- PHP 8.0 or later
- Composer

### Installation

```bash
git clone https://github.com/utopia-php/emails.git
cd emails
composer install
```

### Running Tests

```bash
composer test
```

### Code Style

We use Laravel Pint for code formatting. Run the following commands:

```bash
# Check code style
composer lint

# Fix code style issues
composer format
```

### Static Analysis

We use PHPStan for static analysis:

```bash
composer check
```

## Code Standards

### PHP Standards

- Follow PSR-12 coding standards
- Use type hints for all parameters and return types
- Write comprehensive PHPDoc comments
- Use meaningful variable and method names
- Keep methods small and focused
- Write unit tests for all new functionality

### Testing Standards

- Write unit tests for all new features
- Aim for high test coverage
- Use descriptive test method names
- Test both positive and negative cases
- Test edge cases and error conditions

### Documentation Standards

- Update README.md for new features
- Add PHPDoc comments for all public methods
- Include usage examples in documentation
- Keep documentation up to date with code changes

## Pull Request Guidelines

### Before Submitting

1. Ensure all tests pass
2. Run code style checks and fix any issues
3. Run static analysis and fix any issues
4. Update documentation if needed
5. Add tests for new functionality

### Pull Request Template

When creating a pull request, please include:

- A clear description of the changes
- Reference to any related issues
- Screenshots or examples if applicable
- Testing instructions if needed

### Review Process

- All pull requests require review
- Address feedback promptly
- Keep pull requests focused and small
- Rebase on main branch if needed

## Issue Guidelines

### Bug Reports

When reporting bugs, please include:

- PHP version
- Library version
- Steps to reproduce
- Expected behavior
- Actual behavior
- Error messages or logs

### Feature Requests

When requesting features, please include:

- Use case description
- Proposed solution
- Alternative solutions considered
- Additional context

## Development Workflow

### Branch Naming

- `feature/description` - New features
- `bugfix/description` - Bug fixes
- `hotfix/description` - Critical fixes
- `docs/description` - Documentation updates
- `refactor/description` - Code refactoring

### Commit Messages

Use clear, descriptive commit messages:

- Use imperative mood ("Add feature" not "Added feature")
- Keep the first line under 50 characters
- Add more details in the body if needed
- Reference issues when applicable

### Release Process

1. Update version in composer.json
2. Update CHANGELOG.md
3. Create a release tag
4. Publish to Packagist

## Community Guidelines

- Be respectful and inclusive
- Help others learn and grow
- Provide constructive feedback
- Follow the Code of Conduct
- Be patient with newcomers

## Getting Help

- Check existing issues and discussions
- Ask questions in GitHub Discussions
- Join our Discord community
- Contact maintainers directly

## License

By contributing to Utopia Emails, you agree that your contributions will be licensed under the MIT License.

## Thank You

Thank you for contributing to Utopia Emails! Your contributions help make this project better for everyone.

