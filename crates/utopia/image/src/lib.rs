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
//! | `Image` | [`Image`] |
//! | `new Image($data)` | [`Image::new`] |
//! | `Format` (`detect()`, `->value`) | [`Format`] ([`Format::detect`], [`Format::value`]) |
//! | `Image::GRAVITY_*`, `getGravityTypes()` | [`Gravity`], [`Gravity::ALL`] |
//! | `crop($width, $height, $gravity, $x, $y)` | [`Image::crop`] with [`FocalPoint`] |
//! | `setBorder($width, $color)` | [`Image::set_border`] |
//! | `setBorderRadius($radius)` | [`Image::set_border_radius`] |
//! | `setOpacity($opacity)` | [`Image::set_opacity`] |
//! | `setRotation($degree)` | [`Image::set_rotation`] |
//! | `setBackground($color)` | [`Image::set_background`] |
//! | `output($type, $quality)` | [`Image::output`] with [`OutputFormat`] |
//! | `save($path, $type, $quality)` | [`Image::save`] |
//! | `getSizeByFixedHeight()`, `getSizeByFixedWidth()` | [`Image::size_by_fixed_height`], [`Image::size_by_fixed_width`] |
//! | `Image::setResourceLimit($type, $value)` | [`set_resource_limit`] with [`Resource`] |
//! | colour strings (`ImagickPixel`) | [`Color`] |
//! | `Exception\ImageException` and its `UnsupportedFormatException`, `CorruptImageException` and `LimitExceededException`; `InvalidArgumentException`, `DivisionByZeroError` | [`Error`] |

#![warn(missing_docs)]

mod color;
mod decode;
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

pub use crate::color::Color;
pub use crate::error::{Error, Result};
pub use crate::format::Format;
pub use crate::image::Image;
pub use crate::options::{FocalPoint, Gravity, MAX_DIMENSION, OutputFormat, Resource, set_resource_limit};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
