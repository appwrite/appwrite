//! Document and query caching: keys, purges and the read-through
//! `with_cache`.
//!
//! Area: schema.

use std::future::Future;

use super::Database;
use super::filters::json_encode;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::Query;
use crate::validator::authorization::Input;
use crate::value::{Array, ArrayKey, Ext, Str, Value, from_plain, to_plain, to_text};

/// A cache failure as a database error (PHP lets the cache's exception
/// through).
fn cache_error(e: utopia_cache::Error) -> Error {
    Error::new(Kind::Exception, e.to_string())
}

/// `md5($text)`, in hex.
fn md5(text: &[u8]) -> String {
    use md5::{Digest, Md5};
    hex::encode(Md5::digest(text))
}

/// `json_encode($value) ?: ''`.
fn json(value: &Value) -> String {
    match json_encode(value) {
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        _ => String::new(),
    }
}

/// An array of `(key, value)` pairs.
fn assoc(pairs: Vec<(&str, Value)>) -> Array {
    pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect()
}

/// `if ($documentId)`: neither `null`, `""` nor `"0"`.
fn truthy_id(id: Option<&str>) -> Option<&str> {
    id.filter(|id| !id.is_empty() && *id != "0")
}

impl Database {
    /// The collection key and the document key (`""` without a document)
    /// of the document cache:
    /// `{cacheName}-cache-{hostname}:{namespace}:{tenant}:collection:{collectionId}[:{documentId}]`.
    ///
    /// PHP: `Database::getCacheBaseKeys()`.
    pub fn cache_base_keys(&self, collection_id: &str, document_id: Option<&str>) -> (String, String) {
        let adapter = &self.inner.adapter;
        let hostname = if adapter.support_for_hostname() { adapter.hostname() } else { String::new() };
        let mut tenant = to_text(&adapter.tenant());
        if collection_id == Self::METADATA
            && adapter.shared_tables()
            && self.state().global_collections.iter().any(|c| c == document_id.unwrap_or(""))
        {
            tenant = String::new();
        }
        let collection_key =
            format!("{}-cache-{hostname}:{}:{tenant}:collection:{collection_id}", self.cache_name(), self.namespace());
        let document_key = match truthy_id(document_id) {
            Some(id) => format!("{collection_key}:{id}"),
            None => String::new(),
        };
        (collection_key, document_key)
    }

    /// The collection key, the document key and the document hash key (the
    /// document key with the md5 of the relationship flag, the filter
    /// signatures and the sorted selections).
    ///
    /// PHP: `Database::getCacheKeys()`.
    pub fn cache_keys(
        &self,
        collection_id: &str,
        document_id: Option<&str>,
        selects: &[String],
    ) -> (String, String, String) {
        let (collection_key, document_key) = self.cache_base_keys(collection_id, document_id);
        let mut hash_key = String::new();
        if truthy_id(document_id).is_some() {
            let mut sorted: Vec<Value> = selects.iter().map(|s| Value::Str(Str::from(s.as_str()))).collect();
            sorted.sort_by(|a, b| a.cmp_php(b));
            let selections =
                if sorted.is_empty() { String::new() } else { json(&Value::Array(sorted.into_iter().collect())) };
            let payload = format!(
                "{}:{}:{}",
                if self.state().resolve_relationships { "1" } else { "0" },
                self.filter_signature_key(),
                selections
            );
            hash_key = format!("{document_key}:{}", md5(payload.as_bytes()));
        }
        (collection_key, document_key, hash_key)
    }

    /// The cache key of a collection's cached queries (in `namespace`, the
    /// database's by default).
    ///
    /// PHP: `Database::getQueryCacheKey()`.
    pub fn query_cache_key(&self, collection_id: &str, namespace: Option<&str>) -> String {
        let adapter = &self.inner.adapter;
        let hostname = if adapter.support_for_hostname() { adapter.hostname() } else { String::new() };
        let namespace = match namespace {
            Some(n) => n.to_owned(),
            None => self.namespace(),
        };
        format!(
            "{}-cache-{hostname}:{namespace}:{}:collection:{collection_id}:query",
            self.cache_name(),
            to_text(&adapter.tenant())
        )
    }

