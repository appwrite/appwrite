//! Unique indexes of the [`Redis`] adapter: the signatures of the values a
//! document holds under each unique index, checked against the stored
//! documents and the other documents of a write.

use std::collections::HashMap;

use redis::aio::ConnectionLike;

use super::schema_ops::unique_index_attributes;
use super::{Redis, reply_bytes};
use crate::adapter::Adapter;
use crate::document::Document;
use crate::error::{Error, Result, UNIQUE_MESSAGE};
use crate::value::{Ext, Value};

/// A write of an upsert or a batch update (`Adapter\Redis\Write`): the id,
/// the key, the payload it replaces (`None` when it creates the document),
/// the document and its tenant.
#[derive(Debug, Clone)]
pub(super) struct Write {
    pub id: String,
    pub key: String,
    pub payload: Option<Vec<u8>>,
    pub document: Document,
    pub tenant: Option<Value>,
}

/// The signature of each unique index a document holds values for, by index
/// position: `serialize()` of its normalized values, the tenant first under
/// shared tables.
type Signatures = Vec<(usize, Vec<u8>)>;

/// `normalizeIndexValue()`: a boolean as `1`/`0`, an array as its JSON, a
/// numeric string as its number.
pub(super) fn normalize_index_value(value: Value) -> Value {
    match value {
        Value::Bool(b) => Value::Int(i64::from(b)),
        Value::Array(_) => {
            match php_std::json::encode(&crate::value::json_value(&value), php_std::json::Flags::NONE, 512) {
                Ok(json) => Value::Str(json.into()),
                Err(_) => Value::Bool(false),
            }
        }
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), _)) => Value::Int(i),
            Some((php_std::value::Number::Float(f), _)) => Value::Float(f),
            None => Value::Str(s),
        },
        Value::Ext(Ext::Document(d)) => Value::Ext(Ext::Document(d)),
        other => other,
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    /// The attributes of each unique index of a collection
    /// (`uniqueIndexAttributes()`).
    async fn unique_indexes(&self, collection: &str) -> Result<Vec<Vec<String>>> {
        Ok(unique_index_attributes(&self.read_indexes(&self.meta_key(collection)).await?))
    }

    /// `uniqueSignatures()`: an index where the document holds a `null` is
    /// left out, as nulls never collide.
    fn unique_signatures(&self, document: &Document, indexes: &[Vec<String>], tenant: &Value) -> Signatures {
        let mut signatures = Vec::new();
        'indexes: for (position, attributes) in indexes.iter().enumerate() {
            let mut signature = Vec::with_capacity(attributes.len() + 1);
            for attribute in attributes {
                let value = self.resolve(document, attribute);
                if matches!(value, Value::Null) {
                    continue 'indexes;
                }
                signature.push(normalize_index_value(value));
            }
            if self.shared_tables() {
                signature.insert(0, tenant.clone());
            }
            signatures.push((position, php_std::serialize::serialize(&Value::Array(signature.into_iter().collect()))));
        }
        signatures
    }

    /// The tenant a document's unique values are checked under:
    /// `$document->getTenant() ?? $this->getTenant()` under shared tables.
    fn unique_tenant(&self, document: &Document) -> Value {
        if !self.shared_tables() {
            return Value::Null;
        }
        document.tenant().unwrap_or_else(|| self.tenant())
    }

    /// `enforceUniqueIndexes()`.
    pub(super) async fn enforce_unique(&self, collection: &str, document: &Document, exclude: Option<&str>) -> Result<()> {
        let documents = std::slice::from_ref(document);
        let replaced: Vec<String> = exclude.map(|id| vec![id.to_owned()]).unwrap_or_default();
        self.enforce_unique_for(collection, documents, &replaced).await
    }

    /// `enforceUniqueIndexesForDocuments()`: refuses two documents of the
    /// write sharing a unique value, or one sharing it with a stored
    /// document it does not replace. `replaced` holds the stored id each
    /// document overwrites, by position.
    pub(super) async fn enforce_unique_for(
        &self,
        collection: &str,
        documents: &[Document],
        replaced: &[String],
    ) -> Result<()> {
        let indexes = self.unique_indexes(collection).await?;
        if indexes.is_empty() {
            return Ok(());
        }
        // By id set: its tenant, the ids replaced, and the claimed
        // signatures by index.
        let mut order: Vec<String> = Vec::new();
        let mut tenants: HashMap<String, Value> = HashMap::new();
        let mut skipped: HashMap<String, Vec<String>> = HashMap::new();
        let mut claimed: HashMap<String, HashMap<usize, Vec<Vec<u8>>>> = HashMap::new();
        for (position, document) in documents.iter().enumerate() {
            let tenant = self.unique_tenant(document);
            let idx_key = self.idx_key(collection, Some(&tenant));
            tenants.insert(idx_key.clone(), tenant.clone());
            if let Some(id) = replaced.get(position) {
                skipped.entry(idx_key.clone()).or_default().push(super::lower(id));
            }
            for (index, signature) in self.unique_signatures(document, &indexes, &tenant) {
                let claims = claimed.entry(idx_key.clone()).or_default().entry(index).or_default();
                if claims.contains(&signature) {
                    return Err(Error::unique(UNIQUE_MESSAGE));
                }
                claims.push(signature);
                if !order.contains(&idx_key) {
                    order.push(idx_key.clone());
                }
            }
        }
        for idx_key in order {
            let tenant = tenants.get(&idx_key).cloned().unwrap_or(Value::Null);
            let skip = skipped.get(&idx_key).cloned().unwrap_or_default();
            let (owners, _) = self.stored_unique_values(collection, &idx_key, &tenant, &indexes, &skip).await?;
            let signatures = &claimed[&idx_key];
            for (index, values) in &owners {
                if let Some(claims) = signatures.get(index)
                    && claims.iter().any(|claim| values.contains_key(claim))
                {
                    return Err(Error::unique(UNIQUE_MESSAGE));
                }
            }
        }
        Ok(())
    }

    /// `enforceUniqueIndexesInOrder()`: refuses an upsert batch as writing
    /// its documents one after another would; a document replacing a stored
    /// one frees that id's values.
    pub(super) async fn enforce_unique_in_order(&self, collection: &str, writes: &[Write]) -> Result<()> {
        let indexes = self.unique_indexes(collection).await?;
        if indexes.is_empty() {
            return Ok(());
        }
        type Owners = HashMap<usize, HashMap<Vec<u8>, String>>;
        let mut owners: HashMap<String, Owners> = HashMap::new();
        let mut held: HashMap<String, HashMap<String, Signatures>> = HashMap::new();
        for write in writes {
            let tenant = self.unique_tenant(&write.document);
            let idx_key = self.idx_key(collection, Some(&tenant));
            if !owners.contains_key(&idx_key) {
                let (o, h) = self.stored_unique_values(collection, &idx_key, &tenant, &indexes, &[]).await?;
                owners.insert(idx_key.clone(), o);
                held.insert(idx_key.clone(), h);
            }
            let id = super::lower(&write.id);
            let signatures = self.unique_signatures(&write.document, &indexes, &tenant);
            let bucket = owners.get_mut(&idx_key).expect("loaded above");
            for (index, signature) in &signatures {
                if let Some(owner) = bucket.get(index).and_then(|values| values.get(signature))
                    && (write.payload.is_none() || *owner != id)
                {
                    return Err(Error::unique(UNIQUE_MESSAGE));
                }
            }
            let holders = held.get_mut(&idx_key).expect("loaded above");
            if let Some(previous) = holders.get(&id) {
                for (index, signature) in previous {
                    if let Some(values) = bucket.get_mut(index) {
                        values.remove(signature);
                    }
                }
            }
            for (index, signature) in &signatures {
                bucket.entry(*index).or_default().insert(signature.clone(), id.clone());
            }
            holders.insert(id, signatures);
        }
        Ok(())
    }

    /// `storedUniqueValues()`: for the stored documents of one id set
    /// (without `skipped`), by index and signature the lower-cased id holding
    /// it, and by id its signatures.
    async fn stored_unique_values(
        &self,
        collection: &str,
        idx_key: &str,
        tenant: &Value,
        indexes: &[Vec<String>],
        skipped: &[String],
    ) -> Result<(HashMap<usize, HashMap<Vec<u8>, String>>, HashMap<String, Signatures>)> {
        let mut ids = Vec::new();
        let mut keys = Vec::new();
        for member in self.members(idx_key).await? {
            let id = super::lower(&member);
            if skipped.contains(&id) {
                continue;
            }
            keys.push(self.doc_key(collection, &member, Some(tenant)));
            ids.push(id);
        }
        let mut owners: HashMap<usize, HashMap<Vec<u8>, String>> = HashMap::new();
        let mut held: HashMap<String, Signatures> = HashMap::new();
        if keys.is_empty() {
            return Ok((owners, held));
        }
        let payloads = self.mget(&keys).await?;
        for (position, payload) in payloads.iter().enumerate() {
            let Some(payload) = reply_bytes(payload).filter(|p| !p.is_empty()) else {
                continue;
            };
            let existing = Self::decode(&payload)?;
            if self.shared_tables() && !existing.tenant().unwrap_or(Value::Null).strict_eq(tenant) {
                continue;
            }
            let Some(id) = ids.get(position) else {
                continue;
            };
            let signatures = self.unique_signatures(&existing, indexes, tenant);
            for (index, signature) in &signatures {
                owners.entry(*index).or_default().insert(signature.clone(), id.clone());
            }
            held.insert(id.clone(), signatures);
        }
        Ok((owners, held))
    }
}
