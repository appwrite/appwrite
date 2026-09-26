# RFC: Absorbing the Utopia libraries into appwrite/appwrite

Status: draft · Author: loks0n · Last updated: 2026-09-15 · First slice (tooling + `agents`) on this branch

## Summary

Every `utopia-php/*` library Appwrite depends on moves into this repository under `packages/<name>`. Appwrite loads them **directly** through its own PSR-4 autoload, as it already does for `packages/agents` and `packages/bus`: no Composer dependency, no `path` repository, no `utopia-php/*` entry in `composer.lock`. Each package keeps its own `composer.json`, is split back to its read-only mirror `github.com/utopia-php/<name>` on every push to `main`, and keeps publishing to Packagist for consumers that are not Appwrite. `utopia-php/monorepo`, which already holds 36 of these packages and all of the tooling this needs, is archived once its packages have moved here.

## Goals

- Appwrite runs the head of every library. Library changes and the Appwrite change that needs them land in one pull request, with one CI run, with no release in between.
- One home for library code: one set of CI workflows, one QA toolchain (Pint, PHPStan, Rector, PHPUnit), one lock file for third-party dependencies.
- External consumers (`appwrite/cloud`, `open-runtimes/executor`, `open-runtimes/sdk-for-php`, `appwrite/php-runtimes`, community) keep installing from Packagist without noticing.
- `appwrite/cloud` stops declaring Utopia dependencies of its own; every `Utopia\*` class reaches it through `appwrite/server-ce`'s autoloader.
- Every package converges on one directory and namespace shape, enforced by CI.

## Non-goals

