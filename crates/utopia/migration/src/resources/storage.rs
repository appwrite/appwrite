//! Buckets and files.

use std::sync::{Mutex, MutexGuard};

use php_std::{Array, Value};

use super::{Input, param, to_array, to_bool, to_bytes, to_int, to_opt_int, to_string};
use crate::error::Result;
use crate::resource::{Base, Chunk, Kind, Resource, ResourceRef, lock};

/// A storage bucket.
///
/// PHP: `Utopia\Migration\Resources\Storage\Bucket` (`getBucketName()` is the name).
#[derive(Debug, Clone, PartialEq)]
pub struct Bucket {
    /// The name.
    pub name: String,
    /// Whether file permissions apply.
    pub file_security: bool,
    /// Whether it is enabled.
    pub enabled: bool,
    /// The maximum file size.
    pub max_file_size: Option<i64>,
    /// The allowed extensions.
    pub allowed_file_extensions: Array,
    /// `none`, `gzip` or `zstd`.
    pub compression: String,
    /// Whether files are encrypted.
    pub encryption: bool,
    /// Whether files are scanned.
    pub anti_virus: bool,
    /// Whether limits are updated (unused by destinations).
    pub update_limits: bool,
    /// Whether image transformations are allowed.
    pub transformations: bool,
}

impl Default for Bucket {
    fn default() -> Self {
        Self {
            name: String::new(),
            file_security: false,
            enabled: false,
            max_file_size: None,
            allowed_file_extensions: Array::new(),
            compression: "none".to_owned(),
            encryption: false,
            anti_virus: false,
            update_limits: false,
            transformations: false,
        }
    }
}

impl Bucket {
    /// A resource of this bucket.
    pub fn resource(self, id: impl Into<String>, permissions: Vec<String>) -> ResourceRef {
        Resource::new(Kind::Bucket(self), Base { id: id.into(), permissions, ..Base::default() })
    }

    /// PHP: `Bucket::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Storage\\Bucket::__construct", n, name);
        let b = |k: &str, n, name| to_bool(&i.or(k, Value::Bool(false)), &p(n, name));
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let name = to_string(&i.or("name", Value::from("")), &p(2, "name"))?;
        let permissions = to_array(&i.or("permissions", Value::Array(Array::new())), &p(3, "permissions"))?;
        let bucket = Bucket {
            name,
            file_security: b("fileSecurity", 4, "fileSecurity")?,
            enabled: b("enabled", 5, "enabled")?,
            max_file_size: to_opt_int(&i.or("maxFileSize", Value::Null), &p(6, "maxFileSize"))?,
            allowed_file_extensions: to_array(
                &i.or("allowedFileExtensions", Value::Array(Array::new())),
                &p(7, "allowedFileExtensions"),
            )?,
            compression: to_string(&i.or("compression", Value::from("none")), &p(8, "compression"))?,
            encryption: b("encryption", 9, "encryption")?,
            anti_virus: b("antiVirus", 10, "antiVirus")?,
            update_limits: b("updateLimits", 11, "updateLimits")?,
            transformations: b("transformations", 12, "transformations")?,
        };
        Ok(bucket.resource(id, super::strings(&permissions)))
    }
}

/// A file, transferred in chunks.
///
/// PHP: `Utopia\Migration\Resources\Storage\File` (`getFileName()` is the name).
#[derive(Debug)]
pub struct File {
    /// The bucket.
    pub bucket: ResourceRef,
    /// The name.
    pub name: String,
    /// The MD5 signature.
    pub signature: String,
    /// The MIME type.
    pub mime_type: String,
    /// The size in bytes.
    pub size: i64,
    /// The folder it is in (`''`: the bucket's root).
    pub folder: String,
    chunk: Mutex<Chunk>,
}

impl File {
    /// A file of `bucket` with its first chunk.
    pub fn new(
        bucket: ResourceRef,
        name: impl Into<String>,
        signature: impl Into<String>,
        mime_type: impl Into<String>,
        size: i64,
        chunk: Chunk,
    ) -> Self {
        Self {
            bucket,
            name: name.into(),
            signature: signature.into(),
            mime_type: mime_type.into(),
            size,
            folder: String::new(),
            chunk: Mutex::new(chunk),
        }
    }

    /// The file in `folder`.
    pub fn with_folder(mut self, folder: impl Into<String>) -> Self {
        self.folder = folder.into();
        self
    }

    /// The current chunk.
    pub fn chunk(&self) -> MutexGuard<'_, Chunk> {
        lock(&self.chunk)
    }

    /// The current chunk, to change it.
    pub fn chunk_mut(&self) -> MutexGuard<'_, Chunk> {
        lock(&self.chunk)
    }

    /// The length of the current chunk's data (`getSizeInBytes()`).
    pub fn size_in_bytes(&self) -> i64 {
        self.chunk().data.len() as i64
    }

    /// `end - start` (`getChunkSize()`).
    pub fn chunk_size(&self) -> i64 {
        let c = self.chunk();
        c.end - c.start
    }

    /// A resource of this file.
    pub fn resource(self, id: impl Into<String>, permissions: Vec<String>) -> ResourceRef {
        Resource::new(Kind::File(self), Base { id: id.into(), permissions, ..Base::default() })
    }

    /// PHP: `File::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Storage\\File::__construct", n, name);
        let s = |k: &str, n, name| to_string(&i.or(k, Value::from("")), &p(n, name));
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let bucket = Bucket::from_array(
            &i.array("bucket", &param("Utopia\\Migration\\Resources\\Storage\\Bucket::fromArray", 1, "array"))?,
        )?;
        let name = s("name", 3, "name")?;
        let signature = s("signature", 4, "signature")?;
        let mime = s("mimeType", 5, "mimeType")?;
        let permissions = to_array(&i.or("permissions", Value::Array(Array::new())), &p(6, "permissions"))?;
        let size = to_int(&i.or("size", Value::Int(0)), &p(7, "size"))?;
        let data = to_bytes(&i.or("data", Value::from("")), &p(8, "data"))?;
        let start = to_int(&i.or("start", Value::Int(0)), &p(9, "start"))?;
        let end = to_int(&i.or("end", Value::Int(0)), &p(10, "end"))?;
        let folder = s("folder", 11, "folder")?;
        Ok(File::new(bucket, name, signature, mime, size, Chunk { start, end, data })
            .with_folder(folder)
            .resource(id, super::strings(&permissions)))
    }
}
