//! Download, view, preview and push.

use std::str::FromStr;

use serde_json::{Value as Json, json};
use utopia_compression::{Algorithm, Gzip, Zstd};
use utopia_database::{Database, Document};
use utopia_http::{Aborted, Response, StatusCode};
use utopia_image::{FocalPoint, Gravity, Image, OutputFormat, Resource, set_resource_limit};
use utopia_validators::php::Number;
use utopia_validators::{HexColor, Range, Text, Type, WhiteList};

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{
    self, DOWNLOAD_MAX_AGE, OUTPUT_CHUNK, PREVIEW_MAX_AGE, PROJECT_ACCESS, READ_BUFFER, token_for_bucket,
    token_matches_file,
};
use crate::device::Stored;

const INPUTS: &[&str] = &["image/jpeg", "image/gif", "image/png", "image/heic", "image/webp"];
const GRAVITY: &[&str] =
    &["auto", "center", "top-left", "top", "top-right", "left", "right", "bottom-left", "bottom", "bottom-right"];
const OUTPUTS: &[&str] = &["jpg", "jpeg", "png", "webp", "heic", "avif", "gif"];
const MIMES: &[&str] = &[
    "image/jpeg",
    "image/gif",
    "image/png",
    "image/webp",
    "image/heic",
    "image/heic-sequence",
    "image/avif",
    "image/svg+xml",
    "video/mp4",
    "video/x-flv",
    "video/webm",
    "application/x-mpegURL",
    "video/MP2T",
    "video/3gpp",
    "video/quicktime",
    "video/x-msvideo",
    "video/x-ms-wmv",
    "audio/basic",
    "auido/L24",
    "audio/mid",
    "audio/mpeg",
    "audio/mp4",
    "audio/x-aiff",
    "audio/x-mpegurl",
    "audio/vnd.rn-realaudio",
    "audio/ogg",
    "audio/vorbis",
    "audio/vnd.wav",
    "audio/x-wav",
    "audio/aac",
    "audio/x-hx-aac-adts",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.template",
    "application/vnd.ms-word.document.macroEnabled.12",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.template",
    "application/vnd.ms-excel.sheet.macroEnabled.12",
    "application/vnd.ms-excel.template.macroEnabled.12",
    "application/vnd.ms-excel.addin.macroEnabled.12",
    "application/vnd.ms-excel.sheet.binary.macroEnabled.12",
    "application/vnd.ms-powerpoint",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.openxmlformats-officedocument.presentationml.template",
    "application/vnd.openxmlformats-officedocument.presentationml.slideshow",
    "application/vnd.ms-powerpoint.addin.macroEnabled.12",
    "application/vnd.ms-powerpoint.presentation.macroEnabled.12",
    "application/vnd.ms-powerpoint.template.macroEnabled.12",
    "application/vnd.ms-powerpoint.slideshow.macroEnabled.12",
    "application/vnd.ms-access",
    "application/pdf",
];

/// `GET /v1/storage/buckets/:bucketId/files/:fileId/download`.
pub async fn download(ctx: &mut Context) -> Result<Response> {
    let (bucket_id, file_id) = ids(ctx)?;
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = load_file(ctx, &db, &bucket, &file_id).await?;
    send(ctx, &file, &file.string_or_empty("mimeType"), "attachment", "", false, OUTPUT_CHUNK).await
}

/// `GET /v1/storage/buckets/:bucketId/files/:fileId/view`.
pub async fn view(ctx: &mut Context) -> Result<Response> {
    let (bucket_id, file_id) = ids(ctx)?;
    let db = ctx.db()?;
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let file = load_file(ctx, &db, &bucket, &file_id).await?;
    let mime = file.string_or_empty("mimeType");
    let content_type = if MIMES.contains(&mime.as_str()) { mime.clone() } else { "text/plain".to_owned() };
    let disposition = if content_type == "image/svg+xml" { "attachment" } else { "inline" };
    send(ctx, &file, &content_type, disposition, "script-src 'none';", true, READ_BUFFER).await
}

