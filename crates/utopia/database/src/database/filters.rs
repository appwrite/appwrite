//! Attribute filters (the global registry and the defaults), document
//! encoding, decoding and casting, the joins' encode and decode, and the
//! adapter's casting passthroughs.

use std::sync::{Arc, LazyLock, RwLock};

use async_trait::async_trait;
use utopia_query::schema::ColumnType;

use super::{Database, METADATA};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::validator::big_int::BigInt;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, to_text};

/// An attribute filter of every database: transforms an attribute's value
/// on its way to the adapter ([`Filter::encode`]) and back
/// ([`Filter::decode`]). Attributes name their filters; filters run in that
/// order on encode and in reverse on decode. Filters receive the document
/// and the database (Appwrite's filters run reads through it), so they are
/// async.
///
/// PHP: the `encode` and `decode` callables of `Database::addFilter()`. A
/// database's own filters ([`crate::filter::Codec`]) take precedence.
#[async_trait]
pub trait Filter: Send + Sync {
    /// The value to store for `value` (`fn ($value, Document $document,
    /// Database $database)`).
    async fn encode(&self, value: Value, document: &Document, database: &Database) -> Result<Value>;

    /// The value to read for the stored `value` of `attribute` (`fn ($value,
    /// Document $document, Database $database, string $attribute)`).
    async fn decode(&self, value: Value, document: &Document, database: &Database, attribute: &str) -> Result<Value>;

    /// What cache keys carry for this filter, so a changed filter misses
    /// entries written with the old one: `"<encode>:<decode>"`, each part
    /// the `file:line` of PHP's closure (or `Class::method`, or a function
    /// name). Cache entries are shared with PHP, so a filter porting a PHP
    /// filter returns PHP's signature for it.
    ///
    /// PHP: `computeCallableSignature($encode).':'.computeCallableSignature($decode)`.
    fn signature(&self) -> String;
}

/// The global filters (PHP's static `$filters`), by name in registration
/// order, and whether the defaults were registered.
struct Registry {
    filters: Vec<(String, Arc<dyn Filter>, String)>,
    defaults: bool,
}

static REGISTRY: LazyLock<RwLock<Registry>> =
    LazyLock::new(|| RwLock::new(Registry { filters: Vec::new(), defaults: false }));

fn registry() -> std::sync::RwLockReadGuard<'static, Registry> {
    REGISTRY.read().unwrap_or_else(|e| e.into_inner())
}

/// Registers the default filters once, before anything else touches the
/// registry, so an explicit [`Database::add_filter`] always wins
/// (`registerDefaultFilters()`).
pub(crate) fn register_default_filters() {
    {
        let mut r = REGISTRY.write().unwrap_or_else(|e| e.into_inner());
        if r.defaults {
            return;
        }
        r.defaults = true;
    }
    let defaults: [(&str, Arc<dyn Filter>); 7] = [
        ("json", Arc::new(JsonFilter)),
        ("datetime", Arc::new(DatetimeFilter)),
        ("point", Arc::new(SpatialFilter(ColumnType::Point, 433, 449))),
        ("linestring", Arc::new(SpatialFilter(ColumnType::Linestring, 471, 487))),
        ("polygon", Arc::new(SpatialFilter(ColumnType::Polygon, 509, 525))),
        ("vector", Arc::new(VectorFilter)),
        ("object", Arc::new(ObjectFilter)),
    ];
    for (name, filter) in defaults {
        Database::add_filter(name, filter);
    }
}

impl Database {
    /// Registers `filter` as `name` for every database, replacing one of the
    /// same name in its place (`addFilter()`, static). A database's own
    /// filters take precedence.
    pub fn add_filter(name: &str, filter: Arc<dyn Filter>) {
        register_default_filters();
        let signature = filter.signature();
        let mut r = REGISTRY.write().unwrap_or_else(|e| e.into_inner());
        match r.filters.iter_mut().find(|(n, _, _)| n == name) {
            Some(entry) => *entry = (name.to_owned(), filter, signature),
            None => r.filters.push((name.to_owned(), filter, signature)),
        }
    }

    /// The global filter registered as `name` (`self::$filters[$name]`).
    pub(crate) fn global_filter(name: &str) -> Option<Arc<dyn Filter>> {
        registry().filters.iter().find(|(n, _, _)| n == name).map(|(_, f, _)| f.clone())
    }

