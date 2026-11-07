use bytes::Bytes;

/// A loaded file: its contents and MIME type.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct File {
    /// The file's bytes.
    pub contents: Bytes,
    /// The MIME type, from [`EXTENSIONS`](crate::EXTENSIONS) or sniffed from the contents.
    pub mime: String,
}
