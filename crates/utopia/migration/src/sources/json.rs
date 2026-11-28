//! Rows from a JSON file: an array of objects, streamed.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::Value;
use utopia_storage::{COPY_CHUNK_SIZE, Device, DeviceType, Local};

use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::json_machine::{Decoder, Items};
use crate::resource::{ResourceRef, is_supported, types};
use crate::resources::database::{Database, DatabaseFlavor, Row, Table};
use crate::source::{Emitter, ResourceIds, Source};
use crate::target::Target;
use crate::transfer::{Report, group};

/// Rows of one table from a JSON file on a storage device: each item of the
/// top-level array is a row (`$id` and `$permissions` are taken out of its
/// attributes). The file is deleted after the export.
///
/// PHP: `Utopia\Migration\Sources\JSON`.
pub struct Json {
    target: Target,
    file_path: String,
    resource_id: String,
    resource_child_id: Option<String>,
    device: Arc<dyn Device>,
    downloaded: bool,
}

impl std::fmt::Debug for Json {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Json")
            .field("file_path", &self.file_path)
            .field("resource_id", &self.resource_id)
            .finish_non_exhaustive()
    }
}

impl Json {
    /// A source of `file_path` on `device` for `resource_id`, which names
    /// the database and table as `<database>:<table>`.
    ///
    /// PHP: `new JSON($resourceId, $filePath, $device, $dbForProject)` (the
    /// database is unused).
    pub fn new(resource_id: impl Into<String>, file_path: impl Into<String>, device: Arc<dyn Device>) -> Self {
        Self {
            target: Target::new(),
            file_path: file_path.into(),
            resource_id: resource_id.into(),
            resource_child_id: None,
            device,
            downloaded: false,
        }
    }

    /// A source for table `table_id` of database `database_id` (either may
    /// contain `:`).
    ///
    /// PHP: `JSON::fromResourceIds()`.
    pub fn from_resource_ids(
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        file_path: impl Into<String>,
        device: Arc<dyn Device>,
    ) -> Self {
        let mut s = Self::new(database_id, file_path, device);
        s.resource_child_id = Some(table_id.into());
        s
    }

    /// The resource id (`$resourceId`).
    pub fn resource_id(&self) -> &str {
        &self.resource_id
    }

    /// The table id given apart from the database id (`$resourceChildId`).
    pub fn resource_child_id(&self) -> Option<&str> {
        self.resource_child_id.as_deref()
    }

    fn resource_ids(&self) -> (String, String) {
        match &self.resource_child_id {
            Some(child) => (self.resource_id.clone(), child.clone()),
            None => match self.resource_id.split_once(':') {
                Some((a, b)) => (a.to_owned(), b.to_owned()),
                None => (self.resource_id.clone(), String::new()),
            },
        }
    }

    async fn download_to_local(&mut self) -> Result<()> {
        if self.downloaded || self.device.kind() == DeviceType::Local {
            return Ok(());
        }
        let local = Local::new("/");
        let ok =
            self.device.copy(&self.file_path, &self.file_path, Some(&local), COPY_CHUNK_SIZE).await.unwrap_or(false);
        if !ok {
            return Err(Error::exception(
                "Failed to transfer JSON file from device to local storage.",
                Exception::CODE_INTERNAL,
            ));
        }
        self.downloaded = true;
        Ok(())
    }

    async fn export_rows(&mut self, batch_size: i64, out: &mut Emitter<'_>) -> Result<()> {
        let (database_id, table_id) = self.resource_ids();
        let database = Database::new(DatabaseFlavor::Database, "")
            .resource(crate::resource::Base { id: database_id, ..Default::default() });
        let table = Table::new(false, database, "", false, true).resource(table_id, Vec::new(), "", "");
        if !self.device.exists(&self.file_path).await.unwrap_or(false) {
            return Ok(());
        }
        if !self.downloaded {
            self.download_to_local().await?;
        }
        let mut items = Items::from_file(&self.file_path, Decoder::Ext { assoc: true }).await?;
        let mut buffer: Vec<ResourceRef> = Vec::new();
        while let Some((index, item)) = items.next().await? {
            let Value::Array(mut item) = item else {
                return Err(Error::exception(
                    format!("JSON item at index {} is not an object.", crate::resources::cast_string(&index)),
                    Exception::CODE_VALIDATION,
                ));
            };
            let row_id = item
                .get_str("$id")
                .filter(|v| !matches!(v, Value::Null))
                .cloned()
                .unwrap_or_else(|| Value::from("unique()"));
            let mut permissions = Vec::new();
            if let Some(p) = item.get_str("$permissions") {
                permissions = validate_permissions(p)?;
            }
            item.remove(&"$id".into());
            item.remove(&"$permissions".into());
            let id = crate::resources::to_string(
                &row_id,
                &crate::resources::param("Utopia\\Migration\\Resources\\Database\\Row::__construct", 1, "id"),
            )?;
            buffer.push(Row { document: false, table: table.clone(), data: item }.resource(id, permissions));
            if buffer.len() as i64 == batch_size {
                out.emit(std::mem::take(&mut buffer)).await?;
            }
        }
        if !buffer.is_empty() {
            out.emit(buffer).await?;
        }
        Ok(())
    }
}

fn validate_permissions(value: &Value) -> Result<Vec<String>> {
    let Value::Array(a) = value else {
        return Err(Error::exception(
            "Invalid permissions format; expected an array of strings.",
            Exception::CODE_VALIDATION,
        ));
    };
    let mut out = Vec::with_capacity(a.len());
    for v in a.values() {
        match v {
            Value::Str(s) => out.push(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => {
                return Err(Error::exception("Invalid permission value; expected string.", Exception::CODE_VALIDATION));
            }
        }
    }
    Ok(out)
}

fn not_implemented() -> Error {
    Error::exception("Not Implemented", 0)
}

#[async_trait]
impl Source for Json {
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
        let mut report = Report::new();
        if !self.device.exists(&self.file_path).await.unwrap_or(false) {
            return Ok(report);
        }
        self.download_to_local().await?;
        let items = Items::from_file(&self.file_path, Decoder::PassThru).await?;
        report.insert(types::ROW.to_owned(), Value::Int(items.count().await?));
        Ok(report)
    }

    async fn export_group(
        &mut self,
        group: &str,
        batch_size: i64,
        resources: &[String],
        out: &mut Emitter<'_>,
    ) -> Result<()> {
        if group != group::DATABASES {
            return Err(not_implemented());
        }
        let result =
            if is_supported(&[types::ROW], resources) { self.export_rows(batch_size, out).await } else { Ok(()) };
        if let Err(e) = result {
            let code = e.code().or_internal();
            self.target.add_error(Exception::new(types::ROW, group::DATABASES, None, e.message(), code, Some(e)));
        }
        let _ = self.device.delete(&self.file_path, false).await;
        Ok(())
    }
}
