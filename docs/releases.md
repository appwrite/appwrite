# Releases

Self-hosted ships on two channels. Cloud is separate: it builds from `cl-*` tags to `appwrite/ce` via [`publish.yml`](../.github/workflows/publish.yml) and nothing below applies to it.

| Channel | Source | Trigger | Tags on `appwrite/appwrite` |
|---------|--------|---------|------------------------------|
| stable | release branch `X.Y.x`, cut from `main` for every minor | GitHub Release, published after admin approval | `X.Y.Z` (immutable), `X.Y`, `X`, `latest` |
| nightly | newest CI-green commit on that branch | daily at 00:00 UTC, or `workflow_dispatch` | `X.Y-nightly.<date>` (one a day), `X.Y-nightly`, `nightly` |

[`nightly.yml`](../.github/workflows/nightly.yml) builds the channel; [`security-scan.yml`](../.github/workflows/security-scan.yml) is the Trivy scan that used to own that file name. `X.Y` comes from the branch name and `<date>` is `YYYYMMDD`, so `2.0.x` publishes `2.0-nightly.20260915`. The tag is a label, not a version the product reads: `migrate` keys off `APP_VERSION_STABLE` compiled into the image, and the `VERSION` build arg only sets `_APP_VERSION`. The branch is rebuilt daily whether or not it moved, so the channel carries base image security fixes. Only the newest release branch is built, so an `X.Y-nightly` tag stops moving once a newer line opens. A minor tagged from `main` without its own `X.Y.x` branch leaves nightly on the previous line, which is what happened with 2.2.0 (no `2.2.x`, so nightly stayed on `2.1-nightly`). Always cut the branch.

Four rules keep the channel safe:

1. **A patch never adds a migration.** Map a new patch to the previous version's class in `Migration::$versions`. A fix that needs a schema change is a minor. This is what lets a nightly user roll back to yesterday's build, and `1.9.6` (which introduced V25) is the exception not to repeat.
2. **Nightly publishes only a CI-green commit** rather than the tip, so a red branch delays the channel instead of breaking it.
3. **The release branch stays releasable.** Backports land as complete cherry-picks, behind a flag when the fix is not finished.
4. **Nightly publishes to `appwrite/appwrite` only.** It must never be able to push to `appwrite/ce`.

Self-hosters opt in through `_APP_VERSION`, which every service in `docker-compose.yml` already resolves (`${_APP_IMAGE:-appwrite/appwrite}:${_APP_VERSION:-latest}`), or with `--channel` on [`install`](../src/Appwrite/Platform/Tasks/Install.php) and [`upgrade`](../src/Appwrite/Platform/Tasks/Upgrade.php):

```
_APP_VERSION=2.0-nightly     # then: docker compose pull && docker compose up -d && docker compose exec appwrite migrate
```

Nightly is unsupported, has no SLA, and only ever moves within one patch line. `X.Y-nightly` is the form to recommend; the bare `nightly` tag follows the newest line and will jump minors.

## Patch train

Patches leave on a schedule so that "is this worth a release?" stops being a per-fix decision:

- **Weekly**, on the active release branch, if at least one user-visible fix landed since the last tag. If nothing landed, skip the release without announcing it.
- **Security and data-loss fixes ship out of band**, same day.
- **Backports are labelled, not remembered.** Fixes land on `main`; a `backport X.Y.x` label opens the cherry-pick PR.
- **A console publish is a release trigger.** The console is pinned (`appwrite/new:X.Y.Z`) in `docker-compose.yml`, so a console-only fix reaches self-hosters only through an `appwrite/appwrite` patch. Bump the pin, let CI pass, and let it ride the next train.

## Release branch

Every release is tagged from its `X.Y.x` branch, never from `main`. The branch gives a stable target to test before tagging, and it is what nightly and backports follow.

