//! Database resources. PHP `Utopia\Migration\Resources\Database\*`.

use serde_json::{json, Map, Value};
use utopia_database::constants::LENGTH_KEY;

use crate::resource::{
    Resource, ResourceBase, TYPE_ATTRIBUTE, TYPE_COLLECTION, TYPE_COLUMN, TYPE_DATABASE,
    TYPE_DATABASE_DOCUMENTSDB, TYPE_DATABASE_VECTORSDB, TYPE_DOCUMENT, TYPE_INDEX, TYPE_ROW,
    TYPE_TABLE,
};
use crate::transfer;

fn map_str(m: &Map<String, Value>, key: &str) -> String {
    match m.get(key) {
        Some(Value::String(s)) => s.clone(),
        Some(Value::Null) | None => String::new(),
        Some(other) => other
            .as_str()
            .map_or_else(|| other.to_string(), ToOwned::to_owned),
    }
}

fn map_bool(m: &Map<String, Value>, key: &str, default: bool) -> bool {
    m.get(key).and_then(Value::as_bool).unwrap_or(default)
}

fn map_i64(m: &Map<String, Value>, key: &str) -> Option<i64> {
    match m.get(key) {
        Some(Value::Number(n)) => n.as_i64(),
        Some(Value::String(s)) => s.parse().ok(),
        _ => None,
    }
}

fn map_string_vec(m: &Map<String, Value>, key: &str) -> Vec<String> {
    m.get(key)
        .and_then(Value::as_array)
        .map(|a| {
            a.iter()
                .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                .collect()
        })
        .unwrap_or_default()
}

fn map_object<'a>(m: &'a Map<String, Value>, key: &str) -> Option<&'a Map<String, Value>> {
    m.get(key).and_then(Value::as_object)
}

#[derive(Debug, Clone)]
pub struct Database {
    base: ResourceBase,
    name: String,
    enabled: bool,
    type_: String,
    database: Option<String>,
    database_status: Option<String>,
}

impl Database {
    pub fn new(id: impl Into<String>, name: impl Into<String>) -> Self {
        let id = id.into();
        Self {
            base: ResourceBase::new(id),
            name: name.into(),
            enabled: true,
            type_: String::new(),
            database: Some(String::new()),
            database_status: None,
        }
    }

    /// PHP `Database::fromArray`.
    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let mut db = Self::new(map_str(array, "id"), map_str(array, "name"));
        db.base.created_at = map_str(array, "createdAt");
        db.base.updated_at = map_str(array, "updatedAt");
        db.enabled = map_bool(array, "enabled", true);
        db.base.original_id = map_str(array, "originalId");
        db.type_ = if array
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .is_empty()
        {
            "legacy".into()
        } else {
            map_str(array, "type")
        };
        db.database = match array.get("database") {
            None | Some(Value::Null) => None,
            Some(Value::String(s)) => Some(s.clone()),
            Some(other) => Some(other.to_string()),
        };
        db.database_status = array.get("status").and_then(|v| match v {
            Value::Null => None,
            Value::String(s) => Some(s.clone()),
            other => other.as_str().map(ToOwned::to_owned),
        });
        db
    }

    #[must_use]
    pub fn get_database_name(&self) -> &str {
        &self.name
    }
    #[must_use]
    pub fn get_enabled(&self) -> bool {
        self.enabled
    }
    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.type_
    }
    pub fn set_type(&mut self, type_: impl Into<String>) {
        self.type_ = type_.into();
    }
    #[must_use]
    pub fn get_database(&self) -> Option<&str> {
        self.database.as_deref()
    }
    #[must_use]
    pub fn get_database_status(&self) -> Option<&str> {
        self.database_status.as_deref()
    }
    pub fn set_database(&mut self, dsn: Option<String>) {
        self.database = dsn;
    }
    pub fn set_database_status(&mut self, status: Option<String>) {
        self.database_status = status;
    }
}

