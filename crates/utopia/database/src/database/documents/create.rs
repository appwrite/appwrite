//! `createDocument()`, `createDocuments()`, `prepareCreate()`,
//! `createPrepared()`, the `authorize*()` checks, and what the writes share
//! (`nestedPreparation()`, `prepareNested()`, `prepareNestedCopy()`,
//! `holdsRelatedDocument()`, `assertCreateTenancy()`, `prepareDocument()`).

use php_std::datetime::{DateTime, TzInfo};

use super::super::{BoxFuture, Callback, Database, METADATA, Resolve};
use super::numeric::document_security;
use super::read::empty;
use crate::cache::Target;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::relationships::Preparation;
use crate::permission::PermissionType;
use crate::validator::authorization::Input;
use crate::validator::permissions::Permissions;
use crate::validator::structure::Structure;
use crate::value::{Array, Ext, Str, Value, list};

impl Database {
    /// Creates a document (`createDocument()`): id, timestamps, tenant and
    /// permissions set, encoded and validated, its related documents
    /// related; returns it as stored and decoded.
    ///
    /// A retried transaction starts again from the document given (PHP's
    /// `$copies` and `restore()` of the relationship hook).
    ///
    /// Fails ([`crate::Kind::Authorization`]) without create permission,
    /// ([`crate::Kind::Structure`]) for an invalid document,
    /// ([`crate::Kind::Duplicate`]) for an existing id.
    pub fn create_document<'a>(&'a self, collection: &'a str, document: Document) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            self.assert_create_tenancy(collection)?;

            let collection = self.silent(self.get_collection(collection)).await?;

            let document = self.prepare_document(&collection, document).await?;
            let preparation = self.nested_preparation(None, true);