    /// The global filters' signatures by name, in registration order.
    pub(crate) fn global_filter_signatures() -> Vec<(String, String)> {
        registry().filters.iter().map(|(n, _, s)| (n.clone(), s.clone())).collect()
    }

    /// `document` ready for the adapter (`encode()`): each attribute's
    /// default applied to a missing or `null` value (when `apply_defaults`),
    /// each value passed through its filters' encode in order, `$createdAt`
    /// and `$updatedAt` emptied to `null`; unknown attributes dropped when
    /// [`Database::set_drop_unknown_attributes`] is on.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an unknown filter (`Filter:
    /// <name> not found`), and ([`crate::Kind::Database`]) with a filter's
    /// message when it fails.
    pub async fn encode(
        &self,
        collection: &Collection,
        mut document: Document,
        apply_defaults: bool,
    ) -> Result<Document> {
        let attributes = collection.attributes_with(self.internal_attributes())?;
        let mut known: Vec<&str> = Vec::with_capacity(attributes.len());
        for attribute in &attributes {
            let key = attribute.key.as_str();
            known.push(key);
            let exists = document.has(key);
            let value = if exists { document.raw(key).cloned().unwrap_or(Value::Null) } else { Value::Null };

            if (key == document::CREATED_AT || key == document::UPDATED_AT)
                && matches!(&value, Value::Str(s) if empty_string(s.as_bytes()))
            {
                document.put(key, Value::Null);
                continue;
            }
            if key == document::PERMISSIONS {
                continue;
            }
            let default = &attribute.default;
            if !exists && matches!(default, Value::Null) {
                continue;
            }
            if matches!(value, Value::Ext(Ext::Operator(_))) {
                continue;
            }
            let value = if matches!(value, Value::Null) && !matches!(default, Value::Null) {
                if !apply_defaults {
                    continue;
                }
                if attribute.array { default.clone() } else { Value::Array(Array::from_list(vec![default.clone()])) }
            } else if attribute.array {
                value
            } else {
                Value::Array(Array::from_list(vec![value]))
            };
            if matches!(value, Value::Null) {
                continue;
            }
            let mut value = value;
            if !attribute.filters.is_empty()
                && let Value::Array(nodes) = &mut value
            {
                let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                for k in keys {
                    let Some(mut node) = nodes.get(&k).cloned() else { continue };
                    if matches!(node, Value::Null) {
                        continue;
                    }
                    for filter in &attribute.filters {
                        node = self.encode_attribute(filter, node, &document).await?;
                    }
                    nodes.set(k, node);
                }
            }
            let value = if attribute.array { value } else { first(&value) };
            document.put(key, value);
        }
        Ok(self.remove_unknown_attributes(collection, document, Some(&known)))
    }

    /// `document` without the attributes the collection does not declare
    /// (`$`-prefixed keys are kept), when dropping unknown attributes is on
    /// and the adapter defines attributes (`removeUnknownAttributes()`).
    /// `known` are the keys the caller already collected.
    pub(crate) fn remove_unknown_attributes(
        &self,
        collection: &Collection,
        mut document: Document,
        known: Option<&[&str]>,
    ) -> Document {
        if !self.is_dropping_unknown_attributes()
            || !self.inner.adapter.supports(crate::capability::Capability::DefinedAttributes)
        {
            return document;
        }
        let collected: Vec<String>;
        let known: Vec<&str> = match known {
            Some(k) => k.to_vec(),
            None => {
                collected =
                    collection.attributes().map(|a| a.iter().map(|a| a.key.clone()).collect()).unwrap_or_default();
                collected.iter().map(String::as_str).collect()
            }
        };
        let keys: Vec<String> = document.keys().map(|k| key_text(&k)).collect();
        let mut dropped: Vec<String> = Vec::new();
        for key in keys {
            if key.starts_with('$') || known.contains(&key.as_str()) {
                continue;
            }
            document.remove(&key);
            dropped.push(key);
        }
        if !dropped.is_empty() {
            let tenant = self.inner.adapter.tenant();
            super::warning(format!(
                "Dropped unknown attributes \"{}\" from collection \"{}\"{}",
                dropped.join("\", \""),
                collection.id(),
                if matches!(tenant, Value::Null) { String::new() } else { format!(" on tenant {}", to_text(&tenant)) }
            ));
        }
        document
    }

