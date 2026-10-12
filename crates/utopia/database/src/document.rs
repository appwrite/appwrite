//! [`Document`]: an ordered set of attributes, with the system attributes
//! (`$id`, `$sequence`, `$collection`, `$tenant`, `$createdAt`, `$updatedAt`,
//! `$permissions`) every stored document has.

use php_std::sort::{SortFlags, array_intersect, array_unique};

use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Ext, KeyRef, Str, Value};

/// The PHP class of a plain document.
pub const CLASS: &str = "Utopia\\Database\\Document";

/// The internal (system) attributes [`Document::attributes`] leaves out.
const INTERNAL: [&str; 7] = ["$id", "$sequence", "$collection", "$tenant", "$createdAt", "$updatedAt", "$permissions"];

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
/// `clone $document` (`__clone`, a deep copy of nested documents) is
/// [`Clone`].
#[derive(Clone, PartialEq, Debug, Default)]
pub struct Document {
    attrs: Array,
    class: Option<Str>,
}

/// How [`Document::set_with`] stores a value.
///
/// PHP: `Document::SET_TYPE_ASSIGN`, `SET_TYPE_PREPEND`, `SET_TYPE_APPEND`.
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

    /// The set type a PHP constant's value names; `None` for any other
    /// string (PHP's `setAttribute` then does nothing).
    pub fn from_name(name: &str) -> Option<SetType> {
        match name {
            "assign" => Some(SetType::Assign),
            "prepend" => Some(SetType::Prepend),
            "append" => Some(SetType::Append),
            _ => None,
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

/// The name PHP gives a value's type in a `TypeError` (`zend_zval_value_name`).
fn value_name(v: &Value) -> &str {
    match v {
        Value::Bool(true) => "true",
        Value::Bool(false) => "false",
        other => other.debug_type(),
    }
}

/// A string argument of an internal function in coercive mode
/// (`zend_parse_arg_str_weak`): scalars convert, `null` is `""` (PHP
/// deprecates it), anything else is a `TypeError`.
fn string_arg(v: &Value, function: &str, arg: &str) -> Result<Str> {
    match v {
        Value::Array(_) | Value::Object(_) | Value::Ext(_) => Err(Error::new(
            Kind::TypeError,
            format!("{function}(): Argument {arg} must be of type string, {} given", value_name(v)),
        )),
        other => Ok(other.to_php_string().unwrap_or_default()),
    }
}

/// Removes every occurrence of `search` from `subject` (`str_replace($search, '', $subject)`).
fn remove_all(subject: &[u8], search: &[u8]) -> Vec<u8> {
    php_std::string::str_replace(search, b"", subject).0.into_owned()
}

/// What `$value[$key]` is when `isset($value[$key])` holds.
enum Offset<'a> {
    /// An array element or a document attribute.
    Value(&'a Value),
    /// A byte of a string (a numeric `$key` is a string offset).
    Byte(u8),
}

/// The string offset a key names: `$str[$key]` takes an integer numeric
/// string (surrounding whitespace allowed), negative from the end.
fn string_offset(s: &Str, key: &str) -> Option<usize> {
    let (php_std::value::Number::Int(i), _) = php_std::value::numeric_str_ex(key)? else { return None };
    let len = s.len() as i64;
    let i = if i < 0 { i.checked_add(len)? } else { i };
    (0..len).contains(&i).then_some(i as usize)
}

/// `isset($value[$key]) ? $value[$key] : nothing`, failing like PHP when
/// `$value` is an object that is not array-accessible.
fn offset<'a>(value: &'a Value, key: &str) -> Result<Option<Offset<'a>>> {
    Ok(match value {
        Value::Array(a) => a.get_str(key).filter(|v| !matches!(v, Value::Null)).map(Offset::Value),
        Value::Ext(Ext::Document(d)) => d.get(key).map(Offset::Value),
        Value::Ext(_) | Value::Object(_) => {
            return Err(Error::new(Kind::Error, format!("Cannot use object of type {} as array", value.debug_type())));
        }
        Value::Str(s) => string_offset(s, key).map(|i| Offset::Byte(s.as_bytes()[i])),
        _ => None,
    })
}