    /// The cache field of one query result: a hash of the collection's
    /// schema and of the queries, authorization, database, relationship
    /// flag and filter signatures, then `field`. `None` for permissions
    /// other than `read` (never cached).
    ///
    /// PHP: `Database::getQueryCacheField()`.
    pub fn query_cache_field(
        &self,
        collection: Option<&Document>,
        queries: &[Query],
        field: &str,
        for_permission: &str,
    ) -> Result<Option<String>> {
        self.check_query_types(queries)?;
        if for_permission != Self::PERMISSION_READ {
            return Ok(None);
        }
        let authorization = self.authorization();
        let mut roles: Vec<Value> = Vec::new();
        for role in authorization.roles() {
            if !roles.iter().any(|r| to_text(r) == to_text(&role)) {
                roles.push(role);
            }
        }
        roles.sort_by(|a, b| a.cmp_php(b));
        let filters: Array = self
            .active_filter_signatures()
            .into_iter()
            .map(|(n, s)| (ArrayKey::normalize(Str::from(n)), Value::Str(Str::from(s))))
            .collect();
        let payload = assoc(vec![
            ("version", Value::Int(1)),
            (
                "authorization",
                Value::Array(assoc(vec![
                    ("enabled", Value::Bool(authorization.status())),
                    ("roles", Value::Array(roles.into_iter().collect())),
                ])),
            ),
            ("database", Value::Str(Str::from(self.database()))),
            (
                "queries",
                Value::Array(queries.iter().map(|q| Value::Array(self.serialize_query_cache_query(q))).collect()),
            ),
            ("relationships", Value::Bool(self.state().resolve_relationships)),
            ("filters", Value::Array(filters)),
        ]);
        let mut schema_hash = String::new();
        if let Some(collection) = collection.filter(|c| !c.is_empty()) {
            let attribute = |key: &str, default: Value| collection.get(key).cloned().unwrap_or(default);
            let text = format!(
                "{}{}{}{}",
                json(&attribute("attributes", Value::Array(Array::new()))),
                json(&attribute("indexes", Value::Array(Array::new()))),
                json(&attribute("$permissions", Value::Array(Array::new()))),
                json(&attribute("documentSecurity", Value::Bool(false))),
            );
            schema_hash = md5(text.as_bytes());
        }
        Ok(Some(format!("{schema_hash}:{}:{field}", md5(json(&Value::Array(payload)).as_bytes()))))
    }

    /// A query as the query cache field hashes it: method, attribute (when
    /// not empty) and values (nested queries serialized the same way).
    ///
    /// PHP: `Database::serializeQueryCacheQuery()` (private).
    pub(crate) fn serialize_query_cache_query(&self, query: &Query) -> Array {
        let mut serialized = assoc(vec![("method", Value::Str(Str::from(query.method().as_str())))]);
        if !query.attribute().is_empty() {
            serialized.set(ArrayKey::from("attribute"), Value::Str(Str::from(query.attribute())));
        }
        let values: Array = query
            .values()
            .iter()
            .map(|v| match v {
                Value::Ext(Ext::Query(q)) => Value::Array(self.serialize_query_cache_query(q)),
                other => self.normalize_query_cache_query_value(other.clone()),
            })
            .collect();
        serialized.set(ArrayKey::from("values"), Value::Array(values));
        serialized
    }

