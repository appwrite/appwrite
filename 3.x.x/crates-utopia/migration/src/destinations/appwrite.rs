//! Appwrite destination. PHP `Utopia\Migration\Destinations\Appwrite`.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;

use indexmap::IndexMap;
use serde_json::{json, Map, Value};
use utopia_database::adapter::Memory;
use utopia_database::constants::{
    LENGTH_KEY, MAX_ARRAY_INDEX_LENGTH, METADATA, VAR_BIGINT, VAR_BOOLEAN, VAR_DATETIME, VAR_FLOAT,
    VAR_INTEGER, VAR_LINESTRING, VAR_LONGTEXT, VAR_MEDIUMTEXT, VAR_OBJECT, VAR_POINT, VAR_POLYGON,
    VAR_RELATIONSHIP, VAR_STRING, VAR_TEXT, VAR_VARCHAR, VAR_VECTOR,
};
use utopia_database::helpers::Id;
use utopia_database::validator::{Index as IndexValidator, Structure, Uid};
use utopia_database::{Adapter, AttrValue, Database, Document};
use utopia_validators::Validator;

use crate::destination::{dest_run, Destination, DestinationCommon};
use crate::exception::Exception;
use crate::on_duplicate::{OnDuplicate, SchemaAction};
use crate::resource::{
    AnyResource, Resource, ALL_RESOURCES, STATUS_ERROR, STATUS_PROCESSING, STATUS_SKIPPED,
    STATUS_SUCCESS, TYPE_ATTRIBUTE, TYPE_COLLECTION, TYPE_COLUMN, TYPE_DATABASE,
    TYPE_DATABASE_DOCUMENTSDB, TYPE_DATABASE_VECTORSDB, TYPE_DOCUMENT, TYPE_INDEX, TYPE_ROW,
    TYPE_TABLE,
};
use crate::resource_selector::ResourceSelector;
use crate::resources::database::{Column, Database as DatabaseResource, Index, Table};
use crate::source::Source;
use crate::target::{Target, TargetState};
use crate::transfer::GROUP_DATABASES;

pub type DatabaseDsnResolver = Arc<dyn Fn(&DatabaseResource) -> String + Send + Sync>;

const META_DATABASES: &str = "databases";
const META_ATTRIBUTES: &str = "attributes";
const META_INDEXES: &str = "indexes";
const DATABASE_STATUS_PROVISIONING: &str = "provisioning";
const DATABASE_STATUS_READY: &str = "ready";
const DATABASE_STATUS_FAILED: &str = "failed";

/// Attribute fields the SDK can't update in place.
pub const ATTRIBUTE_IMMUTABLE_FIELDS: &[&str] = &[
    "type",
    "array",
    "signed",
    "format",
    "formatOptions",
    "filters",
];

/// Relationship options the SDK can't update in place.
pub const RELATIONSHIP_IMMUTABLE_FIELDS: &[&str] =
    &["relationType", "twoWay", "twoWayKey", "relatedCollection"];

/// PHP `$collectionStructure` (`attributes` + `indexes` document arrays).
#[derive(Clone, Debug, Default)]
pub struct CollectionStructure {
    pub attributes: Vec<Value>,
    pub indexes: Vec<Value>,
}

impl CollectionStructure {
    #[must_use]
    pub fn from_value(value: &Value) -> Self {
        let obj = value.as_object();
        Self {
            attributes: obj
                .and_then(|o| o.get("attributes"))
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default(),
            indexes: obj
                .and_then(|o| o.get("indexes"))
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default(),
        }
    }
}

/// Appwrite project destination. Database schema import uses `utopia-database`
/// (Memory in tests). Live Appwrite HTTP is feature-gated (`appwrite-http`).
pub struct Appwrite<A: Adapter = Memory> {
    common: DestinationCommon,
    pub project_id: String,
    pub endpoint: String,
    pub key: String,
    pub project_internal_id: String,
    pub on_duplicate: OnDuplicate,
    get_database_dsn: Option<DatabaseDsnResolver>,
    db: Option<Database<A>>,
    collection_structure: CollectionStructure,
    source_supports_database_status: bool,
    database_status_supported: Option<bool>,
    provisioning_databases: HashSet<String>,
    pub run_count: usize,
}

impl Appwrite<Memory> {
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        project: impl Into<String>,
        endpoint: impl Into<String>,
        key: impl Into<String>,
        project_internal_id: impl Into<String>,
        on_duplicate: OnDuplicate,
        get_database_dsn: Option<DatabaseDsnResolver>,
    ) -> Self {
        Self::new_inner(
            project,
            endpoint,
            key,
            project_internal_id,
            on_duplicate,
            get_database_dsn,
            None,
            CollectionStructure::default(),
        )
    }
}

