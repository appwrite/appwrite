# PR Workflow for Appwrite

## Branch Targeting

**Important:** Appwrite does NOT use `main` as the primary development branch.

PRs should target the **current version branch** (e.g., `1.8.x`, `1.9.x`), not `main`.

### How to determine the correct target branch

1. Check which version branch you're currently on: `git branch --show-current`
2. Look for branches matching the pattern `X.Y.x` (e.g., `1.8.x`, `1.9.x`)
3. The current active development branch is typically the highest version number with the `.x` suffix

### When creating PRs

Always use the version branch as the base:

```bash
# Correct - targets the version branch
gh pr create --base 1.8.x --title "Your PR title" --body "..."

# Wrong - do not target main
gh pr create --base main ...
```


<!-- Tanisha test change -->