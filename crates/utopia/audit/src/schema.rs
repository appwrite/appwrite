//! The schema of audit logs shared by the SQL adapters: their attributes,
//! indexes and column definitions, and how a resource path splits into its
//! type, id and parent.
//!
//! PHP: `Utopia\Audit\Adapter\SQL`, the abstract base of the Database and
//! ClickHouse adapters.

use php_std::{Array, ArrayKey, Str, Value};

use crate::{Error, Result};

/// The collection (Database) or base table name of audit logs.
///
/// PHP: `SQL::COLLECTION`.
pub const COLLECTION: &str = "audit";

/// `Database::VAR_STRING`.
pub const VAR_STRING: &str = "string";

/// `Database::VAR_DATETIME`.
pub const VAR_DATETIME: &str = "datetime";

/// `Database::LENGTH_KEY`.
pub const LENGTH_KEY: i64 = 255;

/// How PHP orders the keys of an attribute definition (its array literal).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Layout {
    /// `$id, type, size, required, signed, array, filters`.
    Base,
    /// `$id, type, format, size, signed, required, array, filters` (`time`).
    Time,
    /// `$id, type, size, required, default, signed, array, filters`.
    Default,
    /// `$id, type, format, size, signed, required, default, array, filters`.
    Format,
}

/// One attribute (column) of audit logs.
///
/// PHP: an element of `getAttributes()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Attribute {
    /// The attribute id (`$id`), which is also the column name.
    pub id: &'static str,
    /// Its type: [`VAR_STRING`] or [`VAR_DATETIME`].
    pub kind: &'static str,
    /// The size (0 for dates).
    pub size: i64,
    /// Whether every log must have it.
    pub required: bool,
    /// Whether it is signed (always `true`).
    pub signed: bool,
    /// Whether it is an array (always `false`).
    pub array: bool,
    /// The database filters (`datetime`, `json`).
    pub filters: &'static [&'static str],
    layout: Layout,
}

impl Attribute {
    const fn new(id: &'static str, kind: &'static str, size: i64, required: bool, layout: Layout) -> Attribute {
        Attribute { id, kind, size, required, signed: true, array: false, filters: &[], layout }
    }

    const fn string(id: &'static str, size: i64, required: bool) -> Attribute {
        Attribute::new(id, VAR_STRING, size, required, Layout::Base)
    }

    /// A ClickHouse extension, with `default => null`.
    const fn extra(id: &'static str, required: bool) -> Attribute {
        Attribute::new(id, VAR_STRING, LENGTH_KEY, required, Layout::Default)
    }

    /// A ClickHouse extension, with `format => ''` and `default => null`.
    const fn formatted(id: &'static str, required: bool) -> Attribute {
        Attribute::new(id, VAR_STRING, LENGTH_KEY, required, Layout::Format)
    }

    const fn filtered(mut self, filters: &'static [&'static str]) -> Attribute {
        self.filters = filters;
        self
    }

    /// The definition as PHP's array, keys in PHP's order.
    pub fn to_array(&self) -> Array {
        let mut out = Array::new();
        let mut put = |key: &str, value: Value| {
            out.set(ArrayKey::normalize(Str::from(key)), value);
        };
        let filters = || Value::Array(Array::from_list(self.filters.iter().map(|f| Value::from(*f)).collect()));
        put("$id", Value::from(self.id));
        put("type", Value::from(self.kind));
        match self.layout {
            Layout::Base => {
                put("size", Value::Int(self.size));
                put("required", Value::Bool(self.required));
            }
            Layout::Time | Layout::Format => {
                put("format", Value::from(""));
                put("size", Value::Int(self.size));
                put("signed", Value::Bool(self.signed));
                put("required", Value::Bool(self.required));
            }
            Layout::Default => {
                put("size", Value::Int(self.size));
                put("required", Value::Bool(self.required));
                put("default", Value::Null);
            }
        }
        match self.layout {
            Layout::Base => put("signed", Value::Bool(self.signed)),
            Layout::Default => put("signed", Value::Bool(self.signed)),
            Layout::Format => put("default", Value::Null),
            Layout::Time => {}
        }
        put("array", Value::Bool(self.array));
        put("filters", filters());
        out
    }
}

/// One index of audit logs.
///
/// PHP: an element of `getIndexes()`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Index {
    /// The index id (`$id`).
    pub id: &'static str,
    /// The index type (`key`).
    pub kind: &'static str,
    /// The attributes it covers, in order.
    pub attributes: &'static [&'static str],
    /// Whether the definition carries (empty) `lengths` and `orders`.
    extended: bool,
}

