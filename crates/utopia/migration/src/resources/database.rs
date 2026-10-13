//! Databases, tables, columns, indexes and rows.

use std::sync::{Mutex, MutexGuard};

use php_std::{Array, Value};

use super::{
    Input, Param, cast_array, cast_bool, cast_int, param, to_array, to_bool, to_int, to_opt_array, to_opt_float,
    to_opt_int, to_opt_string, to_string,
};
use crate::error::{Error, Result};
use crate::resource::{Base, Kind, Resource, ResourceRef, lock, types};

/// `Utopia\Database\Database::LENGTH_KEY`.
pub const LENGTH_KEY: i64 = 255;

/// Which database class a database resource is.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum DatabaseFlavor {
    /// `Database\Database` (legacy and TablesDB databases).
    Database,
    /// `Database\DocumentsDB`.
    DocumentsDb,
    /// `Database\VectorsDB`.
    VectorsDb,
}

impl DatabaseFlavor {
    /// The resource type.
    pub fn name(self) -> &'static str {
        match self {
            DatabaseFlavor::Database => types::DATABASE,
            DatabaseFlavor::DocumentsDb => types::DATABASE_DOCUMENTSDB,
            DatabaseFlavor::VectorsDb => types::DATABASE_VECTORSDB,
        }
    }

    /// The PHP class.
    pub fn class(self) -> &'static str {
        match self {
            DatabaseFlavor::Database => "Utopia\\Migration\\Resources\\Database\\Database",
            DatabaseFlavor::DocumentsDb => "Utopia\\Migration\\Resources\\Database\\DocumentsDB",
            DatabaseFlavor::VectorsDb => "Utopia\\Migration\\Resources\\Database\\VectorsDB",
        }
    }

    /// The flavor of database type `t` (`documentsdb`, `vectorsdb`, else the plain one).
    pub fn of_type(t: &str) -> Self {
        match t {
            types::DATABASE_DOCUMENTSDB => DatabaseFlavor::DocumentsDb,
            types::DATABASE_VECTORSDB => DatabaseFlavor::VectorsDb,
            _ => DatabaseFlavor::Database,
        }
    }
}

/// A database.
///
/// PHP: `Utopia\Migration\Resources\Database\Database`, `DocumentsDB` and
/// `VectorsDB` (`getDatabaseName()` is the name, `getType()` the type,
/// `getDatabase()` the DSN, `getDatabaseStatus()` the status).
#[derive(Debug, Clone, PartialEq)]
pub struct Database {
    /// The class.
    pub flavor: DatabaseFlavor,
    /// The name.
    pub name: String,
    /// Whether it is enabled.
    pub enabled: bool,
    /// The database type (`legacy`, `tablesdb`, `documentsdb`, `vectorsdb`).
    pub database_type: String,
    /// The DSN of the database the data lives in.
    pub database: Option<String>,
    /// The provisioning status.
    pub status: Option<String>,
}

impl Database {
    /// A database with the constructor's defaults.
    pub fn new(flavor: DatabaseFlavor, name: impl Into<String>) -> Self {
        Self {
            flavor,
            name: name.into(),
            enabled: true,
            database_type: String::new(),
            database: Some(String::new()),
            status: None,
        }
    }

    /// A resource of this database.
    pub fn resource(self, base: Base) -> ResourceRef {
        Resource::new(Kind::Database(self), base)
    }

    /// PHP: `Database::fromArray()`, `DocumentsDB::fromArray()`, `VectorsDB::fromArray()`.
    pub fn from_array(flavor: DatabaseFlavor, array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = match flavor {
            DatabaseFlavor::Database => "Utopia\\Migration\\Resources\\Database\\Database::__construct",
            DatabaseFlavor::DocumentsDb => "Utopia\\Migration\\Resources\\Database\\DocumentsDB::__construct",
            DatabaseFlavor::VectorsDb => "Utopia\\Migration\\Resources\\Database\\VectorsDB::__construct",
        };
        let p = |n, name| param(class, n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let name = to_string(&i.raw("name"), &p(2, "name"))?;
        let created_at = to_string(&i.or("createdAt", Value::from("")), &p(3, "createdAt"))?;
        let updated_at = to_string(&i.or("updatedAt", Value::from("")), &p(4, "updatedAt"))?;
        let enabled = to_bool(&i.or("enabled", Value::Bool(true)), &p(5, "enabled"))?;
        let original_id = to_string(&i.or("originalId", Value::from("")), &p(6, "originalId"))?;
        let (default_type, default_dsn) = match flavor {
            DatabaseFlavor::Database => ("legacy", Value::Null),
            DatabaseFlavor::DocumentsDb => (types::DATABASE_DOCUMENTSDB, Value::from("")),
            DatabaseFlavor::VectorsDb => (types::DATABASE_VECTORSDB, Value::from("")),
        };
        let database_type = to_string(&i.or("type", Value::from(default_type)), &p(7, "type"))?;
        let database = to_opt_string(&i.or("database", default_dsn), &p(8, "database"))?;
        let status = to_opt_string(&i.or("status", Value::Null), &p(9, "databaseStatus"))?;
        Ok(Database { flavor, name, enabled, database_type, database, status }.resource(Base {
            id,
            original_id,
            created_at,
            updated_at,
            ..Base::default()
        }))
    }
}

/// A table, or a collection of a DocumentsDB or VectorsDB database.
///
/// PHP: `Utopia\Migration\Resources\Database\Table` and `Collection`
/// (`getTableName()` is the name, `getRowSecurity()` the document security).
#[derive(Debug)]
pub struct Table {
    /// Whether this is a `Collection`.
    pub collection: bool,
    /// The database.
    pub database: ResourceRef,
    /// The name.
    pub name: String,
    /// Whether row permissions apply.
    pub row_security: bool,
    /// Whether it is enabled.
    pub enabled: bool,
    dimension: Mutex<Option<i64>>,
}

impl Table {
    /// A table (`collection: false`) or collection of `database`.
    pub fn new(
        collection: bool,
        database: ResourceRef,
        name: impl Into<String>,
        row_security: bool,
        enabled: bool,
    ) -> Self {
        Self { collection, database, name: name.into(), row_security, enabled, dimension: Mutex::new(None) }
    }

