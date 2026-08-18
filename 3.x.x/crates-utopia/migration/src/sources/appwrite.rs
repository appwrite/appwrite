//! Appwrite source. PHP `Utopia\Migration\Sources\Appwrite`.

use std::collections::HashMap;

use serde_json::{Map, Value};

use crate::exception::Exception;
use crate::resource::{
    AnyResource, Resource, ALL_RESOURCES, TYPE_DATABASE_DOCUMENTSDB, TYPE_DATABASE_VECTORSDB,
};
use crate::resources::database::{Attribute, Collection, Column, Table};
use crate::source::{Source, SourceCommon};
use crate::target::{Target, TargetState};

pub struct Appwrite {
    common: SourceCommon,
    pub project_id: String,
    pub endpoint: String,
    pub key: String,
    pub source: String,
}

impl Appwrite {
    pub const SOURCE_API: &'static str = "api";
    pub const SOURCE_DATABASE: &'static str = "database";

    pub fn new(
        project_id: impl Into<String>,
        endpoint: impl Into<String>,
        key: impl Into<String>,
        source: impl Into<String>,
    ) -> Self {
        let project_id = project_id.into();
        let endpoint = endpoint.into();
        let key = key.into();
        let mut common = SourceCommon::default();
        common.state.endpoint.clone_from(&endpoint);
        common
            .state
            .headers
            .insert("x-appwrite-project".into(), project_id.clone());
        common
            .state
            .headers
            .insert("x-appwrite-key".into(), key.clone());
        Self {
            common,
            project_id,
            endpoint,
            key,
            source: source.into(),
        }
    }

    /// PHP `Appwrite::getColumn(Table $table, mixed $column)`.
    ///
    /// `column` is a JSON object (PHP also accepts a Utopia `Document`).
    pub fn get_column(table: &Table, column: &Value) -> Result<Column, Exception> {
        let payload = column
            .as_object()
            .ok_or_else(|| Exception::message_only("Unsupported column type: ".to_owned()))?;
        let resolved = Column::resolve(payload);
        let type_ = resolved
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let format = resolved
            .get("format")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let size = resolved.get("size").and_then(Value::as_i64).unwrap_or(0);
        let key = payload
            .get("key")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let required = payload
            .get("required")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        let default = payload.get("default").cloned().unwrap_or(Value::Null);
        let array = payload
            .get("array")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        let created_at = payload
            .get("$createdAt")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let updated_at = payload
            .get("$updatedAt")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();

        let mut col = match type_.as_str() {
            Column::TYPE_STRING => match format.as_str() {
                Column::TYPE_EMAIL => Column::email(key, table.clone(), size),
                Column::TYPE_ENUM => {
                    let elements = payload
                        .get("elements")
                        .and_then(Value::as_array)
                        .map(|a| {
                            a.iter()
                                .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                                .collect()
                        })
                        .unwrap_or_default();
                    Column::enum_col(key, table.clone(), elements, size)
                }
                Column::TYPE_URL => Column::url(key, table.clone(), size),
                Column::TYPE_IP => Column::ip(key, table.clone(), size),
                _ => Column::text(key, table.clone(), size),
            },
            Column::TYPE_BOOLEAN => Column::boolean(key, table.clone()),
            Column::TYPE_INTEGER => Column::integer(key, table.clone()),
            Column::TYPE_BIG_INT => Column::big_int(key, table.clone()),
            Column::TYPE_FLOAT => Column::decimal(key, table.clone()),
            Column::TYPE_RELATIONSHIP => Column::relationship(key, table.clone()),
            Column::TYPE_DATETIME => Column::datetime(key, table.clone()),
            Column::TYPE_POINT => Column::point(key, table.clone()),
            Column::TYPE_LINE => Column::line(key, table.clone()),
            Column::TYPE_POLYGON => Column::polygon(key, table.clone()),
            Column::TYPE_OBJECT => Column::object(key, table.clone()),
            Column::TYPE_VECTOR => {
                let raw_size = payload.get("size").and_then(Value::as_i64).unwrap_or(size);
                Column::vector(key, table.clone(), raw_size)
            }
            Column::TYPE_VARCHAR => {
                let size = if size > 0 {
                    size
                } else {
                    Column::DEFAULT_VARCHAR_SIZE
                };
                Column::varchar(key, table.clone(), size)
            }
            Column::TYPE_TEXT => Column::regular_text(key, table.clone(), size),
            Column::TYPE_MEDIUMTEXT => Column::medium_text(key, table.clone(), size),
            Column::TYPE_LONGTEXT => Column::long_text(key, table.clone(), size),
            other => {
                return Err(Exception::message_only(format!(
                    "Unsupported column type: {other}"
                )));
            }
        };
        col.set_required(required);
        col.set_default(default);
        col.set_array(array);
        col.set_created_at(created_at);
        col.set_updated_at(updated_at);
        if let Some(signed) = payload.get("signed").and_then(Value::as_bool) {
            col.set_signed(signed);
        }
        Ok(col)
    }

    /// PHP `Appwrite::getAttribute(Collection $collection, mixed $attribute)`.
    pub fn get_attribute(
        collection: &Collection,
        attribute: &Value,
    ) -> Result<Attribute, Exception> {
        Ok(Self::get_column(collection.as_table(), attribute)?.get_attribute())
    }

    /// PHP `Appwrite::getEntity`.
    #[must_use]
    pub fn get_entity(database_type: &str, entity: &Map<String, Value>) -> AnyResource {
        match database_type {
            TYPE_DATABASE_DOCUMENTSDB | TYPE_DATABASE_VECTORSDB => {
                Collection::from_array(entity).into()
            }
            _ => Table::from_array(entity).into(),
        }
    }

    /// PHP `Reader\API` - unwrap an SDK `ColumnList` payload to a plain column array.
    #[must_use]
    pub fn list_columns_from_sdk_list(payload: &Value) -> Vec<Value> {
        payload
            .get("columns")
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default()
    }
}

impl Target for Appwrite {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for Appwrite {
    fn name() -> &'static str {
        "Appwrite"
    }
    fn supported_resources() -> &'static [&'static str] {
        ALL_RESOURCES
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
        Ok(HashMap::new())
    }
    fn export_group_auth(&mut self, _: usize, _: &[String], _: &mut dyn FnMut(Vec<AnyResource>)) {}
    fn export_group_databases(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
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
}