/// `GET /v1/storage/buckets/:bucketId/files/:fileId/push`.
pub async fn push(ctx: &mut Context) -> Result<Response> {
    let bucket_id = ctx.required_str("bucketId", &uid())?;
    let file_id = ctx.required_str("fileId", &uid())?;
    let jwt = ctx.optional_str("jwt", "", &Text::with_min(2048, 0))?.unwrap_or_default();
    let claims = appwrite_core::auth::jwt::decode(&ctx.state.config.openssl_key_v1, &jwt, 3600)
        .map_err(|_| Error::new(ErrorType::UserUnauthorized))?;
    if claim(&claims, "projectId") != ctx.project.id
        || claim(&claims, "bucketId") != bucket_id
        || claim(&claims, "fileId") != file_id
    {
        return Err(Error::new(ErrorType::UserUnauthorized));
    }
    let disposition = {
        let value = claim(&claims, "disposition");
        if value.is_empty() { "inline".to_owned() } else { value }
    };
    let internal = claim_truthy(claims.get("internal"));
    let db = if internal { ctx.platform_db()? } else { ctx.db()? };
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
    let collection = base::bucket_collection(&bucket);
    let file = db
        .authorization()
        .skip(db.get_document(&collection, &file_id, Vec::new(), false))
        .await
        .map_err(base::map_db)?;
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    let device = Stored::for_project(&ctx.project.id)?;
    let path = file.string_or_empty("path");
    if !device.exists(&path).await? {
        return Err(Error::with_message(ErrorType::StorageFileNotFound, format!("File not found in {path}")));
    }
    let mime = file.string_or_empty("mimeType");
    let content_type = if MIMES.contains(&mime.as_str()) { mime } else { "text/plain".to_owned() };
    let response =
        serve(ctx, &device, &file, &content_type, &disposition, "script-src none;", true, READ_BUFFER).await?;
    Ok(response)
}

