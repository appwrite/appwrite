//! Attribute filters (the global registry and the defaults), document
//! encoding, decoding and casting, and the filter signatures cache keys
//! carry.
//!
//! Area: schema.

use std::collections::HashSet;
use std::sync::{Arc, LazyLock, RwLock};

use async_trait::async_trait;

use super::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::validator::big_int::BigInt;
use crate::validator::spatial::Spatial;
use crate::validator::{index, index_or, is_numeric, nth};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, to_text};

/// An attribute filter: transforms an attribute's value on its way to the
/// adapter (`encode`) and back (`decode`). Attributes name their filters in
/// their `filters` list; filters run in that order on encode and in reverse
/// on decode.
///
/// Register one globally with [`Database::add_filter`] or for one database
/// with [`Database::new`]. Filters receive the document and the database
/// (Appwrite's filters run finds through it).
///
/// PHP: the `['encode' => callable, 'decode' => callable]` pairs of
/// `Database::addFilter()` and the constructor's `$filters`.
#[async_trait]
pub trait Filter: Send + Sync {
    /// The value to store for `value`.
    ///
    /// PHP: the `encode` callable, `fn (mixed $value, Document $document, Database $database)`.
    async fn encode(&self, value: Value, document: &Document, database: &Database) -> Result<Value>;

    /// The value to return for the stored `value`.
    ///
    /// PHP: the `decode` callable.
    async fn decode(&self, value: Value, document: &Document, database: &Database) -> Result<Value>;

    /// A string identifying this filter's behaviour, which cache keys
    /// carry so that a changed filter misses entries written with the old
    /// one. Cache entries are shared with PHP, so a filter that ports a PHP
    /// filter returns PHP's signature for it: `"<encode>:<decode>"`, each
    /// part `file:line` of the closure (or `Class::method`, or the function
    /// name).
    ///
    /// PHP: `Database::computeCallableSignature()` of the two callables.
    fn signature(&self) -> String;
}

/// The global filters (PHP's static `$filters`, `$filtersVersion` and
/// `$defaultFiltersRegistered`).
struct Registry {
    /// Name => filter, in registration order (re-registering keeps the
    /// position, as a PHP array assignment does).
    filters: Vec<(String, Arc<dyn Filter>)>,
    /// Bumped by every [`Database::add_filter`].
    version: i64,
    /// Whether the defaults were registered.
    defaults_registered: bool,
}

static REGISTRY: LazyLock<RwLock<Registry>> =
    LazyLock::new(|| RwLock::new(Registry { filters: Vec::new(), version: 0, defaults_registered: false }));

fn registry_read() -> std::sync::RwLockReadGuard<'static, Registry> {
    REGISTRY.read().unwrap_or_else(|e| e.into_inner())
}

fn registry_write() -> std::sync::RwLockWriteGuard<'static, Registry> {
    REGISTRY.write().unwrap_or_else(|e| e.into_inner())
}

impl Database {
    /// Registers `filter` as `name` for every database (replacing one of
    /// the same name). Instance filters given to [`Database::new`] take
    /// precedence.
    ///
    /// PHP: `Database::addFilter($name, $encode, $decode)` (static).
    pub fn add_filter(name: &str, filter: Arc<dyn Filter>) {
        Self::register_default_filters();
        let mut r = registry_write();
        match r.filters.iter_mut().find(|(n, _)| n == name) {
            Some(entry) => entry.1 = filter,
            None => r.filters.push((name.to_owned(), filter)),
        }
        r.version += 1;
    }

    /// Registers the default filters once, before anything else touches
    /// the registry, so an explicit [`Database::add_filter`] always wins.
    ///
    /// PHP: `Database::registerDefaultFilters()` (private static).
    pub(crate) fn register_default_filters() {
        {
            let mut r = registry_write();
            if r.defaults_registered {
                return;
            }
            r.defaults_registered = true;
        }
        Self::add_filter("json", Arc::new(JsonFilter));
        Self::add_filter("datetime", Arc::new(DatetimeFilter));
        Self::add_filter(Self::VAR_POINT, Arc::new(PointFilter));
        Self::add_filter(Self::VAR_LINESTRING, Arc::new(LinestringFilter));
        Self::add_filter(Self::VAR_POLYGON, Arc::new(PolygonFilter));
        Self::add_filter(Self::VAR_VECTOR, Arc::new(VectorFilter));
        Self::add_filter(Self::VAR_OBJECT, Arc::new(ObjectFilter));
    }

