//! Validators for uploaded files: extension, name, size, type and origin.

use std::collections::HashSet;
use std::io::Read;

use utopia_validators::{Type, Validator, Value};

use crate::{Error, Result};

/// Accepts file names whose extension (case-insensitive) is in a list.
///
/// ```
/// use utopia_storage::validator::FileExt;
/// use utopia_validators::Validator;
///
/// let validator = FileExt::new(["gif", "gz"]);
/// assert!(validator.is_valid(&"archive.tar.gz".into()));
/// assert!(!validator.is_valid(&"file.tar".into()));
/// ```
///
/// ```php
/// use Utopia\Storage\Validator\FileExt;
///
/// $validator = new FileExt(['gif', 'gz']);
/// var_dump($validator->isValid('archive.tar.gz')); // bool(true)
/// var_dump($validator->isValid('file.tar')); // bool(false)
/// ```
///
/// PHP: `Utopia\Storage\Validator\FileExt`; its `TYPE_*` constants are
/// the extension strings.
#[derive(Debug, Clone)]
pub struct FileExt {
    allowed: Vec<String>,
}

impl FileExt {
    /// `jpeg`.
    pub const TYPE_JPEG: &'static str = "jpeg";
    /// `jpg`.
    pub const TYPE_JPG: &'static str = "jpg";
    /// `gif`.
    pub const TYPE_GIF: &'static str = "gif";
    /// `png`.
    pub const TYPE_PNG: &'static str = "png";
    /// `gz`.
    pub const TYPE_GZIP: &'static str = "gz";
    /// `zip`.
    pub const TYPE_ZIP: &'static str = "zip";

    /// A validator accepting the extensions in `allowed` (lowercase).
    ///
    /// PHP: `new FileExt($allowed)`.
    pub fn new<S: Into<String>>(allowed: impl IntoIterator<Item = S>) -> Self {
        FileExt { allowed: allowed.into_iter().map(Into::into).collect() }
    }
}