    /// A query value with documents turned into arrays, recursively.
    ///
    /// PHP: `Database::normalizeQueryCacheQueryValue()` (private).
    // A method like PHP's (Rust 1.88's clippy flags `self` as only recursed with).
    #[allow(clippy::only_used_in_recursion)]
    pub(crate) fn normalize_query_cache_query_value(&self, value: Value) -> Value {
        let value = match value {
            Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(&[], &[])),
            other => other,
        };
        match value {
            Value::Array(a) => {
                Value::Array(a.into_iter().map(|(k, v)| (k, self.normalize_query_cache_query_value(v))).collect())
            }
            other => other,
        }
    }

    /// Purges every cached document of a collection, and the collection
    /// key.
    ///
    /// PHP: `Database::purgeCachedCollection()`.
    pub async fn purge_cached_collection(&self, collection_id: &str) -> Result<bool> {
        let (collection_key, _, _) = self.cache_keys(collection_id, None, &[]);
        let cache = self.cache();
        let document_keys = cache.list(&collection_key).await.map_err(cache_error)?;
        for document_key in document_keys {
            cache.purge(&document_key, "").await.map_err(cache_error)?;
        }
        cache.purge(&collection_key, "").await.map_err(cache_error)?;
        Ok(true)
    }

    /// Purges a cached document (all its hash variants) without triggering
    /// the purge event.
    ///
    /// PHP: `Database::purgeCachedDocumentInternal()` (protected).
    pub(crate) async fn purge_cached_document_internal(&self, collection_id: &str, id: Option<&str>) -> Result<bool> {
        let Some(id) = id else {
            return Ok(true);
        };
        let (collection_key, document_key) = self.cache_base_keys(collection_id, Some(id));
        let cache = self.cache();
        cache.purge(&collection_key, &document_key).await.map_err(cache_error)?;
        cache.purge(&document_key, "").await.map_err(cache_error)?;
        Ok(true)
    }

    /// Purges a cached document and triggers
    /// [`Database::EVENT_DOCUMENT_PURGE`].
    ///
    /// PHP: `Database::purgeCachedDocument()`.
    pub async fn purge_cached_document(&self, collection_id: &str, id: Option<&str>) -> Result<bool> {
        let result = self.purge_cached_document_internal(collection_id, id).await?;
        if let Some(id) = id {
            let event = Document::from_array_unchecked(assoc(vec![
                ("$id", Value::Str(Str::from(id))),
                ("$collection", Value::Str(Str::from(collection_id))),
            ]));
            self.trigger(Self::EVENT_DOCUMENT_PURGE, &Value::from(event))?;
        }
        Ok(result)
    }

    /// Purges a collection's cached queries.
    ///
    /// PHP: `Database::purgeCachedQueries()`.
    pub async fn purge_cached_queries(&self, collection: &str, namespace: Option<&str>) -> Result<bool> {
        let document = self.silent(|| self.get_collection(collection), None).await?;
        let id = if document.is_empty() { collection.to_owned() } else { document.id().to_owned() };
        self.cache().purge(&self.query_cache_key(&id, namespace), "").await.map_err(cache_error)
    }

    /// Read-through cache: the value cached under `key` / `hash` when there
    /// is a valid one (documents restored, cast, checked for TTL expiry and
    /// read permission), otherwise `f()`'s value, saved with a lease unless
    /// it is `false`. `hash: None` bypasses the cache. Cache failures only
    /// warn.
    ///
    /// PHP: `Database::withCache()`. PHP prints a console warning on cache
    /// failures; they are ignored here.
    pub async fn with_cache<F, Fut>(&self, key: &str, f: F, hash: Option<&str>) -> Result<Value>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<Value>>,
    {
        let Some(hash) = hash else {
            return f().await;
        };
        let cache = self.cache();
        let mut refresh = false;
        let cached = cache.load(key, Self::TTL, hash).await.unwrap_or(None).map(from_plain);
        if let Some(cached) = cached.filter(|c| !matches!(c, Value::Null | Value::Bool(false))) {
            if let Some(decoded) = self.restore_cached(&cached).await? {
                return Ok(decoded);
            }
            refresh = true;
        }
        if refresh {
            let _ = cache.purge(key, hash).await;
        }
        let generation = cache.generation(key).await.unwrap_or_else(|_| "0".to_owned());
        let value = f().await?;
        if !matches!(value, Value::Bool(false))
            && let Some(encoded) = cache_entry(&value)
        {
            let _ = cache.save_with_lease(key, &to_plain(&Value::Array(encoded)), hash, &generation).await;
        }
        Ok(value)
    }

    /// The value of a cache entry of [`Database::with_cache`]: `None` when
    /// it cannot be used (and must be refreshed).
    async fn restore_cached(&self, cached: &Value) -> Result<Option<Value>> {
        let Value::Array(entry) = cached else {
            return Ok(None);
        };
        let Some(value) = entry.get_str("value").cloned() else {
            return Ok(None);
        };
        if matches!(value, Value::Bool(false)) {
            return Ok(None);
        }
        let collection_id = match entry.get_str("collection") {
            Some(Value::Str(s)) if !s.as_bytes().is_empty() => to_text(&Value::Str(s.clone())),
            _ => return Ok(Some(value)),
        };
        let collection = self.silent(|| self.get_collection(&collection_id), None).await?;
        if collection.is_empty() {
            return Ok(None);
        }
        let single = matches!(entry.get_str("type"), Some(Value::Str(s)) if s.as_bytes() == b"document");
        let document_security = collection.get("documentSecurity").is_some_and(Value::truthy);
        let authorization = self.authorization();
        let skip_auth = authorization.is_valid(&Input::new(Self::PERMISSION_READ, collection.read()));
        let metadata = collection.id() == Self::METADATA;
        if !skip_auth && !document_security && !metadata {
            return Err(Error::authorization(authorization.description()));
        }
        let payload = if single { Value::Array([value].into_iter().collect()) } else { value };
        let Value::Array(payload) = payload else {
            return Ok(None);
        };
        let mut documents = Vec::new();
        for item in payload.values() {
            let Value::Array(attrs) = item else {
                return Ok(None);
            };
            let document = self.create_document_instance(collection.id(), attrs.clone())?;
            let document = self.casting(&collection, document)?;
            if self.is_ttl_expired(&collection, &document) {
                return Ok(None);
            }
            if !skip_auth
                && document_security
                && !metadata
                && !authorization.is_valid(&Input::new(Self::PERMISSION_READ, document.read()))
            {
                if single {
                    return Ok(None);
                }
                continue;
            }
            documents.push(document);
        }
        Ok(if single {
            documents.into_iter().next().map(Value::from)
        } else {
            Some(Value::Array(documents.into_iter().map(Value::from).collect()))
        })
    }

    /// Runs `f` with the document's tenant when each document carries its
    /// own tenant on shared tables, otherwise as is.
    ///
    /// PHP: `Database::withDocumentTenant()` (private).
    pub(crate) async fn with_document_tenant<T, F, Fut>(&self, document: &Document, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        if self.shared_tables() && self.tenant_per_document() {
            self.with_tenant(document.tenant().unwrap_or(Value::Null), f).await
        } else {
            f().await
        }
    }

    /// A document's identity: `"{tenant}:{id}"` when each document carries
    /// its own tenant on shared tables, otherwise its id.
    ///
    /// PHP: `Database::tenantKey()` (private).
    pub(crate) fn tenant_key(&self, document: &Document) -> String {
        if self.shared_tables() && self.tenant_per_document() {
            format!("{}:{}", document.tenant().map(|t| to_text(&t)).unwrap_or_default(), document.id())
        } else {
            document.id().to_owned()
        }
    }
}