            let target = [Target::Document(document.clone())];
            let mut document = self
                .with_mutation(Event::DocumentCreate, &target, || {
                    let (collection, document, preparation) = (&collection, document.clone(), &preparation);
                    async move {
                        let mut document = document;
                        if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                            document = self.prepare_nested_copy(collection, document, preparation.as_ref()).await?;
                            document = self.silent(hook.after_document_create(collection, document)).await?;
                        }

                        let document = self.inner.adapter.create_document(collection, document).await?;
                        self.with_document_tenant(
                            &document,
                            self.purge_cached_document_internal(collection.id(), Some(document.id())),
                        )
                        .await?;

                        Ok(document)
                    }
                })
                .await?;

            if let Some(hook) = self.relationship_hook()
                && !hook.is_in_batch_population()
                && hook.is_enabled()
            {
                let depth = hook.write_stack_count();
                let populated = self
                    .silent(self.skip_query_hooks(hook.populate_documents(
                        vec![document],
                        &collection,
                        depth,
                        Vec::new(),
                    )))
                    .await?;
                document = populated.into_iter().next().unwrap_or_default();
            }

            let document = self.cast_after_document(&collection, document)?;
            let document = self.casting(&collection, document)?;
            let mut document = self.decode(&collection, document, &[]).await?;

            if self.document_type(collection.id()).is_some() {
                document = self.new_document(collection.id(), document.array_copy())?;
            }

            let document = self.decorate_document(Event::DocumentCreate, &collection, document).await?;

            let listeners = self.listens(Event::DocumentCreate);
            if !listeners.is_empty() {
                let event =
                    Domain::DocumentCreated { collection: collection.id().to_owned(), document: document.clone() };
                self.dispatch(&event, &listeners).await?;
            }

            Ok(document)
        })
    }

    /// Creates documents in batches of at most `batch_size`
    /// (`createDocuments()`), handing `on_next` each created document (and
    /// `None`) once its batch is written; an error it returns aborts the
    /// call. Returns how many were created. Under
    /// [`Database::ignore_duplicates`] a document whose id exists is skipped.
    ///
    /// Fails ([`crate::Kind::Limit`]) for a batch above
    /// [`super::super::BATCH_SIZE`], and as [`Database::create_document`] does.
    pub async fn create_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let mut on_next = on_next;
        let batch_size = super::joins::batch_size(batch_size)?;

        let adapter = self.inner.adapter.clone();
        if adapter.shared_tables() && !adapter.tenant_per_document() && php_empty(&adapter.tenant()) {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        if !adapter.shared_tables() && adapter.tenant_per_document() {
            return Err(Error::database("Shared tables must be enabled if tenant per document is enabled."));
        }

        if documents.is_empty() {
            return Ok(0);
        }

        let collection = self.silent(self.get_collection(collection)).await?;
        if collection.id() != METADATA {
            self.authorize_permissions(
                PermissionType::Create,
                collection.permissions_by_type(PermissionType::Create)?,
            )?;
        }

        let time = crate::datetime::now();
        let mut modified = 0;
        let has_relationships = !Database::relationship_attributes(&collection)?.is_empty();

        let mut documents = documents;
        let preparation = if has_relationships { self.nested_preparation(None, false) } else { None };
        if let Some(preparation) = &preparation {
            let mut prepared = Vec::with_capacity(documents.len());
            for document in documents {
                prepared.push(self.prepare_nested(&collection, document, preparation).await?);
            }
            documents = prepared;
        }

        let validator = self.is_validating().then(|| Structure::new(collection.document().clone(), self.profile()));

        let preserve_dates = self.is_preserving_dates();
        let mut ready = Vec::with_capacity(documents.len());
        for mut document in documents {
            let created_at = document.created_at().map(str::to_owned);
            let updated_at = document.updated_at().map(str::to_owned);
            let id = if empty(document.id()) { crate::id::unique()? } else { document.id().to_owned() };

            document
                .put(document::ID, Value::Str(Str::from(id)))
                .put(document::COLLECTION, Value::Str(Str::from(collection.id())))
                .put(document::CREATED_AT, date(created_at, preserve_dates, &time))
                .put(document::UPDATED_AT, date(updated_at, preserve_dates, &time));

            if document.permissions()?.is_empty() {
                document.put(document::PERMISSIONS, Value::Array(Array::new()));
            }

            if adapter.shared_tables() {
                if adapter.tenant_per_document() {
                    if document.tenant().is_none() {
                        return Err(Error::database(
                            "Missing tenant. Tenant must be set when tenant per document is enabled.",
                        ));
                    }
                } else {
                    document.put(document::TENANT, adapter.tenant());
                }
            }

            let mut document = self.encode(&collection, document, true).await?;

            if let Some(validator) = &validator
                && !validator.validate(&mut document)?
            {
                return Err(Error::structure(validator.description()));
            }

            if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
                document = self.silent(hook.after_document_create(&collection, document)).await?;
            }

            ready.push(self.cast_before(&collection, document)?);
        }

        let chunks: Vec<Vec<Document>> = ready.chunks(batch_size as usize).map(<[Document]>::to_vec).collect();
        for chunk in chunks {
            let target: Vec<Target> = chunk.iter().cloned().map(Target::Document).collect();
            let insert = self.with_mutation(Event::DocumentsCreate, &target, || {
                let (collection, chunk) = (&collection, chunk.clone());
                async move {
                    let batch = self.inner.adapter.create_documents(collection, chunk.clone()).await?;
                    for document in &chunk {
                        self.with_document_tenant(
                            document,
                            self.advance_collection_cache_epoch(collection.id(), document.id()),
                        )
                        .await?;
                    }
                    Ok(batch)
                }
            });
            let mut batch = if self.is_ignoring_duplicates() {
                adapter.state().ignoring_duplicates().with(true, insert).await?
            } else {
                insert.await?
            };

            if on_next.is_some() || has_relationships {
                batch = adapter.get_sequences(&collection, batch).await?;
            }

            if let Some(hook) = self.relationship_hook()
                && !hook.is_in_batch_population()
                && hook.is_enabled()
            {
                let depth = hook.fetch_depth();
                batch = self
                    .silent(self.skip_query_hooks(hook.populate_documents(batch, &collection, depth, Vec::new())))
                    .await?;
            }

            let mut decoded = Vec::with_capacity(batch.len());
            for document in self.cast_after(&collection, batch)? {
                let document = self.casting(&collection, document)?;
                decoded.push(self.decode(&collection, document, &[]).await?);
            }

            let batch = self.decorate_documents(Event::DocumentsCreate, &collection, decoded).await?;

            for document in batch {
                if let Some(callback) = on_next.as_deref_mut() {
                    callback.call((document, None)).await?;
                }
                modified += 1;
            }
        }

        let listeners = self.listens(Event::DocumentsCreate);
        if !listeners.is_empty() {
            let event = Domain::DocumentBatchCreated { collection: collection.id().to_owned(), count: modified };
            self.dispatch(&event, &listeners).await?;
        }

        Ok(modified)
    }

    /// Applies to a document everything [`Database::create_document`] does
    /// before writing it (`prepareCreate()`, internal): the tenancy and
    /// permission checks, the generated attributes, encoding and
    /// validation.
    pub async fn prepare_create(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.assert_create_tenancy(collection.id())?;

        self.prepare_document(collection, document).await
    }

    /// Writes documents [`Database::prepare_create`] prepared, one at a time
    /// in order, each the way [`Database::create_document`] writes it,
    /// under one invalidation scope (`createPrepared()`, internal).
    pub async fn create_prepared(&self, documents: Vec<(Collection, Document)>) -> Result<()> {
        self.with_invalidation_scope(async {
            let mut collections: Vec<(String, Vec<Target>)> = Vec::new();
            for (collection, document) in &documents {
                let target = Target::Document(document.clone());
                match collections.iter_mut().find(|(id, _)| id == collection.id()) {
                    Some((_, created)) => created.push(target),
                    None => collections.push((collection.id().to_owned(), vec![target])),
                }
            }

            for (_, created) in &collections {
                self.block_mutation(Event::DocumentCreate, created).await?;
            }

            for (collection, document) in documents {
                let document = self.inner.adapter.create_document(&collection, document).await?;
                self.with_document_tenant(
                    &document,
                    self.purge_cached_document_internal(collection.id(), Some(document.id())),
                )
                .await?;
            }
            Ok(())
        })
        .await
    }

    /// Checks, without writing, that the caller may create `document`, and
    /// relate every related document it carries (`authorizeCreate()`): what
    /// [`Database::create_document`] checks, related documents first
    /// prepared by the nested hooks. With `resolve`, each related document
    /// is judged as staged writes leave it.
    ///
    /// Fails ([`crate::Kind::Authorization`]) when a create would refuse
    /// the caller, ([`crate::Kind::NotFound`]) for a missing collection,
    /// ([`crate::Kind::Relationship`]) for an invalid relationship value.
    pub async fn authorize_create(
        &self,
        collection: &str,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        let definition = self.silent(self.get_collection(collection)).await?;

        if definition.id() != METADATA {
            self.authorize_permissions(
                PermissionType::Create,
                definition.permissions_by_type(PermissionType::Create)?,
            )?;
        }

        if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
            let preparation = self.nested_preparation(resolve.clone(), false);
            let prepared = self.prepare_nested_copy(&definition, document.clone(), preparation.as_ref()).await?;
            hook.authorize(&definition, &prepared, None, resolve).await?;
        }
        Ok(())
    }

    /// Checks, without writing, that the caller may update `old` with
    /// `document` (`authorizeUpdate()`): update on the document (on its
    /// collection, or on `old` under document security), even for a change
    /// that changes nothing, and what [`Database::authorize_create`] checks
    /// for every related document `document` carries.
    ///
    /// Fails as [`Database::authorize_create`] does.
    pub async fn authorize_update(
        &self,
        collection: &str,
        old: &Document,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        let definition = self.silent(self.get_collection(collection)).await?;

        if definition.id() != METADATA {
            let mut permissions = definition.permissions_by_type(PermissionType::Update)?;
            if document_security(&definition) {
                permissions.extend(old.permissions_by_type(PermissionType::Update)?);
            }
            self.authorize_permissions(PermissionType::Update, permissions)?;
        }

        if let Some(hook) = self.relationship_hook().filter(|h| h.is_enabled()) {
            let preparation = self.nested_preparation(resolve.clone(), false);
            let prepared = self.prepare_nested_copy(&definition, document.clone(), preparation.as_ref()).await?;
            hook.authorize(&definition, &prepared, Some(old), resolve).await?;
        }
        Ok(())
    }

    /// Checks only the grants of the related documents nested in `document`
    /// (`authorizeGrants()`): that none adds a permission for a role the
    /// caller does not hold. Does nothing unless grants are checked,
    /// authorization is on, and the relationship hook is attached and
    /// enabled.
    ///
    /// Fails ([`crate::Kind::Authorization`]) for a grant the caller does not
    /// hold, ([`crate::Kind::NotFound`]) for a missing collection.
    pub async fn authorize_grants(
        &self,
        collection: &str,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        if !self.checks_grants() || !self.relationship_hook().is_some_and(|h| h.is_enabled()) {
            return Ok(());
        }

        let definition = self.silent(self.get_collection(collection)).await?;

        let preparation = self.nested_preparation(resolve, false);
        self.prepare_nested_copy(&definition, document.clone(), preparation.as_ref()).await?;
        Ok(())
    }

    /// Fails ([`crate::Kind::Authorization`]) with the authorization's
    /// description unless the caller holds `action` on `permissions`.
    pub(crate) fn authorize_permissions(&self, action: PermissionType, permissions: Vec<String>) -> Result<()> {
        let authorization = self.authorization();
        if !authorization.is_valid(&Input::new(action, permissions)) {
            return Err(Error::authorization(authorization.description()));
        }
        Ok(())
    }

    /// How a write prepares its related documents (`nestedPreparation()`);
    /// `None` when it leaves them as given.
    pub(crate) fn nested_preparation(&self, resolve: Option<Resolve>, lock: bool) -> Option<Preparation> {
        let check = self.checks_grants();
        let hooks = self.nested_hooks();
        if (hooks.is_empty() && !check) || !self.relationship_hook().is_some_and(|h| h.is_enabled()) {
            return None;
        }
        Some(Preparation { hooks, check, lock, resolve })
    }

    /// The related documents of `document` prepared (`prepareNested()`).
    ///
    /// Fails ([`crate::Kind::Authorization`]) for a grant the caller does
    /// not hold, ([`crate::Kind::Relationship`]) and
    /// ([`crate::Kind::Structure`]) for an invalid related document.
    pub(crate) async fn prepare_nested(
        &self,
        collection: &Collection,
        document: Document,
        preparation: &Preparation,
    ) -> Result<Document> {
        let Some(hook) = self.relationship_hook() else {
            return Ok(document);
        };
        self.silent(hook.prepare_nested(collection, document, preparation)).await
    }

    /// [`Database::prepare_nested`] of a copy (`prepareNestedCopy()`), so a
    /// write that runs again starts from the document given; the document
    /// as given without a preparation.
    pub(crate) async fn prepare_nested_copy(
        &self,
        collection: &Collection,
        document: Document,
        preparation: Option<&Preparation>,
    ) -> Result<Document> {
        match preparation {
            None => Ok(document),
            Some(preparation) => self.prepare_nested(collection, document, preparation).await,
        }
    }

    /// Fails ([`crate::Kind::Database`]) for a create without the tenant
    /// shared tables need (`assertCreateTenancy()`).
    pub(crate) fn assert_create_tenancy(&self, collection: &str) -> Result<()> {
        let adapter = &self.inner.adapter;
        if collection != METADATA
            && adapter.shared_tables()
            && !adapter.tenant_per_document()
            && php_empty(&adapter.tenant())
        {
            return Err(Error::database("Missing tenant. Tenant must be set when table sharing is enabled."));
        }
        if !adapter.shared_tables() && adapter.tenant_per_document() {
            return Err(Error::database("Shared tables must be enabled if tenant per document is enabled."));
        }
        Ok(())
    }

    /// A new document ready for the adapter (`prepareDocument()`): create
    /// permission checked, id, collection, timestamps, permissions and
    /// tenant set, encoded, validated, cast.
    ///
    /// Fails ([`crate::Kind::Authorization`]) without create permission,
    /// ([`crate::Kind::Database`]) for a missing tenant or invalid
    /// permissions, ([`crate::Kind::Structure`]) for an invalid document.
    async fn prepare_document(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        if collection.id() != METADATA {
            self.authorize_permissions(
                PermissionType::Create,
                collection.permissions_by_type(PermissionType::Create)?,
            )?;
        }

        let time = crate::datetime::now();
        let preserve_dates = self.is_preserving_dates();

        let created_at = document.created_at().map(str::to_owned);
        let updated_at = document.updated_at().map(str::to_owned);

        let id = if empty(document.id()) { crate::id::unique()? } else { document.id().to_owned() };
        document
            .put(document::ID, Value::Str(Str::from(id)))
            .put(document::COLLECTION, Value::Str(Str::from(collection.id())))
            .put(document::CREATED_AT, date(created_at, preserve_dates, &time))
            .put(document::UPDATED_AT, date(updated_at, preserve_dates, &time));

        if document.permissions()?.is_empty() {
            document.put(document::PERMISSIONS, Value::Array(Array::new()));
        }

        let adapter = &self.inner.adapter;
        if adapter.shared_tables() {
            if adapter.tenant_per_document() {
                if collection.id() != METADATA && document.tenant().is_none() {
                    return Err(Error::database(
                        "Missing tenant. Tenant must be set when tenant per document is enabled.",
                    ));
                }
            } else {
                document.put(document::TENANT, adapter.tenant());
            }
        }

        let mut document = self.encode(collection, document, true).await?;

        if self.is_validating() {
            let validator = Permissions::default();
            let permissions = list(document.permissions()?.into_iter().map(|p| Value::Str(Str::from(p))));
            if !validator.is_valid(&permissions) {
                return Err(Error::database(validator.description()));
            }
        }

        if self.is_validating() {
            let structure = Structure::new(collection.document().clone(), self.profile());
            if !structure.validate(&mut document)? {
                return Err(Error::structure(structure.description()));
            }
        }

        self.cast_before(collection, document)
    }

    /// Fails ([`crate::Kind::Conflict`]) when a document last updated at
    /// `updated_at` changed after the request timestamp. A date that does
    /// not parse fails with the parser's message: as a
    /// [`crate::Kind::Database`] error wrapping it with `wrap` (the bulk
    /// writes, upserts and deletes), as it is otherwise
    /// ([`crate::Kind::DateMalformedString`], `updateDocument()`).
    pub(crate) fn assert_not_updated_after_request(&self, updated_at: Option<&str>, wrap: bool) -> Result<()> {
        let text = updated_at.unwrap_or("now");
        let old = DateTime::new(text.as_bytes(), None, &TzInfo::utc()).map_err(|e| {
            let malformed = Error::new(Kind::DateMalformedString, e.message());
            if wrap { Error::database(e.message()).with_previous(malformed) } else { malformed }
        })?;
        if let Some(timestamp) = self.request_timestamp()
            && old > timestamp
        {
            return Err(Error::conflict("Document was updated after the request timestamp"));
        }
        Ok(())
    }
}

