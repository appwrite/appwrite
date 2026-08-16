//! Format encoders / decoders.

use std::io::Cursor;

use image::codecs::gif::{GifDecoder, GifEncoder, Repeat};
use image::codecs::jpeg::JpegEncoder;
use image::codecs::png::PngDecoder;
use image::codecs::png::{CompressionType, FilterType as PngFilterType, PngEncoder};
use image::{
    AnimationDecoder, ExtendedColorType, Frame as ImageFrame, ImageDecoder, ImageEncoder, RgbImage,
    Rgba, RgbaImage,
};

use crate::error::{ImageError, Result};
use crate::frame::{Frame, Raster};

pub(crate) fn decode_frames(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    if data.is_empty() {
        return Err(ImageError::Decode("empty image blob".into()));
    }

    // Format-specific sniffing before generic probing.
    if looks_like_jpeg(data) {
        return decode_jpeg(data);
    }
    if looks_like_png(data) {
        return decode_png(data);
    }
    if looks_like_gif(data) {
        return decode_gif(data);
    }

    #[cfg(feature = "webp")]
    if looks_like_webp(data) {
        return decode_webp(data);
    }

    #[cfg(feature = "heic")]
    if looks_like_heic(data) {
        return decode_heic(data);
    }

    let dyn_img = image::load_from_memory(data).map_err(|e| ImageError::Decode(e.to_string()))?;
    if dyn_img.color().has_alpha() {
        let rgba = dyn_img.to_rgba8();
        let width = rgba.width();
        let height = rgba.height();
        crate::limits::check_area(width, height)?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 4)?;
        Ok((vec![Frame::still(rgba)], width, height))
    } else {
        let rgb = dyn_img.to_rgb8();
        let width = rgb.width();
        let height = rgb.height();
        crate::limits::check_area(width, height)?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 3)?;
        Ok((vec![Frame::still_rgb(rgb)], width, height))
    }
}

fn decode_jpeg(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    #[cfg(feature = "jpeg-turbo")]
    {
        if let Ok(frames) = decode_jpeg_turbo(data) {
            return Ok(frames);
        }
    }

    let dyn_img = image::load_from_memory(data).map_err(|e| ImageError::Decode(e.to_string()))?;
    // Keep RGB for JPEG sources - alpha is always opaque until a later op.
    let rgb = dyn_img.to_rgb8();
    let width = rgb.width();
    let height = rgb.height();
    crate::limits::check_area(width, height)?;
    crate::limits::check_memory(u64::from(width) * u64::from(height) * 3)?;
    Ok((vec![Frame::still_rgb(rgb)], width, height))
}

#[cfg(feature = "jpeg-turbo")]
fn decode_jpeg_turbo(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    thread_local! {
        static DECOMP: std::cell::RefCell<Option<turbojpeg::Decompressor>> =
            const { std::cell::RefCell::new(None) };
    }

    DECOMP.with(|slot| {
        let mut guard = slot.borrow_mut();
        if guard.is_none() {
            *guard = Some(
                turbojpeg::Decompressor::new().map_err(|e| ImageError::Decode(e.to_string()))?,
            );
        }
        let decomp = guard.as_mut().unwrap();
        let header = decomp
            .read_header(data)
            .map_err(|e| ImageError::Decode(e.to_string()))?;
        let width = header.width as u32;
        let height = header.height as u32;
        crate::limits::check_area(width, height)?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 3)?;

        let mut rgb = vec![0u8; (width as usize) * (height as usize) * 3];
        let image = turbojpeg::Image {
            pixels: rgb.as_mut_slice(),
            width: width as usize,
            pitch: (width as usize) * 3,
            height: height as usize,
            format: turbojpeg::PixelFormat::RGB,
        };
        decomp
            .decompress(data, image)
            .map_err(|e| ImageError::Decode(e.to_string()))?;
        let img = RgbImage::from_raw(width, height, rgb)
            .ok_or_else(|| ImageError::Decode("invalid jpeg pixel buffer".into()))?;
        Ok((vec![Frame::still_rgb(img)], width, height))
    })
}

