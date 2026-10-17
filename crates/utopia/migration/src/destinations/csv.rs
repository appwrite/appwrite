//! Rows exported to a CSV file.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::json::{Flags, encode};
use php_std::{Array, Value};
use utopia_async::io::AsyncWriteExt;
use utopia_storage::{Device, Local};

use super::json::{row_fields, sanitize_filename, temp_local};
use crate::destination::{Callback, Destination};
use crate::error::{Error, Result};
use crate::exception::{Exception, Warning};
use crate::resource::{Kind, ResourceRef, status, types};
use crate::source::ResourceIds;
use crate::target::Target;
use crate::transfer::{Report, STORAGE_MAX_CHUNK_SIZE, group};

/// CSV formatting options.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CsvOptions {
    /// The field delimiter.
    pub delimiter: String,
    /// The enclosure.
    pub enclosure: String,
    /// The escape character (unused when writing, as in PHP).
    pub escape: String,
    /// Whether each batch starts with a header line.
    pub include_headers: bool,
}

impl Default for CsvOptions {
    fn default() -> Self {
        Self { delimiter: ",".into(), enclosure: "\"".into(), escape: "\"".into(), include_headers: true }
    }
}

/// Rows of one table exported to `<directory>/<filename>.csv` on a storage
/// device. Each imported batch writes a header line (when headers are on)
/// and one line per row; values are converted to text (`null`, `true`,
/// `false`, arrays as JSON or their `$id`). [`Destination::shutdown`] copies
/// the file to the device.
///
/// PHP: `Utopia\Migration\Destinations\CSV`. The header is written once per
/// batch, not once per file (PHP's `$csvHeader` is a local of `import()`).
pub struct Csv {
    target: Target,
    device: Arc<dyn Device>,
    resource_id: String,
    resource_child_id: Option<String>,
    directory: String,
    output_file: String,
    local: Local,
    allowed_columns: Vec<String>,
    options: CsvOptions,
}

impl std::fmt::Debug for Csv {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Csv").field("output_file", &self.output_file).finish_non_exhaustive()
    }
}

impl Csv {
    /// A destination writing `<directory>/<filename>.csv` to `device`.
    ///
    /// PHP: `new CSV($deviceForFiles, $resourceId, $directory, $filename,
    /// $allowedColumns, $delimiter, $enclosure, $escape, $includeHeaders)`.
    pub async fn new(
        device: Arc<dyn Device>,
        resource_id: impl Into<String>,
        directory: impl Into<String>,
        filename: &str,
        allowed_columns: Vec<String>,
        options: CsvOptions,
    ) -> Result<Self> {
        Ok(Self {
            target: Target::new(),
            device,
            resource_id: resource_id.into(),
            resource_child_id: None,
            directory: directory.into(),
            output_file: sanitize_filename(filename),
            local: temp_local("csv_export_").await?,
            allowed_columns,
            options,
        })
    }

