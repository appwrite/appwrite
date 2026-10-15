//! `getDocument()` and the reads writes make.

use md5::{Digest, Md5};

use super::super::keys::json;
use super::super::{BoxFuture, Database, METADATA, collection_definition};
use super::cached::{CACHE_EMPTY_MARKER, Validity};
use super::joins::{Joined, assert_join_count, joined_by_alias, outer_join_id_selections, with_join_authorization};
use crate::capability::Capability;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::validator::authorization::Input;
use crate::validator::queries::document::Document as DocumentValidator;
use crate::value::{Array, ArrayKey, Str, Value};

/// PHP's `empty()` of a string.
pub(crate) fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

impl Database {
    /// Reads a document (`getDocument()`): an empty document when it does
    /// not exist or the caller cannot read it. `queries` select attributes,
    /// join, ...; `for_update` locks the row.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an empty collection id
    /// (`Collection not found`) or a missing collection, and
    /// ([`crate::Kind::Query`]) for invalid queries.
    pub fn get_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move { Ok(self.get_document_with_epoch(collection, id, queries, for_update).await?.0) })
    }

    /// [`Database::get_document`], with the document cache epoch of the
    /// collection's documents when the document is a collection definition
    /// (PHP attaches it to the definition object).
    pub(crate) fn get_document_with_epoch<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<(Document, Option<String>)>> {
        Box::pin(async move {
            if empty(collection) {
                return Err(Error::not_found("Collection not found"));
            }
            if empty(id) {
                return Ok((self.new_document(collection, Array::new())?, None));
            }
            let definition = if collection == METADATA && (!self.has_decorators() || self.are_events_silenced()) {
                collection_definition()
            } else {
                self.silent(self.get_collection(collection)).await?
            };
            let queries = self.rewrite_queries(Event::DocumentRead, &definition, queries).await?;
            self.read_document(&definition, id, queries, for_update).await
        })
    }

    /// [`Database::get_document`] under a definition the caller already
    /// resolved (`readDocument()`), with the document cache epoch of the
    /// collection's documents when the document read is a collection
    /// definition.
    pub(crate) fn read_document<'a>(
        &'a self,
        collection: &'a Collection,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<(Document, Option<String>)>> {
        Box::pin(self.read(collection, id, queries, for_update))
    }

    async fn read(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<(Document, Option<String>)> {
        let collection_id = collection.id();
        if collection_id == METADATA && id == METADATA {
            return Ok((collection_definition().into(), None));
        }
        let none =
            || -> Result<(Document, Option<String>)> { Ok((self.new_document(collection_id, Array::new())?, None)) };
        if empty(id) {
            return none();
        }
        let attributes = collection.attributes()?;
        let mut queries = Database::resolve_one_to_many_joins(collection, queries)?;

        let mut joined: Option<Joined> = None;
        if self.is_validating() && !queries.is_empty() {
            let resolved = self.resolve_joined_collections(&queries).await?;
            let validator = DocumentValidator::new(attributes, &self.profile(), self.max_query_values());
            let documents: Vec<Document> = resolved.iter().map(|(_, c)| c.document().clone()).collect();
            validator.set_joined_collections(&documents)?;
            if !validator.check(&queries) {
                return Err(Error::query(validator.description()));
            }
            joined = Some(resolved);
        }

        let relationships = Database::relationship_attributes(collection)?;
        let parsed = Query::group_by_type(&queries)?;
        let selects = parsed.selections;
        let joins = parsed.joins;
        if !joins.is_empty() && !self.inner.adapter.supports(Capability::Joins) {
            return Err(Error::query("Join queries are not supported by this adapter"));
        }
        assert_join_count(joins.len())?;
        let joined = match joined {
            Some(j) => j,
            None => self.resolve_joined_collections(&joins).await?,
        };
        let mut security = Vec::new();
        let mut by_alias: Joined = Vec::new();
        if !joins.is_empty() {
            security = self.authorize_joins(&joins, PermissionType::Read, &joined)?;
            by_alias = joined_by_alias(&joins, &joined);
            queries = self.convert_queries(collection, queries, &by_alias)?;
        }
        let outer_ids = outer_join_id_selections(&selects, &joins, &by_alias);
        if !outer_ids.is_empty() {
            queries.push(Query::select(outer_ids.clone()));
        }
        let selections = self.validate_selections(collection, &selects)?;
        let hook = self.relationship_hook();
        let nested = match &hook {
            Some(h) => h.process_queries(&relationships, &mut queries)?,
            None => Vec::new(),
        };
        let document_security = collection.get(crate::collection::DOCUMENT_SECURITY).is_some_and(Value::truthy);

        let (collection_key, document_key, hash_key) = self.cache_keys(collection_id, Some(id), &selections).await?;
        let definition = collection_id == METADATA;
        // The cache lower-cases keys; the hash key keeps the id's case. A global
        // collection's definition has one key for every tenant, and each tenant
        // its own collection epoch.
        let field =
            md5_hex(if definition { format!("{hash_key}:{}", json(&self.inner.adapter.tenant())) } else { hash_key });

        let in_transaction = self.inner.adapter.in_transaction();
        let blocked = self.context().is_some_and(|c| {
            super::super::transactions::ContextState::get(&c.state().document_cache_mutations, &collection_key)
                .is_some()
        });
        let cacheable = !for_update
            && joins.is_empty()
            && (!in_transaction || self.is_cached_in_transaction(&document_key))
            && !blocked;
        let epoch: Option<String> =
            if cacheable && !definition { collection.cache_epoch().map(str::to_owned) } else { None };
        let mut fill_epoch = if in_transaction { None } else { epoch.clone() };
        let mut fill_definition = cacheable && definition && !in_transaction;

        let mut cached: Option<Array> = None;
        let mut collection_epoch: Option<String> = None;
        let mut cache_failed = false;
        let loaded = if cacheable && definition {
            self.load_cached_definition(&document_key, &field).await.map(|entry| {
                entry.map(|e| {
                    collection_epoch = e.collection_epoch;
                    e.document
                })
            })
        } else if let Some(epoch) = &epoch {
            self.load_cached_document(&document_key, &field, epoch).await
        } else {
            Ok(None)
        };
        match loaded {
            Ok(c) => cached = c,
            Err(e) if !e.is_engine_error() => {
                cache_failed = true;
                super::super::warning(format!("Warning: Failed to get document from cache: {}", e.message()));
            }
            Err(e) => return Err(e),
        }

        let read_permissions = |document: Option<&Document>| -> Result<Input> {
            let mut permissions = collection.permissions_by_type(PermissionType::Read)?;
            if let Some(d) = document
                && document_security
            {
                permissions.extend(d.permissions_by_type(PermissionType::Read)?);
            }
            Ok(Input::new(PermissionType::Read, permissions))
        };
        let authorization = self.authorization();

        if let Some(cached) = cached {
            if cached.get_str(CACHE_EMPTY_MARKER).is_some() {
                return none();
            }
            if !cached.is_empty() {
                let document = if definition {
                    self.create_definition_instance(&document_key, cached)?
                } else {
                    self.new_document(collection_id, cached)?
                };
                let document = self.casting(collection, document)?;
                if !definition && !authorization.is_valid(&read_permissions(Some(&document))?) {
                    return none();
                }
                let document = self.decorate_document(Event::DocumentRead, collection, document).await?;
                self.trigger(Event::DocumentRead, || Domain::DocumentRead {
                    collection: collection_id.to_owned(),
                    document: document.clone(),
                })
                .await?;
                if self.is_ttl_expired(collection, &document)? {
                    return none();
                }
                return Ok((document, collection_epoch));
            }
        }

        let mut generation = "0".to_owned();
        if fill_epoch.is_some() || fill_definition {
            match self.cache().generation(&document_key).await {
                Ok(g) => generation = g,
                Err(e) => {
                    super::super::warning(format!("Warning: Failed to get cache generation: {e}"));
                }
            }
        }

        let transaction_definition = cacheable && definition && in_transaction && queries.is_empty();
        let transaction_key = document_key.to_lowercase();
        if transaction_definition {
            let read_in_transaction = self.context().and_then(|c| {
                c.state().transaction_definitions.get(&transaction_key).and_then(|f| f.get(&field)).cloned()
            });
            if let Some(read) = read_in_transaction {
                let described = self.cache_base_keys(id, None).await?.0;
                let state = self.load_document_cache_state(&described).await;
                let document = self.decorate_document(Event::DocumentRead, collection, read).await?;
                self.trigger(Event::DocumentRead, || Domain::DocumentRead {
                    collection: collection_id.to_owned(),
                    document: document.clone(),
                })
                .await?;
                return Ok((document, state.value));
            }
        }

        let collection_granted = authorization.is_valid(&read_permissions(None)?);
        let skip_auth = joins.is_empty() && !definition && collection_granted;
        let adapter_collection =
            with_join_authorization(collection.clone(), &security, collection_granted || definition)?;
        let adapter_collection = Database::with_join_attributes(adapter_collection, &joins, &joined)?;
        let adapter_collection = self.with_join_indexed(adapter_collection, &joins, &joined)?;
        let adapter = self.inner.adapter.clone();
        let read = || adapter.get_document(&adapter_collection, id, queries.clone(), for_update);
        let mut document = if skip_auth { authorization.skip(read()).await? } else { read().await? };
        if self.is_read_from_replica() {
            fill_epoch = None;
            fill_definition = false;
        }

        if document.is_empty() {
            // The marker is shared by every reader, so a miss seen with
            // authorization on only proves absence once an unfiltered read agrees.
            let mut missing = true;
            if fill_epoch.is_some() && relationships.is_empty() && !skip_auth && !definition {
                missing = authorization.skip(read()).await?.is_empty();
                if self.is_read_from_replica() {
                    fill_epoch = None;
                }
            }
            let marker: Array =
                [(ArrayKey::Str(Str::from(CACHE_EMPTY_MARKER)), Value::Bool(true))].into_iter().collect();
            let saved = match &fill_epoch {
                Some(epoch) if relationships.is_empty() && missing => {
                    self.save_cached_document(&document_key, &field, epoch, marker, &generation).await
                }
                _ if fill_definition => {
                    self.save_cached_definition(&document_key, &field, marker, None, &generation, None).await
                }
                _ => Ok(()),
            };
            match saved {
                Ok(()) => {}
                Err(e) if !e.is_engine_error() => {
                    super::super::warning(format!("Failed to save empty document to cache: {}", e.message()));
                }
                Err(e) => return Err(e),
            }
            return none();
        }

        if self.is_ttl_expired(collection, &document)? {
            return none();
        }

        let mut described = String::new();
        let mut state = crate::cache::Epoch::default();
        if cacheable && definition {
            described = self.cache_base_keys(id, None).await?.0;
            state = self.load_document_cache_state(&described).await;
        }

        document = self.cast_after_document(collection, document)?;
        super::super::filters::place_sequence_before_id(&mut document);
        if self.document_type(collection_id).is_some() {
            document = self.new_document(collection_id, document.array_copy())?;
        }
        document.put(document::COLLECTION, Value::Str(Str::from(collection_id)));

        if !definition && !authorization.is_valid(&read_permissions(Some(&document))?) {
            return none();
        }

        document = self.casting(collection, document)?;
        document = self.decode(collection, document, &selections).await?;
        if !joins.is_empty() {
            document = self.decode_joins(document, &by_alias).await?;
            for id in &outer_ids {
                document.remove(id);
            }
        }

        if let Some(hook) = &hook
            && !hook.is_in_batch_population()
            && hook.is_enabled()
            && !relationships.is_empty()
            && (selects.is_empty() || !nested.is_empty())
        {
            let populated =
                self.silent(hook.populate_documents(vec![document], collection, hook.fetch_depth(), nested)).await?;
            document = populated.into_iter().next().unwrap_or_default();
        }

        let saved = match &fill_epoch {
            Some(epoch) if relationships.is_empty() => {
                self.save_cached_document(&document_key, &field, epoch, document.array_copy(), &generation).await
            }
            _ if fill_definition => {
                let validity = Validity {
                    collection_epoch: state.value.clone(),
                    blocked_at: state.blocked_at,
                    checked_at: Some(php_std::datetime::time()),
                };
                self.save_cached_definition(
                    &document_key,
                    &field,
                    document.array_copy(),
                    Some(validity),
                    &generation,
                    Some((described.as_str(), state.value.as_deref())),
                )
                .await
            }
            _ => Ok(()),
        };
        match saved {
            Ok(()) => {}
            Err(e) if !e.is_engine_error() => {
                super::super::warning(format!("Failed to save document to cache: {}", e.message()));
            }
            Err(e) => return Err(e),
        }

        if transaction_definition && let Some(context) = self.context() {
            context
                .state()
                .transaction_definitions
                .entry(transaction_key.clone())
                .or_default()
                .insert(field.clone(), document.clone());
            if !cache_failed {
                self.queue_definition_refill(&transaction_key, &field, id);
            }
        }

        let document = self.decorate_document(Event::DocumentRead, collection, document).await?;
        self.trigger(Event::DocumentRead, || Domain::DocumentRead {
            collection: collection_id.to_owned(),
            document: document.clone(),
        })
        .await?;
        Ok((document, state.value))
    }

    /// The documents read again by their `$sequence`, with `selections`, as
    /// stored, keeping any not found (`refetchDocuments()`).
    ///
    /// Fails ([`crate::Kind::Database`]) for a document without a sequence.
    pub(crate) async fn refetch_documents(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
        selections: Vec<Query>,
    ) -> Result<Vec<Document>> {
        let _ = (collection, documents, selections);
        todo!("reads area: refetchDocuments")
    }

    /// Strips the attributes `select_queries` do not select from each
    /// document, keeping the internal ones (`applySelectFiltersToDocuments()`,
    /// internal).
    pub fn apply_select_filters_to_documents(&self, documents: &mut [Document], select_queries: &[Query]) {
        let _ = (documents, select_queries);
        todo!("reads area: applySelectFiltersToDocuments")
    }
}

