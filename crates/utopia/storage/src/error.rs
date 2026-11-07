//! The error type shared by every device and validator.

/// Why a storage operation failed.
///
/// Every runtime failure is a storage error a caller can match on as one
/// category; invalid arguments (programmer errors) are
/// [`Error::InvalidArgument`].
///
/// PHP: one variant per PHP exception class (see [`Error::php_class`]);
/// `StorageException` is the base class of every runtime one.
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A storage failure that is none of the more specific ones, such as a
    /// directory that cannot be created or a file that cannot be written.
    ///
    /// PHP: `Utopia\Storage\Exception\StorageException`.
    #[error("{0}")]
    Storage(String),
    /// There is no file at the path.
    ///
    /// PHP: `Utopia\Storage\Exception\NotFoundException`, its code the HTTP
    /// status when S3 reported it.
    #[error("{message}")]
    NotFound {
        /// The message.
        message: String,
        /// The HTTP status, or 0.
        code: i64,
    },
    /// A conditional operation was refused: the file no longer carries the
    /// ETag the caller named, or a file appeared where none was expected.
    ///
    /// PHP: `Utopia\Storage\Exception\PreconditionFailedException`.
    #[error("{message}")]
    PreconditionFailed {
        /// The message.
        message: String,
        /// The HTTP status, or 0.
        code: i64,
    },
    /// A chunked upload is in an invalid state: a chunk is missing, the
    /// multipart upload was never prepared, or it could not be finalized.
    ///
    /// PHP: `Utopia\Storage\Exception\UploadException`.
    #[error("{0}")]
    Upload(String),
    /// The storage service answered, but with an error or a malformed
    /// response.
    ///
    /// PHP: `Utopia\Storage\Exception\RemoteException`: `code` is the
    /// exception code, `error_code` its `$errorCode`.
    #[error("{message}")]
    Remote {
        /// The message, with the S3 request IDs when the service sent them.
        message: String,
        /// The HTTP status, or 0.
        code: i64,
        /// The service's own error identifier, such as `SlowDown`.
        error_code: Option<String>,
    },
    /// The request never got an answer: DNS, connection or timeout.
    ///
    /// PHP: `Utopia\Storage\Exception\TransportException`, with the client
    /// exception as its previous one.
    #[error("{message}")]
    Transport {
        /// The client's message.
        message: String,
        /// The client's error code (a cURL or socket error number), or 0.
        code: i64,
    },
    /// The body handed to a write failed while it was read.
    ///
    /// PHP: the stream's own exception, thrown through unchanged.
    #[error("{0}")]
    Body(std::io::Error),
    /// An invalid argument, such as a chunk size below one or a foreign
    /// listing cursor.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// A call the device does not support, such as listing the multipart
    /// uploads of a device that has none.
    ///
    /// PHP: `\BadMethodCallException`.
    #[error("{0}")]
    BadMethodCall(String),
}

impl Error {
    /// The name of the exception class this error matches.
    ///
    /// PHP: the class `utopia-php/storage` throws in the same case. A
    /// [`Error::Body`] reports `RuntimeException`, as the body's own
    /// exception class is not known.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::Storage(_) => "Utopia\\Storage\\Exception\\StorageException",
            Error::NotFound { .. } => "Utopia\\Storage\\Exception\\NotFoundException",
            Error::PreconditionFailed { .. } => "Utopia\\Storage\\Exception\\PreconditionFailedException",
            Error::Upload(_) => "Utopia\\Storage\\Exception\\UploadException",
            Error::Remote { .. } => "Utopia\\Storage\\Exception\\RemoteException",
            Error::Transport { .. } => "Utopia\\Storage\\Exception\\TransportException",
            Error::Body(_) => "RuntimeException",
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::BadMethodCall(_) => "BadMethodCallException",
        }
    }

    /// The exception code: the HTTP status for errors S3 reported, the
    /// client's error number for transport failures, otherwise 0.
    ///
    /// PHP: `getCode()`.
    pub fn code(&self) -> i64 {
        match self {
            Error::NotFound { code, .. }
            | Error::PreconditionFailed { code, .. }
            | Error::Remote { code, .. }
            | Error::Transport { code, .. } => *code,
            _ => 0,
        }
    }

    /// Whether this is [`Error::NotFound`].
    pub fn is_not_found(&self) -> bool {
        matches!(self, Error::NotFound { .. })
    }

    pub(crate) fn not_found(message: impl Into<String>) -> Self {
        Error::NotFound { message: message.into(), code: 0 }
    }

    pub(crate) fn precondition(message: impl Into<String>) -> Self {
        Error::PreconditionFailed { message: message.into(), code: 0 }
    }

    #[cfg(feature = "s3")]
    pub(crate) fn remote(message: impl Into<String>) -> Self {
        Error::Remote { message: message.into(), code: 0, error_code: None }
    }
}

/// A `Result` with [`Error`].
pub type Result<T, E = Error> = std::result::Result<T, E>;