impl Index {
    const fn key(id: &'static str, attributes: &'static [&'static str]) -> Index {
        Index { id, kind: "key", attributes, extended: false }
    }

    const fn extended(id: &'static str, attributes: &'static [&'static str]) -> Index {
        Index { id, kind: "key", attributes, extended: true }
    }

    /// The definition as PHP's array, keys in PHP's order.
    pub fn to_array(&self) -> Array {
        let mut out = Array::new();
        let mut put = |key: &str, value: Value| {
            out.set(ArrayKey::normalize(Str::from(key)), value);
        };
        put("$id", Value::from(self.id));
        put("type", Value::from(self.kind));
        put("attributes", Value::Array(Array::from_list(self.attributes.iter().map(|a| Value::from(*a)).collect())));
        if self.extended {
            put("lengths", Value::Array(Array::new()));
            put("orders", Value::Array(Array::new()));
        }
        out
    }
}

/// The attributes of the Database adapter (and the base of ClickHouse's).
pub static BASE_ATTRIBUTES: [Attribute; 7] = [
    Attribute::string("userId", LENGTH_KEY, false),
    Attribute::string("event", 255, true),
    Attribute::string("resource", 255, false),
    Attribute::string("userAgent", 65534, true),
    Attribute::string("ip", 45, true),
    Attribute::new("time", VAR_DATETIME, 0, false, Layout::Time).filtered(&["datetime"]),
    Attribute::string("data", 16777216, false).filtered(&["json"]),
];

/// The indexes of the Database adapter (and the base of ClickHouse's).
pub static BASE_INDEXES: [Index; 4] = [
    Index::key("idx_event", &["event"]),
    Index::key("idx_userId_event", &["userId", "event"]),
    Index::key("idx_resource_event", &["resource", "event"]),
    Index::key("idx_time_desc", &["time"]),
];

/// The ClickHouse attributes: the base ones (`userId` renamed `actorId`)
/// and the actor, resource, project, geo, SDK and user-agent columns.
pub static CLICKHOUSE_ATTRIBUTES: [Attribute; 42] = [
    Attribute::string("actorId", LENGTH_KEY, false),
    Attribute::string("event", 255, true),
    Attribute::string("resource", 255, false),
    Attribute::string("userAgent", 65534, true),
    Attribute::string("ip", 45, true),
    Attribute::new("time", VAR_DATETIME, 0, false, Layout::Time).filtered(&["datetime"]),
    Attribute::string("data", 16777216, false).filtered(&["json"]),
    Attribute::extra("actorType", true),
    Attribute::extra("actorInternalId", false),
    Attribute::extra("resourceParent", false),
    Attribute::extra("resourceType", true),
    Attribute::extra("resourceId", true),
    Attribute::extra("resourceInternalId", false),
    Attribute::extra("country", false),
    Attribute::extra("city", false),
    Attribute::extra("continentCode", false),
    Attribute::extra("subdivisions", false),
    Attribute::extra("isp", false),
    Attribute::extra("autonomousSystemNumber", false),
    Attribute::extra("autonomousSystemOrganization", false),
    Attribute::extra("connectionType", false),
    Attribute::extra("connectionUsageType", false),
    Attribute::extra("connectionOrganization", false),
    Attribute::formatted("projectId", true),
    Attribute::formatted("projectInternalId", true),
    Attribute::formatted("teamId", true),
    Attribute::formatted("teamInternalId", true),
    Attribute::formatted("hostname", true),
    Attribute::formatted("sdk", false),
    Attribute::formatted("sdkVersion", false),
    Attribute::formatted("osCode", false),
    Attribute::formatted("osName", false),
    Attribute::formatted("osVersion", false),
    Attribute::formatted("clientType", false),
    Attribute::formatted("clientCode", false),
    Attribute::formatted("clientName", false),
    Attribute::formatted("clientVersion", false),
    Attribute::formatted("clientEngine", false),
    Attribute::formatted("clientEngineVersion", false),
    Attribute::formatted("deviceName", false),
    Attribute::formatted("deviceBrand", false),
    Attribute::formatted("deviceModel", false),
];

/// The ClickHouse indexes: the base ones (`idx_userId_event` as
/// `idx_actorId_event`) and its own.
pub static CLICKHOUSE_INDEXES: [Index; 12] = [
    Index::key("idx_event", &["event"]),
    Index::key("idx_actorId_event", &["actorId", "event"]),
    Index::key("idx_resource_event", &["resource", "event"]),
    Index::key("idx_time_desc", &["time"]),
    Index::extended("_key_actor_internal_and_event", &["actorInternalId", "event"]),
    Index::extended("_key_project_internal_id", &["projectInternalId"]),
    Index::extended("_key_team_internal_id", &["teamInternalId"]),
    Index::extended("_key_actor_internal_id", &["actorInternalId"]),
    Index::extended("_key_actor_type", &["actorType"]),
    Index::extended("_key_country", &["country"]),
    Index::extended("_key_hostname", &["hostname"]),
    Index::extended("_key_sdk", &["sdk"]),
];

/// The parts of a resource path (`database/<id>/collection/<id>`).
///
/// PHP: the array `parseResource()` returns.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Resource<'a> {
    /// The last segment, or the whole resource when it is not made of
    /// `<type>/<id>` pairs.
    pub id: &'a str,
    /// The second-to-last segment, or `""`.
    pub kind: &'a str,
    /// The segments before the type, joined with `/`, or `""`.
    pub parent: &'a str,
}

/// Splits `resource` into its id, type and parent: any even number of
/// `/`-separated segments alternate `<type>/<id>`; anything else is an id
/// alone.
///
/// ```
/// use utopia_audit::schema::parse_resource;
///
/// let r = parse_resource("database/db1/table/t1");
/// assert_eq!((r.kind, r.id, r.parent), ("table", "t1", "database/db1"));
/// assert_eq!(parse_resource("foo/bar/baz").id, "foo/bar/baz");
/// ```
///
/// ```php
/// // parseResource() is protected; the ClickHouse adapter fills
/// // resourceType, resourceId and resourceParent with it.
/// $parse = new ReflectionMethod(Utopia\Audit\Adapter\SQL::class, 'parseResource');
/// $adapter = new Utopia\Audit\Adapter\ClickHouse('clickhouse');
/// print_r($parse->invoke($adapter, 'database/db1/table/t1'));
/// // [resourceId => t1, resourceType => table, resourceParent => database/db1]
/// ```
///
/// PHP: `SQL::parseResource()`.
pub fn parse_resource(resource: &str) -> Resource<'_> {
    let count = resource.split('/').count();
    if count < 2 || !count.is_multiple_of(2) {
        return Resource { id: resource, kind: "", parent: "" };
    }
    let last = resource.rfind('/').unwrap_or(0);
    let id = &resource[last + 1..];
    let head = &resource[..last];
    let (parent, kind) = match head.rfind('/') {
        Some(at) => (&head[..at], &head[at + 1..]),
        None => ("", head),
    };
    Resource { id, kind, parent }
}

