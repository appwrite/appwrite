//! [`Document`]: an ordered set of attributes, with the system attributes
//! (`$id`, `$sequence`, `$collection`, `$tenant`, `$createdAt`, `$updatedAt`,
//! `$permissions`) every stored document has.

use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// The PHP class of a plain document.
pub const CLASS: &str = "Utopia\\Database\\Document";

/// `Document::ID`.
pub const ID: &str = "$id";
/// `Document::SEQUENCE`.
pub const SEQUENCE: &str = "$sequence";
/// `Document::COLLECTION`.
pub const COLLECTION: &str = "$collection";
/// `Document::CREATED_AT`.
pub const CREATED_AT: &str = "$createdAt";
/// `Document::UPDATED_AT`.
pub const UPDATED_AT: &str = "$updatedAt";
/// `Document::PERMISSIONS`.
pub const PERMISSIONS: &str = "$permissions";
/// `Document::TENANT`.
pub const TENANT: &str = "$tenant";
/// `Document::DISTANCE`.
pub const DISTANCE: &str = "$distance";
/// `Document::DELETED_AT`.
pub const DELETED_AT: &str = "$deletedAt";

/// The internal attributes [`Document::attributes`] leaves out: the keys of
/// `Database::internalAttributesFor(true)`.
pub const INTERNAL: [&str; 7] = [ID, SEQUENCE, COLLECTION, TENANT, CREATED_AT, UPDATED_AT, PERMISSIONS];

/// How [`Document::set_with`] stores a value.
///
/// PHP: `Utopia\Database\SetType`.
#[derive(Clone, Copy, PartialEq, Eq, Hash, Debug)]
pub enum SetType {
    /// Replace the attribute (`assign`).
    Assign,
    /// Put the value in front of the attribute's array (`prepend`).
    Prepend,
    /// Put the value behind the attribute's array (`append`).
    Append,
}

impl SetType {
    /// The case's value (`assign`, `prepend`, `append`).
    pub fn as_str(self) -> &'static str {
        match self {
            SetType::Assign => "assign",
            SetType::Prepend => "prepend",
            SetType::Append => "append",
        }
    }

    /// The case of a value (`tryFrom()`).
    pub fn from_name(name: &str) -> Option<SetType> {
        match name {
            "assign" => Some(SetType::Assign),
            "prepend" => Some(SetType::Prepend),
            "append" => Some(SetType::Append),
            _ => None,
        }
    }
}

/// The classes documents were given besides [`CLASS`] and `Collection` (the
/// custom document types): what [`is_document_class`] knows.
static CLASSES: std::sync::LazyLock<std::sync::RwLock<std::collections::HashSet<String>>> =
    std::sync::LazyLock::new(Default::default);

/// Records `class` as a document class.
pub(crate) fn register_class(class: &str) {
    if is_document_class(class) {
        return;
    }
    CLASSES.write().unwrap_or_else(|e| e.into_inner()).insert(class.to_owned());
}

/// Whether `class` is a document class (`instanceof Document`):
/// `Utopia\Database\Document`, `Utopia\Database\Collection`, or a class a
/// document was given ([`Document::with_class`],
/// `Database::set_document_type`). Any other class, such as an
/// `ArrayObject` like `Utopia\Audit\Log`, is not.
pub fn is_document_class(class: &str) -> bool {
    class == CLASS
        || class == crate::collection::CLASS
        || CLASSES.read().unwrap_or_else(|e| e.into_inner()).contains(class)
}

/// A document: attributes in insertion order, each a [`Value`]. Nested
/// documents (relationships) are [`Value::Ext`] with [`Ext::Document`].
///
/// ```
/// use utopia_database::{Document, value::string};
///
/// let mut doc = Document::from_pairs([("$id", string("movie1")), ("name", string("Alien"))]).unwrap();
/// assert_eq!(doc.id(), "movie1");
/// doc.set("year", 1979.into()).unwrap();
/// assert_eq!(doc.get("year"), Some(&1979.into()));
/// assert!(doc.remove("name").is_some());
/// ```
///
/// PHP: `Utopia\Database\Document` (an `ArrayObject`). Attribute access
/// follows `isset()`: an attribute holding `null` reads as absent through
/// [`Document::get`] and [`Document::is_set`]. A document of a custom class
/// registered with `setDocumentType` keeps its class name ([`Document::class`]).
/// `clone $document` (`__clone`, a deep copy of nested documents) is
/// [`Clone`].
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Document {
    attrs: Array,
    class: Option<Str>,
}

