//! JSON file source. PHP `Utopia\Migration\Sources\JSON`.

use std::collections::HashMap;
use std::path::{Path, PathBuf};

use serde_json::{Map, Value};
use utopia_storage::{Device, Local};

use crate::exception::Exception;
use crate::resource::{AnyResource, Resource, TYPE_ROW};
use crate::resources::database::{Database, Row, Table};
use crate::source::{Source, SourceCommon};
use crate::target::{Target, TargetState};
use crate::transfer::GROUP_DATABASES;

pub struct JsonSource {
    common: SourceCommon,
    file_path: PathBuf,
    resource_id: String,
    resource_child_id: Option<String>,
    device: Local,
}

impl JsonSource {
    pub fn new(
        resource_id: impl Into<String>,
        file_path: impl Into<PathBuf>,
        device: Local,
        _db_for_project: Option<()>,
    ) -> Self {
        Self {
            common: SourceCommon::default(),
            file_path: file_path.into(),
            resource_id: resource_id.into(),
            resource_child_id: None,
            device,
        }
    }

    pub fn from_resource_ids(
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        file_path: impl Into<PathBuf>,
        device: Local,
        db_for_project: Option<()>,
    ) -> Self {
        let mut source = Self::new(database_id, file_path, device, db_for_project);
        source.resource_child_id = Some(table_id.into());
        source
    }

    #[must_use]
    pub fn resource_id(&self) -> &str {
        &self.resource_id
    }
    #[must_use]
    pub fn resource_child_id(&self) -> Option<&str> {
        self.resource_child_id.as_deref()
    }

    fn get_resource_ids(&self) -> (String, String) {
        if let Some(child) = &self.resource_child_id {
            return (self.resource_id.clone(), child.clone());
        }
        let mut parts = self.resource_id.splitn(2, ':');
        (
            parts.next().unwrap_or("").to_owned(),
            parts.next().unwrap_or("").to_owned(),
        )
    }

    fn read_items(&self) -> Result<Vec<Map<String, Value>>, Exception> {
        if !self.device.exists(&self.file_path) {
            return Ok(Vec::new());
        }
        let bytes = self
            .device
            .read(&self.file_path, 0, None)
            .map_err(|e| Exception::message_only(e.to_string()))?;
        let value: Value =
            serde_json::from_slice(&bytes).map_err(|e| Exception::message_only(e.to_string()))?;
        match value {
            Value::Array(items) => items
                .into_iter()
                .enumerate()
                .map(|(index, item)| match item {
                    Value::Object(map) => Ok(map),
                    _ => Err(Exception::new(
                        TYPE_ROW,
                        GROUP_DATABASES,
                        None,
                        format!("JSON item at index {index} is not an object."),
                        Exception::CODE_VALIDATION,
                    )),
                })
                .collect(),
            _ => Err(Exception::message_only("JSON root must be an array")),
        }
    }
}

impl Target for JsonSource {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for JsonSource {
    fn name() -> &'static str {
        "JSON"
    }
    fn supported_resources() -> &'static [&'static str] {
        &[TYPE_ROW]
    }
    fn previous_report(&self) -> &HashMap<String, i64> {
        &self.common.previous_report
    }
    fn previous_report_mut(&mut self) -> &mut HashMap<String, i64> {
        &mut self.common.previous_report
    }

    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        let mut report = HashMap::new();
        if self.device.exists(&self.file_path) {
            report.insert(TYPE_ROW.to_owned(), self.read_items()?.len() as i64);
        }
        Ok(report)
    }

    fn export_group_auth(&mut self, _: usize, _: &[String], _: &mut dyn FnMut(Vec<AnyResource>)) {}
    fn export_group_storage(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_functions(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_messaging(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_sites(&mut self, _: usize, _: &[String], _: &mut dyn FnMut(Vec<AnyResource>)) {}
    fn export_group_integrations(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_backups(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_projects(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_domains(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }

    fn export_group_databases(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        if !crate::resource::is_supported(
            &[TYPE_ROW],
            &resources.iter().map(String::as_str).collect::<Vec<_>>(),
        ) {
            return;
        }
        let (database_id, table_id) = self.get_resource_ids();
        let database = Database::new(database_id, "");
        let table = Table::new(database, "", table_id);
        match self.read_items() {
            Ok(items) => {
                let mut buffer = Vec::new();
                for mut item in items {
                    let row_id = item
                        .remove("$id")
                        .and_then(|v| v.as_str().map(str::to_owned))
                        .unwrap_or_else(|| "unique()".to_owned());
                    let permissions = match item.remove("$permissions") {
                        Some(Value::Array(arr)) => arr
                            .into_iter()
                            .filter_map(|v| v.as_str().map(str::to_owned))
                            .collect(),
                        _ => Vec::new(),
                    };
                    let mut row = Row::new(row_id, table.clone(), item);
                    row.set_permissions(permissions);
                    buffer.push(AnyResource::Row(row));
                    if buffer.len() >= batch_size.max(1) {
                        emit(std::mem::take(&mut buffer));
                    }
                }
                if !buffer.is_empty() {
                    emit(buffer);
                }
            }
            Err(e) => self.add_error(e),
        }
        let _ = Path::new(&self.file_path);
    }
}
