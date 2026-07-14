# PR Workflow for Appwrite

## Branch Targeting

PRs should target **`main`**.

`main` is the primary development branch. Version branches (`1.8.x`, `1.9.x`, …) are for release maintenance / backports only — do not use them as the default PR base unless you are intentionally backporting.

### When creating PRs

```bash
# Correct - targets main
gh pr create --base main --title "Your PR title" --body "..."

# Only for intentional backports
gh pr create --base 1.9.x --title "…" --body "…"
```

### Branch naming convention

- `main` — primary development; feature branches merge here by default
- `X.Y.x` branches (e.g. `1.8.x`, `1.9.x`) — release / maintenance lines; use only for backports
- Feature branches should be created from and merged back into `main`
