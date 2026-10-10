# Crates

The documentation of the Utopia libraries in Rust, as a TanStack Start app in the Appwrite console's style. It is written to become the official Utopia docs: by default it shows only the Rust crates, their getting started guides, namespaces, items and examples.

**Migration** mode (the switch in the header) adds everything about the move from PHP, each piece in a yellow migration card: the libraries not converted yet and their PHP API, each library's conversion status and sync with PHP, the PHP twin of every example, the PHP method each item ports, and the PHP notes the generator moves out of the Rust docs (paragraphs that mention PHP, sections under a PHP heading, and a leading PHP name such as `` `getHost()`: ``). Libraries are grouped by category; inside one, a second menu lists its namespaces.

## Run

From the repository root, generate the data (PHP signatures are read in the `appwrite-dev` image; without Docker the docs are generated without them), then start the app:

```bash
bin/compat crates
cd apps/crates
bun install
bun run dev
```

The app serves on <http://localhost:3200>. `bun run generate` regenerates the data from here. `bun run build` then `bun run start` serves a production build. The data lands in `apps/crates/data` (not committed).

## Where the content comes from

Everything is generated from each library's own data and metadata; nothing is written in this app:

| Content | Source |
|---|---|
| Libraries | `packages/*`, the `utopia-php/*` packages in `composer.lock`, `crates/utopia/*` and `crates/support/*` |
| Category | `extra.utopia.category` in the package's `composer.json` (or `[package.metadata.utopia]` in a Rust-only crate's `Cargo.toml`); category titles and descriptions in [`crates/categories.json`](../../crates/categories.json) |
| Status | converted (`tests/compat/<lib>` is complete), in progress (a compat spec), started (a crate) or planned |
| Sync with PHP | git history of `packages/<lib>/src` since the commit in the crate's `php-sync` marker (below) |
| Getting started | `guide.md` next to the crate's `Cargo.toml` (below) |
| Overview | the crate's `//!` docs, else the PHP README |
| Namespaces and items | module and item doc comments, read with `syn` |
| PHP API | the package's public classes and methods by reflection |
| Examples | code blocks in doc comments (below) |

## Writing examples

Put an example in a doc comment as a Rust code block, and its PHP twin right after it:

````rust
/// `getHost()`.
///
/// ```
/// use utopia_dsn::Dsn;
///
/// let dsn = Dsn::parse("redis://cache:6379")?;
/// assert_eq!(dsn.host(), "cache");
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\DSN\DSN;
///
/// echo (new DSN('redis://cache:6379'))->getHost(), "\n"; // cache
/// ```
pub fn host(&self) -> &str
````

The Rust block is a doctest: `cargo test` compiles and runs it. The page shows it as written and copies the complete program (rustdoc's rules: `# ` lines hidden, wrapped in `fn main`). The PHP block gets `<?php` and Composer's autoloader when it lacks them. `bin/compat examples` runs both; CI runs it too, so every example a reader copies runs.

## Writing a getting started guide

A converted library gets a **Getting started** section from `guide.md` next to its `Cargo.toml`: a short introduction, then one `## ` section per task ("Read options from the query string"), each with a sentence of context, a Rust example and its PHP twin, written exactly as above. The page keeps the guide's order and lists its sections under "On this page".

The crate compiles the guide into its doctests, so `cargo test` runs every Rust example in it:

```rust
/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
```

`bin/compat examples` runs the guide's PHP examples with the rest. See [`crates/utopia/dsn/guide.md`](../../crates/utopia/dsn/guide.md).

## Keeping up with PHP

The PHP libraries keep changing while they are ported. Each crate records the PHP commit it last matched as `php-sync` in its `Cargo.toml`:

```toml
[package.metadata.utopia]
php-sync = "b85cf6c95b416fbf393216a07e16d48c162cac1c"
```

On every generation, each library page shows how far the crate is behind:
- the PHP commits to `packages/<lib>/src` since the sync point, with authors and links;
- the size of the change, and the releases tagged since;
- commits to the library on `origin/main` that this branch has not merged yet, as last fetched.

The home page's **Behind PHP** filter lists the crates with something to catch up on. A crate without a marker is measured from when it was created, and marked as an estimate.

After porting the changes, record the new sync point:

```bash
bin/compat sync <lib>
```

It first runs the library's compat cases and refuses to record unless they all match. A crate without compat cases needs `--force`.
