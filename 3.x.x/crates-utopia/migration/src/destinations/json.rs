//! JSON file destination. PHP `Utopia\Migration\Destinations\JSON`.

use std::collections::HashMap;
use std::fs::{self, OpenOptions};
use std::io::Write;
use std::path::PathBuf;

use serde_json::{json, Map, Value};
use utopia_storage::{Device, Local};

use crate::destination::{Destination, DestinationCommon};
use crate::exception::Exception;
use crate::resource::{AnyResource, Resource, STATUS_ERROR, STATUS_SUCCESS, TYPE_ROW};
use crate::resource_selector::ResourceSelector;
use crate::target::{Target, TargetState};

pub struct JsonDestination {
    common: DestinationCommon,
    device_for_files: Local,
    resource_id: String,
    resource_child_id: Option<String>,
    directory: String,
    output_file: String,
    local: Local,
    allowed_columns: HashMap<String, bool>,
    json_started: bool,
    json_has_items: bool,
    skip_shutdown_transfer: bool,
}

impl JsonDestination {
    pub fn new(
        device_for_files: Local,
        resource_id: impl Into<String>,
        directory: impl Into<String>,
        filename: impl Into<String>,
        allowed_columns: Vec<String>,
    ) -> Self {
        let output_file = sanitize_filename(&filename.into());
        let tmp = std::env::temp_dir().join(format!("json_export_{}", uniq()));
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
            output_file,
            local: Local::new(tmp),
            allowed_columns: allowed,
            json_started: false,
            json_has_items: false,
            skip_shutdown_transfer: false,
        }
    }

    pub fn from_resource_ids(
        device_for_files: Local,
        database_id: impl Into<String>,
        table_id: impl Into<String>,
        directory: impl Into<String>,
        filename: impl Into<String>,
        allowed_columns: Vec<String>,
    ) -> Self {
        let mut dest = Self::new(
            device_for_files,
            database_id,
            directory,
            filename,
            allowed_columns,
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

    fn log_path(&self) -> PathBuf {
        self.local
            .get_root()
            .join(format!("{}.json", self.output_file))
    }

    fn resource_to_json_data(&self, row: &crate::resources::database::Row) -> Map<String, Value> {
        let mut row_data = row.get_data().clone();
        let created = row_data.remove("$createdAt").unwrap_or(json!(""));
        let updated = row_data.remove("$updatedAt").unwrap_or(json!(""));
        let mut data = Map::new();
        data.insert("$id".into(), json!(row.get_id()));
        data.insert("$permissions".into(), json!(row.get_permissions()));
        data.insert("$createdAt".into(), created);
        data.insert("$updatedAt".into(), updated);
        if self.allowed_columns.is_empty() {
            for (k, v) in row_data {
                data.insert(k, v);
            }
        } else {
            for (k, v) in row_data {
                if self.allowed_columns.contains_key(&k) {
                    data.insert(k, v);
                }
            }
        }
        data
    }
}

impl Target for JsonDestination {
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
        let filename = format!("{}.json", self.output_file);
        let source_path = self.local.get_path(&filename);
        let dest_path = self
            .device_for_files
            .get_path(&format!("{}/{}", self.directory, filename));
        if !self.local.exists(&source_path) {
            self.add_error(Exception::message_only(format!(
                "No data to export for resource: {}",
                self.resource_id
            )));
            return;
        }
        {
            let mut f = OpenOptions::new()
                .create(true)
                .append(true)
                .open(&source_path)
                .ok();
            if let Some(f) = f.as_mut() {
                if !self.json_started {
                    let _ = f.write_all(b"[");
                    self.json_started = true;
                }
                let _ = f.write_all(b"]");
            }
        }
        if let Ok(bytes) = fs::read(&source_path) {
            let _ = self
                .device_for_files
                .write(&dest_path, &bytes, "application/json");
        }
    }
}

impl Destination for JsonDestination {
    fn name() -> &'static str {
        "JSON"
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
        let log = self.log_path();
        if let Some(parent) = log.parent() {
            let _ = fs::create_dir_all(parent);
        }
        let mut out = String::new();
        if !self.json_started {
            out.push('[');
            self.json_started = true;
        }
        let mut processed = Vec::new();
        for mut resource in resources {
            let AnyResource::Row(row) = &resource else {
                processed.push(resource);
                continue;
            };
            let data = self.resource_to_json_data(row);
            match serde_json::to_string(&data) {
                Ok(json) => {
                    if self.json_has_items {
                        out.push(',');
                    }
                    out.push_str(&json);
                    self.json_has_items = true;
                    resource.set_status(STATUS_SUCCESS, "");
                }
                Err(e) => {
                    resource.set_status(STATUS_ERROR, e.to_string());
                    self.add_error(Exception::new(
                        resource.get_name(),
                        resource.get_group(),
                        Some(resource.get_id().to_owned()),
                        e.to_string(),
                        Exception::CODE_INTERNAL,
                    ));
                }
            }
            if let Some(cache) = self.state().cache() {
                if let Ok(mut guard) = cache.lock() {
                    guard.update(&mut resource);
                }
            }
            processed.push(resource);
        }
        if !out.is_empty() {
            if let Ok(mut f) = OpenOptions::new().create(true).append(true).open(&log) {
                let _ = f.write_all(out.as_bytes());
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

fn sanitize_filename(name: &str) -> String {
    name.replace(['/', '\\', '\0'], "_")
}

fn uniq() -> u128 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or(0)
}
