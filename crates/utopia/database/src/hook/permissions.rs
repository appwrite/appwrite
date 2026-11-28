//! [`Permissions`]: keeps the `_perms` side table of a SQL adapter in step
//! with the documents it writes.

use std::collections::HashMap;

use async_trait::async_trait;
use utopia_query::Query;
use utopia_query::builder::Statement;

use super::{SqlBuilder, Write, WriteContext};
use crate::change::Change;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::Event;
use crate::permission::PermissionType;
use crate::query::query_error;
use crate::storage;
use crate::value::{Array, ArrayKey, Str, Value};

/// The permission types a document stores rows for (`PERMISSION_TYPES`).
const PERMISSION_TYPES: [PermissionType; 4] =
    [PermissionType::Create, PermissionType::Read, PermissionType::Update, PermissionType::Delete];

/// Runs `$body` on the dialect builder inside a [`SqlBuilder`].
macro_rules! on {
    ($builder:expr, $b:ident => $body:expr) => {
        match $builder {
            SqlBuilder::MySql($b) => $body,
            SqlBuilder::MariaDb($b) => $body,
            SqlBuilder::PostgreSql($b) => $body,
            SqlBuilder::Sqlite($b) => $body,
        }
    };
}

/// A document's current permissions: roles by permission type.
type Current = HashMap<String, Vec<String>>;

/// The write hook that stores each document's permissions as rows of the
/// collection's `_perms` table: inserted on create, replaced on update,
/// moved on a renamed id, removed on delete. A SQL adapter registers it.
///
/// PHP: `Utopia\Database\Hook\Permissions`.
#[derive(Debug, Default, Clone)]
pub struct Permissions;

impl Permissions {
    /// The hook (`new Permissions()`).
    pub fn new() -> Self {
        Permissions
    }
}

/// A builder inserting into the collection's permissions table
/// (`builder()->into(rawTable(permissionsTable($collection)))`).
fn insert_builder(collection: &str, context: &dyn WriteContext) -> SqlBuilder {
    let mut builder = context.builder();
    let table = context.raw_table(&storage::permissions_table(collection));
    on!(&mut builder, b => { b.into(table.as_str()); });
    builder
}

/// A builder over the collection's permissions table, scoped to the
/// adapter's tenant (`builder()->from(permissionsTable($collection))`).
fn from_builder(collection: &str, context: &dyn WriteContext) -> SqlBuilder {
    let mut builder = context.builder();
    let table = storage::permissions_table(collection);
    on!(&mut builder, b => { b.from(table.as_str(), ""); });
    builder
}

/// Adds a row to an insert (`set()`).
fn set(builder: &mut SqlBuilder, row: &Array) -> Result<()> {
    let row = crate::value::to_query_array(row);
    on!(builder, b => b.set(row).map(|_| ()).map_err(query_error))
}

/// Adds filters (`filter()`).
fn filter(builder: &mut SqlBuilder, queries: Vec<Query>) {
    on!(builder, b => { b.filter(queries); });
}

/// Compiles an insert (`insert()`).
fn insert(builder: &mut SqlBuilder) -> Result<Statement> {
    on!(builder, b => b.insert().map_err(query_error))
}

/// Compiles an insert that skips duplicates (`insertOrIgnore()`).
fn insert_or_ignore(builder: &mut SqlBuilder) -> Result<Statement> {
    on!(builder, b => b.insert_or_ignore().map_err(query_error))
}

/// Compiles a delete (`delete()`).
fn delete(builder: &mut SqlBuilder) -> Result<Statement> {
    on!(builder, b => b.delete().map_err(query_error))
}

/// `Query::equal($attribute, $values)` of strings.
fn equal<S: AsRef<str>>(attribute: &str, values: &[S]) -> Query {
    Query::equal(
        attribute,
        utopia_query::Array::from_list(
            values.iter().map(|v| utopia_query::Value::Str(Str::from(v.as_ref()))).collect(),
        ),
    )
}

/// A permission row: `_document`, `_type`, `_permission`.
fn row(document: &str, kind: &str, permission: &str) -> Array {
    [
        (storage::PERMISSIONS_DOCUMENT, document),
        (storage::PERMISSIONS_TYPE, kind),
        (storage::PERMISSIONS_PERMISSION, permission),
    ]
    .into_iter()
    .map(|(k, v)| (ArrayKey::Str(Str::from_static(k)), Value::Str(Str::from(v))))
    .collect()
}