/// `isset($array[$key])`.
fn isset(a: &Array, key: &str) -> bool {
    a.get_str(key).is_some_and(|v| !matches!(v, Value::Null))
}

/// Whether an array is a document's (`isset($value['$id']) || isset($value['$collection'])`).
fn wraps(a: &Array) -> bool {
    isset(a, ID) || isset(a, COLLECTION)
}

/// The name PHP gives a value's type in a `TypeError` (`zend_zval_value_name`).
#[allow(dead_code)] // for the validators being ported
pub(crate) fn value_name(v: &Value) -> &str {
    match v {
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        other => other.debug_type(),
    }
}

/// `array_values(array_unique($strings))`: the first of equal strings kept.
fn unique(strings: Vec<Str>) -> Vec<Str> {
    let mut out: Vec<Str> = Vec::with_capacity(strings.len());
    for s in strings {
        if !out.iter().any(|o| o.as_bytes() == s.as_bytes()) {
            out.push(s);
        }
    }
    out
}

/// `Document::normalizePermissions()`: the permissions as a list of unique
/// strings.
///
/// Fails with [`crate::Kind::Structure`] when `permissions` is not an array
/// (`$permissions must be of type array`) or holds a value that is not a
/// string (`Every permission must be of type string`).
pub(crate) fn normalize_permissions(permissions: &Value) -> Result<Vec<Str>> {
    let Value::Array(list) = permissions else {
        return Err(Error::structure("$permissions must be of type array"));
    };
    let mut strings = Vec::with_capacity(list.len());
    for permission in list.values() {
        let Value::Str(s) = permission else {
            return Err(Error::structure("Every permission must be of type string"));
        };
        strings.push(s.clone());
    }
    Ok(unique(strings))
}

fn str_list(strings: Vec<Str>) -> Value {
    Value::Array(Array::from_list(strings.into_iter().map(Value::Str).collect()))
}

/// The string-keyed entries of an array (`foreach ($value as $name => $item) if (is_string($name))`).
fn string_keyed(a: &Array) -> Array {
    a.iter().filter(|(k, _)| matches!(k, KeyRef::Str(_))).map(|(k, v)| (k.to_owned(), v.clone())).collect()
}

/// `trim()`'s default characters.
fn trim(bytes: &[u8]) -> &[u8] {
    const WS: &[u8] = b" \t\n\r\0\x0B";
    let start = bytes.iter().position(|b| !WS.contains(b)).unwrap_or(bytes.len());
    let end = bytes.iter().rposition(|b| !WS.contains(b)).map_or(start, |e| e + 1);
    &bytes[start..end.max(start)]
}

impl Document {
    /// A document of `input`, as `new Document($input)`: `$permissions` made
    /// a list of unique strings, nested arrays with a `$id` or `$collection`
    /// made documents, and so are such arrays inside array values.
    ///
    /// Fails ([`crate::Kind::Structure`]) when `$id` is not a string
    /// (`$id must be of type string`), `$permissions` is not an array
    /// (`$permissions must be of type array`) or holds a value that is not a
    /// string (`Every permission must be of type string`).
    ///
    /// PHP: `Document::__construct`.
    pub fn new(input: Array) -> Result<Self> {
        Self::build(input, false)
    }

    /// [`Document::new`], or `fromStorage()` with `storage`.
    fn build(input: Array, storage: bool) -> Result<Self> {
        let mut attrs = input;
        if let Some(id) = attrs.get_str(ID)
            && !matches!(id, Value::Str(_))
        {
            return Err(Error::structure("$id must be of type string"));
        }
        let key = ArrayKey::Str(Str::from_static(PERMISSIONS));
        if let Some(p) = attrs.get(&key) {
            let normalized = if storage {
                let Value::Array(list) = p else {
                    return Err(Error::structure("$permissions must be of type array"));
                };
                unique(
                    list.values().filter_map(|v| if let Value::Str(s) = v { Some(s.clone()) } else { None }).collect(),
                )
            } else {
                normalize_permissions(p)?
            };
            attrs.set(key, str_list(normalized));
        }
        let keys: Vec<ArrayKey> =
            attrs.iter().filter(|(_, v)| matches!(v, Value::Array(_))).map(|(k, _)| k.to_owned()).collect();
        for key in keys {
            let Some(Value::Array(value)) = attrs.get_mut(&key) else { continue };
            if wraps(value) {
                let inner = std::mem::take(value);
                attrs.set(key, Value::from(Self::build(inner, storage)?));
                continue;
            }
            let child_keys: Vec<ArrayKey> = value
                .iter()
                .filter(|(_, c)| matches!(c, Value::Array(c) if wraps(c)))
                .map(|(k, _)| k.to_owned())
                .collect();
            for ck in child_keys {
                if let Some(Value::Array(child)) = value.get_mut(&ck) {
                    let inner = std::mem::take(child);
                    value.set(ck, Value::from(Self::build(inner, storage)?));
                }
            }
        }
        Ok(Self { attrs, class: None })
    }

