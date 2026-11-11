# utopia PHP extension

Utopia crates registered as the PHP classes of their Composer packages. A class the
extension registers exists before any autoloader runs, so Composer never loads the
PHP one, and PHP code runs the Rust implementation unchanged. The rest of a package
(its other classes, its tests) still loads from `packages/<name>`.

```sh
cargo build --release -p utopia-extension
php -d extension="$PWD/target/release/libutopia.so" ...   # libutopia.dylib on macOS
```

The Appwrite image builds and loads it only when asked; the default build compiles no Rust:

```sh
docker compose build --build-arg UTOPIA_EXTENSION=true
docker compose exec appwrite php --ri utopia
```

Built on [ext-php-rs](https://github.com/extphprs/ext-php-rs); it needs `php-config`
and libclang at build time.

## Utopia\Image\Image

Over `crates/utopia/image`, with the signatures of `packages/image/src/Image.php`.
An error is thrown as the class the crate names for it (`Error::php_class`); a class
PHP cannot find, such as `ImagickException` without ext-imagick, is an `\Exception`.

`crates/extension/tests/image.php` runs without Composer. The package's own suite is
the parity check: with the extension loaded, all 94 tests of `packages/image/tests` pass.

The extension turns on the crate's `libwebp` and `libheif` features, so it needs
libheif's headers to build (`libheif-dev`) and the library at run time; libwebp is
built from source. AVIF and HEIC follow the system's libheif, as they do for
ImageMagick: Appwrite's base image decodes both, encodes AVIF, and has no HEVC encoder
unless `libheif-x265` is installed (the `UTOPIA_EXTENSION=true` image installs it).

How it behaves inside a Swoole worker:

- **Errors.** An error is thrown as the class the crate names for it. With Composer
  loaded that is the package's own `UnsupportedFormatException`, and with ext-imagick
  `ImagickException`; `Utopia\Image\Format` stays the PHP enum.
- **Panics.** A panic in a Rust codec is caught and thrown as a PHP `Error` whose message
  starts with `Rust panic:`; the worker keeps running. The release profile must not set
  `panic = "abort"`.
- **Threads and forks.** The extension starts no thread pool. The pure-Rust AVIF encoder
  (the crate's default `ravif` feature) is left out: with its pool, a process that had
  encoded an AVIF and then forked left the child waiting forever on its next encode.

Known differences from ImageMagick: a greyscale JPEG source is written as a colour
JPEG (about 10% larger; ImageMagick writes one component). A negative size is refused
with ImageMagick's message for that operation, as the build in Appwrite's image words it.

Not bound: the protected `getSizeByFixedHeight` and `getSizeByFixedWidth`. The class
cannot be cloned.

`crates/support/php-std` here holds only the functions `utopia-image` calls, copied
unchanged from the full crate on `feat/rust-utopia-conversion`.
