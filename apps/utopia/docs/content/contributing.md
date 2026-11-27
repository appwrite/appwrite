## Set up

The crates live in [appwrite/appwrite](https://github.com/appwrite/appwrite), one per directory under `crates/utopia/<name>`, and are published to crates.io as `utopia-<name>`. Open issues and pull requests against appwrite/appwrite. Run every command on this page from the root of a checkout.

`rust-toolchain.toml` pins the Rust version CI builds and lints with; rustup picks it up on the first `cargo` command.

```bash
git clone https://github.com/appwrite/appwrite.git
cd appwrite
cargo build --workspace
```

A crate's README and its page on this site both start from the `guide.md` next to its `Cargo.toml`. The guide is compiled and run with the crate's doctests, so its examples are tests too.

## Test

Before you open a pull request, run the checks CI runs on the whole workspace:

```bash
cargo fmt --all --check
cargo clippy --workspace --all-targets --all-features --locked -- -D warnings
cargo test --locked --workspace --exclude utopia-extension --all-features
```

While you work on one crate, run them for that crate only:

```bash
cargo test -p utopia-dsn
cargo test -p utopia-dsn --doc
cargo clippy -p utopia-dsn --all-targets -- -D warnings
```

`--doc` runs the guide and the doc examples.

## Serve a crate to PHP

Appwrite runs the crates through its PHP extension, `crates/extension`: each crate is registered as the PHP classes it replaces, and those classes' PHP source is removed from `packages/<name>`. The package's own PHP tests then run against Rust, so they are the parity test.

A library goes through two gates in `crates/extension/src/lib.rs`:

- `enabled("<name>")`: the crate serves the classes only when the library is switched on, through `utopia.libraries` in the ini or `_APP_EXTENSION_LIBRARIES`. The PHP source stays to fall back to.
- `stable("<name>")`: the crate always serves the classes, and their PHP source is deleted.

`crates/extension/tests/libraries.expected` lists every class the extension registers by default. The Appwrite image builds and loads the extension; run its tests there:

```bash
docker compose build appwrite
docker compose exec appwrite php --ri utopia
docker compose exec appwrite crates/extension/tests/run.sh
```

`run.sh` runs each switchable library's PHP tests with Rust on, then its parity corpus (`tests/<name>.php`) on PHP and on Rust and diffs the two. The [extension's README](https://github.com/appwrite/appwrite/blob/main/crates/extension/README.md) covers loading it outside Appwrite.

## Work on this site

This site lives in `apps/utopia/docs`, a TanStack Start app. Everything on it except this guide, the categories and the changelog is generated from the repository and the crates' docs on docs.rs into `apps/utopia/docs/data`, which is not committed:

```bash
cd apps/utopia/docs
bun install
bun run generate
bun run dev
```

The app serves on <http://localhost:3200>. `bun run generate` (`scripts/generate.ts`) reads each crate's `Cargo.toml`, downloads each crate's rustdoc JSON from docs.rs, and scans the extension, the PHP packages and the Appwrite server. Run it again after a merge; the live site rebuilds itself on each merge to `main`.

To change what a page says, change its source, not the app:

| On the site | Where it comes from |
|---|---|
| A library's overview | the crate's `//!` docs in `src/lib.rs`, as published to docs.rs |
| Getting started | `guide.md` next to the crate's `Cargo.toml`: one `## ` section per task, each with a Rust example and its PHP twin |
| Modules and items | the module and item doc comments, as published to docs.rs |
| Status | the library's gate in `crates/extension/src/lib.rs`, and whether its package still has PHP classes |
| Category | `[package.metadata.utopia]` in the crate's `Cargo.toml`, titles in `apps/utopia/docs/content/categories.json` |
| Changelog | one Markdown file per entry in `apps/utopia/docs/content/changelog/`, with `title`, `date`, an optional `tag` and comma-separated `packages` in its frontmatter |
| This page | `apps/utopia/docs/content/contributing.md`, one section per `## ` heading |

Before you open a pull request that touches the app, type-check and build it:

```bash
cd apps/utopia/docs
bun run check
bun run build
```

`bun run build` generates the data and renders each library's share card into `public/og/` (not committed) first.