    /// An empty document (`new Document()`).
    pub fn empty() -> Self {
        Self::default()
    }

    /// A document of `(key, value)` pairs, as [`Document::new`].
    pub fn from_pairs<K: Into<Str>>(pairs: impl IntoIterator<Item = (K, Value)>) -> Result<Self> {
        Self::new(pairs.into_iter().map(|(k, v)| (ArrayKey::normalize(k.into()), v)).collect())
    }

    /// A document read from a SQL row: integer keys dropped, a `null` `$id`
    /// read as `""`, `$permissions` kept to their unique strings; nothing
    /// else converted.
    ///
    /// Fails ([`crate::Kind::Structure`]) for an `$id` that is neither a
    /// string nor `null`, and for `$permissions` that are not an array.
    ///
    /// PHP: `Document::fromRow()` (internal).
    pub fn from_row(row: Array) -> Result<Self> {
        let mut attrs = row;
        attrs.retain(|k, _| matches!(k, KeyRef::Str(_)));
        let id = ArrayKey::Str(Str::from_static(ID));
        match attrs.get(&id) {
            Some(Value::Null) => {
                attrs.set(id, Value::Str(Str::EMPTY));
            }
            Some(Value::Str(_)) | None => {}
            Some(_) => return Err(Error::structure("$id must be of type string")),
        }
        let key = ArrayKey::Str(Str::from_static(PERMISSIONS));
        if let Some(p) = attrs.get(&key) {
            let Value::Array(list) = p else {
                return Err(Error::structure("$permissions must be of type array"));
            };
            let strings = unique(
                list.values().filter_map(|v| if let Value::Str(s) = v { Some(s.clone()) } else { None }).collect(),
            );
            attrs.set(key, str_list(strings));
        }
        Ok(Self { attrs, class: None })
    }

    /// A document read from storage that keeps documents (MongoDB, Memory,
    /// Redis, the caches): like [`Document::new`], except that permissions
    /// that are not strings are dropped, at every level, instead of refused.
    ///
    /// Fails ([`crate::Kind::Structure`]) for an `$id` that is not a string
    /// and `$permissions` that are not an array.
    ///
    /// PHP: `Document::fromStorage()` (internal).
    pub fn from_storage(data: Array) -> Result<Self> {
        Self::build(data, true)
    }

    /// A document holding `attrs` exactly, without the nested-document
    /// wrapping or checks of [`Document::new`] (adapters building documents
    /// from rows they already validated; PHP's `exchangeArray()`).
    pub fn from_array_unchecked(attrs: Array) -> Self {
        Self { attrs, class: None }
    }

    /// The PHP class of the document (`get_class()`): `Utopia\Database\Document`
    /// unless a custom document type was set.
    pub fn class(&self) -> &str {
        self.class.as_ref().and_then(Str::as_str).unwrap_or(CLASS)
    }

    /// The same document as an instance of the custom class `class`
    /// (`Database::setDocumentType`).
    pub fn with_class(mut self, class: impl Into<Str>) -> Self {
        let class = class.into();
        if class.as_bytes() == CLASS.as_bytes() {
            self.class = None;
        } else {
            register_class(&String::from_utf8_lossy(class.as_bytes()));
            self.class = Some(class);
        }
        self
    }

    /// `$id` (`getId()`); empty when unset.
    ///
    /// PHP: `Document::getId`. An `$id` set to another type after
    /// construction reads as empty here.
    pub fn id(&self) -> &str {
        match self.get(ID) {
            Some(Value::Str(s)) => s.as_str().unwrap_or(""),
            _ => "",
        }
    }

    /// `$sequence` (`getSequence()`), as stored.
    pub fn sequence(&self) -> Option<&Value> {
        self.get(SEQUENCE)
    }

    /// `$collection` (`getCollection()`); empty when unset.
    pub fn collection(&self) -> &str {
        match self.get(COLLECTION) {
            Some(Value::Str(s)) => s.as_str().unwrap_or(""),
            _ => "",
        }
    }