/// `$createdAt`/`$updatedAt` of a new document: the one given when dates
/// are preserved, else `time`.
fn date(given: Option<String>, preserve: bool, time: &str) -> Value {
    match given {
        Some(given) if preserve => Value::Str(Str::from(given)),
        _ => Value::Str(Str::from(time)),
    }
}

/// PHP's `empty()` of a value.
pub(crate) fn php_empty(value: &Value) -> bool {
    match value {
        Value::Str(s) => s.is_empty() || s.as_bytes() == b"0",
        other => !other.truthy(),
    }
}

/// Whether `document` holds a related document, given as a document or as
/// an array that is not a list, in a relationship attribute
/// (`holdsRelatedDocument()`).
pub(crate) fn holds_related_document(collection: &Collection, document: &Document) -> Result<bool> {
    for attribute in Database::relationship_attributes(collection)? {
        let value = document.raw(&attribute.key).cloned().unwrap_or(Value::Null);
        let values: Vec<Value> = match value {
            Value::Array(a) if a.is_list() => a.values().cloned().collect(),
            other => vec![other],
        };
        for related in values {
            match related {
                Value::Ext(Ext::Document(_)) => return Ok(true),
                Value::Array(a) if !a.is_list() => return Ok(true),
                _ => {}
            }
        }
    }
    Ok(false)
}

#[cfg(test)]
#[path = "writes_tests.rs"]
mod tests;
