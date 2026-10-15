//! Databases, tables, columns, indexes and rows, written to the project's
//! databases.

use std::collections::HashSet;

use php_std::{Array, ArrayKey, Value};
use utopia_database::attribute::{Format, Patch, Update as AttributeUpdate};
use utopia_database::database::{BATCH_SIZE, LENGTH_KEY, MAX_ARRAY_INDEX_LENGTH, METADATA};
use utopia_database::relationship::{RelationshipDeleteAction, RelationshipSide, Update as RelationshipUpdate};
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::validator::structure::formats;
use utopia_database::validator::uid::Uid;
use utopia_database::{
    Attribute, Capability, Collection, Database as Db, Document, Index, Kind as DbKind, Permission, PermissionType,
    Query, Relationship, id,
};
use utopia_query::schema::ColumnType;

use super::{Appwrite, Orphans, normalize_date_time};
use crate::destinations::on_duplicate::{OnDuplicate, SchemaAction};
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{Kind, ResourceRef, status, types};
use crate::resources::database::{DatabaseFlavor, column};
use crate::resources::{cast_bool, cast_int, cast_string};

const META_DATABASES: &str = "databases";
const META_ATTRIBUTES: &str = "attributes";
const META_INDEXES: &str = "indexes";
const VECTORSDB_EMBEDDINGS_KEY: &str = "embeddings";
const DATABASE_STATUS_PROVISIONING: &str = "provisioning";
const DATABASE_STATUS_READY: &str = "ready";
const DATABASE_STATUS_FAILED: &str = "failed";
/// The type is immutable too, but is compared through `type_matches()`.
const ATTRIBUTE_IMMUTABLE_FIELDS: [&str; 5] = ["array", "signed", "format", "formatOptions", "filters"];
const RELATIONSHIP_IMMUTABLE_FIELDS: [&str; 4] = ["relationType", "twoWay", "twoWayKey", "relatedCollection"];

type DbValue = utopia_database::Value;
type DbArray = utopia_database::Array;

/// A migration value as a database value.
pub(super) fn db_value(v: &Value) -> DbValue {
    utopia_database::value::from_plain(v.clone())
}

/// A database value as a migration value.
pub(super) fn plain_value(v: &DbValue) -> Value {
    utopia_database::value::to_plain(v)
}

pub(super) fn db_array(a: &Array) -> DbArray {
    match db_value(&Value::Array(a.clone())) {
        DbValue::Array(a) => a,
        _ => DbArray::new(),
    }
}

pub(super) fn doc(pairs: Vec<(&str, DbValue)>) -> Result<Document> {
    let mut a = DbArray::new();
    for (k, v) in pairs {
        a.set(k.into(), v);
    }
    Ok(Document::new(a)?)
}

pub(super) fn s(v: &str) -> DbValue {
    DbValue::from(v)
}

fn strings(v: &[String]) -> DbValue {
    DbValue::Array(DbArray::from_list(v.iter().map(|s| DbValue::from(s.as_str())).collect()))
}

/// `$document->getSequence()` in a string context.
pub(super) fn seq(d: &Document) -> String {
    d.sequence().map(|v| cast_string(&plain_value(v))).unwrap_or_default()
}

/// `$document->getAttribute($key)` as a migration value (`null` when unset).
pub(super) fn attr(d: &Document, key: &str) -> Value {
    d.get(key).map(plain_value).unwrap_or(Value::Null)
}

/// PHP `empty()` of a string.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

fn database_collection_id(database: &Document) -> String {
    format!("database_{}", seq(database))
}

fn table_collection_id(database: &Document, table: &Document) -> String {
    format!("{}_collection_{}", database_collection_id(database), seq(table))
}

fn meta_id(database: &Document, table: &Document, key: &str) -> String {
    format!("{}_{}_{key}", seq(database), seq(table))
}

fn table_identity(database: &Document, table: &Document) -> String {
    format!("{}:{}", seq(database), seq(table))
}

/// `$a === $b`, associative arrays compared with their keys sorted.
fn values_match(a: &Value, b: &Value) -> bool {
    match (a, b) {
        (Value::Array(x), Value::Array(y)) if !x.is_list() && !y.is_list() => {
            let mut x = x.clone();
            let mut y = y.clone();
            php_std::sort::ksort(&mut x, php_std::sort::SortFlags::from_php(0));
            php_std::sort::ksort(&mut y, php_std::sort::SortFlags::from_php(0));
            Value::Array(x).strict_eq(&Value::Array(y))
        }
        _ => a.strict_eq(b),
    }
}

fn get_or_null(a: &Array, key: &str) -> Value {
    a.get_str(key).cloned().unwrap_or(Value::Null)
}

fn arrays_differ_on_keys(a: &Array, b: &Array, keys: &[&str]) -> bool {
    keys.iter().any(|k| !values_match(&get_or_null(a, k), &get_or_null(b, k)))
}

