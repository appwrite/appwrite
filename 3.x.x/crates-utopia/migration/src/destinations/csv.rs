//! CSV destination. PHP `Utopia\Migration\Destinations\CSV`.

use std::collections::HashMap;
use std::fs::{self, OpenOptions};
use std::io::Write;
use std::path::PathBuf;

use serde_json::{json, Value};
use utopia_storage::{Device, Local};

use crate::destination::{Destination, DestinationCommon};
use crate::exception::Exception;
use crate::resource::{AnyResource, Resource, STATUS_SUCCESS, TYPE_ROW};
use crate::resource_selector::ResourceSelector;
use crate::target::{Target, TargetState};

pub struct CsvDestination {
    common: DestinationCommon,
    device_for_files: Local,
    resource_id: String,
    resource_child_id: Option<String>,
    directory: String,
    output_file: String,
    local: Local,
    allowed_columns: HashMap<String, bool>,
    delimiter: String,
    enclosure: String,
    include_headers: bool,
    headers_written: bool,
    skip_shutdown_transfer: bool,
}

impl CsvDestination {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        device_for_files: Local,
        resource_id: impl Into<String>,
        directory: impl Into<String>,
        filename: impl Into<String>,
        allowed_columns: Vec<String>,
        delimiter: impl Into<String>,
        enclosure: impl Into<String>,
        include_headers: bool,
    ) -> Self {
        let tmp = std::env::temp_dir().join(format!(
            "csv_export_{}",
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .map(|d| d.as_nanos())
                .unwrap_or(0)
        ));
        let _ = fs::create_dir_all(&tmp);
        let mut allowed = HashMap::new();
        for col in allowed_columns {
            allowed.insert(col, true);
        }
        Self {
            common: DestinationCommon::default(),
            device_for_files,
            resource_id: resource_id.into(),
            resource_child_id: None,
            directory: directory.into(),
            output_file: filename.into().replace(['/', '\\', '\0'], "_"),
            local: Local::new(tmp),
            allowed_columns: allowed,
            delimiter: delimiter.into(),
            enclosure: enclosure.into(),
            include_headers,
            headers_written: false,
            skip_shutdown_transfer: false,
        }
    }

    #[allow(clippy::too_many_arguments)]
    pub fn from_resource_ids(
        device_for_files: Local,
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        directory: impl Into<String>,
        filename: impl Into<String>,
        allowed_columns: Vec<String>,
        delimiter: impl Into<String>,
        enclosure: impl Into<String>,
        include_headers: bool,
    ) -> Self {
        let mut dest = Self::new(
            device_for_files,
            database_id,
            directory,
            filename,
            allowed_columns,
            delimiter,
            enclosure,
            include_headers,
        );
        dest.resource_child_id = Some(table_id.into());
        dest
    }

    #[must_use]
    pub fn resource_id(&self) -> &str {
        &self.resource_id
    }
    #[must_use]
    pub fn resource_child_id(&self) -> Option<&str> {
        self.resource_child_id.as_deref()
    }
    #[must_use]
    pub fn local_root(&self) -> PathBuf {
        self.local.get_root().to_path_buf()
    }
    pub fn set_skip_shutdown_transfer(&mut self, skip: bool) {
        self.skip_shutdown_transfer = skip;
    }

    fn resource_to_csv_data(&self, row: &crate::resources::database::Row) -> Vec<(String, String)> {
        let mut row_data = row.get_data().clone();
        let created = match row_data.remove("$createdAt") {
            Some(Value::String(s)) => s,
            Some(other) => other.as_str().unwrap_or("").to_owned(),
            None => String::new(),
        };
        let updated = match row_data.remove("$updatedAt") {
            Some(Value::String(s)) => s,
            Some(other) => other.as_str().unwrap_or("").to_owned(),
            None => String::new(),
        };
        let mut ordered: Vec<(String, Value)> = vec![
            ("$id".into(), Value::String(row.get_id().to_owned())),
            ("$permissions".into(), json!(row.get_permissions())),
            ("$createdAt".into(), Value::String(created)),
            ("$updatedAt".into(), Value::String(updated)),
        ];
        if self.allowed_columns.is_empty() {
            for (k, v) in row_data {
                ordered.push((k, v));
            }
        } else {
            for (k, v) in row_data {
                if self.allowed_columns.contains_key(&k) {
                    ordered.push((k, v));
                }
            }
        }
        ordered
            .into_iter()
            .map(|(k, v)| (k, convert_value_to_csv(&v)))
            .collect()
    }

    fn escape(&self, value: &str) -> String {
        let enc = &self.enclosure;
        if value.contains(self.delimiter.as_str()) || value.contains(enc) || value.contains('\n') {
            format!("{enc}{}{enc}", value.replace(enc, &format!("{enc}{enc}")))
        } else {
            value.to_owned()
        }
    }
}

