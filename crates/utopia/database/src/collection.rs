//! [`Collection`]: a collection's definition, the metadata document of its
//! attributes, indexes, permissions and document security.
//!
//! PHP: `Utopia\Database\Collection` (a `Document`) and
//! `Collection\Update`.

use std::ops::Deref;
use std::sync::OnceLock;

use crate::attribute::Attribute;
use crate::document::{self, Document, SetType};
use crate::error::{Error, Result};
use crate::index::Index;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// The key of the collection's name (`Collection::NAME`).
pub const NAME: &str = "name";
/// The key of the attribute definitions (`Collection::ATTRIBUTES`).
pub const ATTRIBUTES: &str = "attributes";
/// The key of the index definitions (`Collection::INDEXES`).
pub const INDEXES: &str = "indexes";
/// The key of the document security flag (`Collection::DOCUMENT_SECURITY`).
pub const DOCUMENT_SECURITY: &str = "documentSecurity";

/// The PHP class of a collection definition.
pub const CLASS: &str = "Utopia\\Database\\Collection";

const CORE_KEYS: [&str; 3] = [ATTRIBUTES, INDEXES, DOCUMENT_SECURITY];

/// The sparse change of a collection: `None` keeps a field.
///
/// PHP: `Utopia\Database\Collection\Update`.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Update {
    /// New permissions.
    pub permissions: Option<Vec<String>>,
    /// A new document security flag.
    pub document_security: Option<bool>,
}

/// A collection definition: its metadata document, with the attributes and
/// indexes read into [`Attribute`]s and [`Index`]es on first use and kept
/// until the definition changes.
///
/// It reads as a [`Document`] (it derefs to one, as PHP's `Collection`
/// extends `Document`); writes go through [`Collection::set`] and
/// [`Collection::document_mut`], which forget the models they change.
///
/// ```
/// use utopia_database::{Attribute, Collection};
///
/// let movies = Collection::create("movies", "", vec![Attribute::integer("year")], vec![], None, true, Default::default()).unwrap();
/// assert_eq!(movies.name(), "movies");
/// assert_eq!(movies.attributes().unwrap()[0].key, "year");
/// assert!(movies.document_security());
/// assert_eq!(movies.declared_permissions().unwrap(), None);
/// ```
#[derive(Debug, Clone, Default)]
pub struct Collection {
    document: Document,
    attributes: OnceLock<Result<Vec<Attribute>>>,
    indexes: OnceLock<Result<Vec<Index>>>,
    /// The document cache epoch the definition was read with (PHP keeps it
    /// in a `WeakMap` keyed by the definition object); never compared.
    cache_epoch: Option<String>,
}

impl PartialEq for Collection {
    fn eq(&self, other: &Self) -> bool {
        self.document == other.document
    }
}

impl Deref for Collection {
    type Target = Document;

    fn deref(&self) -> &Document {
        &self.document
    }
}

impl From<Collection> for Document {
    fn from(collection: Collection) -> Self {
        collection.document
    }
}

impl Collection {
    fn of(document: Document) -> Self {
        Collection {
            document: document.with_class(CLASS),
            attributes: OnceLock::new(),
            indexes: OnceLock::new(),
            cache_epoch: None,
        }
    }

