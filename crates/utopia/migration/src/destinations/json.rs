//! Rows exported to a JSON file.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use utopia_async::io::AsyncWriteExt;
use utopia_storage::{Device, Local};

use crate::destination::{Callback, Destination};
use crate::error::{Error, Result};
use crate::exception::{Exception, Warning};
use crate::resource::{Kind, ResourceRef, status, types};
use crate::source::ResourceIds;
use crate::target::Target;
use crate::transfer::{Report, STORAGE_MAX_CHUNK_SIZE, group};

/// `sanitizeFilename()` of the CSV and JSON destinations: `:/<>"|*?` and
/// bytes outside printable ASCII become `_`, the result is trimmed, and an
/// empty result (or `"0"`) is `export`.
pub fn sanitize_filename(filename: &str) -> String {
    let replaced: Vec<u8> = filename
        .bytes()
        .map(|b| {
            if matches!(b, b':' | b'/' | b'<' | b'>' | b'"' | b'|' | b'*' | b'?') || !(0x20..=0x7E).contains(&b) {
                b'_'
            } else {
                b
            }
        })
        .collect();
    let s = String::from_utf8_lossy(&replaced).into_owned();
    let t = s.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b']);
    if t.is_empty() || t == "0" { "export".to_owned() } else { t.to_owned() }
}

/// A row's attributes with `$id`, `$permissions`, `$createdAt` and
/// `$updatedAt` first, limited to `allowed` columns when any are given.
pub(crate) fn row_fields(resource: &ResourceRef, allowed: &[String]) -> Array {
    let (Kind::Row(row), base) = (resource.kind(), resource.base_snapshot()) else {
        return Array::new();
    };
    let mut data = row.data.clone();
    let mut out = Array::new();
    out.set("$id".into(), Value::from(base.id.as_str()));
    out.set("$permissions".into(), crate::resource::str_list(&base.permissions));
    out.set(
        "$createdAt".into(),
        data.get_str("$createdAt").filter(|v| !matches!(v, Value::Null)).cloned().unwrap_or_else(|| Value::from("")),
    );
    out.set(
        "$updatedAt".into(),
        data.get_str("$updatedAt").filter(|v| !matches!(v, Value::Null)).cloned().unwrap_or_else(|| Value::from("")),
    );
    data.remove(&"$createdAt".into());
    data.remove(&"$updatedAt".into());
    if allowed.is_empty() {
        for (k, v) in data.iter() {
            // array_merge(): integer keys are renumbered.
            match k.to_owned() {
                php_std::ArrayKey::Int(_) => {
                    let _ = out.push(v.clone());
                }
                key => {
                    out.set(key, v.clone());
                }
            }
        }
    } else {
        for (k, v) in data.iter() {
            let key = k.to_owned();
            let name = match &key {
                php_std::ArrayKey::Int(i) => i.to_string(),
                php_std::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            };
            if allowed.contains(&name) {
                out.set(key, v.clone());
            }
        }
    }
    out
}

/// Rows of one table exported to `<directory>/<filename>.json` on a storage
/// device: a JSON array of objects with `$id`, `$permissions`, `$createdAt`
/// and `$updatedAt` first. Batches are written to a local temporary file;
/// [`Destination::shutdown`] closes the array and copies it to the device.
///
/// PHP: `Utopia\Migration\Destinations\JSON`.
pub struct Json {
    target: Target,
    device: Arc<dyn Device>,
    resource_id: String,
    resource_child_id: Option<String>,
    directory: String,
    output_file: String,
    local: Local,
    allowed_columns: Vec<String>,
    json_started: bool,
    json_has_items: bool,
}

impl std::fmt::Debug for Json {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Json").field("output_file", &self.output_file).finish_non_exhaustive()
    }
}

/// A local temporary directory for an export (`sys_get_temp_dir()/<prefix><uniqid>`).
pub(crate) async fn temp_local(prefix: &str) -> Result<Local> {
    let dir = std::env::temp_dir();
    let root =
        format!("{}/{prefix}{}", dir.to_string_lossy().trim_end_matches('/'), php_std::system::uniqid("", false));
    if utopia_async::fs::metadata(&root).await.is_err() {
        let mut b = utopia_async::fs::DirBuilder::new();
        b.recursive(true);
        #[cfg(unix)]
        b.mode(0o755);
        b.create(&root).await.map_err(|_| Error::exception(format!("Error creating directory: {root}"), 0))?;
    }
    Ok(Local::new(root))
}

impl Json {
    /// A destination writing `<directory>/<filename>.json` to `device` for
    /// `resource_id` (`<database>:<table>`), keeping only `allowed_columns`
    /// when any are given.
    ///
    /// PHP: `new JSON($deviceForFiles, $resourceId, $directory, $filename, $allowedColumns)`.
    pub async fn new(
        device: Arc<dyn Device>,
        resource_id: impl Into<String>,
        directory: impl Into<String>,
        filename: &str,
        allowed_columns: Vec<String>,
    ) -> Result<Self> {
        Ok(Self {
            target: Target::new(),
            device,
            resource_id: resource_id.into(),
            resource_child_id: None,
            directory: directory.into(),
            output_file: sanitize_filename(filename),
            local: temp_local("json_export_").await?,
            allowed_columns,
            json_started: false,
            json_has_items: false,
        })
    }