/// `array_values(array_diff($a, $b))` of strings.
fn diff(a: &[String], b: &[String]) -> Vec<String> {
    a.iter().filter(|x| !b.contains(x)).cloned().collect()
}

/// `array_values(array_unique(array_diff($desired, $current)))`
/// (`uniqueAdditions()`).
fn unique_additions(desired: &[String], current: &[String]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for permission in diff(desired, current) {
        if !out.contains(&permission) {
            out.push(permission);
        }
    }
    out
}

/// No permission of any type (`emptyPermissions()`).
fn empty_permissions() -> Current {
    PERMISSION_TYPES.iter().map(|t| (t.as_str().to_owned(), Vec::new())).collect()
}

/// The roles of `kind` in `permissions`.
fn of_kind(permissions: &Current, kind: PermissionType) -> &[String] {
    permissions.get(kind.as_str()).map(Vec::as_slice).unwrap_or(&[])
}

/// A row field as a string, `None` when missing or `null`.
fn field(row: &Array, key: &str) -> Option<String> {
    match row.get_str(key) {
        None | Some(Value::Null) => None,
        Some(v) => Some(crate::value::to_text(v)),
    }
}

impl Permissions {
    /// Every document's permission rows (`buildPermissionRows()`), each as
    /// the write hooks decorate it.
    fn build_permission_rows(document: &Document, context: &dyn WriteContext) -> Result<Vec<Array>> {
        let mut rows = Vec::new();
        for kind in PERMISSION_TYPES {
            for permission in document.permissions_by_type(kind.as_str())? {
                let permission = permission.replace('"', "");
                rows.push(context.decorate_row(row(document.id(), kind.as_str(), &permission), document));
            }
        }
        Ok(rows)
    }

    /// The current permissions of documents in one read, and the ids they
    /// are stored under (`readCurrentPermissionsBatch()`).
    async fn read_current_permissions_batch(
        collection: &str,
        documents: &[&Document],
        context: &dyn WriteContext,
    ) -> Result<(HashMap<String, Current>, Vec<(String, String)>)> {
        if documents.is_empty() {
            return Ok((HashMap::new(), Vec::new()));
        }

        let ids = permission_read_ids(documents);
        if ids.is_empty() {
            return Ok((HashMap::new(), Vec::new()));
        }

        let mut builder = from_builder(collection, context);
        on!(&mut builder, b => {
            b.select([storage::PERMISSIONS_DOCUMENT, storage::PERMISSIONS_TYPE, storage::PERMISSIONS_PERMISSION]);
        });
        filter(&mut builder, vec![equal(storage::PERMISSIONS_DOCUMENT, &ids)]);
        let statement = on!(&mut builder, b => b.build().map_err(query_error))?;

        let rows = context.fetch(statement, Event::PermissionsRead).await?;

        Ok((group_permission_rows(&ids, &rows), stored_document_ids(&ids, &rows)))
    }

    /// Deletes the permission rows of a document listed in `removals`
    /// (`deletePermissions()`).
    async fn delete_permissions(
        collection: &str,
        document_id: &str,
        removals: &[(String, Vec<String>)],
        context: &dyn WriteContext,
    ) -> Result<()> {
        if removals.is_empty() {
            return Ok(());
        }

        let conditions = removals
            .iter()
            .map(|(kind, permissions)| {
                Query::and([
                    equal(storage::PERMISSIONS_DOCUMENT, &[document_id]),
                    equal(storage::PERMISSIONS_TYPE, &[kind]),
                    equal(storage::PERMISSIONS_PERMISSION, permissions),
                ])
            })
            .collect::<Vec<_>>();

        let mut builder = from_builder(collection, context);
        filter(&mut builder, vec![Query::or(conditions)]);
        context.run(delete(&mut builder)?, Event::PermissionsDelete).await?;
        Ok(())
    }