    /// The vector dimension of a VectorsDB collection.
    pub fn dimension(&self) -> Option<i64> {
        *lock(&self.dimension)
    }

    /// Sets the vector dimension (`Collection::setDimension()`).
    pub fn set_dimension(&self, dimension: Option<i64>) {
        *lock(&self.dimension) = dimension;
    }

    /// A resource of this table.
    pub fn resource(
        self,
        id: impl Into<String>,
        permissions: Vec<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::Table(self),
            Base {
                id: id.into(),
                permissions,
                created_at: created_at.into(),
                updated_at: updated_at.into(),
                ..Base::default()
            },
        )
    }

    /// PHP: `Table::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let database = Database::from_array(
            DatabaseFlavor::Database,
            &i.array("database", &param("Utopia\\Migration\\Resources\\Database\\Database::fromArray", 1, "array"))?,
        )?;
        let p = |n, name| param("Utopia\\Migration\\Resources\\Database\\Table::__construct", n, name);
        let name = to_string(&i.raw("name"), &p(2, "name"))?;
        let id = to_string(&i.raw("id"), &p(3, "id"))?;
        let row_security = cast_bool(&i.either("rowSecurity", "documentSecurity"));
        let permissions = to_array(&i.or("permissions", Value::Array(Array::new())), &p(5, "permissions"))?;
        let created = to_string(&i.or("createdAt", Value::from("")), &p(6, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(7, "updatedAt"))?;
        let enabled = to_bool(&i.or("enabled", Value::Bool(true)), &p(8, "enabled"))?;
        Ok(Table::new(false, database, name, row_security, enabled).resource(
            id,
            super::strings(&permissions),
            created,
            updated,
        ))
    }

    /// PHP: `Collection::fromArray()`: the database's class from its `type`.
    pub fn collection_from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let db = i.array("database", &param("Utopia\\Migration\\Resources\\Database\\Database::fromArray", 1, "array"));
        let db = match db {
            Ok(a) => a,
            // `$array['database']['type']` on a non-array reads null.
            Err(_) => return Err(Error::TypeError(
                "Utopia\\Migration\\Resources\\Database\\Database::fromArray(): Argument #1 ($array) must be of type array, null given".into(),
            )),
        };
        let flavor = match db.get_str("type") {
            Some(Value::Str(s)) => DatabaseFlavor::of_type(&String::from_utf8_lossy(s.as_bytes())),
            _ => DatabaseFlavor::Database,
        };
        let database = Database::from_array(flavor, &db)?;
        let p = |n, name| param("Utopia\\Migration\\Resources\\Database\\Table::__construct", n, name);
        let name = to_string(&i.raw("name"), &p(2, "name"))?;
        let id = to_string(&i.raw("id"), &p(3, "id"))?;
        let row_security = to_bool(&i.either("rowSecurity", "documentSecurity"), &p(4, "rowSecurity"))?;
        let permissions = to_array(&i.or("permissions", Value::Array(Array::new())), &p(5, "permissions"))?;
        let created = to_string(&i.or("createdAt", Value::from("")), &p(6, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(7, "updatedAt"))?;
        let enabled = to_bool(&i.or("enabled", Value::Bool(true)), &p(8, "enabled"))?;
        let dimension = to_opt_int(
            &i.or("dimension", Value::Null),
            &param("Utopia\\Migration\\Resources\\Database\\Collection::setDimension", 1, "dimension"),
        )?;
        let table = Table::new(true, database, name, row_security, enabled);
        table.set_dimension(dimension);
        Ok(table.resource(id, super::strings(&permissions), created, updated))
    }
}

/// The class of a column (or an attribute derived from one).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FieldClass {
    /// `Columns\BigInt`.
    BigInt,
    /// `Columns\Boolean`.
    Boolean,
    /// `Columns\DateTime`.
    DateTime,
    /// `Columns\Decimal`.
    Decimal,
    /// `Columns\Email`.
    Email,
    /// `Columns\Enum`.
    Enum,
    /// `Columns\IP`.
    Ip,
    /// `Columns\Integer`.
    Integer,
    /// `Columns\Line`.
    Line,
    /// `Columns\LongText`.
    LongText,
    /// `Columns\MediumText`.
    MediumText,
    /// `Columns\ObjectType`.
    ObjectType,
    /// `Columns\Point`.
    Point,
    /// `Columns\Polygon`.
    Polygon,
    /// `Columns\RegularText`.
    RegularText,
    /// `Columns\Relationship`.
    Relationship,
    /// `Columns\Text`.
    Text,
    /// `Columns\URL`.
    Url,
    /// `Columns\Varchar`.
    Varchar,
    /// `Columns\Vector`.
    Vector,
    /// `Database\Attribute`: a column's attribute form, of any type.
    Attribute,
}