impl Validator for FileExt {
    fn description(&self) -> String {
        "File extension is not valid".into()
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(name) = value.as_str() else { return false };
        let ext = php_std::string::strtolower(extension(name).as_bytes()).into_owned();
        // `in_array()` compares loosely, so numeric strings match numerically.
        self.allowed.iter().any(|a| php_std::value::loose_str_eq(&ext, a.as_bytes()))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// `pathinfo($path, PATHINFO_EXTENSION)`.
fn extension(path: &str) -> &str {
    let trimmed = path.trim_end_matches('/');
    let base = trimmed.rsplit('/').next().unwrap_or_default();
    base.rfind('.').map(|i| &base[i + 1..]).unwrap_or_default()
}

/// Accepts file names made of letters, digits, `.`, `-` and `_`.
///
/// ```
/// use utopia_storage::validator::FileName;
/// use utopia_validators::Validator;
///
/// assert!(FileName.is_valid(&"test.test-test_test".into()));
/// assert!(!FileName.is_valid(&"../test".into()));
/// ```
///
/// ```php
/// use Utopia\Storage\Validator\FileName;
///
/// var_dump((new FileName())->isValid('test.test-test_test')); // bool(true)
/// var_dump((new FileName())->isValid('../test')); // bool(false)
/// ```
///
/// PHP: `Utopia\Storage\Validator\FileName`.
#[derive(Debug, Clone, Copy, Default)]
pub struct FileName;

impl Validator for FileName {
    fn description(&self) -> String {
        "Filename is not valid".into()
    }

    fn is_valid(&self, value: &Value) -> bool {
        // empty(): "" and "0" are empty.
        let Some(name) = value.as_str() else { return false };
        if name.is_empty() || name == "0" {
            return false;
        }
        let stripped: Vec<u8> = name.bytes().filter(|b| !matches!(b, b'.' | b'-' | b'_')).collect();
        php_std::string::ctype_alnum(&stripped)
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// Accepts integer sizes up to a maximum, in bytes.
///
/// ```
/// use utopia_storage::validator::FileSize;
/// use utopia_validators::Validator;
///
/// let validator = FileSize::new(1000);
/// assert!(validator.is_valid(&1000.into()));
/// assert!(!validator.is_valid(&1001.into()));
/// ```
///
/// ```php
/// use Utopia\Storage\Validator\FileSize;
///
/// $validator = new FileSize(1000);
/// var_dump($validator->isValid(1000)); // bool(true)
/// var_dump($validator->isValid(1001)); // bool(false)
/// ```
///
/// PHP: `Utopia\Storage\Validator\FileSize`.
#[derive(Debug, Clone, Copy)]
pub struct FileSize {
    max: i64,
}

impl FileSize {
    /// A validator accepting sizes up to `max` bytes.
    ///
    /// PHP: `new FileSize($max)`.
    pub fn new(max: i64) -> Self {
        FileSize { max }
    }
}

impl Validator for FileSize {
    fn description(&self) -> String {
        format!("File size can't be bigger than {}", self.max)
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_i64().filter(|_| !value.is_f64()).is_some_and(|size| size <= self.max)
    }

    fn kind(&self) -> Type {
        Type::Integer
    }
}

/// A file type [`FileType`] recognises by its first bytes.
///
/// PHP: the `FileType::FILE_TYPE_*` constants.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// JPEG (`jpeg`).
    Jpeg,
    /// GIF (`gif`).
    Gif,
    /// PNG (`png`).
    Png,
    /// Gzip (`gz`). Its signature is the text `application/x-gzip`, which
    /// no file's first seven bytes can match (a PHP quirk kept).
    Gzip,
}

impl Kind {
    /// The kind named `name` (`jpeg`, `gif`, `png` or `gz`).
    pub fn from_name(name: &str) -> Option<Kind> {
        Some(match name {
            "jpeg" => Kind::Jpeg,
            "gif" => Kind::Gif,
            "png" => Kind::Png,
            "gz" => Kind::Gzip,
            _ => return None,
        })
    }

    fn signature(self) -> &'static [u8] {
        match self {
            Kind::Jpeg => b"\xFF\xD8\xFF",
            Kind::Gif => b"GIF",
            Kind::Png => b"\x89\x50\x4e\x47\x0d\x0a",
            Kind::Gzip => b"application/x-gzip",
        }
    }
}

/// Accepts paths of readable files whose first bytes are those of an
/// allowed type.
///
/// ```
/// use utopia_storage::validator::FileType;
/// use utopia_validators::Validator;
///
/// let validator = FileType::new(["jpeg"])?;
/// assert!(!validator.is_valid(&"/no/such/file".into()));
/// assert!(FileType::new(["bmp"]).is_err());
/// # Ok::<(), utopia_storage::Error>(())
/// ```
///
/// ```php
/// use Utopia\Storage\Validator\FileType;
///
/// $validator = new FileType(['jpeg']);
/// var_dump($validator->isValid('/no/such/file')); // bool(false)
/// ```
///
/// PHP: `Utopia\Storage\Validator\FileType`.
#[derive(Debug, Clone)]
pub struct FileType {
    allowed: Vec<Kind>,
}

impl FileType {
    /// `jpeg`.
    pub const FILE_TYPE_JPEG: &'static str = "jpeg";
    /// `gif`.
    pub const FILE_TYPE_GIF: &'static str = "gif";
    /// `png`.
    pub const FILE_TYPE_PNG: &'static str = "png";
    /// `gz`.
    pub const FILE_TYPE_GZIP: &'static str = "gz";

    /// A validator accepting the types named in `allowed`; an unknown name
    /// is an [`Error::InvalidArgument`].
    ///
    /// PHP: `new FileType($allowed)`.
    pub fn new<'a>(allowed: impl IntoIterator<Item = &'a str>) -> Result<Self> {
        let allowed = allowed
            .into_iter()
            .map(|name| Kind::from_name(name).ok_or_else(|| Error::InvalidArgument("Unknown file mime type".into())))
            .collect::<Result<_>>()?;
        Ok(FileType { allowed })
    }
}

impl Validator for FileType {
    fn description(&self) -> String {
        "File mime-type is not allowed ".into()
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(path) = value.as_str() else { return false };
        let Ok(mut file) = std::fs::File::open(path) else { return false };
        // fgets($handle, 8): up to 7 bytes, stopping after a newline.
        let mut head = Vec::with_capacity(7);
        let mut byte = [0u8; 1];
        while head.len() < 7 {
            match file.read(&mut byte) {
                Ok(1) => {
                    head.push(byte[0]);
                    if byte[0] == b'\n' {
                        break;
                    }
                }
                Ok(_) => break,
                Err(_) => return false,
            }
        }
        if head.is_empty() {
            return false;
        }
        self.allowed.iter().any(|kind| head.starts_with(kind.signature()))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}

/// Accepts paths of files uploaded with the current request.
///
/// PHP: `Utopia\Storage\Validator\Upload`, which asks
/// `is_uploaded_file()`: the SAPI's record of this request's uploads. In
/// Rust the HTTP layer hands the validator the paths it stored uploads at;
/// without any (as in PHP's CLI), nothing is valid.
#[derive(Debug, Clone, Default)]
pub struct Upload {
    uploaded: HashSet<String>,
}

impl Upload {
    /// A validator accepting the paths in `uploaded`.
    pub fn new<S: Into<String>>(uploaded: impl IntoIterator<Item = S>) -> Self {
        Upload { uploaded: uploaded.into_iter().map(Into::into).collect() }
    }
}

impl Validator for Upload {
    fn description(&self) -> String {
        "Not a valid upload file".into()
    }

    fn is_valid(&self, value: &Value) -> bool {
        value.as_str().is_some_and(|path| self.uploaded.contains(path))
    }

    fn kind(&self) -> Type {
        Type::String
    }
}