impl Resource for Database {
    fn get_name(&self) -> &'static str {
        match self.type_.as_str() {
            TYPE_DATABASE_DOCUMENTSDB => TYPE_DATABASE_DOCUMENTSDB,
            TYPE_DATABASE_VECTORSDB => TYPE_DATABASE_VECTORSDB,
            _ => TYPE_DATABASE,
        }
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "id": self.get_id(),
            "name": self.name,
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
            "enabled": self.enabled,
            "type": self.type_,
            "database": self.database,
            "status": self.database_status,
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

/// PHP `DocumentsDB`.
pub type DocumentsDB = Database;

impl Database {
    /// PHP `DocumentsDB::fromArray`.
    #[must_use]
    pub fn documents_db_from_array(array: &Map<String, Value>) -> Self {
        let mut db = Self::from_array(array);
        if db.type_.is_empty() || db.type_ == "legacy" {
            TYPE_DATABASE_DOCUMENTSDB.clone_into(&mut db.type_);
        }
        db
    }

    /// PHP `VectorsDB::fromArray`.
    #[must_use]
    pub fn vectors_db_from_array(array: &Map<String, Value>) -> Self {
        let mut db = Self::from_array(array);
        if db.type_.is_empty() || db.type_ == "legacy" {
            TYPE_DATABASE_VECTORSDB.clone_into(&mut db.type_);
        }
        db
    }
}

/// PHP `VectorsDB`.
pub type VectorsDB = Database;

#[derive(Debug, Clone)]
pub struct Table {
    base: ResourceBase,
    database: Database,
    name: String,
    row_security: bool,
    enabled: bool,
}

impl Table {
    pub fn new(database: Database, name: impl Into<String>, id: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            database,
            name: name.into(),
            row_security: false,
            enabled: true,
        }
    }

    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let database = map_object(array, "database")
            .map_or_else(|| Database::new("", ""), Database::from_array);
        let row_security = array
            .get("rowSecurity")
            .and_then(Value::as_bool)
            .or_else(|| array.get("documentSecurity").and_then(Value::as_bool))
            .unwrap_or(false);
        let mut table = Self::new(database, map_str(array, "name"), map_str(array, "id"));
        table.row_security = row_security;
        table.base.permissions = map_string_vec(array, "permissions");
        table.base.created_at = map_str(array, "createdAt");
        table.base.updated_at = map_str(array, "updatedAt");
        table.enabled = map_bool(array, "enabled", true);
        table
    }

    #[must_use]
    pub fn get_database(&self) -> &Database {
        &self.database
    }
    #[must_use]
    pub fn get_table_name(&self) -> &str {
        &self.name
    }
    #[must_use]
    pub fn get_row_security(&self) -> bool {
        self.row_security
    }
    #[must_use]
    pub fn get_enabled(&self) -> bool {
        self.enabled
    }
    pub fn set_row_security(&mut self, value: bool) {
        self.row_security = value;
    }
}

impl Resource for Table {
    fn get_name(&self) -> &'static str {
        TYPE_TABLE
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "database": self.database.json_serialize(),
            "id": self.get_id(),
            "name": self.name,
            "rowSecurity": self.row_security,
            "permissions": self.get_permissions(),
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
            "enabled": self.enabled,
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

/// PHP `Collection extends Table` with optional vector `dimension`.
#[derive(Debug, Clone)]
pub struct Collection {
    table: Table,
    dimension: Option<i64>,
}

impl Collection {
    pub fn new(database: Database, name: impl Into<String>, id: impl Into<String>) -> Self {
        Self {
            table: Table::new(database, name, id),
            dimension: None,
        }
    }

    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let database = match map_object(array, "database") {
            Some(d) if d.get("type").and_then(Value::as_str) == Some(TYPE_DATABASE_DOCUMENTSDB) => {
                Database::documents_db_from_array(d)
            }
            Some(d) if d.get("type").and_then(Value::as_str) == Some(TYPE_DATABASE_VECTORSDB) => {
                Database::vectors_db_from_array(d)
            }
            Some(d) => Database::from_array(d),
            None => Database::new("", ""),
        };
        let row_security = array
            .get("rowSecurity")
            .and_then(Value::as_bool)
            .or_else(|| array.get("documentSecurity").and_then(Value::as_bool))
            .unwrap_or(false);
        let mut table = Table::new(database, map_str(array, "name"), map_str(array, "id"));
        table.row_security = row_security;
        table.base.permissions = map_string_vec(array, "permissions");
        table.base.created_at = map_str(array, "createdAt");
        table.base.updated_at = map_str(array, "updatedAt");
        table.enabled = map_bool(array, "enabled", true);
        Self {
            table,
            dimension: map_i64(array, "dimension"),
        }
    }