    /// The global filter registered as `name`.
    ///
    /// PHP: `self::$filters[$name]`.
    pub(crate) fn global_filter(name: &str) -> Option<Arc<dyn Filter>> {
        registry_read().filters.iter().find(|(n, _)| n == name).map(|(_, f)| f.clone())
    }

    /// The global filters, in registration order.
    ///
    /// PHP: `self::$filters`.
    pub(crate) fn global_filters() -> Vec<(String, Arc<dyn Filter>)> {
        registry_read().filters.clone()
    }

    /// The registry version (bumped by every registration).
    ///
    /// PHP: `self::$filtersVersion`.
    pub(crate) fn filters_version() -> i64 {
        registry_read().version
    }

    /// The filter `name` this database uses: the instance one, else the
    /// global one.
    pub(crate) fn filter_named(&self, name: &str) -> Option<Arc<dyn Filter>> {
        self.inner
            .instance_filters
            .iter()
            .find(|(n, _)| n == name)
            .map(|(_, f)| f.clone())
            .or_else(|| Self::global_filter(name))
    }

    /// `document` without the attributes `collection` does not declare
    /// (internal attributes are kept). `known` is the set of attribute ids
    /// already collected by the caller.
    ///
    /// PHP: `Database::removeUnknownAttributes()` (protected). PHP also
    /// prints a console warning naming the dropped keys.
    pub(crate) fn remove_unknown_attributes(
        &self,
        collection: &Document,
        mut document: Document,
        known: Option<&HashSet<String>>,
    ) -> Document {
        if !self.drop_unknown_attributes() || !self.inner.adapter.support_for_attributes() {
            return document;
        }
        let collected;
        let known = match known {
            Some(k) => k,
            None => {
                collected = collection_attributes(collection)
                    .iter()
                    .map(|a| to_text(index(a, "$id")))
                    .collect::<HashSet<String>>();
                &collected
            }
        };
        let keys: Vec<String> = document
            .keys()
            .map(|k| match k {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            })
            .collect();
        for key in keys {
            if key.starts_with('$') || known.contains(&key) {
                continue;
            }
            document.remove(&key);
        }
        document
    }

    /// `document` ready for the adapter: defaults applied (when
    /// `apply_defaults`), each attribute's filters' `encode` run in order,
    /// unknown attributes removed when dropping them is on.
    ///
    /// PHP: `Database::encode()`.
    pub async fn encode(
        &self,
        collection: &Document,
        mut document: Document,
        apply_defaults: bool,
    ) -> Result<Document> {
        let attributes = collection_attribute_refs(collection).chain(self.internal_attribute_values());
        let mut known = HashSet::new();
        for attribute in attributes {
            let key = to_text(index(attribute, "$id"));
            known.insert(key.clone());
            let array = index(attribute, "array").truthy();
            let default = index(attribute, "default").clone();
            let filters = strings(index(attribute, "filters"));
            let value = document.get(&key).cloned().unwrap_or(Value::Null);

            if (key == "$createdAt" || key == "$updatedAt")
                && matches!(&value, Value::Str(s) if empty_string(s.as_bytes()))
            {
                document.set(key.as_str(), Value::Null);
                continue;
            }
            if key == "$permissions" {
                continue;
            }
            if is_null(&value) && is_null(&default) {
                continue;
            }
            if matches!(value, Value::Ext(Ext::Operator(_))) {
                continue;
            }
            let mut value = if is_null(&value) {
                if !apply_defaults {
                    continue;
                }
                if array { default } else { Value::Array([default].into_iter().collect()) }
            } else if array {
                value
            } else {
                Value::Array([value].into_iter().collect())
            };
            if !filters.is_empty()
                && let Value::Array(nodes) = &mut value
            {
                let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                for k in keys {
                    let Some(node) = nodes.get(&k).cloned() else { continue };
                    if is_null(&node) {
                        continue;
                    }
                    let mut node = node;
                    for filter in &filters {
                        node = self.encode_attribute(filter, node, &document).await?;
                    }
                    nodes.set(k, node);
                }
            }
            let value = if array { value } else { nth(&value, 0).clone() };
            document.set(key.as_str(), value);
        }
        Ok(self.remove_unknown_attributes(collection, document, Some(&known)))
    }

