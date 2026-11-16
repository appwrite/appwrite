//! `GET /v1/storage/buckets/:bucketId/files/:fileId/preview`.
//! PHP `Http/Buckets/Files/Preview/Get.php`.

use std::str::FromStr;

use serde_json::{Value as Json, json};
use utopia_database::Document;
use utopia_http::{Response, StatusCode};
use utopia_image::{Canvas, FocalPoint, Gravity, Limits, OutputFormat, Transform};
use utopia_validators::php::Number;
use utopia_validators::{HexColor, Range, Type, WhiteList};

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, PREVIEW_MAX_AGE, PROJECT_ACCESS, READ_BUFFER};
use crate::device::Stored;

use super::super::send::{decompress, decrypt, ids, load_file};

const INPUTS: &[&str] = &["image/jpeg", "image/gif", "image/png", "image/heic", "image/webp"];
const GRAVITY: &[&str] =
    &["auto", "center", "top-left", "top", "top-right", "left", "right", "bottom-left", "bottom", "bottom-right"];
const OUTPUTS: &[&str] = &["jpg", "jpeg", "png", "webp", "heic", "avif", "gif"];

pub async fn get(ctx: &mut Context) -> Result<Response> {
    let (bucket_id, file_id) = ids(ctx)?;
    let width = optional_i64(ctx, "width", 0, 0, 4000)?;
    let height = optional_i64(ctx, "height", 0, 0, 4000)?;
    let gravity =
        ctx.optional_str("gravity", "center", &WhiteList::strict(GRAVITY))?.unwrap_or_else(|| "center".into());
    let quality = optional_i64(ctx, "quality", -1, -1, 100)?;
    let border_width = optional_i64(ctx, "borderWidth", 0, 0, 100)?;
    let border_color = ctx.optional_str("borderColor", "", &HexColor)?.unwrap_or_default();
    let border_radius = optional_i64(ctx, "borderRadius", 0, 0, 4000)?;
    let opacity = optional_opacity(ctx)?;
    let rotation = optional_i64(ctx, "rotation", 0, -360, 360)?;
    let mut background = ctx.optional_str("background", "", &HexColor)?.unwrap_or_default();
    let output = ctx.optional_str("output", "", &WhiteList::strict(OUTPUTS))?.unwrap_or_default();

    let db = ctx.db()?;
    let bucket = crate::http::visible_bucket(ctx, &db, &bucket_id).await?;
    if bucket.boolean("transformations") == Some(false) && !ctx.is_key() && !ctx.is_privileged() {
        return Err(Error::new(ErrorType::StorageBucketTransformationsDisabled));
    }
    let file = load_file(ctx, &db, &bucket, &file_id).await?;
    let mut path = file.string_or_empty("path");
    let mut algorithm = file.string_or_empty("algorithm");
    let mut cipher = file.string_or_empty("openSSLCipher");
    let mime = file.string_or_empty("mimeType");
    let preview_limit = appwrite_core::config::env_int("_APP_STORAGE_PREVIEW_LIMIT", READ_BUFFER);
    let mut device = Stored::for_project(&ctx.project.id)?;
    let image = INPUTS.contains(&mime.as_str());
    if !image || base::long(&file, "sizeOriginal") > preview_limit {
        path = logo_path(if image { "image.png" } else { logo_name(&mime) });
        algorithm = "none".to_owned();
        cipher.clear();
        if background.is_empty() {
            background = "eceff1".to_owned();
        }
        device = Stored::local_root();
    }
    if !device.exists(&path).await? {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    let mut source = base::read_all(&device, &path).await?;
    if !cipher.is_empty() {
        source = decrypt(&ctx.state.config.openssl_key_v1, &file, &source)?;
    }
    source = decompress(&algorithm, source)?;
    let focal = focal_point(&gravity, width, height, &source).await?;
    let chosen = choose_output(&output, &path, &mime);
    let content_type = output_type(&chosen).unwrap_or("image/jpeg").to_owned();
    let limits = image_limits();
    let rendered = utopia_async::spawn_blocking(move || {
        render(
            source,
            width,
            height,
            gravity,
            quality,
            border_width,
            border_color,
            border_radius,
            opacity,
            rotation,
            background,
            chosen,
            focal,
            limits,
        )
    })
    .await
    .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))??;

    if !ctx.is_privileged() {
        let now = appwrite_core::datetime::now();
        let stale = file
            .datetime("transformedAt")
            .is_none_or(|at| at < appwrite_core::datetime::add_seconds(now, -PROJECT_ACCESS));
        if stale {
            let mut updates = Document::default();
            updates.put("transformedAt", appwrite_core::datetime::value(&now));
            let collection = base::bucket_collection(&bucket);
            db.authorization().skip(db.update_document(&collection, file.id(), updates)).await.map_err(base::map_db)?;
        }
    }
    ctx.response_headers.push(("cache-control", format!("private, max-age={PREVIEW_MAX_AGE}")));
    Ok(Response::with_body(StatusCode::OK, &content_type, rendered))
}

