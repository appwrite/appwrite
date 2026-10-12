//! [`Document`]: an ordered set of attributes, with the system attributes
//! (`$id`, `$sequence`, `$collection`, `$tenant`, `$createdAt`, `$updatedAt`,
//! `$permissions`) every stored document has.

use php_std::sort::{SortFlags, array_unique};

use crate::error::{Error, Result};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value, as_document};

/// The PHP class of a plain document.
pub const CLASS: &str = "Utopia\\Database\\Document";

/// A document: attributes in insertion order, each a [`Value`]. Nested
/// documents (relationships) are [`Value::Ext`] with [`Ext::Document`].
///
/// ```
/// use utopia_database::{Document, value::string};
///
/// let mut doc = Document::from_pairs([("$id", string("movie1")), ("name", string("Alien"))]).unwrap();
/// assert_eq!(doc.id(), "movie1");
/// doc.set("year", 1979.into());
/// assert_eq!(doc.get("year"), Some(&1979.into()));
/// assert!(doc.remove("name").is_some());
/// ```
///
/// PHP: `Utopia\Database\Document` (an `ArrayObject`). Attribute access
/// follows `isset()`: an attribute holding `null` reads as absent through
/// [`Document::get`] and [`Document::is_set`]. A document of a custom class
/// registered with `setDocumentType` keeps its class name ([`Document::class`]).
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Document {
    attrs: Array,
    class: Option<Str>,
}

/// How [`Document::set_with`] stores a value.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum SetType {
    /// Replace the attribute (`SET_TYPE_ASSIGN`).
    Assign,
    /// Push onto the front of the attribute's array (`SET_TYPE_PREPEND`).
    Prepend,
    /// Push onto the end of the attribute's array (`SET_TYPE_APPEND`).
    Append,
}

impl SetType {
    /// The PHP constant's value (`assign`, `prepend`, `append`).
    pub fn as_str(self) -> &'static str {
        match self {
            SetType::Assign => "assign",
            SetType::Prepend => "prepend",
            SetType::Append => "append",
        }
    }
}

/// `isset($array[$key])`.
fn isset(a: &Array, key: &str) -> bool {
    a.get_str(key).is_some_and(|v| !matches!(v, Value::Null))
}

fn wraps(a: &Array) -> bool {
    isset(a, "$id") || isset(a, "$collection")
}