    #[must_use]
    pub fn as_table(&self) -> &Table {
        &self.table
    }
    #[must_use]
    pub fn as_table_mut(&mut self) -> &mut Table {
        &mut self.table
    }
    #[must_use]
    pub fn get_database(&self) -> &Database {
        self.table.get_database()
    }
    #[must_use]
    pub fn get_table_name(&self) -> &str {
        self.table.get_table_name()
    }
    #[must_use]
    pub fn get_row_security(&self) -> bool {
        self.table.get_row_security()
    }
    #[must_use]
    pub fn get_dimension(&self) -> Option<i64> {
        self.dimension
    }
    pub fn set_dimension(&mut self, dimension: Option<i64>) {
        self.dimension = dimension;
    }
}

impl Resource for Collection {
    fn get_name(&self) -> &'static str {
        TYPE_COLLECTION
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        self.table.base()
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        self.table.base_mut()
    }
    fn json_serialize(&self) -> Map<String, Value> {
        let mut data = self.table.json_serialize();
        if let Some(dimension) = self.dimension {
            data.insert("dimension".into(), json!(dimension));
        }
        data
    }
}

#[derive(Debug, Clone)]
pub struct Row {
    base: ResourceBase,
    table: Table,
    data: Map<String, Value>,
}

impl Row {
    /// PHP `__construct(string $id, Table $table, array $data = [], array $permissions = [])`.
    pub fn new(id: impl Into<String>, table: Table, data: Map<String, Value>) -> Self {
        Self {
            base: ResourceBase::new(id),
            table,
            data,
        }
    }

    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let table = map_object(array, "table")
            .or_else(|| map_object(array, "collection"))
            .map_or_else(
                || Table::new(Database::new("", ""), "", ""),
                Table::from_array,
            );
        let data = map_object(array, "data").cloned().unwrap_or_default();
        let mut row = Self::new(map_str(array, "id"), table, data);
        row.base.permissions = map_string_vec(array, "permissions");
        row
    }

    #[must_use]
    pub fn get_table(&self) -> &Table {
        &self.table
    }
    #[must_use]
    pub fn get_data(&self) -> &Map<String, Value> {
        &self.data
    }
    pub fn get_data_mut(&mut self) -> &mut Map<String, Value> {
        &mut self.data
    }
}

impl Resource for Row {
    fn get_name(&self) -> &'static str {
        TYPE_ROW
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "id": self.get_id(),
            "table": self.table.json_serialize(),
            "data": self.data,
            "permissions": self.get_permissions(),
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

/// PHP `Document extends Row` with `getName() = document`.
#[derive(Debug, Clone)]
pub struct Document {
    inner: Row,
}

impl Document {
    pub fn new(id: impl Into<String>, collection: Collection, data: Map<String, Value>) -> Self {
        Self {
            inner: Row::new(id, collection.table, data),
        }
    }

    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let collection = map_object(array, "table")
            .or_else(|| map_object(array, "collection"))
            .map_or_else(
                || Collection::new(Database::new("", ""), "", ""),
                Collection::from_array,
            );
        let data = map_object(array, "data").cloned().unwrap_or_default();
        let mut doc = Self::new(map_str(array, "id"), collection, data);
        doc.inner.base.permissions = map_string_vec(array, "permissions");
        doc
    }

    #[must_use]
    pub fn get_table(&self) -> &Table {
        self.inner.get_table()
    }
    #[must_use]
    pub fn get_data(&self) -> &Map<String, Value> {
        self.inner.get_data()
    }
}

impl Resource for Document {
    fn get_name(&self) -> &'static str {
        TYPE_DOCUMENT
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        self.inner.base()
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        self.inner.base_mut()
    }
    fn json_serialize(&self) -> Map<String, Value> {
        self.inner.json_serialize()
    }
}

#[derive(Debug, Clone)]
pub struct Index {
    base: ResourceBase,
    key: String,
    table: Table,
    type_: String,
    columns: Vec<String>,
    lengths: Vec<i64>,
    orders: Vec<String>,
}

impl Index {
    pub const TYPE_UNIQUE: &'static str = "unique";
    pub const TYPE_FULLTEXT: &'static str = "fulltext";
    pub const TYPE_KEY: &'static str = "key";
    pub const TYPE_SPATIAL: &'static str = "spatial";