impl FieldClass {
    /// Every column class, with its PHP short name.
    pub const COLUMNS: &'static [(FieldClass, &'static str)] = &[
        (FieldClass::BigInt, "BigInt"),
        (FieldClass::Boolean, "Boolean"),
        (FieldClass::DateTime, "DateTime"),
        (FieldClass::Decimal, "Decimal"),
        (FieldClass::Email, "Email"),
        (FieldClass::Enum, "Enum"),
        (FieldClass::Ip, "IP"),
        (FieldClass::Integer, "Integer"),
        (FieldClass::Line, "Line"),
        (FieldClass::LongText, "LongText"),
        (FieldClass::MediumText, "MediumText"),
        (FieldClass::ObjectType, "ObjectType"),
        (FieldClass::Point, "Point"),
        (FieldClass::Polygon, "Polygon"),
        (FieldClass::RegularText, "RegularText"),
        (FieldClass::Relationship, "Relationship"),
        (FieldClass::Text, "Text"),
        (FieldClass::Url, "URL"),
        (FieldClass::Varchar, "Varchar"),
        (FieldClass::Vector, "Vector"),
    ];

    /// The PHP class.
    pub fn php_class(self) -> &'static str {
        match self {
            FieldClass::BigInt => "Utopia\\Migration\\Resources\\Database\\Columns\\BigInt",
            FieldClass::Boolean => "Utopia\\Migration\\Resources\\Database\\Columns\\Boolean",
            FieldClass::DateTime => "Utopia\\Migration\\Resources\\Database\\Columns\\DateTime",
            FieldClass::Decimal => "Utopia\\Migration\\Resources\\Database\\Columns\\Decimal",
            FieldClass::Email => "Utopia\\Migration\\Resources\\Database\\Columns\\Email",
            FieldClass::Enum => "Utopia\\Migration\\Resources\\Database\\Columns\\Enum",
            FieldClass::Ip => "Utopia\\Migration\\Resources\\Database\\Columns\\IP",
            FieldClass::Integer => "Utopia\\Migration\\Resources\\Database\\Columns\\Integer",
            FieldClass::Line => "Utopia\\Migration\\Resources\\Database\\Columns\\Line",
            FieldClass::LongText => "Utopia\\Migration\\Resources\\Database\\Columns\\LongText",
            FieldClass::MediumText => "Utopia\\Migration\\Resources\\Database\\Columns\\MediumText",
            FieldClass::ObjectType => "Utopia\\Migration\\Resources\\Database\\Columns\\ObjectType",
            FieldClass::Point => "Utopia\\Migration\\Resources\\Database\\Columns\\Point",
            FieldClass::Polygon => "Utopia\\Migration\\Resources\\Database\\Columns\\Polygon",
            FieldClass::RegularText => "Utopia\\Migration\\Resources\\Database\\Columns\\RegularText",
            FieldClass::Relationship => "Utopia\\Migration\\Resources\\Database\\Columns\\Relationship",
            FieldClass::Text => "Utopia\\Migration\\Resources\\Database\\Columns\\Text",
            FieldClass::Url => "Utopia\\Migration\\Resources\\Database\\Columns\\URL",
            FieldClass::Varchar => "Utopia\\Migration\\Resources\\Database\\Columns\\Varchar",
            FieldClass::Vector => "Utopia\\Migration\\Resources\\Database\\Columns\\Vector",
            FieldClass::Attribute => "Utopia\\Migration\\Resources\\Database\\Attribute",
        }
    }

    /// The class of PHP short name `name` (`Text`, `IP`, ...).
    pub fn from_short(name: &str) -> Option<Self> {
        Self::COLUMNS.iter().find(|(_, n)| *n == name).map(|(c, _)| *c)
    }

    /// The type a column of this class reports (`getType()`).
    pub fn column_type(self) -> &'static str {
        match self {
            FieldClass::BigInt => column::BIG_INT,
            FieldClass::Boolean => column::BOOLEAN,
            FieldClass::DateTime => column::DATETIME,
            FieldClass::Decimal => column::FLOAT,
            FieldClass::Email => column::EMAIL,
            FieldClass::Enum => column::ENUM,
            FieldClass::Ip => column::IP,
            FieldClass::Integer => column::INTEGER,
            FieldClass::Line => column::LINE,
            FieldClass::LongText => column::LONGTEXT,
            FieldClass::MediumText => column::MEDIUMTEXT,
            FieldClass::ObjectType => column::OBJECT,
            FieldClass::Point => column::POINT,
            FieldClass::Polygon => column::POLYGON,
            FieldClass::RegularText => column::TEXT,
            FieldClass::Relationship => column::RELATIONSHIP,
            FieldClass::Text => column::STRING,
            FieldClass::Url => column::URL,
            FieldClass::Varchar => column::VARCHAR,
            FieldClass::Vector => column::VECTOR,
            FieldClass::Attribute => "",
        }
    }
}