/// `isset($value[$key]) && $value[$key] === $find`.
fn matches(value: &Value, key: &str, find: &Value) -> Result<bool> {
    Ok(match offset(value, key)? {
        None => false,
        Some(Offset::Value(v)) => v.strict_eq(find),
        Some(Offset::Byte(b)) => matches!(find, Value::Str(s) if s.as_bytes() == [b]),
    })
}

impl Document {
    /// A document of `input`, as `new Document($input)`: nested arrays with a
    /// `$id` or `$collection` become documents, and so do such arrays inside
    /// list values.
    ///
    /// Fails like PHP when `$id` is not a string or `$permissions` not an
    /// array ([`crate::Kind::Structure`]).
    ///
    /// PHP: `Document::__construct`.
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
    ///
    /// PHP: `Document::getId`. PHP coerces an `$id` set to another scalar
    /// after construction to a string; here it reads as empty.
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

    /// `$permissions` without duplicates, as text (`getPermissions()`).
    pub fn permissions(&self) -> Vec<String> {
        self.permission_list().map(|p| p.values().map(crate::value::to_text).collect()).unwrap_or_default()
    }

    /// `$permissions` without duplicates, the values as stored, renumbered
    /// (`array_values(array_unique($permissions))`: duplicates are found by
    /// their string forms, the first one kept).
    ///
    /// Fails like PHP when `$permissions` was set to something other than
    /// an array (`TypeError`) or holds an object among several values
    /// (`Error`).
    ///
    /// PHP: `Document::getPermissions`.
    pub fn permission_list(&self) -> Result<Array> {
        let empty = Value::Array(Array::new());
        match self.get_or("$permissions", &empty) {
            Value::Array(p) => {
                let unique = array_unique(p, SortFlags::String)?;
                Ok(unique.into_iter().map(|(_, v)| v).collect())
            }
            other => Err(Error::new(
                Kind::TypeError,
                format!("array_unique(): Argument #1 ($array) must be of type array, {} given", value_name(other)),
            )),
        }
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
        self.write_roles().map(|r| texts(&r)).unwrap_or_default()
    }

    /// The roles of the permissions starting with `kind`
    /// (`getPermissionsByType()`): `read("any")` gives `any`.
    pub fn permissions_by_type(&self, kind: &str) -> Vec<String> {
        self.roles(kind).map(|r| texts(&r)).unwrap_or_default()
    }

    /// The roles of the permissions starting with `kind`, as PHP returns
    /// them: `kind(`, `)`, `"` and spaces removed from each permission, then
    /// duplicates dropped with their keys (so `["a", "a", "b"]` is
    /// `[0 => "a", 2 => "b"]`).
    ///
    /// Fails like [`Document::permission_list`], and with a `TypeError` for
    /// a permission that is not a scalar.
    ///
    /// PHP: `Document::getPermissionsByType` (and `getRead`, `getCreate`,
    /// `getUpdate`, `getDelete` for their types).
    pub fn roles(&self, kind: &str) -> Result<Array> {
        let open = [kind.as_bytes(), b"("].concat();
        let mut out = Array::new();
        for permission in self.permission_list()?.values() {
            let permission = string_arg(permission, "str_starts_with", "#1 ($haystack)")?;
            if !permission.as_bytes().starts_with(kind.as_bytes()) {
                continue;
            }
            let mut role = remove_all(permission.as_bytes(), &open);
            for search in [&b")"[..], b"\"", b" "] {
                role = remove_all(&role, search);
            }
            let _ = out.push(Value::Str(Str::from(role)));
        }
        Ok(array_unique(&out, SortFlags::String)?)
    }

