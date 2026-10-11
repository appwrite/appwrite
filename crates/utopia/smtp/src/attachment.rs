//! Files carried along with a message.

use std::io::Read;
use std::path::{Path, PathBuf};

use crate::Error;
use crate::address::spans_lines;

const FALLBACK_TYPE: &str = "application/octet-stream";

/// A file to carry along, either held in memory or read from disk when the
/// message is written, so a large file is never held twice.
///
/// PHP: `Utopia\SMTP\Attachment`.
///
/// ```
/// use utopia_smtp::Attachment;
///
/// let logo = Attachment::from_bytes(b"\x89PNG...".to_vec(), "logo.png", Some("image/png"))?.inline("logo")?;
/// assert_eq!(logo.cid(), Some("logo"));
/// assert_eq!(logo.mime_type(), "image/png");
/// # Ok::<(), utopia_smtp::Error>(())
/// ```
///
/// ```php
/// use Utopia\SMTP\Attachment;
///
/// $logo = Attachment::fromString("\x89PNG...", 'logo.png', 'image/png')->inline('logo');
/// echo $logo->cid, "\n"; // logo
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Attachment {
    name: String,
    mime_type: String,
    source: Source,
    cid: Option<String>,
}

/// Where the bytes of an attachment come from.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Source {
    /// Held in memory.
    Bytes(Vec<u8>),
    /// Read from this file when the message is written.
    Path(PathBuf),
}

impl Attachment {
    fn new(name: String, mime_type: String, source: Source, cid: Option<String>) -> Result<Attachment, Error> {
        if spans_lines(&name) || spans_lines(&mime_type) || cid.as_deref().is_some_and(spans_lines) {
            return Err(Error::InvalidArgument("An attachment name, type or identifier must not span lines".into()));
        }
        Ok(Attachment { name, mime_type, source, cid })
    }

    /// A file read when the message is written. `name` defaults to the file
    /// name and `mime_type` to the type detected from the content (see
    /// [`detect`]). A file that cannot be read now is an
    /// [`Error::InvalidArgument`].
    ///
    /// PHP: `Attachment::fromPath()`.
    pub fn from_path(
        path: impl Into<PathBuf>,
        name: Option<&str>,
        mime_type: Option<&str>,
    ) -> Result<Attachment, Error> {
        let path = path.into();
        if std::fs::File::open(&path).is_err() {
            return Err(Error::InvalidArgument(format!("Cannot read attachment: {}", path.display())));
        }
        let name = match name {
            Some(name) => name.to_owned(),
            None => {
                String::from_utf8_lossy(php_std::path::basename(path.as_os_str().as_encoded_bytes(), b"")).into_owned()
            }
        };
        let mime_type = match mime_type {
            Some(t) => t.to_owned(),
            None => detect(&path).to_owned(),
        };
        Attachment::new(name, mime_type, Source::Path(path), None)
    }

    /// Bytes held in memory; `mime_type` defaults to
    /// `application/octet-stream`.
    ///
    /// PHP: `Attachment::fromString()`.
    pub fn from_bytes(content: impl Into<Vec<u8>>, name: &str, mime_type: Option<&str>) -> Result<Attachment, Error> {
        Attachment::new(
            name.to_owned(),
            mime_type.unwrap_or(FALLBACK_TYPE).to_owned(),
            Source::Bytes(content.into()),
            None,
        )
    }

    /// The same file, referenced from markup as `cid:<cid>`, which puts it in
    /// a multipart/related tree rather than alongside the message.
    ///
    /// PHP: `inline()`.
    pub fn inline(self, cid: &str) -> Result<Attachment, Error> {
        Attachment::new(self.name, self.mime_type, self.source, Some(cid.to_owned()))
    }

    /// The file name the recipient sees.
    ///
    /// PHP: `$attachment->name`.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The media type.
    ///
    /// PHP: `$attachment->type`.
    pub fn mime_type(&self) -> &str {
        &self.mime_type
    }

    /// Where the bytes come from.
    ///
    /// PHP: `$attachment->content` and `$attachment->path`.
    pub fn source(&self) -> &Source {
        &self.source
    }

    /// The content identifier of an inline attachment.
    ///
    /// PHP: `$attachment->cid`.
    pub fn cid(&self) -> Option<&str> {
        self.cid.as_deref()
    }
}

/// The media type of a file from its first bytes, for the files a message
/// usually carries: `application/x-empty` for an empty file, PNG, GIF, JPEG,
/// PDF, ZIP and gzip by their signatures, `text/plain` for UTF-8 text of at
/// least two bytes, and `application/octet-stream` for anything else.
///
/// PHP: `mime_content_type()` (libmagic), which knows many more formats;
/// these answers are the same as its answers for these files.
pub fn detect(path: &Path) -> &'static str {
    let mut head = Vec::with_capacity(4096);
    let read = std::fs::File::open(path).and_then(|f| f.take(65536).read_to_end(&mut head));
    if read.is_err() {
        return FALLBACK_TYPE;
    }
    sniff(&head)
}

fn sniff(head: &[u8]) -> &'static str {
    const SIGNATURES: &[(&[u8], &str)] = &[
        (b"\x89PNG\r\n\x1a\n", "image/png"),
        (b"GIF87a", "image/gif"),
        (b"GIF89a", "image/gif"),
        (b"\xff\xd8\xff", "image/jpeg"),
        (b"%PDF-", "application/pdf"),
        (b"PK\x03\x04", "application/zip"),
        (b"\x1f\x8b", "application/gzip"),
    ];
    match head.len() {
        0 => return "application/x-empty",
        1 => return FALLBACK_TYPE,
        _ => {}
    }
    for (signature, mime) in SIGNATURES {
        if head.starts_with(signature) {
            return mime;
        }
    }
    let text = std::str::from_utf8(head).is_ok()
        && head.iter().all(|&b| b >= 0x20 || matches!(b, b'\t' | b'\n' | b'\r' | 0x07 | 0x08 | 0x0b | 0x0c | 0x1b));
    if text && !head.contains(&0x7f) { "text/plain" } else { FALLBACK_TYPE }
}