    #[allow(clippy::too_many_arguments)]
    pub fn new(
        id: impl Into<String>,
        key: impl Into<String>,
        table: Table,
        type_: impl Into<String>,
        columns: Vec<String>,
        lengths: Vec<i64>,
        orders: Vec<String>,
    ) -> Self {
        Self {
            base: ResourceBase::new(id),
            key: key.into(),
            table,
            type_: type_.into(),
            columns,
            lengths,
            orders,
        }
    }

    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let table = map_object(array, "table")
            .or_else(|| map_object(array, "collection"))
            .map_or_else(
                || Table::new(Database::new("", ""), "", ""),
                Table::from_array,
            );
        let columns = if array.get("columns").is_some() {
            map_string_vec(array, "columns")
        } else {
            map_string_vec(array, "attributes")
        };
        let lengths = array
            .get("lengths")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .map(|v| v.as_i64().unwrap_or(0))
                    .collect::<Vec<_>>()
            })
            .unwrap_or_default();
        let mut index = Self::new(
            map_str(array, "id"),
            map_str(array, "key"),
            table,
            map_str(array, "type"),
            columns,
            lengths,
            map_string_vec(array, "orders"),
        );
        index.base.created_at = map_str(array, "createdAt");
        index.base.updated_at = map_str(array, "updatedAt");
        index
    }

    #[must_use]
    pub fn get_table(&self) -> &Table {
        &self.table
    }
    #[must_use]
    pub fn get_key(&self) -> &str {
        &self.key
    }
    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.type_
    }
    #[must_use]
    pub fn get_columns(&self) -> &[String] {
        &self.columns
    }
    #[must_use]
    pub fn get_lengths(&self) -> &[i64] {
        &self.lengths
    }
    #[must_use]
    pub fn get_orders(&self) -> &[String] {
        &self.orders
    }
}

impl Resource for Index {
    fn get_name(&self) -> &'static str {
        TYPE_INDEX
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "id": self.get_id(),
            "key": self.key,
            "table": self.table.json_serialize(),
            "type": self.type_,
            "columns": self.columns,
            "lengths": self.lengths,
            "orders": self.orders,
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

/// PHP column subclasses (`Text`, `Email`, `Varchar`, …).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ColumnKind {
    String,
    Varchar,
    RegularText,
    MediumText,
    LongText,
    Email,
    Url,
    Ip,
    Enum,
    Integer,
    BigInt,
    Decimal,
    Boolean,
    DateTime,
    Point,
    Line,
    Polygon,
    Object,
    Relationship,
    Vector,
}

impl ColumnKind {
    #[must_use]
    pub fn as_type(self) -> &'static str {
        match self {
            Self::String => Column::TYPE_STRING,
            Self::Varchar => Column::TYPE_VARCHAR,
            Self::RegularText => Column::TYPE_TEXT,
            Self::MediumText => Column::TYPE_MEDIUMTEXT,
            Self::LongText => Column::TYPE_LONGTEXT,
            Self::Email => Column::TYPE_EMAIL,
            Self::Url => Column::TYPE_URL,
            Self::Ip => Column::TYPE_IP,
            Self::Enum => Column::TYPE_ENUM,
            Self::Integer => Column::TYPE_INTEGER,
            Self::BigInt => Column::TYPE_BIG_INT,
            Self::Decimal => Column::TYPE_FLOAT,
            Self::Boolean => Column::TYPE_BOOLEAN,
            Self::DateTime => Column::TYPE_DATETIME,
            Self::Point => Column::TYPE_POINT,
            Self::Line => Column::TYPE_LINE,
            Self::Polygon => Column::TYPE_POLYGON,
            Self::Object => Column::TYPE_OBJECT,
            Self::Relationship => Column::TYPE_RELATIONSHIP,
            Self::Vector => Column::TYPE_VECTOR,
        }
    }
}

#[derive(Debug, Clone)]
pub struct Column {
    base: ResourceBase,
    key: String,
    table: Table,
    kind: ColumnKind,
    size: i64,
    required: bool,
    default: Value,
    array: bool,
    signed: bool,
    format: String,
    format_options: Map<String, Value>,
    filters: Vec<String>,
    options: Map<String, Value>,
}