fn decode_png(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    let decoder =
        PngDecoder::new(Cursor::new(data)).map_err(|e| ImageError::Decode(e.to_string()))?;
    let (width, height) = decoder.dimensions();
    crate::limits::check_area(width, height)?;
    let dyn_img = image::DynamicImage::from_decoder(decoder)
        .map_err(|e| ImageError::Decode(e.to_string()))?;
    if dyn_img.color().has_alpha() {
        let rgba = dyn_img.to_rgba8();
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 4)?;
        Ok((vec![Frame::still(rgba)], width, height))
    } else {
        let rgb = dyn_img.to_rgb8();
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 3)?;
        Ok((vec![Frame::still_rgb(rgb)], width, height))
    }
}

fn decode_gif(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    let decoder =
        GifDecoder::new(Cursor::new(data)).map_err(|e| ImageError::Decode(e.to_string()))?;
    let (width, height) = decoder.dimensions();
    crate::limits::check_area(width, height)?;

    // `image`'s GIF iterator already coalesces (disposal + offsets → full frames).
    let raw_frames = decoder
        .into_frames()
        .collect_frames()
        .map_err(|e| ImageError::Decode(e.to_string()))?;

    if raw_frames.is_empty() {
        return Err(ImageError::Decode("gif contained no frames".into()));
    }

    let mut frames = Vec::with_capacity(raw_frames.len());
    for frame in raw_frames {
        let delay = frame.delay().numer_denom_ms();
        let delay_ms = if delay.1 == 0 { 0 } else { delay.0 / delay.1 };
        let mut out = Frame::still(frame.into_buffer());
        out.delay_ms = delay_ms;
        frames.push(out);
    }

    let bytes = u64::from(width) * u64::from(height) * 4 * frames.len() as u64;
    crate::limits::check_memory(bytes)?;
    Ok((frames, width, height))
}

#[cfg(feature = "webp")]
fn decode_webp(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    use webp::{AnimDecoder, Decoder as WebpDecoder};

    if let Ok(mut anim) = AnimDecoder::new(data).decode() {
        if anim.has_animation() && anim.len() > 1 {
            anim.sort_by_time_stamp();
            let mut timestamps = Vec::with_capacity(anim.len());
            let mut images = Vec::with_capacity(anim.len());
            let mut width = 0u32;
            let mut height = 0u32;
            for frame in &anim {
                width = frame.width();
                height = frame.height();
                timestamps.push(frame.get_time_ms());
                images.push(rgba_from_webp_frame(&frame, width, height));
            }
            crate::limits::check_area(width, height)?;
            let mut frames = Vec::with_capacity(images.len());
            for (idx, rgba) in images.into_iter().enumerate() {
                let delay_ms = if idx + 1 < timestamps.len() {
                    (timestamps[idx + 1] - timestamps[idx]).max(0) as u32
                } else if idx > 0 {
                    (timestamps[idx] - timestamps[idx - 1]).max(1) as u32
                } else {
                    100
                };
                let mut frame = Frame::still(rgba);
                frame.delay_ms = delay_ms;
                frames.push(frame);
            }
            let bytes = u64::from(width) * u64::from(height) * 4 * frames.len() as u64;
            crate::limits::check_memory(bytes)?;
            return Ok((frames, width, height));
        }
    }

    let decoded = WebpDecoder::new(data)
        .decode()
        .ok_or_else(|| ImageError::Decode("failed to decode webp".into()))?;
    let width = decoded.width();
    let height = decoded.height();
    crate::limits::check_area(width, height)?;
    if decoded.is_alpha() {
        let rgba = RgbaImage::from_raw(width, height, decoded.to_vec())
            .ok_or_else(|| ImageError::Decode("invalid webp pixel buffer".into()))?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 4)?;
        Ok((vec![Frame::still(rgba)], width, height))
    } else {
        let rgb = RgbImage::from_raw(width, height, decoded.to_vec())
            .ok_or_else(|| ImageError::Decode("invalid webp pixel buffer".into()))?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 3)?;
        Ok((vec![Frame::still_rgb(rgb)], width, height))
    }
}

#[cfg(feature = "webp")]
fn rgba_from_webp_frame(frame: &webp::AnimFrame<'_>, width: u32, height: u32) -> RgbaImage {
    use webp::PixelLayout;

    let data = frame.get_image();
    match frame.get_layout() {
        PixelLayout::Rgba => RgbaImage::from_raw(width, height, data.to_vec())
            .unwrap_or_else(|| RgbaImage::from_pixel(width, height, Rgba([0, 0, 0, 0]))),
        PixelLayout::Rgb => {
            let mut rgba = Vec::with_capacity((width * height * 4) as usize);
            for chunk in data.chunks_exact(3) {
                rgba.extend_from_slice(&[chunk[0], chunk[1], chunk[2], 255]);
            }
            RgbaImage::from_raw(width, height, rgba)
                .unwrap_or_else(|| RgbaImage::from_pixel(width, height, Rgba([0, 0, 0, 0])))
        }
    }
}