impl<A: Adapter> Appwrite<A> {
    #[allow(clippy::too_many_arguments)]
    fn new_inner(
        project: impl Into<String>,
        endpoint: impl Into<String>,
        key: impl Into<String>,
        project_internal_id: impl Into<String>,
        on_duplicate: OnDuplicate,
        get_database_dsn: Option<DatabaseDsnResolver>,
        db: Option<Database<A>>,
        collection_structure: CollectionStructure,
    ) -> Self {
        let endpoint = endpoint.into();
        let mut common = DestinationCommon::default();
        common.state.endpoint.clone_from(&endpoint);
        Self {
            common,
            project_id: project.into(),
            endpoint,
            key: key.into(),
            project_internal_id: project_internal_id.into(),
            on_duplicate,
            get_database_dsn,
            db,
            collection_structure,
            source_supports_database_status: false,
            database_status_supported: None,
            provisioning_databases: HashSet::new(),
            run_count: 0,
        }
    }

    /// PHP constructor with `dbForProject` (and the same handle for platform / per-database).
    #[allow(clippy::too_many_arguments)]
    pub fn with_database(
        project: impl Into<String>,
        endpoint: impl Into<String>,
        key: impl Into<String>,
        db_for_project: Database<A>,
        collection_structure: CollectionStructure,
        project_internal_id: impl Into<String>,
        on_duplicate: OnDuplicate,
        get_database_dsn: Option<DatabaseDsnResolver>,
    ) -> Self {
        Self::new_inner(
            project,
            endpoint,
            key,
            project_internal_id,
            on_duplicate,
            get_database_dsn,
            Some(db_for_project),
            collection_structure,
        )
    }

    /// PHP `resolveDestinationDsn`. Public so tests do not need reflection.
    #[must_use]
    pub fn resolve_destination_dsn(&self, resource: &DatabaseResource) -> String {
        match &self.get_database_dsn {
            None => String::new(),
            Some(resolver) => resolver(resource),
        }
    }

    #[must_use]
    pub fn database(&self) -> Option<&Database<A>> {
        self.db.as_ref()
    }

    #[must_use]
    pub fn database_mut(&mut self) -> Option<&mut Database<A>> {
        self.db.as_mut()
    }

    fn with_db<R>(&mut self, f: impl FnOnce(&mut Self, &mut Database<A>) -> R) -> Option<R> {
        let mut db = self.db.take()?;
        let out = db.skip_authorization(|db| f(self, db));
        self.db = Some(db);
        Some(out)
    }

    fn get_support_for_database_status(&mut self, db: &mut Database<A>) -> bool {
        if !self.source_supports_database_status {
            return false;
        }
        if let Some(cached) = self.database_status_supported {
            return cached;
        }
        let supported = db
            .get_collection(META_DATABASES)
            .ok()
            .and_then(|collection| {
                documents_from_attr(collection.get_attribute("attributes"))
                    .into_iter()
                    .any(|a| a.get_id() == "status")
                    .then_some(true)
            })
            .unwrap_or(false);
        self.database_status_supported = Some(supported);
        supported
    }

    fn mark_provisioned_databases_ready(&mut self) {
        let ids: Vec<String> = self.provisioning_databases.iter().cloned().collect();
        let _ = self.with_db(|dest, db| {
            if !dest.get_support_for_database_status(db) {
                return;
            }
            for id in &ids {
                let doc =
                    Document::from_pairs([("status", AttrValue::from(DATABASE_STATUS_READY))])
                        .unwrap_or_else(|_| Document::new());
                let _ = db.update_document(META_DATABASES, id, doc);
            }
        });
    }

    fn import_database_resource(
        &mut self,
        db: &mut Database<A>,
        resource: &mut AnyResource,
    ) -> bool {
        match resource.get_name() {
            TYPE_DATABASE | TYPE_DATABASE_DOCUMENTSDB | TYPE_DATABASE_VECTORSDB => {
                let AnyResource::Database(r) = resource else {
                    return false;
                };
                self.create_database(db, r)
            }
            TYPE_TABLE | TYPE_COLLECTION => {
                let table = match resource {
                    AnyResource::Table(t) => t,
                    AnyResource::Collection(c) => c.as_table_mut(),
                    _ => return false,
                };
                self.create_entity(db, table)
            }
            TYPE_COLUMN => {
                let AnyResource::Column(c) = resource else {
                    return false;
                };
                self.create_field(db, c)
            }
            TYPE_ATTRIBUTE => {
                let AnyResource::Attribute(a) = resource else {
                    return false;
                };
                self.create_field_from_attribute(db, a)
            }
            TYPE_INDEX => {
                let AnyResource::Index(i) = resource else {
                    return false;
                };
                self.create_index(db, i)
            }
            TYPE_ROW | TYPE_DOCUMENT => true,
            _ => false,
        }
    }

