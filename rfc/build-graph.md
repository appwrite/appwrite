# RFC: An exact graph for Rust, a total map for PHP

Status: draft · Author: loks0n · Last updated: 2026-11-23

## Summary

CI chooses which E2E suites a pull request runs from a hand-kept map in `.github/ci/e2e.json`. Ten of the last 40 pull request runs fell back to running everything, and those ten used 92% of the E2E minutes.

This RFC treats the two languages differently, on purpose.

- **Rust gets an exact graph.** Cargo already has one and the compiler enforces it. What is missing is the edge from a crate to the PHP it serves. Today a change under `crates/` runs no E2E suite on a pull request.
- **PHP gets a total map, and no more.** Every file is assigned to a suite, to `shared`, or to nothing. A file with no owner fails CI, where today it silently runs everything. Nothing tries to infer or enforce the PHP graph, and Utopia DI is left alone.

The reasoning is in [Why not make PHP exact](#why-not-make-php-exact): the measurements say a precise PHP graph would not have changed which suites ran, and code that moves to a crate gets an exact graph by moving.

It does not adopt Bazel. [Alternatives](#alternatives-considered) says why.

## Goals

- Every tracked file has a declared owner. The full-suite fallback is something a person wrote down, in a diff someone reviewed.
- A change to a crate selects the suites that use what it serves, through declared edges.
- `Dockerfile` and workflow changes stop running every suite on every push of a pull request.
- One command, `bin/monorepo affected`, replaces `.github/scripts/changes.mjs`.

## Non-goals

- Restructuring Utopia DI, or decoupling PHP modules, for the sake of the graph. See [Why not make PHP exact](#why-not-make-php-exact).
- Inferring or enforcing PHP dependencies with a static tool.
- Replacing Composer, Cargo or the Dockerfile. `appwrite-labs/cloud` consumes this repository through Composer and builds from the Dockerfile; the Packagist mirrors are split from `packages/`.
- Subdividing a suite, or caching test results across runs.

## Current state

### Where CI time goes

From the last 40 completed `pull_request` runs of `ci.yml`, taken on 2026-11-23. E2E jobs are 70% of all job minutes.

| What ran | Runs | E2E minutes | Share |
|---|---|---|---|
| No E2E | 15 | 0 | 0% |
| Selected suites, 2 to 14 jobs | 15 | 405 | 7% |
| Every suite, 45 jobs | 7 | 1,511 | 26% |
| Every suite on 3 databases in 2 modes, 232 jobs | 3 | 3,791 | 66% |

Three quarters of runs are already cheap. The cost is in the fallbacks.

### What triggers the fallbacks

`changes.mjs` walks the diff. A file that matches no suite's `paths`, and none of the `ignore`, `checks` or `console` lists, sets `all`. A change under one of six "database libraries" (`abuse`, `audit`, `database`, `migration`, `mongo`, `query`) also widens the run to three databases and two modes.

In the ten expensive runs the triggers were:

| Trigger | Runs | Example |
|---|---|---|
| A file under `packages/` with no owner | 2 | 7 files in `packages/abuse`, adding a class nothing used yet: 232 jobs, 1,206 minutes |
| `Dockerfile` | 3 | a base image digest bump; a new `FROM scratch` stage |
| CI files (`ci.yml`, `e2e.json`, `changes.mjs`) | 3 | a Renovate bump of `actions/cache` |
| Fixture lockfiles under `tests/resources/` | 1 | Renovate lock-file maintenance |
| Shared bootstrap (`app/init`, `Response.php`, `.env`) | 1 | a new environment variable |

Seven of the ten were triggered by a file that is not PHP source.

### Which graphs exist

| Unit | Declared in | Enforced by |
|---|---|---|
| `packages/<name>`, 46 | `composer.json` requires | `bin/monorepo test`, which installs each package on its own |
| `crates/<name>`, 12 | `Cargo.toml` | the compiler |
| Crate to the PHP it serves | prose in `crates/extension/README.md`; `paths.extension` in `e2e.json` | nothing |
| Package to the suites that use it | `paths` in `e2e.json`, for 6 of the 46 | nothing |
| Server code to the suite that tests it | `paths` of 36 services in `e2e.json` | nothing |

`bin/monorepo dependents` already walks the package graph, and the `Packages / Changed` job uses it to pick package jobs.

### The Rust gap

`crates/` is in the `checks` and `extension` path lists. Those run the static checks and the extension job, which builds the library and runs the tests of the packages it serves. They select no E2E suite. So a pull request that changes how `Utopia\Validator\Text` or `Utopia\Locale\Locale` behaves runs no E2E until it lands on `main`. Neither class has PHP source any more, and the server uses both on nearly every request.

That gap grows with every class that moves to the extension. There are ten crates under `crates/utopia/` today.

## Target shape

### Rust: the crate graph selects suites

The crate graph is Cargo's. `cargo metadata --no-deps` reads the manifests and prints every crate with the workspace crates it depends on: no build, no network. A change to `crates/support/php-std` reaches every crate that depends on it.

The edge from a crate to PHP needs no new metadata. `crates/utopia/<name>` is the library the extension registers as `packages/<name>`; `NAMES` in `crates/extension/src/libraries.rs` lists them. So a changed crate names its package by its directory.

From a package the PHP map takes over: the suites that own `packages/<name>/` run. A package no suite owns runs every suite, which is right for `validators`, `locale` and `dsn`, and coarse for `image`.

The extension crate binds every library, so a change to it reaches all of them. So do `Cargo.toml`, `Cargo.lock`, `.cargo/` and `rust-toolchain.toml`. A crate's tests, benchmarks and docs reach none; the Extension job runs those.

When server code itself moves to Rust, as a crate behind the extension or as a service of its own, its dependencies are in this graph from the first commit.

### PHP: every file has an owner

`e2e.json` gains a `shared` list: the files a change to which runs every suite. It starts as today's implicit set, written down. The lists are then total. A tracked file that matches none of `services[].paths`, `shared`, `checks`, `console` or `ignore` fails the `Checks` job with the file's name.

Each package gets an owner the same way: the suites that use it, or `shared`. Six have one today. That leaves 40 decisions, made once.

The map stays hand-kept and unchecked. It will sometimes be wrong. The push to `main` runs every suite, and that run is what catches it.

### What no graph can place

A `Dockerfile` or workflow change reaches every suite and nothing in particular. A rule decides those:

- On a pull request, a change that only touches `Dockerfile`, `.github/` or a lockfile runs a smoke set: one suite per runner class.
- The merge queue runs every suite for them. `ci.yml` already handles `merge_group`.
- A push to `main` keeps running every suite on one database, as today.

The three-database, two-mode matrix moves to the merge queue as well. On a pull request it is the most expensive thing CI does, and three runs of it were 66% of the sample's E2E minutes.

### One command

```
bin/monorepo affected [--base=<ref>] [--format=json]
```

It reads the diff, `cargo metadata`, the package graph and `e2e.json`, and prints the package jobs, the suites, the database matrix, and whether the extension and console jobs run. For each selected suite it prints the file and the edge that selected it, so a surprising run explains itself in the job log.

`changes.mjs` and the shell in the `packages` job that computes its matrix go away.

## Why not make PHP exact

Two prototypes measured what an exact PHP graph would buy.

### A static graph

A tokenizer read every PHP file under `app/`, `src/` and `packages/*/src`, resolved class references through the PSR-4 map, and walked the result: 3,171 files and 16,463 edges, in a few seconds.

- 985 files (31%) are reachable from the bootstrap (`app/init`, `app/http.php`, `app/controllers`) before a request reaches a module. A change to any of them has to run every suite.
- 35 of 46 packages have at least one file in that set.

The core is that large because `app/init` constructs every resource and modules ask for them by string (`->inject('dbForProject')`).

### The same graph, following injection

A second pass followed each `->inject('name')` to the block that defines that name, so a resource counts against the files that inject it and not against `app/init`. That is the graph a restructured DI would give.

| | Static | Following injection |
|---|---|---|
| Files in the core | 985 (31%) | 810 (26%) |
| Packages with a file in the core | 35 | 30 |
| Resources reached from the bootstrap | all 158, by construction | 88 |

Then both were replayed on 32 recent pull requests, used only for PHP files the hand-kept map does not own.

| | Suites selected across 32 pull requests |
|---|---|
| Today | 472 |
| Static graph | 436 |
| Following injection | 436 |

One pull request changed, the `packages/abuse` one, and it changed the same way under both. Assigning `packages/abuse` an owner by hand gets the same result.

So following injection moves the core from 31% to 26% and changes nothing that ran. The bootstrap and its hooks still reach 88 of the 158 resources, which is why the core does not fall further.

### What restructuring DI would cost

The shape that would put the edges in the source is a resource class keyed by its own name, defined in the module that owns it, and injected as `->inject(ClientForAvatars::class)`. It is a sound design and the library barely changes, since a class name is a string.

The cost is in the call sites and in Cloud:

| | |
|---|---|
| Resources defined under `app/` | 158 |
| `->inject('name')` calls under `src/Appwrite/Platform` | 4,569, in 838 files |
| Resources injected by at least half of the units | 9 |
| Resources injected by exactly one unit | 40 |
| `->inject('name')` calls in `appwrite-labs/cloud` | 2,988, in 729 files |
| Names defined here that Cloud defines again, replacing the factory | 91 of 158 |
| Resources Cloud adds | 76 |

Cloud replaces more than half of the resources defined here by registering the same name again. Any move has to carry that, across two repositories, with both names live until Cloud finishes.

That is a large migration for a graph that would not have changed a CI run in the sample. It may still be worth doing for other reasons, such as finding usages or deleting dead resources. It is not justified by test selection, so it is not in this RFC.

The three questions the design left open have answers, recorded here in case it is picked up:

- **Dependencies.** A constant listing resource classes, in parameter order, as `Container::set()` already takes them. Types cannot do it: `dbForProject` and `dbForPlatform` are both a `Database`.
- **Scope.** Scope is which container a resource is registered in, as today: the process container, or a child made per request, per worker message or per realtime connection. A resource class does not need to know.
- **Cloud's replacements.** Registering Cloud's class under this repository's class name replaces the factory, exactly as registering the same string does now.

## Alternatives considered

### Bazel

Bazel enforces a graph by sandboxing: an action sees only its declared inputs. It cannot subdivide E2E, because the thing under test is the whole server image.

The costs are specific to this repository:

- No maintained general PHP ruleset turned up in a search. The PHP rules that exist generate protobuf code.
- Composer stays, for the mirrors and for Cloud. `BUILD` files would repeat `composer.json` unless a generator is written too.
- The Dockerfile stays, for Cloud and for self-hosters.
- It is a public repository. Every outside contributor would need Bazel to run a test.

For Rust alone, Cargo already gives the graph this RFC needs. Bazel becomes worth another look when there are several Rust services with their own images and a cache would save real build time.

### moon and Nx

Both run tasks over a project graph and have an `--affected` mode. moon's documentation lists PHP as supported, with Composer coverage that looks partial. Nx has no first-party PHP support, only a community Composer plugin with one maintainer. Neither adds something `cargo metadata` and `bin/monorepo dependents` do not already provide.

### Recorded coverage

Run every suite nightly with a coverage driver, record which files each suite executes, and select by intersecting that with the diff. It observes what ran, so the container does not hide anything. It is the one route to an exact PHP graph that needs no code change. It is not proposed because the replay above suggests an exact PHP graph would not pay, and because nobody has checked that a coverage driver works under Swoole's coroutines.

## Phases

### Phase 0. Print the reason

`changes.mjs` logs which file made a run select everything. No behaviour changes. This gives a baseline from CI's own logs.

### Phase 1. Make the PHP map total

Write down `shared`. Give each package an owner. Assign the files that have none today: the fixture lockfiles go to Sites and Functions, package manifests and tests to the package jobs. A file with no owner fails `Checks`.

Done when: the full-suite fallback can only be reached through `shared`, `Dockerfile` or CI files.

### Phase 2. The Rust edges

A change under `crates/` selects suites through Cargo's graph and its package. Implemented in #15973.

Done when: a pull request that changes only `crates/utopia/image` runs the suites `packages/image` is assigned to, and one that changes `crates/utopia/validators` runs every suite.

### Phase 3. The rule for what no graph can place

The smoke set, and the full and three-database runs in the merge queue.

Done when: a Renovate digest bump of the base image runs the smoke set on its pull request and every suite in the queue.

### Phase 4. `bin/monorepo affected`

Port the selection from `changes.mjs`. Run both in CI for a week and diff their output before removing the old one.

## Risks

- **A wrong owner skips a suite that should have run.** Nothing checks the PHP map. The push to `main` runs every suite, so a miss is found after merge, not before.
- **Phase 2 makes some pull requests slower.** A crate change runs no E2E today. That is the gap being closed, and a crate that serves a `shared` package will run every suite.
- **The smoke set misses a base image regression on the pull request.** The merge queue runs every suite before it lands.
- **The numbers are a small sample.** 40 runs, with one `packages/abuse` run worth 21% of the E2E minutes. Phase 0 replaces them with CI's own.

## Open questions

- Which suites make up the smoke set?
- Does the repository use the merge queue on every pull request, or only some? Phase 3 depends on it.
- Should a crate that serves a `shared` package run every suite on the pull request, or the smoke set there and every suite in the queue?
- When a PHP module is rewritten as a Rust service, where does its suite's entry live: still in `e2e.json`, or in the crate's `Cargo.toml` metadata?
- The Extension job runs clippy, the tests and the parity corpus for the whole workspace on any crate change. Should it narrow to the crates the graph reaches?