    /// A new collection definition (`Collection::create()`): `name`
    /// defaults to `id`, `permissions` are stored only when given (`None`
    /// grants the default create permission on creation), `metadata` adds
    /// keys the metadata collection stores.
    ///
    /// Fails ([`crate::Kind::Structure`]) when `metadata` sets `attributes`,
    /// `indexes` or `documentSecurity` (`Collection metadata must not set
    /// <keys>; pass them as arguments`), and as [`Attribute::to_document`]
    /// does.
    pub fn create(
        id: impl Into<String>,
        name: impl Into<String>,
        attributes: Vec<Attribute>,
        indexes: Vec<Index>,
        permissions: Option<Vec<String>>,
        document_security: bool,
        metadata: Array,
    ) -> Result<Self> {
        let reserved: Vec<&str> = CORE_KEYS.iter().copied().filter(|k| metadata.get_str(k).is_some()).collect();
        if !reserved.is_empty() {
            return Err(Error::structure(format!(
                "Collection metadata must not set {}; pass them as arguments",
                reserved.join(", ")
            )));
        }
        let id = id.into();
        let name = name.into();
        let name = if name.is_empty() { id.clone() } else { name };
        let mut data = Array::with_capacity(6 + metadata.len());
        let key = |k: &'static str| ArrayKey::Str(Str::from_static(k));
        data.set(key(document::ID), Value::Str(Str::from(id)));
        data.set(key(NAME), Value::Str(Str::from(name)));
        let mut attribute_documents = Vec::with_capacity(attributes.len());
        for attribute in &attributes {
            attribute_documents.push(Value::from(attribute.to_document()?));
        }
        data.set(key(ATTRIBUTES), Value::Array(Array::from_list(attribute_documents)));
        data.set(
            key(INDEXES),
            Value::Array(Array::from_list(indexes.iter().map(|i| Value::from(i.to_document())).collect())),
        );
        data.set(key(DOCUMENT_SECURITY), Value::Bool(document_security));
        if let Some(permissions) = permissions {
            data.set(
                key(document::PERMISSIONS),
                Value::Array(Array::from_list(permissions.into_iter().map(|p| Value::Str(Str::from(p))).collect())),
            );
        }
        // `array_merge()`: string keys replace, integer keys are appended.
        for (k, v) in metadata {
            match k {
                ArrayKey::Int(_) => {
                    let _ = data.push(v);
                }
                k => {
                    data.set(k, v);
                }
            }
        }
        let collection = Collection::of(Document::new(data)?);
        // The models are the ones given.
        let _ = collection.attributes.set(Ok(attributes));
        let _ = collection.indexes.set(Ok(indexes));
        Ok(collection)
    }

    /// The collection a stored definition array describes
    /// (`Collection::fromArray()`): `$id` (else `''`), `name` (else the
    /// id), `documentSecurity` (else `true`), the attributes and indexes as
    /// documents, `$permissions` dropped when not an array.
    ///
    /// Fails ([`crate::Kind::Structure`], [`crate::Kind::Index`]) for an
    /// attribute or index that is not a document, and as [`Document::new`]
    /// does.
    pub fn from_array(data: Array) -> Result<Self> {
        let mut data = data;
        let key = |k: &'static str| ArrayKey::Str(Str::from_static(k));
        let id = match data.get_str(document::ID) {
            Some(Value::Str(s)) => s.clone(),
            _ => Str::EMPTY,
        };
        data.set(key(document::ID), Value::Str(id.clone()));
        let name = match data.get_str(NAME) {
            Some(Value::Str(s)) if !s.is_empty() => s.clone(),
            _ => id,
        };
        data.set(key(NAME), Value::Str(name));
        if data.get_str(document::PERMISSIONS).is_some_and(|p| !matches!(p, Value::Array(_))) {
            data.remove(&key(document::PERMISSIONS));
        }
        let security = data.get_str(DOCUMENT_SECURITY).filter(|v| !matches!(v, Value::Null)).is_none_or(Value::truthy);
        data.set(key(DOCUMENT_SECURITY), Value::Bool(security));
        let attributes = definitions(data.get_str(ATTRIBUTES), || {
            Error::structure("Collection attributes must be attribute documents")
        })?;
        data.set(key(ATTRIBUTES), attributes);
        let indexes =
            definitions(data.get_str(INDEXES), || Error::index("Collection indexes must be index documents"))?;
        data.set(key(INDEXES), indexes);
        Ok(Collection::of(Document::new(data)?))
    }

    /// The collection a stored definition document describes
    /// (`Collection::fromDocument()`).
    ///
    /// Fails as [`Collection::from_array`] does.
    pub fn from_document(document: &Document) -> Result<Self> {
        Collection::from_array(document.array_copy())
    }

    /// The definition as a plain document (`toDocument()`).
    pub fn to_document(&self) -> Document {
        Document::from_array_unchecked(self.document.as_array().clone())
    }

    /// The definition document.
    pub fn document(&self) -> &Document {
        &self.document
    }

    /// The definition document, mutably; the attribute and index models
    /// are read again on their next use.
    pub fn document_mut(&mut self) -> &mut Document {
        self.attributes = OnceLock::new();
        self.indexes = OnceLock::new();
        &mut self.document
    }