pub(crate) fn encode_frames(frames: &[Frame], format: &str, quality: i32) -> Result<Vec<u8>> {
    if frames.is_empty() {
        return Err(ImageError::Encode("no frames to encode".into()));
    }

    match format {
        "jpg" | "jpeg" => {
            #[cfg(feature = "jpeg")]
            {
                encode_jpeg(&frames[0].raster, quality)
            }
            #[cfg(not(feature = "jpeg"))]
            {
                Err(ImageError::Unsupported("jpeg"))
            }
        }
        "png" => {
            #[cfg(feature = "png")]
            {
                encode_png(&frames[0].raster, quality)
            }
            #[cfg(not(feature = "png"))]
            {
                Err(ImageError::Unsupported("png"))
            }
        }
        "gif" => {
            #[cfg(feature = "gif")]
            {
                encode_gif(frames)
            }
            #[cfg(not(feature = "gif"))]
            {
                Err(ImageError::Unsupported("gif"))
            }
        }
        "webp" => {
            #[cfg(feature = "webp")]
            {
                encode_webp(frames, quality)
            }
            #[cfg(not(feature = "webp"))]
            {
                Err(ImageError::Unsupported("webp"))
            }
        }
        "avif" => {
            #[cfg(feature = "avif")]
            {
                let q = if quality >= 0 { quality.min(99) } else { 75 };
                encode_avif(&frames[0].raster, q)
            }
            #[cfg(not(feature = "avif"))]
            {
                let _ = quality;
                Err(ImageError::Unsupported("avif"))
            }
        }
        "heic" => {
            #[cfg(feature = "heic")]
            {
                let q = if quality >= 0 {
                    quality.clamp(0, 100)
                } else {
                    75
                };
                encode_heic(&frames[0].raster, q)
            }
            #[cfg(not(feature = "heic"))]
            {
                let _ = quality;
                Err(ImageError::Unsupported("heic"))
            }
        }
        _ => Err(ImageError::InvalidType),
    }
}

#[cfg(feature = "jpeg")]
fn encode_jpeg(raster: &Raster, quality: i32) -> Result<Vec<u8>> {
    let q = if quality >= 0 {
        quality.clamp(0, 100) as u8
    } else {
        75
    };

    #[cfg(feature = "jpeg-turbo")]
    {
        if let Ok(buf) = encode_jpeg_turbo(raster, q) {
            return Ok(buf);
        }
    }

    let (rgb, width, height) = match raster {
        Raster::Rgb(img) => (img.as_raw().as_slice(), img.width(), img.height()),
        Raster::Rgba(img) => {
            let mut packed = Vec::with_capacity(img.len() / 4 * 3);
            for px in img.as_raw().chunks_exact(4) {
                packed.extend_from_slice(&px[..3]);
            }
            return encode_jpeg_packed(&packed, img.width(), img.height(), q);
        }
    };
    encode_jpeg_packed(rgb, width, height, q)
}

#[cfg(feature = "jpeg")]
fn encode_jpeg_packed(rgb: &[u8], width: u32, height: u32, q: u8) -> Result<Vec<u8>> {
    let mut buf = Vec::with_capacity(rgb.len() / 4);
    let mut encoder = JpegEncoder::new_with_quality(&mut buf, q);
    encoder
        .encode(rgb, width, height, ExtendedColorType::Rgb8)
        .map_err(|e| ImageError::Encode(e.to_string()))?;
    Ok(buf)
}