/// `GET /v1/storage/buckets/:bucketId/files/:fileId/preview`.
pub async fn preview(ctx: &mut Context) -> Result<Response> {
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
    let bucket = super::visible_bucket(ctx, &db, &bucket_id).await?;
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
    let memory = appwrite_core::config::env_int("_APP_IMAGES_RESOURCE_LIMIT_MEMORY", 64 * 1024 * 1024);
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
            memory,
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

fn ids(ctx: &Context) -> Result<(String, String)> {
    Ok((ctx.required_str("bucketId", &uid())?, ctx.required_str("fileId", &uid())?))
}

async fn load_file(ctx: &Context, db: &Database, bucket: &Document, file_id: &str) -> Result<Document> {
    let secured = bucket.boolean("fileSecurity").unwrap_or(false);
    let permitted = base::allows(ctx, bucket, utopia_database::PermissionType::Read)?;
    let token = token_for_bucket(ctx, bucket);
    if !secured && !permitted && !token {
        return Err(base::unauthorized(ctx));
    }
    let collection = base::bucket_collection(bucket);
    let file = if secured && !permitted && !token {
        db.get_document(&collection, file_id, Vec::new(), false).await.map_err(base::map_db)?
    } else {
        db.authorization().skip(db.get_document(&collection, file_id, Vec::new(), false)).await.map_err(base::map_db)?
    };
    token_matches_file(ctx, &file)?;
    if file.is_empty() {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    Ok(file)
}

async fn send(
    ctx: &mut Context,
    file: &Document,
    content_type: &str,
    disposition: &str,
    csp: &str,
    nosniff: bool,
    open_end: i64,
) -> Result<Response> {
    let device = Stored::for_project(&ctx.project.id)?;
    serve(ctx, &device, file, content_type, disposition, csp, nosniff, open_end).await
}

#[allow(clippy::too_many_arguments)]
async fn serve(
    ctx: &Context,
    device: &Stored,
    file: &Document,
    content_type: &str,
    disposition: &str,
    csp: &str,
    nosniff: bool,
    open_end: i64,
) -> Result<Response> {
    let path = file.string_or_empty("path");
    if !device.exists(&path).await? {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    let size = base::long(file, "sizeOriginal");
    let ranged = range_of(ctx, size, open_end)?;
    let status = if ranged.is_some() { StatusCode::PARTIAL_CONTENT } else { StatusCode::OK };
    let encrypted = !file.string_or_empty("openSSLCipher").is_empty();
    let algorithm = file.string_or_empty("algorithm");
    let mut response = if encrypted || matches!(algorithm.as_str(), "zstd" | "gzip") {
        // Like PHP, the whole object: encrypted and compressed files are at
        // most `APP_STORAGE_READ_BUFFER` (20 MB) before the transformation.
        let mut bytes = base::read_all(device, &path).await?;
        if encrypted {
            bytes = decrypt(&ctx.state.config.openssl_key_v1, file, &bytes)?;
        }
        bytes = decompress(&algorithm, bytes)?;
        let body = match ranged {
            Some((start, end)) => slice(&bytes, start, end),
            None => bytes,
        };
        Response::with_body(status, content_type, body)
    } else if let Some((start, end)) = ranged {
        // Only the requested bytes (at most `APP_STORAGE_READ_BUFFER`).
        let length = u64::try_from(end - start + 1).unwrap_or(0);
        let body = device.read(&path, u64::try_from(start).unwrap_or(0), Some(length)).await?;
        let bytes =
            body.bytes().await.map_err(|e| Error::with_message(ErrorType::GeneralServerError, e.to_string()))?;
        Response::with_body(status, content_type, bytes)
    } else if size <= READ_BUFFER {
        Response::with_body(status, content_type, base::read_all(device, &path).await?)
    } else {
        // One read pins the object; its chunks go out as the client takes them.
        let body = device.read(&path, 0, None).await?;
        let mut response = Response::with_body(status, content_type, Vec::new());
        let length = u64::try_from(size).unwrap_or(0);
        response.stage_stream(chunks(body, length), usize::try_from(length).unwrap_or(usize::MAX));
        response
    };
    if let Some((start, end)) = ranged {
        response.add("accept-ranges", "bytes");
        response.add("content-range", &format!("bytes {start}-{end}/{size}"));
        response.add("content-length", &(end - start + 1).to_string());
    }
    response.add("cache-control", &format!("private, max-age={DOWNLOAD_MAX_AGE}"));
    response.add("content-disposition", &format!("{disposition}; filename=\"{}\"", file.string_or_empty("name")));
    if !csp.is_empty() {
        response.add("content-security-policy", csp);
    }
    if nosniff {
        response.add("x-content-type-options", "nosniff");
    }
    Ok(response)
}

fn range_of(ctx: &Context, size: i64, open_end: i64) -> Result<Option<(i64, i64)>> {
    let header = ctx.request.header_line("range").unwrap_or_default();
    if header.is_empty() || header == "0" {
        return Ok(None);
    }
    let Some(range) = ctx.request.range() else {
        return Err(Error::new(ErrorType::StorageInvalidRange));
    };
    let start = range.start;
    let mut end = range.end.unwrap_or(start + open_end - 1);
    if range.end.is_none_or(|end| end - start > READ_BUFFER) {
        end = start + open_end - 1;
    }
    end = end.min(size - 1);
    if range.unit != "bytes" || start > end {
        return Err(Error::new(ErrorType::StorageInvalidRange));
    }
    Ok(Some((start, end)))
}

fn slice(bytes: &[u8], start: i64, end: i64) -> Vec<u8> {
    let start = usize::try_from(start).unwrap_or(0);
    let end = usize::try_from(end).unwrap_or(0).saturating_add(1);
    if start >= bytes.len() {
        return Vec::new();
    }
    bytes[start..end.min(bytes.len())].to_vec()
}

/// The `length` bytes of a stored file, in chunks of at most
/// [`utopia_http::CHUNK_SIZE`]. Like PHP, a read that runs past or short of
/// the captured size cuts the response short instead of ending it.
fn chunks(
    body: utopia_storage::Body,
    length: u64,
) -> impl futures_util::Stream<Item = std::result::Result<bytes::Bytes, Aborted>> + Send + 'static {
    futures_util::stream::unfold(Some((body, length)), |state| async move {
        let (mut body, remaining) = state?;
        match body.read(utopia_http::CHUNK_SIZE).await {
            Ok(chunk) if chunk.is_empty() && remaining == 0 => None,
            Ok(chunk) if !chunk.is_empty() && chunk.len() as u64 <= remaining => {
                let remaining = remaining - chunk.len() as u64;
                Some((Ok(chunk), Some((body, remaining))))
            }
            Ok(_) | Err(_) => {
                tracing::warn!(remaining, "file read did not match its captured size");
                Some((Err(Aborted), None))
            }
        }
    })
}

/// `OpenSSL::decrypt($data, $cipher, _APP_OPENSSL_KEY_V{openSSLVersion}, 0, $iv, $tag)`:
/// base64 ciphertext, as PHP and this service write it.
fn decrypt(key_v1: &str, file: &Document, data: &[u8]) -> Result<Vec<u8>> {
    let iv = hex::decode(file.string_or_empty("openSSLIV")).unwrap_or_default();
    let tag = hex::decode(file.string_or_empty("openSSLTag")).unwrap_or_default();
    let version = file.string_or_empty("openSSLVersion");
    let key = match version.as_str() {
        "" | "1" => key_v1.to_owned(),
        version => appwrite_core::crypto::env_key(version).unwrap_or_default(),
    };
    appwrite_core::crypto::open_encoded(&key, data, &iv, &tag)
        // Raw ciphertext, as early Rust builds of this service wrote it.
        .or_else(|| appwrite_core::crypto::open(&key, data, &iv, &tag))
        .ok_or_else(|| Error::with_message(ErrorType::GeneralServerError, "Failed to decrypt file"))
}

fn decompress(algorithm: &str, data: Vec<u8>) -> Result<Vec<u8>> {
    match algorithm {
        "zstd" => Zstd::new()
            .decompress(&data)
            .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string())),
        "gzip" => Gzip::new()
            .decompress(&data)
            .map_err(|err| Error::with_message(ErrorType::GeneralServerError, err.to_string())),
        _ => Ok(data),
    }
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
    memory: i64,
) -> Result<Vec<u8>> {
    set_resource_limit(Resource::Memory, memory);
    if let Some((w, h)) = dimensions(&source) {
        let max = utopia_image::MAX_DIMENSION;
        let area = Resource::Area.limit();
        if w > max || h > max || (area > 0 && w.saturating_mul(h) > area) {
            return Err(Error::with_message(
                ErrorType::StorageImageResolutionExceeded,
                format!("Image resolution {w}x{h} exceeds the maximum allowed {max}x{max} or {area} total pixels"),
            ));
        }
    }
    let mut image = match Image::new(&source) {
        Ok(image) => image,
        Err(err) => {
            let message = err.to_string();
            if message.contains("exceeds limit") || message.contains("cache resources exhausted") {
                return Err(Error::with_message(ErrorType::StorageImageResolutionExceeded, message));
            }
            return Err(Error::with_message(ErrorType::StorageFileTypeUnsupported, message));
        }
    };
    let gravity =
        if gravity == "auto" { Gravity::Center } else { Gravity::from_name(&gravity).unwrap_or(Gravity::Center) };
    if width > 0 || height > 0 || gravity != Gravity::Center || focal.is_some() {
        image.crop(width as u32, height as u32, gravity, focal).map_err(image_error)?;
    }
    if (opacity - 1.0).abs() > f64::EPSILON {
        image.set_opacity(opacity);
    }
    if !background.is_empty() {
        image.set_background(&format!("#{background}")).map_err(image_error)?;
    }
    if border_width > 0 {
        image.set_border(border_width as u32, &format!("#{border_color}")).map_err(image_error)?;
    }
    if border_radius > 0 {
        image.set_border_radius(border_radius as u32).map_err(image_error)?;
    }
    if rotation != 0 {
        image.set_rotation((rotation + 360).rem_euclid(360)).map_err(image_error)?;
    }
    let format = OutputFormat::from_str(&output)
        .map_err(|err| Error::with_message(ErrorType::StorageFileTypeUnsupported, err.to_string()))?;
    image.output(format, quality).map_err(image_error)
}

fn image_error(err: utopia_image::Error) -> Error {
    let message = err.to_string();
    if message.contains("exceeds limit") || message.contains("cache resources exhausted") {
        Error::with_message(ErrorType::StorageImageResolutionExceeded, message)
    } else {
        Error::with_message(ErrorType::StorageFileTypeUnsupported, message)
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

fn claim(claims: &serde_json::Map<String, Json>, key: &str) -> String {
    match claims.get(key) {
        Some(Json::String(value)) => value.clone(),
        Some(Json::Number(value)) => value.to_string(),
        Some(Json::Bool(value)) => value.to_string(),
        _ => String::new(),
    }
}

fn claim_truthy(value: Option<&Json>) -> bool {
    match value {
        Some(Json::Bool(value)) => *value,
        Some(Json::Number(value)) => value.as_i64() != Some(0) && value.as_f64() != Some(0.0),
        Some(Json::String(value)) => !value.is_empty() && value != "0",
        _ => false,
    }
}