    /// `document` as the adapter returned it, decoded: each attribute's
    /// filters' `decode` run in reverse order, keys mapped back from their
    /// internal names, limited to `selections` when not empty.
    ///
    /// PHP: `Database::decode()`.
    pub async fn decode(
        &self,
        collection: &Document,
        mut document: Document,
        selections: &[String],
    ) -> Result<Document> {
        let all: Vec<&Value> = collection_attribute_refs(collection).collect();
        let is_relationship =
            |a: &Value| matches!(index(a, "type"), Value::Str(s) if s.as_bytes() == Self::VAR_RELATIONSHIP.as_bytes());
        let mut attributes: Vec<&Value> = all.iter().copied().filter(|a| !is_relationship(a)).collect();
        let adapter = &self.inner.adapter;

        for relationship in all.iter().copied().filter(|a| is_relationship(a)) {
            let key = to_text(index(relationship, "$id"));
            let filtered = adapter.filter(&key)?;
            if document.has(&key) || document.has(&filtered) {
                let value = match document.get(&key) {
                    Some(v) => v.clone(),
                    None => document.get(&filtered).cloned().unwrap_or(Value::Null),
                };
                document.remove(&filtered);
                document.set(key.as_str(), value);
            }
        }

        let internal = self.internal_attribute_values();
        let internal_keys: HashSet<String> = internal.iter().map(|a| to_text(index(a, "$id"))).collect();
        attributes.extend(internal);

        let has_relationship_selections = selections.iter().any(|s| s.contains('.'));
        let all_selected = selections.iter().any(|s| s == "*");
        // The values decoded, for relationship selections only.
        let mut filtered_values: Vec<(String, Value)> = Vec::new();

        for attribute in attributes {
            let key = to_text(index(attribute, "$id"));
            let array = index(attribute, "array").truthy();
            let filters = strings(index(attribute, "filters"));
            let mut value = document.get(&key).cloned().unwrap_or(Value::Null);

            if key == "$permissions" {
                continue;
            }
            if is_null(&value) && !internal_keys.contains(&key) {
                let filtered = adapter.filter(&key)?;
                value = document.get(&filtered).cloned().unwrap_or(Value::Null);
                if !is_null(&value) || (filtered != key && document.has(&filtered)) {
                    document.remove(&filtered);
                }
            }
            if matches!(value, Value::Ext(Ext::Operator(_))) {
                continue;
            }

            let mut value = if array { value } else { Value::Array([value].into_iter().collect()) };
            if is_null(&value) {
                value = Value::Array(Array::new());
            }

            let selected = selections.is_empty() || selections.contains(&key) || all_selected;

            if !filters.is_empty()
                && (selected || has_relationship_selections)
                && let Value::Array(nodes) = &mut value
            {
                let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                for k in keys {
                    let mut node = nodes.get(&k).cloned().unwrap_or(Value::Null);
                    for filter in filters.iter().rev() {
                        node = self.decode_attribute(filter, node, &document, &key).await?;
                    }
                    nodes.set(k, node);
                }
            }

            let value = if array { value } else { nth(&value, 0).clone() };
            if has_relationship_selections {
                match filtered_values.iter_mut().find(|(k, _)| *k == key) {
                    Some(entry) => entry.1 = value.clone(),
                    None => filtered_values.push((key.clone(), value.clone())),
                }
            }
            if selected {
                document.set(key.as_str(), value);
            }
        }

        if has_relationship_selections && !selections.is_empty() && !all_selected {
            for attribute in all {
                let key = to_text(index(attribute, "$id"));
                if is_relationship(attribute) || key == "$permissions" {
                    continue;
                }
                if !selections.contains(&key)
                    && let Some((_, value)) = filtered_values.iter().find(|(k, v)| *k == key && !is_null(v))
                {
                    document.set(key.as_str(), value.clone());
                }
            }
        }
        Ok(document)
    }

