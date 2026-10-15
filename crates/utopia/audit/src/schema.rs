//! The schema of audit logs shared by the SQL adapters: their attributes,
//! indexes and column definitions, and how a resource path splits into its
//! type, id and parent.
//!
//! PHP: `Utopia\Audit\Adapter\SQL`, the abstract base of the Database and
//! ClickHouse adapters.

use std::sync::LazyLock;

use utopia_database::filter::Filter;
pub use utopia_database::{Attribute, Index};

use crate::{Error, Result};

/// The collection (Database) or base table name of audit logs.
///
/// PHP: `SQL::COLLECTION`.
pub const COLLECTION: &str = "audit";

/// `Database::LENGTH_KEY`, the size of a string attribute by default.
pub const LENGTH_KEY: i64 = utopia_database::database::LENGTH_KEY;

/// `Attribute::string(key: $key, required: $required)`.
fn string(key: &str, required: bool) -> Attribute {
    Attribute::string(key, LENGTH_KEY).required(required)
}

/// `Index::key(key: $key, attributes: $attributes)`.
fn key(key: &str, attributes: &[&str]) -> Index {
    Index::key(key, attributes.iter().copied(), Vec::new(), Vec::new()).expect("a key index without orders is valid")
}

/// The attributes of the Database adapter (and the base of ClickHouse's).
///
/// PHP: `SQL::getAttributes()`.
pub static BASE_ATTRIBUTES: LazyLock<Vec<Attribute>> = LazyLock::new(|| {
    vec![
        string("userId", false),
        string("event", true),
        string("resource", false),
        Attribute::string("userAgent", 65534).required(true),
        Attribute::string("ip", 45).required(true),
        Attribute::datetime("time"),
        Attribute::string("data", 16777216).filters([Filter::Json]),
    ]
});

/// The indexes of the Database adapter (and the base of ClickHouse's).
///
/// PHP: `SQL::getIndexes()`.
pub static BASE_INDEXES: LazyLock<Vec<Index>> = LazyLock::new(|| {
    vec![
        key("idx_event", &["event"]),
        key("idx_userId_event", &["userId", "event"]),
        key("idx_resource_event", &["resource", "event"]),
        key("idx_time_desc", &["time"]),
    ]
});

/// The ClickHouse attributes: the base ones (`userId` renamed `actorId`)
/// and the actor, resource, project, geo, SDK and user-agent columns.
///
/// PHP: `ClickHouse::getAttributes()`.
pub static CLICKHOUSE_ATTRIBUTES: LazyLock<Vec<Attribute>> = LazyLock::new(|| {
    let mut attributes = BASE_ATTRIBUTES.clone();
    if let Some(user) = attributes.iter_mut().find(|a| a.key == "userId") {
        user.key = "actorId".to_owned();
    }
    attributes.extend(
        [
            ("actorType", true),
            ("actorInternalId", false),
            ("resourceParent", false),
            ("resourceType", true),
            ("resourceId", true),
            ("resourceInternalId", false),
            ("country", false),
            ("city", false),
            ("continentCode", false),
            ("subdivisions", false),
            ("isp", false),
            ("autonomousSystemNumber", false),
            ("autonomousSystemOrganization", false),
            ("connectionType", false),
            ("connectionUsageType", false),
            ("connectionOrganization", false),
            ("projectId", true),
            ("projectInternalId", true),
            ("teamId", true),
            ("teamInternalId", true),
            ("hostname", true),
            ("sdk", false),
            ("sdkVersion", false),
            ("osCode", false),
            ("osName", false),
            ("osVersion", false),
            ("clientType", false),
            ("clientCode", false),
            ("clientName", false),
            ("clientVersion", false),
            ("clientEngine", false),
            ("clientEngineVersion", false),
            ("deviceName", false),
            ("deviceBrand", false),
            ("deviceModel", false),
        ]
        .into_iter()
        .map(|(id, required)| string(id, required)),
    );
    attributes
});

/// The ClickHouse indexes: the base ones (`idx_userId_event` as
/// `idx_actorId_event` on `actorId, event`) and its own.
///
/// PHP: `ClickHouse::getIndexes()`.
pub static CLICKHOUSE_INDEXES: LazyLock<Vec<Index>> = LazyLock::new(|| {
    let mut indexes = BASE_INDEXES.clone();
    if let Some(index) = indexes.iter_mut().find(|i| i.key == "idx_userId_event") {
        index.key = "idx_actorId_event".to_owned();
        index.attributes = vec!["actorId".to_owned(), "event".to_owned()];
    }
    indexes.extend([
        key("_key_actor_internal_and_event", &["actorInternalId", "event"]),
        key("_key_project_internal_id", &["projectInternalId"]),
        key("_key_team_internal_id", &["teamInternalId"]),
        key("_key_actor_internal_id", &["actorInternalId"]),
        key("_key_actor_type", &["actorType"]),
        key("_key_country", &["country"]),
        key("_key_hostname", &["hostname"]),
        key("_key_sdk", &["sdk"]),
    ]);
    indexes
});

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
        self.attributes().iter().find(|a| a.key == id)
    }

    /// The attributes, for `createCollection()`.
    ///
    /// PHP: `getAttributeDocuments()`.
    fn attribute_documents(&self) -> Vec<Attribute> {
        self.attributes().to_vec()
    }

    /// The indexes, for `createCollection()`.
    ///
    /// PHP: `getIndexDocuments()`.
    fn index_documents(&self) -> Vec<Index> {
        self.indexes().to_vec()
    }

    /// Every column definition, in attribute order.
    ///
    /// PHP: `getAllColumnDefinitions()` (protected).
    fn column_definitions(&self) -> Result<Vec<String>> {
        self.attributes().iter().map(|a| self.column_definition(&a.key)).collect()
    }
}

/// `Attribute <id> not found`.
#[cfg_attr(not(any(feature = "database", feature = "clickhouse")), allow(dead_code))]
pub(crate) fn not_found(id: &str) -> Error {
    Error::exception(format!("Attribute {id} not found"))
}