    /// A destination for table `table_id` of database `database_id`.
    ///
    /// PHP: `JSON::fromResourceIds()`.
    pub async fn from_resource_ids(
        device: Arc<dyn Device>,
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        directory: impl Into<String>,
        filename: &str,
        allowed_columns: Vec<String>,
    ) -> Result<Self> {
        let mut d = Self::new(device, database_id, directory, filename, allowed_columns).await?;
        d.resource_child_id = Some(table_id.into());
        Ok(d)
    }

    /// The resource id.
    pub fn resource_id(&self) -> &str {
        &self.resource_id
    }

    /// The table id given apart from the database id.
    pub fn resource_child_id(&self) -> Option<&str> {
        self.resource_child_id.as_deref()
    }

    /// The local temporary directory batches are written to.
    pub fn local_root(&self) -> &str {
        utopia_storage::Device::root(&self.local)
    }

    /// The sanitized output file name (without `.json`).
    pub fn output_file(&self) -> &str {
        &self.output_file
    }
}

async fn append(path: &str, bytes: &[u8]) -> Result<()> {
    let mut f = utopia_async::fs::OpenOptions::new()
        .append(true)
        .create(true)
        .open(path)
        .await
        .map_err(|_| Error::exception(format!("Failed to open file for writing: {path}"), 0))?;
    f.write_all(bytes).await.map_err(|_| Error::exception("Failed to write JSON data to file.", 0))?;
    Ok(())
}

#[async_trait]
impl Destination for Json {
    fn name(&self) -> &str {
        "JSON"
    }

    fn supported_resources(&self) -> Vec<&'static str> {
        vec![types::ROW]
    }

    fn target(&self) -> &Target {
        &self.target
    }

    fn target_mut(&mut self) -> &mut Target {
        &mut self.target
    }

    async fn report(&mut self, _resources: &[String], _resource_ids: &ResourceIds) -> Result<Report> {
        Ok(Report::new())
    }

    async fn import(&mut self, resources: &[ResourceRef], callback: &mut Callback<'_>) -> Result<()> {
        let log = format!("{}/{}.json", self.local_root(), self.output_file);
        let mut buffer: Vec<u8> = Vec::new();
        if !self.json_started {
            buffer.push(b'[');
            self.json_started = true;
        }
        for resource in resources {
            if !matches!(resource.kind(), Kind::Row(_)) {
                continue;
            }
            let data = row_fields(resource, &self.allowed_columns);
            match encode(&Value::Array(data), Flags::THROW_ON_ERROR | Flags::INVALID_UTF8_SUBSTITUTE, 512) {
                Ok(json) => {
                    if self.json_has_items {
                        buffer.push(b',');
                    }
                    buffer.extend_from_slice(json.as_bytes());
                    self.json_has_items = true;
                    resource.set_status(status::SUCCESS, "");
                    if let Ok(cache) = self.target.cache() {
                        cache.update(resource);
                    }
                }
                Err(e) => {
                    let message = e.message().to_owned();
                    resource.set_status(status::ERROR, message.clone());
                    self.target.add_error(Exception::new(
                        resource.name(),
                        resource.group(),
                        Some(resource.id()),
                        message.clone(),
                        Exception::CODE_VALIDATION,
                        Some(Error::Other { class: "JsonException".into(), message, code: e.code().code() }),
                    ));
                }
            }
        }
        if !buffer.is_empty() {
            append(&log, &buffer).await?;
        }
        callback(resources)
    }

    async fn shutdown(&mut self) -> Result<()> {
        let filename = format!("{}.json", self.output_file);
        let source_path = utopia_storage::Device::path(&self.local, &filename);
        let dest_path = self.device.path(&format!("{}/{}", self.directory, filename));
        if !utopia_storage::Device::exists(&self.local, &source_path).await.unwrap_or(false) {
            return Err(Error::exception(format!("No data to export for resource: {}", self.resource_id), 0));
        }
        if !self.json_started {
            append(&source_path, b"[").await?;
            self.json_started = true;
        }
        append(&source_path, b"]").await?;
        let result = async {
            let copied = utopia_storage::Device::copy(
                &self.local,
                &source_path,
                &dest_path,
                Some(&*self.device),
                STORAGE_MAX_CHUNK_SIZE,
            )
            .await
            .unwrap_or(false);
            if !copied {
                return Err(Error::exception(format!("Error transferring to {}/{filename}", self.device.root()), 0));
            }
            if !self.device.exists(&dest_path).await.unwrap_or(false) {
                return Err(Error::exception(format!("File not found on destination: {dest_path}"), 0));
            }
            Ok(())
        }
        .await;
        let removed = utopia_storage::Device::delete_path(&self.local, "").await.unwrap_or(false);
        if !removed || utopia_storage::Device::exists(&self.local, self.local_root()).await.unwrap_or(false) {
            self.target.add_warning(Warning::new(
                types::ROW,
                group::DATABASES,
                format!("Error cleaning up: {}", self.local_root()),
                self.resource_child_id.clone().unwrap_or_else(|| self.resource_id.clone()),
            ));
        }
        result
    }
}
