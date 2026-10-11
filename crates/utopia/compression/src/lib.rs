//! Compression algorithms behind one [`Algorithm`] trait: [`Brotli`],
//! [`Deflate`], [`Gzip`], [`Lz4`], [`Snappy`], [`Xz`] and [`Zstd`], and the
//! choice of one from a name or an HTTP `Accept-Encoding` header
//! ([`Compression::from_accept_encoding`]).
//!
//! Every algorithm writes the bytes PHP's extension writes at the same level
//! (except Brotli at qualities 2 to 11, see [`Brotli`]), so data compressed
//! by either runtime is read by the other, and responses are identical.
//!
//! ```
//! use utopia_compression::{Algorithm, Compression};
//!
//! let algorithm = Compression::from_accept_encoding("gzip;q=0.8, br", &[] as &[&str]).unwrap();
//! assert_eq!(algorithm.content_encoding(), "br");
//!
//! let body = algorithm.compress(b"Hello, Hello, Hello")?;
//! assert_eq!(algorithm.decompress(&body)?, b"Hello, Hello, Hello");
//! # Ok::<(), utopia_compression::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Compression\Compression;
//!
//! $algorithm = Compression::fromAcceptEncoding('gzip;q=0.8, br');
//! echo $algorithm->getContentEncoding(), "\n"; // br
//!
//! $body = $algorithm->compress('Hello, Hello, Hello');
//! echo $algorithm->decompress($body), "\n"; // Hello, Hello, Hello
//! ```
//!
//! Compressing is CPU work that grows with the input: in async code, run
//! large inputs on the blocking pool (`tokio::task::spawn_blocking`) so they
//! do not hold up a runtime thread.
//!
//! ## Cargo features
//!
//! Each algorithm that needs a codec library is behind a feature, as each
//! needs an extension in PHP: `brotli`, `lz4`, `zlib` (Deflate and GZIP),
//! `zstd` (all on by default) and `xz` (off). Without its feature an
//! algorithm reports [`Algorithm::supported`] `false` and fails with
//! [`Error::Unsupported`], like PHP without the extension. [`Snappy`] is pure
//! Rust and always available.
//!
//! PHP: ports `utopia-php/compression`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Compression\Compression` (abstract class) | [`Algorithm`] (trait), and [`Compression`] for the built-in algorithms |
//! | `Compression::NONE`, `IDENTITY`, `BROTLI`, ... | [`Compression::NONE`], [`Compression::IDENTITY`], [`Compression::BROTLI`], ... |
//! | `Compression::fromName()` | [`Compression::from_name`] |
//! | `Compression::fromAcceptEncoding()` | [`Compression::from_accept_encoding`], [`Compression::from_accept_encoding_with`] (a `name => bool` map) |
//! | `getName()`, `getContentEncoding()` | [`Algorithm::name`], [`Algorithm::content_encoding`] |
//! | `compress()`, `decompress()` | [`Algorithm::compress`], [`Algorithm::decompress`] |
//! | `isSupported()` (static) | `is_supported()` on each algorithm, and [`Algorithm::supported`] |
//! | `getLevel()`, `setLevel()` | `level()`, `set_level()` on [`Brotli`], [`Lz4`] and [`Zstd`] |
//! | `new LZ4($level)`, `new Zstd($level)` | [`Lz4::with_level`], [`Zstd::with_level`] |
//! | `Brotli::useGenericMode()`, `useTextMode()`, `useFontMode()` | [`Brotli::use_generic_mode`], [`Brotli::use_text_mode`], [`Brotli::use_font_mode`] |
//! | `Algorithms\Brotli`, `Deflate`, `GZIP`, `LZ4`, `Snappy`, `XZ`, `Zstd` | [`Brotli`], [`Deflate`], [`Gzip`], [`Lz4`], [`Snappy`], [`Xz`], [`Zstd`] |
//! | `\InvalidArgumentException`, a warning and `""`, `\Error` | [`Error::InvalidArgument`], [`Error::Codec`], [`Error::Unsupported`] |
//!
//! PHP: where an extension emits a warning and returns `false` (invalid
//! data, a level the codec refuses), the PHP method returns an empty string;
//! Rust returns [`Error::Codec`] with the warning's text.

#![warn(missing_docs)]

mod brotli;
mod error;
mod lz4;
mod negotiate;
mod snappy;
mod xz;
mod zlib;
mod zstd;

use std::borrow::Cow;