    fn create_database(&mut self, db: &mut Database<A>, resource: &mut DatabaseResource) -> bool {
        if resource.get_id() == "unique()" {
            let Ok(id) = Id::unique() else {
                resource.set_status(STATUS_ERROR, "Failed to generate a unique ID");
                return false;
            };
            resource.set_id(id);
        }
        let uid = Uid::default();
        if !uid.is_valid(&json!(resource.get_id())) {
            resource.set_status(STATUS_ERROR, uid.description());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                uid.description(),
                Exception::CODE_VALIDATION,
            ));
            return false;
        }

        let created_at = resource.get_created_at().to_owned();
        let updated_at = if resource.get_updated_at().is_empty() {
            created_at.clone()
        } else {
            resource.get_updated_at().to_owned()
        };

        if self.on_duplicate != OnDuplicate::Fail {
            let existing = db
                .get_document(META_DATABASES, resource.get_id(), &[], false)
                .unwrap_or_else(|_| Document::new());
            let mut action = self.on_duplicate.resolve_schema_action(
                !existing.is_empty(),
                Some(updated_at.as_str()).filter(|s| !s.is_empty()),
                existing.get_updated_at().as_deref(),
            );
            let is_failed = !existing.is_empty()
                && self.get_support_for_database_status(db)
                && existing.get_attribute("status").as_str() == Some(DATABASE_STATUS_FAILED);
            if is_failed {
                action = SchemaAction::Overwrite;
            }
            match action {
                SchemaAction::Skip => {
                    if let Some(seq) = existing.get_sequence() {
                        resource.set_sequence(seq);
                    }
                    resource.set_status(STATUS_SKIPPED, "Already exists on destination");
                    return false;
                }
                SchemaAction::Overwrite | SchemaAction::Create => {}
            }
        }

        let type_ = if resource.get_type().is_empty() {
            "legacy"
        } else {
            resource.get_type()
        };
        let original = if resource.get_original_id().is_empty() {
            Value::Null
        } else {
            json!(resource.get_original_id())
        };
        let dsn = self.resolve_destination_dsn(resource);
        let mut pairs: Vec<(&str, AttrValue)> = vec![
            ("$id", AttrValue::from(Id::custom(resource.get_id()))),
            ("name", AttrValue::from(resource.get_database_name())),
            ("enabled", AttrValue::from(resource.get_enabled())),
            (
                "search",
                AttrValue::from(format!(
                    "{} {}",
                    resource.get_id(),
                    resource.get_database_name()
                )),
            ),
            ("$createdAt", AttrValue::from(created_at)),
            ("$updatedAt", AttrValue::from(updated_at)),
            ("originalId", AttrValue::from(original)),
            ("type", AttrValue::from(type_)),
            ("database", AttrValue::from(dsn)),
        ];
        let support_status = self.get_support_for_database_status(db);
        if support_status {
            pairs.push(("status", AttrValue::from(DATABASE_STATUS_PROVISIONING)));
        }
        let document = match Document::from_pairs(pairs) {
            Ok(d) => d,
            Err(e) => {
                resource.set_status(STATUS_ERROR, e.to_string());
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    e.to_string(),
                    Exception::CODE_INTERNAL,
                ));
                return false;
            }
        };
        match db.create_document(META_DATABASES, document) {
            Ok(created) => {
                if let Some(seq) = created.get_sequence() {
                    resource.set_sequence(seq);
                }
                let collection_id = database_collection_id(&created);
                let columns = docs_from_json(&self.collection_structure.attributes);
                let indexes = docs_from_json(&self.collection_structure.indexes);
                if let Err(e) = db.create_collection(&collection_id, columns, indexes, None, true) {
                    if support_status {
                        let fail = Document::from_pairs([(
                            "status",
                            AttrValue::from(DATABASE_STATUS_FAILED),
                        )])
                        .unwrap_or_else(|_| Document::new());
                        let _ = db.update_document(META_DATABASES, resource.get_id(), fail);
                    }
                    resource.set_status(STATUS_ERROR, e.to_string());
                    self.add_error(Exception::new(
                        resource.get_name(),
                        resource.get_group(),
                        Some(resource.get_id().to_owned()),
                        e.to_string(),
                        Exception::CODE_INTERNAL,
                    ));
                    return false;
                }
                if support_status {
                    self.provisioning_databases
                        .insert(resource.get_id().to_owned());
                }
                true
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
                false
            }
        }
    }

    fn create_entity(&mut self, db: &mut Database<A>, resource: &mut Table) -> bool {
        if resource.get_id() == "unique()" {
            let Ok(id) = Id::unique() else {
                resource.set_status(STATUS_ERROR, "Failed to generate a unique ID");
                return false;
            };
            resource.set_id(id);
        }
        let uid = Uid::default();
        if !uid.is_valid(&json!(resource.get_id())) {
            resource.set_status(STATUS_ERROR, uid.description());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                uid.description(),
                Exception::CODE_VALIDATION,
            ));
            return false;
        }
        let database =
            match db.get_document(META_DATABASES, resource.get_database().get_id(), &[], false) {
                Ok(d) if !d.is_empty() => d,
                _ => {
                    resource.set_status(STATUS_ERROR, "Database not found");
                    self.add_error(Exception::new(
                        resource.get_name(),
                        resource.get_group(),
                        Some(resource.get_id().to_owned()),
                        "Database not found",
                        Exception::CODE_NOT_FOUND,
                    ));
                    return false;
                }
            };
        if !db.exists(None, Some(METADATA)).unwrap_or(false) {
            let _ = db.create(None);
        }

        let created_at = resource.get_created_at().to_owned();
        let updated_at = if resource.get_updated_at().is_empty() {
            created_at.clone()
        } else {
            resource.get_updated_at().to_owned()
        };
        let db_collection = database_collection_id(&database);

        if self.on_duplicate != OnDuplicate::Fail {
            let existing = db
                .get_document(&db_collection, resource.get_id(), &[], false)
                .unwrap_or_else(|_| Document::new());
            let action = self.on_duplicate.resolve_schema_action(
                !existing.is_empty(),
                Some(updated_at.as_str()).filter(|s| !s.is_empty()),
                existing.get_updated_at().as_deref(),
            );
            if action == SchemaAction::Skip {
                if let Some(seq) = existing.get_sequence() {
                    resource.set_sequence(seq);
                }
                resource.set_status(STATUS_SKIPPED, "Already exists on destination");
                return false;
            }
        }

        let document = match Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(resource.get_id()))),
            (
                "databaseInternalId",
                AttrValue::from(database.get_sequence().unwrap_or_default()),
            ),
            (
                "databaseId",
                AttrValue::from(resource.get_database().get_id()),
            ),
            (
                "$permissions",
                AttrValue::from(resource.get_permissions().to_vec()),
            ),
            (
                "documentSecurity",
                AttrValue::from(resource.get_row_security()),
            ),
            ("enabled", AttrValue::from(resource.get_enabled())),
            ("name", AttrValue::from(resource.get_table_name())),
            (
                "search",
                AttrValue::from(format!(
                    "{} {}",
                    resource.get_id(),
                    resource.get_table_name()
                )),
            ),
            ("$createdAt", AttrValue::from(created_at)),
            ("$updatedAt", AttrValue::from(updated_at)),
            ("attributes", AttrValue::from(Vec::<AttrValue>::new())),
            ("indexes", AttrValue::from(Vec::<AttrValue>::new())),
        ]) {
            Ok(d) => d,
            Err(e) => {
                resource.set_status(STATUS_ERROR, e.to_string());
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    e.to_string(),
                    Exception::CODE_INTERNAL,
                ));
                return false;
            }
        };
        match db.create_document(&db_collection, document) {
            Ok(table) => {
                if let Some(seq) = table.get_sequence() {
                    resource.set_sequence(seq);
                }
                let table_collection = table_collection_id(&database, &table);
                if let Err(e) = db.create_collection(
                    &table_collection,
                    Vec::new(),
                    Vec::new(),
                    Some(resource.get_permissions().to_vec()),
                    resource.get_row_security(),
                ) {
                    resource.set_status(STATUS_ERROR, e.to_string());
                    self.add_error(Exception::new(
                        resource.get_name(),
                        resource.get_group(),
                        Some(resource.get_id().to_owned()),
                        e.to_string(),
                        Exception::CODE_INTERNAL,
                    ));
                    return false;
                }
                true
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
                false
            }
        }
    }

    fn create_field_from_attribute(
        &mut self,
        db: &mut Database<A>,
        resource: &crate::resources::database::Attribute,
    ) -> bool {
        let mut column = Column::new(resource.get_key(), resource.get_table().clone());
        column.set_size(resource.get_size());
        column.set_required(resource.is_required());
        column.set_default(resource.get_default().clone());
        column.set_array(resource.is_array());
        column.set_signed(resource.is_signed());
        column.set_format(resource.get_format());
        column.set_created_at(resource.get_created_at());
        column.set_updated_at(resource.get_updated_at());
        self.create_field(db, &mut column)
    }

    fn create_field(&mut self, db: &mut Database<A>, resource: &mut Column) -> bool {
        if resource.get_table().get_database().get_type() == TYPE_DATABASE_DOCUMENTSDB {
            resource.set_status(STATUS_SKIPPED, "Columns not supported for DocumentsDB");
            return false;
        }
        let type_ = match column_var_type(resource.get_type()) {
            Ok(t) => t,
            Err(msg) => {
                resource.set_status(STATUS_ERROR, &msg);
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    msg,
                    Exception::CODE_VALIDATION,
                ));
                return false;
            }
        };
        if !resource.get_format().is_empty() && !Structure::has_format(resource.get_format(), type_)
        {
            let msg = format!(
                "Format {} not available for column type {type_}",
                resource.get_format()
            );
            resource.set_status(STATUS_ERROR, &msg);
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                msg,
                Exception::CODE_VALIDATION,
            ));
            return false;
        }
        let database = match db.get_document(
            META_DATABASES,
            resource.get_table().get_database().get_id(),
            &[],
            false,
        ) {
            Ok(d) if !d.is_empty() => d,
            _ => {
                resource.set_status(STATUS_ERROR, "Database not found");
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    "Database not found",
                    Exception::CODE_NOT_FOUND,
                ));
                return false;
            }
        };
        let db_collection = database_collection_id(&database);
        let mut table =
            match db.get_document(&db_collection, resource.get_table().get_id(), &[], false) {
                Ok(d) if !d.is_empty() => d,
                _ => {
                    resource.set_status(STATUS_ERROR, "Table not found");
                    self.add_error(Exception::new(
                        resource.get_name(),
                        resource.get_group(),
                        Some(resource.get_id().to_owned()),
                        "Table not found",
                        Exception::CODE_NOT_FOUND,
                    ));
                    return false;
                }
            };
        let meta_id = attribute_index_meta_id(&database, &table, resource.get_key());
        let created_at = resource.get_created_at().to_owned();
        let updated_at = if resource.get_updated_at().is_empty() {
            created_at.clone()
        } else {
            resource.get_updated_at().to_owned()
        };
        let column = match Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(&meta_id))),
            ("key", AttrValue::from(resource.get_key())),
            (
                "databaseInternalId",
                AttrValue::from(database.get_sequence().unwrap_or_default()),
            ),
            ("databaseId", AttrValue::from(database.get_id())),
            (
                "collectionInternalId",
                AttrValue::from(table.get_sequence().unwrap_or_default()),
            ),
            ("collectionId", AttrValue::from(table.get_id())),
            ("type", AttrValue::from(type_)),
            ("status", AttrValue::from("available")),
            ("size", AttrValue::from(resource.get_size())),
            ("required", AttrValue::from(resource.is_required())),
            ("signed", AttrValue::from(resource.is_signed())),
            ("default", AttrValue::from(resource.get_default().clone())),
            ("array", AttrValue::from(resource.is_array())),
            ("format", AttrValue::from(resource.get_format())),
            (
                "formatOptions",
                AttrValue::from(map_to_index(resource.get_format_options())),
            ),
            ("filters", AttrValue::from(resource.get_filters().to_vec())),
            (
                "options",
                AttrValue::from(map_to_index(resource.get_options())),
            ),
            ("$createdAt", AttrValue::from(created_at)),
            ("$updatedAt", AttrValue::from(updated_at)),
        ]) {
            Ok(d) => d,
            Err(e) => {
                resource.set_status(STATUS_ERROR, e.to_string());
                return false;
            }
        };
        if let Err(e) = db.create_document(META_ATTRIBUTES, column.clone()) {
            resource.set_status(STATUS_ERROR, e.to_string());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                e.to_string(),
                Exception::CODE_INTERNAL,
            ));
            return false;
        }
        let table_collection = table_collection_id(&database, &table);
        let format = if resource.get_format().is_empty() {
            None
        } else {
            Some(resource.get_format())
        };
        if let Err(e) = db.create_attribute(
            &table_collection,
            resource.get_key(),
            type_,
            resource.get_size(),
            resource.is_required(),
            AttrValue::from(resource.get_default().clone()),
            resource.is_signed(),
            resource.is_array(),
            format,
            map_to_index(resource.get_format_options()),
            resource.get_filters().to_vec(),
        ) {
            let _ = db.delete_document(META_ATTRIBUTES, &meta_id);
            resource.set_status(STATUS_ERROR, e.to_string());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                e.to_string(),
                Exception::CODE_INTERNAL,
            ));
            return false;
        }
        // Subquery filters are not registered in Rust; keep `attributes` on the
        // table meta-document in sync so IndexValidator sees the same shape.
        let mut attrs = documents_from_attr(table.get_attribute("attributes"));
        attrs.push(column);
        table.set_attribute(
            "attributes",
            AttrValue::from(attrs.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
        );
        let _ = db.update_document(&db_collection, &table.get_id(), table);
        true
    }

    fn create_index(&mut self, db: &mut Database<A>, resource: &mut Index) -> bool {
        let database = match db.get_document(
            META_DATABASES,
            resource.get_table().get_database().get_id(),
            &[],
            false,
        ) {
            Ok(d) if !d.is_empty() => d,
            _ => {
                resource.set_status(STATUS_ERROR, "Database not found");
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    "Database not found",
                    Exception::CODE_NOT_FOUND,
                ));
                return false;
            }
        };
        let db_collection = database_collection_id(&database);
        let table = match db.get_document(&db_collection, resource.get_table().get_id(), &[], false)
        {
            Ok(d) if !d.is_empty() => d,
            _ => {
                resource.set_status(STATUS_ERROR, "Table not found");
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    "Table not found",
                    Exception::CODE_NOT_FOUND,
                ));
                return false;
            }
        };
        let created_at = resource.get_created_at().to_owned();
        let updated_at = if resource.get_updated_at().is_empty() {
            created_at.clone()
        } else {
            resource.get_updated_at().to_owned()
        };
        let meta_id = attribute_index_meta_id(&database, &table, resource.get_key());

        if self.on_duplicate != OnDuplicate::Fail {
            let existing = db
                .get_document(META_INDEXES, &meta_id, &[], false)
                .unwrap_or_else(|_| Document::new());
            let mut action = self.on_duplicate.resolve_schema_action(
                !existing.is_empty(),
                Some(updated_at.as_str()).filter(|s| !s.is_empty()),
                existing.get_updated_at().as_deref(),
            );
            if action != SchemaAction::Create && index_spec_matches(&existing, resource) {
                action = SchemaAction::Skip;
            }
            if action == SchemaAction::Skip {
                resource.set_status(STATUS_SKIPPED, "Already exists on destination");
                return false;
            }
            if action == SchemaAction::Overwrite && !existing.is_empty() {
                let _ =
                    db.delete_index(&table_collection_id(&database, &table), resource.get_key());
                let _ = db.delete_document(META_INDEXES, &meta_id);
            }
        }

        let lengths = match self.prefix_lengths(resource, &table) {
            Ok(l) => l,
            Err(msg) => {
                resource.set_status(STATUS_ERROR, &msg);
                self.add_error(Exception::new(
                    resource.get_name(),
                    resource.get_group(),
                    Some(resource.get_id().to_owned()),
                    msg,
                    Exception::CODE_VALIDATION,
                ));
                return false;
            }
        };

        let index = match Document::from_pairs([
            ("$id", AttrValue::from(Id::custom(&meta_id))),
            ("key", AttrValue::from(resource.get_key())),
            ("status", AttrValue::from("available")),
            (
                "databaseInternalId",
                AttrValue::from(database.get_sequence().unwrap_or_default()),
            ),
            ("databaseId", AttrValue::from(database.get_id())),
            (
                "collectionInternalId",
                AttrValue::from(table.get_sequence().unwrap_or_default()),
            ),
            ("collectionId", AttrValue::from(table.get_id())),
            ("type", AttrValue::from(resource.get_type())),
            (
                "attributes",
                AttrValue::from(resource.get_columns().to_vec()),
            ),
            (
                "lengths",
                AttrValue::from(
                    lengths
                        .iter()
                        .copied()
                        .map(AttrValue::from)
                        .collect::<Vec<_>>(),
                ),
            ),
            ("orders", AttrValue::from(resource.get_orders().to_vec())),
            ("$createdAt", AttrValue::from(created_at)),
            ("$updatedAt", AttrValue::from(updated_at)),
        ]) {
            Ok(d) => d,
            Err(e) => {
                resource.set_status(STATUS_ERROR, e.to_string());
                return false;
            }
        };

        let table_columns = documents_from_attr(table.get_attribute("attributes"));
        let table_indexes = documents_from_attr(table.get_attribute("indexes"));
        let adapter = db.get_adapter();
        let validator = IndexValidator::new(
            table_columns,
            table_indexes,
            adapter.get_max_index_length(),
            adapter.get_internal_indexes_keys(),
            adapter.get_support_for_index_array(),
            adapter.get_support_for_spatial_index_null(),
            adapter.get_support_for_spatial_index_order(),
            adapter.get_support_for_vectors(),
            adapter.get_support_for_attributes(),
            adapter.get_support_for_multiple_fulltext_indexes(),
            adapter.get_support_for_identical_indexes(),
            adapter.get_support_for_object_indexes(),
            adapter.get_support_for_trigram_index(),
            adapter.get_support_for_spatial_attributes(),
            adapter.get_support_for_index(),
            adapter.get_support_for_unique_index(),
            adapter.get_support_for_fulltext_index(),
            adapter.get_support_for_ttl_indexes(),
            adapter.get_support_for_object(),
        );
        if !validator.is_valid_document(&index) {
            let msg = format!("Invalid index: {}", validator.description());
            resource.set_status(STATUS_ERROR, &msg);
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                msg,
                Exception::CODE_VALIDATION,
            ));
            return false;
        }
        if let Err(e) = db.create_document(META_INDEXES, index) {
            resource.set_status(STATUS_ERROR, e.to_string());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                e.to_string(),
                Exception::CODE_INTERNAL,
            ));
            return false;
        }
        let table_collection = table_collection_id(&database, &table);
        if let Err(e) = db.create_index(
            &table_collection,
            resource.get_key(),
            resource.get_type(),
            resource.get_columns().to_vec(),
            lengths,
            resource.get_orders().to_vec(),
            0,
        ) {
            let _ = db.delete_document(META_INDEXES, &meta_id);
            resource.set_status(STATUS_ERROR, e.to_string());
            self.add_error(Exception::new(
                resource.get_name(),
                resource.get_group(),
                Some(resource.get_id().to_owned()),
                e.to_string(),
                Exception::CODE_INTERNAL,
            ));
            return false;
        }
        true
    }

    fn prefix_lengths(&self, resource: &Index, table: &Document) -> Result<Vec<i64>, String> {
        let mut old_columns: Vec<Map<String, Value>> =
            documents_from_attr(table.get_attribute("attributes"))
                .into_iter()
                .map(|d| {
                    let mut m = Map::new();
                    m.insert(
                        "key".into(),
                        json!(d.get_attribute("key").as_str().unwrap_or("")),
                    );
                    m.insert(
                        "type".into(),
                        json!(d.get_attribute("type").as_str().unwrap_or("")),
                    );
                    m.insert(
                        "status".into(),
                        json!(d.get_attribute("status").as_str().unwrap_or("available")),
                    );
                    m.insert(
                        "array".into(),
                        json!(d.get_attribute("array").as_bool().unwrap_or(false)),
                    );
                    m.insert(
                        "size".into(),
                        json!(d.get_attribute("size").as_i64().unwrap_or(0)),
                    );
                    m
                })
                .collect();
        old_columns.push(
            json!({
                "key": "$id",
                "type": VAR_STRING,
                "status": "available",
                "required": true,
                "array": false,
                "default": Value::Null,
                "size": LENGTH_KEY,
            })
            .as_object()
            .cloned()
            .unwrap_or_default(),
        );
        old_columns.push(
            json!({
                "key": "$createdAt",
                "type": VAR_DATETIME,
                "status": "available",
                "signed": false,
                "required": false,
                "array": false,
                "default": Value::Null,
                "size": 0,
            })
            .as_object()
            .cloned()
            .unwrap_or_default(),
        );
        old_columns.push(
            json!({
                "key": "$updatedAt",
                "type": VAR_DATETIME,
                "status": "available",
                "signed": false,
                "required": false,
                "array": false,
                "default": Value::Null,
                "size": 0,
            })
            .as_object()
            .cloned()
            .unwrap_or_default(),
        );

        let mut lengths = vec![0i64; resource.get_columns().len()];
        for (i, column) in resource.get_columns().iter().enumerate() {
            let found = old_columns
                .iter()
                .find(|c| c.get("key").and_then(Value::as_str) == Some(column.as_str()));
            let Some(col) = found else {
                return Err(format!("Column not found in table: {column}"));
            };
            if col.get("type").and_then(Value::as_str) == Some(VAR_RELATIONSHIP) {
                return Err("Relationship columns are not supported in indexes".into());
            }
            if col.get("status").and_then(Value::as_str) != Some("available") {
                return Err(format!("Column not available: {column}"));
            }
            let source_length = resource.get_lengths().get(i).copied();
            lengths[i] = match source_length {
                Some(l) if l > 0 => l,
                _ => 0,
            };
            if col.get("array").and_then(Value::as_bool).unwrap_or(false) {
                lengths[i] = MAX_ARRAY_INDEX_LENGTH;
            }
        }
        Ok(lengths)
    }
}

