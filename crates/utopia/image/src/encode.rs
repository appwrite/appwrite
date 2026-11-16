//! Writing frames in an output format.

use std::io::Write;

use image::RgbaImage;

use crate::error::{Error, Result};
use crate::frame::{Format, Frame};

fn failed(e: impl std::fmt::Display) -> Error {
    Error::write(e)
}

/// Whether the format keeps every frame in one file (ImageMagick's adjoin).
pub(crate) fn adjoins(format: Format) -> bool {
    matches!(format, Format::Gif | Format::Webp)
}

/// Encodes `frames` as `format`: the first frame only unless the format adjoins.
///
/// `quality` is the image quality of the first frame (`setImageCompressionQuality`),
/// `wand_quality` the object quality AVIF and HEIC read (`setCompressionQuality`).
pub(crate) fn encode(
    frames: &[Frame],
    format: Format,
    quality: Option<i64>,
    wand_quality: Option<i64>,
) -> Result<Vec<u8>> {
    let first = &frames[0];
    match format {
        Format::Jpeg => jpeg(first, quality),
        Format::Png => png(first),
        Format::Gif => gif(frames),
        Format::Webp => webp(frames, quality),
        Format::Avif => avif(first, wand_quality),
        Format::Heic => heic(first, wand_quality),
    }
}

/// The grey level of every pixel, with its alpha when `alpha`, if every pixel is
/// a grey: such an image is written with one colour channel, as ImageMagick does.
fn gray(pixels: &RgbaImage, alpha: bool) -> Option<Vec<u8>> {
    let raw = pixels.as_raw();
    if !raw.chunks_exact(4).all(|px| px[0] == px[1] && px[1] == px[2]) {
        return None;
    }
    Some(raw.chunks_exact(4).flat_map(|px| [px[0], px[3]].into_iter().take(if alpha { 2 } else { 1 })).collect())
}

fn rgb(pixels: &RgbaImage) -> Vec<u8> {
    let mut out = Vec::with_capacity(pixels.len() / 4 * 3);
    for px in pixels.as_raw().chunks_exact(4) {
        out.extend_from_slice(&px[..3]);
    }
    out
}

/// JPEG as ImageMagick's libjpeg writes it: baseline, with Huffman tables
/// built for the image. The chroma subsampling is the source's when that was
/// a JPEG; otherwise 4:4:4 from quality 90 and 4:2:0 below.
fn jpeg(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    // ImageMagick's JPEG default is 92; 0 means unset.
    let quality = match quality {
        Some(q) if q > 0 => q.min(100) as u8,
        _ => 92,
    };
    // JPEG's own limit; ImageMagick fails on a larger image too.
    let (width, height) =
        (u16::try_from(frame.width()).map_err(failed)?, u16::try_from(frame.height()).map_err(failed)?);
    let mut out = Vec::new();
    let mut encoder = jpeg_encoder::Encoder::new(&mut out, quality);
    let sampling = match frame.jpeg_sampling {
        Some((horizontal, vertical)) => jpeg_encoder::SamplingFactor::from_factors(horizontal, vertical),
        None if quality >= 90 => Some(jpeg_encoder::SamplingFactor::F_1_1),
        None => None,
    };
    encoder.set_sampling_factor(sampling.unwrap_or(jpeg_encoder::SamplingFactor::F_2_2));
    encoder.set_optimized_huffman_tables(true);
    match gray(&frame.pixels, false) {
        Some(luma) => encoder.encode(&luma, width, height, jpeg_encoder::ColorType::Luma),
        None => encoder.encode(&rgb(&frame.pixels), width, height, jpeg_encoder::ColorType::Rgb),
    }
    .map_err(failed)?;
    Ok(out)
}