    /// `document` as the adapter returned it, decoded (`decode()`):
    /// relationship values moved back from their filtered keys, each value
    /// passed through its filters' decode in reverse order (unless filters
    /// are off or the filter is excluded), limited to `selections` when they
    /// name attributes.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an unknown filter (`Filter
    /// "<name>" not found for attribute "<key>"`), and as a filter does.
    pub async fn decode(
        &self,
        collection: &Collection,
        mut document: Document,
        selections: &[String],
    ) -> Result<Document> {
        let all = collection.attributes()?;
        let adapter = &self.inner.adapter;
        let (relationships, mut attributes): (Vec<&Attribute>, Vec<&Attribute>) =
            all.iter().partition(|a| a.relationship.is_some());

        let mut relationship_keys: Vec<&str> = Vec::new();
        for relationship in &relationships {
            let key = relationship.key.as_str();
            relationship_keys.push(key);
            let filtered = crate::adapter::filter(key);
            if document.has(key) || document.has(&filtered) {
                let value = match document.get(key) {
                    Some(v) => v.clone(),
                    None => document.get(&filtered).cloned().unwrap_or(Value::Null),
                };
                document.remove(&filtered);
                document.put(key, value);
            }
        }

        let internal = self.internal_attributes();
        attributes.extend(internal.iter());
        let is_internal = |key: &str| internal.iter().any(|a| a.key == key);

        let has_selections = !selections.is_empty();
        let select_all = has_selections && selections.iter().any(|s| s == "*");
        let selections_map = has_selections && !select_all;

        let has_relationship_selections = selections_map
            && !relationship_keys.is_empty()
            && selections.iter().any(|s| s.find('.').is_some_and(|dot| relationship_keys.contains(&&s[..dot])));

        let mut filtering: Option<bool> = None;
        let mut disabled: Option<Vec<String>> = None;
        let mut filtered_values: Vec<(String, Value)> = Vec::new();

        for attribute in attributes {
            let key = attribute.key.as_str();
            if key == document::PERMISSIONS {
                continue;
            }
            let mut value = document.get(key).cloned().unwrap_or(Value::Null);
            if matches!(value, Value::Null) && !is_internal(key) {
                let filtered = adapter_filter(adapter.as_ref(), key);
                value = document.get(&filtered).cloned().unwrap_or(Value::Null);
                if filtered != key && document.has(&filtered) {
                    document.remove(&filtered);
                }
            }
            if matches!(value, Value::Ext(Ext::Operator(_))) {
                continue;
            }
            let value = if attribute.array { value } else { Value::Array(Array::from_list(vec![value])) };
            let mut value = if matches!(value, Value::Null) { Value::Array(Array::new()) } else { value };

            let selected = !has_selections || select_all || (selections_map && selections.iter().any(|s| s == key));
            let filters = &attribute.filters;
            if !filters.is_empty() && (selected || has_relationship_selections) {
                let on = *filtering.get_or_insert_with(|| self.is_filtering());
                let off = disabled.get_or_insert_with(|| {
                    self.filter_exclusions().map(|e| e.iter().map(|(n, _)| n.clone()).collect()).unwrap_or_default()
                });
                if on && let Value::Array(nodes) = &mut value {
                    let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                    let off = off.clone();
                    for k in keys {
                        let mut node = nodes.get(&k).cloned().unwrap_or(Value::Null);
                        for filter in filters.iter().rev() {
                            if !off.contains(filter) {
                                node = self.decode_attribute(filter, node, &document, key).await?;
                            }
                        }
                        nodes.set(k, node);
                    }
                }
            }
            let resolved = if attribute.array { value } else { first(&value) };
            if has_relationship_selections {
                match filtered_values.iter_mut().find(|(k, _)| k == key) {
                    Some(entry) => entry.1 = resolved.clone(),
                    None => filtered_values.push((key.to_owned(), resolved.clone())),
                }
            }
            if selected {
                document.put(key, resolved);
            }
        }

        if has_relationship_selections && selections_map {
            for attribute in all {
                let key = attribute.key.as_str();
                if attribute.relationship.is_some() || key == document::PERMISSIONS {
                    continue;
                }
                if !selections.iter().any(|s| s == key)
                    && let Some((_, value)) =
                        filtered_values.iter().find(|(k, v)| k == key && !matches!(v, Value::Null))
                {
                    document.put(key, value.clone());
                }
            }
        }
        Ok(document)
    }

