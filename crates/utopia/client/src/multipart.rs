//! `multipart/form-data` request bodies: [`Part`]s (form fields, in-memory
//! files, files on disk) encoded between a boundary by [`Form`].
//!
//! The bytes are those PHP's `utopia-php/psr7` multipart body produces: each
//! part is `--<boundary>`, `Content-Disposition: form-data; name="<name>"`
//! (with `; filename="<filename>"` for a file, both quoted with `\` and `"`
//! backslash-escaped), `Content-Type` when given, the part's extra headers,
//! a blank line, the content and a CRLF; the body ends with
//! `--<boundary>--` and a CRLF.
//!
//! ```
//! use utopia_client::multipart::{Form, Part};
//!
//! let form = Form::with_boundary("abc123", vec![Part::field("name", b"Ada", vec![])]);
//! assert_eq!(form.content_type(), "multipart/form-data; boundary=abc123");
//! assert_eq!(
//!     form.encode()?,
//!     b"--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n"
//! );
//! # Ok::<(), utopia_client::multipart::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Psr7\Request\Multipart\Body;
//! use Utopia\Psr7\Request\Multipart\Part;
//!
//! $body = new Body('abc123', [Part::field('name', 'Ada')]);
//! echo json_encode((string) $body), "\n"; // "--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123--\r\n"
//! ```
//!
//! PHP: `Utopia\Psr7\Request\Multipart\Part` and `Body`, and the boundary
//! `Utopia\Psr7\Request\Factory::multipart()` generates.

use std::path::{Path, PathBuf};

/// A part whose file cannot be read.
///
/// PHP: `RuntimeException`.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
#[error("{0}")]
pub struct Error(String);

impl Error {
    /// The message.
    pub fn message(&self) -> &str {
        &self.0
    }

    /// The PHP exception class this error corresponds to.
    pub fn php_class(&self) -> &'static str {
        "RuntimeException"
    }
}

/// One part of a multipart body: a form field, an in-memory file, or a file
/// on disk read when the body is encoded.
///
/// PHP: `Utopia\Psr7\Request\Multipart\Part`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Part {
    name: String,
    content: Vec<u8>,
    path: Option<PathBuf>,
    filename: Option<String>,
    content_type: Option<String>,
    headers: Vec<(String, String)>,
}

impl Part {
    /// A form field.
    ///
    /// PHP: `Part::field()`.
    pub fn field(name: &str, value: &[u8], headers: Vec<(String, String)>) -> Part {
        Part { name: name.to_owned(), content: value.to_vec(), path: None, filename: None, content_type: None, headers }
    }

    /// A file on disk, read when the body is encoded; the filename defaults
    /// to the path's last component.
    ///
    /// Fails with `Unable to read multipart file.` when `path` is not a file.
    ///
    /// PHP: `Part::file()`.
    pub fn file(
        name: &str,
        path: impl AsRef<Path>,
        filename: Option<&str>,
        content_type: Option<&str>,
        headers: Vec<(String, String)>,
    ) -> Result<Part, Error> {
        let path = path.as_ref();
        if !path.is_file() {
            return Err(Error("Unable to read multipart file.".into()));
        }
        let filename = match filename {
            Some(f) => f.to_owned(),
            None => {
                let raw = path.to_string_lossy();
                String::from_utf8_lossy(php_std::path::basename(raw.as_bytes(), b"")).into_owned()
            }
        };
        Ok(Part {
            name: name.to_owned(),
            content: Vec::new(),
            path: Some(path.to_path_buf()),
            filename: Some(filename),
            content_type: content_type.map(str::to_owned),
            headers,
        })
    }

    /// An in-memory file.
    ///
    /// PHP: `Part::body()`.
    pub fn body(
        name: &str,
        body: &[u8],
        filename: Option<&str>,
        content_type: Option<&str>,
        headers: Vec<(String, String)>,
    ) -> Part {
        Part {
            name: name.to_owned(),
            content: body.to_vec(),
            path: None,
            filename: filename.map(str::to_owned),
            content_type: content_type.map(str::to_owned),
            headers,
        }
    }

    /// The field name.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The in-memory content; empty for a file part, whose bytes are at [`Part::path`].
    pub fn content(&self) -> &[u8] {
        &self.content
    }

    /// The file a file part reads.
    pub fn path(&self) -> Option<&Path> {
        self.path.as_deref()
    }