impl Column {
    pub const TYPE_STRING: &'static str = "string";
    pub const TYPE_TEXT: &'static str = "text";
    pub const TYPE_VARCHAR: &'static str = "varchar";
    pub const TYPE_MEDIUMTEXT: &'static str = "mediumtext";
    pub const TYPE_LONGTEXT: &'static str = "longtext";
    pub const TYPE_INTEGER: &'static str = "integer";
    pub const TYPE_BIG_INT: &'static str = "bigint";
    pub const TYPE_FLOAT: &'static str = "double";
    pub const TYPE_BOOLEAN: &'static str = "boolean";
    pub const TYPE_DATETIME: &'static str = "datetime";
    pub const TYPE_EMAIL: &'static str = "email";
    pub const TYPE_ENUM: &'static str = "enum";
    pub const TYPE_IP: &'static str = "ip";
    pub const TYPE_URL: &'static str = "url";
    pub const TYPE_RELATIONSHIP: &'static str = "relationship";
    pub const TYPE_POINT: &'static str = "point";
    pub const TYPE_LINE: &'static str = "linestring";
    pub const TYPE_POLYGON: &'static str = "polygon";
    pub const TYPE_OBJECT: &'static str = "object";
    pub const TYPE_VECTOR: &'static str = "vector";
    pub const DEFAULT_VARCHAR_SIZE: i64 = 255;

    pub fn sizes(type_: &str) -> Option<i64> {
        match type_ {
            Self::TYPE_TEXT => Some(65535),
            Self::TYPE_MEDIUMTEXT => Some(16_777_215),
            Self::TYPE_LONGTEXT => Some(2_147_483_647),
            _ => None,
        }
    }

    pub fn format_sizes(format: &str) -> Option<i64> {
        match format {
            Self::TYPE_EMAIL => Some(254),
            Self::TYPE_ENUM => Some(LENGTH_KEY),
            Self::TYPE_IP => Some(39),
            Self::TYPE_URL => Some(2000),
            _ => None,
        }
    }

    /// PHP `Column::resolve(array $column)`.
    #[must_use]
    pub fn resolve(column: &Map<String, Value>) -> Map<String, Value> {
        let mut type_ = column
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let mut format = column
            .get("format")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        if Self::format_sizes(&type_).is_some() {
            format.clone_from(&type_);
            Self::TYPE_STRING.clone_into(&mut type_);
        }
        let mut size = match column.get("size") {
            Some(Value::Number(n)) => n.as_i64().unwrap_or(0),
            Some(Value::String(s)) => s.parse::<i64>().unwrap_or(0),
            _ => 0,
        };
        if let Some(fixed) = Self::sizes(&type_) {
            size = fixed;
        } else if size < 1 {
            if let Some(fs) = Self::format_sizes(&format) {
                size = fs;
            }
        }
        json!({"type": type_, "format": format, "size": size})
            .as_object()
            .cloned()
            .unwrap_or_default()
    }

    fn base_new(key: impl Into<String>, table: Table, kind: ColumnKind, size: i64) -> Self {
        let key = key.into();
        Self {
            base: ResourceBase::new(key.clone()),
            key,
            table,
            kind,
            size,
            required: false,
            default: Value::Null,
            array: false,
            signed: false,
            format: String::new(),
            format_options: Map::new(),
            filters: Vec::new(),
            options: Map::new(),
        }
    }

