# Patch Release Checklist for Appwrite

When bumping a patch version (e.g., `1.9.0` -> `1.9.1`), follow this checklist.

## Checklist

### Bump console image

Update the console Docker image tag:
- [ ] `docker-compose.yml` -- update `image: appwrite/new:X.Y.Z` (the `appwrite-console` service)

### Bump Appwrite version

- [ ] **`app/init/constants.php`** -- update `APP_VERSION_STABLE` to the new version (e.g., `'1.9.1'`). In same file, increment `APP_CACHE_BUSTER` by 1.
- [ ] **`README.md`** -- update the Docker image tag `appwrite/appwrite:X.Y.Z` in all 3 install code blocks (Unix, Windows CMD, PowerShell).
- [ ] **`README-CN.md`** -- same Docker image tag update in all 3 install code blocks.
- [ ] **`src/Appwrite/Migration/Migration.php`** -- add the new version to the `$versions` array, mapping it to a migration class. If new class exists, use that, otherwise use sle same class as previous version

### Update CHANGES.md

- [ ] Add a new `# Version X.Y.Z` section at the top of `CHANGES.md` with subsections: `### Notable changes`, `### Fixes`, `### Miscellaneous`

## Final review

- [ ] Ask user to review changes before commiting
- [ ] Ask user to update `CHANGES.md` with PRs
- [ ] Ask user to generate specs, if needed
- [ ] Ask user to add request and response filters, if needed
