//! CSV source. PHP `Utopia\Migration\Sources\CSV`.

use std::collections::HashMap;
use std::path::PathBuf;

use serde_json::{Map, Value};
use utopia_storage::{Device, Local};

use crate::exception::Exception;
use crate::resource::{AnyResource, TYPE_DOCUMENT, TYPE_ROW};
use crate::resources::database::{Database, Row, Table};
use crate::source::{Source, SourceCommon};
use crate::target::{Target, TargetState};
use crate::transfer::GROUP_DATABASES;

pub struct CsvSource {
    common: SourceCommon,
    file_path: PathBuf,
    resource_id: String,
    resource_child_id: Option<String>,
    device: Local,
}

impl CsvSource {
    pub fn new(
        resource_id: impl Into<String>,
        file_path: impl Into<PathBuf>,
        device: Local,
        _db_for_project: Option<()>,
        _get_databases_db: Option<()>,
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
        get_databases_db: Option<()>,
    ) -> Self {
        let mut source = Self::new(
            database_id,
            file_path,
            device,
            db_for_project,
            get_databases_db,
        );
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

    /// PHP `CSV::delimiter($stream)` - score up to five sample lines.
    #[must_use]
    pub fn detect_delimiter(sample: &str) -> char {
        let delimiters = [',', ';', '\t', '|'];
        let mut sample_lines = Vec::new();
        for line in sample.lines() {
            let line = line.trim();
            if line.is_empty() {
                continue;
            }
            sample_lines.push(line.to_owned());
            if sample_lines.len() >= 5 {
                break;
            }
        }
        if sample_lines.is_empty() {
            return ',';
        }
        let mut best = ',';
        let mut best_score = 0.0_f64;
        for delimiter in delimiters {
            let mut column_counts = Vec::new();
            let mut total_fields = 0usize;
            let mut usable_fields = 0usize;
            for line in &sample_lines {
                let fields = if line.contains(delimiter) {
                    split_csv(line, delimiter)
                } else {
                    vec![line.clone()]
                };
                let field_count = fields.len();
                column_counts.push(field_count);
                total_fields += field_count;
                for field in &fields {
                    if field.trim().len() > 1 {
                        usable_fields += 1;
                    }
                }
            }
            let sample_count = column_counts.len() as f64;
            let avg_columns = total_fields as f64 / sample_count;
            let score = if avg_columns <= 1.0 {
                0.0
            } else {
                let consistency = if column_counts.len() <= 1 {
                    1.0
                } else {
                    let variance = column_counts
                        .iter()
                        .map(|c| {
                            let d = *c as f64 - avg_columns;
                            d * d
                        })
                        .sum::<f64>();
                    let stddev = (variance / sample_count).sqrt();
                    let cv = stddev / avg_columns;
                    1.0 / (1.0 + cv * 2.0)
                };
                let quality = if total_fields > 0 {
                    usable_fields as f64 / total_fields as f64
                } else {
                    0.0
                };
                consistency * quality
            };
            if score > best_score {
                best_score = score;
                best = delimiter;
            }
        }
        if best_score > 0.0 {
            best
        } else {
            ','
        }
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

    fn parse_rows(&self) -> Result<Vec<Map<String, Value>>, Exception> {
        if !self.device.exists(&self.file_path) {
            return Ok(Vec::new());
        }
        let bytes = self
            .device
            .read(&self.file_path, 0, None)
            .map_err(|e| Exception::message_only(e.to_string()))?;
        let text = String::from_utf8_lossy(&bytes);
        let delim = Self::detect_delimiter(&text);
        let mut lines = text.lines().filter(|l| !l.trim().is_empty());
        let Some(header_line) = lines.next() else {
            return Ok(Vec::new());
        };
        let headers: Vec<String> = split_csv(header_line, delim);
        let mut rows = Vec::new();
        for line in lines {
            let cols = split_csv(line, delim);
            let mut map = Map::new();
            for (i, h) in headers.iter().enumerate() {
                map.insert(h.clone(), json_cell(cols.get(i).map_or("", String::as_str)));
            }
            rows.push(map);
        }
        Ok(rows)
    }
}

fn json_cell(s: &str) -> Value {
    if s.is_empty() {
        return Value::Null;
    }
    if let Ok(n) = s.parse::<i64>() {
        return Value::Number(n.into());
    }
    if let Ok(n) = s.parse::<f64>() {
        if let Some(num) = serde_json::Number::from_f64(n) {
            return Value::Number(num);
        }
    }
    Value::String(s.to_owned())
}

fn split_csv(line: &str, delim: char) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    let mut in_quotes = false;
    let chars: Vec<char> = line.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if c == '"' {
            if in_quotes && chars.get(i + 1) == Some(&'"') {
                cur.push('"');
                i += 2;
                continue;
            }
            in_quotes = !in_quotes;
            i += 1;
            continue;
        }
        if c == delim && !in_quotes {
            out.push(std::mem::take(&mut cur));
            i += 1;
            continue;
        }
        cur.push(c);
        i += 1;
    }
    out.push(cur);
    out
}

impl Target for CsvSource {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for CsvSource {
    fn name() -> &'static str {
        "CSV"
    }
    fn supported_resources() -> &'static [&'static str] {
        &[TYPE_ROW, TYPE_DOCUMENT]
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
        report.insert(TYPE_ROW.to_owned(), self.parse_rows()?.len() as i64);
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
        match self.parse_rows() {
            Ok(items) => {
                let mut buffer = Vec::new();
                for mut item in items {
                    let row_id = item
                        .remove("$id")
                        .and_then(|v| match v {
                            Value::String(s) => Some(s),
                            other => other.as_str().map(str::to_owned),
                        })
                        .unwrap_or_else(|| "unique()".to_owned());
                    buffer.push(AnyResource::Row(Row::new(row_id, table.clone(), item)));
                    if buffer.len() >= batch_size.max(1) {
                        emit(std::mem::take(&mut buffer));
                    }
                }
                if !buffer.is_empty() {
                    emit(buffer);
                }
            }
            Err(e) => self.add_error(Exception::new(
                TYPE_ROW,
                GROUP_DATABASES,
                None,
                e.to_string(),
                Exception::CODE_INTERNAL,
            )),
        }
    }
}