    /// The content's size: the file's for a file part (0 when it is gone).
    pub fn size(&self) -> u64 {
        match &self.path {
            None => self.content.len() as u64,
            Some(path) => std::fs::metadata(path).map(|m| m.len()).unwrap_or(0),
        }
    }

    /// The filename sent in `Content-Disposition`.
    pub fn filename(&self) -> Option<&str> {
        self.filename.as_deref()
    }

    /// The part's `Content-Type`.
    pub fn content_type(&self) -> Option<&str> {
        self.content_type.as_deref()
    }

    /// Extra headers of the part.
    pub fn headers(&self) -> &[(String, String)] {
        &self.headers
    }

    /// The lines before the content: `Content-Disposition`, `Content-Type`,
    /// then the extra headers (one named exactly like an earlier one
    /// replaces it in place), each ending in CRLF.
    fn head(&self) -> Vec<u8> {
        let mut disposition = format!("form-data; name=\"{}\"", escape(&self.name));
        if let Some(filename) = &self.filename {
            disposition.push_str(&format!("; filename=\"{}\"", escape(filename)));
        }
        let mut lines: Vec<(&str, &str)> = vec![("Content-Disposition", &disposition)];
        if let Some(content_type) = &self.content_type {
            lines.push(("Content-Type", content_type));
        }
        for (name, value) in &self.headers {
            match lines.iter_mut().find(|(n, _)| *n == name.as_str()) {
                Some(line) => line.1 = value,
                None => lines.push((name, value)),
            }
        }
        let mut out = Vec::new();
        for (name, value) in lines {
            out.extend_from_slice(name.as_bytes());
            out.extend_from_slice(b": ");
            out.extend_from_slice(value.as_bytes());
            out.extend_from_slice(b"\r\n");
        }
        out
    }
}

/// `addcslashes($value, '\\"')`.
fn escape(value: &str) -> String {
    String::from_utf8_lossy(&php_std::string::addcslashes(value.as_bytes(), b"\\\"")).into_owned()
}

/// Parts between a boundary: the body of a `multipart/form-data` request.
///
/// PHP: `Utopia\Psr7\Request\Multipart\Body`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Form {
    boundary: String,
    parts: Vec<Part>,
}

impl Form {
    /// `parts` between a random `utopia-<32 hex>` boundary.
    ///
    /// PHP: `Request\Factory::multipart()`.
    pub fn new(parts: Vec<Part>) -> Form {
        Form::with_boundary(&boundary(), parts)
    }

    /// `parts` between `boundary`.
    ///
    /// PHP: `new Body($boundary, $parts)`.
    pub fn with_boundary(boundary: &str, parts: Vec<Part>) -> Form {
        Form { boundary: boundary.to_owned(), parts }
    }

    /// The boundary.
    pub fn boundary(&self) -> &str {
        &self.boundary
    }

    /// The parts.
    pub fn parts(&self) -> &[Part] {
        &self.parts
    }

    /// `multipart/form-data; boundary=<boundary>`.
    pub fn content_type(&self) -> String {
        format!("multipart/form-data; boundary={}", self.boundary)
    }

    /// The body's bytes, file parts read from disk.
    ///
    /// Fails with `Unable to open multipart file.` when a file part cannot
    /// be read.
    pub fn encode(&self) -> Result<Vec<u8>, Error> {
        let mut out = Vec::new();
        for part in &self.parts {
            out.extend_from_slice(b"--");
            out.extend_from_slice(self.boundary.as_bytes());
            out.extend_from_slice(b"\r\n");
            out.extend_from_slice(&part.head());
            out.extend_from_slice(b"\r\n");
            match &part.path {
                None => out.extend_from_slice(&part.content),
                Some(path) => {
                    let bytes = std::fs::read(path).map_err(|_| Error("Unable to open multipart file.".into()))?;
                    out.extend_from_slice(&bytes);
                }
            }
            out.extend_from_slice(b"\r\n");
        }
        out.extend_from_slice(b"--");
        out.extend_from_slice(self.boundary.as_bytes());
        out.extend_from_slice(b"--\r\n");
        Ok(out)
    }
}