/// `gravity=auto`: the focal point from the Autogravity service when the
/// crop changes the aspect ratio, otherwise center.
async fn focal_point(gravity: &str, width: i64, height: i64, source: &[u8]) -> Result<Option<FocalPoint>> {
    if gravity != "auto" {
        return Ok(None);
    }
    let host = utopia_system::env("_APP_AUTOGRAVITY_HOST").unwrap_or_default();
    if host.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralArgumentInvalid,
            "Autogravity needs to be configured with _APP_AUTOGRAVITY_HOST to use automatic gravity",
        ));
    }
    let Some((source_width, source_height)) = dimensions(source).filter(|&(w, h)| w > 0 && h > 0) else {
        return Ok(None);
    };
    if width <= 0 || height <= 0 {
        return Ok(None);
    }
    let requested = width as f64 / height as f64;
    let original = source_width as f64 / source_height as f64;
    if (requested - original).abs() <= 0.000001 {
        return Ok(None);
    }
    let (mut x, mut y) = analyze(host.trim_end_matches('/'), source).await?;
    (x, y) = unrotate(x, y, orientation(source));
    FocalPoint::new(x, y)
        .map(Some)
        .map_err(|err| Error::with_message(ErrorType::GeneralArgumentInvalid, err.to_string()))
}

async fn analyze(host: &str, source: &[u8]) -> Result<(f64, f64)> {
    let client = utopia_client::Client::new(utopia_client::Http::new())
        .with_timeout(30.0)
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?;
    let request = utopia_client::Request::new("POST", &format!("{host}/analyze"))
        .and_then(|request| request.header("content-type", "application/octet-stream"))
        .map(|request| request.body(source.to_vec()))
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?;
    let response = client
        .send(request)
        .await
        .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string()))?;
    let status = response.status();
    let body = response.text().into_owned();
    let parsed: Json = serde_json::from_str(&body).unwrap_or(Json::Null);
    if status >= 400 {
        let message = parsed.get("error").and_then(Json::as_str).unwrap_or("Autogravity failed");
        return Err(Error::with_message(ErrorType::GeneralServerError, message.to_string()));
    }
    let x = parsed.pointer("/gravity/x").and_then(Json::as_f64);
    let y = parsed.pointer("/gravity/y").and_then(Json::as_f64);
    match (x, y) {
        (Some(x), Some(y)) => Ok((x, y)),
        _ => Err(Error::with_message(ErrorType::GeneralServerError, "Autogravity returned an invalid gravity")),
    }
}

/// `Gravity::unrotate`. Orientation 6 is 90 degrees, 8 is -90, 3 is 180.
fn unrotate(x: f64, y: f64, rotation: i32) -> (f64, f64) {
    match rotation.rem_euclid(360) {
        0 => (x, y),
        90 => (y, 1.0 - x),
        180 => (1.0 - x, 1.0 - y),
        270 => (1.0 - y, x),
        _ => (x, y),
    }
}

/// JPEG `exif:Orientation`, mapped the way Imagick reports it. Other images are 0.
fn orientation(data: &[u8]) -> i32 {
    let Some(value) = jpeg_orientation(data) else { return 0 };
    match value {
        3 => 180,
        6 => 90,
        8 => -90,
        _ => 0,
    }
}

