# utopia-compression

Compression algorithms and `Accept-Encoding` negotiation. Rust port of [utopia-php/compression](https://github.com/utopia-php/compression).

## Install

```toml
utopia-compression = { path = "../utopia-compression" } # workspace
```

## Features

All algorithms are enabled by default:

| Feature | Algorithm |
|---------|-----------|
| `gzip` | Gzip (`flate2`) |
| `deflate` | Raw deflate (`flate2`) |
| `brotli` | Brotli (`brotli`) |
| `zstd` | Zstandard (`zstd`) |

Disable defaults and pick only what you need:

```toml
utopia-compression = { path = "../utopia-compression", default-features = false, features = ["gzip", "brotli"] }
```

When a feature is disabled, `is_supported()` is `false` and `compress` / `decompress` return `CompressionError::Unsupported`.

## Usage

```rust
use utopia_compression::{Compression, GZIP};

let payload = b"hello, utopia!";

let gzip = Compression::Gzip;
let compressed = gzip.compress(payload)?;
let plain = gzip.decompress(&compressed)?;
assert_eq!(plain, payload);

// Negotiate from an Accept-Encoding header
let negotiated = Compression::from_accept_encoding("gzip, deflate, br;q=0.8");
assert_eq!(negotiated, Some(Compression::Gzip));

// Brotli uses the `br` content-encoding token
let brotli = Compression::brotli();
assert_eq!(brotli.content_encoding(), "br");

// Tune brotli / zstd levels
let mut zstd = Compression::zstd();
zstd.set_zstd_level(10)?;
```

## Prelude

```rust
use utopia_compression::prelude::*;
// Compression, CompressionError
```

Name constants (`GZIP`, etc.) are **not** in prelude - import from the crate root.

## API Reference

### Name constants

```rust
pub const NONE: &str = "none";
pub const IDENTITY: &str = "identity";  // deprecated alias for none; still recognized
pub const BROTLI: &str = "brotli";
pub const DEFLATE: &str = "deflate";
pub const GZIP: &str = "gzip";
pub const ZSTD: &str = "zstd";
```

### `Compression`

```rust
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Compression {
    None,
    Gzip,
    Deflate,
    Brotli { level: u32 },
    Zstd { level: i32 },
}

impl Default for Compression {
    fn default() -> Self; // None
}
```

| Variant | Meaning |
|---------|---------|
| `None` | Passthrough (copy bytes) |
| `Gzip` | Gzip (default flate2 level) |
| `Deflate` | Raw deflate (zlib wrapper-free) |
| `Brotli { level }` | Brotli quality 0–11 (default **11**) |
| `Zstd { level }` | Zstd level 1–22 (default **3**) |

#### Constructors

| Method | Signature | Description |
|--------|-----------|-------------|
| `from_name` | `fn from_name(name: &str) -> Option<Self>` | Trim + lowercase. Recognizes `brotli`/`br`, `deflate`, `gzip`, `zstd`. Returns `None` for unknown names **and** for `none` / `identity` (PHP parity). |
| `brotli` | `fn brotli() -> Self` | `Brotli { level: 11 }` when feature on. |
| `zstd` | `fn zstd() -> Self` | `Zstd { level: 3 }` when feature on. |

#### Accept-Encoding negotiation

| Method | Signature | Description |
|--------|-----------|-------------|
| `from_accept_encoding` | `fn from_accept_encoding(accept_encoding: &str) -> Option<Self>` | Negotiate with default supported map. |
| `from_accept_encoding_with_supported` | `fn from_accept_encoding_with_supported(accept_encoding: &str, supported: Option<&[&str]>) -> Option<Self>` | Same rules with an optional allowlist. |

**Rules**

1. Empty or `"0"` → `None`.
2. Split on `,`; each token is `encoding[;q=…]`.
3. `br` normalized to `brotli`.
4. Missing `q` → `1.0`; bad `q` → `0.0`.
5. Keep encodings present in the supported map.
6. Sort by quality descending, then original index ascending.
7. Map winner via `from_name` (so `none`/`identity` as winner → `None`).

**Default supported map** (`supported == None`): `zstd` / `brotli` / `gzip` / `deflate` if their Cargo features are on; `none` and `identity` always. Explicit `supported` list treats every listed name as supported regardless of features.

#### Introspection

| Method | Description |
|--------|-------------|
| `name(&self) -> &'static str` | Canonical: `none` / `gzip` / `deflate` / `brotli` / `zstd` |
| `content_encoding(&self) -> &'static str` | Header token; Brotli → **`"br"`** |
| `is_supported(&self) -> bool` | `None` always true; others gated by features |
| `brotli_level` / `zstd_level` | `Some(level)` for matching variant |

#### Level setters

| Method | Valid range | Side effect |
|--------|-------------|-------------|
| `set_brotli_level(&mut self, level: u32)` | **0–11** | Updates `Brotli` level, or replaces `*self` with `Brotli { level }` |
| `set_zstd_level(&mut self, level: i32)` | **1–22** | Same for `Zstd` |

Out of range → `CompressionError::InvalidLevel { min, max }`.

#### Compress / decompress

```rust
pub fn compress(&self, data: &[u8]) -> Result<Vec<u8>, CompressionError>;
pub fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, CompressionError>;
```

| Variant | Compress | Decompress |
|---------|----------|------------|
| `None` | `data.to_vec()` | `data.to_vec()` |
| `Gzip` | flate2 `GzEncoder` | `GzDecoder` |
| `Deflate` | flate2 `DeflateEncoder` | `DeflateDecoder` |
| `Brotli` | brotli quality = `level` | `BrotliDecompress` |
| `Zstd` | `zstd::bulk::compress` | `zstd::decode_all` |

### `CompressionError`

```rust
#[derive(Debug, thiserror::Error)]
pub enum CompressionError {
    Compress(String),
    Decompress(String),
    InvalidLevel { min: i32, max: i32 },
    Unsupported(&'static str),
}
```

| Variant | When |
|---------|------|
| `Compress` / `Decompress` | Codec failure |
| `InvalidLevel` | Level outside brotli 0–11 or zstd 1–22 |
| `Unsupported` | Algorithm feature disabled |

## Tests

```bash
cargo test -p utopia-compression
```

## Benchmarks

```bash
cargo bench -p utopia-compression
```

## Code quality

This crate inherits workspace linting:

- **rustfmt** - `cargo fmt -p <crate>` (config: repo-root `rustfmt.toml`)
- **Clippy + rustc lints** - `cargo clippy -p <crate> --all-targets -- -D warnings` (config: `clippy.toml`, `[workspace.lints]`)
- **Docs** - `cargo doc -p <crate> --no-deps` (`RUSTDOCFLAGS=-Dwarnings` in CI)
- **Supply chain** - `cargo deny check` (config: `deny.toml`)