#[cfg(all(feature = "jpeg", feature = "jpeg-turbo"))]
fn encode_jpeg_turbo(raster: &Raster, quality: u8) -> Result<Vec<u8>> {
    thread_local! {
        static COMPRESSOR: std::cell::RefCell<Option<turbojpeg::Compressor>> =
            const { std::cell::RefCell::new(None) };
    }

    COMPRESSOR.with(|slot| {
        let mut guard = slot.borrow_mut();
        if guard.is_none() {
            *guard =
                Some(turbojpeg::Compressor::new().map_err(|e| ImageError::Encode(e.to_string()))?);
        }
        let comp = guard.as_mut().unwrap();
        comp.set_quality(i32::from(quality))
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        let _ = comp.set_subsamp(turbojpeg::Subsamp::Sub2x2);
        match raster {
            Raster::Rgb(img) => {
                let image = turbojpeg::Image {
                    pixels: img.as_raw().as_slice(),
                    width: img.width() as usize,
                    pitch: (img.width() as usize) * 3,
                    height: img.height() as usize,
                    format: turbojpeg::PixelFormat::RGB,
                };
                comp.compress_to_vec(image)
                    .map_err(|e| ImageError::Encode(e.to_string()))
            }
            Raster::Rgba(img) => {
                // TurboJPEG accepts RGBA directly - skip RGB pack copies.
                let image = turbojpeg::Image {
                    pixels: img.as_raw().as_slice(),
                    width: img.width() as usize,
                    pitch: (img.width() as usize) * 4,
                    height: img.height() as usize,
                    format: turbojpeg::PixelFormat::RGBA,
                };
                comp.compress_to_vec(image)
                    .map_err(|e| ImageError::Encode(e.to_string()))
            }
        }
    })
}

#[cfg(feature = "png")]
fn encode_png(raster: &Raster, quality: i32) -> Result<Vec<u8>> {
    let mut buf = Vec::new();
    // PHP maps quality 0–100 → PNG compression 9–0 (inverted). Prefer Fast +
    // NoFilter whenever the mapped level is in the fast band (pipeline latency).
    let (compression, filter) = if quality >= 0 {
        let scale = ((f64::from(quality) / 100.0) * 9.0).round() as u8;
        let level = 9u8.saturating_sub(scale.min(9));
        match level {
            0..=3 => (CompressionType::Fast, PngFilterType::NoFilter),
            4..=6 => (CompressionType::Default, PngFilterType::Adaptive),
            _ => (CompressionType::Best, PngFilterType::Adaptive),
        }
    } else {
        (CompressionType::Fast, PngFilterType::NoFilter)
    };
    let encoder = PngEncoder::new_with_quality(&mut buf, compression, filter);
    match raster {
        Raster::Rgb(img) => encoder
            .write_image(
                img.as_raw(),
                img.width(),
                img.height(),
                ExtendedColorType::Rgb8,
            )
            .map_err(|e| ImageError::Encode(e.to_string()))?,
        Raster::Rgba(img) => encoder
            .write_image(
                img.as_raw(),
                img.width(),
                img.height(),
                ExtendedColorType::Rgba8,
            )
            .map_err(|e| ImageError::Encode(e.to_string()))?,
    }
    Ok(buf)
}

#[cfg(feature = "gif")]
fn encode_gif(frames: &[Frame]) -> Result<Vec<u8>> {
    let mut buf = Vec::new();
    {
        let mut encoder = GifEncoder::new_with_speed(&mut buf, 10);
        encoder
            .set_repeat(Repeat::Infinite)
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        // Stream frames one-by-one instead of collecting a Vec first.
        for frame in frames {
            let rgba = frame.raster.to_rgba_image();
            let delay = image::Delay::from_numer_denom_ms(frame.delay_ms.max(1), 1);
            let gif_frame = if frames.len() == 1 {
                ImageFrame::new(rgba)
            } else {
                ImageFrame::from_parts(rgba, 0, 0, delay)
            };
            encoder
                .encode_frame(gif_frame)
                .map_err(|e| ImageError::Encode(e.to_string()))?;
        }
    }
    Ok(buf)
}

