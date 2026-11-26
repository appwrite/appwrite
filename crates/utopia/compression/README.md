# Utopia Compression

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Compression algorithms (Brotli, Deflate, GZIP, LZ4, Snappy, XZ, Zstd) and Accept-Encoding negotiation, byte for byte as PHP's extensions.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-compression = { git = "https://github.com/appwrite/appwrite" }
```

This library compresses and decompresses data with seven algorithms behind one `Algorithm` trait: Brotli, Deflate, Gzip, LZ4, Snappy, XZ and Zstd. It also picks the algorithm to answer an HTTP request with, from the client's `Accept-Encoding` header. The output matches what PHP's extensions write, so either runtime reads what the other wrote. This guide covers the common tasks.

## Compress a response for the client

`Compression::from_accept_encoding` reads the header and returns the accepted algorithm with the highest `q` value, or `None` when the response should go out as is. Send the result with its `content_encoding()` as the `Content-Encoding` header.

```rust
use utopia_compression::{Algorithm, Compression};

let body = b"{\"users\": [], \"total\": 0}".repeat(100);

match Compression::from_accept_encoding("gzip;q=0.9, br, deflate;q=0.5", &[] as &[&str]) {
    Some(algorithm) => {
        let encoded = algorithm.compress(&body)?;
        assert_eq!(algorithm.content_encoding(), "br");
        assert!(encoded.len() < body.len());
    }
    None => unreachable!("Brotli is accepted"),
}
```

## Offer only some algorithms

Pass the names you offer. Clients' preferences decide among them, and the first one listed in the header wins a tie. `none` and `identity` mean no compression.

```rust
use utopia_compression::{Algorithm, Compression};

let chosen = Compression::from_accept_encoding("br;q=0.5, gzip;q=0.5", &["gzip", "brotli"]);
assert_eq!(chosen.unwrap().name(), "brotli");

assert!(Compression::from_accept_encoding("br", &["gzip"]).is_none());
```

## Choose an algorithm and its level

Each algorithm is a type you can build directly. Brotli, LZ4 and Zstd have levels: `set_level` checks the range and fails with `Error::InvalidArgument` outside it.

```rust
use utopia_compression::{Algorithm, Brotli, Error, Zstd};

let mut zstd = Zstd::new();
zstd.set_level(19)?;
let packed = zstd.compress(b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")?;
assert_eq!(zstd.decompress(&packed)?, b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");

let mut brotli = Brotli::new();
assert_eq!(brotli.set_level(12), Err(Error::InvalidArgument("Level must be between 0 and 11".into())));
```

## Write into your own buffer

Each algorithm's `compress_into` and `decompress_into` append to a `Sink`: a `Vec<u8>`, or a buffer type of your own that implements it, so the output is written once, where it ends up. The PHP extension writes straight into the PHP string it returns this way.

```rust
use utopia_compression::{Algorithm, Zstd};

let mut out = b"header:".to_vec();
Zstd::new().compress_into(b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", &mut out)?;
assert_eq!(Zstd::new().decompress(&out[7..])?, b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
```

## Handle data that is not compressed

Decompressing data that is not a valid stream fails with `Error::Codec`, whose message is the warning PHP's extension emits. PHP returns an empty string in that case.

```rust
use utopia_compression::{Algorithm, Error, Gzip};

assert_eq!(Gzip::new().decompress(b"plain text"), Err(Error::Codec("gzdecode(): data error".into())));
```

## Add your own algorithm

Implement `Algorithm` for a type. `content_encoding()` defaults to the name in lower case.

```rust
use utopia_compression::{Algorithm, Error};

/// Reverses the bytes: not compression, but an inverse pair.
struct Reverse;

impl Algorithm for Reverse {
    fn name(&self) -> &str {
        "Reverse"
    }
    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        Ok(data.iter().rev().copied().collect())
    }
    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        self.compress(data)
    }
    fn supported(&self) -> bool {
        true
    }
}

assert_eq!(Reverse.content_encoding(), "reverse");
assert_eq!(Reverse.decompress(&Reverse.compress(b"abc")?)?, b"abc");
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/compression`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/compression). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-compression
cargo clippy -p utopia-compression --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

The utopia PHP extension serves this crate as every class of `Utopia\Compression`, which no longer has a PHP implementation. `crates/extension/tests/run.sh` runs `packages/compression/tests` on it in the development image.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
