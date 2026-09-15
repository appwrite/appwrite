use std::path::PathBuf;
use std::time::SystemTime;

/// Metadata for a single stored file.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FileInfo {
    pub path: PathBuf,
    pub size: u64,
    pub modified_at: Option<SystemTime>,
    pub etag: Option<String>,
}

impl FileInfo {
    pub fn new(
        path: impl Into<PathBuf>,
        size: u64,
        modified_at: Option<SystemTime>,
        etag: Option<String>,
    ) -> Self {
        Self {
            path: path.into(),
            size,
            modified_at,
            etag,
        }
    }
}

/// One page of a file listing. When `cursor` is not `None`, pass it back to
/// [`crate::Device::list_files`] to fetch the next page.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct FileList {
    pub files: Vec<FileInfo>,
    pub cursor: Option<String>,
}

impl FileList {
    pub fn new(files: Vec<FileInfo>, cursor: Option<String>) -> Self {
        Self { files, cursor }
    }
}