#[cfg(feature = "webp")]
fn encode_webp(frames: &[Frame], quality: i32) -> Result<Vec<u8>> {
    use webp::{AnimEncoder, AnimFrame, Encoder, WebPConfig};

    let q = if quality >= 0 {
        quality.clamp(0, 100) as f32
    } else {
        75.0
    };

    if frames.len() == 1 {
        let mut config = WebPConfig::new().map_err(|e| ImageError::Encode(format!("{e:?}")))?;
        config.lossless = 0;
        config.quality = q;
        // method=0: fastest still-image encode (pipeline-bound paths).
        config.method = 0;
        config.alpha_quality = 100;
        let mem = match &frames[0].raster {
            Raster::Rgb(img) => {
                let encoder = Encoder::from_rgb(img.as_raw(), img.width(), img.height());
                encoder
                    .encode_advanced(&config)
                    .map_err(|e| ImageError::Encode(format!("{e:?}")))?
            }
            Raster::Rgba(img) => {
                let encoder = Encoder::from_rgba(img.as_raw(), img.width(), img.height());
                encoder
                    .encode_advanced(&config)
                    .map_err(|e| ImageError::Encode(format!("{e:?}")))?
            }
        };
        return Ok(mem.to_vec());
    }

    let first = frames[0].raster.as_rgba_arc();
    let width = first.width();
    let height = first.height();
    let mut config = WebPConfig::new().map_err(|e| ImageError::Encode(format!("{e:?}")))?;
    config.quality = q;
    // Animated: method=1 balances latency vs quality across many frames.
    config.method = 1;
    config.alpha_quality = 100;

    let mut encoder = AnimEncoder::new(width, height, &config);
    encoder.set_loop_count(0);
    let mut timestamp = 0i32;
    // Keep owned RGBA buffers alive for the encoder borrow.
    // Libwebp collapses consecutive identical frames (dropping hold/pause timing).
    // Nudge one alpha LSB when a frame matches the previous so holds survive.
    let mut owned: Vec<Vec<u8>> = Vec::with_capacity(frames.len());
    for (idx, frame) in frames.iter().enumerate() {
        let mut raw = frame.raster.to_rgba_image().into_raw();
        if idx > 0 && raw == owned[idx - 1] {
            if let Some(a) = raw.get_mut(3) {
                *a ^= 1;
            }
        }
        owned.push(raw);
    }
    for (idx, raw) in owned.iter().enumerate() {
        let frame = AnimFrame::from_rgba(raw, width, height, timestamp);
        encoder.add_frame(frame);
        timestamp += frames[idx].delay_ms.max(1) as i32;
    }
    // The `webp` crate finalizes with a NULL frame at timestamp 0, which drops the
    // last frame's duration. Append an end-marker copy at the true end time.
    if let Some(last) = owned.last() {
        encoder.add_frame(AnimFrame::from_rgba(last, width, height, timestamp));
    }
    let mem = encoder.encode();
    Ok(mem.to_vec())
}

#[cfg(feature = "avif")]
fn encode_avif(raster: &Raster, quality: i32) -> Result<Vec<u8>> {
    let q = if quality >= 0 {
        quality.min(99) // PHP/AOM: keep highest quality lossy (no lossless @ 100)
    } else {
        75
    };

    // Prefer system libheif (AOM for thumbs, SVT for larger stills).
    #[cfg(feature = "heic")]
    {
        if let Ok(bytes) = encode_libheif(raster, q, libheif_rs::CompressionFormat::Av1) {
            if !bytes.is_empty() {
                return Ok(bytes);
            }
        }
    }

    encode_avif_ravif(raster, q)
}

#[cfg(feature = "avif")]
fn encode_avif_ravif(raster: &Raster, quality: i32) -> Result<Vec<u8>> {
    use image::codecs::avif::AvifEncoder;

    let rgba = raster.as_rgba_arc();
    let mut buf = Vec::new();
    let q = quality.clamp(0, 100) as u8;
    // Max rav1e speed - size grows slightly; encode latency drops a lot.
    let encoder = AvifEncoder::new_with_speed_quality(&mut buf, 10, q);
    encoder
        .write_image(
            rgba.as_raw(),
            rgba.width(),
            rgba.height(),
            ExtendedColorType::Rgba8,
        )
        .map_err(|e| ImageError::Encode(e.to_string()))?;
    Ok(buf)
}

#[cfg(feature = "heic")]
fn heic_lock() -> parking_lot::MutexGuard<'static, ()> {
    // libheif encoder plugins are not reliably re-entrant across threads.
    static LOCK: parking_lot::Mutex<()> = parking_lot::Mutex::new(());
    LOCK.lock()
}

#[cfg(feature = "heic")]
fn with_libheif<T>(f: impl FnOnce(&LibHeifTls) -> Result<T>) -> Result<T> {
    thread_local! {
        static LIB: LibHeifTls = LibHeifTls(libheif_rs::LibHeif::new());
    }
    LIB.with(|lib| f(lib))
}

#[cfg(feature = "heic")]
struct LibHeifTls(libheif_rs::LibHeif);

#[cfg(feature = "heic")]
fn encode_heic(raster: &Raster, quality: i32) -> Result<Vec<u8>> {
    let q = if quality >= 0 {
        quality.clamp(0, 100)
    } else {
        75
    };
    encode_libheif(raster, q, libheif_rs::CompressionFormat::Hevc)
}