    /// Sets an attribute of the definition (`setAttribute()`), forgetting
    /// the models it changes.
    ///
    /// Fails as [`Document::set`] does.
    pub fn set(&mut self, key: &str, value: Value) -> Result<&mut Self> {
        self.set_with(key, value, SetType::Assign)
    }

    /// Sets, appends or prepends an attribute of the definition
    /// (`setAttribute($key, $value, $type)`), forgetting the models it
    /// changes.
    ///
    /// Fails as [`Document::set_with`] does.
    pub fn set_with(&mut self, key: &str, value: Value, how: SetType) -> Result<&mut Self> {
        self.forget(key);
        self.document.set_with(key, value, how)?;
        Ok(self)
    }

    /// Sets every attribute of `attrs` (`setAttributes()`), forgetting the
    /// models they change.
    ///
    /// Fails as [`Document::set`] does.
    pub fn set_many(&mut self, attrs: Array) -> Result<&mut Self> {
        for k in attrs.keys() {
            if let KeyRef::Str(s) = k {
                self.forget(&String::from_utf8_lossy(s));
            }
        }
        self.document.set_many(attrs)?;
        Ok(self)
    }

    /// Replaces every attribute of the definition (`exchangeArray()`),
    /// returning the previous ones. The attribute and index models are kept
    /// when their stored lists did not change.
    pub fn exchange_array(&mut self, data: Array) -> Array {
        let previous = std::mem::replace(self.document.as_array_mut(), data);
        if previous.get_str(ATTRIBUTES) != self.document.as_array().get_str(ATTRIBUTES) {
            self.forget(ATTRIBUTES);
        }
        if previous.get_str(INDEXES) != self.document.as_array().get_str(INDEXES) {
            self.forget(INDEXES);
        }
        previous
    }

    /// Removes an attribute of the definition (`removeAttribute()`).
    pub fn remove(&mut self, key: &str) -> Option<Value> {
        self.forget(key);
        self.document.remove(key)
    }

    fn forget(&mut self, key: &str) {
        if key == ATTRIBUTES {
            self.attributes = OnceLock::new();
        } else if key == INDEXES {
            self.indexes = OnceLock::new();
        }
    }

    /// The attributes, as models (`attributes()`), read once.
    ///
    /// Fails ([`crate::Kind::Structure`]) when the stored attributes are
    /// not a list of attribute documents (`Collection attributes must be a
    /// list of attribute documents`, `Collection attributes must be
    /// attribute documents`), and as [`Attribute::from_document`] does.
    pub fn attributes(&self) -> Result<&[Attribute]> {
        self.attributes
            .get_or_init(|| {
                let Some(stored) = stored_list(self.document.get(ATTRIBUTES)) else {
                    return Err(Error::structure("Collection attributes must be a list of attribute documents"));
                };
                let mut models = Vec::with_capacity(stored.len());
                for attribute in stored.values() {
                    models.push(match attribute {
                        Value::Ext(Ext::Document(d)) => Attribute::from_document(d)?,
                        Value::Array(a) => Attribute::from_document(&Document::new(string_keyed(a))?)?,
                        _ => return Err(Error::structure("Collection attributes must be attribute documents")),
                    });
                }
                Ok(models)
            })
            .as_deref()
            .map_err(Clone::clone)
    }

    /// The declared attributes followed by `internal`
    /// (`attributesWith()`).
    ///
    /// Fails as [`Collection::attributes`] does.
    pub fn attributes_with(&self, internal: &[Attribute]) -> Result<Vec<Attribute>> {
        let mut all = self.attributes()?.to_vec();
        all.extend_from_slice(internal);
        Ok(all)
    }