    /// Inserts the permission rows of a document listed in `additions`
    /// (`insertPermissions()`).
    async fn insert_permissions(
        collection: &str,
        document: &Document,
        document_id: &str,
        additions: &[(String, Vec<String>)],
        context: &dyn WriteContext,
    ) -> Result<()> {
        if additions.is_empty() {
            return Ok(());
        }

        let mut builder = insert_builder(collection, context);
        for (kind, permissions) in additions {
            for permission in unique_additions(permissions, &[]) {
                set(&mut builder, &context.decorate_row(row(document_id, kind, &permission), document))?;
            }
        }

        context.run(insert(&mut builder)?, Event::PermissionsCreate).await?;
        Ok(())
    }

    /// A renamed document leaves its rows keyed by the old id, which nothing
    /// reads any more, so they are dropped and the full set is written
    /// under the new id (`movePermissions()`).
    async fn move_permissions(
        &self,
        collection: &str,
        previous_id: &str,
        document: &Document,
        context: &dyn WriteContext,
    ) -> Result<()> {
        let mut builder = from_builder(collection, context);
        filter(&mut builder, vec![equal(storage::PERMISSIONS_DOCUMENT, &[previous_id])]);
        context.run(delete(&mut builder)?, Event::PermissionsDelete).await?;

        self.after_document_create(collection, std::slice::from_ref(document), context).await
    }
}

/// The ids to read permissions for: each document's non-empty id, once
/// (`permissionReadIds()`).
fn permission_read_ids(documents: &[&Document]) -> Vec<String> {
    let mut ids: Vec<String> = Vec::new();
    for document in documents {
        let id = document.id();
        if !id.is_empty() && !ids.iter().any(|i| i == id) {
            ids.push(id.to_owned());
        }
    }
    ids
}

/// The id each requested id is stored under, compared case-insensitively
/// (`storedDocumentIds()`), in PHP's insertion order.
fn stored_document_ids(ids: &[String], rows: &[Array]) -> Vec<(String, String)> {
    let mut stored: Vec<(String, String)> = Vec::new();
    for row in rows {
        let Some(Value::Str(stored_id)) = row.get_str(storage::PERMISSIONS_DOCUMENT) else { continue };
        let stored_id = String::from_utf8_lossy(stored_id.as_bytes()).into_owned();
        if stored_id.is_empty() {
            continue;
        }
        for id in ids {
            if stored_id.eq_ignore_ascii_case(id) {
                match stored.iter_mut().find(|(k, _)| k == id) {
                    Some(entry) => entry.1 = stored_id.clone(),
                    None => stored.push((id.clone(), stored_id.clone())),
                }
            }
        }
    }
    stored
}

/// The id the permission rows of `requested` are stored under
/// (`permissionDocumentId()`).
fn permission_document_id(requested: &str, stored: &[(String, String)]) -> String {
    if let Some((_, id)) = stored.iter().find(|(k, _)| k == requested)
        && id.eq_ignore_ascii_case(requested)
    {
        return id.clone();
    }
    for (_, id) in stored {
        if id.eq_ignore_ascii_case(requested) {
            return id.clone();
        }
    }
    requested.to_owned()
}

/// The rows grouped by requested id and type (`groupPermissionRows()`).
fn group_permission_rows(ids: &[String], rows: &[Array]) -> HashMap<String, Current> {
    let mut result: HashMap<String, Current> = HashMap::new();
    let mut requested_by_lower: HashMap<String, Vec<String>> = HashMap::new();
    for id in ids {
        result.insert(id.clone(), empty_permissions());
        requested_by_lower.entry(id.to_ascii_lowercase()).or_default().push(id.clone());
    }

    for row in rows {
        let (Some(stored_id), Some(kind), Some(permission)) = (
            field(row, storage::PERMISSIONS_DOCUMENT),
            field(row, storage::PERMISSIONS_TYPE),
            field(row, storage::PERMISSIONS_PERMISSION),
        ) else {
            continue;
        };

        let targets = match requested_by_lower.get(&stored_id.to_ascii_lowercase()) {
            Some(targets) if !targets.is_empty() => targets.clone(),
            _ => vec![resolve_stored_document_id(&stored_id, &result, &requested_by_lower)],
        };

        for key in targets {
            result
                .entry(key)
                .or_insert_with(empty_permissions)
                .entry(kind.clone())
                .or_default()
                .push(permission.clone());
        }
    }

    result
}

