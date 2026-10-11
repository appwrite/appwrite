//! Compat adapter for `image`: maps `tests/compat/image/spec.json` operations
//! onto `utopia-image`.
//!
//! Encoded bytes differ between ImageMagick and the Rust codecs, so, like
//! Adapter.php, results describe images instead: the format read from the
//! magic bytes, the first frame's size, the frame count and total delay, and
//! pixel comparisons on 16x16 area-averaged, premultiplied grids.

use std::hint::black_box;
use std::io::Cursor;

use image::AnimationDecoder;
use serde_json::{Value, json};
use utopia_image::{Error, FocalPoint, Gravity, Image, OutputFormat, Resource, set_resource_limit};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "image.new",
    "image.decode",
    "image.crop",
    "image.set_border",
    "image.set_border_radius",
    "image.set_opacity",
    "image.set_rotation",
    "image.set_background",
    "image.output",
    "image.save",
    "image.pixel",
    "image.compare",
    "image.size_by_fixed_height",
    "image.size_by_fixed_width",
    "image.gravity_types",
    "image.set_resource_limit",
    "file.probe",
    "file.compare",
    "pipeline",
];

/// Grid side for pixel comparisons.
const GRID: usize = 16;

/// Largest mean absolute difference (0-255 channels) two grids may have to be similar.
const TOLERANCE: f64 = 6.0;

const KITTEN: &str = "packages/image/tests/resources/disk-a/kitten-1.jpg";

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("decode", "Decode a 1837x1920 JPEG"),
    ("preview_jpeg", "Decode a 1837x1920 JPEG, crop it to 400x400 and encode a JPEG at 80"),
    ("preview_gravity", "Decode a 1837x1920 JPEG, crop its top 400x200 and encode a PNG"),
    ("png_output", "Encode a 400x400 image as PNG at quality 90"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    let blob = read(KITTEN)?;
    let fault = |e: Error| Fault::new(e.to_string());
    Ok(match name {
        "decode" => Bench::sync(move || {
            black_box(Image::new(black_box(&blob)).ok());
        }),
        "preview_jpeg" => Bench::sync(move || {
            let mut image = Image::new(&blob).expect("kitten decodes");
            image.crop(400, 400, Gravity::Center, None).expect("crop");
            black_box(image.output(OutputFormat::Jpeg, 80).ok());
        }),
        "preview_gravity" => Bench::sync(move || {
            let mut image = Image::new(&blob).expect("kitten decodes");
            image.crop(400, 200, Gravity::Top, None).expect("crop");
            black_box(image.output(OutputFormat::Png, 90).ok());
        }),
        "png_output" => {
            let mut image = Image::new(&blob).map_err(fault)?;
            image.crop(400, 400, Gravity::Center, None).map_err(fault)?;
            Bench::sync(move || {
                black_box(image.output(OutputFormat::Png, 90).ok());
            })
        }
        _ => return Err(Fault::new(format!("image: no benchmark `{name}`"))),
    })
}