    /// `document` with attribute values cast to their declared types (for
    /// adapters that return strings).
    ///
    /// PHP: `Database::casting()`.
    pub fn casting(&self, collection: &Document, mut document: Document) -> Result<Document> {
        if !self.inner.adapter.support_for_casting() {
            return Ok(document);
        }
        let attributes = collection_attribute_refs(collection).chain(self.internal_attribute_values());

        for attribute in attributes {
            let key = to_text(index(attribute, "$id"));
            let kind = to_text(index(attribute, "type"));
            let signed = index_or(attribute, "signed", &Value::Bool(true)).truthy();
            let array = index(attribute, "array").truthy();
            let Some(value) = document.get(&key).cloned() else { continue };
            if key == "$permissions" {
                continue;
            }
            let mut value = if array {
                match value {
                    Value::Str(s) => json_decode_assoc(s.as_bytes()),
                    other => other,
                }
            } else {
                Value::Array([value].into_iter().collect())
            };
            let typed = [Self::VAR_ID, Self::VAR_BOOLEAN, Self::VAR_INTEGER, Self::VAR_BIGINT, Self::VAR_FLOAT];
            if typed.contains(&kind.as_str())
                && let Value::Array(nodes) = &mut value
            {
                let keys: Vec<ArrayKey> = nodes.keys().map(|k| k.to_owned()).collect();
                for k in keys {
                    let node = nodes.get(&k).cloned().unwrap_or(Value::Null);
                    let node = match kind.as_str() {
                        Self::VAR_ID => Value::Str(Str::from(to_text(&node))),
                        Self::VAR_BOOLEAN => Value::Bool(node.truthy()),
                        Self::VAR_INTEGER => Value::Int(to_long(&node)),
                        Self::VAR_BIGINT => match &node {
                            Value::Str(s) if BigInt::fits_php_int(s.as_bytes(), signed) => Value::Int(to_long(&node)),
                            _ => node,
                        },
                        _ => Value::Float(node.to_double()),
                    };
                    nodes.set(k, node);
                }
            }
            let value = if array { value } else { nth(&value, 0).clone() };
            document.set(key.as_str(), value);
        }
        Ok(document)
    }

    /// Runs filter `name`'s `encode` on `value`. A filter error becomes a
    /// database error with the same message.
    ///
    /// PHP: `Database::encodeAttribute()` (protected). Throws
    /// `NotFoundException("Filter: {name} not found")` for unknown filters.
    pub(crate) async fn encode_attribute(&self, name: &str, value: Value, document: &Document) -> Result<Value> {
        let Some(filter) = self.filter_named(name) else {
            return Err(Error::not_found(format!("Filter: {name} not found")));
        };
        filter.encode(value, document, self).await.map_err(|e| {
            let code = e.code();
            Error::database(e.message()).with_code(code).with_previous(e)
        })
    }

    /// Runs filter `filter`'s `decode` on `value` (unless filters are off
    /// or this one is disabled).
    ///
    /// PHP: `Database::decodeAttribute()` (protected).
    pub(crate) async fn decode_attribute(
        &self,
        filter: &str,
        value: Value,
        document: &Document,
        attribute: &str,
    ) -> Result<Value> {
        {
            let s = self.state();
            if !s.filter {
                return Ok(value);
            }
            if s.disabled_filters.as_ref().is_some_and(|d| d.contains(filter)) {
                return Ok(value);
            }
        }
        let Some(f) = self.filter_named(filter) else {
            return Err(Error::not_found(format!("Filter \"{filter}\" not found for attribute \"{attribute}\"")));
        };
        f.decode(value, document, self).await
    }