/// `resolveStoredDocumentId()`.
fn resolve_stored_document_id(
    stored_id: &str,
    result: &HashMap<String, Current>,
    requested_by_lower: &HashMap<String, Vec<String>>,
) -> String {
    if result.contains_key(stored_id) {
        return stored_id.to_owned();
    }
    match requested_by_lower.get(&stored_id.to_ascii_lowercase()) {
        Some(candidates) if candidates.len() == 1 => candidates[0].clone(),
        _ => stored_id.to_owned(),
    }
}

/// An upsert batch can hold documents of several tenants, none of them the
/// adapter's, so its removals cannot take `from()`'s filter on the
/// adapter's tenant: each one is scoped to the tenant the write hooks store
/// its own document's rows under instead (`tenantScope()`).
fn tenant_scope(document: &Document, context: &dyn WriteContext) -> Vec<Query> {
    let row = context.decorate_row(Array::new(), document);
    let Some(tenant) = row.get_str(storage::TENANT) else {
        return Vec::new();
    };
    let tenant = match tenant {
        Value::Int(_) | Value::Str(_) => crate::value::to_query(tenant),
        _ => utopia_query::Value::Null,
    };
    vec![Query::equal(storage::TENANT, utopia_query::Array::from_list(vec![tenant]))]
}

#[async_trait]
impl Write for Permissions {
    /// Inserts the permission rows of every created document
    /// (`afterDocumentCreate()`); every SQL dialect has insert-or-ignore,
    /// so PHP's refusal of a dialect without it does not arise.
    async fn after_document_create(
        &self,
        collection: &str,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let mut builder = insert_builder(collection, context);
        let mut has_permissions = false;

        for document in documents {
            for row in Permissions::build_permission_rows(document, context)? {
                set(&mut builder, &row)?;
                has_permissions = true;
            }
        }

        if has_permissions {
            let statement =
                if context.ignore_duplicates() { insert_or_ignore(&mut builder)? } else { insert(&mut builder)? };
            context.run(statement, Event::PermissionsCreate).await?;
        }
        Ok(())
    }

    /// Diffs the current and the new permissions of one document and
    /// applies the removals and additions (`afterDocumentUpdate()`).
    async fn after_document_update(
        &self,
        collection: &str,
        id: &str,
        document: &Document,
        context: &dyn WriteContext,
    ) -> Result<()> {
        if context.skip_permissions(document) {
            return Ok(());
        }

        if !id.is_empty() && id != document.id() {
            return self.move_permissions(collection, id, document, context).await;
        }

        let (map, stored_ids) = Permissions::read_current_permissions_batch(collection, &[document], context).await?;
        let permissions = map.get(document.id()).cloned().unwrap_or_else(empty_permissions);
        let permission_document_id = permission_document_id(document.id(), &stored_ids);

        let mut removals: Vec<(String, Vec<String>)> = Vec::new();
        let mut additions: Vec<(String, Vec<String>)> = Vec::new();
        for kind in PERMISSION_TYPES {
            let wanted = document.permissions_by_type(kind.as_str())?;
            let removed = diff(of_kind(&permissions, kind), &wanted);
            if !removed.is_empty() {
                removals.push((kind.as_str().to_owned(), removed));
            }
            let added = unique_additions(&wanted, of_kind(&permissions, kind));
            if !added.is_empty() {
                additions.push((kind.as_str().to_owned(), added));
            }
        }

        Permissions::delete_permissions(collection, &permission_document_id, &removals, context).await?;
        Permissions::insert_permissions(collection, document, &permission_document_id, &additions, context).await
    }

