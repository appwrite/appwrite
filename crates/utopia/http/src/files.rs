//! Static files loaded into memory and served by [`crate::Http::run`] for an
//! exact URI match.
//!
//! PHP: `Utopia\Http\Files`.

use std::path::Path;

use bytes::Bytes;
use indexmap::IndexMap;

use crate::error::{Error, Result};

/// MIME types by extension that override content sniffing: CSS, JavaScript
/// and SVG.
///
/// PHP: `Files::EXTENSIONS`.
pub const EXTENSIONS: [(&str, &str); 3] = [("css", "text/css"), ("js", "text/javascript"), ("svg", "image/svg+xml")];

/// A loaded file: its contents and MIME type.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct File {
    /// The file's bytes.
    pub contents: Bytes,
    /// The MIME type, from [`EXTENSIONS`] or sniffed from the contents.
    pub mime: String,
}

/// Files loaded from directories, by URI (the path relative to the load root,
/// starting with `/`).
#[derive(Debug, Clone, Default)]
pub struct Files {
    loaded: IndexMap<String, File>,
    count: usize,
    mime_types: IndexMap<String, bool>,
}

impl Files {
    /// An empty set of files.
    pub fn new() -> Self {
        Self::default()
    }

    /// Adds a MIME type to the registered list (it does not affect loading).
    ///
    /// PHP: `addMimeType($mimeType)`.
    pub fn add_mime_type(&mut self, mime: &str) {
        self.mime_types.insert(mime.to_owned(), true);
    }

    /// Removes a MIME type from the registered list.
    ///
    /// PHP: `removeMimeType($mimeType)`.
    pub fn remove_mime_type(&mut self, mime: &str) {
        self.mime_types.shift_remove(mime);
    }

    /// The registered MIME types, each mapped to `true`.
    ///
    /// PHP: `getMimeTypes()`.
    pub fn mime_types(&self) -> &IndexMap<String, bool> {
        &self.mime_types
    }

    /// The number of files loaded.
    ///
    /// PHP: `getCount()`.
    pub fn count(&self) -> usize {
        self.count
    }

    /// Loads every file under `directory`, recursively, keyed by its path
    /// relative to `root` (by default `directory` itself).
    ///
    /// Dot files and `.php`/`.phtml` files are skipped, and a file already
    /// loaded under the same URI is kept. Fails when `directory` cannot be
    /// read.
    ///
    /// PHP: `load($directory, $root)`. Reading stops at an entry named `0`
    /// (PHP's `while ($path = readdir())`).
    pub fn load(&mut self, directory: &str, root: Option<&str>) -> Result<()> {
        let dir = Path::new(directory);
        if std::fs::read_dir(dir).is_err() && std::fs::metadata(dir).is_err() {
            return Err(Error::Generic(format!("Failed to load directory: {directory}")));
        }
        let real =
            std::fs::canonicalize(dir).map_err(|_| Error::Generic(format!("Failed to load directory: {directory}")))?;
        let real = real.to_string_lossy().into_owned();
        // The directory is resolved; resolve the root too, so that keys stay
        // relative when it sits behind a symlink (`/tmp` on macOS).
        let root = match root {
            Some(r) => {
                std::fs::canonicalize(r).map(|p| p.to_string_lossy().into_owned()).unwrap_or_else(|_| r.to_owned())
            }
            None => real.clone(),
        };
        let entries =
            std::fs::read_dir(&real).map_err(|_| Error::Generic(format!("Failed to open directory: {real}")))?;
        for entry in entries {
            let Ok(entry) = entry else { continue };
            let name = entry.file_name().to_string_lossy().into_owned();
            if name == "0" {
                break;
            }
            let extension = extension(&name);
            if name == "." || name == ".." || extension == "php" || extension == "phtml" || name.starts_with('.') {
                continue;
            }
            let path = format!("{real}/{name}");
            if Path::new(&path).is_dir() {
                self.load(&path, Some(&root))?;
                continue;
            }
            let key = path.get(root.len()..).unwrap_or("").to_owned();
            if self.loaded.contains_key(&key) {
                continue;
            }
            let contents = Bytes::from(std::fs::read(&path).unwrap_or_default());
            let mime = EXTENSIONS
                .iter()
                .find(|(e, _)| *e == extension)
                .map(|(_, m)| (*m).to_owned())
                .unwrap_or_else(|| sniff(&contents).to_owned());
            self.loaded.insert(key, File { contents, mime });
            self.count += 1;
        }
        Ok(())
    }

    /// Whether a file is loaded under `uri`.
    ///
    /// PHP: `isFileLoaded($uri)`.
    pub fn is_loaded(&self, uri: &str) -> bool {
        self.loaded.contains_key(uri)
    }

    /// The file loaded under `uri`, or an [`Error::Generic`] when there is
    /// none.
    ///
    /// PHP: `getFileContents($uri)` and `getFileMimeType($uri)`.
    pub fn get(&self, uri: &str) -> Result<&File> {
        self.loaded.get(uri).ok_or_else(|| Error::Generic(format!("File not found or not loaded: {uri}")))
    }

    /// Forgets every loaded file and registered MIME type.
    ///
    /// PHP: `reset()`.
    pub fn reset(&mut self) {
        self.count = 0;
        self.loaded.clear();
        self.mime_types.clear();
    }
}

/// `pathinfo($path, PATHINFO_EXTENSION)`.
fn extension(name: &str) -> &str {
    match name.rfind('.') {
        Some(i) => &name[i + 1..],
        None => "",
    }
}

/// Guesses the MIME type of file contents: signatures of common binary
/// formats (PNG, GIF, JPEG, WebP, PDF, ZIP, gzip), HTML, JSON and text,
/// else `application/octet-stream`.
///
/// PHP: stands in for `mime_content_type()` on the content types static
/// assets have.
pub fn sniff(contents: &[u8]) -> &'static str {
    if contents.is_empty() {
        return "application/x-empty";
    }
    const SIGNATURES: [(&[u8], &str); 7] = [
        (b"\x89PNG\r\n\x1a\n", "image/png"),
        (b"GIF87a", "image/gif"),
        (b"GIF89a", "image/gif"),
        (b"\xFF\xD8\xFF", "image/jpeg"),
        (b"%PDF-", "application/pdf"),
        (b"PK\x03\x04", "application/zip"),
        (b"\x1F\x8B", "application/gzip"),
    ];
    for (signature, mime) in SIGNATURES {
        if contents.starts_with(signature) {
            return mime;
        }
    }
    if contents.len() >= 12 && &contents[..4] == b"RIFF" && &contents[8..12] == b"WEBP" {
        return "image/webp";
    }
    let Ok(text) = std::str::from_utf8(contents) else { return "application/octet-stream" };
    if text.contains('\0') {
        return "application/octet-stream";
    }
    let trimmed = text.trim_start();
    let lower: String = trimmed.chars().take(15).collect::<String>().to_ascii_lowercase();
    if lower.starts_with("<!doctype html") || lower.starts_with("<html") {
        return "text/html";
    }
    if (trimmed.starts_with('{') || trimmed.starts_with('['))
        && php_std::json::validate(contents, php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE).is_ok()
    {
        return "application/json";
    }
    "text/plain"
}