    /// The WKT of a spatial value (`point`, `linestring`, `polygon`).
    ///
    /// PHP: `Database::encodeSpatialData()` (protected).
    pub(crate) fn encode_spatial_data(&self, value: &Value, kind: &str) -> Result<String> {
        let validator = Spatial::new(kind);
        if !validator.is_valid(value) {
            return Err(Error::structure(validator.description()));
        }
        let pair = |point: &Value| format!("{} {}", to_text(nth(point, 0)), to_text(nth(point, 1)));
        let items = |v: &Value| -> Vec<Value> {
            match v {
                Value::Array(a) => a.values().cloned().collect(),
                _ => Vec::new(),
            }
        };
        match kind {
            Self::VAR_POINT => Ok(format!("POINT({})", pair(value))),
            Self::VAR_LINESTRING => {
                Ok(format!("LINESTRING({})", items(value).iter().map(pair).collect::<Vec<_>>().join(", ")))
            }
            Self::VAR_POLYGON => {
                let rings = items(value);
                let first = nth(value, 0);
                let single = !rings.is_empty()
                    && matches!(first, Value::Array(a) if a.len() == 2)
                    && is_numeric(nth(first, 0))
                    && is_numeric(nth(first, 1));
                let rings = if single { vec![value.clone()] } else { rings };
                let rings: Vec<String> = rings
                    .iter()
                    .map(|ring| format!("({})", items(ring).iter().map(pair).collect::<Vec<_>>().join(", ")))
                    .collect();
                Ok(format!("POLYGON({})", rings.join(", ")))
            }
            _ => Err(Error::database(format!("Unknown spatial type: {kind}"))),
        }
    }

    /// Decodes a stored `object` attribute: `json_decode(.., true)`, except
    /// that empty JSON objects stay `stdClass`.
    ///
    /// PHP: `Database::decodeObject()` (private static).
    pub(crate) fn decode_object(value: &[u8]) -> Value {
        if !has_empty_object(value) {
            return json_decode_assoc(value);
        }
        let decoded = php_std::json::decode(value, Some(false), 512, php_std::json::Flags::NONE)
            .map(crate::value::from_plain)
            .unwrap_or(Value::Null);
        Self::to_associative(decoded)
    }

    /// Converts decoded `stdClass` values to arrays, recursively, keeping
    /// empty ones as `stdClass`.
    ///
    /// PHP: `Database::toAssociative()` (private static).
    pub(crate) fn to_associative(value: Value) -> Value {
        match value {
            Value::Object(o) if o.is_empty() => Value::Object(o),
            Value::Object(o) | Value::Array(o) => {
                Value::Array(o.into_iter().map(|(k, v)| (k, Self::to_associative(v))).collect())
            }
            other => other,
        }
    }

    // ----- Filter signatures -----

    /// The signatures of the filters in effect (none when filters are off,
    /// without the disabled ones), sorted by name.
    ///
    /// PHP: `Database::getActiveFilterSignatures()` (private).
    pub(crate) fn active_filter_signatures(&self) -> Vec<(String, String)> {
        if !self.state().filter {
            return Vec::new();
        }
        self.refresh_filter_signatures();
        let s = self.state();
        match &s.disabled_filters {
            Some(disabled) if !disabled.is_empty() => {
                s.filter_signatures.iter().filter(|(n, _)| !disabled.contains(n)).cloned().collect()
            }
            _ => s.filter_signatures.clone(),
        }
    }

    /// Recomputes the filter signatures when the global registry changed.
    ///
    /// PHP: `Database::refreshFilterSignatures()` (private).
    pub(crate) fn refresh_filter_signatures(&self) {
        let version = Self::filters_version();
        if self.state().filter_signatures_version == version {
            return;
        }
        let mut signatures: Vec<(String, String)> = Self::global_filters()
            .into_iter()
            .filter(|(name, _)| !self.inner.instance_filters.iter().any(|(n, _)| n == name))
            .map(|(name, f)| (name, f.signature()))
            .collect();
        signatures.extend(self.inner.instance_filters.iter().map(|(n, f)| (n.clone(), f.signature())));
        signatures.sort_by(|a, b| a.0.as_bytes().cmp(b.0.as_bytes()));
        let encoded = signatures_json(&signatures);
        let mut s = self.state();
        s.filter_signatures = signatures;
        s.filter_signatures_encoded = encoded;
        s.filter_signatures_version = version;
    }