/// PNG: ImageMagick's PNG coder reads its quality (`zlib level * 10 + filter`)
/// from the wand, never from the image, so the image quality `Image::output()`
/// sets changes nothing: every PNG is written at the default 75, which is
/// zlib level 7 with adaptive filtering.
fn png(frame: &Frame) -> Result<Vec<u8>> {
    let deflate = png::DeflateCompression::Level(7);
    let filter = png::Filter::Adaptive;
    let mut out = Vec::new();
    {
        let mut encoder = png::Encoder::new(&mut out, frame.width(), frame.height());
        let (color, data) = match (gray(&frame.pixels, frame.alpha), frame.alpha) {
            (Some(levels), true) => (png::ColorType::GrayscaleAlpha, levels),
            (Some(levels), false) => (png::ColorType::Grayscale, levels),
            (None, true) => (png::ColorType::Rgba, frame.pixels.as_raw().clone()),
            (None, false) => (png::ColorType::Rgb, rgb(&frame.pixels)),
        };
        encoder.set_color(color);
        encoder.set_depth(png::BitDepth::Eight);
        encoder.set_deflate_compression(deflate);
        encoder.set_filter(filter);
        let mut writer = encoder.write_header().map_err(failed)?;
        writer.write_image_data(&data).map_err(failed)?;
        writer.finish().map_err(failed)?;
    }
    Ok(out)
}

fn gif(frames: &[Frame]) -> Result<Vec<u8>> {
    let first = &frames[0];
    let screen_w = if first.page.width == 0 { first.width() } else { first.page.width };
    let screen_h = if first.page.height == 0 { first.height() } else { first.page.height };
    let clamp16 = |v: u32| v.min(u32::from(u16::MAX)) as u16;
    let mut out = Vec::new();
    {
        let mut encoder = gif::Encoder::new(&mut out, clamp16(screen_w), clamp16(screen_h), &[]).map_err(failed)?;
        if frames.len() > 1 {
            encoder.set_repeat(gif::Repeat::Infinite).map_err(failed)?;
        }
        for frame in frames {
            let (w, h) = (clamp16(frame.width()), clamp16(frame.height()));
            let mut rgba = Vec::with_capacity(usize::from(w) * usize::from(h) * 4);
            for y in 0..u32::from(h) {
                for x in 0..u32::from(w) {
                    let p = frame.pixels.get_pixel(x, y);
                    // GIF transparency is binary: under half opaque is transparent.
                    if frame.alpha && p[3] < 128 {
                        rgba.extend_from_slice(&[0, 0, 0, 0]);
                    } else {
                        rgba.extend_from_slice(&[p[0], p[1], p[2], 255]);
                    }
                }
            }
            let mut out = gif::Frame::from_rgba_speed(w, h, &mut rgba, 10);
            out.delay = frame.delay.min(u32::from(u16::MAX)) as u16;
            out.dispose = gif::DisposalMethod::Background;
            out.left = frame.page.x.clamp(0, i64::from(u16::MAX)) as u16;
            out.top = frame.page.y.clamp(0, i64::from(u16::MAX)) as u16;
            encoder.write_frame(&out).map_err(failed)?;
        }
    }
    Ok(out)
}

/// One WebP frame as a lossless `VP8L` bitstream (the chunk payload).
fn vp8l(frame: &Frame, width: u32, height: u32) -> Result<Vec<u8>> {
    let pixels = if (width, height) == frame.pixels.dimensions() {
        frame.pixels.as_raw().clone()
    } else {
        image::imageops::crop_imm(&frame.pixels, 0, 0, width, height).to_image().into_raw()
    };
    let (data, color) = if frame.alpha {
        (pixels, image_webp::ColorType::Rgba8)
    } else {
        let rgba = RgbaImage::from_raw(width, height, pixels).ok_or_else(|| Error::write("frame size"))?;
        (rgb(&rgba), image_webp::ColorType::Rgb8)
    };
    let mut file = Vec::new();
    image_webp::WebPEncoder::new(&mut file).encode(&data, width, height, color).map_err(failed)?;
    // RIFF header (12 bytes), then the VP8L chunk header (8 bytes).
    Ok(file[20..].to_vec())
}