1. **Minor:** cut `X.Y.x` from `main`. **Patch:** reuse the existing `X.Y.x`. `bin/release prepare` does this.
2. Open the prep PR (below) against `X.Y.x`.
3. Open a PR from `X.Y.x` into `main`. Run both [gates](#self-hosted-rc--final) locally against `X.Y.x` and record the results, with the exact commit SHA tested, in this PR, so it is the release's test record. Tag that SHA, then merge this PR after the release is published so the version bump and any release-only fixes land back on `main`.

## Version bump

[`bin/release`](../bin/release) runs on the host and does the mechanical part:

```
bin/release prepare X.Y.Z   # branch release/X.Y.Z off origin/X.Y.x (origin/main for a minor), bump, commit, draft notes
bin/release push X.Y.Z      # push release/X.Y.Z as reviewed (and X.Y.x for a minor), open the prep PR and the X.Y.x → main PR
bin/release check X.Y.Z [--ref=<commit>]
```

`prepare` refuses a dirty tree, an existing tag or an existing `release/X.Y.Z`, never touches a local `X.Y.x`, and fails if the result does not pass `check`. Review and amend its commit and draft before `push`, which pushes them as they are and regenerates nothing. It changes:

- [`app/init/constants.php`](../app/init/constants.php): `APP_VERSION_STABLE`.
- [`README.md`](../README.md) and [`README-CN.md`](../README-CN.md): every `appwrite/appwrite:X.Y.Z` install snippet.
- [`docker-compose.yml`](../docker-compose.yml): the `appwrite-console` image, set to the newest of the last 10 [`appwrite/vibes` releases](https://github.com/appwrite/vibes/releases) whose `appwrite/new:<console>-self-hosted` image is on Docker Hub (the image trails the release by a few minutes). Vibes publishes several releases a day, so a pin already bumped on `main` is usually stale.
- [`src/Appwrite/Migration/Migration.php`](../src/Appwrite/Migration/Migration.php): maps the version to the previous version's class unless it is already mapped. It warns when migrations changed since the last tag; then confirm the class stays idempotent for installs that already ran it. A minor that needs a new class gets it by hand. `check` fails a patch that maps to a different class than the previous version (rule 1).

A **minor** (`2.2.0` → `2.3.0`) is anything with new public API, a schema change or removed env vars. A **patch** is fixes only (see rule 1).

`prepare` also writes a release-notes draft to the git directory (its path is printed, and `push` uses it as the prep PR body): the section skeleton of the published [2.2.0 release](https://github.com/appwrite/appwrite/releases/tag/2.2.0) with the install and upgrade commands and contributors filled in, followed by GitHub's generated PR list. Rewrite it in the prep PR body so it is reviewed with the bump, listing only what a self-hosted user can reach through the API and the pinned console. Leave out API additions that have no console screen yet, and say so in the PR. The sections:

- one intro paragraph: themes, console version, upgrade effort
- `### Highlights`, including the console bump
- `### Fixes`, grouped by area in bold (`**Auth**`, `**Databases**`, …), each with PR numbers and `Fixes #N`
- `### Under the hood`
- `### Removed`: env vars, collections and features that are gone
- `### Install` and `### Upgrade`: the commands, what `migrate` changes, and any manual steps
- `### Contributors`

`APP_CACHE_BUSTER` is not a version number and does not track releases. It salts the response cache key in [`Request::cacheIdentifier()`](../src/Appwrite/Utopia/Request.php) for the routes labelled `cache` (file preview, avatars). Bump it only when cached output would now be wrong — a changed image pipeline or new bundled avatar assets — since every bump orphans every entry and regenerates them.

## Publish

The agent running the release does the steps below with the `gh` CLI (and `aws` for oss.appwrite.org), but asks the admin running the release (a maintainer) for explicit approval before each outward-facing one: merging a PR, publishing the GitHub Release, dispatching the specs workflow, merging `appwrite/specs` or `appwrite/vibes` PRs, and touching oss.appwrite.org. One approval covers one step, not the rest of the list.

1. Merge the prep PR into `X.Y.x` once CI is green, then pass both [gates](#self-hosted-rc--final) locally and record them, with the tested SHA (`git rev-parse origin/X.Y.x`), in the `X.Y.x` → `main` PR. Anything merged into `X.Y.x` after that needs the gates again and a new SHA.
2. Publish the GitHub Release on the tested commit, not the branch, with the reviewed notes as the body: `bin/release publish X.Y.Z --sha=<tested-sha> --notes=notes.md`. It fails unless the commit is on `origin/X.Y.x` and passes `check`, the tag is absent or already on that commit, and the image is not on Docker Hub yet. It warns when `X.Y.x` has moved past the tested commit, then asks you to type the version before running `gh release create`. [`release.yml`](../.github/workflows/release.yml) builds amd64+arm64 and pushes `X.Y.Z`, `X.Y`, `X` and `latest` (metadata-action adds `latest` for semver tags). It refuses a version already on Docker Hub, because published tags are immutable. Never re-tag: ship the next patch instead.
3. Watch the `Release` workflow run through to a pushed image.
4. Merge the `X.Y.x` → `main` PR.
5. Publish the specs (below).
6. Merge the vibes PR ([Website and docs](#website-and-docs)) with the changelog entry and the new API reference version.
7. Upgrade [oss.appwrite.org](https://oss.appwrite.org/) to `X.Y.Z`. It runs on EC2 in the Appwrite AWS account (`eu-central-1`, instance named `oss-production`). Log in with `aws sso login --profile <admin-profile>`, then open a shell over SSM (`aws ssm start-session --target <instance-id>`) and upgrade the self-hosted install as usual.

## Specs

Versioned API specs and SDK examples live in [`appwrite/specs`](https://github.com/appwrite/specs) (`specs/X.Y.x/open-api3-X.Y.x.json`, `examples/X.Y.x/`). They are published by the **Generate Specs** workflow (`.github/workflows/specs.yml`) in `appwrite-labs/cloud`, which pulls this repo in as `appwrite/server-ce` (`dev-main`). Both runs go from cloud `main`, never from a one-off branch. `X.Y.x` must describe exactly the released code, so that run pins `server-ce` to the release tag inside the job instead of using the locked `dev-main`, which may lack release-only fixes and carry unreleased work from `main`. `latest` describes `main` and keeps the locked `dev-main`.

1. After the `X.Y.x` → `main` merge, sync cloud `main` with this repo: a cloud PR that runs `composer update appwrite/server-ce` and adds `X.Y.x` to the `version` choices in `specs.yml`. `specs.yml` needs a `server-ce-ref` input that, when set, runs `composer require appwrite/server-ce:"dev-main#<sha>"` in the job before generating (not committed); add it in this PR if it is missing. Merge it once CI is green.
2. `gh workflow run specs.yml -R appwrite-labs/cloud --ref main -f version=X.Y.x -f server-ce-ref=$(git rev-list -n1 X.Y.Z)`. Check the `Installing appwrite/server-ce` line in the job log shows that SHA. It generates the spec and SDK examples and opens `feat: API specs update for version X.Y.x` on `appwrite/specs` from `feat-X.Y.x-specs`.
3. `gh workflow run specs.yml -R appwrite-labs/cloud --ref main -f version=latest` to refresh `specs/latest`.
4. Review and merge both `appwrite/specs` PRs, then add the `X.Y.x` row to the **Available Versions** table in its README (#117 did this for 2.1.x and 2.2.x).

`latest` is regenerated whenever the API changes. `X.Y.x` is regenerated for each `X.Y` patch until the next minor ships, then it is frozen.

## Website and docs

appwrite.io, its docs, the API reference and the [changelog](https://appwrite.io/changelog) are all served from [`appwrite/vibes`](https://github.com/appwrite/vibes). Prepare one vibes PR per release, named `docs: Appwrite X.Y.Z release`, while the `X.Y.x` → `main` PR is under review, so it can be approved and merged shortly after the tag. [vibes#368](https://github.com/appwrite/vibes/pull/368) and [vibes#402](https://github.com/appwrite/vibes/pull/402) are the 2.2.0 examples.

`scripts/release.ts` in vibes makes the edits. Run it on a branch off vibes `main`, with this repo checked out at the release commit:

```
bun run release X.Y.Z --notes=<reviewed-notes.md> --appwrite=<appwrite checkout>
bun run release X.Y.Z --specs      # after the appwrite/specs PR for X.Y.x merged
```

`--notes` defaults to the published GitHub Release, `--appwrite` to `APPWRITE_REPO` or the sibling `appwrite` checkout, and the changelog date to today (`--date`). The first run:

- bumps `appwrite/appwrite:X.Y.Z` in the install snippets on the installation and databases pages
- adds `# Upgrading to X.Y.Z` to the updates page, with the upgrade and `migrate` commands and the prose from the notes' Upgrade section, and moves the example tag
- copies the previous version's rows in both SDK compatibility tables to `X.Y.Z`
- creates the changelog entry `src/content/changelog/entries/YYYY-MM-DD.markdoc` from the notes' Highlights, linking to the GitHub Release
- regenerates the compose generator data (`composeData.ts`) from the appwrite checkout, after checking that its `APP_VERSION_STABLE` is `X.Y.Z`

`--specs` moves the `@appwrite.io/specs` pin to `appwrite/specs` `main`, fails if that commit has no `specs/X.Y.x`, and regenerates the reference `versions.ts`. It runs last because it needs the specs PR merged (see [Specs](#specs)).

What stays manual:

- the changelog cover at `public/images/changelog/YYYY-MM-DD.avif`, and the entry's `tags`
- SDK versions in the new compatibility rows, when SDKs were released for `X.Y.Z`
- rows on the environment variables page, for anything the notes' Removed or Upgrade sections list
- `docker compose config` on the generated compose combinations

`grep -rn '<previous-version>' src/content/docs/advanced/self-hosting src/lib/docs` finds anything still pinned to the old release.

Screenshots go in the PR description so a reviewer can approve without a local build. Run the dev server and use Playwright to capture the version picker, a page of the new reference version, the changelog entry, the upgrade section and the compose generator. Upload them with the PR itself so the links outlive the branch: reference each file in the body as `![alt](./<name>.png)` and pass `--attach './<name>.png#<alt>'` to `gh pr create` or `gh pr edit` (gh ≥ 2.100), which rewrites the references to uploaded asset URLs. Open the PR afterwards and confirm every image loads. Do not host screenshots on a branch. Also include a route smoke-test table (reference pages for the new and previous two versions return 200).

## Self-hosted RC / final

A release is not ready until a **fresh install** and an **upgrade from the previous stable** both work with realistic data. Previous baseline = highest stable semver tag lower than the target (ignore RC/beta/alpha; prefer `git ls-remote --tags origin`).

**Fresh install:** `docker compose down -v` then `up -d --force-recreate --build --wait`. Check `docker compose ps` / logs for crash loops, missing env, failed workers. Hit `/v1/health/version` on the public port. Run unit tests, `tests/e2e/General`, and service e2e. Exercise console users, projects, databases/rows, storage, and (when in scope) functions/sites through public APIs — not empty-stack health checks alone.

**Upgrade:** install the previous stable image, seed broad data (empty values, long strings, relationships, mixed permissions), keep volumes, switch to the target image, run migrate. Migration must complete, be idempotent, and preserve seeded data through public API reads/writes.

**Metadata:** `bin/release check X.Y.Z --ref=<tested-sha>` passes, and the vibes PR is ready. For public API breaks: request filters in `src/Appwrite/Utopia/Request/Filters/V*.php`, response filters in `src/Appwrite/Utopia/Response/Filters/V*.php`, registered in [`app/controllers/general.php`](../app/controllers/general.php) for `x-appwrite-response-format`. Unit-test filters under `tests/unit/Utopia/{Request,Response}/Filters`; add e2e with that header when routing, auth, or persistence is involved.

Do not approve an RC/final until both gates pass, metadata matches the target, and unintended public breaks have filters (or the owner documents the break on the changelog).