    /// The JSON of the active filter signatures, as document cache keys
    /// carry it (`""` when filters are off).
    ///
    /// PHP: `Database::getFilterSignatureKey()` (private).
    pub(crate) fn filter_signature_key(&self) -> String {
        let (filter, disabled) = {
            let s = self.state();
            (s.filter, s.disabled_filters.as_ref().is_some_and(|d| !d.is_empty()))
        };
        if !filter {
            return String::new();
        }
        if disabled {
            return signatures_json(&self.active_filter_signatures());
        }
        self.refresh_filter_signatures();
        self.state().filter_signatures_encoded.clone()
    }
}

/// `$collection->getAttribute('attributes', [])`, as a list.
pub(crate) fn collection_attributes(collection: &Document) -> Vec<Value> {
    collection_attribute_refs(collection).cloned().collect()
}

/// [`collection_attributes`], borrowed.
pub(crate) fn collection_attribute_refs(collection: &Document) -> impl Iterator<Item = &Value> {
    match collection.get("attributes") {
        Some(Value::Array(a)) => Some(a.values()),
        _ => None,
    }
    .into_iter()
    .flatten()
}

/// The strings of a list value (`$attribute['filters'] ?? []`).
fn strings(value: &Value) -> Vec<String> {
    match value {
        Value::Array(a) => a.values().map(to_text).collect(),
        _ => Vec::new(),
    }
}

/// PHP's `empty()` of a string: `""` or `"0"`.
fn empty_string(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// `(int) $value`.
fn to_long(value: &Value) -> i64 {
    crate::value::to_long(value)
}

/// `json_decode($value, true)` (`null` on invalid JSON).
pub(crate) fn json_decode_assoc(value: &[u8]) -> Value {
    php_std::json::decode(value, Some(true), 512, php_std::json::Flags::NONE)
        .map(crate::value::from_plain)
        .unwrap_or(Value::Null)
}

/// `json_encode($value)` (`false` when it fails).
pub(crate) fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, 512) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `json_encode($signatures)` of name => signature pairs (`[]` when empty).
fn signatures_json(signatures: &[(String, String)]) -> String {
    let map: Array = signatures
        .iter()
        .map(|(n, s)| (ArrayKey::normalize(Str::from(n.as_str())), Value::Str(Str::from(s.as_str()))))
        .collect();
    php_std::json::encode(&Value::Array(map), php_std::json::Flags::NONE, 512).unwrap_or_default()
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

// ----- The default filters (PHP: the closures of `registerDefaultFilters()`) -----

/// Where PHP's `Database.php` is in the image the PHP runtime runs: the
/// default filters' signatures (`file:line` of their closures) name it, and
/// cache keys shared with PHP carry them.
const DATABASE_PHP: &str = "/usr/src/code/vendor/utopia-php/database/src/Database/Database.php";

/// The `Database.php` lines of each default filter's encode and decode
/// closures.
fn default_signature(encode: u32, decode: u32) -> String {
    format!("{DATABASE_PHP}:{encode}:{DATABASE_PHP}:{decode}")
}

/// `json`: arrays, `stdClass` and documents to JSON; JSON back to arrays,
/// with `$id`-carrying objects as documents.
struct JsonFilter;

#[async_trait]
impl Filter for JsonFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let value = match value {
            Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(&[], &[])),
            other => other,
        };
        Ok(match value {
            Value::Array(_) | Value::Object(_) => json_encode(&value),
            other => other,
        })
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let Value::Str(s) = &value else {
            return Ok(value);
        };
        let decoded = match json_decode_assoc(s.as_bytes()) {
            Value::Null => Value::Array(Array::new()),
            v => v,
        };
        let Value::Array(a) = decoded else {
            // `array_key_exists()` on a scalar throws a TypeError.
            return Err(Error::new(
                crate::error::Kind::TypeError,
                format!(
                    "array_key_exists(): Argument #2 ($array) must be of type array, {} given",
                    decoded.type_name()
                ),
            ));
        };
        if a.get_str("$id").is_some() {
            return Ok(Value::from(Document::new(a)?));
        }
        let mut out = Array::with_capacity(a.len());
        for (k, item) in a {
            let item = match item {
                Value::Array(i) if i.get_str("$id").is_some() => Value::from(Document::new(i)?),
                other => other,
            };
            out.set(k, item);
        }
        Ok(Value::Array(out))
    }

    fn signature(&self) -> String {
        default_signature(544, 558)
    }
}