impl Document {
    /// A document of `input`, as `new Document($input)`: nested arrays with a
    /// `$id` or `$collection` become documents, and so do such arrays inside
    /// list values.
    ///
    /// Fails like PHP when `$id` is not a string or `$permissions` not an
    /// array ([`crate::Kind::Structure`]).
    pub fn new(input: Array) -> Result<Self> {
        if let Some(id) = input.get_str("$id")
            && !matches!(id, Value::Str(_))
        {
            return Err(Error::structure("$id must be of type string"));
        }
        if let Some(p) = input.get_str("$permissions")
            && !matches!(p, Value::Array(_))
        {
            return Err(Error::structure("$permissions must be of type array"));
        }
        let mut attrs = input;
        let keys: Vec<ArrayKey> = attrs.keys().map(KeyRef::to_owned).collect();
        for key in keys {
            let Some(Value::Array(value)) = attrs.get_mut(&key) else { continue };
            if wraps(value) {
                let inner = std::mem::take(value);
                attrs.set(key, Value::from(Document::new(inner)?));
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
                    value.set(ck, Value::from(Document::new(inner)?));
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

    /// A document holding `attrs` exactly, without the nested-document
    /// wrapping or checks of [`Document::new`] (adapters building documents
    /// from rows they already validated).
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
        self.class = Some(class.into());
        self
    }

    /// `$id` (`getId()`); empty when unset.
    pub fn id(&self) -> &str {
        match self.get("$id") {
            Some(Value::Str(s)) => s.as_str().unwrap_or(""),
            _ => "",
        }
    }

    /// `$sequence` (`getSequence()`).
    pub fn sequence(&self) -> Option<&Value> {
        self.get("$sequence")
    }

    /// `$collection` (`getCollection()`); empty when unset.
    pub fn collection(&self) -> &str {
        match self.get("$collection") {
            Some(Value::Str(s)) => s.as_str().unwrap_or(""),
            _ => "",
        }
    }

    /// `$permissions` without duplicates (`getPermissions()`).
    pub fn permissions(&self) -> Vec<String> {
        let Some(Value::Array(p)) = self.get("$permissions") else { return Vec::new() };
        let unique = array_unique(p, SortFlags::String).unwrap_or_else(|_| p.clone());
        unique.values().map(crate::value::to_text).collect()
    }

    /// The roles granted `read` (`getRead()`).
    pub fn read(&self) -> Vec<String> {
        self.permissions_by_type("read")
    }

    /// The roles granted `create` (`getCreate()`).
    pub fn create(&self) -> Vec<String> {
        self.permissions_by_type("create")
    }

    /// The roles granted `update` (`getUpdate()`).
    pub fn update(&self) -> Vec<String> {
        self.permissions_by_type("update")
    }

    /// The roles granted `delete` (`getDelete()`).
    pub fn delete(&self) -> Vec<String> {
        self.permissions_by_type("delete")
    }

    /// The roles granted create, update and delete (`getWrite()`).
    pub fn write(&self) -> Vec<String> {
        let (create, update, delete) = (self.create(), self.update(), self.delete());
        let mut out: Vec<String> = Vec::new();
        for role in create {
            if update.contains(&role) && delete.contains(&role) && !out.contains(&role) {
                out.push(role);
            }
        }
        out
    }

    /// The roles of the permissions starting with `kind`
    /// (`getPermissionsByType()`): `read("any")` gives `any`.
    pub fn permissions_by_type(&self, kind: &str) -> Vec<String> {
        let mut out: Vec<String> = Vec::new();
        for permission in self.permissions() {
            if !permission.starts_with(kind) {
                continue;
            }
            let open = format!("{kind}(");
            let role: String = permission.replace(&open, "").replace([')', '"', ' '], "");
            if !out.contains(&role) {
                out.push(role);
            }
        }
        out
    }

    /// `$createdAt` (`getCreatedAt()`).
    pub fn created_at(&self) -> Option<&str> {
        self.get("$createdAt").and_then(crate::value::as_str)
    }

    /// `$updatedAt` (`getUpdatedAt()`).
    pub fn updated_at(&self) -> Option<&str> {
        self.get("$updatedAt").and_then(crate::value::as_str)
    }

    /// `$tenant` (`getTenant()`): numeric values as integers.
    pub fn tenant(&self) -> Option<Value> {
        let t = self.get("$tenant")?;
        match php_std::value::numeric(&t.to_json()) {
            Some(n) if !matches!(t, Value::Array(_) | Value::Object(_) | Value::Ext(_)) => Some(Value::Int(match n {
                php_std::value::Number::Int(i) => i,
                php_std::value::Number::Float(f) => f as i64,
            })),
            _ => Some(t.clone()),
        }
    }

    /// Every attribute except the internal ones (`getAttributes()`).
    pub fn attributes(&self) -> Array {
        const INTERNAL: [&str; 7] =
            ["$id", "$sequence", "$collection", "$tenant", "$createdAt", "$updatedAt", "$permissions"];
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

    /// Whether `key` is present at all, `null` included
    /// (`array_key_exists`/`offsetExists`).
    pub fn has(&self, key: &str) -> bool {
        self.attrs.get_str(key).is_some()
    }

    /// Sets `key` to `value` (`setAttribute($key, $value)`).
    pub fn set(&mut self, key: impl Into<Str>, value: Value) -> &mut Self {
        self.attrs.set(ArrayKey::normalize(key.into()), value);
        self
    }

    /// Sets, appends or prepends (`setAttribute($key, $value, $type)`).
    pub fn set_with(&mut self, key: impl Into<Str>, value: Value, how: SetType) -> &mut Self {
        let key = ArrayKey::normalize(key.into());
        match how {
            SetType::Assign => {
                self.attrs.set(key, value);
            }
            SetType::Append | SetType::Prepend => {
                let mut list = match self.attrs.get(&key) {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                if how == SetType::Append {
                    let _ = list.push(value);
                } else {
                    // array_unshift renumbers integer keys and keeps string keys.
                    let mut out = Array::new();
                    let _ = out.push(value);
                    for (k, v) in list.iter() {
                        match k {
                            KeyRef::Int(_) => {
                                let _ = out.push(v.clone());
                            }
                            KeyRef::Str(_) => {
                                out.set(k.to_owned(), v.clone());
                            }
                        }
                    }
                    list = out;
                }
                self.attrs.set(key, Value::Array(list));
            }
        }
        self
    }

    /// Sets every attribute of `attrs` (`setAttributes()`).
    pub fn set_many(&mut self, attrs: Array) -> &mut Self {
        for (k, v) in attrs {
            self.attrs.set(k, v);
        }
        self
    }

    /// Removes `key` (`removeAttribute()`), returning its value.
    pub fn remove(&mut self, key: &str) -> Option<Value> {
        self.attrs.remove(&ArrayKey::normalize(Str::from(key)))
    }

    /// The first element of `subject` (or the document itself when `subject`
    /// is empty or unset) whose `key` is `===` `needle` (`find()`).
    pub fn find(&self, key: &str, needle: &Value, subject: &str) -> Option<Value> {
        let subject_value = self.attrs.get_str(subject).filter(|v| v.truthy());
        let matches =
            |v: &Value| -> bool { lookup(v, key).is_some_and(|x| !matches!(x, Value::Null) && x.strict_eq(needle)) };
        match subject_value {
            None => {
                let me = Value::from(self.clone());
                matches(&me).then_some(me)
            }
            Some(Value::Array(a)) => a.values().find(|v| matches(v)).cloned(),
            Some(other) => matches(other).then(|| other.clone()),
        }
    }

    /// Replaces the first element of `subject` whose `key` is `===` `needle`
    /// with `replacement` (`findAndReplace()`); whether one was found.
    pub fn find_and_replace(&mut self, key: &str, needle: &Value, replacement: Value, subject: &str) -> bool {
        let skey = ArrayKey::normalize(Str::from(subject));
        let truthy = self.attrs.get(&skey).is_some_and(Value::truthy);
        let matches =
            |v: &Value| -> bool { lookup(v, key).is_some_and(|x| !matches!(x, Value::Null) && x.strict_eq(needle)) };
        if !truthy {
            // `$subject = &$this[$subject]` creates the key as null when it
            // was missing; then the subject is the document itself.
            if !self.attrs.contains_key(&skey) {
                self.attrs.set(skey, Value::Null);
            }
            let Some(v) = self.attrs.get_str(key) else { return false };
            if !matches!(v, Value::Null) && v.strict_eq(needle) {
                self.attrs.set(ArrayKey::normalize(Str::from(key)), replacement);
                return true;
            }
            return false;
        }
        match self.attrs.get_mut(&skey) {
            Some(Value::Array(a)) => {
                let found = a.iter().find(|(_, v)| matches(v)).map(|(k, _)| k.to_owned());
                match found {
                    Some(k) => {
                        a.set(k, replacement);
                        true
                    }
                    None => false,
                }
            }
            Some(Value::Ext(Ext::Document(d))) if d.get(key).is_some_and(|x| x.strict_eq(needle)) => {
                d.set(key, replacement);
                true
            }
            _ => false,
        }
    }

    /// Removes the first element of `subject` whose `key` is `===` `needle`
    /// (`findAndRemove()`); whether one was found.
    pub fn find_and_remove(&mut self, key: &str, needle: &Value, subject: &str) -> bool {
        let skey = ArrayKey::normalize(Str::from(subject));
        let truthy = self.attrs.get(&skey).is_some_and(Value::truthy);
        let matches =
            |v: &Value| -> bool { lookup(v, key).is_some_and(|x| !matches!(x, Value::Null) && x.strict_eq(needle)) };
        if !truthy {
            if !self.attrs.contains_key(&skey) {
                self.attrs.set(skey, Value::Null);
            }
            let Some(v) = self.attrs.get_str(key) else { return false };
            if !matches!(v, Value::Null) && v.strict_eq(needle) {
                self.attrs.remove(&ArrayKey::normalize(Str::from(key)));
                return true;
            }
            return false;
        }
        match self.attrs.get_mut(&skey) {
            Some(Value::Array(a)) => {
                let found = a.iter().find(|(_, v)| matches(v)).map(|(k, _)| k.to_owned());
                match found {
                    Some(k) => {
                        a.remove(&k);
                        true
                    }
                    None => false,
                }
            }
            Some(Value::Ext(Ext::Document(d))) if d.get(key).is_some_and(|x| x.strict_eq(needle)) => {
                d.remove(key);
                true
            }
            _ => false,
        }
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

    /// The attributes as an array, nested documents as arrays, keeping only
    /// `allow` (when not empty) and dropping `disallow` (`getArrayCopy()`).
    pub fn array_copy(&self, allow: &[&str], disallow: &[&str]) -> Array {
        let listed = |k: &KeyRef<'_>, list: &[&str]| -> bool {
            list.iter().any(|a| match k {
                KeyRef::Str(s) => a.as_bytes() == *s,
                KeyRef::Int(i) => a.parse::<i64>().ok() == Some(*i),
            })
        };
        let mut out = Array::with_capacity(self.attrs.len());
        for (k, v) in self.attrs.iter() {
            if !allow.is_empty() && !listed(&k, allow) {
                continue;
            }
            if !disallow.is_empty() && listed(&k, disallow) {
                continue;
            }
            let v = match v {
                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(allow, disallow)),
                Value::Array(items) => Value::Array(
                    items
                        .iter()
                        .map(|(ik, iv)| {
                            let iv = match iv {
                                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy(allow, disallow)),
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

    /// The attributes, mutably.
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
}

/// `$value[$key]` on an array or a document.
fn lookup<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => a.get_str(key),
        _ => as_document(value).and_then(|d| d.raw(key)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::string;

    #[test]
    fn wraps_nested_documents() {
        let nested: Array = [(ArrayKey::from("$id"), string("x"))].into_iter().collect();
        let doc = Document::from_pairs([("rel", Value::Array(nested))]).unwrap();
        assert!(as_document(doc.get("rel").unwrap()).is_some());
        assert_eq!(doc.array_copy(&[], &[]).get_str("rel").map(|v| matches!(v, Value::Array(_))), Some(true));
    }

    #[test]
    fn rejects_bad_system_attributes() {
        let e = Document::from_pairs([("$id", Value::Int(1))]).unwrap_err();
        assert_eq!(e.message(), "$id must be of type string");
    }

    #[test]
    fn permissions_by_type() {
        let perms =
            crate::value::list([string("read(\"any\")"), string("read(\"any\")"), string("update(\"user:1\")")]);
        let doc = Document::from_pairs([("$permissions", perms)]).unwrap();
        assert_eq!(doc.read(), vec!["any".to_owned()]);
        assert_eq!(doc.update(), vec!["user:1".to_owned()]);
        assert_eq!(doc.permissions().len(), 2);
    }
}