fn jpeg_orientation(data: &[u8]) -> Option<u16> {
    if data.len() < 4 || data[0] != 0xFF || data[1] != 0xD8 {
        return None;
    }
    let mut index = 2;
    while index + 4 < data.len() {
        if data[index] != 0xFF {
            index += 1;
            continue;
        }
        let marker = data[index + 1];
        let len = u16::from_be_bytes([data[index + 2], data[index + 3]]) as usize;
        if len < 2 || index + 2 + len > data.len() {
            break;
        }
        if marker == 0xE1 {
            let segment = &data[index + 4..index + 2 + len];
            if let Some(value) = exif_orientation(segment) {
                return Some(value);
            }
        }
        if marker == 0xDA {
            break;
        }
        index += 2 + len;
    }
    None
}

fn exif_orientation(segment: &[u8]) -> Option<u16> {
    let rest = segment.strip_prefix(b"Exif\0\0")?;
    if rest.len() < 8 {
        return None;
    }
    let le = rest.starts_with(b"II");
    let be = rest.starts_with(b"MM");
    if !le && !be {
        return None;
    }
    let u16_at = |offset: usize| -> Option<u16> {
        let bytes: [u8; 2] = rest.get(offset..offset + 2)?.try_into().ok()?;
        Some(if le { u16::from_le_bytes(bytes) } else { u16::from_be_bytes(bytes) })
    };
    let u32_at = |offset: usize| -> Option<u32> {
        let bytes: [u8; 4] = rest.get(offset..offset + 4)?.try_into().ok()?;
        Some(if le { u32::from_le_bytes(bytes) } else { u32::from_be_bytes(bytes) })
    };
    let ifd = u32_at(4)? as usize;
    let count = u16_at(ifd)? as usize;
    for entry in 0..count {
        let at = ifd + 2 + entry * 12;
        if u16_at(at)? == 0x0112 {
            return u16_at(at + 8);
        }
    }
    None
}

/// What one image may cost to decode and transform (`storage-image-limits`):
/// a value that is not a positive number falls back to its default.
fn image_limits() -> Limits {
    let limit = |name: &str, default: u64| match appwrite_core::config::env_int(name, default as i64) {
        value if value > 0 => value as u64,
        _ => default,
    };
    Limits {
        width: limit("_APP_IMAGES_LIMIT_WIDTH", 50_000),
        height: limit("_APP_IMAGES_LIMIT_HEIGHT", 50_000),
        memory: limit("_APP_IMAGES_LIMIT_MEMORY", 576 * 1024 * 1024),
    }
}

#[allow(clippy::too_many_arguments)]
fn render(
    source: Vec<u8>,
    width: i64,
    height: i64,
    gravity: String,
    quality: i64,
    border_width: i64,
    border_color: String,
    border_radius: i64,
    opacity: f64,
    rotation: i64,
    background: String,
    output: String,
    focal: Option<FocalPoint>,
    limits: Limits,
) -> Result<Vec<u8>> {
    // Reads the header only; the pixels are decoded once, by encode() below.
    let canvas = Canvas::decode(&source, limits).map_err(|err| image_error(err, &source))?;
    let gravity =
        if gravity == "auto" { Gravity::Center } else { Gravity::from_name(&gravity).unwrap_or(Gravity::Center) };
    let transform = Transform {
        width: width as u32,
        height: height as u32,
        gravity,
        focus: focal,
        opacity,
        background: (!background.is_empty()).then(|| format!("#{background}")),
        border: (border_width > 0).then(|| (border_width as u32, format!("#{border_color}"))),
        radius: border_radius as u32,
        rotation: (rotation + 360) % 360,
    };
    let format = OutputFormat::from_str(&output)
        .map_err(|err| Error::with_message(ErrorType::StorageFileTypeUnsupported, err.to_string()))?;
    let quality = (quality != -1).then_some(quality.clamp(0, 100) as u8);
    canvas
        .apply(transform)
        .and_then(|transformed| transformed.encode(format, quality))
        .map_err(|err| image_error(err, &source))
}