/// `datetime`: dates to the storage format in the default timezone; back
/// with `DateTime::formatTz`.
struct DatetimeFilter;

#[async_trait]
impl Filter for DatetimeFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let text = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => s.as_bytes().to_vec(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => to_text(&value).into_bytes(),
            _ => return Ok(value),
        };
        Ok(match crate::datetime::set_timezone(text) {
            Ok(formatted) => Value::Str(Str::from(formatted)),
            Err(_) => value,
        })
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let text = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => to_text(&value),
            _ => return Ok(value),
        };
        Ok(crate::datetime::format_tz(Some(&text)).map(|s| Value::Str(Str::from(s))).unwrap_or(Value::Null))
    }

    fn signature(&self) -> String {
        default_signature(586, 602)
    }
}

/// The encode of the spatial filters: WKT for arrays the validator
/// accepts, the value itself otherwise.
fn spatial_encode(value: Value, database: &Database, kind: &str) -> Value {
    if !matches!(value, Value::Array(_)) {
        return value;
    }
    match database.encode_spatial_data(&value, kind) {
        Ok(wkt) => Value::Str(Str::from(wkt)),
        Err(_) => value,
    }
}

/// The stored bytes of a spatial value (`?string`).
fn spatial_bytes(value: &Value) -> Option<Vec<u8>> {
    match value {
        Value::Null => None,
        Value::Str(s) => Some(s.as_bytes().to_vec()),
        other => Some(to_text(other).into_bytes()),
    }
}

/// `point`: coordinates to WKT; back with the adapter's `decodePoint`.
struct PointFilter;

#[async_trait]
impl Filter for PointFilter {
    async fn encode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        Ok(spatial_encode(value, database, Database::VAR_POINT))
    }

    async fn decode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        match spatial_bytes(&value) {
            None => Ok(Value::Null),
            Some(b) => database.adapter().decode_point(&b),
        }
    }

    fn signature(&self) -> String {
        default_signature(615, 631)
    }
}

/// `linestring`: coordinates to WKT; back with `decodeLinestring`.
struct LinestringFilter;

#[async_trait]
impl Filter for LinestringFilter {
    async fn encode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        Ok(spatial_encode(value, database, Database::VAR_LINESTRING))
    }

    async fn decode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        match spatial_bytes(&value) {
            None => Ok(Value::Null),
            Some(b) => database.adapter().decode_linestring(&b),
        }
    }

    fn signature(&self) -> String {
        default_signature(647, 663)
    }
}

/// `polygon`: rings to WKT; back with `decodePolygon`.
struct PolygonFilter;

#[async_trait]
impl Filter for PolygonFilter {
    async fn encode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        Ok(spatial_encode(value, database, Database::VAR_POLYGON))
    }

    async fn decode(&self, value: Value, _document: &Document, database: &Database) -> Result<Value> {
        match spatial_bytes(&value) {
            None => Ok(Value::Null),
            Some(b) => database.adapter().decode_polygon(&b),
        }
    }

    fn signature(&self) -> String {
        default_signature(679, 695)
    }
}

/// `vector`: lists of numbers to JSON floats; JSON back to a list.
struct VectorFilter;

#[async_trait]
impl Filter for VectorFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let Value::Array(a) = &value else {
            return Ok(value);
        };
        if !a.is_list() || a.values().any(|v| !matches!(v, Value::Int(_) | Value::Float(_))) {
            return Ok(value);
        }
        let floats: Array = a.values().map(|v| Value::Float(v.to_double())).collect();
        Ok(json_encode(&Value::Array(floats)))
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let text = match &value {
            Value::Null => return Ok(Value::Null),
            Value::Str(s) => s.as_bytes().to_vec(),
            Value::Int(_) | Value::Float(_) | Value::Bool(_) => to_text(&value).into_bytes(),
            _ => return Ok(value),
        };
        Ok(match json_decode_assoc(&text) {
            decoded @ Value::Array(_) => decoded,
            _ => match value {
                Value::Str(_) => value,
                _ => Value::Str(Str::from(text)),
            },
        })
    }

    fn signature(&self) -> String {
        default_signature(709, 728)
    }
}

