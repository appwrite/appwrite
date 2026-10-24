//! MIME type detection from a file's first bytes.
//!
//! PHP: `mime_content_type()`, which asks libmagic. This module knows the
//! signatures of the formats files in storage buckets usually have (images,
//! audio, video, archives, documents) and tells text formats apart the way
//! libmagic does; anything else is `application/octet-stream`. See the
//! deviation recorded in `tests/compat/storage/spec.json`.

/// How many leading bytes [`detect`] looks at.
pub const SNIFF_LENGTH: usize = 8192;

/// The MIME type of content starting with `head` (at least its first
/// [`SNIFF_LENGTH`] bytes, or all of it when shorter).
///
/// ```
/// assert_eq!(utopia_storage::mime::detect(b"\x89PNG\r\n\x1a\n...."), "image/png");
/// assert_eq!(utopia_storage::mime::detect(b"Hello World\n"), "text/plain");
/// assert_eq!(utopia_storage::mime::detect(b""), "application/x-empty");
/// ```
///
/// ```php
/// file_put_contents('/tmp/hello.txt', "Hello World\n");
/// echo mime_content_type('/tmp/hello.txt'), "\n"; // text/plain
/// ```
pub fn detect(head: &[u8]) -> &'static str {
    if head.is_empty() {
        return "application/x-empty";
    }
    if let Some(m) = binary(head) {
        return m;
    }
    if is_text(head) {
        return text(head);
    }
    "application/octet-stream"
}

fn binary(h: &[u8]) -> Option<&'static str> {
    let starts = |p: &[u8]| h.starts_with(p);
    let at = |o: usize, p: &[u8]| h.len() >= o + p.len() && &h[o..o + p.len()] == p;
    Some(match () {
        _ if starts(b"\xFF\xD8\xFF") => "image/jpeg",
        _ if starts(b"\x89PNG\r\n\x1a\n") => "image/png",
        _ if starts(b"GIF87a") || starts(b"GIF89a") => "image/gif",
        _ if starts(b"RIFF") && at(8, b"WEBP") => "image/webp",
        _ if starts(b"RIFF") && at(8, b"WAVE") => "audio/x-wav",
        _ if starts(b"RIFF") && at(8, b"AVI ") => "video/x-msvideo",
        _ if starts(b"BM") && h.len() >= 26 && at(14, b"\x28\x00\x00\x00") => "image/bmp",
        _ if starts(b"II*\x00") || starts(b"MM\x00*") => "image/tiff",
        _ if starts(b"\x00\x00\x01\x00") && h.len() >= 6 && h[4] != 0 => "image/vnd.microsoft.icon",
        _ if starts(b"8BPS") => "image/vnd.adobe.photoshop",
        _ if at(4, b"ftyp") => return Some(ftyp(h)),
        _ if starts(b"\x1aE\xdf\xa3") => {
            if h.windows(4).any(|w| w == b"webm") {
                "video/webm"
            } else {
                "video/x-matroska"
            }
        }
        _ if starts(b"OggS") => "audio/ogg",
        _ if starts(b"fLaC") => "audio/flac",
        _ if starts(b"ID3") => "audio/mpeg",
        _ if h.len() >= 2 && h[0] == 0xFF && (h[1] & 0xE0) == 0xE0 && (h[1] & 0x06) != 0 => "audio/mpeg",
        _ if starts(b"%PDF-") => "application/pdf",
        _ if starts(b"PK\x03\x04") || starts(b"PK\x05\x06") => "application/zip",
        _ if starts(b"\x1f\x8b") => "application/gzip",
        _ if starts(b"BZh") => "application/x-bzip2",
        _ if starts(b"\xFD7zXZ\x00") => "application/x-xz",
        _ if starts(b"\x28\xB5\x2F\xFD") => "application/zstd",
        _ if starts(b"7z\xBC\xAF\x27\x1C") => "application/x-7z-compressed",
        _ if starts(b"Rar!\x1a\x07") => "application/x-rar",
        _ if at(257, b"ustar") => "application/x-tar",
        _ if starts(b"\x7fELF") => "application/x-executable",
        _ if starts(b"wOFF") => "font/woff",
        _ if starts(b"wOF2") => "font/woff2",
        _ if starts(b"\x00\x01\x00\x00\x00") => "font/sfnt",
        _ if starts(b"OTTO") => "font/sfnt",
        _ if starts(b"SQLite format 3\x00") => "application/vnd.sqlite3",
        _ if starts(b"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => "application/CDFV2",
        _ => return None,
    })
}

/// ISO base media files (`ftyp` box): MP4, QuickTime, HEIF, AVIF, 3GP.
fn ftyp(h: &[u8]) -> &'static str {
    let brand = if h.len() >= 12 { &h[8..12] } else { b"" as &[u8] };
    match brand {
        b"avif" | b"avis" => "image/avif",
        b"heic" | b"heix" | b"heim" | b"heis" => "image/heic",
        b"mif1" | b"msf1" => "image/heif",
        b"qt  " => "video/quicktime",
        b"M4A " | b"M4B " => "audio/x-m4a",
        b"3gp4" | b"3gp5" | b"3gp6" | b"3ge6" | b"3gg6" => "video/3gpp",
        b"3g2a" => "video/3gpp2",
        _ => "video/mp4",
    }
}

/// Whether libmagic would call the content text: valid UTF-8 (or ASCII)
/// without control characters other than the usual whitespace and escape.
fn is_text(h: &[u8]) -> bool {
    // A multi-byte character cut at the end of the sniffed head is fine.
    let valid = match std::str::from_utf8(h) {
        Ok(_) => true,
        Err(e) => e.error_len().is_none() && h.len() >= SNIFF_LENGTH,
    };
    valid && !h.iter().any(|&b| b < 0x20 && !matches!(b, b'\t' | b'\n' | b'\r' | 0x0c | 0x08 | 0x1b) || b == 0x7f)
}

fn text(h: &[u8]) -> &'static str {
    let trimmed = trim_start(h);
    let lower: Vec<u8> = trimmed.iter().take(512).map(u8::to_ascii_lowercase).collect();
    if lower.starts_with(b"<?xml") {
        if contains(&lower, b"<svg") || contains(&h.to_ascii_lowercase(), b"<svg") {
            return "image/svg+xml";
        }
        return "text/xml";
    }
    if lower.starts_with(b"<svg") {
        return "image/svg+xml";
    }
    if lower.starts_with(b"<!doctype html") || lower.starts_with(b"<html") || lower.starts_with(b"<head") {
        return "text/html";
    }
    if lower.starts_with(b"<?php") {
        return "text/x-php";
    }
    if h.starts_with(b"#!") {
        let line = h.split(|&b| b == b'\n').next().unwrap_or_default();
        if contains(line, b"sh") {
            return "text/x-shellscript";
        }
        if contains(line, b"python") {
            return "text/x-script.python";
        }
    }
    if (trimmed.starts_with(b"{") || trimmed.starts_with(b"[")) && is_json(trimmed) {
        return "application/json";
    }
    "text/plain"
}

fn trim_start(h: &[u8]) -> &[u8] {
    let start = h.iter().position(|b| !b.is_ascii_whitespace()).unwrap_or(h.len());
    &h[start..]
}

fn contains(h: &[u8], needle: &[u8]) -> bool {
    h.windows(needle.len()).any(|w| w == needle)
}

/// A complete JSON document, as libmagic's JSON test requires.
fn is_json(h: &[u8]) -> bool {
    php_std::json::validate(h, 512, php_std::json::Flags::default()).is_ok()
}
