//! Reading blobs into frames.

use std::io::Cursor;

use image::RgbaImage;

use crate::error::{Error, Result};
use crate::frame::{Format, Frame};
use crate::options::{canvas_bytes, check_dimensions, check_pixels, pixel_budget};

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
    // A backstop under the check below: the decoder refuses allocations past
    // the whole budget on its own.
    let mut limits = image::Limits::no_limits();
    limits.max_alloc = Some(pixel_budget());
    reader.limits(limits);
    let decoder = reader.into_decoder().map_err(|e| corrupt(format, e))?;
    let (w, h) = image::ImageDecoder::dimensions(&decoder);
    check_dimensions(w.into(), h.into())?;
    // The decoded pixels in the file's own layout (up to 8 bytes a pixel for
    // 16-bit PNG), and the RGBA copy when that layout is not RGBA8.
    let color = image::ImageDecoder::color_type(&decoder);
    let decoded_size = u64::from(w) * u64::from(h) * u64::from(color.bytes_per_pixel());
    let copy = if color == image::ColorType::Rgba8 { 0 } else { canvas_bytes(w.into(), h.into()) };
    check_pixels(decoded_size.saturating_add(copy))?;
    let decoded = image::DynamicImage::from_decoder(decoder).map_err(|e| corrupt(format, e))?;
    let alpha = decoded.color().has_alpha();
    Ok(vec![Frame::new(decoded.into_rgba8(), alpha, 0, format)])
}

fn gif(data: &[u8]) -> Result<Vec<Frame>> {
    let mut options = gif::DecodeOptions::new();
    options.set_color_output(gif::ColorOutput::RGBA);
    // A backstop under the checks below: the decoder refuses a frame buffer
    // past the whole budget on its own.
    if let Some(limit) = std::num::NonZeroU64::new(pixel_budget()) {
        options.set_memory_limit(gif::MemoryLimit::Bytes(limit));
    }
    let mut decoder = options.read_info(Cursor::new(data)).map_err(|e| corrupt(Format::Gif, e))?;
    let (sw, sh) = (u32::from(decoder.width()), u32::from(decoder.height()));
    check_dimensions(sw.into(), sh.into())?;
    let canvas_size = canvas_bytes(sw.max(1).into(), sh.max(1).into());
    let mut canvas = RgbaImage::new(sw.max(1), sh.max(1));
    let mut frames = Vec::new();
    let mut buffer = Vec::new();
    loop {
        // The frame's descriptor first: its declared size is checked before
        // its pixels are decoded.
        let info = match decoder.next_frame_info() {
            Ok(Some(f)) => (f.left, f.top, f.width, f.height, f.dispose, f.transparent.is_some(), f.delay),
            Ok(None) => break,
            // ImageMagick keeps the frames it read before a damaged one.
            Err(_) if !frames.is_empty() => break,
            Err(e) => return Err(corrupt(Format::Gif, e)),
        };
        let (left, top, fw, fh, dispose, transparent, delay) = info;
        let (left, top, fw, fh) = (u32::from(left), u32::from(top), u32::from(fw), u32::from(fh));
        // Everything alive at once: the frames kept so far, the working
        // canvas, the copy pushed for this frame, the canvas saved for a
        // `Previous` disposal, and this frame's own pixels.
        let saved = u64::from(dispose == gif::DisposalMethod::Previous);
        let live = canvas_size
            .saturating_mul(frames.len() as u64 + 2 + saved)
            .saturating_add(canvas_bytes(fw.into(), fh.into()));
        check_pixels(live)?;
        buffer.resize(decoder.buffer_size(), 0);
        match decoder.read_into_buffer(&mut buffer) {
            Ok(()) => {}
            Err(_) if !frames.is_empty() => break,
            Err(e) => return Err(corrupt(Format::Gif, e)),
        }
        let previous = (dispose == gif::DisposalMethod::Previous).then(|| canvas.clone());
        for y in 0..fh {
            for x in 0..fw {
                let (cx, cy) = (left + x, top + y);
                if cx >= canvas.width() || cy >= canvas.height() {
                    continue;
                }
                let i = ((y * fw + x) * 4) as usize;
                let px = &buffer[i..i + 4];
                if px[3] != 0 {
                    canvas.put_pixel(cx, cy, image::Rgba([px[0], px[1], px[2], px[3]]));
                }
            }
        }
        frames.push(Frame::new(canvas.clone(), transparent, u32::from(delay), Format::Gif));
        match dispose {
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
    let channels: u64 = if alpha { 4 } else { 3 };
    let canvas_size = canvas_bytes(w.into(), h.into());
    // The decoder's buffer and the RGBA copy made of it, before either exists.
    let read_size = u64::from(w) * u64::from(h) * channels;
    check_pixels(read_size.saturating_add(canvas_size))?;
    let mut buf = vec![0u8; read_size as usize];
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
        // The frames kept so far, the decoder's buffer and this frame's copy.
        check_pixels(canvas_size.saturating_mul(frames.len() as u64 + 1).saturating_add(read_size))?;
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