    pub fn new(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::String, 0)
    }

    /// PHP `Columns\Text`.
    pub fn text(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::String, size)
    }

    /// PHP `Columns\Varchar`.
    pub fn varchar(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::Varchar, size)
    }

    /// PHP `Columns\RegularText` (`getType() = text`).
    pub fn regular_text(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::RegularText, size)
    }

    /// PHP `Columns\MediumText`.
    pub fn medium_text(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::MediumText, size)
    }

    /// PHP `Columns\LongText`.
    pub fn long_text(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::LongText, size)
    }

    /// PHP `Columns\Email`.
    pub fn email(key: impl Into<String>, table: Table, size: i64) -> Self {
        let mut col = Self::base_new(key, table, ColumnKind::Email, size);
        Self::TYPE_EMAIL.clone_into(&mut col.format);
        col
    }

    /// PHP `Columns\URL`.
    pub fn url(key: impl Into<String>, table: Table, size: i64) -> Self {
        let mut col = Self::base_new(key, table, ColumnKind::Url, size);
        Self::TYPE_URL.clone_into(&mut col.format);
        col
    }

    /// PHP `Columns\IP`.
    pub fn ip(key: impl Into<String>, table: Table, size: i64) -> Self {
        let mut col = Self::base_new(key, table, ColumnKind::Ip, size);
        Self::TYPE_IP.clone_into(&mut col.format);
        col
    }

    /// PHP `Columns\Enum`.
    pub fn enum_col(
        key: impl Into<String>,
        table: Table,
        elements: Vec<String>,
        size: i64,
    ) -> Self {
        let mut col = Self::base_new(key, table, ColumnKind::Enum, size);
        Self::TYPE_ENUM.clone_into(&mut col.format);
        col.format_options
            .insert("elements".into(), json!(elements));
        col
    }

    pub fn integer(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Integer, 0)
    }
    pub fn big_int(key: impl Into<String>, table: Table) -> Self {
        let mut col = Self::base_new(key, table, ColumnKind::BigInt, 0);
        col.signed = true;
        col
    }
    pub fn decimal(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Decimal, 0)
    }
    pub fn boolean(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Boolean, 0)
    }
    pub fn datetime(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::DateTime, 0)
    }
    pub fn point(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Point, 0)
    }
    pub fn line(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Line, 0)
    }
    pub fn polygon(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Polygon, 0)
    }
    pub fn object(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Object, 0)
    }
    pub fn vector(key: impl Into<String>, table: Table, size: i64) -> Self {
        Self::base_new(key, table, ColumnKind::Vector, size)
    }
    pub fn relationship(key: impl Into<String>, table: Table) -> Self {
        Self::base_new(key, table, ColumnKind::Relationship, 0)
    }

    #[must_use]
    pub fn kind(&self) -> ColumnKind {
        self.kind
    }
    #[must_use]
    pub fn get_table(&self) -> &Table {
        &self.table
    }
    #[must_use]
    pub fn get_key(&self) -> &str {
        &self.key
    }
    #[must_use]
    pub fn get_type(&self) -> &'static str {
        self.kind.as_type()
    }
    #[must_use]
    pub fn get_size(&self) -> i64 {
        self.size
    }
    #[must_use]
    pub fn is_required(&self) -> bool {
        self.required
    }
    #[must_use]
    pub fn get_default(&self) -> &Value {
        &self.default
    }
    #[must_use]
    pub fn is_array(&self) -> bool {
        self.array
    }
    #[must_use]
    pub fn is_signed(&self) -> bool {
        self.signed
    }
    #[must_use]
    pub fn get_format(&self) -> &str {
        &self.format
    }
    #[must_use]
    pub fn get_format_options(&self) -> &Map<String, Value> {
        &self.format_options
    }
    #[must_use]
    pub fn get_filters(&self) -> &[String] {
        &self.filters
    }
    #[must_use]
    pub fn get_options(&self) -> &Map<String, Value> {
        &self.options
    }
    pub fn get_options_mut(&mut self) -> &mut Map<String, Value> {
        &mut self.options
    }
    #[must_use]
    pub fn get_elements(&self) -> Vec<String> {
        self.format_options
            .get("elements")
            .and_then(Value::as_array)
            .map(|a| {
                a.iter()
                    .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                    .collect()
            })
            .unwrap_or_default()
    }
    pub fn set_required(&mut self, required: bool) {
        self.required = required;
    }
    pub fn set_default(&mut self, default: Value) {
        self.default = default;
    }
    pub fn set_array(&mut self, array: bool) {
        self.array = array;
    }
    pub fn set_signed(&mut self, signed: bool) {
        self.signed = signed;
    }
    pub fn set_format(&mut self, format: impl Into<String>) {
        self.format = format.into();
    }
    pub fn set_size(&mut self, size: i64) {
        self.size = size;
    }

    /// PHP `Column::getAttribute()`.
    #[must_use]
    pub fn get_attribute(&self) -> Attribute {
        Attribute::from_column(self)
    }
}