    /// A destination for table `table_id` of database `database_id`.
    ///
    /// PHP: `CSV::fromResourceIds()`.
    pub async fn from_resource_ids(
        device: Arc<dyn Device>,
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        directory: impl Into<String>,
        filename: &str,
        allowed_columns: Vec<String>,
        options: CsvOptions,
    ) -> Result<Self> {
        let mut d = Self::new(device, database_id, directory, filename, allowed_columns, options).await?;
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

    /// The sanitized output file name (without `.csv`).
    pub fn output_file(&self) -> &str {
        &self.output_file
    }

    fn line(&self, fields: &[Vec<u8>]) -> Vec<u8> {
        let delimiter = self.options.delimiter.as_bytes();
        let enclosure = self.options.enclosure.as_bytes();
        let mut special: Vec<u8> = delimiter.to_vec();
        special.extend_from_slice(b"\n\r");
        special.extend_from_slice(enclosure);
        let mut out = Vec::new();
        for (i, field) in fields.iter().enumerate() {
            if i > 0 {
                out.extend_from_slice(delimiter);
            }
            if !special.is_empty() && field.iter().any(|b| special.contains(b)) {
                let mut doubled = enclosure.to_vec();
                doubled.extend_from_slice(enclosure);
                out.extend_from_slice(enclosure);
                if enclosure.is_empty() {
                    out.extend_from_slice(field);
                } else {
                    out.extend_from_slice(&php_std::string::str_replace(enclosure, &doubled, field).0);
                }
                out.extend_from_slice(enclosure);
            } else {
                out.extend_from_slice(field);
            }
        }
        out.push(b'\n');
        out
    }
}

/// `convertValueToCSV()`.
pub(crate) fn csv_value(value: &Value) -> Vec<u8> {
    match value {
        Value::Null => b"null".to_vec(),
        Value::Bool(b) => {
            if *b {
                b"true".to_vec()
            } else {
                b"false".to_vec()
            }
        }
        Value::Array(a) => {
            if a.is_empty() {
                return Vec::new();
            }
            match a.get_str("$id") {
                Some(id) if !matches!(id, Value::Null) => {
                    id.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default()
                }
                _ => encode(value, Flags::NONE, 512).unwrap_or_default().into_bytes(),
            }
        }
        Value::Object(_) => encode(value, Flags::NONE, 512).unwrap_or_default().into_bytes(),
        other => other.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default(),
    }
}

#[async_trait]
impl Destination for Csv {
    fn name(&self) -> &str {
        "CSV"
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
        let log = format!("{}/{}.csv", self.local_root(), self.output_file);
        let mut lines: Vec<u8> = Vec::new();
        let mut header = false;
        for resource in resources {
            if !matches!(resource.kind(), Kind::Row(_)) {
                continue;
            }
            let data: Array = row_fields(resource, &self.allowed_columns);
            if !header && self.options.include_headers {
                let keys: Vec<Vec<u8>> = data
                    .keys()
                    .map(|k| match k.to_owned() {
                        php_std::ArrayKey::Int(i) => i.to_string().into_bytes(),
                        php_std::ArrayKey::Str(s) => s.as_bytes().to_vec(),
                    })
                    .collect();
                lines.extend(self.line(&keys));
                header = true;
            }
            let values: Vec<Vec<u8>> = data.values().map(csv_value).collect();
            lines.extend(self.line(&values));
            resource.set_status(status::SUCCESS, "");
            if let Ok(cache) = self.target.cache() {
                cache.update(resource);
            }
        }
        if !lines.is_empty() {
            let mut f =
                utopia_async::fs::OpenOptions::new().append(true).create(true).open(&log).await.map_err(|_| {
                    Error::exception(format!("Failed to open file for writing: {log}"), Exception::CODE_INTERNAL)
                })?;
            f.write_all(&lines).await.map_err(|_| {
                Error::exception(format!("Failed to write CSV line to file: {log}"), Exception::CODE_INTERNAL)
            })?;
        }
        callback(resources)
    }

    async fn shutdown(&mut self) -> Result<()> {
        let filename = format!("{}.csv", self.output_file);
        let source_path = utopia_storage::Device::path(&self.local, &filename);
        let dest_path = self.device.path(&format!("{}/{}", self.directory, filename));
        if !utopia_storage::Device::exists(&self.local, &source_path).await.unwrap_or(false) {
            return Err(Error::exception(
                format!("No data to export for resource: {}", self.resource_id),
                Exception::CODE_NOT_FOUND,
            ));
        }
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
                return Err(Error::exception(
                    format!("Error transferring to {}/{filename}", self.device.root()),
                    Exception::CODE_INTERNAL,
                ));
            }
            if !self.device.exists(&dest_path).await.unwrap_or(false) {
                return Err(Error::exception(
                    format!("File not found on destination: {dest_path}"),
                    Exception::CODE_NOT_FOUND,
                ));
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