/// `object`: arrays and `stdClass` to JSON; back with `decodeObject`.
struct ObjectFilter;

#[async_trait]
impl Filter for ObjectFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(match value {
            Value::Array(_) | Value::Object(_) => json_encode(&value),
            other => other,
        })
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        let Value::Str(s) = &value else {
            return Ok(value);
        };
        Ok(match Database::decode_object(s.as_bytes()) {
            decoded @ (Value::Array(_) | Value::Object(_)) => decoded,
            _ => value,
        })
    }

    fn signature(&self) -> String {
        default_signature(746, 757)
    }
}

/// `is_null($value)`.
fn is_null(value: &Value) -> bool {
    matches!(value, Value::Null)
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
        let decoded = Database::decode_object(br#"{"a":{},"b":{"c":1},"d":[{}]}"#);
        let Value::Array(a) = decoded else { panic!("an array") };
        assert!(matches!(a.get_str("a"), Some(Value::Object(o)) if o.is_empty()));
        assert!(matches!(a.get_str("b"), Some(Value::Array(b)) if b.get_str("c") == Some(&Value::Int(1))));
        assert!(matches!(a.get_str("d"), Some(Value::Array(d)) if matches!(d.values().next(), Some(Value::Object(_)))));
        // Without an empty object it is a plain associative decode.
        assert!(matches!(Database::decode_object(br#"{"x":{"y":2}}"#), Value::Array(_)));
    }

    #[test]
    fn default_signatures_name_the_closures() {
        assert_eq!(JsonFilter.signature(), format!("{DATABASE_PHP}:544:{DATABASE_PHP}:558"));
        assert_eq!(signatures_json(&[("a/b".to_owned(), "x".to_owned())]), r#"{"a\/b":"x"}"#);
        assert_eq!(signatures_json(&[]), "[]");
    }

    /// The default filters' signatures against the PHP source they name:
    /// `vendor/utopia-php/database/src/Database/Database.php` in the
    /// checkout (present where `composer install` ran), or the file
    /// `UTOPIA_DATABASE_PHP` names (`docker cp` it out of the PHP image).
    /// Each `self::addFilter(` of `registerDefaultFilters()`, in order,
    /// gives the start lines of its two closures. Without the file this
    /// test has nothing to read; the compat cases that record
    /// `getCacheKeys` from PHP still catch a moved closure.
    #[test]
    fn default_signatures_match_the_php_source() {
        let path = std::env::var("UTOPIA_DATABASE_PHP").unwrap_or_else(|_| {
            concat!(env!("CARGO_MANIFEST_DIR"), "/../../../vendor/utopia-php/database/src/Database/Database.php")
                .to_owned()
        });
        let Ok(source) = std::fs::read_to_string(&path) else {
            return;
        };
        let lines: Vec<&str> = source.lines().collect();
        let start = lines
            .iter()
            .position(|l| l.contains("function registerDefaultFilters("))
            .expect("registerDefaultFilters() in Database.php");
        let mut closures: Vec<(u32, u32)> = Vec::new();
        let mut current: Vec<u32> = Vec::new();
        let mut depth = 0i32;
        for (i, line) in lines.iter().enumerate().skip(start) {
            let trimmed = line.trim_start();
            if trimmed.starts_with("self::addFilter(") {
                current.clear();
            } else if trimmed.starts_with("static function (") || trimmed.starts_with("function (") {
                current.push(i as u32 + 1);
                if current.len() == 2 {
                    closures.push((current[0], current[1]));
                }
            }
            depth += line.matches('{').count() as i32 - line.matches('}').count() as i32;
            if i > start && depth <= 0 && trimmed == "}" {
                break;
            }
        }
        let defaults: [&dyn Filter; 7] = [
            &JsonFilter,
            &DatetimeFilter,
            &PointFilter,
            &LinestringFilter,
            &PolygonFilter,
            &VectorFilter,
            &ObjectFilter,
        ];
        let ours: Vec<String> = defaults.iter().map(|f| f.signature()).collect();
        let php: Vec<String> = closures.iter().map(|&(e, d)| default_signature(e, d)).collect();
        assert_eq!(ours, php, "update the default filters' default_signature() lines to {path}'s closures");
    }
}