/// Whether a value holds a document anywhere (`$containsDocument`).
fn contains_document(value: &Value) -> bool {
    match value {
        Value::Ext(Ext::Document(_)) => true,
        Value::Array(a) => a.values().any(contains_document),
        _ => false,
    }
}

/// The cache entry [`Database::with_cache`] saves for a callback's value:
/// `{collection, type: document, value}` for a document of a collection,
/// `{collection, type: documents, value}` for a list of documents of one
/// collection, `{value}` for anything without documents; `None` for values
/// that cannot be restored (not cached).
fn cache_entry(value: &Value) -> Option<Array> {
    match value {
        Value::Ext(Ext::Document(d)) => {
            let collection = d.collection();
            (!collection.is_empty()).then(|| {
                assoc(vec![
                    ("collection", Value::Str(Str::from(collection))),
                    ("type", Value::Str(Str::from("document"))),
                    ("value", Value::Array(d.array_copy(&[], &[]))),
                ])
            })
        }
        Value::Array(items) => {
            let mut collection: Option<String> = None;
            let mut has_documents = false;
            let mut has_non_documents = false;
            let mut documents = Array::new();
            for item in items.values() {
                let Value::Ext(Ext::Document(d)) = item else {
                    if has_documents || contains_document(item) {
                        return None;
                    }
                    has_non_documents = true;
                    continue;
                };
                if has_non_documents {
                    return None;
                }
                let of = d.collection();
                if of.is_empty() || collection.as_deref().is_some_and(|c| c != of) {
                    return None;
                }
                collection = Some(of.to_owned());
                has_documents = true;
                let _ = documents.push(Value::Array(d.array_copy(&[], &[])));
            }
            Some(match collection {
                Some(c) if has_documents => assoc(vec![
                    ("collection", Value::Str(Str::from(c))),
                    ("type", Value::Str(Str::from("documents"))),
                    ("value", Value::Array(documents)),
                ]),
                _ => assoc(vec![("value", value.clone())]),
            })
        }
        other => Some(assoc(vec![("value", other.clone())])),
    }
}