impl Resource for Column {
    fn get_name(&self) -> &'static str {
        TYPE_COLUMN
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "key": self.key,
            "table": self.table.json_serialize(),
            "type": self.get_type(),
            "size": self.size,
            "required": self.required,
            "default": self.default,
            "array": self.array,
            "signed": self.signed,
            "format": self.format,
            "formatOptions": self.format_options,
            "filters": self.filters,
            "options": self.options,
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

/// PHP `Attribute` - derived from a [`Column`] via [`Attribute::from_column`].
#[derive(Debug, Clone)]
pub struct Attribute {
    base: ResourceBase,
    key: String,
    table: Table,
    field_type: String,
    size: i64,
    required: bool,
    default: Value,
    array: bool,
    signed: bool,
    format: String,
    format_options: Map<String, Value>,
    filters: Vec<String>,
    options: Map<String, Value>,
}

impl Attribute {
    pub const TYPE_STRING: &'static str = Column::TYPE_STRING;
    pub const TYPE_INTEGER: &'static str = Column::TYPE_INTEGER;
    pub const TYPE_FLOAT: &'static str = Column::TYPE_FLOAT;
    pub const TYPE_BOOLEAN: &'static str = Column::TYPE_BOOLEAN;
    pub const TYPE_DATETIME: &'static str = Column::TYPE_DATETIME;
    pub const TYPE_EMAIL: &'static str = Column::TYPE_EMAIL;
    pub const TYPE_ENUM: &'static str = Column::TYPE_ENUM;
    pub const TYPE_IP: &'static str = Column::TYPE_IP;
    pub const TYPE_URL: &'static str = Column::TYPE_URL;
    pub const TYPE_RELATIONSHIP: &'static str = Column::TYPE_RELATIONSHIP;
    pub const TYPE_POINT: &'static str = Column::TYPE_POINT;
    pub const TYPE_LINE: &'static str = Column::TYPE_LINE;
    pub const TYPE_POLYGON: &'static str = Column::TYPE_POLYGON;
    pub const TYPE_OBJECT: &'static str = Column::TYPE_OBJECT;
    pub const TYPE_VECTOR: &'static str = Column::TYPE_VECTOR;

    #[must_use]
    pub fn from_column(column: &Column) -> Self {
        let mut attr = Self {
            base: ResourceBase::new(column.get_key()),
            key: column.get_key().to_owned(),
            table: column.get_table().clone(),
            field_type: column.get_type().to_owned(),
            size: column.get_size(),
            required: column.is_required(),
            default: column.get_default().clone(),
            array: column.is_array(),
            signed: column.is_signed(),
            format: column.get_format().to_owned(),
            format_options: column.get_format_options().clone(),
            filters: column.get_filters().to_vec(),
            options: column.get_options().clone(),
        };
        column
            .get_created_at()
            .clone_into(&mut attr.base.created_at);
        column
            .get_updated_at()
            .clone_into(&mut attr.base.updated_at);
        attr.base.permissions = column.get_permissions().to_vec();
        attr
    }

    #[must_use]
    pub fn get_table(&self) -> &Table {
        &self.table
    }
    #[must_use]
    pub fn get_key(&self) -> &str {
        &self.key
    }
    #[must_use]
    pub fn get_type(&self) -> &str {
        &self.field_type
    }
    #[must_use]
    pub fn get_size(&self) -> i64 {
        self.size
    }
    #[must_use]
    pub fn is_required(&self) -> bool {
        self.required
    }
    #[must_use]
    pub fn get_default(&self) -> &Value {
        &self.default
    }
    #[must_use]
    pub fn is_array(&self) -> bool {
        self.array
    }
    #[must_use]
    pub fn is_signed(&self) -> bool {
        self.signed
    }
    #[must_use]
    pub fn get_format(&self) -> &str {
        &self.format
    }
    #[must_use]
    pub fn get_format_options(&self) -> &Map<String, Value> {
        &self.format_options
    }
    #[must_use]
    pub fn get_filters(&self) -> &[String] {
        &self.filters
    }
    #[must_use]
    pub fn get_options(&self) -> &Map<String, Value> {
        &self.options
    }
}

impl Resource for Attribute {
    fn get_name(&self) -> &'static str {
        TYPE_ATTRIBUTE
    }
    fn get_group(&self) -> &'static str {
        transfer::GROUP_DATABASES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "key": self.key,
            "table": self.table.json_serialize(),
            "type": self.field_type,
            "size": self.size,
            "required": self.required,
            "default": self.default,
            "array": self.array,
            "signed": self.signed,
            "format": self.format,
            "formatOptions": self.format_options,
            "filters": self.filters,
            "options": self.options,
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

pub const TYPE_STRING: &str = "string";
pub const TYPE_INTEGER: &str = "integer";
pub const TYPE_FLOAT: &str = "float";
pub const TYPE_BOOLEAN: &str = "boolean";
pub const TYPE_OBJECT: &str = "object";
pub const TYPE_ARRAY: &str = "array";
pub const TYPE_NULL: &str = "null";
pub const TYPE_POINT: &str = "point";
pub const TYPE_LINE: &str = "linestring";
pub const TYPE_POLYGON: &str = "polygon";