    /// The values a document carries under each join alias, decoded as a
    /// direct read of the joined collection would decode them
    /// (`decodeJoins()`): cast, then passed through their decode filters.
    /// An alias whose `$id` is `null` matched no row, and stays `null`.
    pub(crate) async fn decode_joins(
        &self,
        mut document: Document,
        collections: &[(String, Collection)],
    ) -> Result<Document> {
        for (alias, row) in joined_rows(&document, collections) {
            let Some((_, collection)) = collections.iter().find(|(a, _)| *a == alias) else { continue };
            let keys: Vec<String> = row.keys().map(|k| key_text(&k)).collect();
            let mut data = row;
            data.set(ArrayKey::Str(Str::from(document::COLLECTION)), Value::Str(Str::from(collection.id())));
            let joined = Document::from_row(data)?;
            let joined = self.cast_after_document(collection, joined)?;
            let joined = self.casting(collection, joined)?;
            let joined = self.decode(collection, joined, &keys).await?;
            for key in &keys {
                document.put(format!("{alias}.{key}"), joined.raw(key).cloned().unwrap_or(Value::Null));
            }
        }
        Ok(document)
    }

    /// The values a document carries under each join alias, encoded back to
    /// how the joined collection stores them (`encodeJoins()`), on a copy.
    pub(crate) async fn encode_joins(
        &self,
        document: &Document,
        collections: &[(String, Collection)],
    ) -> Result<Document> {
        let rows = joined_rows(document, collections);
        let mut encoded = document.clone();
        for (alias, row) in rows {
            let Some((_, collection)) = collections.iter().find(|(a, _)| *a == alias) else { continue };
            let keys: Vec<String> = row.keys().map(|k| key_text(&k)).collect();
            let mut data = row;
            data.set(ArrayKey::Str(Str::from(document::COLLECTION)), Value::Str(Str::from(collection.id())));
            let joined = Document::from_row(data)?;
            let joined = self.encode(collection, joined, false).await?;
            let joined = self.cast_before(collection, joined)?;
            for key in &keys {
                encoded.put(format!("{alias}.{key}"), joined.raw(key).cloned().unwrap_or(Value::Null));
            }
        }
        Ok(encoded)
    }

    /// Document attribute values cast to their types by the collection's
    /// attributes (`casting()`, internal): ids to strings, booleans,
    /// integers, big integers that fit, floats; array values decoded from
    /// JSON first. Nothing for an adapter that casts itself.
    pub fn casting(&self, collection: &Collection, mut document: Document) -> Result<Document> {
        if self.inner.adapter.casting().is_some() {
            return Ok(document);
        }
        for attribute in collection.attributes_with(self.internal_attributes())? {
            let kind = attribute.kind;
            let typed = matches!(
                kind,
                ColumnType::Id
                    | ColumnType::Boolean
                    | ColumnType::Integer
                    | ColumnType::BigInteger
                    | ColumnType::Float
                    | ColumnType::Double
            );
            if !(attribute.array || typed) || attribute.key == document::PERMISSIONS {
                continue;
            }
            let key = attribute.key.as_str();
            let Some(value) = document.get(key).cloned() else { continue };
            let value = if attribute.array {
                match value {
                    Value::Str(s) => json_decode_assoc(s.as_bytes()),
                    other => other,
                }
            } else {
                Value::Array(Array::from_list(vec![value]))
            };
            let mut value = value;
            if let Value::Array(nodes) = &mut value {
                let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                for k in keys {
                    let node = nodes.get(&k).cloned().unwrap_or(Value::Null);
                    let node = match kind {
                        ColumnType::Id => Value::Str(Str::from(to_text(&node))),
                        ColumnType::Boolean => Value::Bool(node.truthy()),
                        ColumnType::Integer => Value::Int(crate::value::to_long(&node)),
                        ColumnType::BigInteger => match &node {
                            Value::Str(s) if BigInt::fits_php_int(s.as_bytes(), attribute.signed) => {
                                Value::Int(crate::value::to_long(&node))
                            }
                            _ => node,
                        },
                        ColumnType::Float | ColumnType::Double => Value::Float(node.to_double()),
                        _ => node,
                    };
                    nodes.set(k, node);
                }
            }
            let value = if attribute.array { value } else { first(&value) };
            document.put(key, value);
        }
        Ok(document)
    }

