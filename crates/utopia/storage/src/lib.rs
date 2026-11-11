//! File storage on the local disk or in S3-compatible object stores, behind
//! one [`Device`] trait: write, read (whole or a byte range), conditional
//! create and replace, chunked and multipart uploads, copies between
//! devices, listings, file info and hashes. [`Telemetry`] measures any
//! device, and [`validator`] checks uploaded files.
//!
//! Devices are async: [`Local`] does each read or write in one blocking
//! call on the worker (see its docs), [`S3`] sends signed requests over
//! HTTP(S). Bodies are [`Body`] streams.
//!
//! PHP: ports `utopia-php/storage`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Storage\Device` (abstract) | [`Device`] (trait); the base `copy()`, `move()` and `stream()` are [`copy`], [`move_file`] and [`stream`] |
//! | the `callable(string): void $sink` of `Device::stream()` | [`Sink`] |
//! | `Device::getAbsolutePath()` | [`absolute_path`] |
//! | `Device::COPY_CHUNK_SIZE` | [`COPY_CHUNK_SIZE`] |
//! | `$metadata` (`UploadMetadata` array) | [`Metadata`], [`Part`] |
//! | `Psr\Http\Message\StreamInterface` bodies | [`Body`], [`Source`] |
//! | `Utopia\Storage\Device\Local` | [`Local`] |
//! | `Utopia\Storage\Device\S3` | [`S3`] |
//! | `Device\AWS`, `DOSpaces`, `Backblaze`, `Linode`, `Wasabi` | [`S3::aws`], [`S3::do_spaces`], [`S3::backblaze`], [`S3::linode`], [`S3::wasabi`]; regions in [`s3::regions`] |
//! | `Device\S3\RetryStrategy` | [`s3::RetryStrategy`] |
//! | `Device\S3\Response` | internal |
//! | PSR-18 client, `Utopia\Client\Decorator\Retry` | [`s3::Client`], [`s3::HttpClient`], [`s3::Retry`] |
//! | `Utopia\Storage\Device\Telemetry` | [`Telemetry`] |
//! | `Utopia\Storage\DeviceType`, `Acl` | [`DeviceType`], [`Acl`] |
//! | `FileInfo`, `FileList`, `UploadInfo`, `UploadList` | [`FileInfo`], [`FileList`], [`UploadInfo`], [`UploadList`] |
//! | `Validator\FileExt`, `FileName`, `FileSize`, `FileType`, `Upload` | [`validator::FileExt`], [`validator::FileName`], [`validator::FileSize`], [`validator::FileType`], [`validator::Upload`] |
//! | `Exception\StorageException` and its subclasses | [`Error`] |
//! | `mime_content_type()` | [`mime::detect`] |
//!
//! Features: `s3` (S3 and the providers) and `telemetry` (the [`Telemetry`]
//! decorator), both on by default.

#![warn(missing_docs)]

mod body;
mod device;
mod error;
mod local;
pub mod mime;
#[cfg(feature = "s3")]
pub mod s3;
#[cfg(feature = "telemetry")]
mod telemetry;
pub mod validator;

pub use body::{Body, Source};
pub use device::{
    Acl, COPY_CHUNK_SIZE, Device, DeviceType, FileInfo, FileList, Metadata, Part, Sink, UploadInfo, UploadList,
    absolute_path, copy, move_file, stream,
};
pub use error::{Error, Result};
pub use local::Local;
#[cfg(feature = "s3")]
pub use s3::S3;
#[cfg(feature = "telemetry")]
pub use telemetry::Telemetry;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