/// Prefer SVT-AV1 for larger stills; AOM wins on small thumbnail pipelines.
#[cfg(feature = "heic")]
const SVT_MIN_PIXELS: u64 = 512 * 512;

/// Shared libheif encode for HEIC (HEVC) and AVIF (AV1).
#[cfg(feature = "heic")]
fn encode_libheif(
    raster: &Raster,
    quality: i32,
    format: libheif_rs::CompressionFormat,
) -> Result<Vec<u8>> {
    use libheif_rs::{
        Channel, ColorSpace, CompressionFormat, EncoderParameterValue, EncoderQuality, HeifContext,
        RgbChroma,
    };

    let _guard = heic_lock();
    let rgba = raster.as_rgba_arc();
    let width = rgba.width();
    let height = rgba.height();
    let pixels = u64::from(width) * u64::from(height);

    with_libheif(|LibHeifTls(lib)| {
        let mut enc = match format {
            CompressionFormat::Av1 => {
                let descs = lib.encoder_descriptors(16, Some(CompressionFormat::Av1), None);
                let prefer_svt = pixels >= SVT_MIN_PIXELS;
                let chosen = if prefer_svt {
                    descs
                        .iter()
                        .find(|d| d.name().to_ascii_lowercase().contains("svt"))
                        .or_else(|| {
                            descs.iter().find(|d| {
                                let n = d.name().to_ascii_lowercase();
                                n.contains("aom") || n.contains("aomedia")
                            })
                        })
                } else {
                    descs
                        .iter()
                        .find(|d| {
                            let n = d.name().to_ascii_lowercase();
                            n.contains("aom") || n.contains("aomedia")
                        })
                        .or_else(|| {
                            descs
                                .iter()
                                .find(|d| d.name().to_ascii_lowercase().contains("svt"))
                        })
                };
                match chosen {
                    Some(d) => lib.encoder(*d).map_err(|e| {
                        ImageError::Encode(format!("libheif AV1 encoder unavailable: {e}"))
                    })?,
                    None => lib.encoder_for_format(format).map_err(|e| {
                        ImageError::Encode(format!(
                            "libheif encoder for {format:?} unavailable: {e}"
                        ))
                    })?,
                }
            }
            _ => lib.encoder_for_format(format).map_err(|e| {
                ImageError::Encode(format!("libheif encoder for {format:?} unavailable: {e}"))
            })?,
        };

        match format {
            CompressionFormat::Hevc => {
                // Prefer API latency over x265's default "slow" preset.
                let _ = enc.set_parameter_value(
                    "preset",
                    EncoderParameterValue::String("ultrafast".into()),
                );
            }
            CompressionFormat::Av1 => {
                let name = enc.name().to_ascii_lowercase();
                let speed = if name.contains("svt") { 10 } else { 8 };
                let _ = enc.set_parameter_value("speed", EncoderParameterValue::Int(speed));
                let _ = enc.set_parameter_value("threads", EncoderParameterValue::Int(1));
            }
            _ => {}
        }

        enc.set_quality(EncoderQuality::Lossy(quality.clamp(0, 100) as u8))
            .map_err(|e| ImageError::Encode(e.to_string()))?;

        let mut heif_img = libheif_rs::Image::new(width, height, ColorSpace::Rgb(RgbChroma::Rgba))
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        heif_img
            .create_plane(Channel::Interleaved, width, height, 32)
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        {
            let plane = heif_img
                .planes_mut()
                .interleaved
                .ok_or_else(|| ImageError::Encode("heif interleaved plane missing".into()))?;
            let src = rgba.as_raw();
            let row_bytes = (width as usize) * 4;
            for y in 0..height as usize {
                let src_off = y * row_bytes;
                let dst_off = y * plane.stride;
                plane.data[dst_off..dst_off + row_bytes]
                    .copy_from_slice(&src[src_off..src_off + row_bytes]);
            }
        }

        let mut ctx = HeifContext::new().map_err(|e| ImageError::Encode(e.to_string()))?;
        // libheif-rs 0.22.0 drops `EncodingOptions` before the FFI call (UAF) when
        // passing `Some(options)`. Defaults already preserve alpha - pass `None`.
        // Stay on 0.22 for Ubuntu 24.04 libheif 1.17 (newer crates need newer libheif).
        let mut handle = ctx
            .encode_image(&heif_img, &mut enc, None)
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        ctx.set_primary_image(&mut handle)
            .map_err(|e| ImageError::Encode(e.to_string()))?;

        // Prefer in-memory writer; fall back to /dev/shm tempfile when empty/broken.
        if let Ok(bytes) = ctx.write_to_bytes() {
            if !bytes.is_empty() {
                return Ok(bytes);
            }
        }

        let tmp = tempfile::Builder::new()
            .prefix("utopia-heif-")
            .suffix(".tmp")
            .tempfile_in("/dev/shm")
            .or_else(|_| tempfile::NamedTempFile::new())
            .map_err(ImageError::Io)?;
        let path = tmp
            .path()
            .to_str()
            .ok_or_else(|| ImageError::Encode("heif temp path is not UTF-8".into()))?;
        ctx.write_to_file(path)
            .map_err(|e| ImageError::Encode(e.to_string()))?;
        std::fs::read(path).map_err(ImageError::Io)
    })
}