    /// Runs filter `name`'s encode on `value` (`encodeAttribute()`): this
    /// database's codec, else the global filter.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an unknown filter, and
    /// ([`crate::Kind::Database`]) with a filter's message when it fails.
    pub(crate) async fn encode_attribute(&self, name: &str, value: Value, document: &Document) -> Result<Value> {
        let codec = self.settings().codecs.clone();
        if let Some(codecs) = codec {
            let codec = codecs.read().unwrap_or_else(|e| e.into_inner()).get(name).cloned();
            if let Some(codec) = codec {
                return codec.encode(value).map_err(wrap);
            }
        }
        match Self::global_filter(name) {
            Some(filter) => filter.encode(value, document, self).await.map_err(wrap),
            None => Err(Error::not_found(format!("Filter: {name} not found"))),
        }
    }

    /// Runs filter `name`'s decode on `value` (`decodeAttribute()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an unknown filter.
    pub(crate) async fn decode_attribute(
        &self,
        name: &str,
        value: Value,
        document: &Document,
        attribute: &str,
    ) -> Result<Value> {
        let codec = self.settings().codecs.clone();
        if let Some(codecs) = codec {
            let codec = codecs.read().unwrap_or_else(|e| e.into_inner()).get(name).cloned();
            if let Some(codec) = codec {
                return codec.decode(value);
            }
        }
        match Self::global_filter(name) {
            Some(filter) => filter.decode(value, document, self, attribute).await,
            None => Err(Error::not_found(format!("Filter \"{name}\" not found for attribute \"{attribute}\""))),
        }
    }

    /// A document of `collection` from data read back from storage or the
    /// cache (`newDocument()`): of the collection's custom type, when one is
    /// set; non-string permissions dropped, as `Document::fromStorage()`
    /// does.
    pub(crate) fn new_document(&self, collection: &str, data: Array) -> Result<Document> {
        let document = Document::from_storage(data)?;
        Ok(match self.document_type(collection) {
            Some(class) => document.with_class(class),
            None => document,
        })
    }

    /// The document converted to the engine's types, for an adapter that
    /// casts itself (`castBefore()`).
    pub(crate) fn cast_before(&self, collection: &Collection, document: Document) -> Result<Document> {
        match self.inner.adapter.casting() {
            Some(c) => c.cast_before(collection, document),
            None => Ok(document),
        }
    }

    /// One document converted back from the engine's types (`castAfterDocument()`).
    pub(crate) fn cast_after_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        match self.inner.adapter.casting() {
            Some(c) => Ok(c.cast_after(collection, vec![document])?.into_iter().next().unwrap_or_default()),
            None => Ok(document),
        }
    }

    /// Documents converted back from the engine's types (`castAfter()`).
    pub(crate) fn cast_after(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        match self.inner.adapter.casting() {
            Some(c) if !documents.is_empty() => c.cast_after(collection, documents),
            _ => Ok(documents),
        }
    }

    /// The relationship attributes of a collection (`relationshipAttributes()`).
    pub(crate) fn relationship_attributes(collection: &Collection) -> Result<Vec<Attribute>> {
        Ok(collection.attributes()?.iter().filter(|a| a.relationship.is_some()).cloned().collect())
    }

    /// Whether `collection` is the metadata collection.
    pub(crate) fn is_metadata(collection: &str) -> bool {
        collection == METADATA
    }
}

/// `$sequence` moved ahead of `$id`, the key order 7.x returned single
/// reads in (`placeSequenceBeforeId()`).
pub(crate) fn place_sequence_before_id(document: &mut Document) {
    if !document.has(document::SEQUENCE) || !document.has(document::ID) {
        return;
    }
    let sequence = document.raw(document::SEQUENCE).cloned().unwrap_or(Value::Null);
    let mut ordered = Array::with_capacity(document.len());
    for (key, value) in document.iter() {
        if matches!(key, KeyRef::Str(k) if k == document::SEQUENCE.as_bytes()) {
            continue;
        }
        if matches!(key, KeyRef::Str(k) if k == document::ID.as_bytes()) {
            ordered.set(ArrayKey::Str(Str::from(document::SEQUENCE)), sequence.clone());
        }
        ordered.set(key.to_owned(), value.clone());
    }
    *document.as_array_mut() = ordered;
}

