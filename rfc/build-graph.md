# RFC: A declared dependency graph, so CI tests what a change affects

Status: draft · Author: loks0n · Last updated: 2026-11-23

## Summary

CI already chooses which E2E suites a pull request runs, from a hand-kept map in `.github/ci/e2e.json`. The map has two weaknesses. A file it does not mention runs every suite, and nothing checks that what it does say is true. Ten of the last 40 pull request runs fell back to running everything, and those ten used 92% of the E2E minutes.

This RFC makes the map a graph that is declared for every file and checked against the code. Packages and crates already have one (`composer.json`, `Cargo.toml`). The PHP server does not, and neither does the edge from a crate to the package it implements. Once those exist, one command joins the three graphs and prints what a change affects.

It does not adopt Bazel. [Alternatives](#alternatives-considered) says why, and what would change that.

## Goals

- Every tracked file has a declared owner: a suite, the shared core, checks only, or nothing. A file with no owner fails CI, where today it silently runs everything.
- The declarations are checked. A suite whose code reaches a file it does not declare fails CI.
- A change to a crate selects the packages it implements and the suites that use them, through declared edges.
- One command, `bin/monorepo affected`, replaces `.github/scripts/changes.mjs` and the path lists in `e2e.json`.
- The size of the shared core is a number CI prints, so shrinking it is visible work.

## Non-goals

- Replacing Composer, Cargo or the Dockerfile. `appwrite-labs/cloud` consumes this repository through Composer and builds from the Dockerfile; the Packagist mirrors are split from `packages/`.
- Subdividing a suite. A suite runs or it does not.
- Caching test results across runs. That needs hermetic tests, and E2E runs against Docker Compose with real databases.
- Replacing the dependency injection container with a framework. [Injection names a class](#injection-names-a-class) changes what a resource is keyed by and where it is defined; `Utopia\DI\Container` stays about a hundred lines.

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

### Which graphs exist

| Unit | Declared in | Enforced by |
|---|---|---|
| `packages/<name>`, 46 | `composer.json` requires | `bin/monorepo test`, which installs each package on its own |
| `crates/<name>`, 12 | `Cargo.toml` | the compiler |
| Crate to the package it implements | prose in `crates/extension/README.md`; `paths.extension` in `e2e.json` | nothing |
| Server code to the suite that tests it | `paths` of 36 services in `e2e.json` | nothing |

`bin/monorepo dependents` already walks the package graph, and the `Packages / Changed` job uses it to pick package jobs.

### What an inferred graph finds

A prototype tokenized every PHP file under `app/`, `src/` and `packages/*/src`, resolved class references through the PSR-4 map, and walked the result: 3,171 files and 16,463 edges, in a few seconds.

- 985 files (31%) are reachable from the bootstrap (`app/init`, `app/http.php`, `app/controllers`) before a request reaches a module. A change to any of them has to run every suite.
- 35 of 46 packages have at least one file in that set.
- 1,249 files outside it are reached by a median of 3 suites out of 36.

Replayed on 31 of those pull requests, it changed the selection on two. The `packages/abuse` run went from every suite to none. A change to `packages/vcs/src/Adapter.php` went from 7 suites to all 36, because the bootstrap references the class; the hand-kept map says only the VCS suites matter.

So inference alone is not the answer. It is cheap and it is a good check, but the core is large because `app/init` constructs every resource and modules ask for them by string (`->inject('dbForProject')`). No static tool sees through that.

It also has a blind spot: a class the `utopia` extension registers has no PHP source. `validators` and `user-agent` came out as used by nothing.

### How injection is used

`Utopia\DI\Container` maps a string to a factory. The factories are defined centrally and modules name them by string.

| | |
|---|---|
| Resources defined under `app/` | 158, of which 128 in `app/init/resources.php` and `app/init/resources/request.php` |
| `->inject('name')` calls under `src/Appwrite/Platform` | 4,569, in 838 files across 33 units (30 modules, `Workers`, `Tasks`, `Installer`) |
| Resources injected by at least half of the units | 9: `response`, `authorization`, `project`, `request`, `queueForEvents`, `dbForPlatform`, `dbForProject`, `user`, `publisherForDeletes` |
| Resources injected by exactly one unit | 40 |
| Resources injected by two or three units | 33 |
| Resources with no injection, dependency or lookup in this repository | 11 |
| Injections whose action parameter already has a class type | 92% |

So the core is large by placement, not by need. Nine resources are used everywhere. Most of the rest belong to one module and are defined in a file every module's graph passes through.

## Target shape

### Every file has an owner

`e2e.json` gains a `shared` list: the files a change to which runs every suite. It starts as today's implicit set, written down. The lists are then total. A tracked file that matches none of `services[].paths`, `shared`, `checks`, `console` or `ignore` fails the `Checks` job with the file's name.

That turns "this file runs everything" from a default into a decision someone made, in a diff someone reviewed.

### The declarations are checked

A new `bin/monorepo graph --check` builds the static graph above and fails when:

- a suite's declared files reach a PHP file that is neither in that suite's `paths` nor in `shared`;
- a file in `shared` is reached by nothing in the bootstrap, so it does not need to be there.

Existing violations go in a baseline file, as `phpstan-baseline.neon` does for PHPStan. An entry that no longer holds fails the check, so the baseline only shrinks.

The check does not select tests. Selection reads the declarations. The check keeps the declarations honest.

### Injection names a class

A resource becomes a class, and its key is that class's name:

```php
namespace Appwrite\Platform\Modules\Avatars\Resources;

final class ClientForAvatars
{
    public const array DEPENDS = [];

    public function __invoke(): Client
    {
        return new Client(/* ... */);
    }
}
```

```php
->inject(ClientForAvatars::class)
```

Three things follow.

- **The edge is in the source.** The action file imports the resource class, and the resource class imports what it constructs. Any static tool follows that: the checker in this RFC, PHPStan, an editor's "find usages". Nothing has to understand the container.
- **A resource lives with its owner.** `ClientForAvatars` sits in the Avatars module. Its files leave `shared`, and a change to them selects the Avatars suite. The nine resources everything uses stay in a small shared kernel.
- **The library does not change.** `Foo::class` is a string, so `Container::set()` and `Action::inject()` take it as they are. The change is a convention plus one helper that registers a resource class and reads `DEPENDS`. During the move the old name stays registered as an alias of the class, so the 4,569 call sites change by codemod, module by module, and Cloud's own injections keep working until it follows.

A module using another module's resource imports it by name. That import is a declared dependency between two modules, and the check can hold it to an allowed list. Modules that should not know each other talk through events, as they already do through `queueForEvents`.

### Crates declare what they implement

Each crate under `crates/utopia/` names its package in `Cargo.toml`:

```toml
[package.metadata.appwrite]
implements = "utopia-php/image"
```

`cargo metadata` prints it with the crate graph. `validate` fails when a package requires `ext-utopia` and no crate claims it, or a crate claims a package that does not. This replaces the package names hand-listed in `paths.extension`. That list and the code already disagree: `domains`, `emails` and `user-agent` require `ext-utopia` and are not in it.

### One command

```
bin/monorepo affected [--base=<ref>] [--format=json]
```

It reads the diff and the three graphs and prints the package jobs, the suites, the database matrix, and whether the extension and console jobs run. For each selected suite it prints the file and the edge that selected it, so a surprising run explains itself in the job log.

`changes.mjs` and the shell in the `packages` job that computes its matrix go away.

### What no graph can place

A `Dockerfile` or workflow change reaches every suite and nothing in particular. A rule decides those:

- On a pull request, a change that only touches `Dockerfile`, `.github/` or a lockfile runs a smoke set: one suite per runner class.
- The merge queue runs every suite for them. `ci.yml` already handles `merge_group`.
- A push to `main` keeps running every suite on one database, as today. That run is the backstop for anything a selection missed.

The three-database, two-mode matrix moves to the merge queue as well. On a pull request it is the most expensive thing CI does, and three runs of it were 66% of the sample's E2E minutes.

## Alternatives considered

### Bazel

Bazel enforces a graph by sandboxing: an action sees only its declared inputs. For a PHP package's unit tests that is what `bin/monorepo test` already does through Composer. It cannot subdivide E2E, because the thing under test is the whole server image. To gain anything there the server must first be split into targets with declared dependencies, which is this RFC.

The costs are specific to this repository:

- No maintained general PHP ruleset turned up in a search. The PHP rules that exist generate protobuf code. The rules would be ours to write and keep.
- Composer stays, for the mirrors and for Cloud. `BUILD` files would repeat `composer.json` unless a generator is written too.
- The Dockerfile stays, for Cloud and for self-hosters.
- It is a public repository. Every outside contributor would need Bazel to run a test.

Once the graph in this RFC exists, Bazel is a replacement for `bin/monorepo affected` plus a cache. That is the point to decide, with a working graph to compare against.

### moon and Nx

Both run tasks over a project graph and have an `--affected` mode. Neither enforces that the graph is true. moon's documentation lists PHP as supported, with Composer coverage that looks partial. Nx has no first-party PHP support, only a community Composer plugin with one maintainer.

### Recorded coverage

Run every suite nightly with a coverage driver, record which files each suite executes on the server, and select by intersecting that with the diff. It sees through the container, because it observes what ran. It is the only route found here to a small core without changing how modules inject.

It is not in the phases because two things are unknown: whether a coverage driver works under Swoole's coroutines, and what it costs a nightly run. It needs a spike. If it works, its output is a second source for the check in `graph --check`, and a suite that executes a file it does not declare fails the same way.

## Phases

### Phase 0. Print the reason

`changes.mjs` logs which file made a run select everything. No behaviour changes. This gives a baseline to compare the later phases against, from CI's own logs.

### Phase 1. Make the map total

Write down `shared`. Assign the files that have no owner today: the fixture lockfiles go to Sites and Functions, package manifests and tests to the package jobs. A file with no owner fails `Checks`.

Done when: the full-suite fallback can only be reached through `shared`, `Dockerfile` or CI files.

### Phase 2. Check the map

`bin/monorepo graph --check` with a baseline. `validate` and the crate `implements` metadata.

Done when: the check runs in `Checks` on every pull request, and deleting a real path from a suite's `paths` turns it red.

### Phase 3. The rule for what no graph can place

The smoke set, and the full and three-database runs in the merge queue.

Done when: a Renovate digest bump of the base image runs the smoke set on its pull request and every suite in the queue.

### Phase 4. `bin/monorepo affected`

Port the selection from `changes.mjs`. Run both in CI for a week and diff their output before removing the old one.

### Phase 5. Shrink the core

Move resources to class keys, in this order:

1. The helper that registers a resource class, and the alias from each old name. No call site changes.
2. The 40 resources one unit injects move into that unit as classes. A Rector rule rewrites that unit's `->inject('name')` calls. One pull request per module.
3. The 33 that two or three units inject get an owner each; the others import it.
4. The 11 with no use in this repository are checked against Cloud, then deleted or moved there.
5. The nine everything injects move last, into the kernel, and the aliases go.

`graph --check` prints the size of `shared` on every run. Done when `app/init` defines only the kernel.

### Phase 6. Decide on a build tool

With the graph declared and checked, compare `bin/monorepo affected` against Bazel on this repository: what selecting costs to maintain, and whether a cache would pay for itself.

## Risks

- **A wrong declaration skips a suite that should have run.** The check catches a missing edge it can see. It cannot see string-keyed injection, which is why everything `app/init` constructs stays in `shared` until phase 5 moves it. The push to `main` runs every suite.
- **The baseline becomes permanent.** An entry that no longer holds fails the check, so it cannot grow stale, but nothing forces it to shrink.
- **The smoke set misses a base image regression on the pull request.** The merge queue runs every suite before it lands.
- **Cloud extends this repository and injects by the same names.** The aliases keep it working, and it has to finish its own move before they are removed. Its resources that override one defined here need a way to replace a class key, which the helper has to provide.
- **The numbers are a small sample.** 40 runs, with one `packages/abuse` run worth 21% of the E2E minutes. Phase 0 replaces them with CI's own.

## Open questions

- Does the declaration stay in one file, or move next to each module as a manifest? One file is simpler to check. A manifest is reviewed by the people who own the module.
- Write the checker in `bin/monorepo`, or configure [Deptrac](https://github.com/qossmic/deptrac)? Deptrac enforces declared layers and fails on anything undeclared, which is the model here. It has not been tried against this repository.
- PHPStan keeps a file dependency map in its result cache, and CI already runs PHPStan. Is that a sturdier source for the graph than a tokenizer?
- Does a resource declare its dependencies in a constant, as sketched, or does the helper read them from attributes on `__invoke`'s parameters? Types alone are not enough: `dbForProject` and `dbForPlatform` are both a `Database`.
- Request, worker-message and process scope are separate files today. Is scope an interface a resource class implements, or the namespace it lives in?
- Which suites make up the smoke set?
- Does the repository use the merge queue on every pull request, or only some? Phase 3 depends on it.