    /// The roles granted create, update and delete, as PHP returns them
    /// (`array_unique(array_intersect($create, $update, $delete))`: the keys
    /// of [`Document::roles`]`("create")` are kept).
    ///
    /// PHP: `Document::getWrite`.
    pub fn write_roles(&self) -> Result<Array> {
        let (create, update, delete) = (self.roles("create")?, self.roles("update")?, self.roles("delete")?);
        Ok(array_unique(&array_intersect(&create, &[&update, &delete])?, SortFlags::String)?)
    }

    /// `$createdAt` (`getCreatedAt()`).
    pub fn created_at(&self) -> Option<&str> {
        self.get("$createdAt").and_then(crate::value::as_str)
    }

    /// `$updatedAt` (`getUpdatedAt()`).
    pub fn updated_at(&self) -> Option<&str> {
        self.get("$updatedAt").and_then(crate::value::as_str)
    }

    /// `$tenant` (`getTenant()`): numeric values (integers, floats, numeric
    /// strings) as integers, cast like `(int)`; booleans as 0 or 1.
    ///
    /// PHP: `Document::getTenant`. A non-scalar `$tenant` fails PHP's return
    /// type (`TypeError`); here it is returned as it is.
    pub fn tenant(&self) -> Option<Value> {
        let t = self.get("$tenant")?;
        Some(match t {
            Value::Int(i) => Value::Int(*i),
            Value::Float(f) => Value::Int(php_std::format::dval_to_lval(*f)),
            Value::Bool(b) => Value::Int(i64::from(*b)),
            Value::Str(s) if php_std::value::numeric_bytes_ex(s.as_bytes()).is_some() => {
                Value::Int(php_std::format::str_to_int(s.as_bytes()))
            }
            other => other.clone(),
        })
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
    /// Appending or prepending to an attribute that is unset, `null` or not
    /// an array starts a new array.
    pub fn set_with(&mut self, key: impl Into<Str>, value: Value, how: SetType) -> &mut Self {
        let key = ArrayKey::normalize(key.into());
        match how {
            SetType::Assign => {
                self.attrs.set(key, value);
            }
            SetType::Append | SetType::Prepend => {
                // Taken in place, so the attribute keeps its position.
                let mut list = match self.attrs.get_mut(&key) {
                    Some(Value::Array(a)) => std::mem::take(a),
                    _ => Array::new(),
                };
                if how == SetType::Append {
                    let _ = list.push(value);
                } else {
                    // array_unshift renumbers integer keys and keeps string keys.
                    let mut out = Array::with_capacity(list.len() + 1);
                    let _ = out.push(value);
                    for (k, v) in list {
                        match k {
                            ArrayKey::Int(_) => {
                                let _ = out.push(v);
                            }
                            k => {
                                out.set(k, v);
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
    ///
    /// [`Document::try_find`] reports the errors PHP raises for objects
    /// that are not documents; here they read as not found.
    pub fn find(&self, key: &str, needle: &Value, subject: &str) -> Option<Value> {
        self.try_find(key, needle, subject).ok().flatten()
    }

    /// [`Document::find`], failing like PHP when an element (or the subject)
    /// is an object other than a document (`Error`).
    ///
    /// The subject is the attribute `subject` when it is truthy, else the
    /// document itself. An array subject is searched element by element; any
    /// other subject is matched itself. `$value[$key]` follows PHP: an array
    /// element, a document attribute, or a byte of a string when `key` is an
    /// integer.
    ///
    /// PHP: `Document::find` (`false` when nothing matches is `None`).
    pub fn try_find(&self, key: &str, needle: &Value, subject: &str) -> Result<Option<Value>> {
        match self.attrs.get_str(subject).filter(|v| v.truthy()) {
            None => Ok(self.get(key).is_some_and(|v| v.strict_eq(needle)).then(|| Value::from(self.clone()))),
            Some(Value::Array(a)) => {
                for v in a.values() {
                    if matches(v, key, needle)? {
                        return Ok(Some(v.clone()));
                    }
                }
                Ok(None)
            }
            Some(other) => Ok(matches(other, key, needle)?.then(|| other.clone())),
        }
    }

    /// Replaces the first element of `subject` whose `key` is `===` `needle`
    /// with `replacement` (`findAndReplace()`); whether one was found.
    ///
    /// [`Document::try_find_and_replace`] reports the errors PHP raises;
    /// here they read as not found.
    pub fn find_and_replace(&mut self, key: &str, needle: &Value, replacement: Value, subject: &str) -> bool {
        self.try_find_and_replace(key, needle, replacement, subject).unwrap_or(false)
    }

    /// [`Document::find_and_replace`], failing like PHP.
    ///
    /// The subject is chosen as in [`Document::try_find`]. In an array
    /// subject the matching element is replaced; a document subject gets
    /// `key` set; a string subject gets the byte at `key` replaced by the
    /// first byte of `replacement` (an empty one is an `Error`); without a
    /// subject the document gets `key` set.
    ///
    /// PHP: `Document::findAndReplace`. Without a truthy subject PHP also
    /// stores the document inside itself under `subject` (it binds the
    /// subject by reference and assigns `$this` to it), a cycle Rust does not
    /// build: the subject attribute is left as it was.
    pub fn try_find_and_replace(
        &mut self,
        key: &str,
        needle: &Value,
        replacement: Value,
        subject: &str,
    ) -> Result<bool> {
        let skey = ArrayKey::normalize(Str::from(subject));
        let Some(target) = self.attrs.get_mut(&skey).filter(|v| v.truthy()) else {
            // The subject is the document, which now holds itself under `subject`.
            if ArrayKey::normalize(Str::from(key)) == skey {
                return Ok(false);
            }
            if self.get(key).is_some_and(|v| v.strict_eq(needle)) {
                self.set(key, replacement);
                return Ok(true);
            }
            return Ok(false);
        };
        match target {
            Value::Array(a) => {
                let mut found = None;
                for (k, v) in a.iter() {
                    if matches(v, key, needle)? {
                        found = Some(k.to_owned());
                        break;
                    }
                }
                Ok(found.map(|k| a.set(k, replacement)).is_some())
            }
            Value::Str(s) => {
                let Some(i) = string_offset(s, key)
                    .filter(|&i| matches!(needle, Value::Str(n) if n.as_bytes() == [s.as_bytes()[i]]))
                else {
                    return Ok(false);
                };
                let with = replacement.to_php_string().map_err(Error::from)?;
                let Some(&byte) = with.as_bytes().first() else {
                    return Err(Error::new(Kind::Error, "Cannot assign an empty string to a string offset"));
                };
                let mut bytes = s.as_bytes().to_vec();
                bytes[i] = byte;
                *s = Str::from(bytes);
                Ok(true)
            }
            other => {
                if !matches(other, key, needle)? {
                    return Ok(false);
                }
                if let Value::Ext(Ext::Document(d)) = other {
                    d.set(key, replacement);
                }
                Ok(true)
            }
        }
    }

    /// Removes the first element of `subject` whose `key` is `===` `needle`
    /// (`findAndRemove()`); whether one was found.
    ///
    /// [`Document::try_find_and_remove`] reports the errors PHP raises;
    /// here they read as not found.
    pub fn find_and_remove(&mut self, key: &str, needle: &Value, subject: &str) -> bool {
        self.try_find_and_remove(key, needle, subject).unwrap_or(false)
    }

    /// [`Document::find_and_remove`], failing like PHP (removing a byte of a
    /// string subject is an `Error`).
    ///
    /// PHP: `Document::findAndRemove`, with the same caveat as
    /// [`Document::try_find_and_replace`] when there is no truthy subject.
    pub fn try_find_and_remove(&mut self, key: &str, needle: &Value, subject: &str) -> Result<bool> {
        let skey = ArrayKey::normalize(Str::from(subject));
        let Some(target) = self.attrs.get_mut(&skey).filter(|v| v.truthy()) else {
            if ArrayKey::normalize(Str::from(key)) == skey {
                return Ok(false);
            }
            if self.get(key).is_some_and(|v| v.strict_eq(needle)) {
                self.remove(key);
                return Ok(true);
            }
            return Ok(false);
        };
        match target {
            Value::Array(a) => {
                let mut found = None;
                for (k, v) in a.iter() {
                    if matches(v, key, needle)? {
                        found = Some(k.to_owned());
                        break;
                    }
                }
                Ok(found.map(|k| a.remove(&k)).is_some())
            }
            other => {
                if !matches(other, key, needle)? {
                    return Ok(false);
                }
                match other {
                    Value::Ext(Ext::Document(d)) => {
                        d.remove(key);
                    }
                    _ => return Err(Error::new(Kind::Error, "Cannot unset string offsets")),
                }
                Ok(true)
            }
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
    ///
    /// Keys are matched as PHP's `in_array` matches them, loosely: `"5"`
    /// matches the key `5`, and numeric strings match numerically.
    pub fn array_copy(&self, allow: &[&str], disallow: &[&str]) -> Array {
        if allow.is_empty() && disallow.is_empty() {
            return self.array_copy_values(&[], &[]);
        }
        let text = |list: &[&str]| -> Vec<Value> { list.iter().map(|s| Value::Str(Str::from(*s))).collect() };
        self.array_copy_values(&text(allow), &text(disallow))
    }

    /// [`Document::array_copy`] with any values as the lists, compared to
    /// each key with PHP's `==` (so `null` matches the keys `""` and `0`,
    /// and `true` every other key).
    ///
    /// PHP: `Document::getArrayCopy` (`in_array($key, $allow)`): nested
    /// documents, directly or as elements of an array, are exported with
    /// the same lists.
    pub fn array_copy_values(&self, allow: &[Value], disallow: &[Value]) -> Array {
        let listed = |k: KeyRef<'_>, list: &[Value]| -> bool {
            let key = match k {
                KeyRef::Int(i) => Value::Int(i),
                KeyRef::Str(s) => Value::Str(Str::from(s)),
            };
            list.iter().any(|a| key.loose_eq(a))
        };
        let mut out = Array::with_capacity(self.attrs.len());
        for (k, v) in self.attrs.iter() {
            if !allow.is_empty() && !listed(k, allow) {
                continue;
            }
            if !disallow.is_empty() && listed(k, disallow) {
                continue;
            }
            let v = match v {
                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy_values(allow, disallow)),
                Value::Array(items) => Value::Array(
                    items
                        .iter()
                        .map(|(ik, iv)| {
                            let iv = match iv {
                                Value::Ext(Ext::Document(d)) => Value::Array(d.array_copy_values(allow, disallow)),
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

/// The values of an array of strings, as text.
fn texts(a: &Array) -> Vec<String> {
    a.values().map(crate::value::to_text).collect()
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

    #[test]
    fn roles_keep_php_keys() {
        let perms = crate::value::list([string("read(\"a\")"), string("read(a)"), string("read(\"b\")")]);
        let doc = Document::from_pairs([("$permissions", perms)]).unwrap();
        let roles = doc.roles("read").unwrap();
        assert_eq!(roles.keys().collect::<Vec<_>>(), vec![KeyRef::Int(0), KeyRef::Int(2)]);
    }

    #[test]
    fn string_subjects_use_byte_offsets() {
        let mut doc = Document::from_pairs([("s", string("abc"))]).unwrap();
        assert!(doc.try_find_and_replace("0", &string("a"), string("zz"), "s").unwrap());
        assert_eq!(doc.get("s"), Some(&string("zbc")));
        assert_eq!(
            doc.try_find_and_remove("-1", &string("c"), "s").unwrap_err().message(),
            "Cannot unset string offsets"
        );
    }
}
