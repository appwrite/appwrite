//! AVIF and HEIC through libheif, the library ImageMagick reads and writes
//! them with (the `libheif` feature). What it can do depends on the codecs the
//! system's libheif was built with: an AV1 decoder for AVIF, libde265 for
//! HEIC input and an HEVC encoder (x265) for HEIC output.

use image::RgbaImage;
use libheif_rs::{Channel, ColorSpace, CompressionFormat, EncoderQuality, HeifContext, LibHeif, RgbChroma};

use crate::error::{Error, Result};
use crate::frame::{Format, Frame};
use crate::options::{canvas_bytes, check_dimensions, check_pixels};

/// Decodes the primary image of `data`, with the file's own rotation,
/// mirroring and cropping applied, as ImageMagick reads it.
pub(crate) fn decode(data: &[u8], format: Format) -> Result<Vec<Frame>> {
    let corrupt = |e: libheif_rs::HeifError| Error::imagick(format!("corrupt image `{}': {e}", format.magick()));
    let context = HeifContext::read_from_bytes(data).map_err(corrupt)?;
    let handle = context.primary_image_handle().map_err(corrupt)?;
    let (width, height) = (handle.width(), handle.height());
    check_dimensions(width.into(), height.into())?;
    // libheif's decoded image and the RGBA copy made of it, before either exists.
    check_pixels(canvas_bytes(width.into(), height.into()).saturating_mul(2))?;
    let alpha = handle.has_alpha_channel();
    let decoded = LibHeif::new().decode(&handle, ColorSpace::Rgb(RgbChroma::Rgba), None).map_err(corrupt)?;
    let planes = decoded.planes();
    let Some(plane) = planes.interleaved else {
        return Err(Error::imagick(format!("insufficient image data in blob `{}'", format.magick())));
    };
    let row = plane.width as usize * 4;
    let mut pixels = Vec::with_capacity(row * plane.height as usize);
    for line in plane.data.chunks(plane.stride).take(plane.height as usize) {
        pixels.extend_from_slice(&line[..row]);
    }
    let pixels = RgbaImage::from_raw(plane.width, plane.height, pixels)
        .ok_or_else(|| Error::imagick(format!("insufficient image data in blob `{}'", format.magick())))?;
    Ok(vec![Frame::new(pixels, alpha, 0, format)])
}

/// Encodes `frame` as HEIC. `quality` is the object quality
/// (`setCompressionQuality`): ImageMagick's default is 50, and 100 is lossless.
/// `None` when libheif has no HEVC encoder, or the encoder fails.
pub(crate) fn encode(frame: &Frame, quality: Option<i64>) -> Option<Vec<u8>> {
    let lib = LibHeif::new();
    let mut encoder = lib.encoder_for_format(CompressionFormat::Hevc).ok()?;
    let quality = match quality {
        Some(q) if q >= 100 => EncoderQuality::LossLess,
        Some(q) if q >= 0 => EncoderQuality::Lossy(q as u8),
        _ => EncoderQuality::Lossy(50),
    };
    encoder.set_quality(quality).ok()?;
    let (width, height) = (frame.width(), frame.height());
    let (chroma, channels) = if frame.alpha { (RgbChroma::Rgba, 4) } else { (RgbChroma::Rgb, 3) };
    let mut image = libheif_rs::Image::new(width, height, ColorSpace::Rgb(chroma)).ok()?;
    image.create_plane(Channel::Interleaved, width, height, 8).ok()?;
    {
        let planes = image.planes_mut();
        let plane = planes.interleaved?;
        let stride = plane.stride;
        for (line, source) in plane.data.chunks_mut(stride).zip(frame.pixels.rows()) {
            for (target, pixel) in line.chunks_exact_mut(channels).zip(source) {
                target.copy_from_slice(&pixel.0[..channels]);
            }
        }
    }
    let mut context = HeifContext::new().ok()?;
    context.encode_image(&image, &mut encoder, None).ok()?;
    context.write_to_bytes().ok()
}