/// Reads a file; a relative path is the repository's (the drivers run from
/// the root, `cargo test` from the crate).
fn read(path: &str) -> Result<Vec<u8>, Fault> {
    std::fs::read(path)
        .or_else(|e| {
            let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
            if std::path::Path::new(path).is_relative() { std::fs::read(root.join(path)) } else { Err(e) }
        })
        .map_err(|e| Fault::new(format!("{path}: {e}")))
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn dimension(a: &Args, key: &str) -> Result<u32, Fault> {
    u32::try_from(a.i64(key)?)
        .map_err(|_| Fault::new(format!("`{key}` must be a non-negative 32-bit integer (deviation)")))
}

fn opt_float(a: &Args, key: &str) -> Result<Option<f64>, Fault> {
    match a.opt(key) {
        None | Some(Value::Null) => Ok(None),
        Some(v) => float(v).map(Some).ok_or_else(|| Fault::new(format!("`{key}` must be a number"))),
    }
}

/// `$image->output($type, $quality)`, the type parsed as PHP's switch does.
fn output(a: &Args, session: &mut Session) -> Result<Result<Vec<u8>, Error>, Fault> {
    let format = match a.str("type")?.parse::<OutputFormat>() {
        Ok(f) => f,
        Err(e) => return Ok(Err(e)),
    };
    let quality = a.opt_i64("quality")?.unwrap_or(75);
    let image = session.get_mut::<Image>(a.value("image")?)?;
    Ok(image.output(format, quality))
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    macro_rules! with_image {
        (|$image:ident| $body:expr) => {{
            let $image = session.get_mut::<Image>(a.value("image")?)?;
            match $body {
                Ok(_) => Outcome::Ok(Value::Null),
                Err(e) => err(e),
            }
        }};
    }
    Ok(match op {
        "image.new" => {
            let blob = match a.opt_str("path")? {
                Some(path) => read(path)?,
                None => a.bytes("blob")?,
            };
            match Image::new(&blob) {
                Ok(image) => Outcome::Ok(session.handle(image)),
                Err(e) => err(e),
            }
        }
        "image.decode" => {
            let mut blob = match a.opt("magic") {
                Some(m) => crate::adapter::bytes(m).ok_or_else(|| Fault::new("`magic` must be bytes"))?,
                None => Vec::new(),
            };
            blob.extend(a.bytes("blob")?);
            match Image::new(&blob) {
                Ok(image) => Outcome::ok(json!({ "width": image.width(), "height": image.height() })),
                Err(e) => err(e),
            }
        }
        "pipeline" => match pipeline(&a)? {
            Ok(blob) => Outcome::ok(summary(&blob)?),
            Err(e) => err(e),
        },
        "image.crop" => {
            let (width, height) = (dimension(&a, "width")?, dimension(&a, "height")?);
            let gravity = match a.opt_str("gravity")? {
                None => Gravity::Center,
                Some(name) => Gravity::from_name(name)
                    .ok_or_else(|| Fault::new(format!("unknown gravity `{name}` (deviation)")))?,
            };
            let focal = match FocalPoint::from_parts(opt_float(&a, "x")?, opt_float(&a, "y")?) {
                Ok(f) => f,
                Err(e) => return Ok(err(e)),
            };
            with_image!(|image| image.crop(width, height, gravity, focal))
        }
        "image.set_border" => {
            let width = dimension(&a, "width")?;
            let color = a.str("color")?;
            with_image!(|image| image.set_border(width, color))
        }
        "image.set_border_radius" => {
            let radius = dimension(&a, "radius")?;
            with_image!(|image| image.set_border_radius(radius))
        }
        "image.set_opacity" => {
            let opacity = float(a.value("opacity")?).ok_or_else(|| Fault::new("`opacity` must be a number"))?;
            session.get_mut::<Image>(a.value("image")?)?.set_opacity(opacity);
            Outcome::Ok(Value::Null)
        }
        "image.set_rotation" => {
            let degree = a.i64("degree")?;
            with_image!(|image| image.set_rotation(degree))
        }
        "image.set_background" => {
            let color = a.str("color")?;
            with_image!(|image| image.set_background(color))
        }
        "image.output" => match output(&a, session)? {
            Ok(blob) => Outcome::ok(summary(&blob)?),
            Err(e) => err(e),
        },
        "image.save" => {
            let format = match a.str("type")?.parse::<OutputFormat>() {
                Ok(f) => f,
                Err(e) => return Ok(err(e)),
            };
            let quality = a.opt_i64("quality")?.unwrap_or(75);
            let path = a.str("path")?;
            with_image!(|image| image.save(path, format, quality))
        }
        "image.pixel" => match output(&a, session)? {
            Ok(blob) => {
                let frames = frames(&blob)?;
                let frame =
                    frames.get(a.opt_i64("frame")?.unwrap_or(0) as usize).ok_or_else(|| Fault::new("no such frame"))?;
                let p = frame.get_pixel(a.i64("x")? as u32, a.i64("y")? as u32);
                let dominant = ["r", "g", "b"]
                    .into_iter()
                    .enumerate()
                    .find(|&(i, _)| (0..3).all(|j| j == i || p[i] > p[j]))
                    .map(|(_, c)| c);
                Outcome::ok(dominant)
            }
            Err(e) => err(e),
        },
        "image.compare" => match output(&a, session)? {
            Ok(blob) => {
                let reference = read(a.str("path")?)?;
                Outcome::ok(compare(&blob, &reference, a.opt_bool("debug")?.unwrap_or(false))?)
            }
            Err(e) => err(e),
        },
        "image.size_by_fixed_height" => {
            let height = a.i64("height")?;
            match session.get::<Image>(a.value("image")?)?.size_by_fixed_height(height) {
                Ok(v) => Outcome::ok(v),
                Err(e) => err(e),
            }
        }
        "image.size_by_fixed_width" => {
            let width = a.i64("width")?;
            match session.get::<Image>(a.value("image")?)?.size_by_fixed_width(width) {
                Ok(v) => Outcome::ok(v),
                Err(e) => err(e),
            }
        }
        "image.gravity_types" => Outcome::ok(Gravity::ALL.iter().map(|g| g.name()).collect::<Vec<_>>()),
        "image.set_resource_limit" => {
            let name = a.str("type")?;
            let resource = Resource::from_name(name)
                .ok_or_else(|| Fault::new(format!("unknown resource `{name}` (deviation)")))?;
            set_resource_limit(resource, a.i64("value")?);
            Outcome::Ok(Value::Null)
        }
        "file.probe" => {
            let blob = read(a.str("path")?)?;
            Outcome::ok(summary(&blob)?)
        }
        "file.compare" => {
            let blob = read(a.str("path")?)?;
            let reference = read(a.str("reference")?)?;
            Outcome::ok(compare(&blob, &reference, a.opt_bool("debug")?.unwrap_or(false))?)
        }
        _ => return Err(Fault::new(format!("image: unknown operation `{op}`"))),
    })
}

/// The `pipeline` fuzz target: every setter the arguments ask for, then output.
fn pipeline(a: &Args) -> Result<Result<Vec<u8>, Error>, Fault> {
    let path = a.str("path")?;
    let blob = read(path)?;
    let gravity = Gravity::from_name(a.str("gravity")?).ok_or_else(|| Fault::new("unknown gravity"))?;
    let (width, height) = (dimension(a, "width")?, dimension(a, "height")?);
    let format = a.str("type")?.parse::<OutputFormat>();
    let quality = a.i64("quality")?;
    let run = || -> Result<Vec<u8>, Error> {
        let mut image = Image::new(&blob)?;
        let focal = FocalPoint::from_parts(opt_float(a, "x").ok().flatten(), opt_float(a, "y").ok().flatten())?;
        image.crop(width, height, gravity, focal)?;
        if let Some(border) = a.opt_i64("border").ok().flatten() {
            image.set_border(border as u32, a.str("color").unwrap_or(""))?;
        }
        if let Some(radius) = a.opt_i64("radius").ok().flatten() {
            image.set_border_radius(radius as u32)?;
        }
        if let Some(opacity) = a.opt("opacity").and_then(float) {
            image.set_opacity(opacity);
        }
        if let Some(rotation) = a.opt_i64("rotation").ok().flatten() {
            image.set_rotation(rotation)?;
        }
        if let Some(background) = a.opt_str("background").ok().flatten() {
            image.set_background(background)?;
        }
        image.output(format?, quality)
    };
    Ok(run())
}

/// The container named by a blob's magic bytes.
fn format(blob: &[u8]) -> &'static str {
    if blob.starts_with(&[0xFF, 0xD8, 0xFF]) {
        "JPEG"
    } else if blob.starts_with(b"\x89PNG\r\n\x1a\n") {
        "PNG"
    } else if blob.starts_with(b"GIF87a") || blob.starts_with(b"GIF89a") {
        "GIF"
    } else if blob.len() >= 12 && &blob[..4] == b"RIFF" && &blob[8..12] == b"WEBP" {
        "WEBP"
    } else if blob.len() >= 12 && &blob[4..8] == b"ftyp" {
        if matches!(&blob[8..12], b"avif" | b"avis") { "AVIF" } else { "HEIC" }
    } else {
        "unknown"
    }
}