/// What an adapter that stores logs in SQL tables shares: the attribute and
/// index definitions and their column definitions.
///
/// PHP: `Utopia\Audit\Adapter\SQL`.
pub trait Sql {
    /// The attributes, in order.
    ///
    /// PHP: `getAttributes()`.
    fn attributes(&self) -> &'static [Attribute];

    /// The indexes, in order.
    ///
    /// PHP: `getIndexes()`.
    fn indexes(&self) -> &'static [Index];

    /// The backend's definition of the column of attribute `id`.
    ///
    /// Fails with `Attribute <id> not found` for an unknown attribute.
    ///
    /// PHP: `getColumnDefinition($id)` (protected).
    fn column_definition(&self, id: &str) -> Result<String>;

    /// The collection or base table name.
    ///
    /// PHP: `getCollectionName()`.
    fn collection_name(&self) -> &'static str {
        COLLECTION
    }

    /// The attribute `id`, if defined.
    ///
    /// PHP: `getAttribute($id)` (protected).
    fn attribute(&self, id: &str) -> Option<&'static Attribute> {
        self.attributes().iter().find(|a| a.id == id)
    }

    /// The attribute definitions as database documents, for
    /// `createCollection()`.
    ///
    /// PHP: `getAttributeDocuments()`.
    #[cfg(feature = "database")]
    fn attribute_documents(&self) -> Result<Vec<utopia_database::Document>> {
        self.attributes().iter().map(|a| document(a.to_array())).collect()
    }

    /// The index definitions as database documents, for `createCollection()`.
    ///
    /// PHP: `getIndexDocuments()`.
    #[cfg(feature = "database")]
    fn index_documents(&self) -> Result<Vec<utopia_database::Document>> {
        self.indexes().iter().map(|i| document(i.to_array())).collect()
    }

    /// Every column definition, in attribute order.
    ///
    /// PHP: `getAllColumnDefinitions()` (protected).
    fn column_definitions(&self) -> Result<Vec<String>> {
        self.attributes().iter().map(|a| self.column_definition(a.id)).collect()
    }
}

/// A database document of a definition array.
#[cfg(feature = "database")]
fn document(definition: Array) -> Result<utopia_database::Document> {
    Ok(utopia_database::Document::new(crate::adapter::database::db_array(&definition))?)
}

/// `Attribute <id> not found`.
#[cfg_attr(not(any(feature = "database", feature = "clickhouse")), allow(dead_code))]
pub(crate) fn not_found(id: &str) -> Error {
    Error::exception(format!("Attribute {id} not found"))
}