/// `utopia-` and 32 random hex digits.
fn boundary() -> String {
    let mut bytes = [0u8; 16];
    let _ = getrandom::fill(&mut bytes);
    let mut out = String::with_capacity(39);
    out.push_str("utopia-");
    for b in bytes {
        out.push_str(&format!("{b:02x}"));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn encodes_fields_and_file_parts() {
        let dir = std::env::temp_dir().join(format!("utopia-part-{}", boundary()));
        std::fs::write(&dir, b"file-bytes").unwrap();
        let form = Form::with_boundary(
            "abc123",
            vec![
                Part::field("name", b"Ada", vec![]),
                Part::file("doc", &dir, Some("doc.txt"), Some("text/plain"), vec![]).unwrap(),
            ],
        );
        let expected = b"--abc123\r\n\
            Content-Disposition: form-data; name=\"name\"\r\n\r\n\
            Ada\r\n\
            --abc123\r\n\
            Content-Disposition: form-data; name=\"doc\"; filename=\"doc.txt\"\r\n\
            Content-Type: text/plain\r\n\r\n\
            file-bytes\r\n\
            --abc123--\r\n";
        assert_eq!(form.encode().unwrap(), expected);
        assert_eq!(form.parts()[1].size(), 10);
        std::fs::remove_file(&dir).unwrap();
        assert_eq!(form.encode().unwrap_err().message(), "Unable to open multipart file.");
    }

    #[test]
    fn rejects_missing_files() {
        let error = Part::file("doc", "/nonexistent/utopia-missing-file", None, None, vec![]).unwrap_err();
        assert_eq!(error.message(), "Unable to read multipart file.");
        assert_eq!(error.php_class(), "RuntimeException");
    }

    #[test]
    fn file_name_defaults_to_the_basename() {
        let path = std::env::temp_dir().join(format!("utopia-basename-{}.txt", boundary()));
        std::fs::write(&path, b"x").unwrap();
        let part = Part::file("doc", &path, None, None, vec![]).unwrap();
        assert_eq!(part.filename(), path.file_name().and_then(|n| n.to_str()));
        std::fs::remove_file(&path).unwrap();
    }

    #[test]
    fn encodes_in_memory_files_and_repeated_names() {
        let form = Form::with_boundary(
            "b",
            vec![
                Part::field("tag", b"math", vec![]),
                Part::field("tag", b"history", vec![]),
                Part::body("avatar", b"image-bytes", Some("ada.png"), Some("image/png"), vec![]),
            ],
        );
        assert_eq!(
            form.encode().unwrap(),
            b"--b\r\nContent-Disposition: form-data; name=\"tag\"\r\n\r\nmath\r\n\
              --b\r\nContent-Disposition: form-data; name=\"tag\"\r\n\r\nhistory\r\n\
              --b\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\"\r\nContent-Type: image/png\r\n\r\nimage-bytes\r\n\
              --b--\r\n"
        );
    }

    #[test]
    fn empty_form_is_the_closing_boundary() {
        assert_eq!(Form::with_boundary("b", vec![]).encode().unwrap(), b"--b--\r\n");
    }

    #[test]
    fn part_headers_follow_and_replace_in_place() {
        let part = Part::body(
            "payload",
            b"contents",
            None,
            Some("text/plain"),
            vec![("X-Part-ID".into(), "123".into()), ("Content-Type".into(), "application/json".into())],
        );
        assert_eq!(
            Form::with_boundary("b", vec![part]).encode().unwrap(),
            b"--b\r\nContent-Disposition: form-data; name=\"payload\"\r\nContent-Type: application/json\r\nX-Part-ID: 123\r\n\r\ncontents\r\n--b--\r\n"
        );
    }

    #[test]
    fn escapes_quotes_and_backslashes() {
        let part = Part::body("a\"b\\c", b"", Some("x\"y.txt"), None, vec![]);
        assert_eq!(
            Form::with_boundary("b", vec![part]).encode().unwrap(),
            b"--b\r\nContent-Disposition: form-data; name=\"a\\\"b\\\\c\"; filename=\"x\\\"y.txt\"\r\n\r\n\r\n--b--\r\n"
        );
    }

    #[test]
    fn random_boundaries() {
        let form = Form::new(vec![]);
        assert!(form.boundary().starts_with("utopia-"));
        assert_eq!(form.boundary().len(), 39);
        assert!(form.boundary()[7..].bytes().all(|b| b.is_ascii_hexdigit()));
        assert_ne!(form.boundary(), Form::new(vec![]).boundary());
        assert_eq!(form.content_type(), format!("multipart/form-data; boundary={}", form.boundary()));
    }
}