/// The row each join alias carries in a document, by attribute
/// (`joinedRows()`): `$permissions` left out, and an alias whose `$id` is
/// `null` matched no row and is left out.
fn joined_rows(document: &Document, collections: &[(String, Collection)]) -> Vec<(String, Array)> {
    if collections.is_empty() {
        return Vec::new();
    }
    let mut rows: Vec<(String, Array)> = Vec::new();
    for (key, value) in document.iter() {
        let key = key_text(&key);
        let Some(dot) = key.find('.') else { continue };
        let alias = &key[..dot];
        let attribute = &key[dot + 1..];
        if !collections.iter().any(|(a, _)| a == alias) || attribute == document::PERMISSIONS {
            continue;
        }
        let row = match rows.iter_mut().find(|(a, _)| a == alias) {
            Some((_, row)) => row,
            None => {
                rows.push((alias.to_owned(), Array::new()));
                &mut rows.last_mut().expect("pushed").1
            }
        };
        row.set(ArrayKey::normalize(Str::from(attribute)), value.clone());
    }
    rows.retain(|(_, row)| !matches!(row.get_str(document::ID), Some(Value::Null)));
    rows
}

/// A filter's failure as the database reports it.
fn wrap(e: Error) -> Error {
    let code = e.code();
    Error::database(e.message()).with_code(code).with_previous(e)
}

/// `$adapter->filter($key)`.
fn adapter_filter(_adapter: &dyn crate::adapter::Adapter, key: &str) -> String {
    crate::adapter::filter(key)
}