    /// `$permissions` as a list of unique strings (`getPermissions()`).
    ///
    /// Fails ([`crate::Kind::Structure`]) when the stored `$permissions` are
    /// not an array of strings, which only a write that bypassed
    /// [`Document::set`]'s checks (such as [`Document::as_array_mut`]) leaves.
    pub fn permissions(&self) -> Result<Vec<String>> {
        let empty = Value::Array(Array::new());
        Ok(normalize_permissions(self.get_or(PERMISSIONS, &empty))?
            .into_iter()
            .map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned())
            .collect())
    }

    /// The roles the permissions of type `kind` grant
    /// (`getPermissionsByType()`): `read("any")` gives `any` for `read`.
    /// `kind` is a [`PermissionType`] or, for a type of an application's
    /// own such as `execute`, its name.
    ///
    /// Each permission is split at its first `(`: the type is the text
    /// before it, trimmed; the role is the text after it without `)`, `"`
    /// and spaces. Roles are unique per type, in first-seen order.
    ///
    /// Fails as [`Document::permissions`] does.
    pub fn permissions_by_type(&self, kind: impl AsRef<str>) -> Result<Vec<String>> {
        let kind = kind.as_ref().as_bytes();
        let empty = Value::Array(Array::new());
        let mut roles: Vec<String> = Vec::new();
        for permission in normalize_permissions(self.get_or(PERMISSIONS, &empty))? {
            let bytes = permission.as_bytes();
            let Some(open) = bytes.iter().position(|&b| b == b'(') else { continue };
            if trim(&bytes[..open]) != kind {
                continue;
            }
            let role: Vec<u8> =
                bytes[open + 1..].iter().copied().filter(|b| !matches!(b, b')' | b'"' | b' ')).collect();
            let role = String::from_utf8_lossy(&role).into_owned();
            if !roles.contains(&role) {
                roles.push(role);
            }
        }
        Ok(roles)
    }

    /// `$createdAt` (`getCreatedAt()`).
    pub fn created_at(&self) -> Option<&str> {
        self.get(CREATED_AT).and_then(crate::value::as_str)
    }

    /// `$updatedAt` (`getUpdatedAt()`).
    pub fn updated_at(&self) -> Option<&str> {
        self.get(UPDATED_AT).and_then(crate::value::as_str)
    }

    /// `$tenant` (`getTenant()`): a string of digits as an integer (cast
    /// like `(int)`), an integer or another string as it is, anything else
    /// as `None`.
    pub fn tenant(&self) -> Option<Value> {
        match self.get(TENANT)? {
            Value::Str(s) if !s.is_empty() && s.as_bytes().iter().all(u8::is_ascii_digit) => {
                Some(Value::Int(php_std::format::str_to_int(s.as_bytes())))
            }
            v @ (Value::Int(_) | Value::Str(_)) => Some(v.clone()),
            _ => None,
        }
    }

    /// Every attribute except the internal ones (`getAttributes()`).
    pub fn attributes(&self) -> Array {
        self.attrs
            .iter()
            .filter(|(k, _)| !matches!(k, KeyRef::Str(s) if INTERNAL.iter().any(|i| i.as_bytes() == *s)))
            .map(|(k, v)| (k.to_owned(), v.clone()))
            .collect()
    }

    /// The attribute `key`, unless unset or `null` (`getAttribute()`).
    pub fn get(&self, key: &str) -> Option<&Value> {
        self.attrs.get_str(key).filter(|v| !matches!(v, Value::Null))
    }

    /// The attribute `key`, or `default` (`getAttribute($key, $default)`).
    pub fn get_or<'a>(&'a self, key: &str, default: &'a Value) -> &'a Value {
        self.get(key).unwrap_or(default)
    }

    /// The attribute `key` mutably, even when it holds `null`.
    pub fn get_mut(&mut self, key: &str) -> Option<&mut Value> {
        self.attrs.get_mut(&ArrayKey::normalize(Str::from(key)))
    }

    /// The raw attribute `key`, `null` included (`$document[$key]`).
    pub fn raw(&self, key: &str) -> Option<&Value> {
        self.attrs.get_str(key)
    }

    /// Whether `key` is present at all, `null` included (`offsetExists()`).
    pub fn has(&self, key: &str) -> bool {
        self.attrs.get_str(key).is_some()
    }

    /// The attribute `key` when it is present and an array, else an empty
    /// array (`getArray()`).
    pub fn array(&self, key: &str) -> Array {
        match self.attrs.get_str(key) {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        }
    }

    /// The documents of the array attribute `key` (`getDocuments()`): each
    /// document element as it is, each array element as a document of its
    /// string-keyed entries, other elements skipped.
    ///
    /// Fails as [`Document::new`] does for an array element that is not a
    /// valid document.
    pub fn documents(&self, key: &str) -> Result<Vec<Document>> {
        let Some(Value::Array(items)) = self.attrs.get_str(key) else { return Ok(Vec::new()) };
        let mut out = Vec::with_capacity(items.len());
        for item in items.values() {
            match item {
                Value::Ext(Ext::Document(d)) => out.push((**d).clone()),
                Value::Array(a) => out.push(Document::new(string_keyed(a))?),
                _ => {}
            }
        }
        Ok(out)
    }

    /// The document attribute `key` (`getDocument()`): a document as it is,
    /// a non-empty array that is not a list as a document of its
    /// string-keyed entries, anything else as an empty document.
    ///
    /// Fails as [`Document::new`] does for an array that is not a valid
    /// document.
    pub fn document(&self, key: &str) -> Result<Document> {
        match self.attrs.get_str(key) {
            Some(Value::Ext(Ext::Document(d))) => Ok((**d).clone()),
            Some(Value::Array(a)) if !a.is_empty() && !a.is_list() => Document::new(string_keyed(a)),
            _ => Ok(Document::empty()),
        }
    }

    /// Sets `key` to `value` (`setAttribute($key, $value)`).
    ///
    /// Fails ([`crate::Kind::Structure`]) when `key` is `$permissions` and
    /// `value` is neither `null` nor an array of strings; the permissions
    /// are stored as a list of unique strings.
    pub fn set(&mut self, key: impl Into<Str>, value: Value) -> Result<&mut Self> {
        self.set_with(key, value, SetType::Assign)
    }

    /// Sets, appends or prepends (`setAttribute($key, $value, $type)`).
    /// Appending or prepending to an attribute that is unset or not an array
    /// starts a new array; the integer keys of the array are renumbered,
    /// its string keys kept (`[...$current, $value]`).
    ///
    /// Fails as [`Document::set`] does.
    pub fn set_with(&mut self, key: impl Into<Str>, value: Value, how: SetType) -> Result<&mut Self> {
        let key = ArrayKey::normalize(key.into());
        let value = match how {
            SetType::Assign => value,
            SetType::Append | SetType::Prepend => {
                let current = match self.attrs.get(&key) {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                let mut out = Array::with_capacity(current.len() + 1);
                if how == SetType::Prepend {
                    let _ = out.push(value.clone());
                }
                for (k, v) in current.iter() {
                    match k {
                        KeyRef::Int(_) => {
                            let _ = out.push(v.clone());
                        }
                        k => {
                            out.set(k.to_owned(), v.clone());
                        }
                    }
                }
                if how == SetType::Append {
                    let _ = out.push(value);
                }
                Value::Array(out)
            }
        };
        let value = if matches!(&key, ArrayKey::Str(s) if s.as_bytes() == PERMISSIONS.as_bytes())
            && !matches!(value, Value::Null)
        {
            str_list(normalize_permissions(&value)?)
        } else {
            value
        };
        self.attrs.set(key, value);
        Ok(self)
    }

    /// Sets an attribute that is not `$permissions`: [`Document::set`]
    /// without its check, for code that sets attributes it names itself.
    ///
    /// Setting `$permissions` through it stores the value unchecked, as
    /// `$document['$permissions'] = $value` does in PHP.
    pub fn put(&mut self, key: impl Into<Str>, value: Value) -> &mut Self {
        self.attrs.set(ArrayKey::normalize(key.into()), value);
        self
    }

    /// Sets every attribute of `attrs` (`setAttributes()`), failing as
    /// [`Document::set`] does.
    pub fn set_many(&mut self, attrs: Array) -> Result<&mut Self> {
        for (k, v) in attrs {
            let key = match k {
                ArrayKey::Int(i) => Str::from(i.to_string()),
                ArrayKey::Str(s) => s,
            };
            self.set(key, v)?;
        }
        Ok(self)
    }

    /// Removes `key` (`removeAttribute()`), returning its value.
    pub fn remove(&mut self, key: &str) -> Option<Value> {
        self.attrs.remove(&ArrayKey::normalize(Str::from(key)))
    }

    /// Whether the document has no attributes (`isEmpty()`).
    pub fn is_empty(&self) -> bool {
        self.attrs.is_empty()
    }

    /// Whether `key` is set and not `null` (`isSet()`).
    pub fn is_set(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    /// Number of attributes (`count()`).
    pub fn len(&self) -> usize {
        self.attrs.len()
    }

    /// The attributes as an array, nested documents (directly or as
    /// elements of an array attribute) as arrays (`getArrayCopy()`).
    pub fn array_copy(&self) -> Array {
        Self::export(self.attrs.iter())
    }

    /// The `keys` attributes of [`Document::array_copy`], in the document's
    /// order (`only()`); `only(&[])` is empty.
    pub fn only(&self, keys: &[&str]) -> Array {
        Self::export(self.attrs.iter().filter(|(k, _)| Self::listed(*k, keys)))
    }

    /// [`Document::array_copy`] without the `keys` attributes (`except()`).
    pub fn except(&self, keys: &[&str]) -> Array {
        Self::export(self.attrs.iter().filter(|(k, _)| !Self::listed(*k, keys)))
    }

    /// Whether an array key is one of `keys` (`array_flip($keys)`, so `"5"`
    /// names the key `5`).
    fn listed(key: KeyRef<'_>, keys: &[&str]) -> bool {
        keys.iter().any(|k| ArrayKey::normalize(Str::from(*k)).as_ref() == key)
    }

    /// `Document::export()`: nested documents as their array copy, one
    /// level into arrays.
    fn export<'a>(entries: impl Iterator<Item = (KeyRef<'a>, &'a Value)>) -> Array {
        let mut out = Array::new();
        for (k, v) in entries {
            let v = match v {
                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
                Value::Array(items) => Value::Array(
                    items
                        .iter()
                        .map(|(ik, iv)| {
                            let iv = match iv {
                                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy()),
                                other => other.clone(),
                            };
                            (ik.to_owned(), iv)
                        })
                        .collect(),
                ),
                other => other.clone(),
            };
            out.set(k.to_owned(), v);
        }
        out
    }

    /// The attributes as they are, nested documents kept.
    pub fn as_array(&self) -> &Array {
        &self.attrs
    }

    /// The attributes, mutably (`$document[$key] = ...` and the other
    /// `ArrayAccess` writes, which skip [`Document::set`]'s checks).
    pub fn as_array_mut(&mut self) -> &mut Array {
        &mut self.attrs
    }

    /// The attributes, consuming the document.
    pub fn into_array(self) -> Array {
        self.attrs
    }

    /// Iterates the attributes in order (`foreach ($document as $k => $v)`).
    pub fn iter(&self) -> php_std::types::Iter<'_, Ext> {
        self.attrs.iter()
    }

    /// The attribute keys in order (`array_keys`).
    pub fn keys(&self) -> impl Iterator<Item = KeyRef<'_>> {
        self.attrs.keys()
    }

    /// The roles of [`Document::permissions_by_type`] for a built-in
    /// [`PermissionType`].
    pub fn roles(&self, kind: PermissionType) -> Result<Vec<String>> {
        self.permissions_by_type(kind.as_str())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::{as_document, string};

    #[test]
    fn wraps_nested_documents() {
        let nested: Array = [(ArrayKey::from("$id"), string("x"))].into_iter().collect();
        let doc = Document::from_pairs([("rel", Value::Array(nested))]).unwrap();
        assert!(as_document(doc.get("rel").unwrap()).is_some());
        assert_eq!(doc.array_copy().get_str("rel").map(|v| matches!(v, Value::Array(_))), Some(true));
    }

    #[test]
    fn rejects_bad_system_attributes() {
        let e = Document::from_pairs([("$id", Value::Int(1))]).unwrap_err();
        assert_eq!(e.message(), "$id must be of type string");
        let e = Document::from_pairs([("$permissions", crate::value::list([Value::Int(1)]))]).unwrap_err();
        assert_eq!(e.message(), "Every permission must be of type string");
    }

    #[test]
    fn permissions_by_type() {
        let perms =
            crate::value::list([string("read(\"any\")"), string("read(\"any\")"), string("update(\"user:1\")")]);
        let doc = Document::from_pairs([("$permissions", perms)]).unwrap();
        assert_eq!(doc.roles(PermissionType::Read).unwrap(), vec!["any".to_owned()]);
        assert_eq!(doc.permissions_by_type("update").unwrap(), vec!["user:1".to_owned()]);
        assert_eq!(doc.permissions().unwrap().len(), 2);
    }
}