fn column_var_type(type_: &str) -> Result<&'static str, String> {
    Ok(match type_ {
        Column::TYPE_DATETIME => VAR_DATETIME,
        Column::TYPE_BOOLEAN => VAR_BOOLEAN,
        Column::TYPE_INTEGER => VAR_INTEGER,
        Column::TYPE_BIG_INT => VAR_BIGINT,
        Column::TYPE_FLOAT => VAR_FLOAT,
        Column::TYPE_RELATIONSHIP => VAR_RELATIONSHIP,
        Column::TYPE_STRING
        | Column::TYPE_IP
        | Column::TYPE_EMAIL
        | Column::TYPE_URL
        | Column::TYPE_ENUM => VAR_STRING,
        Column::TYPE_POINT => VAR_POINT,
        Column::TYPE_LINE => VAR_LINESTRING,
        Column::TYPE_POLYGON => VAR_POLYGON,
        Column::TYPE_TEXT => VAR_TEXT,
        Column::TYPE_VARCHAR => VAR_VARCHAR,
        Column::TYPE_MEDIUMTEXT => VAR_MEDIUMTEXT,
        Column::TYPE_LONGTEXT => VAR_LONGTEXT,
        Column::TYPE_OBJECT => VAR_OBJECT,
        Column::TYPE_VECTOR => VAR_VECTOR,
        other => return Err(format!("Invalid resource type {other}")),
    })
}

