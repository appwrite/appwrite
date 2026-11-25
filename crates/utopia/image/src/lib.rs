//! Image previews: decode a JPEG, PNG, GIF or WebP (animations included),
//! crop it by gravity or focal point, add a border, round its corners, change
//! its opacity, rotate it, flatten it onto a background, and encode it as
//! JPEG, PNG, GIF, WebP or AVIF.
//!
//! ```
//! use utopia_image::{Gravity, Image, OutputFormat};
//!
//! let jpeg = std::fs::read("../../../packages/image/tests/resources/resize/100x100.jpg")?;
//! let mut image = Image::new(&jpeg)?;
//! image.crop(64, 64, Gravity::Center, None)?.set_border_radius(8)?;
//! let png = image.output(OutputFormat::Png, 90)?;
//! assert_eq!(&png[1..4], b"PNG");
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\Image\Image;
//!
//! $image = new Image(file_get_contents('/usr/src/code/packages/image/tests/resources/resize/100x100.jpg'));
//! $image->crop(64, 64)->setBorderRadius(8);
//! echo substr($image->output('png', 90), 1, 3), "\n"; // PNG
//! ```
//!
//! All the work is CPU-bound: in async code run it on the blocking pool
//! (`utopia_async::spawn_blocking`), never on a runtime thread.
//!
//! The codecs are pure Rust by default. Next to ImageMagick, which the PHP
//! library wraps, that means: WebP is written lossless (no pure-Rust lossy VP8
//! encoder exists), AVIF is written but not read, and HEIC is neither read
//! nor written. Two features add those through the C libraries ImageMagick
//! itself uses: `libwebp` writes lossy WebP at the requested quality, and
//! `libheif` reads AVIF and HEIC, writes AVIF as ImageMagick does, and writes
//! HEIC where the system's libheif has an HEVC encoder (Appwrite's PHP image
//! has none, so HEIC output fails there in PHP too). The pure-Rust AVIF
//! encoder is the default `ravif` feature; a process that forks, such as a
//! PHP extension under Swoole, leaves it out and writes AVIF through libheif. Pixels match ImageMagick's up to resampling and encoder
//! differences; sizes, frames, delays and formats match exactly.
//!
//! PHP → Rust:
//!
//! | PHP (`Utopia\Image`) | Rust (`utopia_image`) |
//! |---|---|
//! | `Canvas`: `new Canvas($data, $limits)`, `new Canvas(new Blank(...))` | [`Canvas`]: [`Canvas::decode`], [`Canvas::blank`] |
//! | `$canvas->width`, `->height`, `->orientation`, `->format` | [`Canvas::width`], [`Canvas::height`], [`Canvas::orientation`], [`Canvas::format`] |
//! | `apply(Transform)` | [`Canvas::apply`] with [`Transform`] |
//! | `draw(Path\|Text)` | [`Canvas::draw`] with [`Drawing`] |
//! | `encode(Format, ?int $quality)` | [`Canvas::encode`] with [`OutputFormat`] |
//! | `Limits` | [`Limits`] |
//! | `Gravity`, `Point` | [`Gravity`], [`FocalPoint`] |
//! | `Font::has($character)` | [`has_glyph`] |
//! | colour strings (`ImagickPixel`) | [`Color`] |
//! | `Exception\ImageException` and its `UnsupportedFormatException`, `CorruptImageException` and `LimitExceededException`; `InvalidArgumentException`, `DivisionByZeroError` | [`Error`] |

#![warn(missing_docs)]

mod canvas;
mod color;
mod decode;
mod draw;
mod encode;
mod error;
mod format;
mod frame;
#[cfg(feature = "libheif")]
mod heif;
mod image;
mod jpeg_source;
mod options;
mod transform;

pub use crate::canvas::{Canvas, Drawing, Transform};
pub use crate::color::Color;
pub use crate::draw::has_glyph;
pub use crate::error::{Error, Result};
pub use crate::format::Format;
pub use crate::image::Image;
pub use crate::options::{FocalPoint, Gravity, Limits, OutputFormat};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