/// Column types (PHP: `Column::TYPE_*`, `Attribute::TYPE_*`).
pub mod column {
    /// A string.
    pub const STRING: &str = "string";
    /// A TEXT string.
    pub const TEXT: &str = "text";
    /// A VARCHAR string.
    pub const VARCHAR: &str = "varchar";
    /// A MEDIUMTEXT string.
    pub const MEDIUMTEXT: &str = "mediumtext";
    /// A LONGTEXT string.
    pub const LONGTEXT: &str = "longtext";
    /// An integer.
    pub const INTEGER: &str = "integer";
    /// A big integer.
    pub const BIG_INT: &str = "bigint";
    /// A float.
    pub const FLOAT: &str = "double";
    /// A boolean.
    pub const BOOLEAN: &str = "boolean";
    /// A datetime.
    pub const DATETIME: &str = "datetime";
    /// An email string.
    pub const EMAIL: &str = "email";
    /// An enum string.
    pub const ENUM: &str = "enum";
    /// An IP string.
    pub const IP: &str = "ip";
    /// A URL string.
    pub const URL: &str = "url";
    /// A relationship.
    pub const RELATIONSHIP: &str = "relationship";
    /// A point.
    pub const POINT: &str = "point";
    /// A line.
    pub const LINE: &str = "linestring";
    /// A polygon.
    pub const POLYGON: &str = "polygon";
    /// An object.
    pub const OBJECT: &str = "object";
    /// A vector.
    pub const VECTOR: &str = "vector";

    /// Sizes fixed by the type (`Column::SIZES`).
    pub const SIZES: &[(&str, i64)] = &[(TEXT, 65535), (MEDIUMTEXT, 16_777_215), (LONGTEXT, 2_147_483_647)];
    /// Format shorthands and their sizes (`Column::FORMAT_SIZES`).
    pub const FORMAT_SIZES: &[(&str, i64)] = &[(EMAIL, 254), (ENUM, super::LENGTH_KEY), (IP, 39), (URL, 2000)];
    /// The size of a varchar given none (`Column::DEFAULT_VARCHAR_SIZE`).
    pub const DEFAULT_VARCHAR_SIZE: i64 = 255;

    pub(crate) fn size_of(table: &[(&str, i64)], t: &str) -> Option<i64> {
        table.iter().find(|(k, _)| *k == t).map(|(_, v)| *v)
    }
}

/// A column definition resolved to the type, format and size Appwrite
/// stores: a format shorthand becomes a string of that format, a missing
/// size comes from the type or the format.
///
/// PHP: `Column::resolve($column)`.
pub fn resolve(definition: &Array) -> (String, String, i64) {
    let text = |k: &str| match definition.get_str(k) {
        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        _ => String::new(),
    };
    let mut t = text("type");
    let mut format = text("format");
    if column::size_of(column::FORMAT_SIZES, &t).is_some() {
        format = t;
        t = column::STRING.to_owned();
    }
    let size = definition.get_str("size").cloned().unwrap_or(Value::Null);
    let mut size = match &size {
        Value::Int(_) | Value::Float(_) => cast_int(&size),
        Value::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => cast_int(&size),
        _ => 0,
    };
    if let Some(fixed) = column::size_of(column::SIZES, &t) {
        size = fixed;
    } else if size < 1 {
        size = column::size_of(column::FORMAT_SIZES, &format).unwrap_or(size);
    }
    (t, format, size)
}

/// A column (or attribute) of a table.
///
/// PHP: `Database\Column` and its subclasses in `Database\Columns`, and
/// `Database\Attribute` ([`FieldClass::Attribute`]). `getType()` is
/// [`Field::field_type`]; class getters (`getMin()`, `getElements()`,
/// `getRelatedTable()`, ...) are methods.
#[derive(Debug)]
pub struct Field {
    /// The class.
    pub class: FieldClass,
    /// The key.
    pub key: String,
    /// The table.
    pub table: ResourceRef,
    /// An attribute's type (columns report their class's).
    pub attribute_type: String,
    /// The size.
    pub size: i64,
    /// Whether it is required (`isRequired()`).
    pub required: bool,
    /// The default value.
    pub default: Value,
    /// Whether it is an array (`isArray()`).
    pub array: bool,
    /// Whether it is signed (`isSigned()`).
    pub signed: bool,
    /// The format.
    pub format: String,
    /// The format options.
    pub format_options: Array,
    /// The filters.
    pub filters: Vec<String>,
    options: Mutex<Array>,
}

/// Arguments common to every column constructor.
#[derive(Debug, Clone, Default)]
pub struct FieldSpec {
    /// The size.
    pub size: i64,
    /// Whether it is required.
    pub required: bool,
    /// The default value.
    pub default: Value,
    /// Whether it is an array.
    pub array: bool,
    /// Whether it is signed.
    pub signed: bool,
    /// The format.
    pub format: String,
    /// The format options.
    pub format_options: Array,
    /// The filters.
    pub filters: Vec<String>,
    /// The options.
    pub options: Array,
}

impl Field {
    /// A field of `class` (PHP: `Column::__construct`).
    pub fn new(class: FieldClass, key: impl Into<String>, table: ResourceRef, spec: FieldSpec) -> Self {
        Self {
            class,
            key: key.into(),
            table,
            attribute_type: String::new(),
            size: spec.size,
            required: spec.required,
            default: spec.default,
            array: spec.array,
            signed: spec.signed,
            format: spec.format,
            format_options: spec.format_options,
            filters: spec.filters,
            options: Mutex::new(spec.options),
        }
    }