fn database_collection_id(database: &Document) -> String {
    format!("database_{}", database.get_sequence().unwrap_or_default())
}

fn table_collection_id(database: &Document, table: &Document) -> String {
    format!(
        "{}_collection_{}",
        database_collection_id(database),
        table.get_sequence().unwrap_or_default()
    )
}

fn attribute_index_meta_id(database: &Document, table: &Document, key: &str) -> String {
    format!(
        "{}_{}_{key}",
        database.get_sequence().unwrap_or_default(),
        table.get_sequence().unwrap_or_default()
    )
}

fn docs_from_json(values: &[Value]) -> Vec<Document> {
    values
        .iter()
        .filter_map(|v| Document::try_from_json(v.clone()).ok())
        .collect()
}

fn documents_from_attr(value: &AttrValue) -> Vec<Document> {
    match value {
        AttrValue::Array(items) => items
            .values()
            .filter_map(|v| match v {
                AttrValue::Document(d) => Some(d.as_ref().clone()),
                other => {
                    let mut pairs = Vec::new();
                    if let Some(map) = other.as_array() {
                        for (k, val) in map {
                            pairs.push((k.clone(), val.clone()));
                        }
                        Document::from_pairs(pairs).ok()
                    } else {
                        None
                    }
                }
            })
            .collect(),
        AttrValue::Document(d) => vec![d.as_ref().clone()],
        _ => Vec::new(),
    }
}

