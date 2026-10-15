//! The keys of the document cache and the query cache, which PHP shares:
//! every key and hash here is byte for byte what `utopia-php/database` 8.x
//! computes.

use md5::{Digest, Md5};
use php_std::sort::SortFlags;
use utopia_query::{Method, Object, Value as QValue};

use super::{Database, METADATA};
use crate::cache::Scope;
use crate::collection::Collection;
use crate::error::Result;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::value::{Array, ArrayKey, Str, Value, to_text};

/// `md5()` as lower-case hex.
pub(crate) fn md5_hex(bytes: impl AsRef<[u8]>) -> String {
    hex::encode(Md5::digest(bytes.as_ref()))
}

/// `json_encode($value)` with PHP's default flags; `""` when it fails (PHP's
/// `?: ''`).
pub(crate) fn json(value: &Value) -> String {
    php_std::json::encode(value, php_std::json::Flags::NONE, 512).unwrap_or_default()
}

/// A tenant as `sprintf('%s', $tenant)` prints it (`null` is empty).
pub(crate) fn tenant_segment(tenant: &Value) -> String {
    if matches!(tenant, Value::Null) { String::new() } else { to_text(tenant) }
}

/// PHP's truthiness of a string id (`""` and `"0"` are falsy).
fn truthy(id: Option<&str>) -> Option<&str> {
    id.filter(|i| !i.is_empty() && *i != "0")
}

fn assoc(pairs: Vec<(&str, Value)>) -> Value {
    Value::Array(pairs.into_iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k)), v)).collect())
}

impl Database {
    /// The collection key and the document key of a document's cache entry
    /// (`getCacheBaseKeys()`): `<name>-cache-<hostname>:<database>:<namespace>:<tenant>:collection:<id>`,
    /// and `<collectionKey>:<documentId>` (empty without a document id). A
    /// global collection's definition is keyed without a tenant.
    pub async fn cache_base_keys(&self, collection: &str, document: Option<&str>) -> Result<(String, String)> {
        let hostname = self.hostname().await?.unwrap_or_default();
        let adapter = &self.inner.adapter;
        let mut tenant = adapter.tenant();
        if collection == METADATA
            && adapter.shared_tables()
            && let Some(id) = document
            && self.settings().global_collections.iter().any(|g| g == id)
        {
            tenant = Value::Null;
        }
        let key = format!(
            "{}-cache-{}:{}:{}:{}:collection:{}",
            self.cache_name(),
            hostname,
            adapter.database(),
            adapter.namespace(),
            tenant_segment(&tenant),
            collection
        );
        let document_key = match truthy(document) {
            Some(id) => format!("{key}:{id}"),
            None => String::new(),
        };
        Ok((key, document_key))
    }

    /// The collection key, the document key and the hash key of a document's
    /// cache entry (`getCacheKeys()`): the hash key is the document key with
    /// the md5 of the selects (sorted), whether relationships resolve, and
    /// the active filter signatures.
    pub async fn cache_keys(
        &self,
        collection: &str,
        document: Option<&str>,
        selects: &[String],
    ) -> Result<(String, String, String)> {
        let (collection_key, document_key) = self.cache_base_keys(collection, document).await?;
        let mut hash_key = String::new();
        if truthy(document).is_some() {
            let mut sorted: Array = selects.iter().map(|s| Value::Str(Str::from(s.as_str()))).collect();
            let _ = php_std::sort::sort(&mut sorted, SortFlags::Regular);
            let relationships = collection != METADATA && self.relationship_hook().is_some_and(|h| h.is_enabled());
            let payload = assoc(vec![
                ("selects", Value::Array(sorted)),
                ("relationships", Value::Bool(relationships)),
                ("filters", self.active_filter_signatures()),
            ]);
            hash_key = format!("{document_key}:{}", md5_hex(json(&payload)));
        }
        Ok((collection_key, document_key, hash_key))
    }

    /// The key of a collection's caller-owned [`Database::with_cache`]
    /// region (`getQueryCacheKey()`).
    pub async fn query_cache_key(&self, collection: &str, namespace: Option<&str>) -> Result<String> {
        let hostname = self.hostname().await?.unwrap_or_default();
        let adapter = &self.inner.adapter;
        Ok(format!(
            "{}-cache-{}:{}:{}:{}:collection:{}:query",
            self.cache_name(),
            hostname,
            adapter.database(),
            namespace.map_or_else(|| adapter.namespace(), str::to_owned),
            tenant_segment(&adapter.tenant()),
            collection
        ))
    }

