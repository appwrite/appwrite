//! Files uploaded with a request, and turning a `$_FILES`-shaped array into them.

use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};

use php_std::{Array, ArrayKey, Value};

use crate::Error;
use crate::stream::{StreamRef, TempStream};

/// `UPLOAD_ERR_OK`: the upload succeeded.
pub const UPLOAD_ERR_OK: i64 = 0;
/// `UPLOAD_ERR_NO_FILE`: no file was uploaded.
pub const UPLOAD_ERR_NO_FILE: i64 = 4;

/// A file uploaded with a request.
///
/// Clones share the file's state: once one moves the file, every clone
/// reports it moved, as every PHP reference holds the same object.
///
/// PHP: `Utopia\Psr7\UploadedFile` (`Psr\Http\Message\UploadedFileInterface`).
#[derive(Debug, Clone)]
pub struct UploadedFile {
    file: PathBuf,
    size: i64,
    error: i64,
    client_filename: Option<String>,
    client_media_type: Option<String>,
    state: Arc<Mutex<State>>,
}

#[derive(Debug, Default)]
struct State {
    stream: Option<StreamRef>,
    moved: bool,
}

impl UploadedFile {
    /// An uploaded file at `file` (its temporary path), with its size, the
    /// `UPLOAD_ERR_*` code, and the name and media type the client sent.
    ///
    /// PHP: `new UploadedFile(...)`.
    pub fn new(
        file: impl Into<PathBuf>,
        size: i64,
        error: i64,
        client_filename: Option<String>,
        client_media_type: Option<String>,
    ) -> UploadedFile {
        UploadedFile { file: file.into(), size, error, client_filename, client_media_type, state: Arc::default() }
    }

    /// A stream over the file, opened on first use.
    ///
    /// Fails with [`Error::Runtime`] when the file was moved, the upload
    /// failed, or the file cannot be opened.
    ///
    /// PHP: `getStream()`.
    pub fn stream(&self) -> Result<StreamRef, Error> {
        let mut state = self.state.lock().unwrap_or_else(|e| e.into_inner());
        if state.moved {
            return Err(Error::runtime("Uploaded file has already been moved."));
        }
        if self.error != UPLOAD_ERR_OK {
            return Err(Error::runtime("Uploaded file is not available."));
        }
        if let Some(stream) = &state.stream {
            return Ok(stream.clone());
        }
        let file =
            std::fs::File::open(&self.file).map_err(|_| Error::runtime("Unable to open uploaded file stream."))?;
        let stream = StreamRef::new(TempStream::from_file(file, &self.file));
        state.stream = Some(stream.clone());
        Ok(stream)
    }

    /// Moves the file to `target` (a rename).
    ///
    /// Fails with [`Error::InvalidArgument`] for an empty target, and
    /// [`Error::Runtime`] when the file was moved already, the upload failed
    /// or the rename fails.
    ///
    /// PHP: `moveTo()` (on the CLI, which renames; other SAPIs call
    /// `move_uploaded_file()`).
    pub fn move_to(&self, target: impl AsRef<Path>) -> Result<(), Error> {
        let target = target.as_ref();
        if target.as_os_str().is_empty() {
            return Err(Error::invalid("Target path must not be empty."));
        }
        let mut state = self.state.lock().unwrap_or_else(|e| e.into_inner());
        if state.moved {
            return Err(Error::runtime("Uploaded file has already been moved."));
        }
        if self.error != UPLOAD_ERR_OK {
            return Err(Error::runtime("Uploaded file is not available."));
        }
        std::fs::rename(&self.file, target).map_err(|_| Error::runtime("Unable to move uploaded file."))?;
        state.moved = true;
        Ok(())
    }

    /// The size the upload reported.
    ///
    /// PHP: `getSize()`.
    pub fn size(&self) -> i64 {
        self.size
    }

    /// The `UPLOAD_ERR_*` code.
    ///
    /// PHP: `getError()`.
    pub fn error(&self) -> i64 {
        self.error
    }