impl Target for CsvDestination {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
    fn shutdown(&mut self) {
        if self.skip_shutdown_transfer {
            return;
        }
        let filename = format!("{}.csv", self.output_file);
        let source_path = self.local.get_path(&filename);
        let dest_path = self
            .device_for_files
            .get_path(&format!("{}/{}", self.directory, filename));
        if self.local.exists(&source_path) {
            if let Ok(bytes) = fs::read(&source_path) {
                let _ = self.device_for_files.write(&dest_path, &bytes, "text/csv");
            }
        }
    }
}

impl Destination for CsvDestination {
    fn name() -> &'static str {
        "CSV"
    }
    fn supported_resources() -> &'static [&'static str] {
        &[TYPE_ROW]
    }
    fn selector(&self) -> Option<&ResourceSelector> {
        self.common.selector.as_ref()
    }
    fn set_selector(&mut self, selector: Option<ResourceSelector>) {
        self.common.selector = selector;
    }
    fn import(&mut self, resources: Vec<AnyResource>, callback: &mut dyn FnMut(Vec<AnyResource>)) {
        let path = self
            .local
            .get_root()
            .join(format!("{}.csv", self.output_file));
        if let Some(parent) = path.parent() {
            let _ = fs::create_dir_all(parent);
        }
        let mut lines = String::new();
        let mut processed = Vec::new();
        for mut resource in resources {
            let AnyResource::Row(row) = &resource else {
                processed.push(resource);
                continue;
            };
            let data = self.resource_to_csv_data(row);
            if self.include_headers && !self.headers_written {
                lines.push_str(
                    &data
                        .iter()
                        .map(|(k, _)| self.escape(k))
                        .collect::<Vec<_>>()
                        .join(&self.delimiter),
                );
                lines.push('\n');
                self.headers_written = true;
            }
            lines.push_str(
                &data
                    .iter()
                    .map(|(_, v)| self.escape(v))
                    .collect::<Vec<_>>()
                    .join(&self.delimiter),
            );
            lines.push('\n');
            resource.set_status(STATUS_SUCCESS, "");
            if let Some(cache) = self.state().cache() {
                if let Ok(mut guard) = cache.lock() {
                    guard.update(&mut resource);
                }
            }
            processed.push(resource);
        }
        if !lines.is_empty() {
            if let Ok(mut f) = OpenOptions::new().create(true).append(true).open(&path) {
                let _ = f.write_all(lines.as_bytes());
            }
        }
        callback(processed);
    }
    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        Ok(HashMap::new())
    }
}

fn convert_value_to_csv(value: &Value) -> String {
    match value {
        Value::Null => "null".into(),
        Value::Bool(true) => "true".into(),
        Value::Bool(false) => "false".into(),
        Value::Number(n) => n.to_string(),
        Value::String(s) => s.clone(),
        Value::Array(a) if a.is_empty() => String::new(),
        Value::Array(a) => serde_json::to_string(a).unwrap_or_default(),
        Value::Object(o) => {
            if let Some(id) = o.get("$id").and_then(Value::as_str) {
                id.to_owned()
            } else {
                serde_json::to_string(o).unwrap_or_default()
            }
        }
    }
}