/// The `ispe` (image spatial extent) of an ISOBMFF image: AVIF's size.
fn ispe(blob: &[u8]) -> Option<(u32, u32)> {
    let at = blob.windows(4).position(|w| w == b"ispe")?;
    let read = |i: usize| blob.get(i..i + 4).map(|b| u32::from_be_bytes([b[0], b[1], b[2], b[3]]));
    Some((read(at + 8)?, read(at + 12)?))
}

fn summary(blob: &[u8]) -> Result<Value, Fault> {
    let fmt = format(blob);
    let (width, height, frames, delay) = match fmt {
        "GIF" => {
            let mut options = gif::DecodeOptions::new();
            options.set_color_output(gif::ColorOutput::Indexed);
            let mut decoder = options.read_info(Cursor::new(blob)).map_err(|e| Fault::new(e.to_string()))?;
            let (mut first, mut count, mut delay) = (None, 0u64, 0u64);
            while let Some(frame) = decoder.read_next_frame().map_err(|e| Fault::new(e.to_string()))? {
                first.get_or_insert((u32::from(frame.width), u32::from(frame.height)));
                count += 1;
                delay += u64::from(frame.delay);
            }
            let (w, h) = first.ok_or_else(|| Fault::new("GIF without frames"))?;
            (w, h, count, delay)
        }
        "WEBP" => {
            let decoder = image_webp::WebPDecoder::new(Cursor::new(blob)).map_err(|e| Fault::new(e.to_string()))?;
            let (w, h) = decoder.dimensions();
            if decoder.is_animated() {
                let delay: u64 = frames_with_delay(blob)?.iter().map(|(_, d)| u64::from(*d)).sum();
                (w, h, u64::from(decoder.num_frames()), delay)
            } else {
                (w, h, 1, 0)
            }
        }
        "AVIF" | "HEIC" => {
            let (w, h) = ispe(blob).ok_or_else(|| Fault::new("no ispe box"))?;
            (w, h, 1, 0)
        }
        _ => {
            let image = image::load_from_memory(blob).map_err(|e| Fault::new(e.to_string()))?;
            (image.width(), image.height(), 1, 0)
        }
    };
    Ok(json!({ "format": fmt, "width": width, "height": height, "frames": frames, "delay": delay }))
}