pub use brotli::{Brotli, Mode};
pub use error::Error;
pub use lz4::Lz4;
pub use snappy::Snappy;
pub use xz::Xz;
pub use zlib::{Deflate, Gzip};
pub use zstd::Zstd;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A compression algorithm: a name, a `Content-Encoding` token, and a pair
/// of inverse transformations.
///
/// Implement it to add an algorithm; [`Compression`] holds the built-in ones.
///
/// ```
/// use utopia_compression::{Algorithm, Error};
///
/// /// Stores data as is.
/// struct Store;
///
/// impl Algorithm for Store {
///     fn name(&self) -> &str {
///         "Store"
///     }
///     fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
///         Ok(data.to_vec())
///     }
///     fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
///         Ok(data.to_vec())
///     }
///     fn supported(&self) -> bool {
///         true
///     }
/// }
///
/// assert_eq!(Store.content_encoding(), "store");
/// ```
///
/// ```php
/// use Utopia\Compression\Compression;
///
/// class Store extends Compression
/// {
///     public function getName(): string { return 'Store'; }
///     public function compress(string $data): string { return $data; }
///     public function decompress(string $data): string { return $data; }
///     public static function isSupported(): bool { return true; }
/// }
///
/// echo (new Store())->getContentEncoding(), "\n"; // store
/// ```
pub trait Algorithm {
    /// The algorithm's name, such as `gzip`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The token of the algorithm in `Content-Encoding` and
    /// `Accept-Encoding` headers: by default the name in lower case (ASCII
    /// only); `br` for Brotli.
    ///
    /// PHP: `getContentEncoding()`, `strtolower($this->getName())` by default.
    fn content_encoding(&self) -> Cow<'_, str> {
        let name = self.name();
        match php_std::string::strtolower(name.as_bytes()) {
            Cow::Borrowed(_) => Cow::Borrowed(name),
            // ASCII case mapping keeps UTF-8 valid.
            Cow::Owned(lower) => Cow::Owned(String::from_utf8_lossy(&lower).into_owned()),
        }
    }

    /// Compresses `data`.
    ///
    /// PHP: `compress($data)`.
    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error>;

    /// Decompresses `data`. Fails with [`Error::Codec`] when `data` is not a
    /// valid stream of the algorithm.
    ///
    /// PHP: `decompress($data)`.
    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error>;

    /// Whether this build can run the algorithm.
    ///
    /// PHP: `static::isSupported()`, whether the extension is loaded.
    fn supported(&self) -> bool;
}

/// A built-in algorithm, as chosen by name or from an `Accept-Encoding`
/// header.
///
/// ```
/// use utopia_compression::{Algorithm, Compression, Zstd};
///
/// assert_eq!(Compression::from_name("ZSTD"), Some(Compression::Zstd(Zstd::new())));
/// assert_eq!(Compression::from_name("br").unwrap().name(), "brotli");
/// assert_eq!(Compression::from_name("identity"), None);
/// ```
///
/// ```php
/// use Utopia\Compression\Compression;
///
/// var_dump(Compression::fromName('ZSTD') instanceof Utopia\Compression\Algorithms\Zstd); // bool(true)
/// echo Compression::fromName('br')->getName(), "\n"; // brotli
/// var_dump(Compression::fromName('identity')); // NULL
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Compression {
    /// Brotli (`br`).
    Brotli(Brotli),
    /// Raw deflate (`deflate`).
    Deflate(Deflate),
    /// Gzip (`gzip`).
    Gzip(Gzip),
    /// LZ4 (`lz4`).
    Lz4(Lz4),
    /// Snappy (`snappy`).
    Snappy(Snappy),
    /// XZ (`xz`).
    Xz(Xz),
    /// Zstandard (`zstd`).
    Zstd(Zstd),
}

impl Compression {
    /// No compression. PHP: `Compression::NONE`.
    pub const NONE: &'static str = "none";
    /// No compression, the HTTP token. PHP: `Compression::IDENTITY` (deprecated there).
    pub const IDENTITY: &'static str = "identity";
    /// PHP: `Compression::BROTLI`.
    pub const BROTLI: &'static str = "brotli";
    /// PHP: `Compression::DEFLATE`.
    pub const DEFLATE: &'static str = "deflate";
    /// PHP: `Compression::GZIP`.
    pub const GZIP: &'static str = "gzip";
    /// PHP: `Compression::LZ4`.
    pub const LZ4: &'static str = "lz4";
    /// PHP: `Compression::SNAPPY`.
    pub const SNAPPY: &'static str = "snappy";
    /// PHP: `Compression::XZ`.
    pub const XZ: &'static str = "xz";
    /// PHP: `Compression::ZSTD`.
    pub const ZSTD: &'static str = "zstd";

    /// The algorithm named `name` (ASCII case-insensitive; `br` is Brotli),
    /// at its default level. `None` for `none`, `identity` and unknown names.
    ///
    /// PHP: `Compression::fromName($name)`.
    pub fn from_name(name: &str) -> Option<Self> {
        Some(match php_std::string::strtolower(name.as_bytes()).as_ref() {
            b"brotli" | b"br" => Compression::Brotli(Brotli::new()),
            b"deflate" => Compression::Deflate(Deflate::new()),
            b"gzip" => Compression::Gzip(Gzip::new()),
            b"lz4" => Compression::Lz4(Lz4::new()),
            b"snappy" => Compression::Snappy(Snappy::new()),
            b"xz" => Compression::Xz(Xz::new()),
            b"zstd" => Compression::Zstd(Zstd::new()),
            _ => return None,
        })
    }

