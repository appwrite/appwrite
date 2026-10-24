//! Writing frames in an output format.

use std::io::Write;

use image::{ImageEncoder, RgbaImage};

use crate::error::{Error, Result};
use crate::frame::{Format, Frame};

/// What Imagick reports when an encoder produced nothing.
const EMPTY: &str = "Failed to get the image contents (empty or invalid image?)";

fn failed(_: impl std::fmt::Display) -> Error {
    Error::imagick(EMPTY)
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
        Format::Png => png(first, quality),
        Format::Gif => gif(frames),
        Format::Webp => webp(frames, quality),
        Format::Avif => avif(first, wand_quality),
        Format::Heic => Err(Error::imagick(EMPTY)),
    }
}

fn rgb(pixels: &RgbaImage) -> Vec<u8> {
    let mut out = Vec::with_capacity(pixels.len() / 4 * 3);
    for px in pixels.as_raw().chunks_exact(4) {
        out.extend_from_slice(&px[..3]);
    }
    out
}

fn jpeg(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    // ImageMagick's JPEG default is 92; 0 means unset.
    let quality = match quality {
        Some(q) if q > 0 => q.min(100) as u8,
        _ => 92,
    };
    let mut out = Vec::new();
    image::codecs::jpeg::JpegEncoder::new_with_quality(&mut out, quality)
        .write_image(&rgb(&frame.pixels), frame.width(), frame.height(), image::ExtendedColorType::Rgb8)
        .map_err(failed)?;
    Ok(out)
}

/// PNG: ImageMagick reads the quality as `zlib level * 10 + filter`, 75 when
/// unset. `Image::output()` maps 0–100 onto 9–0, which is level 0 (Huffman
/// only, the fastest deflate here) with filters 0–9 (5 and up adaptive).
fn png(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
    let q = match quality {
        Some(q) if (0..=99).contains(&q) => q,
        _ => 75,
    };
    let (level, filter) = (q / 10, q % 10);
    let deflate = match level {
        0 => png::DeflateCompression::FdeflateUltraFast,
        l => png::DeflateCompression::Level(l as u8),
    };
    let filter = match filter {
        0 => png::Filter::NoFilter,
        1 => png::Filter::Sub,
        2 => png::Filter::Up,
        3 => png::Filter::Avg,
        4 => png::Filter::Paeth,
        _ => png::Filter::Adaptive,
    };
    let mut out = Vec::new();
    {
        let mut encoder = png::Encoder::new(&mut out, frame.width(), frame.height());
        encoder.set_color(if frame.alpha { png::ColorType::Rgba } else { png::ColorType::Rgb });
        encoder.set_depth(png::BitDepth::Eight);
        encoder.set_deflate_compression(deflate);
        encoder.set_filter(filter);
        let mut writer = encoder.write_header().map_err(failed)?;
        if frame.alpha {
            writer.write_image_data(frame.pixels.as_raw()).map_err(failed)?;
        } else {
            writer.write_image_data(&rgb(&frame.pixels)).map_err(failed)?;
        }
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
        let rgba = RgbaImage::from_raw(width, height, pixels).ok_or_else(|| Error::imagick(EMPTY))?;
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

/// WebP, always lossless (see the crate docs). Several frames make an
/// animation; consecutive identical frames merge into one with their delays
/// summed, as libwebp's animation encoder does.
fn webp(frames: &[Frame], quality: Option<i64>) -> Result<Vec<u8>> {
    if quality.is_some_and(|q| q > 100) {
        return Err(Error::imagick(EMPTY));
    }
    let first = &frames[0];
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
        chunk(&mut anmf, b"VP8L", &vp8l(frame, w, h)?);
        chunk(&mut out, b"ANMF", &anmf);
    }
    let size = (out.len() - 8) as u32;
    out[4..8].copy_from_slice(&size.to_le_bytes());
    Ok(out)
}

fn avif(frame: &Frame, quality: Option<i64>) -> Result<Vec<u8>> {
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

/// Writes `data` to `path`.
pub(crate) fn write(path: &std::path::Path, data: &[u8]) -> Result<()> {
    let mut file = std::fs::File::create(path)
        .map_err(|e| Error::imagick(format!("unable to open image `{}': {e}", path.display())))?;
    file.write_all(data).map_err(|e| Error::imagick(e.to_string()))
}
