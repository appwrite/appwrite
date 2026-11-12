# utopia PHP extension

Utopia crates registered as PHP classes. A class the extension registers has no PHP
source; the rest of its package (other classes, a stub for static analysis, tests)
stays in `packages/<name>`.

```sh
cargo build --release -p utopia-extension
php -d extension="$PWD/target/release/libutopia.so" ...   # libutopia.dylib on macOS
```

The Appwrite image builds and loads it:

```sh
docker compose exec appwrite php --ri utopia
```

Built on [ext-php-rs](https://github.com/extphprs/ext-php-rs); it needs `php-config`
and libclang at build time.

## Utopia\Image\Image

Over `crates/utopia/image`, with the signatures in `packages/image/stubs/Image.php`.

`crates/extension/tests/image.php` runs without Composer; `packages/image/tests` is the
full suite, and is skipped where the extension is not loaded.

Beyond previews, the class draws what Appwrite used ImageMagick for: `Image::create()` makes a
canvas, `path()` fills SVG path data and `text()` draws a line of text from a TrueType or OpenType
file (no kerning or shaping), which is how the fallback and initials avatars are made.
`Image::hasGlyph()` says whether a font draws a character.

The extension turns on the crate's `libwebp` and `libheif` features, so it needs
libheif's headers to build (`libheif-dev`) and the library at run time; libwebp is
built from source. AVIF and HEIC follow the system's libheif, as they do for
ImageMagick: Appwrite's base image decodes both, encodes AVIF, and has no HEVC encoder,
so HEIC output is unavailable unless `libheif-x265` (GPL-2.0-or-later) is installed.

How it behaves inside a Swoole worker:

- **Errors.** One hierarchy: `Exception\ImageException` and
  its `UnsupportedFormatException`, `CorruptImageException` and `LimitExceededException`
  for what an image or the system makes fail, `\InvalidArgumentException` for an argument
  that could never work. The classes load from the Composer package (without it they are
  `\Exception`), and `Utopia\Image\Format` stays the PHP enum.
- **Panics.** A panic in a Rust codec is caught and thrown as a PHP `Error` whose message
  starts with `Rust panic:`; the worker keeps running. The release profile must not set
  `panic = "abort"`.
- **Threads and forks.** The extension starts no thread pool. The pure-Rust AVIF encoder
  (the crate's default `ravif` feature) is left out: with its pool, a process that had
  encoded an AVIF and then forked left the child waiting forever on its next encode.

The class cannot be cloned.

`crates/support/php-std` here holds only the functions `utopia-image` calls, copied
unchanged from the full crate on `feat/rust-utopia-conversion`.