/// A canvas over its limits is `storage_image_resolution_exceeded`, with the
/// source's size as `getimagesizefromstring` reads it; anything else (damaged
/// pixels, a format this build cannot encode) is `storage_file_type_unsupported`.
fn image_error(err: utopia_image::Error, source: &[u8]) -> Error {
    match err {
        utopia_image::Error::LimitExceeded => {
            let (w, h) = dimensions(source).unwrap_or((0, 0));
            Error::with_message(
                ErrorType::StorageImageResolutionExceeded,
                format!("Image resolution {w}x{h} exceeds the maximum allowed for processing"),
            )
        }
        err => Error::with_message(ErrorType::StorageFileTypeUnsupported, err.to_string()),
    }
}

fn dimensions(data: &[u8]) -> Option<(u64, u64)> {
    if data.starts_with(b"\x89PNG\r\n\x1a\n") && data.len() >= 24 {
        let width = u32::from_be_bytes(data[16..20].try_into().ok()?) as u64;
        let height = u32::from_be_bytes(data[20..24].try_into().ok()?) as u64;
        return Some((width, height));
    }
    if (data.starts_with(b"GIF87a") || data.starts_with(b"GIF89a")) && data.len() >= 10 {
        let width = u16::from_le_bytes(data[6..8].try_into().ok()?) as u64;
        let height = u16::from_le_bytes(data[8..10].try_into().ok()?) as u64;
        return Some((width, height));
    }
    jpeg_size(data)
}

fn jpeg_size(data: &[u8]) -> Option<(u64, u64)> {
    if data.len() < 4 || data[0] != 0xFF || data[1] != 0xD8 {
        return None;
    }
    let mut index = 2;
    while index + 8 < data.len() {
        if data[index] != 0xFF {
            index += 1;
            continue;
        }
        let marker = data[index + 1];
        if matches!(marker, 0xC0..=0xC2) {
            let height = u16::from_be_bytes([data[index + 5], data[index + 6]]) as u64;
            let width = u16::from_be_bytes([data[index + 7], data[index + 8]]) as u64;
            return Some((width, height));
        }
        let len = u16::from_be_bytes([data[index + 2], data[index + 3]]) as usize;
        if len < 2 {
            break;
        }
        index += 2 + len;
    }
    None
}

fn choose_output(requested: &str, path: &str, mime: &str) -> String {
    if !requested.is_empty() {
        return requested.to_owned();
    }
    let mut ext = base::extension(path).to_ascii_lowercase();
    if !ext.is_empty() && output_type(&ext).is_none() {
        ext = "jpg".to_owned();
    }
    if ext.is_empty() { mime_output(mime) } else { ext }
}

fn output_type(ext: &str) -> Option<&'static str> {
    Some(match ext {
        "jpg" | "jpeg" => "image/jpeg",
        "png" => "image/png",
        "webp" => "image/webp",
        "heic" => "image/heic",
        "avif" => "image/avif",
        "gif" => "image/gif",
        _ => return None,
    })
}

fn mime_output(mime: &str) -> String {
    match mime {
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "image/heic" => "heic",
        "image/avif" => "avif",
        "image/gif" => "gif",
        _ => "jpg",
    }
    .to_owned()
}

fn logo_name(mime: &str) -> &'static str {
    match mime {
        "video/mp4"
        | "video/x-flv"
        | "video/webm"
        | "application/x-mpegURL"
        | "video/MP2T"
        | "video/3gpp"
        | "video/quicktime"
        | "video/x-msvideo"
        | "video/x-ms-wmv" => "video.png",
        _ => "none.png",
    }
}

fn logo_path(name: &str) -> String {
    let root = utopia_system::env("_APP_RUST_ASSETS").unwrap_or_else(|| "/usr/src/code".to_owned());
    format!("{root}/app/config/storage/logos/{name}")
}

fn optional_i64(ctx: &Context, key: &str, default: i64, min: i64, max: i64) -> Result<i64> {
    let value = ctx.optional(key, json!(default), &Range::new(min, max))?;
    Ok(Range::value(&value).unwrap_or(default))
}

fn optional_opacity(ctx: &Context) -> Result<f64> {
    let value =
        ctx.optional("opacity", json!(1), &Range::with_format(Number::Float(0.0), Number::Float(1.0), Type::Float))?;
    Ok(value.as_f64().or_else(|| value.as_i64().map(|n| n as f64)).unwrap_or(1.0))
}
