//! MIME type detection of message content.
//!
//! PHP: `(new \finfo(FILEINFO_MIME_TYPE))->buffer($content)`, which asks
//! libmagic and its database of several thousand rules. This module knows
//! the signatures of the formats attachments usually have (the images the
//! providers accept first, then common documents, archives and media) and
//! classifies text the way libmagic's `encoding.c` does: content without a
//! byte that never appears in text is text (ASCII, UTF-8, ISO-8859 or
//! extended ASCII), told apart further as JSON, XML, SVG, HTML, PHP or a
//! script; anything else is `application/octet-stream`. See the deviation
//! recorded in `tests/compat/agents/spec.json`.

/// The MIME type libmagic gives `content` (not empty).
pub(crate) fn detect(content: &[u8]) -> &'static str {
    // libmagic: "very short file (no magic)".
    if content.len() == 1 {
        return "application/octet-stream";
    }
    if let Some(m) = binary(content) {
        return m;
    }
    if is_text(content) {
        return text(content);
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
        _ if starts(b"OggS") => "audio/ogg",
        _ if starts(b"fLaC") => "audio/flac",
        _ if starts(b"ID3") => "audio/mpeg",
        _ if starts(b"%PDF-") => "application/pdf",
        _ if starts(b"PK\x03\x04") || starts(b"PK\x05\x06") => "application/zip",
        _ if starts(b"\x1f\x8b") => "application/gzip",
        _ if starts(b"BZh") => "application/x-bzip2",
        _ if starts(b"\xFD7zXZ\x00") => "application/x-xz",
        _ if starts(b"7z\xBC\xAF\x27\x1C") => "application/x-7z-compressed",
        _ if starts(b"\x7fELF") => "application/x-executable",
        _ if starts(b"wOFF") => "font/woff",
        _ if starts(b"wOF2") => "font/woff2",
        _ if starts(b"SQLite format 3\x00") => "application/vnd.sqlite3",
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
        _ => "video/mp4",
    }
}

/// Whether libmagic calls the content text: no byte of its `text_chars`
/// table's "never appears in text" class (NUL to ACK, SO to SUB, FS to US
/// and DEL). Every other byte appears in ASCII, ISO-8859 or extended ASCII
/// text, which all map to a text type.
fn is_text(h: &[u8]) -> bool {
    !h.iter().any(|&b| matches!(b, 0x00..=0x06 | 0x0e..=0x1a | 0x1c..=0x1f | 0x7f))
}

/// libmagic's `diff` rules: a diff header at the start, or a unified diff
/// hunk (`--- `, its line, `+++ `, its line, `@@`) starting in the first
/// 4096 bytes.
fn is_diff(h: &[u8]) -> bool {
    let headers: [&[u8]; 5] = [b"diff ", b"*** ", b"Only in ", b"Common subdirectories: ", b"Index:"];
    if headers.iter().any(|p| h.starts_with(p)) {
        return true;
    }
    let window = &h[..h.len().min(4096 + 3)];
    let Some(at) = window.windows(4).position(|w| w == b"--- ") else { return false };
    let line = |from: usize| h[from..].iter().take(1024).position(|&b| b == b'\n').map(|n| from + n + 1);
    let Some(next) = line(at + 4) else { return false };
    if !h[next..].starts_with(b"+++ ") {
        return false;
    }
    let Some(next) = line(next + 4) else { return false };
    h[next..].starts_with(b"@@")
}

fn text(h: &[u8]) -> &'static str {
    if is_diff(h) {
        return "text/x-diff";
    }
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

#[cfg(test)]
mod tests {
    use super::detect;

    #[test]
    fn images_and_text() {
        assert_eq!(detect(b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR"), "image/png");
        assert_eq!(detect(b"GIF89a\x01\0\x01\0"), "image/gif");
        assert_eq!(detect(b"\xFF\xD8\xFF\xE0\0\x10JFIF"), "image/jpeg");
        assert_eq!(detect(b"RIFF\0\0\0\0WEBPVP8 "), "image/webp");
        assert_eq!(detect(b"hello world"), "text/plain");
        assert_eq!(detect(b"caf\xe9"), "text/plain");
        assert_eq!(detect(b"{\"a\":1}"), "application/json");
        assert_eq!(detect(b"\0\x01\x02"), "application/octet-stream");
    }
}