    /// Diffs and syncs the permission rows of documents updated in a batch
    /// (`afterDocumentBatchUpdate()`), when the update sets permissions.
    async fn after_document_batch_update(
        &self,
        collection: &str,
        updates: &Document,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        if !updates.has(document::PERMISSIONS) {
            return Ok(());
        }

        let mut remove_conditions = Vec::new();
        let mut add_builder = insert_builder(collection, context);
        let mut has_additions = false;

        let eligible: Vec<&Document> = documents.iter().filter(|d| !context.skip_permissions(d)).collect();
        if eligible.is_empty() {
            return Ok(());
        }

        let (map, stored_ids) = Permissions::read_current_permissions_batch(collection, &eligible, context).await?;
        let mut updates_by_type: Current = HashMap::new();
        for kind in PERMISSION_TYPES {
            updates_by_type.insert(kind.as_str().to_owned(), updates.permissions_by_type(kind.as_str())?);
        }

        for document in &eligible {
            let permissions = map.get(document.id()).cloned().unwrap_or_else(empty_permissions);
            let permission_document_id = permission_document_id(document.id(), &stored_ids);

            for kind in PERMISSION_TYPES {
                let removed = diff(of_kind(&permissions, kind), of_kind(&updates_by_type, kind));
                if !removed.is_empty() {
                    remove_conditions.push(Query::and([
                        equal(storage::PERMISSIONS_DOCUMENT, &[&permission_document_id]),
                        equal(storage::PERMISSIONS_TYPE, &[kind.as_str()]),
                        equal(storage::PERMISSIONS_PERMISSION, &removed),
                    ]));
                }
            }

            for kind in PERMISSION_TYPES {
                for permission in unique_additions(of_kind(&updates_by_type, kind), of_kind(&permissions, kind)) {
                    let row = context.decorate_row(row(&permission_document_id, kind.as_str(), &permission), document);
                    set(&mut add_builder, &row)?;
                    has_additions = true;
                }
            }
        }

        if !remove_conditions.is_empty() {
            let mut remove_builder = from_builder(collection, context);
            filter(&mut remove_builder, vec![Query::or(remove_conditions)]);
            context.run(delete(&mut remove_builder)?, Event::PermissionsDelete).await?;
        }

        if has_additions {
            context.run(insert(&mut add_builder)?, Event::PermissionsCreate).await?;
        }
        Ok(())
    }

    /// Diffs the old and new permissions of upserted documents and applies
    /// the removals and additions (`afterDocumentUpsert()`).
    async fn after_document_upsert(
        &self,
        collection: &str,
        changes: &[Change],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let mut remove_conditions = Vec::new();
        let mut add_builder = insert_builder(collection, context);
        let mut has_additions = false;

        for change in changes {
            let old = &change.old;
            let document = &change.new;
            let tenant = tenant_scope(document, context);

            let mut current: Current = HashMap::new();
            for kind in PERMISSION_TYPES {
                current.insert(kind.as_str().to_owned(), old.permissions_by_type(kind.as_str())?);
            }

            for kind in PERMISSION_TYPES {
                let wanted = document.permissions_by_type(kind.as_str())?;
                let removed = diff(of_kind(&current, kind), &wanted);
                if !removed.is_empty() {
                    let mut conditions = vec![equal(storage::PERMISSIONS_DOCUMENT, &[document.id()])];
                    conditions.extend(tenant.iter().cloned());
                    conditions.push(equal(storage::PERMISSIONS_TYPE, &[kind.as_str()]));
                    conditions.push(equal(storage::PERMISSIONS_PERMISSION, &removed));
                    remove_conditions.push(Query::and(conditions));
                }
            }

            for kind in PERMISSION_TYPES {
                let wanted = document.permissions_by_type(kind.as_str())?;
                for permission in unique_additions(&wanted, of_kind(&current, kind)) {
                    let row = context.decorate_row(row(document.id(), kind.as_str(), &permission), document);
                    set(&mut add_builder, &row)?;
                    has_additions = true;
                }
            }
        }

        if !remove_conditions.is_empty() {
            let mut remove_builder =
                context.builder_from_table(&context.raw_table(&storage::permissions_table(collection)));
            filter(&mut remove_builder, vec![Query::or(remove_conditions)]);
            context.run(delete(&mut remove_builder)?, Event::PermissionsDelete).await?;
        }

        if has_additions {
            context.run(insert(&mut add_builder)?, Event::PermissionsCreate).await?;
        }
        Ok(())
    }

    /// Deletes every permission row of the deleted documents
    /// (`afterDocumentDelete()`).
    ///
    /// Fails ([`crate::Kind::Database`]) when the delete fails (`Failed to
    /// delete permissions`).
    async fn after_document_delete(
        &self,
        collection: &str,
        document_ids: &[String],
        context: &dyn WriteContext,
    ) -> Result<()> {
        if document_ids.is_empty() {
            return Ok(());
        }

        let mut builder = from_builder(collection, context);
        filter(&mut builder, vec![equal(storage::PERMISSIONS_DOCUMENT, document_ids)]);

        if !context.run(delete(&mut builder)?, Event::PermissionsDelete).await? {
            return Err(Error::database("Failed to delete permissions"));
        }
        Ok(())
    }
}