fn map_to_index(map: &Map<String, Value>) -> IndexMap<String, AttrValue> {
    let mut out = IndexMap::new();
    for (k, v) in map {
        out.insert(k.clone(), AttrValue::from(v.clone()));
    }
    out
}

fn index_spec_matches(existing: &Document, resource: &Index) -> bool {
    if existing.is_empty() {
        return false;
    }
    if existing.get_attribute("type").as_str() != Some(resource.get_type()) {
        return false;
    }
    let existing_attrs: Vec<String> = existing
        .get_attribute("attributes")
        .as_array()
        .map(|a| {
            a.values()
                .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                .collect()
        })
        .unwrap_or_default();
    if existing_attrs != resource.get_columns() {
        return false;
    }
    let existing_orders: Vec<String> = existing
        .get_attribute("orders")
        .as_array()
        .map(|a| {
            a.values()
                .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                .collect()
        })
        .unwrap_or_default();
    if existing_orders != resource.get_orders() {
        return false;
    }
    let existing_lengths: Vec<i64> = existing
        .get_attribute("lengths")
        .as_array()
        .map(|a| a.values().map(|v| v.as_i64().unwrap_or(0)).collect())
        .unwrap_or_default();
    let wanted = resource.get_lengths();
    for position in 0..resource.get_columns().len() {
        let have = existing_lengths.get(position).copied().unwrap_or(0);
        let want = wanted.get(position).copied().unwrap_or(0);
        if have != want {
            return false;
        }
    }
    true
}