/// The text of an array key.
pub(crate) fn key_text(key: &KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// `$value[0]` of a wrapped value.
fn first(value: &Value) -> Value {
    match value {
        Value::Array(a) => a.get(&ArrayKey::Int(0)).cloned().unwrap_or(Value::Null),
        _ => Value::Null,
    }
}

/// PHP's `empty()` of a string: `""` or `"0"`.
fn empty_string(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// `json_decode($value, true)` (`null` on invalid JSON).
pub(crate) fn json_decode_assoc(value: &[u8]) -> Value {
    php_std::json::decode_ext::<Ext>(value, Some(true), 512, php_std::json::Flags::NONE).unwrap_or(Value::Null)
}

/// `json_encode($value)` (`false` when it fails).
pub(crate) fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, 512) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `preg_match('/\{\s*\}/', $value)`: an empty JSON object somewhere.
fn has_empty_object(value: &[u8]) -> bool {
    value.iter().enumerate().any(|(i, &c)| {
        c == b'{' && {
            let rest = &value[i + 1..];
            let spaces = rest.iter().take_while(|c| matches!(c, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')).count();
            rest.get(spaces) == Some(&b'}')
        }
    })
}

/// `decodeObject()`: `json_decode(.., true)`, except that empty JSON
/// objects stay `stdClass`.
pub(crate) fn decode_object(value: &[u8]) -> Value {
    if !has_empty_object(value) {
        return json_decode_assoc(value);
    }
    let decoded =
        php_std::json::decode_ext::<Ext>(value, Some(false), 512, php_std::json::Flags::NONE).unwrap_or(Value::Null);
    to_associative(decoded)
}

/// `toAssociative()`: decoded `stdClass` values as arrays, recursively,
/// empty ones kept as `stdClass`.
fn to_associative(value: Value) -> Value {
    match value {
        Value::Object(o) if o.is_empty() => Value::Object(o),
        Value::Object(o) | Value::Array(o) => {
            Value::Array(o.into_iter().map(|(k, v)| (k, to_associative(v))).collect())
        }
        other => other,
    }
}

/// `valuesEqual()`: arrays (and `stdClass`) equal key by key in the same
/// order, other values by `===`.
pub(crate) fn values_equal(value: &Value, old: &Value) -> bool {
    match (value, old) {
        (Value::Object(a), Value::Object(b)) | (Value::Array(a), Value::Array(b)) => {
            a.len() == b.len() && a.iter().zip(b.iter()).all(|((ka, va), (kb, vb))| ka == kb && values_equal(va, vb))
        }
        (a, b) => a.strict_eq(b),
    }
}

// ---- The default filters (the closures of `registerDefaultFilters()`) ----

/// Where PHP's `Database.php` is in the image the PHP runtime runs: the
/// default filters' signatures (`file:line` of their closures) name it, and
/// cache keys shared with PHP carry them.
const DATABASE_PHP: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Database.php";

/// The signature of a default filter whose closures start on `encode` and
/// `decode` of `Database.php` 8.1.3.
fn default_signature(encode: u32, decode: u32) -> String {
    format!("{DATABASE_PHP}:{encode}:{DATABASE_PHP}:{decode}")
}

/// `json`: arrays, `stdClass` and documents to JSON; JSON back to arrays,
/// an object with a `$id` (and such items) as documents.
struct JsonFilter;

#[async_trait]
impl Filter for JsonFilter {
    async fn encode(&self, value: Value, _: &Document, _: &Database) -> Result<Value> {
        let value = match value {
            Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
            other => other,
        };
        Ok(match value {
            Value::Array(_) | Value::Object(_) => json_encode(&value),
            other => other,
        })
    }

    async fn decode(&self, value: Value, _: &Document, _: &Database, _: &str) -> Result<Value> {
        let Value::Str(s) = &value else {
            return Ok(value);
        };
        let decoded = match json_decode_assoc(s.as_bytes()) {
            Value::Null => Value::Array(Array::new()),
            v => v,
        };
        let Value::Array(a) = decoded else {
            return Ok(decoded);
        };
        if a.get_str(document::ID).is_some() {
            return Ok(Value::from(Document::from_storage(a)?));
        }
        let mut out = Array::with_capacity(a.len());
        for (k, item) in a {
            let item = match item {
                Value::Array(i) if i.get_str(document::ID).is_some() => Value::from(Document::from_storage(i)?),
                other => other,
            };
            out.set(k, item);
        }
        Ok(Value::Array(out))
    }

    fn signature(&self) -> String {
        default_signature(355, 369)
    }
}

/// `datetime`: a date string to the storage format in the default
/// timezone; back with `DateTime::formatTz()`.
struct DatetimeFilter;

#[async_trait]
impl Filter for DatetimeFilter {
    async fn encode(&self, value: Value, _: &Document, _: &Database) -> Result<Value> {
        let Value::Str(s) = &value else {
            return Ok(value);
        };
        Ok(match crate::datetime::set_timezone(s.as_bytes()) {
            Ok(formatted) => Value::Str(Str::from(formatted)),
            Err(_) => value,
        })
    }

    async fn decode(&self, value: Value, _: &Document, _: &Database, _: &str) -> Result<Value> {
        let text = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => to_text(&value),
            _ => return Ok(value),
        };
        Ok(crate::datetime::format_tz(Some(&text)).map(|s| Value::Str(Str::from(s))).unwrap_or(Value::Null))
    }

    fn signature(&self) -> String {
        default_signature(402, 421)
    }
}

/// `point`, `linestring` and `polygon`: coordinates encoded by the
/// adapter's [`crate::adapter::Spatial`] (an invalid geometry is returned
/// as given, for the structure validator to reject); decoded by it, `null`
/// on an adapter without spatial values.
struct SpatialFilter(ColumnType, u32, u32);

#[async_trait]
impl Filter for SpatialFilter {
    async fn encode(&self, value: Value, _: &Document, database: &Database) -> Result<Value> {
        if !matches!(value, Value::Array(_)) {
            return Ok(value);
        }
        let Some(spatial) = database.adapter().spatial() else {
            return Ok(value);
        };
        match spatial.encode(&value, self.0) {
            Ok(encoded) => Ok(Value::Str(Str::from(encoded))),
            Err(e) if e.kind() == crate::Kind::Structure => Ok(value),
            Err(e) => Err(e),
        }
    }

    async fn decode(&self, value: Value, _: &Document, database: &Database, _: &str) -> Result<Value> {
        let bytes = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => s.as_bytes().to_vec(),
            other => to_text(other).into_bytes(),
        };
        match database.adapter().spatial() {
            Some(spatial) => spatial.decode(&bytes, self.0),
            None => Ok(Value::Null),
        }
    }

    fn signature(&self) -> String {
        default_signature(self.1, self.2)
    }
}

/// `vector`: a list of numbers to a JSON list of floats; back as decoded.
struct VectorFilter;

#[async_trait]
impl Filter for VectorFilter {
    async fn encode(&self, value: Value, _: &Document, _: &Database) -> Result<Value> {
        let Value::Array(a) = &value else {
            return Ok(value);
        };
        if !a.is_list() || a.values().any(|v| !matches!(v, Value::Int(_) | Value::Float(_))) {
            return Ok(value);
        }
        let floats: Array = a.values().map(|v| Value::Float(v.to_double())).collect();
        Ok(json_encode(&Value::Array(floats)))
    }

    async fn decode(&self, value: Value, _: &Document, _: &Database, _: &str) -> Result<Value> {
        let text = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => s.as_bytes().to_vec(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => to_text(&value).into_bytes(),
            _ => return Ok(value),
        };
        Ok(match decode_object(&text) {
            decoded @ (Value::Array(_) | Value::Object(_)) => decoded,
            _ => match value {
                Value::Str(_) => value,
                _ => Value::Str(Str::from(text)),
            },
        })
    }

    fn signature(&self) -> String {
        default_signature(545, 564)
    }
}

/// `object`: arrays and `stdClass` to JSON; back with `decodeObject()`.
struct ObjectFilter;

#[async_trait]
impl Filter for ObjectFilter {
    async fn encode(&self, value: Value, _: &Document, _: &Database) -> Result<Value> {
        Ok(match value {
            Value::Array(_) | Value::Object(_) => json_encode(&value),
            other => other,
        })
    }

    async fn decode(&self, value: Value, _: &Document, _: &Database, _: &str) -> Result<Value> {
        let Value::Str(s) = &value else {
            return Ok(value);
        };
        Ok(match decode_object(s.as_bytes()) {
            decoded @ (Value::Array(_) | Value::Object(_)) => decoded,
            _ => value,
        })
    }

    fn signature(&self) -> String {
        default_signature(579, 589)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finds_empty_objects_like_the_pcre_pattern() {
        assert!(has_empty_object(b"{}"));
        assert!(has_empty_object(b"{\"a\":{ \n\t}}"));
        assert!(!has_empty_object(b"{\"a\":1}"));
        assert!(!has_empty_object(b"[]"));
    }

    #[test]
    fn decode_object_keeps_empty_objects() {
        let decoded = decode_object(br#"{"a":{},"b":{"c":1},"d":[{}]}"#);
        let Value::Array(a) = decoded else { panic!("an array") };
        assert!(matches!(a.get_str("a"), Some(Value::Object(o)) if o.is_empty()));
        assert!(matches!(a.get_str("b"), Some(Value::Array(b)) if b.get_str("c") == Some(&Value::Int(1))));
        assert!(matches!(decode_object(br#"{"x":{"y":2}}"#), Value::Array(_)));
    }

    /// The default filters' signatures against the PHP source they name:
    /// `Database.php` of utopia-php/database 8.1.3 at the path
    /// `UTOPIA_DATABASE_PHP` names, when set. Each `self::addFilter(` of
    /// `registerDefaultFilters()` gives the start lines of its two closures.
    #[test]
    fn default_signatures_match_the_php_source() {
        let Ok(path) = std::env::var("UTOPIA_DATABASE_PHP") else { return };
        let Ok(source) = std::fs::read_to_string(&path) else { return };
        let lines: Vec<&str> = source.lines().collect();
        let start = lines.iter().position(|l| l.contains("function registerDefaultFilters(")).expect("the defaults");
        let mut closures: Vec<(u32, u32)> = Vec::new();
        let mut current: Vec<u32> = Vec::new();
        for (i, line) in lines.iter().enumerate().skip(start) {
            let trimmed = line.trim_start();
            if trimmed.starts_with("self::addFilter(") {
                current.clear();
            } else if trimmed.starts_with("static function (") && line.starts_with("            static") {
                current.push(i as u32 + 1);
                if current.len() == 2 {
                    closures.push((current[0], current[1]));
                }
            }
            if trimmed.starts_with("private static function decodeObject") {
                break;
            }
        }
        let defaults: [&dyn Filter; 7] = [
            &JsonFilter,
            &DatetimeFilter,
            &SpatialFilter(ColumnType::Point, 433, 449),
            &SpatialFilter(ColumnType::Linestring, 471, 487),
            &SpatialFilter(ColumnType::Polygon, 509, 525),
            &VectorFilter,
            &ObjectFilter,
        ];
        let ours: Vec<String> = defaults.iter().map(|f| f.signature()).collect();
        let php: Vec<String> = closures.iter().map(|&(e, d)| default_signature(e, d)).collect();
        assert_eq!(ours, php);
    }
}
