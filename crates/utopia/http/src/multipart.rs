//! `multipart/form-data` bodies, parsed the way Swoole fills
//! `$request->post` and `$request->files`.

use std::path::PathBuf;
use std::sync::atomic::{AtomicU64, Ordering};

use php_std::zval::{Array, Key, Zval};

use crate::params::{Params, array_to_params};

/// `UPLOAD_ERR_NO_FILE`.
const NO_FILE: i64 = 4;

/// The fields and files of a multipart body with the `Content-Type` header
/// `content_type`. Uploaded files are written to the temporary directory;
/// their paths are the files' `tmp_name`.
pub(crate) fn parse(content_type: &str, body: &[u8]) -> (Params, Params) {
    let Some(boundary) = boundary(content_type) else {
        return (Params::new(), Params::new());
    };
    let mut post = Array::new();
    let mut files = Array::new();
    let mut delimiter = Vec::with_capacity(boundary.len() + 4);
    delimiter.extend_from_slice(b"--");
    delimiter.extend_from_slice(boundary);

    let Some(first) = find(body, &delimiter) else {
        return (Params::new(), Params::new());
    };
    let mut rest = &body[first + delimiter.len()..];
    let mut separator = Vec::with_capacity(delimiter.len() + 2);
    separator.extend_from_slice(b"\r\n");
    separator.extend_from_slice(&delimiter);
    loop {
        if rest.starts_with(b"--") {
            break;
        }
        let Some(after_line) = rest.strip_prefix(b"\r\n") else { break };
        let Some(end) = find(after_line, &separator) else { break };
        let part = &after_line[..end];
        rest = &after_line[end + separator.len()..];
        let (head, data) = match find(part, b"\r\n\r\n") {
            Some(n) => (&part[..n], &part[n + 4..]),
            None if part.starts_with(b"\r\n") => (&b""[..], &part[2..]),
            None => continue,
        };
        let mut name: Option<Vec<u8>> = None;
        let mut filename: Option<Vec<u8>> = None;
        let mut kind: Vec<u8> = Vec::new();
        for line in head.split(|&b| b == b'\n') {
            let line = line.strip_suffix(b"\r").unwrap_or(line);
            let Some(colon) = line.iter().position(|&b| b == b':') else { continue };
            let header = &line[..colon];
            let value = trim(&line[colon + 1..]);
            if header.eq_ignore_ascii_case(b"content-disposition") {
                for param in value.split(|&b| b == b';') {
                    let param = trim(param);
                    let Some(eq) = param.iter().position(|&b| b == b'=') else { continue };
                    let key = trim(&param[..eq]);
                    let v = unquote(trim(&param[eq + 1..]));
                    if key.eq_ignore_ascii_case(b"name") {
                        name = Some(v.to_vec());
                    } else if key.eq_ignore_ascii_case(b"filename") {
                        filename = Some(v.to_vec());
                    }
                }
            } else if header.eq_ignore_ascii_case(b"content-type") {
                kind = value.to_vec();
            }
        }
        let Some(name) = name else { continue };
        match filename {
            None => php_std::encoding::register_variable(&mut post, &name, Zval::String(data.to_vec()), false),
            Some(filename) => {
                let (tmp_name, size, error) = if filename.is_empty() {
                    (Vec::new(), 0, NO_FILE)
                } else {
                    match store(data) {
                        Some(path) => (path.into_os_string().into_encoded_bytes(), data.len() as i64, 0),
                        None => (Vec::new(), 0, NO_FILE),
                    }
                };
                register_file(&mut files, &name, filename, kind, tmp_name, size, error);
            }
        }
    }
    (array_to_params(&post), array_to_params(&files))
}

fn register_file(
    files: &mut Array,
    name: &[u8],
    filename: Vec<u8>,
    kind: Vec<u8>,
    tmp: Vec<u8>,
    size: i64,
    error: i64,
) {
    match name.iter().position(|&b| b == b'[') {
        Some(pos) if pos > 0 => {
            let (base, path) = name.split_at(pos);
            let meta = |attr: &str| [base, b"[", attr.as_bytes(), b"]", path].concat();
            php_std::encoding::register_variable(files, &meta("name"), Zval::String(filename), false);
            php_std::encoding::register_variable(files, &meta("type"), Zval::String(kind), false);
            php_std::encoding::register_variable(files, &meta("tmp_name"), Zval::String(tmp), false);
            php_std::encoding::register_variable(files, &meta("error"), Zval::Int(error), false);
            php_std::encoding::register_variable(files, &meta("size"), Zval::Int(size), false);
        }
        _ => {
            let mut file = Array::new();
            file.insert(Key::Str(b"type".to_vec()), Zval::String(kind));
            file.insert(Key::Str(b"tmp_name".to_vec()), Zval::String(tmp));
            file.insert(Key::Str(b"size".to_vec()), Zval::Int(size));
            file.insert(Key::Str(b"name".to_vec()), Zval::String(filename));
            file.insert(Key::Str(b"error".to_vec()), Zval::Int(error));
            php_std::encoding::register_variable(files, name, Zval::Array(file), false);
        }
    }
}

/// Swoole's boundary: what follows `multipart/form-data`, `;` and spaces and
/// the 9 bytes of `boundary=`, up to a `;`, without surrounding quotes.
fn boundary(content_type: &str) -> Option<&[u8]> {
    let ct = content_type.as_bytes();
    let mut offset = "multipart/form-data".len();
    while offset < ct.len() && (ct[offset] == b' ' || ct[offset] == b';') {
        offset += 1;
    }
    offset += "boundary=".len();
    let mut boundary = ct.get(offset..)?;
    if let Some(semi) = boundary.iter().position(|&b| b == b';') {
        boundary = &boundary[..semi];
    }
    if boundary.is_empty() {
        return None;
    }
    if boundary.len() >= 2 && boundary[0] == b'"' && boundary[boundary.len() - 1] == b'"' {
        boundary = &boundary[1..boundary.len() - 1];
    }
    Some(boundary)
}

/// Writes an upload to a temporary file (`/tmp/swoole.upfile.*` in PHP).
fn store(data: &[u8]) -> Option<PathBuf> {
    static NEXT: AtomicU64 = AtomicU64::new(0);
    let n = NEXT.fetch_add(1, Ordering::Relaxed);
    let path = std::env::temp_dir().join(format!("utopia.upfile.{}.{n}", std::process::id()));
    std::fs::write(&path, data).ok()?;
    Some(path)
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || haystack.len() < needle.len() {
        return None;
    }
    haystack.windows(needle.len()).position(|w| w == needle)
}

fn trim(s: &[u8]) -> &[u8] {
    let start = s.iter().position(|b| !b.is_ascii_whitespace()).unwrap_or(s.len());
    let end = s.iter().rposition(|b| !b.is_ascii_whitespace()).map(|e| e + 1).unwrap_or(start);
    &s[start..end.max(start)]
}

fn unquote(s: &[u8]) -> &[u8] {
    if s.len() >= 2 && s[0] == b'"' && s[s.len() - 1] == b'"' { &s[1..s.len() - 1] } else { s }
}