- Rewriting library APIs. Absorption moves code; API changes are separate work with their own releases.
- Folding libraries into `src/Appwrite/`. Generic code stays generic and lives in `packages/`. `src/Utopia/` is gone: `Bus`, its only occupant, became `packages/bus` with a mirror like every other package.
- Moving the four monorepo packages Appwrite does not use. `fastly` is archived, `nats` and `replication` go to `appwrite/cloud`, `reputation` is undecided (Cloud or here).
- Adopting `utopia-php/config` 2.x. See [Version gaps](#version-gaps).

## Current state

Numbers are from `composer.lock` on `main` at 2026-09-10 and the `utopia-php/monorepo` checkout at the same date.

| | |
|---|---|
| `utopia-php/*` packages resolved by Appwrite | 45 (40 direct, 5 transitive: `circuit-breaker`, `di`, `mongo`, `psr7`, `smtp`) |
| Pulled from GitHub VCS repositories rather than Packagist | 3 (`auth`, `cdn`, `vcs`), plus `mqtt`, which Appwrite adopted after this inventory and which is not on Packagist |
| Library source | ~537K lines (`database` 49K, `migration` 21K) |
| Library tests | ~179K lines (`database` 65K) |
| Already in `utopia-php/monorepo` | 32 of the 45 |
| Still standalone repositories, never absorbed | 13: `abuse agents balancer database detector dsn emails fetch locale migration mongo openapi query registry usage`, plus `mqtt` (adopted after this inventory) |
| Distinct PSR-4 declarations across the 45 | 28 |
| Packages declaring the bare `Utopia\` prefix | 5: `http`, `validators`, `client`, `console`, `di` |

`utopia-php/monorepo` already provides, in `bin/monorepo`: `absorb` (subtree import with history, strip hoisted QA, write `mirror.yml`, banner the README, lock the mirror's ruleset), `split` (deterministic per-package history push to the mirror), `release <name> <version>` (tag `<name>/<version>`, mirror the tag, publish notes), `validate` (manifest conventions, dependency graph freshness), `check` (Pint, PHPStan, Rector per package), `test` (unit tier on the host, e2e tier against the package's `docker-compose.yml`), `dependents`, `graph`. Workflows: `tests.yml` (changed-package matrix), `split.yml`, `split-dev.yml`, `mirror-redirect.yml`, `benchmark.yml`. All of it moves here.

## Target shape

### Repository layout

```
appwrite/
  app/  src/  bin/  tests/  public/  docs/          unchanged
  packages/<name>/                                   one directory per library
  bin/monorepo                                       the monorepo tooling, moved verbatim
  rfc/monorepo.md                                    this document
  .github/workflows/
    split.yml  split-dev.yml  mirror-redirect.yml    moved from utopia-php/monorepo
    ci.yml                                           gains the changed-package jobs
```

### Standard package shape

Every package converges on this. `validate` enforces it and CI runs `validate` on every push.

```
packages/<name>/
  composer.json        "Utopia\<Ns>\": "src/"    and    "Utopia\<Ns>\Tests\": "tests/"
  src/                 classes directly here; no src/<Ns>/ nesting
  tests/               unit tier at the top level: no network, no services, no API keys
  tests/E2E/           services tier: anything that needs Redis, a database, an HTTP endpoint or a provider key (capitalised: PSR-4 maps `Tests\E2E\` to the directory name exactly, and Linux is case-sensitive)
  tests/bench/         phpbench cases, excluded from both tiers
  phpunit.xml  rector.php  .gitignore  README.md  CHANGELOG.md  LICENSE
  docker-compose.yml   only when composer test:e2e exists
  .github/workflows/mirror.yml
  docs/  bin/  data/   optional
```

Rules `validate` checks per package:

1. `name` is `utopia-php/<name>`; no `version` key; a `license`.
2. The main `autoload` declares exactly one PSR-4 prefix, `Utopia\<Ns>\`, mapped to `src/`. `<Ns>` lowercased with hyphens removed equals `<name>` (`CircuitBreaker` ↔ `circuit-breaker`, `DNS` ↔ `dns`, `Psr7` ↔ `psr7`, `OpenAPI` ↔ `openapi`).
3. `autoload-dev` declares exactly `Utopia\<Ns>\Tests\` mapped to `tests/`.
4. No `composer.lock`; `.gitignore` lists it.
5. None of: `psalm.xml`, `phpcs.xml`, `.travis.yml`, `.gitpod.yml`, `.coderabbit.yaml`, `pint.json`, Pint/PHPStan/Rector/PHPUnit in `require-dev`, nor any `Dockerfile*` except the ones `docker-compose.yml` builds an e2e service from.
6. Sibling dependencies are Packagist constraints, never path repositories (the mirror must install standalone).
7. The root autoload map and `replace` entries (below) match what the manifests declare.
8. `phpstan.neon` never includes or references a path outside the package: in the old monorepo `../../phpstan.neon` was a per-package floor, here it is Appwrite's own config.

### Root composer.json

`require` loses every `utopia-php/*` line and the three `vcs` repository entries. The libraries' own third-party requirements (`mongodb/mongodb`, `aws/aws-sdk-php`, `phpmailer/phpmailer`, `dragonmantank/cron-expression`, and so on) move into the root `require`, resolved once in one lock. `bin/monorepo validate` enforces it: `replace` hides a package's own `require` from the solver, so every third-party dependency a package declares must be in the root `require`. `bin/monorepo validate` generates the autoload block from the package manifests and fails when the checked-in root is stale:

```json
"autoload": {
  "psr-4": {
    "Appwrite\\": "src/Appwrite",
    "Executor\\": "src/Executor",
    "Utopia\\Bus\\": "packages/bus/src",
    "Utopia\\Abuse\\": "packages/abuse/src",
    "Utopia\\Agents\\": "packages/agents/src",
    "...": "one line per package, 45 in total",
    "Utopia\\": ["packages/http/src", "packages/validators/src",
                 "packages/client/src", "packages/console/src", "packages/di/src"]
  }
},
"autoload-dev": {
  "psr-4": {
    "Tests\\E2E\\": "tests/e2e",
    "Tests\\Unit\\": "tests/unit",
    "Appwrite\\Tests\\": "tests/extensions",
    "Utopia\\Abuse\\Tests\\": "packages/abuse/tests",
    "...": "one line per package"
  }
}
```

The bare `Utopia\` directory list exists only until the five packages that declare it are standardised (phases 2 and 6). An absorbed package that still declares it is named in `BARE` in `bin/monorepo`, with the segment its tests use (`validators` → `Utopia\Validator\Tests\`); `validate` accepts `Utopia\` → `src/` for those packages only, and step B removes each entry.

The root also declares every absorbed package under `replace` (`"utopia-php/<name>": "*"`). Most leaves are transitive dependencies of packages still vendored (`queue` requires `lock`, ten packages require `validators`), and without `replace` Composer would keep installing the vendored copy next to `packages/<name>`; with it the solver treats the root as providing that package and skips the install. `bin/monorepo autoload` generates these entries with the autoload map. Composer probes the list in order; `validate` fails on any class path that resolves in more than one of them.

### Docker

The `composer` stage in `Dockerfile` is unchanged: it installs third-party dependencies only, so its cache no longer busts on library edits. The `base` stage gains `COPY ./packages /usr/src/code/packages` next to the existing `COPY ./src` line and regenerates the optimised autoloader after the copy, because the class map has to see `packages/`:

```dockerfile
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY ./packages /usr/src/code/packages
RUN composer dump-autoload --optimize --no-dev --no-scripts --no-plugins
```

`.dockerignore` gains `packages/*/tests`, `packages/*/docs`, `packages/*/docker-compose.yml`.

### Quality tooling

- Root `pint.json` is the single style config. The monorepo's and Appwrite's are both PSR-12 with a few extra rules; reconcile them once in phase 1 and apply to every absorbed package in its absorb PR (`bin/monorepo check <name> --fix`).
- Root `phpstan.neon` does **not** add `packages/`. Root analysis resolves `Utopia\*` symbols through the autoloader, and analysing a package at Appwrite's level 4 turns its level-max `@phpstan-ignore` annotations into `ignore.unmatchedLine` errors (this happened on the first slice). Every package carries its own `phpstan.neon` (level max, `paths: [src]`, plus `tests` when the unit tier is typed) and is analysed by `bin/monorepo check <name>` with the root binary. A package that does not pass PHPStan 2 at that level on arrival ships with a `phpstan-baseline.neon`, listed under phase 8 for burn-down.
- `phpstan-deadcode.neon` adds `packages/*/src` in phase 8, once the mirrors' external consumers are inventoried.
- Rector stays per package (`packages/<name>/rector.php`); the root `tests/tools/rector.php` is unchanged.

### Tests

- **Library unit tier** runs from Appwrite's PHPUnit. `phpunit.xml` gains a `packages` suite over `./packages/*/tests` excluding `tests/E2E` and `tests/bench`; the `unit` CI job runs it alongside `tests/unit`. Tests that reach a provider or a service belong in `tests/E2E/` even when they skip without a key: on the first slice, agents' conversation tests errored on DNS rather than skipping.
- **Library e2e tier** runs per package on the host against the package's own `docker-compose.yml` (offset host ports, never inside Appwrite's stack), through `bin/monorepo test <name>`.
- **Changed-package matrix.** Port the monorepo's `changed` job into `ci.yml`: diff `packages/` against the base ref, expand through `bin/monorepo dependents`, run `check` and `test` for each. A change under `bin/monorepo`, `.github/`, root `composer.json` or `pint.json` runs every package.
- **Registry-mode nightly.** A scheduled job runs `composer update` inside each `packages/<name>` against Packagist, proving the mirror's constraint combination still installs for external consumers.
- **Appwrite e2e** stays the integration gate. In `ci.yml`, the rule that widens the database matrix when `utopia-php/database`'s lock version changes becomes a path check: `git diff --name-only base...head -- packages/database/ packages/mongo/`.

### Releases and mirrors

- Tags shaped `<package>/<semver>` (`http/2.1.0`) coexist with Appwrite's `1.x.y` tags. `split.yml` already triggers on `tags: ['*/*']`; Appwrite's `release.yml` triggers on its own tag shape and ignores these.
- `split.yml` runs on every push to `main`, splitting each `packages/<name>` to its mirror. Its GitHub App is installed org-wide on `utopia-php`, so only the repository variable `SPLIT_APP_ID` and secret `SPLIT_APP_PRIVATE_KEY` move to this repository.
- `split-dev.yml` (publish a feature branch's split to the mirror so an external consumer can `require dev-<branch>`) and `mirror-redirect.yml` (close PRs and issues opened on a mirror with a pointer here) move as-is.
- `bin/monorepo release <name> <version>` is the only way to release a package. `CHANGELOG.md` is never edited to "release".

### History

Every absorb uses `git subtree add` from the library's **mirror** repository (not from `utopia-php/monorepo`'s subdirectory), so the package arrives with its full linear history and the split algorithm can continue from its `git-subtree-*` annotation. Consequences:

- Absorb PRs **must merge with a merge commit**. A squash drops the annotation and orphans the package from its mirror. The branch ruleset needs an allowance scoped to PRs labelled `absorb`.
- `git log` grows by every library's history. Use `--first-parent` for release notes; the release workflow already scopes a package's notes to `packages/<name>`.

## Version gaps

Direct loading means Appwrite runs the head of every library, so a package where Appwrite trails the latest release is upgraded inside its absorb PR. State at 2026-09-14:

| Package | Appwrite | Latest | Gap | Action |
|---|---|---|---|---|
| `config` | 1.0.0 | 2.0.8 | 1.x is a static key/value registry (`load`, `getParam`, `setParam`); 2.x loads typed config classes from a `Source` plus `Parser`. ~300 call sites, 44 loads in `app/init/configs.php`. | Do not rewrite. Move the 1.x registry (about 100 lines) into `src/Appwrite/Config/`; it only serves Appwrite's product config under `app/config/`. Drop the dependency. 2.x needs a home only if something adopts it. |
| `console` | 0.1.1 → 0.2.9 | 0.2.9 | Additive (`Utopia\Command`). | Merged in #13616 (2026-09-14), which supersedes #11937 and requires `database ^7.3.8`, the first release accepting console 0.2 (utopia-php/database#965). Cloud followed in appwrite-labs/cloud#5817 (merged 2026-09-14). |
| `system` | 0.10.6 | 0.11.0 | Additive (`getMemory()`). `main` already allows `^0.10 \|\| ^0.11`. | `composer update utopia-php/system` in its absorb PR. |
| `vcs` | 5.2.5 | 5.3.0 | Additive. | Absorb PR. |
| `auth` | 0.12.0 | 0.12.x | Bumped on `main` by the 2.1.0 release. | None. |
| `queue` | 2.2.4 | 2.2.4 | Bumped on `main` by the 2.1.0 release. | None. |
| `cache` `pools` `smtp` `telemetry` | patch behind | | Bug fixes. | Absorb PR. |

Re-run the comparison before every phase: `bin/monorepo list` against `jq '.packages[] | select(.name|startswith("utopia-php/"))' composer.lock`.

## Standardising the shape

Two steps with very different cost. Step A rides inside each absorb PR; step B is its own phase.

**Step A, non-breaking.** Move `src/<Ns>/*` up to `src/`; move `messaging`'s `src/Utopia/Messaging` up; rename test namespaces to `Utopia\<Ns>\Tests\` (dev-only, invisible to consumers); remove `di`'s stray `Tests\E2E\ => tests/e2e` from its main autoload; delete the banned files; for `http` and `di`, replace the bare `Utopia\` declaration with `Utopia\Http\` and `Utopia\DI\`, safe because every class already lives under that segment. No consumer sees a change; no release is needed.

**Step B, breaking.** Three packages keep a class at the bare `Utopia\` root, which a `Utopia\<Ns>\` prefix cannot load:

| Package | Today | Standard | Appwrite files |
|---|---|---|---|
| `validators` | `Utopia\Validator` (base class), `Utopia\Validator\*`, `Utopia\PHPStan` | `Utopia\Validator\Validator`, `Utopia\Validator\PHPStan\*` | 39 |
| `console` | `Utopia\Console` | `Utopia\Console\Console` | 77 |
| `client` | `Utopia\Client`, `Utopia\Psr18\*` | `Utopia\Client\Client`, `Utopia\Client\Psr18\*` | 2 |

Each ships as a major on its mirror with a one-major `class_alias` shim for the old name (`src/compat.php`, autoloaded via `files`), so external consumers upgrade at their own pace. Appwrite and Cloud are updated in the same PR. When the last of the three lands, the bare `Utopia\` list leaves the root autoload for good.

`client` took step B in `utopia-php/monorepo` and released it as `0.5.0` before its absorb, so it never joined the bare `Utopia\` list. Packages here are not installed by Composer, so `bin/monorepo autoload` copies each package's `autoload.files` into the root, which is how the shim loads in Appwrite. The shim registers its aliases up front and guarded: PHP never autoloads a name while checking a declared type, and a package's test run loads both the root and the package autoloader.

## Phases

Package edits happen only where the package currently lives; this document carries the freeze list. Every absorb PR does the same six things:

1. `bin/monorepo absorb <name>` from the mirror URL (full history, QA stripped, `mirror.yml`, README banner, ruleset).
2. Apply step A of the shape.
3. Upgrade Appwrite to the package head if a gap exists (table above).
4. `bin/monorepo validate` regenerates the root autoload map; remove the package's `require` line; hoist its third-party requirements; `composer update --lock`.
5. `bin/monorepo check <name> --fix`, `bin/monorepo test <name>`, then the full Appwrite CI.
6. Merge with a merge commit; confirm the mirror's `Split` run is green; triage PRs still open on the mirror.

### Phase 0. Decide and freeze

- Land this RFC. Rewrite the Libraries section of `AGENTS.md`: generic code goes in `packages/<name>` (Utopia namespace, loaded directly, mirrored to Packagist); Appwrite-specific code stays in `src/Appwrite/`.
- Announce the freeze on `utopia-php/monorepo` and the 13 standalone repositories: from the date each package is absorbed, its only writable home is here. `mirror-redirect` enforces it for new PRs; existing open PRs on each mirror are triaged in that package's absorb PR.
- Decide `reputation` (Cloud or here) and whether `config` 2.x gets a `packages/` home.
- Add the branch-ruleset allowance for merge commits on `absorb`-labelled PRs.

Exit: RFC merged, `AGENTS.md` updated, freeze announced, ruleset in place.

### First slice, on this branch

Phase 1 as written below, with `agents` as the proving package instead of `validators`: `bin/monorepo` and the split, split-dev and mirror-redirect workflows moved here; `agents` imported from its `main` branch with history, reshaped to the standard layout, and autoloaded directly, with `utopia-php/agents` gone from `require`. Two things learned while landing it, both now rules above: packages are analysed under their own `phpstan.neon` rather than the root config, and tests that reach a provider or a service live in `tests/E2E/` even when they would skip without a key (agents' conversation suites errored on DNS rather than skipping). The Dockerfile change is smaller than planned: the composer stage's optimised autoloader falls back to PSR-4 for classes outside its class map, so `COPY ./packages` in the base stage is enough.

### Phase 1. Tooling, proven with one package

One PR, labelled `absorb`, containing:

- `bin/monorepo` moved verbatim, with `ORG`/paths adjusted for this repository and a new `autoload` subcommand that `validate` uses to regenerate the root map.
- `split.yml`, `split-dev.yml`, `mirror-redirect.yml` moved; `SPLIT_APP_ID` / `SPLIT_APP_PRIVATE_KEY` set on this repository.
- `ci.yml`: the changed-package job, `validate` in the `checks` job, the `packages` PHPUnit suite in the `unit` job, the database-matrix rule switched to a path check.
- `Dockerfile` and `.dockerignore` changes from [Docker](#docker).
- Root `pint.json` reconciled with the monorepo's.
- The proving package, `validators`: dependency-free, already in the monorepo, at its latest release, declares the bare `Utopia\` prefix (so the shared-prefix list is exercised from day one), and is referenced from about 1,150 Appwrite files, so an autoload mistake fails everything immediately.

Exit: full Appwrite CI green; `bin/monorepo release validators 1.0.2` cut from this repository; the mirror and Packagist show it. This PR is the reversible checkpoint: reverting it restores the Packagist dependency.

### Phase 2. Leaves (no Utopia dependencies), 23 packages

`auth circuit-breaker compression console detector di dsn fetch image locale lock mongo mqtt openapi psr7 query registry smtp system telemetry user-agent websocket` and the `config` registry move.

- `console` lands via #13616 first; its absorb then changes only the source of the same 0.2.9 code.
- `system` is upgraded to 0.11 in its absorb PR.
- The 9 standalone ones (`detector dsn fetch locale mongo mqtt openapi query registry`) go through `absorb`'s full playbook; the others are re-absorbed from their mirrors, which are already prepared.
- `http` and `di` do their step-A prefix change here.
- Batch four to six per PR; independent packages can run in parallel.

Exit: no `utopia-php/*` leaf in `require`; root map has 23 more lines.

### Phase 3. Infrastructure tier

In dependency order, one PR per line:

1. `pools schedule balancer agents servers`
2. `cache cli client span` (`client` and `span` require each other: one PR)
3. `http queue storage domains emails dns cdn vcs messaging` (`vcs` upgraded to 5.3)

Exit: every package below `platform` and `database` is in `packages/`.

### Phase 4. Framework tier

`platform abuse audit usage`. Small, but every HTTP action and worker inherits from `platform`; run the full e2e matrix by `workflow_dispatch`.

### Phase 5. Database, Mongo, Migration

Last on purpose: 70K source lines, 70K test lines, a `docker-compose.yml` with MariaDB, PostgreSQL and MongoDB, and the most expensive e2e tier in the matrix. Land it once runner budget and caching have settled. Wire `packages/database/` and `packages/mongo/` into the widened-matrix rule.

Exit: `composer.lock` contains no `utopia-php/*` package.

### Phase 6. Standardise the breaking part of the shape

`validators` and `console` (step B); `client` did it as `0.5.0` before its absorb. One PR per package: rename, `class_alias` shim, major release on the mirror, Appwrite call sites updated in the same PR. Remove the bare `Utopia\` list from the root map with the last one. Cloud follows through `server-ce`.

### Phase 7. Retire the old homes and move Cloud

- Archive `utopia-php/monorepo` with a README pointer here. Move `nats` and `replication` to `appwrite/cloud`; archive `fastly`; place `reputation` per the phase 0 decision.
- `appwrite/cloud` removes its `utopia-php/*` requirements and takes the classes through `server-ce`'s autoloader. Its stale pins (`validators ^0.5`, `span 3.0`, `usage 0.14`, `audit ^3`, `query 0.1`) disappear with them; that upgrade is Cloud's own PR series and is not blocked by anything here.

### Phase 8. Harvest

- Add `packages/*/src` to `phpstan-deadcode.neon` and run `composer dead-code`. Candidates visible today: database adapters `SQLite`, `Memory`, `Redis`; cache adapters `Hazelcast`, `Memcached`, `Json`, `Memory`, `RedisCluster`; SMS adapters `Plivo`, `Telnyx`, `Clickatell`, `Infobip`, `Seven`, `Sinch`. Each deletion is mirror-visible: confirm against Executor and Packagist dependents first; anything a mirror consumer needs stays.
- Collapse `||` compatibility constraints in package manifests to single ranges once every sibling is on the current major.
- Delete duplicated test helpers (`tests/extensions/Queue/InMemoryConnection.php` versus the queue package's own fakes) and every Appwrite-side workaround that existed only because a library fix was waiting on a release.
- Burn down every `packages/*/phpstan-baseline.neon` a package arrives with (abuse's Redis cluster log adapters need one under PHPStan 2). Compression arrives with 16 pre-existing findings covering extension return types and the untyped supported-encoding array; resolve these separately from its history-preserving import. System arrives with 16 pre-existing findings from mixed CPU and disk statistics; track those separately from its import. OpenAPI arrives with 166 findings at level max (it was analysed at level 5 in the monorepo), nearly all offset access on the decoded `mixed` document in its readers; narrow those separately from its import. Circuit-breaker arrives with 29 findings at level max (also level 5 in the monorepo): casts from `mixed` in the Redis and Swoole Table adapters, and loosely typed telemetry and Redis fixtures in its tests. WebSocket arrives with 22 (level 5 in the monorepo too): `mixed` handling in `Client` and the Workerman adapter, and its Swoole fixture server and e2e helpers. Cache arrives with 25 (level 5 in the monorepo): `mixed` from the Memcached and Hazelcast server stats and the `RedisCluster` node addresses, values passed to `Envelope::encode()` untyped, and casts of Redis replies in its multiplexing and leasable e2e tests. Validators arrives with 75 (level 5 in the monorepo): 48 in `src`, unvalued `array` parameters and casts from `mixed`, mostly in `Globstar`, `Domain`, `URL`, `Contains` and `WhiteList`; 27 in its tests, nullable validator fixtures in `AssocTest` and `URLTest`. MQTT arrives with 82 (its own repository analysed it at level max under PHPStan 1): `chr()` arguments not narrowed to `int<0, 255>` and casts from `mixed` in the packet codecs and `Property`, untyped Swoole client and request fields in `Client` and the Swoole adapter, and loosely typed data providers and e2e assertions in its tests. Auth arrives with 33 (it had no PHPStan config of its own): `mixed` out-parameters and results from `openssl_pkey_export()`, `openssl_pkey_get_details()` and `openssl_sign()` in the asymmetric issuer and verifier, integer arithmetic in the PHPass encoder, and array shapes in `AuthorizationDetails` and `ResourceIndicators`, plus decoded-claim arithmetic in its tests. DSN arrives with 4 (it had no PHPStan config on its standalone repository): the untyped `$params` array, `parse_url()`'s integer port stored in a `?string` property, and `getParam()` returning the `mixed` parsed query value. CDN arrives with 92 (its standalone repository analysed it at level 6): 50 in `src`, offset access and string concatenation on the `mixed` decoded API responses in the Fastly, Fastly TLS and Cloudflare providers and cache adapters, and `request()` results not narrowed to their declared shapes; 42 in its tests, assertions on decoded request bodies captured by `TestClient`.

## Risks

| Risk | Mitigation |
|---|---|
| Shared `Utopia\` prefix, five packages until phase 2, three until phase 6 | Composer probes the list in order; `validate` fails on any duplicate class path; phase 6 removes the list |
| Step B renames break external consumers | Major release per package with a one-major `class_alias` shim; Appwrite and Cloud updated in the same PR |
| Third-party dependency conflicts once hoisted into one lock | Resolve in phases 1-2; the registry-mode nightly already proves each mirror's combination installs |
| CI cost of 45 test suites on top of Appwrite's matrix | Changed-package matrix; database e2e only on `packages/database/` changes; nightly registry run |
| Squash-merge breaks subtree history | Ruleset allowance for `absorb` PRs; `bin/monorepo split --dry-run` in CI on those PRs |
| Two writable homes during migration | Freeze list here; `mirror-redirect` closes PRs opened on mirrors |
| Mirrors drift from what Appwrite runs | They cannot: the split is byte-identical to `packages/<name>`; only the release tag lags, by choice |
| `git log` noise from imported history | `--first-parent`; release notes already scope to `packages/<name>` |

## Open questions

1. `reputation`: `appwrite/cloud` or `packages/`?
2. `config` 2.x: give it a `packages/` home now (unused by Appwrite, still mirrored) or leave it on its standalone repository until something adopts it?
3. Standard source path: this RFC picks flat `src/` (the directory already repeats the package name). The monorepo's own `docs/creating.md` prescribes `src/<Ns>/`. One has to win before phase 1.
