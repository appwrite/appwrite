//! Storage devices and file validators for Utopia.
//!
//! Rust port of [`utopia-php/storage`](https://github.com/utopia-php/storage).

#![deny(unsafe_code)]

mod acl;
mod device;
mod device_type;
mod error;
mod file_info;
#[cfg(feature = "telemetry")]
mod telemetry;
mod validators;

pub use acl::Acl;
pub use device::Local;
pub use device::{
    absolute_path, Device, ParallelUploadOptions, PartValue, ReadSeek, UploadMetadata,
    COPY_CHUNK_SIZE, DEFAULT_MULTIPART_PART_SIZE, DEFAULT_UPLOAD_CONCURRENCY,
    MIN_MULTIPART_PART_SIZE, PIPE_CHUNK_SIZE,
};
#[cfg(feature = "s3")]
pub use device::{AwsS3, Backblaze, DoSpaces, Linode, RetryStrategy, S3Response, Wasabi, S3};
pub use device_type::DeviceType;
pub use error::{NotFound, StorageError, UploadError};
pub use file_info::{FileInfo, FileList};
#[cfg(feature = "telemetry")]
pub use telemetry::TelemetryDevice;
pub use validators::{
    FileExt, FileName, FileSize, FileType, Upload, FILE_TYPE_GIF, FILE_TYPE_GZIP, FILE_TYPE_JPEG,
    FILE_TYPE_PNG, GIF, GZIP, JPEG, JPG, PNG, ZIP,
};

#[cfg(feature = "s3")]
pub mod s3 {
    //! S3-compatible storage adapters (AWS, Wasabi, Backblaze, Linode, etc.).

    pub use crate::device::s3::*;
}