impl<A: Adapter> Target for Appwrite<A> {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl<A: Adapter> Destination for Appwrite<A> {
    fn name() -> &'static str {
        "Appwrite"
    }
    fn supported_resources() -> &'static [&'static str] {
        ALL_RESOURCES
    }
    fn selector(&self) -> Option<&ResourceSelector> {
        self.common.selector.as_ref()
    }
    fn set_selector(&mut self, selector: Option<ResourceSelector>) {
        self.common.selector = selector;
    }
    fn set_source_supports_database_status(&mut self, supports: bool) {
        self.source_supports_database_status = supports;
        self.database_status_supported = None;
    }
    fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        root_resource_id: &str,
        root_resource_type: &str,
    ) {
        self.run_count += 1;
        self.provisioning_databases.clear();
        dest_run(
            self,
            source,
            resources,
            callback,
            root_resource_id,
            root_resource_type,
        );
        self.mark_provisioned_databases_ready();
    }
    fn import(&mut self, resources: Vec<AnyResource>, callback: &mut dyn FnMut(Vec<AnyResource>)) {
        if resources.is_empty() {
            return;
        }
        if self.db.is_none() {
            callback(resources);
            return;
        }
        let processed = self.with_db(|dest, db| {
            let mut out = Vec::new();
            for mut resource in resources {
                resource.set_status(STATUS_PROCESSING, "");
                let success = if resource.get_group() == GROUP_DATABASES {
                    dest.import_database_resource(db, &mut resource)
                } else {
                    #[cfg(feature = "appwrite-http")]
                    {
                        let _ = db;
                        false
                    }
                    #[cfg(not(feature = "appwrite-http"))]
                    {
                        let _ = db;
                        resource.set_status(
                            STATUS_SKIPPED,
                            "HTTP import requires the appwrite-http feature",
                        );
                        false
                    }
                };
                if success {
                    resource.set_status(STATUS_SUCCESS, "");
                }
                if let Some(cache) = dest.state().cache() {
                    if let Ok(mut guard) = cache.lock() {
                        let mut cached = resource.clone();
                        guard.update(&mut cached);
                    }
                }
                out.push(resource);
            }
            out
        });
        callback(processed.unwrap_or_default());
    }
    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        Ok(HashMap::new())
    }
}
