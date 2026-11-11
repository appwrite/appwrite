//! Sending a stored file: the steps download, view, preview and push share.

use utopia_compression::{Algorithm, Gzip, Zstd};
use utopia_database::{Database, Document};
use utopia_http::{Aborted, Response, StatusCode};

use appwrite_core::database::documents::Attributes;
use appwrite_core::platform::Context;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, DOWNLOAD_MAX_AGE, READ_BUFFER, token_for_bucket, token_matches_file};
use crate::device::Stored;

pub(super) const MIMES: &[&str] = &[
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

pub(super) fn ids(ctx: &Context) -> Result<(String, String)> {
    Ok((ctx.required_str("bucketId", &uid())?, ctx.required_str("fileId", &uid())?))
}

pub(super) async fn load_file(ctx: &Context, db: &Database, bucket: &Document, file_id: &str) -> Result<Document> {
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
    if incomplete(&file) {
        return Err(Error::new(ErrorType::StorageFileNotFound));
    }
    Ok(file)
}

/// Missing, or still uploading: a file stays incomplete until its final
/// chunk is joined and scanned.
pub(super) fn incomplete(file: &Document) -> bool {
    let total = file.get("chunksTotal").map(utopia_database::value::to_long).unwrap_or(1);
    file.is_empty() || base::long(file, "chunksUploaded") < total
}

/// `Response::setContentDisposition()`: an ASCII fallback name (a UTF-8
/// lead byte becomes `_`; control characters, `"`, `\` and `;` are dropped)
/// and the RFC 5987 UTF-8 name.
///
/// ```text
/// café "report"\;.html → attachment; filename="caf_ report.html"; filename*=UTF-8''caf%C3%A9%20%22report%22%5C%3B.html
/// ```
fn content_disposition(kind: &str, name: &str) -> String {
    let mut fallback = String::new();
    for &byte in name.as_bytes() {
        if byte >= 0xC0 {
            fallback.push('_');
        } else if (32..127).contains(&byte) && !matches!(byte, b'"' | b'\\' | b';') {
            fallback.push(byte as char);
        }
    }
    if fallback.is_empty() {
        fallback.push_str("download");
    }
    let encoded = php_std::encoding::rawurlencode(if name.is_empty() { b"download" } else { name.as_bytes() });
    format!("{kind}; filename=\"{fallback}\"; filename*=UTF-8''{encoded}")
}

pub(super) async fn send(
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
pub(super) async fn serve(
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
    response.set("content-disposition", &content_disposition(disposition, &file.string_or_empty("name")));
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
pub(super) fn decrypt(key_v1: &str, file: &Document, data: &[u8]) -> Result<Vec<u8>> {
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

pub(super) fn decompress(algorithm: &str, data: Vec<u8>) -> Result<Vec<u8>> {
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

#[cfg(test)]
mod tests {
    use super::content_disposition;

    #[test]
    fn content_disposition_as_php() {
        assert_eq!(
            content_disposition("attachment", "caf\u{e9} \"report\"\\;.html"),
            "attachment; filename=\"caf_ report.html\"; filename*=UTF-8''caf%C3%A9%20%22report%22%5C%3B.html"
        );
        assert_eq!(content_disposition("inline", ""), "inline; filename=\"download\"; filename*=UTF-8''download");
    }
}
