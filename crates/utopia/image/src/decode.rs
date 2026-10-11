//! Reading blobs into frames.

use std::io::Cursor;

use image::RgbaImage;

use crate::error::{Error, Result};
use crate::frame::{Format, Frame};
use crate::options::check_dimensions;

/// The container a blob's first bytes announce.
pub(crate) fn sniff(data: &[u8]) -> Option<Format> {
    if data.starts_with(&[0xFF, 0xD8, 0xFF]) {
        Some(Format::Jpeg)
    } else if data.starts_with(b"\x89PNG\r\n\x1a\n") {
        Some(Format::Png)
    } else if data.starts_with(b"GIF87a") || data.starts_with(b"GIF89a") {
        Some(Format::Gif)
    } else if data.len() >= 12 && &data[..4] == b"RIFF" && &data[8..12] == b"WEBP" {
        Some(Format::Webp)
    } else if data.len() >= 12 && &data[4..8] == b"ftyp" {
        match &data[8..12] {
            b"avif" | b"avis" => Some(Format::Avif),
            b"heic" | b"heix" | b"hevc" | b"hevx" | b"mif1" | b"msf1" | b"heim" | b"heis" => Some(Format::Heic),
            _ => None,
        }
    } else {
        None
    }
}

/// Decodes every frame of `data`, composited onto the full canvas.
pub(crate) fn decode(data: &[u8]) -> Result<Vec<Frame>> {
    if data.is_empty() {
        return Err(Error::imagick("Zero size image string passed"));
    }
    let format = sniff(data)
        .ok_or_else(|| Error::imagick("no decode delegate for this image format `' @ error/blob.c/BlobToImage/479"))?;
    let frames = match format {
        Format::Jpeg | Format::Png => single(data, format)?,
        Format::Gif => gif(data)?,
        Format::Webp => webp(data)?,
        Format::Avif | Format::Heic => {
            return Err(Error::imagick(format!("no decode delegate for this image format `{}'", format.magick())));
        }
    };
    if frames.is_empty() {
        return Err(Error::imagick(format!("insufficient image data in blob `{}'", format.magick())));
    }
    Ok(frames)
}

fn corrupt(format: Format, e: impl std::fmt::Display) -> Error {
    Error::imagick(format!("corrupt image `{}': {e}", format.magick()))
}

fn single(data: &[u8], format: Format) -> Result<Vec<Frame>> {
    let kind = if format == Format::Jpeg { image::ImageFormat::Jpeg } else { image::ImageFormat::Png };
    let mut reader = image::ImageReader::with_format(Cursor::new(data), kind);
    reader.no_limits();
    let (w, h) = reader.into_dimensions().map_err(|e| corrupt(format, e))?;
    check_dimensions(w.into(), h.into())?;
    let decoded = image::load_from_memory_with_format(data, kind).map_err(|e| corrupt(format, e))?;
    let alpha = decoded.color().has_alpha();
    Ok(vec![Frame::new(decoded.into_rgba8(), alpha, 0, format)])
}

fn gif(data: &[u8]) -> Result<Vec<Frame>> {
    let mut options = gif::DecodeOptions::new();
    options.set_color_output(gif::ColorOutput::RGBA);
    let mut decoder = options.read_info(Cursor::new(data)).map_err(|e| corrupt(Format::Gif, e))?;
    let (sw, sh) = (u32::from(decoder.width()), u32::from(decoder.height()));
    check_dimensions(sw.into(), sh.into())?;
    let mut canvas = RgbaImage::new(sw.max(1), sh.max(1));
    let mut frames = Vec::new();
    loop {
        let frame = match decoder.read_next_frame() {
            Ok(Some(frame)) => frame,
            Ok(None) => break,
            // ImageMagick keeps the frames it read before a damaged one.
            Err(e) if !frames.is_empty() => {
                let _ = e;
                break;
            }
            Err(e) => return Err(corrupt(Format::Gif, e)),
        };
        let previous = (frame.dispose == gif::DisposalMethod::Previous).then(|| canvas.clone());
        let (left, top) = (u32::from(frame.left), u32::from(frame.top));
        let (fw, fh) = (u32::from(frame.width), u32::from(frame.height));
        for y in 0..fh {
            for x in 0..fw {
                let (cx, cy) = (left + x, top + y);
                if cx >= canvas.width() || cy >= canvas.height() {
                    continue;
                }
                let i = ((y * fw + x) * 4) as usize;
                let px = &frame.buffer[i..i + 4];
                if px[3] != 0 {
                    canvas.put_pixel(cx, cy, image::Rgba([px[0], px[1], px[2], px[3]]));
                }
            }
        }
        frames.push(Frame::new(canvas.clone(), frame.transparent.is_some(), u32::from(frame.delay), Format::Gif));
        match frame.dispose {
            gif::DisposalMethod::Background => {
                for y in top..(top + fh).min(canvas.height()) {
                    for x in left..(left + fw).min(canvas.width()) {
                        canvas.put_pixel(x, y, image::Rgba([0, 0, 0, 0]));
                    }
                }
            }
            gif::DisposalMethod::Previous => {
                if let Some(p) = previous {
                    canvas = p;
                }
            }
            _ => {}
        }
    }
    Ok(frames)
}

fn webp(data: &[u8]) -> Result<Vec<Frame>> {
    let mut decoder = image_webp::WebPDecoder::new(Cursor::new(data)).map_err(|e| corrupt(Format::Webp, e))?;
    let (w, h) = decoder.dimensions();
    check_dimensions(w.into(), h.into())?;
    let alpha = decoder.has_alpha();
    let channels = if alpha { 4 } else { 3 };
    let mut buf = vec![0u8; w as usize * h as usize * channels];
    let to_rgba = |buf: &[u8]| -> RgbaImage {
        if alpha {
            RgbaImage::from_raw(w, h, buf.to_vec()).unwrap_or_else(|| RgbaImage::new(w, h))
        } else {
            let mut out = Vec::with_capacity(w as usize * h as usize * 4);
            for px in buf.chunks_exact(3) {
                out.extend_from_slice(&[px[0], px[1], px[2], 255]);
            }
            RgbaImage::from_raw(w, h, out).unwrap_or_else(|| RgbaImage::new(w, h))
        }
    };
    if !decoder.is_animated() {
        decoder.read_image(&mut buf).map_err(|e| corrupt(Format::Webp, e))?;
        return Ok(vec![Frame::new(to_rgba(&buf), alpha, 0, Format::Webp)]);
    }
    let mut frames = Vec::new();
    for _ in 0..decoder.num_frames() {
        let ms = match decoder.read_frame(&mut buf) {
            Ok(ms) => ms,
            Err(_) if !frames.is_empty() => break,
            Err(e) => return Err(corrupt(Format::Webp, e)),
        };
        frames.push(Frame::new(to_rgba(&buf), alpha, ms / 10, Format::Webp));
    }
    Ok(frames)
}

/// The EXIF orientation of `data` (1 to 8), when it has one.
pub(crate) fn orientation(data: &[u8]) -> Option<u32> {
    let exif = exif::Reader::new().read_from_container(&mut Cursor::new(data)).ok()?;
    exif.get_field(exif::Tag::Orientation, exif::In::PRIMARY)?.value.get_uint(0)
}