    /// The indexes, as models (`indexes()`), read once.
    ///
    /// Fails ([`crate::Kind::Index`]) when the stored indexes are not a list
    /// of index documents, and as [`Index::from_document`] does.
    pub fn indexes(&self) -> Result<&[Index]> {
        self.indexes
            .get_or_init(|| {
                let Some(stored) = stored_list(self.document.get(INDEXES)) else {
                    return Err(Error::index("Collection indexes must be a list of index documents"));
                };
                let mut models = Vec::with_capacity(stored.len());
                for index in stored.values() {
                    models.push(match index {
                        Value::Ext(Ext::Document(d)) => Index::from_document(d)?,
                        Value::Array(a) => Index::from_array(&string_keyed(a))?,
                        _ => return Err(Error::index("Collection indexes must be index documents")),
                    });
                }
                Ok(models)
            })
            .as_deref()
            .map_err(Clone::clone)
    }

    /// A hash of everything a query against the collection is validated and
    /// cached by: its attributes, indexes, permissions and document
    /// security (`fingerprint()`, internal): PHP's `hash('xxh128',
    /// serialize([...]))`, so caches shared with PHP key alike.
    ///
    /// Fails as [`Collection::attributes`], [`Collection::indexes`] and
    /// [`Document::permissions`] do.
    pub fn fingerprint(&self) -> Result<String> {
        let payload = crate::serialize::definition(
            self.attributes()?,
            self.indexes()?,
            &self.document.permissions()?,
            self.document_security(),
        );
        Ok(format!("{:032x}", xxhash_rust::xxh3::xxh3_128(&payload)))
    }

    /// The epoch the collection's documents may be cached under, as read
    /// with this definition (the database's `getCollectionCacheEpoch()`).
    pub fn cache_epoch(&self) -> Option<&str> {
        self.cache_epoch.as_deref()
    }

    /// Records the epoch the definition was read with
    /// (`attachCollectionCacheEpoch()`).
    pub fn set_cache_epoch(&mut self, epoch: Option<String>) {
        self.cache_epoch = epoch;
    }

    /// The name, else the id (`name()`).
    pub fn name(&self) -> &str {
        match self.document.get(NAME) {
            Some(Value::Str(s)) if !s.is_empty() => s.as_str().unwrap_or(""),
            _ => self.document.id(),
        }
    }

    /// Whether documents carry permissions of their own
    /// (`documentSecurity()`; `true` when unset).
    pub fn document_security(&self) -> bool {
        self.document.get(DOCUMENT_SECURITY).is_none_or(Value::truthy)
    }

    /// The permissions the definition declares, `None` when it declares
    /// none (`declaredPermissions()`).
    ///
    /// Fails as [`Document::permissions`] does.
    pub fn declared_permissions(&self) -> Result<Option<Vec<String>>> {
        if self.document.has(document::PERMISSIONS) { self.document.permissions().map(Some) } else { Ok(None) }
    }
}

/// `Collection::storedList()`: the list as written, or decoded from the
/// JSON a raw metadata row holds; `None` when it is neither.
fn stored_list(source: Option<&Value>) -> Option<Array> {
    match source {
        None => Some(Array::new()),
        Some(Value::Array(a)) => Some(a.clone()),
        Some(Value::Str(s)) => {
            match php_std::json::decode_ext::<Ext>(
                s.as_bytes(),
                Some(true),
                php_std::json::DEFAULT_DEPTH,
                php_std::json::Flags::NONE,
            ) {
                Ok(Value::Array(a)) => Some(a),
                _ => None,
            }
        }
        Some(_) => None,
    }
}

/// `Collection::attributeDocuments()` / `indexDocuments()`: a JSON string
/// kept, anything but an array as an empty list, each element a document.
fn definitions(value: Option<&Value>, invalid: impl Fn() -> Error) -> Result<Value> {
    match value {
        Some(Value::Str(s)) => Ok(Value::Str(s.clone())),
        Some(Value::Array(items)) => {
            let mut documents = Vec::with_capacity(items.len());
            for item in items.values() {
                documents.push(match item {
                    Value::Ext(Ext::Document(_)) => item.clone(),
                    Value::Array(a) => Value::from(Document::new(string_keyed(a))?),
                    _ => return Err(invalid()),
                });
            }
            Ok(Value::Array(Array::from_list(documents)))
        }
        _ => Ok(Value::Array(Array::new())),
    }
}

fn string_keyed(a: &Array) -> Array {
    a.iter().filter(|(k, _)| matches!(k, KeyRef::Str(_))).map(|(k, v)| (k.to_owned(), v.clone())).collect()
}
