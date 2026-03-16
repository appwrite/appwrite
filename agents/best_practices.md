# Pull Request Best Practices

To maintain a healthy codebase and ensure efficient reviews, please follow these principles when submitting Pull Requests.

## ⚠️ Atomic PRs

**Keep only one fix or feature in one PR.**

> [!IMPORTANT]
> This is a core rule we follow: "Atomic PRs: Ek PR mein sirf ek hi fix rakhein".

- **Why**: Small, focused PRs are easier to review, test, and revert if necessary. They reduce the cognitive load for reviewers and speed up the merging process.
- **Guideline**: If you find yourself fixing multiple unrelated issues, split them into separate branches and PRs.

## Branch Naming

Follow the convention: `TYPE-ISSUE_ID-DESCRIPTION`

Types:
- `feat` – new feature
- `fix` – bug fix
- `doc` – documentation only
- `refactor` – code change that neither fixes a bug nor adds a feature
- `cicd` – CI/CD system changes

Example: `fix-1234-correct-user-avatar-upload`

## PR Description

- Clearly describe **what** the change does and **why** it's needed.
- Reference the related GitHub issue (e.g., `Closes #1234`).
- Include steps to test the change manually if applicable.

## Code Quality

- Ensure code follows [PSR-12](https://www.php-fig.org/psr/psr-12/) standards.
- Run formatter before submitting: `composer format <file>`
- Run linter before submitting: `composer lint <file>`
- All tests must pass before requesting review.
