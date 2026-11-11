//! The raster formats an image may be read from, told apart by signature.

use crate::error::{Error, Result};

/// A raster format [`Image::new`](crate::Image::new) accepts, identified
/// from the file signature, so only decoders for these formats ever see the
/// data.
///
/// ```
/// use utopia_image::Format;
///
/// assert_eq!(Format::detect(b"\x89PNG\r\n\x1a\n")?, Format::Png);
/// assert_eq!(Format::Png.value(), "PNG");
/// assert!(Format::detect(b"P3\n1 1\n255\n").is_err());
/// # Ok::<(), utopia_image::Error>(())
/// ```
///
/// ```php
/// use Utopia\Image\Format;
///
/// echo Format::detect("\x89PNG\r\n\x1A\n")->value, "\n"; // PNG
/// try {
///     Format::detect("P3\n1 1\n255\n");
/// } catch (\Utopia\Image\Exception\UnsupportedFormatException $e) {
///     echo $e->getMessage(), "\n";
/// }
/// ```
///
/// PHP: `Utopia\Image\Format` (a string-backed enum).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Format {
    /// PHP: `Format::Jpeg` (`JPEG`).
    Jpeg,
    /// PHP: `Format::Png` (`PNG`).
    Png,
    /// PHP: `Format::Gif` (`GIF`).
    Gif,
    /// PHP: `Format::Webp` (`WEBP`).
    Webp,
    /// PHP: `Format::Avif` (`AVIF`).
    Avif,
    /// PHP: `Format::Heic` (`HEIC`).
    Heic,
}

const AVIF_BRANDS: [&[u8]; 2] = [b"avif", b"avis"];

const HEIC_BRANDS: [&[u8]; 10] =
    [b"heic", b"heix", b"hevc", b"hevx", b"heim", b"heis", b"hevm", b"hevs", b"mif1", b"msf1"];

const HEADER_LENGTH: i64 = 8;

/// A box size of 1 means a 64-bit size follows the box type, so the brands
/// start after it.
const EXTENDED_SIZE: i64 = 1;

const EXTENDED_HEADER_LENGTH: i64 = 16;

const BRAND_LENGTH: i64 = 4;

/// Upper bound on the bytes of a file type box that are inspected; real
/// boxes hold a handful of brands.
const FILE_TYPE_BOX_LIMIT: i64 = 4096;

impl Format {
    /// The enum's backing value, ImageMagick's name for the format (`JPEG`,
    /// `PNG`, `GIF`, `WEBP`, `AVIF`, `HEIC`).
    ///
    /// PHP: `$format->value`.
    pub fn value(self) -> &'static str {
        match self {
            Format::Jpeg => "JPEG",
            Format::Png => "PNG",
            Format::Gif => "GIF",
            Format::Webp => "WEBP",
            Format::Avif => "AVIF",
            Format::Heic => "HEIC",
        }
    }

    /// The format the data's signature announces: JPEG, PNG, GIF87a/89a,
    /// a RIFF WebP, or an ISO base media file whose file type box lists an
    /// AVIF brand (AVIF) or a HEIF brand (HEIC), among its first 4096
    /// bytes.
    ///
    /// Fails with [`Error::UnsupportedFormat`] for anything else.
    ///
    /// PHP: `Format::detect($data)`.
    pub fn detect(data: &[u8]) -> Result<Format> {
        if data.starts_with(&[0xFF, 0xD8, 0xFF]) {
            Ok(Format::Jpeg)
        } else if data.starts_with(b"\x89PNG\r\n\x1a\n") {
            Ok(Format::Png)
        } else if data.starts_with(b"GIF87a") || data.starts_with(b"GIF89a") {
            Ok(Format::Gif)
        } else if data.starts_with(b"RIFF") && data.get(8..12) == Some(b"WEBP") {
            Ok(Format::Webp)
        } else if data.get(4..8) == Some(b"ftyp") {
            from_brands(data)
        } else {
            Err(Error::UnsupportedFormat)
        }
    }
}

fn from_brands(data: &[u8]) -> Result<Format> {
    let mut size = read_size(data, 4, 0);
    let mut start = HEADER_LENGTH;
    if size == EXTENDED_SIZE {
        size = read_size(data, 8, HEADER_LENGTH as usize);
        start = EXTENDED_HEADER_LENGTH;
    }
    let length = i64::try_from(data.len()).unwrap_or(i64::MAX);
    let end = size.min(FILE_TYPE_BOX_LIMIT).min(length);
    let minor_version = start + BRAND_LENGTH;

    let mut heic = false;
    let mut offset = start;
    while offset + BRAND_LENGTH <= end {
        if offset != minor_version {
            let brand = &data[offset as usize..(offset + BRAND_LENGTH) as usize];
            if AVIF_BRANDS.contains(&brand) {
                return Ok(Format::Avif);
            }
            heic = heic || HEIC_BRANDS.contains(&brand);
        }
        offset += BRAND_LENGTH;
    }
    if heic { Ok(Format::Heic) } else { Err(Error::UnsupportedFormat) }
}

/// A big-endian box size of `width` bytes at `offset` (`unpack('N')` or
/// `unpack('J')`, the 64-bit one read as PHP's signed integer); 0 when the
/// data is too short.
fn read_size(data: &[u8], width: usize, offset: usize) -> i64 {
    let Some(bytes) = data.get(offset..offset + width) else {
        return 0;
    };
    bytes.iter().fold(0u64, |size, &b| (size << 8) | u64::from(b)) as i64
}