/// The coalesced frames of a blob with their delays in centiseconds.
fn frames_with_delay(blob: &[u8]) -> Result<Vec<(image::RgbaImage, u32)>, Fault> {
    let fault = |e: image::ImageError| Fault::new(e.to_string());
    let collect = |frames: image::Frames<'_>| -> Result<Vec<(image::RgbaImage, u32)>, Fault> {
        frames
            .map(|f| {
                let f = f.map_err(fault)?;
                let (n, d) = f.delay().numer_denom_ms();
                let ms = n.checked_div(d).unwrap_or(0);
                Ok((f.into_buffer(), ms / 10))
            })
            .collect()
    };
    match format(blob) {
        "GIF" => collect(image::codecs::gif::GifDecoder::new(Cursor::new(blob)).map_err(fault)?.into_frames()),
        "WEBP" => {
            let decoder = image::codecs::webp::WebPDecoder::new(Cursor::new(blob)).map_err(fault)?;
            if decoder.has_animation() {
                collect(decoder.into_frames())
            } else {
                Ok(vec![(image::load_from_memory(blob).map_err(fault)?.into_rgba8(), 0)])
            }
        }
        _ => Ok(vec![(image::load_from_memory(blob).map_err(fault)?.into_rgba8(), 0)]),
    }
}

fn frames(blob: &[u8]) -> Result<Vec<image::RgbaImage>, Fault> {
    Ok(frames_with_delay(blob)?.into_iter().map(|(f, _)| f).collect())
}

/// A frame as a 16x16 grid of premultiplied RGBA (0-255), each cell the area
/// average of the pixels under it (as ImageMagick's scaleImage).
fn grid(frame: &image::RgbaImage) -> Vec<f64> {
    let (w, h) = (frame.width() as usize, frame.height() as usize);
    // Per output cell, the source pixels it covers and by how much.
    let weights = |n: usize| -> Vec<Vec<(usize, f64)>> {
        (0..GRID)
            .map(|c| {
                let (lo, hi) = (c as f64 * n as f64 / GRID as f64, (c + 1) as f64 * n as f64 / GRID as f64);
                (lo.floor() as usize..(hi.ceil() as usize).min(n))
                    .filter_map(|i| {
                        let overlap = (hi.min(i as f64 + 1.0) - lo.max(i as f64)).max(0.0);
                        (overlap > 0.0).then_some((i, overlap))
                    })
                    .collect()
            })
            .collect()
    };
    let (wx, wy) = (weights(w), weights(h));
    let mut out = Vec::with_capacity(GRID * GRID * 4);
    for row in &wy {
        for col in &wx {
            let mut acc = [0.0f64; 4];
            let mut total = 0.0;
            for &(y, ky) in row {
                for &(x, kx) in col {
                    let p = frame.get_pixel(x as u32, y as u32);
                    let a = f64::from(p[3]) / 255.0;
                    let k = kx * ky;
                    for (i, slot) in acc.iter_mut().take(3).enumerate() {
                        *slot += f64::from(p[i]) * a * k;
                    }
                    acc[3] += a * 255.0 * k;
                    total += k;
                }
            }
            out.extend(acc.iter().map(|v| if total > 0.0 { v / total } else { 0.0 }));
        }
    }
    out
}

/// Whether two encoded images match, as Adapter.php's `compare`.
fn compare(blob: &[u8], reference: &[u8], debug: bool) -> Result<Value, Fault> {
    let (a, b) = (summary(blob)?, summary(reference)?);
    let mut result = json!({
        "size": a["width"] == b["width"] && a["height"] == b["height"],
        "frames": a["frames"] == b["frames"],
        "similar": null,
    });
    let undecodable = |s: &Value| matches!(s["format"].as_str(), Some("AVIF" | "HEIC"));
    if undecodable(&a) || undecodable(&b) {
        return Ok(result);
    }
    let (ga, gb) = (frames(blob)?, frames(reference)?);
    let mut worst = 0.0f64;
    for (x, y) in ga.iter().zip(gb.iter()) {
        let (x, y) = (grid(x), grid(y));
        let sum: f64 = x.iter().zip(&y).map(|(p, q)| (p - q).abs()).sum();
        worst = worst.max(sum / x.len() as f64);
    }
    result["similar"] = json!(worst <= TOLERANCE);
    if debug {
        result["mae"] = json!((worst * 100.0).round() / 100.0);
    }
    Ok(result)
}