/// `md5()` of text, in hex.
fn md5_hex(text: String) -> String {
    hex::encode(Md5::digest(text.as_bytes()))
}

#[cfg(test)]
mod tests {
    use std::sync::Arc;

    use super::super::super::testing::{Call, Reply, StubAdapter, block_on, database, uncached};
    use super::super::super::{METADATA, collection_definition};
    use crate::attribute::Attribute;
    use crate::collection::Collection;
    use crate::document::Document;
    use crate::permission::Permission;
    use crate::role::Role;
    use crate::value::{Array, Value, string};

    fn movies(read: bool, document_security: bool) -> Collection {
        let permissions = if read { Some(vec![Permission::read(&Role::any()).to_string()]) } else { Some(Vec::new()) };
        Collection::create(
            "movies",
            "movies",
            vec![
                Attribute::string("name", 128),
                Attribute::boolean("active"),
                Attribute::string("tags", 64).array(true),
            ],
            Vec::new(),
            permissions,
            document_security,
            Array::new(),
        )
        .unwrap()
    }

    fn stub(collection: Collection, stored: Document) -> Arc<StubAdapter> {
        let adapter = StubAdapter::new();
        adapter.on(move |call| match call {
            Call::GetDocument { collection: c, id, .. } if c.id() == METADATA => {
                if id == METADATA {
                    Some(Reply::Document(collection_definition().into()))
                } else if id == collection.id() {
                    Some(Reply::Document(collection.document().clone()))
                } else {
                    Some(Reply::Document(Document::empty()))
                }
            }
            Call::GetDocument { id, .. } if id == stored.id() => Some(Reply::Document(stored.clone())),
            _ => None,
        });
        adapter
    }