    /// Whether this is an `Attribute`.
    pub fn is_attribute(&self) -> bool {
        self.class == FieldClass::Attribute
    }

    /// The type (`getType()`).
    pub fn field_type(&self) -> &str {
        if self.is_attribute() { &self.attribute_type } else { self.class.column_type() }
    }

    /// The options, a copy.
    pub fn options(&self) -> Array {
        lock(&self.options).clone()
    }

    /// The options, to change them in place (PHP returns them by reference).
    pub fn options_mut(&self) -> MutexGuard<'_, Array> {
        lock(&self.options)
    }

    /// A resource of this field.
    pub fn resource(self, created_at: impl Into<String>, updated_at: impl Into<String>) -> ResourceRef {
        Resource::new(
            Kind::Field(self),
            Base { created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// `(int) $formatOptions['min']` (Integer, BigInt).
    pub fn min_int(&self) -> i64 {
        cast_int(self.format_options.get_str("min").unwrap_or(&Value::Null))
    }

    /// `(int) $formatOptions['max']` (Integer, BigInt).
    pub fn max_int(&self) -> i64 {
        cast_int(self.format_options.get_str("max").unwrap_or(&Value::Null))
    }

    /// `(float) $formatOptions['min']` (Decimal).
    pub fn min_float(&self) -> f64 {
        self.format_options.get_str("min").map_or(0.0, Value::to_double)
    }

    /// `(float) $formatOptions['max']` (Decimal).
    pub fn max_float(&self) -> f64 {
        self.format_options.get_str("max").map_or(0.0, Value::to_double)
    }

    /// `(array) $formatOptions['elements']` (Enum).
    pub fn elements(&self) -> Array {
        cast_array(self.format_options.get_str("elements").unwrap_or(&Value::Null))
    }

    fn option(&self, key: &str) -> Value {
        lock(&self.options).get_str(key).cloned().unwrap_or(Value::Null)
    }

    /// The related table (Relationship: `relatedTable ?? relatedCollection`).
    pub fn related_table(&self) -> Value {
        let o = lock(&self.options);
        o.get_str("relatedTable")
            .filter(|v| !matches!(v, Value::Null))
            .or_else(|| o.get_str("relatedCollection"))
            .cloned()
            .unwrap_or(Value::Null)
    }

    /// The relation type (Relationship).
    pub fn relation_type(&self) -> Value {
        self.option("relationType")
    }

    /// Whether the relationship is two-way (Relationship).
    pub fn two_way(&self) -> Value {
        self.option("twoWay")
    }

    /// The key on the related table (Relationship).
    pub fn two_way_key(&self) -> Value {
        self.option("twoWayKey")
    }

    /// The on-delete action (Relationship).
    pub fn on_delete(&self) -> Value {
        self.option("onDelete")
    }

    /// The side (Relationship).
    pub fn side(&self) -> Value {
        self.option("side")
    }

    /// The attribute form of a column (`Column::getAttribute()`,
    /// `Attribute::fromColumn()`): the same definition as an `Attribute`
    /// with the column's type.
    pub fn attribute_of(resource: &Resource) -> Option<ResourceRef> {
        let f = resource.as_field()?;
        let base = resource.base_snapshot();
        let attribute = Field {
            class: FieldClass::Attribute,
            key: f.key.clone(),
            table: f.table.clone(),
            attribute_type: f.field_type().to_owned(),
            size: f.size,
            required: f.required,
            default: f.default.clone(),
            array: f.array,
            signed: f.signed,
            format: f.format.clone(),
            format_options: f.format_options.clone(),
            filters: f.filters.clone(),
            options: Mutex::new(f.options()),
        };
        Some(attribute.resource(base.created_at, base.updated_at))
    }
}

/// Builders of each column class with its constructor's defaults.
pub mod columns {
    use super::*;

    fn opts(pairs: &[(&str, Value)]) -> Array {
        let mut a = Array::new();
        for (k, v) in pairs {
            a.set((*k).into(), v.clone());
        }
        a
    }

    /// A string column of class `class` (Text, Email, IP, URL, RegularText,
    /// MediumText, LongText, Varchar, Enum without elements).
    pub fn text(
        class: FieldClass,
        key: &str,
        table: ResourceRef,
        required: bool,
        default: Option<String>,
        array: bool,
        size: i64,
        format: &str,
    ) -> Field {
        let format = match class {
            FieldClass::Email => "email",
            FieldClass::Ip => "ip",
            FieldClass::Url => "url",
            _ => format,
        };
        Field::new(
            class,
            key,
            table,
            FieldSpec {
                size,
                required,
                default: default.map_or(Value::Null, |d| Value::from(d.as_str())),
                array,
                format: format.to_owned(),
                ..FieldSpec::default()
            },
        )
    }

    /// The default size of a string class (`Text`: [`LENGTH_KEY`], `Email`: 254, ...).
    pub fn default_size(class: FieldClass) -> i64 {
        match class {
            FieldClass::Email => 254,
            FieldClass::Enum => LENGTH_KEY,
            FieldClass::Ip => 39,
            FieldClass::Url => 2000,
            FieldClass::RegularText => 65535,
            FieldClass::MediumText => 16_777_215,
            FieldClass::LongText => 2_147_483_647,
            FieldClass::Varchar => column::DEFAULT_VARCHAR_SIZE,
            _ => LENGTH_KEY,
        }
    }

    /// An `Enum` column.
    pub fn enumeration(
        key: &str,
        table: ResourceRef,
        elements: Array,
        required: bool,
        default: Option<String>,
        array: bool,
        size: i64,
    ) -> Field {
        Field::new(
            FieldClass::Enum,
            key,
            table,
            FieldSpec {
                size,
                required,
                default: default.map_or(Value::Null, |d| Value::from(d.as_str())),
                array,
                format: "enum".to_owned(),
                format_options: opts(&[("elements", Value::Array(elements))]),
                ..FieldSpec::default()
            },
        )
    }

    /// An `Integer` (`big: false`) or `BigInt` column.
    #[allow(clippy::too_many_arguments)]
    pub fn integer(
        big: bool,
        key: &str,
        table: ResourceRef,
        required: bool,
        default: Option<i64>,
        array: bool,
        min: Option<i64>,
        max: Option<i64>,
        signed: bool,
    ) -> Field {
        let min = min.unwrap_or(i64::MIN);
        let max = max.unwrap_or(i64::MAX);
        let size = if big || max > 2_147_483_647 { 8 } else { 4 };
        Field::new(
            if big { FieldClass::BigInt } else { FieldClass::Integer },
            key,
            table,
            FieldSpec {
                size,
                required,
                default: default.map_or(Value::Null, Value::Int),
                array,
                signed,
                format_options: opts(&[("min", Value::Int(min)), ("max", Value::Int(max))]),
                ..FieldSpec::default()
            },
        )
    }

    /// A `Decimal` column (min and max default to `PHP_FLOAT_MIN` and `PHP_FLOAT_MAX`).
    #[allow(clippy::too_many_arguments)]
    pub fn decimal(
        key: &str,
        table: ResourceRef,
        required: bool,
        default: Option<f64>,
        array: bool,
        min: Option<f64>,
        max: Option<f64>,
        signed: bool,
    ) -> Field {
        Field::new(
            FieldClass::Decimal,
            key,
            table,
            FieldSpec {
                required,
                default: default.map_or(Value::Null, Value::Float),
                array,
                signed,
                format_options: opts(&[
                    ("min", Value::Float(min.unwrap_or(f64::MIN_POSITIVE))),
                    ("max", Value::Float(max.unwrap_or(f64::MAX))),
                ]),
                ..FieldSpec::default()
            },
        )
    }

    /// A `Boolean` column.
    pub fn boolean(key: &str, table: ResourceRef, required: bool, default: Option<bool>, array: bool) -> Field {
        Field::new(
            FieldClass::Boolean,
            key,
            table,
            FieldSpec { required, default: default.map_or(Value::Null, Value::Bool), array, ..FieldSpec::default() },
        )
    }

    /// A `DateTime` column (with the `datetime` filter).
    pub fn datetime(key: &str, table: ResourceRef, required: bool, default: Option<String>, array: bool) -> Field {
        Field::new(
            FieldClass::DateTime,
            key,
            table,
            FieldSpec {
                required,
                default: default.map_or(Value::Null, |d| Value::from(d.as_str())),
                array,
                filters: vec!["datetime".to_owned()],
                ..FieldSpec::default()
            },
        )
    }

    /// A `Point`, `Line`, `Polygon` or `ObjectType` column.
    pub fn structured(
        class: FieldClass,
        key: &str,
        table: ResourceRef,
        required: bool,
        default: Option<Array>,
    ) -> Field {
        Field::new(
            class,
            key,
            table,
            FieldSpec { required, default: default.map_or(Value::Null, Value::Array), ..FieldSpec::default() },
        )
    }

    /// A `Vector` column.
    pub fn vector(key: &str, table: ResourceRef, required: bool, size: i64, default: Option<Array>) -> Field {
        Field::new(
            FieldClass::Vector,
            key,
            table,
            FieldSpec { size, required, default: default.map_or(Value::Null, Value::Array), ..FieldSpec::default() },
        )
    }

    /// A `Relationship` column.
    #[allow(clippy::too_many_arguments)]
    pub fn relationship(
        key: &str,
        table: ResourceRef,
        related_table: &str,
        relation_type: &str,
        two_way: bool,
        two_way_key: Option<String>,
        on_delete: &str,
        side: &str,
    ) -> Field {
        Field::new(
            FieldClass::Relationship,
            key,
            table,
            FieldSpec {
                options: opts(&[
                    ("relatedCollection", Value::from(related_table)),
                    ("relationType", Value::from(relation_type)),
                    ("twoWay", Value::Bool(two_way)),
                    ("twoWayKey", two_way_key.as_deref().map_or(Value::Null, Value::from)),
                    ("onDelete", Value::from(on_delete)),
                    ("side", Value::from(side)),
                ]),
                ..FieldSpec::default()
            },
        )
    }

    fn ctor(class: FieldClass) -> &'static str {
        match class {
            FieldClass::BigInt => "Utopia\\Migration\\Resources\\Database\\Columns\\BigInt::__construct",
            FieldClass::Boolean => "Utopia\\Migration\\Resources\\Database\\Columns\\Boolean::__construct",
            FieldClass::DateTime => "Utopia\\Migration\\Resources\\Database\\Columns\\DateTime::__construct",
            FieldClass::Decimal => "Utopia\\Migration\\Resources\\Database\\Columns\\Decimal::__construct",
            FieldClass::Enum => "Utopia\\Migration\\Resources\\Database\\Columns\\Enum::__construct",
            FieldClass::Integer => "Utopia\\Migration\\Resources\\Database\\Columns\\Integer::__construct",
            FieldClass::Line => "Utopia\\Migration\\Resources\\Database\\Columns\\Line::__construct",
            FieldClass::LongText => "Utopia\\Migration\\Resources\\Database\\Columns\\LongText::__construct",
            FieldClass::MediumText => "Utopia\\Migration\\Resources\\Database\\Columns\\MediumText::__construct",
            FieldClass::ObjectType => "Utopia\\Migration\\Resources\\Database\\Columns\\ObjectType::__construct",
            FieldClass::Point => "Utopia\\Migration\\Resources\\Database\\Columns\\Point::__construct",
            FieldClass::Polygon => "Utopia\\Migration\\Resources\\Database\\Columns\\Polygon::__construct",
            FieldClass::RegularText => "Utopia\\Migration\\Resources\\Database\\Columns\\RegularText::__construct",
            FieldClass::Relationship => "Utopia\\Migration\\Resources\\Database\\Columns\\Relationship::__construct",
            FieldClass::Varchar => "Utopia\\Migration\\Resources\\Database\\Columns\\Varchar::__construct",
            FieldClass::Vector => "Utopia\\Migration\\Resources\\Database\\Columns\\Vector::__construct",
            _ => "Utopia\\Migration\\Resources\\Database\\Columns\\Text::__construct",
        }
    }

    /// `<Class>::fromArray($array)` of column class `class`. `Email`, `IP`
    /// and `URL` inherit `Text::fromArray()`, which builds a `Text` (it calls
    /// `new self`), so they return `Text` columns.
    pub fn from_array(class: FieldClass, array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let table_array = i.get("table").or_else(|| i.get("collection")).cloned().unwrap_or(Value::Null);
        let table = Table::from_array(&to_array(
            &table_array,
            &param("Utopia\\Migration\\Resources\\Database\\Table::fromArray", 1, "array"),
        )?)?;
        let key_param = param(ctor(class), 1, "key");
        let key = to_string(&i.raw("key"), &key_param)?;
        let p = |n, name| -> Param { param(ctor(class), n, name) };
        let created = || to_string(&i.or("createdAt", Value::from("")), &p(99, "createdAt"));
        let updated = || to_string(&i.or("updatedAt", Value::from("")), &p(99, "updatedAt"));
        let fo = |k: &str| -> Value {
            match i.get("formatOptions") {
                Some(Value::Array(a)) => a.get_str(k).cloned().unwrap_or(Value::Null),
                _ => Value::Null,
            }
        };
        let field = match class {
            FieldClass::Text
            | FieldClass::Email
            | FieldClass::Ip
            | FieldClass::Url
            | FieldClass::RegularText
            | FieldClass::MediumText
            | FieldClass::LongText
            | FieldClass::Varchar => {
                let class = match class {
                    FieldClass::Email | FieldClass::Ip | FieldClass::Url => FieldClass::Text,
                    c => c,
                };
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = to_opt_string(&i.or("default", Value::Null), &p(4, "default"))?;
                let array = to_bool(&i.raw("array"), &p(5, "array"))?;
                let size = to_int(&i.raw("size"), &p(6, "size"))?;
                let format = to_string(&i.raw("format"), &p(7, "format"))?;
                text(class, &key, table, required, default, array, size, &format)
            }
            FieldClass::Enum => {
                let elements = to_array(&fo("elements"), &p(3, "elements"))?;
                let required = to_bool(&i.raw("required"), &p(4, "required"))?;
                let default = to_opt_string(&i.raw("default"), &p(5, "default"))?;
                let array = to_bool(&i.raw("array"), &p(6, "array"))?;
                let size = to_int(&i.raw("size"), &p(7, "size"))?;
                enumeration(&key, table, elements, required, default, array, size)
            }
            FieldClass::Integer | FieldClass::BigInt => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = to_opt_int(&i.raw("default"), &p(4, "default"))?;
                let array = to_bool(&i.raw("array"), &p(5, "array"))?;
                let min = to_opt_int(&fo("min"), &p(6, "min"))?;
                let max = to_opt_int(&fo("max"), &p(7, "max"))?;
                let signed = if class == FieldClass::BigInt {
                    to_bool(&i.or("signed", Value::Bool(true)), &p(8, "signed"))?
                } else {
                    true
                };
                integer(class == FieldClass::BigInt, &key, table, required, default, array, min, max, signed)
            }
            FieldClass::Decimal => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = to_opt_float(&i.raw("default"), &p(4, "default"))?;
                let array = to_bool(&i.raw("array"), &p(5, "array"))?;
                let min = to_opt_float(&fo("min"), &p(6, "min"))?;
                let max = to_opt_float(&fo("max"), &p(7, "max"))?;
                decimal(&key, table, required, default, array, min, max, true)
            }
            FieldClass::Boolean => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = super::super::to_opt_bool(&i.raw("default"), &p(4, "default"))?;
                let array = to_bool(&i.raw("array"), &p(5, "array"))?;
                boolean(&key, table, required, default, array)
            }
            FieldClass::DateTime => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = to_opt_string(&i.raw("default"), &p(4, "default"))?;
                let array = to_bool(&i.raw("array"), &p(5, "array"))?;
                datetime(&key, table, required, default, array)
            }
            FieldClass::Point | FieldClass::Line | FieldClass::Polygon | FieldClass::ObjectType => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let default = to_opt_array(&i.raw("default"), &p(4, "default"))?;
                structured(class, &key, table, required, default)
            }
            FieldClass::Vector => {
                let required = to_bool(&i.raw("required"), &p(3, "required"))?;
                let size = to_int(&i.raw("size"), &p(4, "size"))?;
                let default = to_opt_array(&i.raw("default"), &p(5, "default"))?;
                vector(&key, table, required, size, default)
            }
            FieldClass::Relationship => {
                let o = |k: &str| -> Value {
                    match i.get("options") {
                        Some(Value::Array(a)) => a.get_str(k).cloned().unwrap_or(Value::Null),
                        _ => Value::Null,
                    }
                };
                let related = match o("relatedTable") {
                    Value::Null => o("relatedCollection"),
                    v => v,
                };
                let related = to_string(&related, &p(3, "relatedTable"))?;
                let relation_type = to_string(&o("relationType"), &p(4, "relationType"))?;
                let two_way = to_bool(&o("twoWay"), &p(5, "twoWay"))?;
                let two_way_key = to_opt_string(&o("twoWayKey"), &p(6, "twoWayKey"))?;
                let on_delete = to_string(&o("onDelete"), &p(7, "onDelete"))?;
                let side = to_string(&o("side"), &p(8, "side"))?;
                relationship(&key, table, &related, &relation_type, two_way, two_way_key, &on_delete, &side)
            }
            FieldClass::Attribute => return Err(Error::InvalidArgument("Attribute has no fromArray()".into())),
        };
        Ok(field.resource(created()?, updated()?))
    }
}