    /// The scope the query cache keys and times this database's entries by
    /// (`getQueryCacheScope()`).
    pub(crate) async fn query_cache_scope(&self, namespace: Option<&str>) -> Scope {
        let adapter = &self.inner.adapter;
        Scope {
            hostname: self.hostname().await.ok().flatten().unwrap_or_default(),
            database: adapter.database(),
            namespace: namespace.map_or_else(|| adapter.namespace(), str::to_owned),
            tenant: adapter.tenant(),
            name: self.cache_name(),
            writer_timeout: self.cache_writer_timeout(),
        }
    }

    /// The stable cache field of a query's cached results
    /// (`getQueryCacheField()`, internal): `None` for any permission but read,
    /// inside a transaction, and for a random order.
    pub fn query_cache_field(
        &self,
        collection: Option<&Collection>,
        queries: &[Query],
        field: &str,
        for_permission: PermissionType,
    ) -> Result<Option<String>> {
        if for_permission != PermissionType::Read || self.inner.adapter.in_transaction() {
            return Ok(None);
        }
        if queries.iter().any(|q| q.method() == Method::OrderRandom) {
            return Ok(None);
        }
        let authorization = self.authorization();
        let roles: Array = authorization.role_names().into_iter().map(|r| Value::Str(Str::from(r))).collect();
        let mut roles = php_std::sort::array_unique(&roles, SortFlags::String).unwrap_or(roles);
        roles = roles.values().cloned().collect();
        let _ = php_std::sort::sort(&mut roles, SortFlags::Regular);
        let payload = assoc(vec![
            ("version", Value::Int(1)),
            (
                "authorization",
                assoc(vec![("enabled", Value::Bool(authorization.status())), ("roles", Value::Array(roles))]),
            ),
            ("database", Value::Str(Str::from(self.database()))),
            ("queries", Value::Array(queries.iter().map(|q| serialize_query(q)).collect())),
            ("relationships", Value::Bool(self.relationship_hook().is_some_and(|h| h.is_enabled()))),
            ("filters", self.active_filter_signatures()),
        ]);
        let fingerprint = match collection {
            Some(c) => c.fingerprint()?,
            None => String::new(),
        };
        Ok(Some(format!("{fingerprint}:{}:{field}", md5_hex(json(&payload)))))
    }

    /// The key of a collection definition in the per-process caches
    /// (`getCollectionMetadataCacheKey()`): database, namespace, a typed
    /// tenant and the collection.
    pub(crate) fn collection_metadata_cache_key(&self, collection: &str) -> String {
        let adapter = &self.inner.adapter;
        let tenant = match adapter.tenant() {
            Value::Null => "null".to_owned(),
            Value::Int(i) => format!("integer:{i}"),
            other => {
                let s = to_text(&other);
                format!("string:{}:{s}", s.len())
            }
        };
        format!("{}::{}::{tenant}::{collection}", adapter.database(), adapter.namespace())
    }

    /// The signatures of the filters in effect, by name, sorted by name
    /// (`getActiveFilterSignatures()`): none while filtering is off; this
    /// database's codecs over the global filters; excluded filters left out.
    pub(crate) fn active_filter_signatures(&self) -> Value {
        if !self.is_filtering() {
            return Value::Array(Array::new());
        }
        let mut signatures: Vec<(String, String)> = Vec::new();
        let codecs = self.settings().codecs.clone();
        if let Some(codecs) = codecs {
            let codecs = codecs.read().unwrap_or_else(|e| e.into_inner());
            signatures.extend(codecs.signatures().map(|(n, s)| (n.to_owned(), s.to_owned())));
        }
        for (name, signature) in Database::global_filter_signatures() {
            if !signatures.iter().any(|(n, _)| *n == name) {
                signatures.push((name, signature));
            }
        }
        if let Some(excluded) = self.filter_exclusions() {
            signatures.retain(|(n, _)| !excluded.iter().any(|(e, _)| e == n));
        }
        let mut map: Array = signatures
            .into_iter()
            .map(|(n, s)| (ArrayKey::normalize(Str::from(n)), Value::Str(Str::from(s))))
            .collect();
        php_std::sort::ksort(&mut map, SortFlags::Regular);
        Value::Array(map)
    }
}