fn chunk(out: &mut Vec<u8>, name: &[u8; 4], payload: &[u8]) {
    out.extend_from_slice(name);
    out.extend_from_slice(&(payload.len() as u32).to_le_bytes());
    out.extend_from_slice(payload);
    if payload.len() % 2 == 1 {
        out.push(0);
    }
}

fn u24(out: &mut Vec<u8>, v: u32) {
    out.extend_from_slice(&v.to_le_bytes()[..3]);
}

/// One WebP frame as a whole file written by libwebp, as ImageMagick sets it
/// up: lossy at `quality` (75 when unset or 0), lossless from 100.
#[cfg(feature = "libwebp")]
fn libwebp(frame: &Frame, width: u32, height: u32, quality: Option<i64>) -> Vec<u8> {
    let cropped;
    let pixels = if (width, height) == frame.pixels.dimensions() {
        &frame.pixels
    } else {
        cropped = image::imageops::crop_imm(&frame.pixels, 0, 0, width, height).to_image();
        &cropped
    };
    let opaque;
    let encoder = if frame.alpha {
        webp::Encoder::from_rgba(pixels.as_raw(), width, height)
    } else {
        opaque = rgb(pixels);
        webp::Encoder::from_rgb(&opaque, width, height)
    };
    match quality {
        Some(q) if q >= 100 => encoder.encode_lossless().to_vec(),
        Some(q) if q > 0 => encoder.encode(q as f32).to_vec(),
        _ => encoder.encode(75.0).to_vec(),
    }
}

/// The image chunks of a WebP file (`ALPH`, `VP8 `, `VP8L`), headers included:
/// what an animation frame holds.
#[cfg(feature = "libwebp")]
fn image_chunks(file: &[u8]) -> Vec<u8> {
    let mut out = Vec::new();
    let mut rest = file.get(12..).unwrap_or_default();
    while rest.len() >= 8 {
        let size = u32::from_le_bytes([rest[4], rest[5], rest[6], rest[7]]) as usize;
        let end = (8 + size + size % 2).min(rest.len());
        if matches!(&rest[..4], b"ALPH" | b"VP8 " | b"VP8L") {
            out.extend_from_slice(&rest[..end]);
        }
        rest = &rest[end..];
    }
    out
}