    /// The filename the client sent.
    ///
    /// PHP: `getClientFilename()`.
    pub fn client_filename(&self) -> Option<&str> {
        self.client_filename.as_deref()
    }

    /// The media type the client sent.
    ///
    /// PHP: `getClientMediaType()`.
    pub fn client_media_type(&self) -> Option<&str> {
        self.client_media_type.as_deref()
    }

    /// The temporary path of the file.
    pub fn path(&self) -> &Path {
        &self.file
    }
}

/// Uploaded files as a request carries them: a file, or a tree of them by key.
///
/// PHP: the array `getUploadedFiles()` returns.
#[derive(Debug, Clone)]
pub enum UploadedFiles {
    /// One file.
    File(UploadedFile),
    /// Files by key (field names, or indexes of a multiple upload).
    Tree(Vec<(ArrayKey, UploadedFiles)>),
}

/// The uploaded files of a `$_FILES`-shaped array: every entry with
/// `tmp_name`, `error` or `size` becomes an [`UploadedFile`] (a list of them
/// when `tmp_name` is an array, as PHP builds `name[]` fields), other arrays
/// are walked, and anything else is skipped.
///
/// PHP: `UploadedFile::normalizeFiles()`.
pub fn normalize_files(files: &Array) -> Vec<(ArrayKey, UploadedFiles)> {
    let mut normalized = Vec::new();
    for (key, file) in files.iter() {
        let Value::Array(file) = file else {
            continue;
        };
        let value = if is_spec(file) { spec(file) } else { UploadedFiles::Tree(normalize_files(file)) };
        normalized.push((key.to_owned(), value));
    }
    normalized
}

fn is_spec(file: &Array) -> bool {
    ["tmp_name", "error", "size"].iter().any(|k| file.get_str(k).is_some())
}

fn spec(file: &Array) -> UploadedFiles {
    if let Some(Value::Array(tmp_names)) = file.get_str("tmp_name") {
        let nested = |field: &str, key: &ArrayKey, default: Value| -> Value {
            match file.get_str(field) {
                Some(Value::Array(values)) => values.get(key).cloned().unwrap_or(default),
                _ => default,
            }
        };
        let mut normalized = Vec::new();
        for (key, tmp_name) in tmp_names.iter() {
            let key = key.to_owned();
            let mut inner = Array::new();
            inner.set(ArrayKey::from("tmp_name"), tmp_name.clone());
            inner.set(ArrayKey::from("size"), nested("size", &key, Value::Int(0)));
            inner.set(ArrayKey::from("error"), nested("error", &key, Value::Int(UPLOAD_ERR_NO_FILE)));
            inner.set(ArrayKey::from("name"), nested("name", &key, Value::Null));
            inner.set(ArrayKey::from("type"), nested("type", &key, Value::Null));
            normalized.push((key, spec(&inner)));
        }
        return UploadedFiles::Tree(normalized);
    }
    let value = |k: &str| file.get_str(k).filter(|v| !matches!(v, Value::Null));
    UploadedFiles::File(UploadedFile::new(
        PathBuf::from(value("tmp_name").map(string).unwrap_or_default()),
        value("size").map_or(0, int),
        value("error").map_or(UPLOAD_ERR_NO_FILE, int),
        value("name").and_then(nullable_string),
        value("type").and_then(nullable_string),
    ))
}

/// A scalar as a string (arrays and objects: empty).
fn string(value: &Value) -> String {
    nullable_string(value).unwrap_or_default()
}

fn nullable_string(value: &Value) -> Option<String> {
    match value {
        Value::Null | Value::Array(_) | Value::Object(_) | Value::Ext(_) => None,
        scalar => scalar.to_php_string().ok().map(|s| String::from_utf8_lossy(&s).into_owned()),
    }
}

/// `(int)` of an int, float or string; 0 for anything else.
fn int(value: &Value) -> i64 {
    match value {
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::Arg::Float(*f).to_int(),
        Value::Str(s) => php_std::format::Arg::Str(s).to_int(),
        _ => 0,
    }
}