/// An index of a table.
///
/// PHP: `Utopia\Migration\Resources\Database\Index` (`TYPE_*` are associated constants).
#[derive(Debug)]
pub struct Index {
    /// The key.
    pub key: String,
    /// The table.
    pub table: ResourceRef,
    /// The type (`getType()`).
    pub index_type: String,
    /// The indexed columns.
    pub columns: Array,
    /// The prefix length of each column.
    pub lengths: Array,
    /// The order of each column.
    pub orders: Array,
}

impl Index {
    /// A unique index.
    pub const TYPE_UNIQUE: &'static str = "unique";
    /// A full text index.
    pub const TYPE_FULLTEXT: &'static str = "fulltext";
    /// A plain index.
    pub const TYPE_KEY: &'static str = "key";
    /// A spatial index.
    pub const TYPE_SPATIAL: &'static str = "spatial";

    /// A resource of this index.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::Index(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `Index::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Database\\Index::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let key = to_string(&i.raw("key"), &p(2, "key"))?;
        let table_array = i.get("table").or_else(|| i.get("collection")).cloned().unwrap_or(Value::Null);
        let table = Table::from_array(&to_array(
            &table_array,
            &param("Utopia\\Migration\\Resources\\Database\\Table::fromArray", 1, "array"),
        )?)?;
        let index_type = to_string(&i.raw("type"), &p(4, "type"))?;
        let columns = to_array(&i.either("columns", "attributes"), &p(5, "columns"))?;
        let lengths = to_array(&i.or("lengths", Value::Array(Array::new())), &p(6, "lengths"))?;
        let orders = to_array(&i.or("orders", Value::Array(Array::new())), &p(7, "orders"))?;
        let created = to_string(&i.or("createdAt", Value::from("")), &p(8, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(9, "updatedAt"))?;
        Ok(Index { key, table, index_type, columns, lengths, orders }.resource(id, created, updated))
    }
}

