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

## Switching a class on

`Utopia\Image\Image` is always registered: `packages/image` has no PHP implementation left. Every
other class is registered only when `_APP_EXTENSION_LIBRARIES` names its package. Otherwise
Composer loads the package's PHP class as before:

```sh
_APP_EXTENSION_LIBRARIES=dsn   # Utopia\DSN\DSN from the extension; comma separate more
```

The extension reads it once, when PHP loads the module, so changing it takes a restart and no
deploy. Each library is canaried on Cloud this way before it becomes the default.

## Utopia\DSN\DSN

Over `crates/utopia/dsn` (`_APP_EXTENSION_LIBRARIES=dsn`). It declares the PHP class's protected
properties, so `==`, `var_dump()` and `(array)` see what they see in PHP. The DSN, `from()`'s user
and its password are `#[\SensitiveParameter]`, as in PHP, so exception traces never hold them.

`crates/extension/tests/dsn.php` prints a corpus of results (#14308's `tests/compat/dsn` cases, plus
edge cases) that must be identical on both implementations, and asserts these known differences on
the extension:

- A DSN that is not UTF-8 is refused as malformed, where `parse_url` takes any bytes.
- `from()` takes string parameters; PHP also round-trips a list value (`['a' => ['b', 'c']]`).
- `serialize()` refuses the object, as it does every class with Rust state.
- PHP fills the protected `$params` with the parsed query on the first `getParam()`, which then
  shows in `var_dump()` and `==`; it stays `null` here.
- A user, password or path that URL-decodes to bytes that are not UTF-8 (`%FF`) reads exactly
  through its getter and lossily through `var_dump()`.

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