fn options_of(d: &Document) -> Array {
    match attr(d, "options") {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

/// The stored type a column of resource type `t` has in the database.
///
/// PHP: `schemaColumnType($resource)` (private).
fn database_type(t: &str) -> Result<&'static str> {
    let kind = match t {
        column::DATETIME => ColumnType::Datetime,
        column::BOOLEAN => ColumnType::Boolean,
        column::INTEGER => ColumnType::Integer,
        column::BIG_INT => ColumnType::BigInteger,
        column::FLOAT => ColumnType::Double,
        column::RELATIONSHIP => ColumnType::Relationship,
        column::STRING | column::IP | column::EMAIL | column::URL | column::ENUM => ColumnType::String,
        column::POINT => ColumnType::Point,
        column::LINE => ColumnType::Linestring,
        column::POLYGON => ColumnType::Polygon,
        column::TEXT => ColumnType::Text,
        column::VARCHAR => ColumnType::Varchar,
        column::MEDIUMTEXT => ColumnType::MediumText,
        column::LONGTEXT => ColumnType::LongText,
        column::OBJECT => ColumnType::Object,
        column::VECTOR => ColumnType::Vector,
        _ => return Err(Error::exception(format!("Invalid resource type {t}"), Exception::CODE_VALIDATION)),
    };
    Ok(Attribute::stored_type(kind))
}

const RELATIONSHIP: &str = "relationship";
const VECTOR: &str = "vector";

/// `$document->getAttribute($key, $default)`: `default` when unset or
/// `null`.
fn attr_or(d: &Document, key: &str, default: Value) -> Value {
    match attr(d, key) {
        Value::Null => default,
        v => v,
    }
}

/// A big integer is stored under either spelling, so an unchanged type is
/// not always an identical string.
///
/// PHP: `typeMatches()` (private).
fn type_matches(existing: &Value, t: &str) -> bool {
    let Value::Str(existing) = existing else { return false };
    let existing = String::from_utf8_lossy(existing.as_bytes());
    if existing == t {
        return true;
    }
    match (Attribute::type_from_stored(&existing), Attribute::type_from_stored(t)) {
        (Ok(a), Ok(b)) => a == b,
        _ => false,
    }
}

/// The attribute a column resource describes.
///
/// PHP: `resourceAttribute($resource, $type)` (private).
fn resource_attribute(f: &crate::resources::database::Field, t: &str) -> utopia_database::Result<Attribute> {
    let options = f.options();
    let mut a = Array::new();
    a.set("key".into(), Value::from(f.key.as_str()));
    a.set("type".into(), Value::from(t));
    a.set("size".into(), Value::Int(f.size));
    a.set("required".into(), Value::Bool(f.required));
    a.set("signed".into(), Value::Bool(f.signed));
    a.set("default".into(), f.default.clone());
    a.set("array".into(), Value::Bool(f.array));
    a.set("format".into(), Value::from(f.format.as_str()));
    a.set("formatOptions".into(), Value::Array(f.format_options.clone()));
    a.set(
        "filters".into(),
        Value::Array(Array::from_list(f.filters.iter().map(|s| Value::from(s.as_str())).collect())),
    );
    a.set("options".into(), if options.is_empty() { Value::Null } else { Value::Array(options) });
    Attribute::from_array(&db_array(&a))
}

/// The deployed attribute and the wanted one, both normalised; `None` when
/// either is not a valid attribute.
///
/// PHP: `normalisedAttributes()` (private).
fn normalised_attributes(
    existing: &Document,
    f: &crate::resources::database::Field,
    t: &str,
) -> Option<(Attribute, Attribute)> {
    let deployed = Attribute::from_document(existing).and_then(|a| a.apply(&AttributeUpdate::default())).ok()?;
    let wanted = resource_attribute(f, t).and_then(|a| a.apply(&AttributeUpdate::default())).ok()?;
    Some((deployed, wanted))
}

/// The fields an in-place update cannot change; `formatOptions` raw (an
/// integer carries its bounds there without naming a format).
///
/// PHP: `immutableFields()` (private).
fn immutable_fields(attribute: &Attribute, format_options: Value) -> Array {
    let mut a = Array::new();
    a.set("array".into(), Value::Bool(attribute.array));
    a.set("signed".into(), Value::Bool(attribute.signed));
    a.set("format".into(), attribute.format.as_ref().map_or(Value::Null, |f| Value::from(f.name.as_str())));
    a.set("formatOptions".into(), format_options);
    a.set(
        "filters".into(),
        Value::Array(Array::from_list(attribute.filters.iter().map(|s| Value::from(s.as_str())).collect())),
    );
    a
}

/// PHP: `immutableFieldsMatch()` (private).
fn immutable_fields_match(
    existing: &Document,
    deployed: &Attribute,
    f: &crate::resources::database::Field,
    wanted: &Attribute,
) -> bool {
    !arrays_differ_on_keys(
        &immutable_fields(deployed, attr_or(existing, "formatOptions", Value::Array(Array::new()))),
        &immutable_fields(wanted, Value::Array(f.format_options.clone())),
        &ATTRIBUTE_IMMUTABLE_FIELDS,
    )
}

/// Only what the index type stores, one length and order per column.
///
/// PHP: `indexShape()` (private).
fn index_shape(index: &Index) -> Value {
    let mut shape = plain_value(&DbValue::Array(index.to_document().array_copy()));
    if let Value::Array(a) = &mut shape {
        a.remove(&"$id".into());
        a.remove(&"key".into());
        let positions = index.attributes.len();
        if a.contains_key(&"lengths".into()) {
            let lengths = (0..positions).map(|p| Value::Int(index.lengths.get(p).copied().flatten().unwrap_or(0)));
            a.set("lengths".into(), Value::Array(Array::from_list(lengths.collect())));
        }
        if a.contains_key(&"orders".into()) {
            let orders: Vec<Value> = match a.get_str("orders") {
                Some(Value::Array(o)) => o.values().cloned().collect(),
                _ => Vec::new(),
            };
            let orders = (0..positions).map(|p| orders.get(p).cloned().unwrap_or(Value::Null));
            a.set("orders".into(), Value::Array(Array::from_list(orders.collect())));
        }
    }
    shape
}

fn database_of(r: &ResourceRef) -> Option<ResourceRef> {
    r.database().cloned()
}

fn database_type_of(r: &ResourceRef) -> String {
    r.database().and_then(|d| d.as_database().map(|d| d.database_type.clone())).unwrap_or_default()
}

/// PHP `array_merge($a, $b)`: string keys of `b` replace, integer keys are
/// appended and renumbered.
fn array_merge(a: DbArray, b: &DbArray) -> DbArray {
    let mut out = DbArray::new();
    for (k, v) in a.iter().chain(b.iter()) {
        match k {
            php_std::types::KeyRef::Int(_) => {
                let _ = out.push(v.clone());
            }
            php_std::types::KeyRef::Str(_) => {
                out.set(k.to_owned(), v.clone());
            }
        }
    }
    out
}

impl Appwrite {
    async fn resolve(&self, database: &Document) -> Db {
        (self.databases_db)(database)
    }

    /// Whether the destination manages database status: the source does,
    /// and the project's `databases` collection has a `status` attribute.
    ///
    /// PHP: `getSupportForDatabaseStatus()` (private).
    async fn support_for_database_status(&mut self) -> Result<bool> {
        if self.source_status != Some(true) {
            return Ok(false);
        }
        if let Some(s) = self.database_status_supported {
            return Ok(s);
        }
        let supported = match self.db_for_project.find_collection(META_DATABASES).await? {
            Some(collection) => collection.attributes()?.iter().any(|a| a.key == "status"),
            None => false,
        };
        self.database_status_supported = Some(supported);
        Ok(supported)
    }

    async fn set_database_status(&mut self, database_id: &str, value: &str) -> Result<()> {
        if !self.support_for_database_status().await? {
            return Ok(());
        }
        self.db_for_project.update_document(META_DATABASES, database_id, doc(vec![("status", s(value))])?).await?;
        Ok(())
    }

    async fn mark_database_failed(&mut self, database_id: &str) {
        let _ = self.set_database_status(database_id, DATABASE_STATUS_FAILED).await;
    }

    pub(super) async fn mark_provisioned_databases_ready(&mut self) {
        let ids: Vec<String> = self.provisioning_databases.keys().cloned().collect();
        for id in ids {
            if let Err(e) = self.set_database_status(&id, DATABASE_STATUS_READY).await {
                let code = e.code();
                self.target.add_error(Exception::new(
                    types::DATABASE,
                    crate::transfer::group::DATABASES,
                    Some(id.clone()),
                    e.message(),
                    code,
                    Some(e),
                ));
            }
        }
    }

    /// The structure of the metadata collection of `r`'s database type, as
    /// attribute and index models.
    ///
    /// PHP: `collectionStructureFor()`, `schemaAttributes()` and
    /// `schemaIndexes()` (private).
    fn structure_for(&self, r: &ResourceRef) -> utopia_database::Result<(Vec<Attribute>, Vec<Index>)> {
        let t = r.as_database().map(|d| d.database_type.clone()).unwrap_or_default();
        let structure = match self.collection_structures.get_str(&t) {
            Some(Value::Array(a)) => a.clone(),
            Some(Value::Null) | None => self.collection_structure.clone(),
            Some(_) => Array::new(),
        };
        let arrays = |key: &str| -> Vec<DbArray> {
            match structure.get_str(key) {
                Some(Value::Array(list)) => list
                    .values()
                    .map(|v| match v {
                        Value::Array(a) => db_array(a),
                        _ => DbArray::new(),
                    })
                    .collect(),
                _ => Vec::new(),
            }
        };
        let attributes =
            arrays("attributes").iter().map(Attribute::from_array).collect::<utopia_database::Result<_>>()?;
        let indexes = arrays("indexes").iter().map(Index::from_array).collect::<utopia_database::Result<_>>()?;
        Ok((attributes, indexes))
    }

    /// Creates the collection of `database`'s tables.
    async fn create_database_collection(&self, r: &ResourceRef, database: &Document) -> Result<()> {
        let (attributes, indexes) = self.structure_for(r)?;
        let collection =
            Collection::create(database_collection_id(database), "", attributes, indexes, None, true, DbArray::new())?;
        self.db_for_project.create_collection(collection).await?;
        Ok(())
    }

    fn database_spec_matches(&self, existing: &Document, r: &ResourceRef) -> bool {
        let Some(d) = r.as_database() else { return false };
        let source_type = if empty(&d.database_type) { "legacy".to_owned() } else { d.database_type.clone() };
        let original = r.original_id();
        let source_original = if empty(&original) { Value::Null } else { Value::from(original.as_str()) };
        attr(existing, "name").strict_eq(&Value::from(d.name.as_str()))
            && attr(existing, "enabled").strict_eq(&Value::Bool(d.enabled))
            && attr(existing, "type").strict_eq(&Value::from(source_type.as_str()))
            && attr(existing, "originalId").strict_eq(&source_original)
            && attr(existing, "database").strict_eq(&Value::from(self.resolve_destination_dsn(r).as_str()))
    }

    /// PHP: `createDatabase($resource)`.
    pub(super) async fn create_database(&mut self, r: &ResourceRef) -> Result<bool> {
        if r.id() == "unique()" {
            r.set_id(id::unique()?);
        }
        let uid = Uid::default();
        if !uid.is_valid(&s(&r.id())) {
            return self.reject(r, uid.description());
        }
        let Some(d) = r.as_database().cloned() else { return Ok(false) };
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let database_type = if empty(&d.database_type) { "legacy".to_owned() } else { d.database_type.clone() };
        let original = r.original_id();
        let original = if empty(&original) { DbValue::Null } else { s(&original) };
        let search = format!("{} {}", r.id(), d.name);

        let existing = self.db_for_project.get_document(META_DATABASES, &r.id(), Vec::new(), false).await?;
        let is_incomplete = !existing.is_empty() && self.support_for_database_status().await? && {
            let status = attr(&existing, "status");
            status.strict_eq(&Value::from(DATABASE_STATUS_FAILED))
                || status.strict_eq(&Value::from(DATABASE_STATUS_PROVISIONING))
        };

        if self.on_duplicate != OnDuplicate::Fail || is_incomplete {
            let mut action =
                self.on_duplicate.resolve_schema_action(!existing.is_empty(), Some(&updated_at), existing.updated_at());
            if is_incomplete {
                action = SchemaAction::Overwrite;
            } else if action != SchemaAction::Create && self.database_spec_matches(&existing, r) {
                action = SchemaAction::Skip;
            }
            match action {
                SchemaAction::Skip => {
                    r.set_sequence(seq(&existing));
                    r.set_status(status::SKIPPED, "Already exists on destination");
                    return Ok(false);
                }
                SchemaAction::Overwrite => {
                    let mut pairs = vec![
                        ("name", s(&d.name)),
                        ("search", s(&search)),
                        ("enabled", DbValue::Bool(d.enabled)),
                        ("type", s(&database_type)),
                        ("originalId", original.clone()),
                        ("database", s(&self.resolve_destination_dsn(r))),
                        ("$updatedAt", s(&updated_at)),
                    ];
                    if self.support_for_database_status().await? {
                        pairs.push(("status", s(DATABASE_STATUS_PROVISIONING)));
                    }
                    self.db_for_project.update_document(META_DATABASES, existing.id(), doc(pairs)?).await?;
                    r.set_sequence(seq(&existing));
                    if is_incomplete
                        && self.db_for_project.find_collection(&database_collection_id(&existing)).await?.is_none()
                        && let Err(e) = self.create_database_collection(r, &existing).await
                    {
                        self.mark_database_failed(&r.id()).await;
                        return Err(e);
                    }
                    if self.support_for_database_status().await? {
                        self.provisioning_databases.insert(r.id(), ());
                    }
                    return Ok(true);
                }
                SchemaAction::Create => {}
            }
        }

        let mut pairs = vec![
            ("$id", s(&r.id())),
            ("name", s(&d.name)),
            ("enabled", DbValue::Bool(d.enabled)),
            ("search", s(&search)),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
            ("originalId", original),
            ("type", s(&database_type)),
            ("database", s(&self.resolve_destination_dsn(r))),
        ];
        if self.support_for_database_status().await? {
            pairs.push(("status", s(DATABASE_STATUS_PROVISIONING)));
        }
        let database = self.db_for_project.create_document(META_DATABASES, doc(pairs)?).await?;
        let created: Result<()> = async {
            let database = self.db_for_project.get_document(META_DATABASES, database.id(), Vec::new(), false).await?;
            if database.is_empty() {
                return Err(
                    utopia_database::Error::database(format!("Failed to reload created database {}", r.id())).into()
                );
            }
            r.set_sequence(seq(&database));
            self.create_database_collection(r, &database).await
        }
        .await;
        if let Err(e) = created {
            self.mark_database_failed(&r.id()).await;
            return Err(e);
        }
        if self.support_for_database_status().await? {
            self.provisioning_databases.insert(r.id(), ());
        }
        Ok(true)
    }

    fn entity_type_metadata(&self, r: &ResourceRef) -> Vec<(&'static str, DbValue)> {
        let Some(t) = r.as_table() else { return Vec::new() };
        if t.collection
            && database_type_of(r) == types::DATABASE_VECTORSDB
            && matches!(self.collection_structures.get_str(types::DATABASE_VECTORSDB), Some(v) if !matches!(v, Value::Null))
        {
            return vec![("dimension", DbValue::Int(t.dimension().unwrap_or(0)))];
        }
        Vec::new()
    }

    fn table_spec_matches(&self, existing: &Document, r: &ResourceRef) -> Result<bool> {
        let Some(t) = r.as_table() else { return Ok(false) };
        if !attr(existing, "name").strict_eq(&Value::from(t.name.as_str()))
            || !attr(existing, "enabled").strict_eq(&Value::Bool(t.enabled))
            || !attr(existing, "documentSecurity").strict_eq(&Value::Bool(t.row_security))
        {
            return Ok(false);
        }
        let mut source = Permission::aggregate(&r.permissions(), &default_permissions())?;
        let mut dest: Vec<String> = match attr(existing, "$permissions") {
            Value::Array(a) => a.values().map(cast_string).collect(),
            _ => Vec::new(),
        };
        source.sort();
        dest.sort();
        Ok(source == dest)
    }

    /// PHP: `createEntity($resource)`.
    pub(super) async fn create_entity(&mut self, r: &ResourceRef) -> Result<bool> {
        if r.id() == "unique()" {
            r.set_id(id::unique()?);
        }
        let uid = Uid::default();
        if !uid.is_valid(&s(&r.id())) {
            return self.reject(r, uid.description());
        }
        let Some(t) = r.as_table() else { return Ok(false) };
        let (name, row_security, enabled) = (t.name.clone(), t.row_security, t.enabled);
        let database_id = database_of(r).map(|d| d.id()).unwrap_or_default();
        let database = self.db_for_project.get_document(META_DATABASES, &database_id, Vec::new(), false).await?;
        if database.is_empty() {
            return self.reject(r, "Database not found");
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let db = self.resolve(&database).await;
        if !db.collection_exists(METADATA, None).await? {
            db.create(None).await?;
        }
        let search = format!("{} {name}", r.id());

        if self.on_duplicate != OnDuplicate::Fail {
            let existing = self
                .db_for_project
                .get_document(&database_collection_id(&database), &r.id(), Vec::new(), false)
                .await?;
            let mut action =
                self.on_duplicate.resolve_schema_action(!existing.is_empty(), Some(&updated_at), existing.updated_at());
            if action != SchemaAction::Create && self.table_spec_matches(&existing, r)? {
                action = SchemaAction::Skip;
            }
            match action {
                SchemaAction::Skip => {
                    r.set_sequence(seq(&existing));
                    r.set_status(status::SKIPPED, "Already exists on destination");
                    return Ok(false);
                }
                SchemaAction::Overwrite => {
                    let permissions = Permission::aggregate(&r.permissions(), &default_permissions())?;
                    let mut pairs = vec![
                        ("name", s(&name)),
                        ("search", s(&search)),
                        ("enabled", DbValue::Bool(enabled)),
                        ("$permissions", strings(&permissions)),
                        ("documentSecurity", DbValue::Bool(row_security)),
                        ("$updatedAt", s(&updated_at)),
                    ];
                    pairs.extend(self.entity_type_metadata(r));
                    self.db_for_project
                        .update_document(&database_collection_id(&database), existing.id(), doc(pairs)?)
                        .await?;
                    r.set_sequence(seq(&existing));
                    return Ok(true);
                }
                SchemaAction::Create => {}
            }
        }

        let permissions = Permission::aggregate(&r.permissions(), &default_permissions())?;
        let mut pairs = vec![
            ("$id", s(&r.id())),
            ("databaseInternalId", s(&seq(&database))),
            ("databaseId", s(&database_id)),
            ("$permissions", strings(&permissions)),
            ("documentSecurity", DbValue::Bool(row_security)),
            ("enabled", DbValue::Bool(enabled)),
            ("name", s(&name)),
            ("search", s(&search)),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ];
        pairs.extend(self.entity_type_metadata(r));
        let table = self.db_for_project.create_document(&database_collection_id(&database), doc(pairs)?).await?;
        r.set_sequence(seq(&table));
        db.create_collection(Collection::create(
            table_collection_id(&database, &table),
            "",
            Vec::new(),
            Vec::new(),
            Some(r.permissions()),
            row_security,
            DbArray::new(),
        )?)
        .await?;
        Ok(true)
    }

    async fn purge_table_caches(&self, database: &Document, table: &Document, db: &Db) -> Result<()> {
        self.db_for_project.purge_cached_document(&database_collection_id(database), table.id()).await?;
        db.purge_cached_collection(&table_collection_id(database, table)).await?;
        Ok(())
    }

    fn track_orphan_candidate(&mut self, database: &Document, table: &Document, attribute: bool, key: &str, db: &Db) {
        if self.on_duplicate != OnDuplicate::Overwrite {
            return;
        }
        let id = table_identity(database, table);
        let entry = self.orphans_by_table.entry(id).or_insert_with(|| Orphans {
            database: database.clone(),
            table: table.clone(),
            db: db.clone(),
            attribute_keys: Vec::new(),
            index_keys: Vec::new(),
        });
        if attribute {
            entry.attribute_keys.push(key.to_owned());
        } else {
            entry.index_keys.push(key.to_owned());
        }
    }

    fn two_way_pair_key(
        &self,
        database: &Document,
        table: &Document,
        key: &str,
        options: &Array,
        t: &str,
    ) -> Option<String> {
        if t != RELATIONSHIP {
            return None;
        }
        if crate::resources::php_empty(&get_or_null(options, "twoWay")) {
            return None;
        }
        let two_way_key = cast_string(&get_or_null(options, "twoWayKey"));
        let related = cast_string(&get_or_null(options, "relatedCollection"));
        if two_way_key.is_empty() || related.is_empty() {
            return None;
        }
        let mut pair = [format!("{}::{key}", table.id()), format!("{related}::{two_way_key}")];
        pair.sort();
        Some(format!("{}@{}", seq(database), pair.join("<->")))
    }

    async fn resolve_two_way_partner(
        &self,
        database: &Document,
        options: &Array,
    ) -> Result<Option<(Document, String)>> {
        if crate::resources::php_empty(&get_or_null(options, "twoWay")) {
            return Ok(None);
        }
        let related = cast_string(&get_or_null(options, "relatedCollection"));
        let partner_key = cast_string(&get_or_null(options, "twoWayKey"));
        if related.is_empty() || partner_key.is_empty() {
            return Ok(None);
        }
        let related_table =
            self.db_for_project.get_document(&database_collection_id(database), &related, Vec::new(), false).await?;
        if related_table.is_empty() {
            return Ok(None);
        }
        let partner_meta_id = meta_id(database, &related_table, &partner_key);
        Ok(Some((related_table, partner_meta_id)))
    }

    fn attribute_spec_matches(
        &self,
        existing: &Document,
        f: &crate::resources::database::Field,
        t: &str,
        relationship: bool,
    ) -> bool {
        if !type_matches(&attr(existing, "type"), t) {
            return false;
        }
        if relationship {
            let source = f.options();
            let dest = options_of(existing);
            for field in RELATIONSHIP_IMMUTABLE_FIELDS {
                if !values_match(&get_or_null(&source, field), &get_or_null(&dest, field)) {
                    return false;
                }
            }
            return values_match(&get_or_null(&source, "onDelete"), &get_or_null(&dest, "onDelete"));
        }
        let Some((deployed, wanted)) = normalised_attributes(existing, f, t) else { return false };
        deployed.size == wanted.size
            && deployed.required == wanted.required
            && plain_value(&deployed.default).strict_eq(&plain_value(&wanted.default))
            && immutable_fields_match(existing, &deployed, f, &wanted)
    }

    /// PHP: `createField($resource)`.
    pub(super) async fn create_field(&mut self, r: &ResourceRef) -> Result<bool> {
        let Some(f) = r.as_field() else { return Ok(false) };
        let table_resource = f.table.clone();
        if database_type_of(&table_resource) == types::DATABASE_DOCUMENTSDB {
            r.set_status(status::SKIPPED, "Columns not supported for DocumentsDB");
            return Ok(false);
        }
        let t = database_type(f.field_type())?;
        let database_id = database_of(&table_resource).map(|d| d.id()).unwrap_or_default();
        let database = self.db_for_project.get_document(META_DATABASES, &database_id, Vec::new(), false).await?;
        if database.is_empty() {
            return self.reject(r, "Database not found");
        }
        let table = self
            .db_for_project
            .get_document(&database_collection_id(&database), &table_resource.id(), Vec::new(), false)
            .await?;
        if table.is_empty() {
            return self.reject(r, "Table not found");
        }
        if !empty(&f.format) && !formats::has(&f.format, Attribute::type_from_stored(t)?) {
            return self.reject(r, format!("Format {} not available for column type {t}", f.format));
        }
        if f.required && !matches!(f.default, Value::Null) {
            return self.reject(r, "Cannot set default value for required column");
        }
        if f.array && !matches!(f.default, Value::Null) {
            return self.reject(r, "Cannot set default value for array column");
        }
        let relationship = t == RELATIONSHIP;
        let mut related_table = None;
        if relationship {
            f.options_mut().set("side".into(), Value::from(RelationshipSide::Parent.as_str()));
            let related = cast_string(&get_or_null(&f.options(), "relatedCollection"));
            let found = self
                .db_for_project
                .get_document(&database_collection_id(&database), &related, Vec::new(), false)
                .await?;
            if found.is_empty() {
                return self.reject(r, "Related table not found");
            }
            related_table = Some(found);
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let db = self.resolve(&database).await;
        let key = f.key.clone();
        self.track_orphan_candidate(&database, &table, true, &key, &db);

        let pair_key = self.two_way_pair_key(&database, &table, &key, &f.options(), t);
        if let Some(p) = &pair_key
            && self.processed_two_way_pairs.contains(p)
        {
            r.set_status(status::SKIPPED, "Two-way partner already reconciled");
            return Ok(false);
        }

        let attribute_meta_id = meta_id(&database, &table, &key);
        if self.on_duplicate != OnDuplicate::Fail {
            let existing =
                self.db_for_project.get_document(META_ATTRIBUTES, &attribute_meta_id, Vec::new(), false).await?;
            let mut action =
                self.on_duplicate.resolve_schema_action(!existing.is_empty(), Some(&updated_at), existing.updated_at());
            if action != SchemaAction::Create && self.attribute_spec_matches(&existing, f, t, relationship) {
                action = SchemaAction::Skip;
            }
            let early = match action {
                SchemaAction::Skip => {
                    self.purge_table_caches(&database, &table, &db).await?;
                    r.set_status(status::SKIPPED, "Already exists on destination");
                    Some(false)
                }
                SchemaAction::Overwrite => {
                    let updated = if relationship {
                        self.update_relationship_in_place(&database, &table, r, t, &updated_at, &existing, &db).await?
                    } else {
                        self.update_attribute_in_place(&database, &table, r, t, &updated_at, &existing, &db).await?
                    };
                    updated.then_some(true)
                }
                SchemaAction::Create => None,
            };
            if let Some(early) = early {
                if let Some(p) = pair_key {
                    self.processed_two_way_pairs.insert(p);
                }
                return Ok(early);
            }
            if action == SchemaAction::Overwrite {
                self.drop_attribute_for_recreate(&database, &table, r, &db, &existing).await?;
            }
        }

        let filters = strings(&f.filters);
        let column_doc = |table: &Document, key: &str, options: &Array| -> Result<Document> {
            doc(vec![
                ("$id", s(&meta_id(&database, table, key))),
                ("key", s(key)),
                ("databaseInternalId", s(&seq(&database))),
                ("databaseId", s(database.id())),
                ("collectionInternalId", s(&seq(table))),
                ("collectionId", s(table.id())),
                ("type", s(t)),
                ("status", s("available")),
                ("size", DbValue::Int(f.size)),
                ("required", DbValue::Bool(f.required)),
                ("signed", DbValue::Bool(f.signed)),
                ("default", db_value(&f.default)),
                ("array", DbValue::Bool(f.array)),
                ("format", s(&f.format)),
                ("formatOptions", DbValue::Array(db_array(&f.format_options))),
                ("filters", filters.clone()),
                ("options", DbValue::Array(db_array(options))),
                ("$createdAt", s(&created_at)),
                ("$updatedAt", s(&updated_at)),
            ])
        };
        let options = f.options();
        let column = column_doc(&table, &key, &options)?;
        let collection_id = table_collection_id(&database, &table);
        let checked = match resource_attribute(f, t) {
            Ok(attribute) => match db.check_attribute(&collection_id, &attribute).await {
                Ok(_) => self.db_for_project.create_document(META_ATTRIBUTES, column).await,
                Err(e) => Err(e),
            },
            Err(e) => Err(e),
        };
        let column = match checked {
            Ok(c) => c,
            Err(e) if e.is_a(DbKind::Duplicate) => return self.reject_with(r, "Attribute already exists", e),
            Err(e) if e.is_a(DbKind::Limit) => return self.reject_with(r, "Attribute limit exceeded", e),
            Err(e) => {
                self.purge_table_caches(&database, &table, &db).await?;
                return Err(e.into());
            }
        };
        self.purge_table_caches(&database, &table, &db).await?;

        let mut options = f.options();
        let two_way = cast_bool(&get_or_null(&options, "twoWay"));
        let mut two_way_key: Option<String> = None;
        let mut two_way_attribute: Option<Document> = None;
        if relationship && two_way {
            let related = related_table.clone().expect("relationship");
            let partner_key = to_opt_string(&get_or_null(&options, "twoWayKey"));
            two_way_key = partner_key.clone();
            options.set("relatedCollection".into(), Value::from(table.id()));
            options.set("twoWayKey".into(), Value::from(key.as_str()));
            options.set("side".into(), Value::from(RelationshipSide::Child.as_str()));
            let partner_key = partner_key.unwrap_or_default();
            let partner = column_doc(&related, &partner_key, &options)?;
            match self.db_for_project.create_document(META_ATTRIBUTES, partner).await {
                Ok(p) => {
                    two_way_attribute = Some(p);
                    self.track_orphan_candidate(&database, &related, true, &partner_key, &db);
                }
                Err(e) if e.is_a(DbKind::Duplicate) => {
                    self.db_for_project.delete_document(META_ATTRIBUTES, column.id()).await?;
                    return self.reject_with(r, "Attribute already exists", e);
                }
                Err(e) if e.is_a(DbKind::Limit) => {
                    self.db_for_project.delete_document(META_ATTRIBUTES, column.id()).await?;
                    return self.reject_with(r, "Attribute limit exceeded", e);
                }
                Err(e) => {
                    self.purge_table_caches(&database, &related, &db).await?;
                    return Err(e.into());
                }
            }
        }

        let created: Result<()> = async {
            if relationship {
                let related = related_table.as_ref().expect("relationship");
                let key_for_partner = if two_way {
                    two_way_key.clone().unwrap_or_default()
                } else {
                    to_opt_string(&get_or_null(&options, "twoWayKey")).unwrap_or_default()
                };
                let mut definition = Array::new();
                definition
                    .set("relatedCollection".into(), Value::from(table_collection_id(&database, related).as_str()));
                definition.set("relationType".into(), get_or_null(&options, "relationType"));
                definition.set("twoWay".into(), get_or_null(&options, "twoWay"));
                definition.set("key".into(), Value::from(key.as_str()));
                definition.set("twoWayKey".into(), Value::from(key_for_partner.as_str()));
                definition.set("onDelete".into(), get_or_null(&options, "onDelete"));
                db.create_relationship(&collection_id, Relationship::from_array(&db_array(&definition))?).await?;
            } else {
                let mut definition = Array::new();
                definition.set("key".into(), Value::from(key.as_str()));
                definition.set("type".into(), Value::from(t));
                definition.set("size".into(), Value::Int(f.size));
                definition.set("required".into(), Value::Bool(f.required));
                definition.set("default".into(), f.default.clone());
                definition.set("signed".into(), Value::Bool(f.signed));
                definition.set("array".into(), Value::Bool(f.array));
                definition.set("format".into(), Value::from(f.format.as_str()));
                definition.set("formatOptions".into(), Value::Array(f.format_options.clone()));
                definition.set(
                    "filters".into(),
                    Value::Array(Array::from_list(f.filters.iter().map(|s| Value::from(s.as_str())).collect())),
                );
                db.create_attribute(&collection_id, Attribute::from_array(&db_array(&definition))?).await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = created {
            self.db_for_project.delete_document(META_ATTRIBUTES, column.id()).await?;
            if let Some(p) = &two_way_attribute {
                self.db_for_project.delete_document(META_ATTRIBUTES, p.id()).await?;
            }
            return Err(e);
        }
        if relationship && two_way {
            let related = related_table.as_ref().expect("relationship");
            self.db_for_project.purge_cached_document(&database_collection_id(&database), related.id()).await?;
        }
        self.purge_table_caches(&database, &table, &db).await?;
        if let Some(p) = pair_key {
            self.processed_two_way_pairs.insert(p);
        }
        self.sync_vector_dimension(r, t, &database, &table).await?;
        Ok(true)
    }

    fn reject_with(&mut self, r: &ResourceRef, message: &str, e: utopia_database::Error) -> Result<bool> {
        r.set_status(status::ERROR, message);
        self.target.add_error(Exception::new(r.name(), r.group(), Some(r.id()), message, 0, Some(Error::Database(e))));
        Ok(false)
    }

    async fn sync_vector_dimension(
        &self,
        r: &ResourceRef,
        t: &str,
        database: &Document,
        table: &Document,
    ) -> Result<()> {
        let Some(f) = r.as_field() else { return Ok(()) };
        if t != VECTOR
            || f.key != VECTORSDB_EMBEDDINGS_KEY
            || database_type_of(&f.table) != types::DATABASE_VECTORSDB
            || matches!(self.collection_structures.get_str(types::DATABASE_VECTORSDB), None | Some(Value::Null))
        {
            return Ok(());
        }
        if cast_int(&table.get("dimension").map(plain_value).unwrap_or(Value::Int(0))) == f.size {
            return Ok(());
        }
        self.db_for_project
            .update_document(
                &database_collection_id(database),
                table.id(),
                doc(vec![("dimension", DbValue::Int(f.size))])?,
            )
            .await?;
        Ok(())
    }

    async fn drop_attribute_for_recreate(
        &mut self,
        database: &Document,
        table: &Document,
        r: &ResourceRef,
        db: &Db,
        existing: &Document,
    ) -> Result<()> {
        let f = r.as_field().expect("a field");
        let collection_id = table_collection_id(database, table);
        let attribute_meta_id = meta_id(database, table, &f.key);
        let relationship = f.field_type() == column::RELATIONSHIP;
        if relationship {
            db.delete_relationship(&collection_id, &f.key).await?;
        } else {
            db.delete_attribute(&collection_id, &f.key).await?;
        }
        self.db_for_project.delete_document(META_ATTRIBUTES, &attribute_meta_id).await?;
        if relationship
            && let Some((_, partner_meta_id)) = self.resolve_two_way_partner(database, &options_of(existing)).await?
        {
            let _ = self.db_for_project.delete_document(META_ATTRIBUTES, &partner_meta_id).await;
        }
        self.purge_table_caches(database, table, db).await
    }

    #[allow(clippy::too_many_arguments)]
    async fn update_attribute_in_place(
        &mut self,
        database: &Document,
        table: &Document,
        r: &ResourceRef,
        t: &str,
        updated_at: &str,
        existing: &Document,
        db: &Db,
    ) -> Result<bool> {
        let f = r.as_field().expect("a field");
        let filters = Value::Array(Array::from_list(f.filters.iter().map(|s| Value::from(s.as_str())).collect()));
        if !type_matches(&attr(existing, "type"), t) {
            return Ok(false);
        }
        let Some((deployed, wanted)) = normalised_attributes(existing, f, t) else { return Ok(false) };
        if !immutable_fields_match(existing, &deployed, f, &wanted) {
            return Ok(false);
        }
        let format =
            if f.format.is_empty() { None } else { Some(Format::new(f.format.as_str(), db_array(&f.format_options))?) };
        let update = AttributeUpdate {
            kind: Some(Attribute::type_from_stored(t)?),
            size: Some(f.size),
            required: Some(f.required),
            default: Patch::Set(db_value(&f.default)),
            signed: Some(deployed.signed),
            array: Some(deployed.array),
            format: Patch::Set(format),
            filters: Some(deployed.filters.iter().map(Into::into).collect()),
            key: None,
        };
        db.update_attribute(&table_collection_id(database, table), &f.key, update).await?;
        self.db_for_project
            .update_document(
                META_ATTRIBUTES,
                existing.id(),
                doc(vec![
                    ("key", s(&f.key)),
                    ("type", s(t)),
                    ("size", DbValue::Int(f.size)),
                    ("required", DbValue::Bool(f.required)),
                    ("signed", DbValue::Bool(f.signed)),
                    ("default", db_value(&f.default)),
                    ("array", DbValue::Bool(f.array)),
                    ("format", s(&f.format)),
                    ("formatOptions", DbValue::Array(db_array(&f.format_options))),
                    ("filters", db_value(&filters)),
                    ("$updatedAt", s(updated_at)),
                ])?,
            )
            .await?;
        self.purge_table_caches(database, table, db).await?;
        Ok(true)
    }

    #[allow(clippy::too_many_arguments)]
    async fn update_relationship_in_place(
        &mut self,
        database: &Document,
        table: &Document,
        r: &ResourceRef,
        t: &str,
        updated_at: &str,
        existing: &Document,
        db: &Db,
    ) -> Result<bool> {
        let f = r.as_field().expect("a field");
        let source = f.options();
        let dest = options_of(existing);
        if arrays_differ_on_keys(&source, &dest, &RELATIONSHIP_IMMUTABLE_FIELDS) {
            return Ok(false);
        }
        let two_way = cast_bool(&get_or_null(&dest, "twoWay"));
        let source_on_delete = get_or_null(&source, "onDelete");
        let on_delete_changed = !source_on_delete.strict_eq(&get_or_null(&dest, "onDelete"));
        let on_delete = RelationshipDeleteAction::try_from_value(&cast_string(&source_on_delete));
        if !two_way && on_delete_changed {
            return Ok(false);
        }
        if on_delete_changed {
            let update = RelationshipUpdate { on_delete, ..Default::default() };
            db.update_relationship(&table_collection_id(database, table), &f.key, update).await?;
        }
        let on_delete_value = on_delete.map_or(Value::Null, |a| Value::from(a.as_str()));
        let mut options = dest.clone();
        options.set("onDelete".into(), first_set(&on_delete_value, &get_or_null(&dest, "onDelete")));
        self.db_for_project
            .update_document(
                META_ATTRIBUTES,
                existing.id(),
                doc(vec![
                    ("key", s(&f.key)),
                    ("type", s(t)),
                    ("options", DbValue::Array(db_array(&options))),
                    ("$updatedAt", s(updated_at)),
                ])?,
            )
            .await?;
        self.purge_table_caches(database, table, db).await?;
        if two_way && let Some((related, partner_meta_id)) = self.resolve_two_way_partner(database, &dest).await? {
            let partner =
                self.db_for_project.get_document(META_ATTRIBUTES, &partner_meta_id, Vec::new(), false).await?;
            if !partner.is_empty() {
                let mut partner_options = options_of(&partner);
                let value = first_set(&on_delete_value, &get_or_null(&partner_options, "onDelete"));
                partner_options.set("onDelete".into(), value);
                self.db_for_project
                    .update_document(
                        META_ATTRIBUTES,
                        partner.id(),
                        doc(vec![
                            ("options", DbValue::Array(db_array(&partner_options))),
                            ("$updatedAt", s(updated_at)),
                        ])?,
                    )
                    .await?;
                self.purge_table_caches(database, &related, db).await?;
            }
        }
        Ok(true)
    }

    /// `lengths` is compared: the source's own prefix lengths drive the
    /// destination index. The resource carries no TTL, so the deployed one
    /// is kept.
    ///
    /// PHP: `indexSpecMatches()` (private).
    fn index_spec_matches(&self, existing: &Document, r: &ResourceRef) -> bool {
        let Kind::Index(i) = r.kind() else { return false };
        let Ok(deployed) = Index::from_document(existing) else { return false };
        let mut wanted = Array::new();
        wanted.set("key".into(), Value::from(i.key.as_str()));
        wanted.set("type".into(), Value::from(i.index_type.as_str()));
        wanted.set("attributes".into(), Value::Array(i.columns.clone()));
        wanted.set("lengths".into(), Value::Array(Array::from_list(i.lengths.values().cloned().collect())));
        wanted.set("orders".into(), Value::Array(Array::from_list(i.orders.values().cloned().collect())));
        wanted.set("ttl".into(), deployed.ttl.map_or(Value::Null, Value::Int));
        let Ok(wanted) = Index::from_array(&db_array(&wanted)) else { return false };
        index_shape(&deployed).strict_eq(&index_shape(&wanted))
    }

    /// The prefix lengths of an index's columns, checking each column
    /// exists, is available and is not a relationship.
    ///
    /// PHP: `validateFieldsForIndexes()` (private).
    fn validate_fields_for_indexes(&self, r: &ResourceRef, table: &Document) -> Result<DbArray> {
        let Kind::Index(i) = r.kind() else { return Ok(DbArray::new()) };
        let mut columns: Vec<Value> = match table.get("attributes") {
            Some(DbValue::Array(list)) => list.values().map(plain_document).collect(),
            _ => Vec::new(),
        };
        let internal = |key: &str, t: &str, required: bool, size: i64, signed: Option<bool>| {
            let mut a = Array::new();
            a.set("key".into(), Value::from(key));
            a.set("type".into(), Value::from(t));
            a.set("status".into(), Value::from("available"));
            if let Some(signed) = signed {
                a.set("signed".into(), Value::Bool(signed));
            }
            a.set("required".into(), Value::Bool(required));
            a.set("array".into(), Value::Bool(false));
            a.set("default".into(), Value::Null);
            a.set("size".into(), Value::Int(size));
            Value::Array(a)
        };
        columns.push(internal("$id", ColumnType::String.as_str(), true, LENGTH_KEY, None));
        columns.push(internal("$createdAt", ColumnType::Datetime.as_str(), false, 0, Some(false)));
        columns.push(internal("$updatedAt", ColumnType::Datetime.as_str(), false, 0, Some(false)));
        let field = |c: &Value, k: &str| match c {
            Value::Array(a) => a.get_str(k).cloned(),
            _ => None,
        };
        let keys: Vec<Value> = columns.iter().filter_map(|c| field(c, "key")).collect();
        let lengths_source: Vec<(php_std::types::KeyRef<'_>, &Value)> = i.lengths.iter().collect();
        let mut lengths = DbArray::new();
        for (position, (k, name)) in i.columns.iter().enumerate() {
            let Some(found) = keys.iter().position(|key| key.loose_eq(name)) else {
                return Err(self.index_error(r, format!("Column not found in table: {}", cast_string(name))));
            };
            let column =
                &columns.iter().filter(|c| field(c, "key").is_some()).nth(found).cloned().unwrap_or(Value::Null);
            if field(column, "type").is_some_and(|t| t.strict_eq(&Value::from(RELATIONSHIP))) {
                return Err(self.index_error(r, "Relationship columns are not supported in indexes".to_owned()));
            }
            if !field(column, "status").is_some_and(|s| s.strict_eq(&Value::from("available"))) {
                return Err(self.index_error(r, format!("Column not available: {}", cast_string(name))));
            }
            let source = i.lengths.get(&k.to_owned()).cloned().unwrap_or(Value::Null);
            let _ = &lengths_source;
            let _ = position;
            let mut length = match source {
                Value::Int(n) if n > 0 => DbValue::Int(n),
                _ => DbValue::Null,
            };
            if field(column, "array").is_some_and(|a| a.strict_eq(&Value::Bool(true))) {
                length = DbValue::Int(MAX_ARRAY_INDEX_LENGTH);
            }
            lengths.set(k.to_owned(), length);
        }
        Ok(lengths)
    }

    fn index_error(&self, r: &ResourceRef, message: String) -> Error {
        Error::migration(Exception::new(r.name(), r.group(), Some(r.id()), message, 0, None))
    }

    /// PHP: `createIndex($resource)`.
    pub(super) async fn create_index(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Index(i) = r.kind() else { return Ok(false) };
        let table_resource = i.table.clone();
        let database_id = database_of(&table_resource).map(|d| d.id()).unwrap_or_default();
        let database = self.db_for_project.get_document(META_DATABASES, &database_id, Vec::new(), false).await?;
        if database.is_empty() {
            return self.reject(r, "Database not found");
        }
        let mut table = self
            .db_for_project
            .get_document(&database_collection_id(&database), &table_resource.id(), Vec::new(), false)
            .await?;
        if table.is_empty() {
            return self.reject(r, "Table not found");
        }
        let db = self.resolve(&database).await;
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        self.track_orphan_candidate(&database, &table, false, &i.key, &db);
        let index_meta_id = meta_id(&database, &table, &i.key);

        if self.on_duplicate != OnDuplicate::Fail {
            let existing = self.db_for_project.get_document(META_INDEXES, &index_meta_id, Vec::new(), false).await?;
            let mut action =
                self.on_duplicate.resolve_schema_action(!existing.is_empty(), Some(&updated_at), existing.updated_at());
            if action != SchemaAction::Create && self.index_spec_matches(&existing, r) {
                action = SchemaAction::Skip;
            }
            if action == SchemaAction::Skip {
                self.db_for_project.purge_cached_document(&database_collection_id(&database), table.id()).await?;
                r.set_status(status::SKIPPED, "Already exists on destination");
                return Ok(false);
            }
            if action == SchemaAction::Overwrite {
                db.delete_index(&table_collection_id(&database, &table), &i.key).await?;
                self.db_for_project.delete_document(META_INDEXES, &index_meta_id).await?;
                self.db_for_project.purge_cached_document(&database_collection_id(&database), table.id()).await?;
                table = self
                    .db_for_project
                    .get_document(&database_collection_id(&database), table.id(), Vec::new(), false)
                    .await?;
            }
        }

        let limit = db.limit_for_indexes();
        let count = self
            .db_for_project
            .count(
                META_INDEXES,
                vec![
                    Query::equal("collectionInternalId", [seq(&table)]),
                    Query::equal("databaseInternalId", [seq(&database)]),
                ],
                Some(limit),
            )
            .await?;
        if count >= limit {
            return self.reject(r, "Index limit reached for table");
        }
        let adapter = db.adapter().clone();
        let lengths = if adapter.supports(Capability::DefinedAttributes) {
            self.validate_fields_for_indexes(r, &table)?
        } else {
            DbArray::new()
        };
        let index = doc(vec![
            ("$id", s(&index_meta_id)),
            ("key", s(&i.key)),
            ("status", s("available")),
            ("databaseInternalId", s(&seq(&database))),
            ("databaseId", s(database.id())),
            ("collectionInternalId", s(&seq(&table))),
            ("collectionId", s(table.id())),
            ("type", s(&i.index_type)),
            ("attributes", DbValue::Array(db_array(&i.columns))),
            ("lengths", DbValue::Array(lengths.clone())),
            ("orders", DbValue::Array(db_array(&i.orders))),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ])?;
        let validator = IndexDefinition::from_documents(
            &table.documents("attributes")?,
            &table.documents("indexes")?,
            db.profile(),
        )?;
        if !validator.is_valid(&DbValue::from(index.clone()))? {
            return self.reject(r, format!("Invalid index: {}", validator.description()));
        }
        let index = self.db_for_project.create_document(META_INDEXES, index).await?;
        let mut definition = Array::new();
        definition.set("key".into(), Value::from(i.key.as_str()));
        definition.set("type".into(), Value::from(i.index_type.as_str()));
        definition.set("attributes".into(), Value::Array(i.columns.clone()));
        definition.set("lengths".into(), plain_value(&DbValue::Array(lengths)));
        definition.set("orders".into(), Value::Array(i.orders.clone()));
        let created = match Index::from_array(&db_array(&definition)) {
            Ok(definition) => db.create_index(&table_collection_id(&database, &table), definition).await.map(|_| ()),
            Err(e) => Err(e),
        };
        if let Err(e) = created {
            self.db_for_project.delete_document(META_INDEXES, index.id()).await?;
            return Err(e.into());
        }
        self.db_for_project.purge_cached_document(&database_collection_id(&database), table.id()).await?;
        Ok(true)
    }

    /// PHP: `createRecord($resource, $isLast)`.
    pub(super) async fn create_record(&mut self, r: &ResourceRef, is_last: bool) -> Result<bool> {
        if r.id() == "unique()" {
            r.set_id(id::unique()?);
        }
        let uid = Uid::default();
        if !uid.is_valid(&s(&r.id())) {
            return self.reject(r, uid.description());
        }
        let cached = self.target.cache()?.get(r.name());
        if cached.contains_key(&ArrayKey::from(r.id().as_str())) {
            r.set_status(status::SKIPPED, "Row has already been created");
            return Ok(false);
        }
        let Some(row) = r.as_row() else { return Ok(false) };
        let mut data = row.data.clone();
        let filled = |a: &Array, k: &str| a.get_str(k).is_some_and(|v| !crate::resources::php_empty(v));
        let has_created = filled(&data, "$createdAt");
        let has_updated = filled(&data, "$updatedAt");
        if !has_created {
            let mut created = Value::from(r.created_at().as_str());
            if crate::resources::php_empty(&created) {
                created = first_set(&get_or_null(&data, "created_at"), &get_or_null(&data, "createdAt"));
            }
            data.set("$createdAt".into(), Value::from(normalize_date_time(&created, &Value::Null)?.as_str()));
        }
        if !has_updated {
            let mut updated = Value::from(r.updated_at().as_str());
            if crate::resources::php_empty(&updated) {
                updated = first_set(&get_or_null(&data, "updated_at"), &get_or_null(&data, "updatedAt"));
            }
            let created = get_or_null(&data, "$createdAt");
            let value = if crate::resources::php_empty(&updated) {
                cast_string(&created)
            } else {
                normalize_date_time(&updated, &created)?
            };
            data.set("$updatedAt".into(), Value::from(value.as_str()));
        }
        let mut head = DbArray::new();
        head.set("$id".into(), s(&r.id()));
        head.set("$permissions".into(), strings(&r.permissions()));
        self.row_buffer.push(Document::new(array_merge(head, &db_array(&data)))?);

        if is_last {
            let result = self.flush_rows(r).await;
            self.row_buffer.clear();
            return result;
        }
        Ok(true)
    }

    async fn flush_rows(&mut self, r: &ResourceRef) -> Result<bool> {
        let table_resource = r.table().cloned().expect("a row has a table");
        let database_id = database_of(&table_resource).map(|d| d.id()).unwrap_or_default();
        let database = self.db_for_project.get_document(META_DATABASES, &database_id, Vec::new(), false).await?;
        let table = self
            .db_for_project
            .get_document(&database_collection_id(&database), &table_resource.id(), Vec::new(), false)
            .await?;
        let db = self.resolve(&database).await;
        self.cleanup_overwrite_orphans_for_table(&table_identity(&database, &table)).await?;
        let table = self
            .db_for_project
            .get_document(&database_collection_id(&database), &table_resource.id(), Vec::new(), false)
            .await?;
        let mut rows = std::mem::take(&mut self.row_buffer);
        if db.adapter().supports(Capability::DefinedAttributes) {
            let declared: HashSet<String> = match table.get("attributes") {
                Some(DbValue::Array(list)) => list
                    .values()
                    .map(|a| match plain_document(a) {
                        Value::Array(a) => cast_string(&a.get_str("key").cloned().unwrap_or(Value::from(""))),
                        _ => String::new(),
                    })
                    .collect(),
                _ => HashSet::new(),
            };
            for row in &mut rows {
                let undeclared: Vec<ArrayKey> = row
                    .keys()
                    .map(|k| k.to_owned())
                    .filter(|k| match k {
                        ArrayKey::Str(s) => {
                            !s.as_bytes().starts_with(b"$")
                                && !declared.contains(&*String::from_utf8_lossy(s.as_bytes()))
                        }
                        ArrayKey::Int(i) => !declared.contains(&i.to_string()),
                    })
                    .collect();
                for k in undeclared {
                    row.as_array_mut().remove(&k);
                }
            }
        }
        let collection_id = table_collection_id(&database, &table);
        let written = match self.on_duplicate {
            OnDuplicate::Overwrite => {
                db.skip_relationships_exist_check(db.upsert_documents(&collection_id, rows, BATCH_SIZE, None, None))
                    .await
            }
            OnDuplicate::Skip => {
                db.ignore_duplicates(db.skip_relationships_exist_check(db.create_documents(
                    &collection_id,
                    rows,
                    BATCH_SIZE,
                    None,
                )))
                .await
            }
            OnDuplicate::Fail => {
                db.skip_relationships_exist_check(db.create_documents(&collection_id, rows, BATCH_SIZE, None)).await
            }
        };
        match written {
            Ok(_) => Ok(true),
            Err(e) if e.is_a(DbKind::Duplicate) => self.reject_with(r, "Document already exists", e),
            Err(e) if e.is_a(DbKind::Structure) => {
                let message = e.message().to_owned();
                self.reject_with(r, &message, e)
            }
            Err(e) => Err(e.into()),
        }
    }

    pub(super) async fn cleanup_overwrite_orphans(&mut self) -> Result<()> {
        let ids: Vec<String> = self.orphans_by_table.keys().cloned().collect();
        for id in ids {
            self.cleanup_overwrite_orphans_for_table(&id).await?;
        }
        Ok(())
    }

    async fn cleanup_overwrite_orphans_for_table(&mut self, table_id: &str) -> Result<()> {
        if self.on_duplicate != OnDuplicate::Overwrite {
            return Ok(());
        }
        let Some(tracked) = self.orphans_by_table.get(table_id) else { return Ok(()) };
        let (database, table, db) = (tracked.database.clone(), tracked.table.clone(), tracked.db.clone());
        let (attribute_keys, index_keys) = (tracked.attribute_keys.clone(), tracked.index_keys.clone());
        for attribute in self.orphans(META_ATTRIBUTES, &attribute_keys, &database, &table).await? {
            self.drop_orphan_attribute(&database, &table, &attribute, &db).await?;
        }
        for index in self.orphans(META_INDEXES, &index_keys, &database, &table).await? {
            let key = cast_string(&attr(&index, "key"));
            self.drop_orphan_index(&database, &table, &key, &db).await?;
        }
        self.orphans_by_table.shift_remove(table_id);
        Ok(())
    }

    async fn orphans(
        &self,
        collection: &str,
        processed: &[String],
        database: &Document,
        table: &Document,
    ) -> Result<Vec<Document>> {
        let found = self
            .db_for_project
            .find(
                collection,
                vec![
                    Query::equal("databaseInternalId", [seq(database)]),
                    Query::equal("collectionInternalId", [seq(table)]),
                    Query::limit(i64::MAX),
                ],
                PermissionType::Read,
            )
            .await?;
        Ok(found
            .into_iter()
            .filter(|d| match d.raw("key") {
                Some(DbValue::Str(k)) => !processed.iter().any(|p| p.as_bytes() == k.as_bytes()),
                _ => true,
            })
            .collect())
    }

    async fn drop_orphan_attribute(
        &mut self,
        database: &Document,
        table: &Document,
        attribute: &Document,
        db: &Db,
    ) -> Result<()> {
        let key = cast_string(&attr(attribute, "key"));
        let t = cast_string(&attr(attribute, "type"));
        let options = options_of(attribute);
        let collection_id = table_collection_id(database, table);
        if t == RELATIONSHIP {
            let _ = db.delete_relationship(&collection_id, &key).await;
        } else {
            let _ = db.delete_attribute(&collection_id, &key).await;
        }
        let _ = self.db_for_project.delete_document(META_ATTRIBUTES, attribute.id()).await;
        self.db_for_project.purge_cached_document(&database_collection_id(database), table.id()).await?;
        db.purge_cached_collection(&collection_id).await?;
        if t != RELATIONSHIP {
            return Ok(());
        }
        let Some((related, partner_meta_id)) = self.resolve_two_way_partner(database, &options).await? else {
            return Ok(());
        };
        let _ = self.db_for_project.delete_document(META_ATTRIBUTES, &partner_meta_id).await;
        self.purge_table_caches(database, &related, db).await
    }

    async fn drop_orphan_index(&mut self, database: &Document, table: &Document, key: &str, db: &Db) -> Result<()> {
        let collection_id = table_collection_id(database, table);
        let _ = db.delete_index(&collection_id, key).await;
        let _ = self.db_for_project.delete_document(META_INDEXES, &meta_id(database, table, key)).await;
        self.db_for_project.purge_cached_document(&database_collection_id(database), table.id()).await?;
        Ok(())
    }
}

/// `$a ?? $b`.
fn first_set(a: &Value, b: &Value) -> Value {
    if matches!(a, Value::Null) { b.clone() } else { a.clone() }
}

fn to_opt_string(v: &Value) -> Option<String> {
    match v {
        Value::Null => None,
        v => Some(cast_string(v)),
    }
}

/// A document (or array) value as an array (`getArrayCopy()`).
fn plain_document(v: &DbValue) -> Value {
    match v {
        DbValue::Ext(utopia_database::value::Ext::Document(d)) => plain_value(&DbValue::Array(d.array_copy())),
        v => plain_value(v),
    }
}

impl From<utopia_database::Error> for Box<Error> {
    fn from(e: utopia_database::Error) -> Self {
        Box::new(Error::Database(e))
    }
}

#[allow(dead_code)]
fn flavor_of(r: &ResourceRef) -> Option<DatabaseFlavor> {
    r.as_database().map(|d| d.flavor)
}

/// `Database::PERMISSIONS`, what `Permission::aggregate()` expands to.
fn default_permissions() -> Vec<PermissionType> {
    PermissionType::ALL.to_vec()
}