/// A row of a table (or a document of a collection).
///
/// PHP: `Utopia\Migration\Resources\Database\Row` and `Document`.
#[derive(Debug)]
pub struct Row {
    /// Whether this is a `Document`.
    pub document: bool,
    /// The table.
    pub table: ResourceRef,
    /// The row's attributes.
    pub data: Array,
}

impl Row {
    /// A resource of this row.
    pub fn resource(self, id: impl Into<String>, permissions: Vec<String>) -> ResourceRef {
        Resource::new(Kind::Row(self), Base { id: id.into(), permissions, ..Base::default() })
    }

    /// PHP: `Row::fromArray()` (`document: false`) and `Document::fromArray()`,
    /// whose table is a `Collection`.
    pub fn from_array(document: bool, array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let class = if document {
            "Utopia\\Migration\\Resources\\Database\\Document::__construct"
        } else {
            "Utopia\\Migration\\Resources\\Database\\Row::__construct"
        };
        let p = |n, name| param(class, n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let table_array = i.get("table").or_else(|| i.get("collection")).cloned().unwrap_or(Value::Null);
        let table = if document {
            Table::collection_from_array(&to_array(
                &table_array,
                &param("Utopia\\Migration\\Resources\\Database\\Collection::fromArray", 1, "array"),
            )?)?
        } else {
            Table::from_array(&to_array(
                &table_array,
                &param("Utopia\\Migration\\Resources\\Database\\Table::fromArray", 1, "array"),
            )?)?
        };
        let data = to_array(&i.raw("data"), &p(3, "data"))?;
        let permissions = to_array(&i.or("permissions", Value::Array(Array::new())), &p(4, "permissions"))?;
        Ok(Row { document, table, data }.resource(id, super::strings(&permissions)))
    }
}
