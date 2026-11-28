# Utopia for Rust

The site of the Utopia libraries in Rust, and of Appwrite's move onto them: a TanStack Start app designed as a printed engineering catalogue (warm paper and ink, the wordmark's red dot as the one accent, Archivo titles, Geist text, Geist Mono code and labels, square corners and hairline rules; tokens in `src/styles.css`).

It has two jobs. The home page tells how far Appwrite has moved to Rust: how many Utopia libraries have a crate, how many PHP classes Appwrite runs from Rust, and Appwrite drawn as a circuit board of the crates its parts are built from (`src/components/Factory.tsx`). Each library carries a status: **Rust** (the extension serves it and no PHP is left), **Partly Rust**, **Rust, switchable** (served when switched on, PHP kept to fall back to) or **PHP**. The rest of the site is the home of the crates: each has a page with its getting started guide, overview, modules and items, and the PHP classes Appwrite runs from it.

The **Map** (`/map`) draws every library and Appwrite as a chord, grouped by category, with a ribbon for each dependency through Rust, PHP or both. The **Changelog** (`/changelog`) is one Markdown file per entry in `content/changelog/` (frontmatter: `title`, `date`, optional `tag`, comma-separated `packages`). **Contributing** (`/contributing`) is `content/contributing.md`, one section per `## ` heading.

## Share cards

Every page carries Open Graph and Twitter tags (`src/lib/share.ts`). The site-wide cover is `public/og.png`: the logo and the tagline, drawn by hand. Each library and category has its own card, with its category, title, crate, description and counts, rendered by `bun run og` (`scripts/og.tsx`: satori lays it out in the site's fonts from `scripts/og/fonts`, resvg rasterises it) into `public/og/`, which is not committed; `bun run build` runs it first. Image addresses are absolute, on the site's public address (`SITE` in `src/lib/share.ts`, <https://utopia-docs.appwrite.network> for now).

## Run

```bash
cd apps/utopia/docs
bun install
bun run generate
bun run dev
```

The app serves on <http://localhost:3200>. `bun run generate` (`scripts/generate.ts`) writes `data/` (not committed); `bun run build` runs it first, then `bun run start` serves the production build. It needs the whole repository and network access to docs.rs, not Rust; rustdoc JSON is cached in `node_modules/.cache/rustdoc`. A crate not yet on crates.io is left off the site until its release.

## Deploy

<https://utopia-docs.appwrite.network> is Appwrite Sites: project `utopia`, site `docs`, connected to this repository's `main`. Sites builds from the repository root, because the generator reads the whole repository: install `bun install --frozen-lockfile --cwd apps/utopia/docs`, build `bun run --cwd apps/utopia/docs build` (one shell runs both, so neither can `cd`), output `./apps/utopia/docs/dist/client`. A push to `main` that touches a source of the site deploys it.

## Where the content comes from

| Content | Source |
|---|---|
| Libraries | the crates in `crates/utopia/*` (their `Cargo.toml`), `packages/*`, and the `utopia-php/*` packages in `composer.lock` |
| Category | `[package.metadata.utopia]` in the crate's `Cargo.toml`, else `content/categories.json`, which also holds the category titles |
| Status | the library's `stable` or `enabled` gate in `crates/extension/src/lib.rs`, and whether its PHP package still declares classes |
| PHP classes served from Rust | `crates/extension/tests/libraries.expected`, matched to packages by their PSR-4 namespaces |
| Size | lines in the crate's `src/` and in the PHP package's `src/` |
| Overview, modules, items, examples | the crate's rustdoc JSON from docs.rs (`https://docs.rs/crate/<name>/<version>/json`) |
| Getting started | `guide.md` next to the crate's `Cargo.toml` |
| Dependencies and the map | the crates' manifests and `Cargo.lock`, the packages' `composer.json`, and the Appwrite server's imports in `app/` and `src/Appwrite` |
| The home page's board | each part of Appwrite (API modules, workers, schedulers, realtime, MQTT) with the libraries its files import |

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

The Rust block is a doctest: `cargo test` compiles and runs it. The page shows it as written and copies the complete program (rustdoc's rules: `# ` lines hidden, wrapped in `fn main`). The PHP block is shown beside it, as the call Appwrite makes; the extension answers it from the same crate.

## Writing a getting started guide

A crate gets a **Getting started** section from `guide.md` next to its `Cargo.toml`: a short introduction, then one `## ` section per task ("Read options from the query string"), each with a sentence of context, a Rust example and its PHP twin, written exactly as above. The page keeps the guide's order and lists its sections under "On this page". The crate compiles the guide into its doctests, so `cargo test` runs every Rust example in it:

```rust
/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
```

See [`crates/utopia/dsn/guide.md`](../../../crates/utopia/dsn/guide.md).