    fn stored() -> Document {
        Document::from_pairs([
            ("$id", string("alien")),
            ("$sequence", Value::Int(7)),
            ("$permissions", Value::Array(Array::new())),
            ("name", string("Alien")),
            ("active", Value::Int(1)),
            ("tags", string(r#"["horror","space"]"#)),
        ])
        .unwrap()
    }

    #[test]
    fn reads_and_decodes_a_document() {
        block_on(async {
            let db = uncached(stub(movies(true, false), stored()));
            let document = db.get_document("movies", "alien", Vec::new(), false).await.unwrap();
            assert_eq!(document.id(), "alien");
            assert_eq!(document.collection(), "movies");
            assert_eq!(document.get("active"), Some(&Value::Bool(true)));
            assert_eq!(document.get("tags"), Some(&crate::value::list([string("horror"), string("space")])));
            let keys: Vec<String> = document.keys().map(|k| super::super::super::filters::key_text(&k)).collect();
            assert_eq!(&keys[..2], ["$sequence", "$id"]);
        });
    }

    #[test]
    fn hides_a_document_the_caller_cannot_read() {
        block_on(async {
            let db = uncached(stub(movies(false, false), stored()));
            assert!(db.get_document("movies", "alien", Vec::new(), false).await.unwrap().is_empty());
            let missing = db.get_document("movies", "absent", Vec::new(), false).await.unwrap();
            assert!(missing.is_empty());
            let err = db.get_document("", "alien", Vec::new(), false).await.unwrap_err();
            assert_eq!(err.message(), "Collection not found");
            let err = db.get_document("series", "alien", Vec::new(), false).await.unwrap_err();
            assert_eq!(err.message(), "Collection not found");
        });
    }

    #[test]
    fn serves_a_second_read_from_the_cache() {
        block_on(async {
            let adapter = stub(movies(true, false), stored());
            let db = database(adapter.clone());
            let first = db.get_document("movies", "alien", Vec::new(), false).await.unwrap();
            let reads = adapter.calls_of("getDocument").len();
            let second = db.get_document("movies", "alien", Vec::new(), false).await.unwrap();
            assert_eq!(first, second);
            assert_eq!(adapter.calls_of("getDocument").len(), reads, "both the definition and the document are cached");
        });
    }
}
