# PR Workflow for Appwrite

## Branch Targeting

PRs target **`main`**, the primary development branch.

### When creating PRs

```bash
gh pr create --base main --title "Your PR title" --body "..."
```

### Branch naming convention

- `main` - Active development branch; the base for regular PRs.
- `X.Y.x` branches (e.g., `1.8.x`, `1.9.x`) - Per-version branches, used only for release management and backports/cherry-picks. Do not target these for regular feature work.
- Feature branches should be created from and merged back into `main`.