/// WebP: lossy with the `libwebp` feature, as ImageMagick writes it, and
/// lossless without (see the crate docs). Several frames make an animation;
/// consecutive identical frames merge into one with their delays summed, as
/// libwebp's animation encoder does.
fn webp(frames: &[Frame], quality: Option<i64>) -> Result<Vec<u8>> {
    // ImageMagick's WebP coder fails on a quality over 100.
    if quality.is_some_and(|q| q > 100) {
        return Err(Error::write("quality over 100"));
    }
    let first = &frames[0];
    #[cfg(feature = "libwebp")]
    if frames.len() == 1 {
        return Ok(libwebp(first, first.width(), first.height(), quality));
    }
    if frames.len() == 1 {
        let mut out = Vec::new();
        let body = vp8l(first, first.width(), first.height())?;
        out.extend_from_slice(b"RIFF");
        out.extend_from_slice(&[0; 4]);
        out.extend_from_slice(b"WEBP");
        chunk(&mut out, b"VP8L", &body);
        let size = (out.len() - 8) as u32;
        out[4..8].copy_from_slice(&size.to_le_bytes());
        return Ok(out);
    }
    let (cw, ch) = (first.width(), first.height());
    let mut merged: Vec<(&Frame, u32)> = Vec::new();
    for frame in frames {
        match merged.last_mut() {
            Some((last, delay)) if last.pixels == frame.pixels && last.alpha == frame.alpha => *delay += frame.delay,
            _ => merged.push((frame, frame.delay)),
        }
    }
    let alpha = frames.iter().any(|f| f.alpha);
    let mut out = Vec::new();
    out.extend_from_slice(b"RIFF");
    out.extend_from_slice(&[0; 4]);
    out.extend_from_slice(b"WEBP");
    let mut vp8x = vec![(1u8 << 1) | if alpha { 1 << 4 } else { 0 }, 0, 0, 0];
    u24(&mut vp8x, cw - 1);
    u24(&mut vp8x, ch - 1);
    chunk(&mut out, b"VP8X", &vp8x);
    let mut anim = vec![255, 255, 255, 255];
    anim.extend_from_slice(&0u16.to_le_bytes());
    chunk(&mut out, b"ANIM", &anim);
    for (frame, delay) in merged {
        let (w, h) = (frame.width().min(cw), frame.height().min(ch));
        let mut anmf = Vec::new();
        u24(&mut anmf, 0);
        u24(&mut anmf, 0);
        u24(&mut anmf, w - 1);
        u24(&mut anmf, h - 1);
        u24(&mut anmf, (delay * 10).min(0xFF_FFFF));
        // No blending: every frame is the whole picture.
        anmf.push(1 << 1);
        #[cfg(feature = "libwebp")]
        anmf.extend_from_slice(&image_chunks(&libwebp(frame, w, h, quality)));
        #[cfg(not(feature = "libwebp"))]
        chunk(&mut anmf, b"VP8L", &vp8l(frame, w, h)?);
        chunk(&mut out, b"ANMF", &anmf);
    }
    let size = (out.len() - 8) as u32;
    out[4..8].copy_from_slice(&size.to_le_bytes());
    Ok(out)
}

/// AVIF: through libheif with the `libheif` feature, as ImageMagick writes it,
/// when it has an AV1 encoder; the pure-Rust encoder (the `ravif` feature)
/// otherwise, and an unsupported format with neither.
fn avif(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    #[cfg(feature = "libheif")]
    if let Some(encoded) = crate::heif::encode(frame, quality, Format::Avif) {
        return Ok(encoded);
    }
    #[cfg(feature = "ravif")]
    return ravif(frame, quality);
    #[cfg(not(feature = "ravif"))]
    {
        let _ = (frame, quality);
        Err(Error::unavailable("AVIF", "output"))
    }
}

/// AVIF from the pure-Rust encoder (rav1e).
#[cfg(feature = "ravif")]
fn ravif(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    use image::ImageEncoder;

    // ImageMagick's AVIF default quality is 50.
    let quality = match quality {
        Some(q) if q >= 0 => q.min(100) as u8,
        _ => 50,
    };
    let mut out = Vec::new();
    let encoder = image::codecs::avif::AvifEncoder::new_with_speed_quality(&mut out, 8, quality.max(1));
    if frame.alpha {
        encoder
            .write_image(frame.pixels.as_raw(), frame.width(), frame.height(), image::ExtendedColorType::Rgba8)
            .map_err(failed)?;
    } else {
        encoder
            .write_image(&rgb(&frame.pixels), frame.width(), frame.height(), image::ExtendedColorType::Rgb8)
            .map_err(failed)?;
    }
    Ok(out)
}

/// HEIC, where libheif has an HEVC encoder; an unsupported format otherwise.
fn heic(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    #[cfg(feature = "libheif")]
    let encoded = crate::heif::encode(frame, quality, Format::Heic);
    #[cfg(not(feature = "libheif"))]
    let encoded: Option<Vec<u8>> = {
        let _ = (frame, quality);
        None
    };
    encoded.ok_or_else(|| Error::unavailable("HEIC", "output"))
}

/// Writes `data` to `path`.
pub(crate) fn write(path: &std::path::Path, data: &[u8]) -> Result<()> {
    let mut file = std::fs::File::create(path).map_err(|e| Error::write(format_args!("{}: {e}", path.display())))?;
    file.write_all(data).map_err(Error::write)
}
