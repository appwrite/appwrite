# utopia PHP extension

Utopia crates registered as the PHP classes of their Composer packages. A class the
extension registers exists before any autoloader runs, so Composer never loads the
PHP one, and PHP code runs the Rust implementation unchanged. The rest of a package
(its other classes, its tests) still loads from `packages/<name>`.

```sh
cargo build --release -p utopia-extension
php -d extension="$PWD/target/release/libutopia.so" ...   # libutopia.dylib on macOS
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
built from source. What AVIF and HEIC can do follows the system's libheif, as it does
for ImageMagick: Appwrite's base image decodes both and has no HEVC encoder.

Known differences from ImageMagick: with no quality given (`-1`), WebP is written at
75, where ImageMagick reuses the quality it estimated from a JPEG source.

Not bound: the protected `getSizeByFixedHeight` and `getSizeByFixedWidth`. The class
cannot be cloned.

`crates/support/php-std` here holds only the functions `utopia-image` calls, copied
unchanged from the full crate on `feat/rust-utopia-conversion`.