    /// The algorithm to answer an `Accept-Encoding` header with: of the
    /// encodings the header lists that are in `supported`, the one with the
    /// highest `q` (the first listed on a tie), or `None` when there is
    /// none, or when it is `none` or `identity` (no compression).
    ///
    /// An empty `supported` means [`Compression::is_default_supported`]:
    /// `zstd`, `brotli`, `gzip` and `deflate` when this build supports them,
    /// plus `none` and `identity`. Names in `supported` are canonical (`brotli`,
    /// not `br`).
    ///
    /// ```
    /// use utopia_compression::{Algorithm, Compression};
    ///
    /// let chosen = Compression::from_accept_encoding("gzip;q=0.5, br;q=0.5", &["gzip", "brotli"]);
    /// assert_eq!(chosen.unwrap().name(), "gzip");
    ///
    /// let chosen = Compression::from_accept_encoding("snappy;q=0.6, br;q=0.4", &["snappy"]);
    /// assert_eq!(chosen.unwrap().name(), "snappy");
    ///
    /// assert_eq!(Compression::from_accept_encoding("identity, gzip", &[] as &[&str]), None);
    /// ```
    ///
    /// ```php
    /// use Utopia\Compression\Compression;
    ///
    /// echo Compression::fromAcceptEncoding('gzip;q=0.5, br;q=0.5', ['gzip', 'brotli'])->getName(), "\n"; // gzip
    /// echo Compression::fromAcceptEncoding('snappy;q=0.6, br;q=0.4', ['snappy'])->getName(), "\n"; // snappy
    /// var_dump(Compression::fromAcceptEncoding('identity, gzip')); // NULL
    /// ```
    ///
    /// PHP: `Compression::fromAcceptEncoding($acceptEncoding, $supported)` with
    /// a list of names.
    pub fn from_accept_encoding<S: AsRef<str>>(accept: &str, supported: &[S]) -> Option<Self> {
        if supported.is_empty() {
            negotiate::choose(accept, Self::is_default_supported)
        } else {
            negotiate::choose(accept, |name| supported.iter().any(|s| s.as_ref() == name))
        }
    }

    /// [`Compression::from_accept_encoding`] with the supported encodings
    /// given by a predicate on their canonical name.
    ///
    /// PHP: `Compression::fromAcceptEncoding($acceptEncoding, $supported)`
    /// with a `name => bool` map (`fn(name) => $supported[$name] ?? false`).
    pub fn from_accept_encoding_with(accept: &str, supported: impl Fn(&str) -> bool) -> Option<Self> {
        negotiate::choose(accept, supported)
    }

    /// Whether `name` is offered when no supported list is given: `zstd`,
    /// `brotli`, `gzip` and `deflate` when this build supports them, and
    /// `none` and `identity`.
    ///
    /// PHP: the default `$supported` of `fromAcceptEncoding()`.
    pub fn is_default_supported(name: &str) -> bool {
        match name {
            Self::ZSTD => Zstd::is_supported(),
            Self::BROTLI => Brotli::is_supported(),
            Self::GZIP => Gzip::is_supported(),
            Self::DEFLATE => Deflate::is_supported(),
            Self::NONE | Self::IDENTITY => true,
            _ => false,
        }
    }

    fn algorithm(&self) -> &dyn Algorithm {
        match self {
            Compression::Brotli(a) => a,
            Compression::Deflate(a) => a,
            Compression::Gzip(a) => a,
            Compression::Lz4(a) => a,
            Compression::Snappy(a) => a,
            Compression::Xz(a) => a,
            Compression::Zstd(a) => a,
        }
    }
}

impl Algorithm for Compression {
    fn name(&self) -> &str {
        self.algorithm().name()
    }

    fn content_encoding(&self) -> Cow<'_, str> {
        self.algorithm().content_encoding()
    }

    fn compress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        match self {
            Compression::Brotli(a) => a.compress(data),
            Compression::Deflate(a) => a.compress(data),
            Compression::Gzip(a) => a.compress(data),
            Compression::Lz4(a) => a.compress(data),
            Compression::Snappy(a) => a.compress(data),
            Compression::Xz(a) => a.compress(data),
            Compression::Zstd(a) => a.compress(data),
        }
    }

    fn decompress(&self, data: &[u8]) -> Result<Vec<u8>, Error> {
        match self {
            Compression::Brotli(a) => a.decompress(data),
            Compression::Deflate(a) => a.decompress(data),
            Compression::Gzip(a) => a.decompress(data),
            Compression::Lz4(a) => a.decompress(data),
            Compression::Snappy(a) => a.decompress(data),
            Compression::Xz(a) => a.decompress(data),
            Compression::Zstd(a) => a.decompress(data),
        }
    }

    fn supported(&self) -> bool {
        self.algorithm().supported()
    }
}