#[cfg(feature = "heic")]
fn decode_heic(data: &[u8]) -> Result<(Vec<Frame>, u32, u32)> {
    use libheif_rs::{ColorSpace, HeifContext, RgbChroma};

    let _guard = heic_lock();
    with_libheif(|LibHeifTls(lib)| {
        let ctx =
            HeifContext::read_from_bytes(data).map_err(|e| ImageError::Decode(e.to_string()))?;
        let handle = ctx
            .primary_image_handle()
            .map_err(|e| ImageError::Decode(e.to_string()))?;
        let width = handle.width();
        let height = handle.height();
        crate::limits::check_area(width, height)?;

        let decoded = lib
            .decode(&handle, ColorSpace::Rgb(RgbChroma::Rgba), None)
            .map_err(|e| ImageError::Decode(e.to_string()))?;
        let plane = decoded
            .planes()
            .interleaved
            .ok_or_else(|| ImageError::Decode("heic interleaved plane missing".into()))?;

        let row_bytes = (width as usize) * 4;
        let mut rgba = vec![0u8; row_bytes * height as usize];
        for y in 0..height as usize {
            let src_off = y * plane.stride;
            let dst_off = y * row_bytes;
            rgba[dst_off..dst_off + row_bytes]
                .copy_from_slice(&plane.data[src_off..src_off + row_bytes]);
        }
        let img = RgbaImage::from_raw(width, height, rgba)
            .ok_or_else(|| ImageError::Decode("invalid heic pixel buffer".into()))?;
        crate::limits::check_memory(u64::from(width) * u64::from(height) * 4)?;
        Ok((vec![Frame::still(img)], width, height))
    })
}

pub(crate) fn looks_like_jpeg(data: &[u8]) -> bool {
    data.len() >= 3 && data[0] == 0xFF && data[1] == 0xD8 && data[2] == 0xFF
}

pub(crate) fn looks_like_png(data: &[u8]) -> bool {
    data.starts_with(&[0x89, b'P', b'N', b'G', 0x0D, 0x0A, 0x1A, 0x0A])
}

pub(crate) fn looks_like_gif(data: &[u8]) -> bool {
    data.starts_with(b"GIF87a") || data.starts_with(b"GIF89a")
}

pub(crate) fn looks_like_webp(data: &[u8]) -> bool {
    data.len() >= 12 && data.starts_with(b"RIFF") && &data[8..12] == b"WEBP"
}

/// Detect HEIC/HEIF (HEVC) via ISOBMFF `ftyp` brands, excluding AVIF.
pub(crate) fn looks_like_heic(data: &[u8]) -> bool {
    if data.len() < 16 || &data[4..8] != b"ftyp" {
        return false;
    }
    let box_size = u32::from_be_bytes([data[0], data[1], data[2], data[3]]) as usize;
    if box_size < 16 {
        return false;
    }
    let end = box_size.min(data.len());
    let brands = &data[8..end];
    let mut hevc_brand = false;
    let mut avif_brand = false;
    for chunk in brands.chunks_exact(4) {
        match chunk {
            b"avif" | b"avis" => avif_brand = true,
            b"heic" | b"heix" | b"heim" | b"heis" | b"hevc" | b"hevx" | b"hevm" | b"hevs" => {
                hevc_brand = true;
            }
            _ => {}
        }
    }
    hevc_brand && !avif_brand
}