/// A query as the query cache hashes it (`serializeQueryCacheQuery()`):
/// `method`, `attribute` when not empty, and `values` with nested queries
/// serialized and documents as their arrays.
pub(crate) fn serialize_query(query: &utopia_query::Query) -> Value {
    let mut pairs: Vec<(&str, Value)> = vec![("method", Value::Str(Str::from(query.method().as_str())))];
    if !query.attribute().is_empty() {
        pairs.push(("attribute", Value::Str(Str::from(query.attribute()))));
    }
    let values: Array = query
        .values()
        .values()
        .map(|v| match v {
            QValue::Ext(Object::Query(q)) => serialize_query(q),
            other => normalize_query_value(other),
        })
        .collect();
    pairs.push(("values", Value::Array(values)));
    assoc(pairs)
}

/// A query value as the query cache hashes it (`normalizeQueryCacheQueryValue()`):
/// a document as its array copy, arrays element by element.
pub(crate) fn normalize_query_value(value: &QValue) -> Value {
    match value {
        QValue::Null => Value::Null,
        QValue::Bool(b) => Value::Bool(*b),
        QValue::Int(i) => Value::Int(*i),
        QValue::Float(f) => Value::Float(*f),
        QValue::Str(s) => Value::Str(s.clone()),
        QValue::Array(a) => Value::Array(a.iter().map(|(k, v)| (k.to_owned(), normalize_query_value(v))).collect()),
        QValue::Object(a) => Value::Object(a.iter().map(|(k, v)| (k.to_owned(), normalize_query_value(v))).collect()),
        QValue::Ext(Object::Row { fields, .. }) => {
            Value::Array(fields.iter().map(|(k, v)| (k.to_owned(), normalize_query_value(v))).collect())
        }
        QValue::Ext(Object::Nulls(n)) => Value::Str(Str::from(n.as_str())),
        QValue::Ext(Object::Query(q)) => serialize_query(q),
    }
}

/// A document value as the query cache hashes it (a cursor's array).
pub(crate) fn normalize_value(value: &Value) -> Value {
    match value {
        Value::Ext(crate::value::Ext::Document(d)) => normalize_value(&Value::Array(d.array_copy())),
        Value::Array(a) => Value::Array(a.iter().map(|(k, v)| (k.to_owned(), normalize_value(v))).collect()),
        other => other.clone(),
    }
}

#[cfg(test)]
mod tests {
    use super::super::testing::{StubAdapter, block_on, uncached};
    use crate::permission::PermissionType;
    use crate::query::Query;
    use crate::value::Value;

    /// The keys PHP 8.1.3 computes for the same database (recorded with
    /// `Adapter\Memory`, which has no connection, as the stub).
    #[test]
    fn cache_keys_match_php() {
        block_on(async {
            let db = uncached(StubAdapter::new());
            db.authorization().clean_roles();
            db.set_database("appwrite").set_namespace("_1");
            let selects = vec!["name".to_owned(), "a".to_owned()];
            let keys = db.cache_keys("users", Some("u1"), &selects).await.unwrap();
            assert_eq!(keys.0, "default-cache-:appwrite:_1::collection:users");
            assert_eq!(keys.1, "default-cache-:appwrite:_1::collection:users:u1");
            assert_eq!(keys.2, "default-cache-:appwrite:_1::collection:users:u1:bc7e0c80e749ec6ec2343e872d592fba");

            db.authorization().add_role("any");
            db.authorization().add_role("user:x");
            let queries = vec![Query::equal("name", ["a"]), Query::limit(5), Query::order_desc("x", None)];
            let field = db.query_cache_field(None, &queries, "documents", PermissionType::Read).unwrap();
            assert_eq!(field.as_deref(), Some(":dc57eeff15e81842cbda766cf87e8279:documents"));
            assert_eq!(
                db.query_cache_key("users", None).await.unwrap(),
                "default-cache-:appwrite:_1::collection:users:query"
            );

            db.set_tenant(Value::Int(3));
            let keys = db.cache_keys("users", Some("u1"), &[]).await.unwrap();
            assert_eq!(keys.0, "default-cache-:appwrite:_1:3:collection:users");
            assert_eq!(keys.2, "default-cache-:appwrite:_1:3:collection:users:u1:67667c8bc0a2866e495c36f5f64abf38");
        });
    }
}
