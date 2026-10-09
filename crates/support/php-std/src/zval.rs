//! PHP values with full fidelity, for the engine functions that need more
//! than the `serde_json::Value` model: byte strings, integer and string
//! array keys, and `stdClass` objects with properties.
//!
//! The rest of php-std carries PHP values as `serde_json::Value`, decoded the
//! way `Utopia\Http\Request` decodes request bodies (objects are associative
//! arrays, except empty ones, which stay `stdClass`). That model cannot hold
//! a binary string, an infinite float or a non-empty `stdClass`, all of which
//! `serialize`, `unserialize`, `json_decode` (object mode) and `var_export`
//! produce or consume. [`Zval`] is the exact model; [`PhpValue`] lets the
//! encoders (`json::encode`, `serialize::serialize`, `serialize::var_export`)
//! walk either model without converting it first.
//!
//! | PHP | Rust |
//! |---|---|
//! | `null`, `bool`, `int`, `float` | [`Zval::Null`], [`Zval::Bool`], [`Zval::Int`], [`Zval::Float`] |
//! | `string` (bytes) | [`Zval::String`] |
//! | `array` (ordered hash, int or string keys) | [`Zval::Array`] / [`Array`], keys [`Key`] |
//! | `stdClass` | [`Zval::Object`] / [`Object`] |
//! | `ZEND_HANDLE_NUMERIC_STR` | [`numeric_key`], [`Key::from_bytes`] |
//! | `zend_array_is_list` | [`Array::is_list`] |

use indexmap::IndexMap;
use indexmap::map::Entry;
use serde_json::{Map, Number, Value};

/// A PHP value.
#[derive(Debug, Clone, PartialEq, Default)]
pub enum Zval {
    #[default]
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    /// A PHP string: bytes, not necessarily UTF-8.
    String(Vec<u8>),
    /// A PHP array.
    Array(Array),
    /// A `stdClass` instance.
    Object(Object),
}

/// A PHP array key: an integer or a byte string.
///
/// Strings that are canonical decimal integers (`"7"`, `"-3"`, not `"07"`,
/// `"-0"` or `" 7"`) are integer keys in PHP; [`Key::from_bytes`] applies
/// that rule.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum Key {
    Int(i64),
    Str(Vec<u8>),
}

/// A borrowed [`Key`].
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum KeyRef<'a> {
    Int(i64),
    Str(&'a [u8]),
}

/// `ZEND_HANDLE_NUMERIC_STR`: the integer a string array key becomes, if any.
///
/// An optional `-` and decimal digits without leading zeros (`"0"` itself is
/// allowed, `"-0"` is not), within the `i64` range.
pub fn numeric_key(key: &[u8]) -> Option<i64> {
    let (negative, digits) = match key.split_first() {
        Some((b'-', rest)) => (true, rest),
        _ => (false, key),
    };
    if digits.is_empty() || digits.len() > 19 || !digits.iter().all(u8::is_ascii_digit) {
        return None;
    }
    if digits[0] == b'0' && (digits.len() > 1 || negative) {
        return None;
    }
    let mut magnitude: u64 = 0;
    for d in digits {
        magnitude = magnitude.checked_mul(10)?.checked_add(u64::from(d - b'0'))?;
    }
    if negative {
        if magnitude <= i64::MAX as u64 + 1 { Some((magnitude as i64).wrapping_neg()) } else { None }
    } else {
        i64::try_from(magnitude).ok()
    }
}

impl Key {
    /// The key PHP uses for a string offset (`$array["7"]` is `$array[7]`).
    pub fn from_bytes(key: &[u8]) -> Key {
        match numeric_key(key) {
            Some(i) => Key::Int(i),
            None => Key::Str(key.to_vec()),
        }
    }

    pub fn as_ref(&self) -> KeyRef<'_> {
        match self {
            Key::Int(i) => KeyRef::Int(*i),
            Key::Str(s) => KeyRef::Str(s),
        }
    }
}

impl From<i64> for Key {
    fn from(i: i64) -> Self {
        Key::Int(i)
    }
}

impl From<&str> for Key {
    fn from(s: &str) -> Self {
        Key::from_bytes(s.as_bytes())
    }
}

impl KeyRef<'_> {
    pub fn to_owned(self) -> Key {
        match self {
            KeyRef::Int(i) => Key::Int(i),
            KeyRef::Str(s) => Key::Str(s.to_vec()),
        }
    }
}

/// A PHP array: an ordered map from [`Key`] to [`Zval`].
///
/// Inserting an existing key replaces its value in place (PHP keeps the
/// original position); [`Array::push`] appends at the next free integer key.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Array {
    entries: IndexMap<Key, Zval>,
    /// `nNextFreeElement`: `None` until an integer key is used.
    next: Option<i64>,
}

impl Array {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_capacity(n: usize) -> Self {
        Self { entries: IndexMap::with_capacity(n), next: None }
    }

    pub fn len(&self) -> usize {
        self.entries.len()
    }

    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }

    /// `$array[$key] = $value`.
    pub fn insert(&mut self, key: Key, value: Zval) {
        if let Key::Int(i) = key {
            self.next = Some(match self.next {
                Some(n) if n > i => n,
                _ => i.saturating_add(1),
            });
        }
        match self.entries.entry(key) {
            Entry::Occupied(mut e) => {
                e.insert(value);
            }
            Entry::Vacant(e) => {
                e.insert(value);
            }
        }
    }

    /// `$array[] = $value`. Returns `false` (and inserts nothing) when the
    /// next key would overflow, as PHP does.
    pub fn push(&mut self, value: Zval) -> bool {
        let key = self.next.unwrap_or(0);
        if self.next == Some(i64::MAX) && self.entries.contains_key(&Key::Int(i64::MAX)) {
            return false;
        }
        self.insert(Key::Int(key), value);
        true
    }

    pub fn get(&self, key: &Key) -> Option<&Zval> {
        self.entries.get(key)
    }

    pub fn iter(&self) -> indexmap::map::Iter<'_, Key, Zval> {
        self.entries.iter()
    }

    /// `zend_array_is_list`: keys are exactly `0, 1, 2, ...` in order.
    pub fn is_list(&self) -> bool {
        self.entries.keys().enumerate().all(|(i, k)| *k == Key::Int(i as i64))
    }
}

impl FromIterator<(Key, Zval)> for Array {
    fn from_iter<T: IntoIterator<Item = (Key, Zval)>>(iter: T) -> Self {
        let mut a = Array::new();
        for (k, v) in iter {
            a.insert(k, v);
        }
        a
    }
}

impl FromIterator<Zval> for Array {
    fn from_iter<T: IntoIterator<Item = Zval>>(iter: T) -> Self {
        let mut a = Array::new();
        for v in iter {
            a.push(v);
        }
        a
    }
}

/// A `stdClass` instance: properties by name, in definition order.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Object {
    props: IndexMap<Vec<u8>, Zval>,
}

impl Object {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn len(&self) -> usize {
        self.props.len()
    }

    pub fn is_empty(&self) -> bool {
        self.props.is_empty()
    }

    /// `$object->{$name} = $value`: replaces an existing property in place.
    pub fn set(&mut self, name: Vec<u8>, value: Zval) {
        self.props.insert(name, value);
    }

    pub fn get(&self, name: &[u8]) -> Option<&Zval> {
        self.props.get(name)
    }

    pub fn iter(&self) -> indexmap::map::Iter<'_, Vec<u8>, Zval> {
        self.props.iter()
    }
}

impl FromIterator<(Vec<u8>, Zval)> for Object {
    fn from_iter<T: IntoIterator<Item = (Vec<u8>, Zval)>>(iter: T) -> Self {
        let mut o = Object::new();
        for (k, v) in iter {
            o.set(k, v);
        }
        o
    }
}

/// One level of a PHP value, as the engine's encoders see it.
pub enum View<'a, V: PhpValue + 'a> {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(&'a [u8]),
    /// A PHP array: its entries in order.
    Array(V::Entries<'a>),
    /// A `stdClass` instance: its properties in order (keys are always strings).
    Object(V::Entries<'a>),
}

/// A value the PHP encoders (`json_encode`, `serialize`, `var_export`) can
/// walk. Implemented by [`Zval`] and by `serde_json::Value` (with the
/// request model: non-empty objects are arrays, numeric string keys are
/// integer keys, integers beyond `i64` are floats).
pub trait PhpValue: Sized {
    type Entries<'a>: Iterator<Item = (KeyRef<'a>, &'a Self)> + ExactSizeIterator
    where
        Self: 'a;

    fn view(&self) -> View<'_, Self>;

    /// `zend_array_is_list` for an array value; `false` for anything else.
    fn is_list(&self) -> bool {
        match self.view() {
            View::Array(entries) => entries.enumerate().all(|(i, (k, _))| k == KeyRef::Int(i as i64)),
            _ => false,
        }
    }
}

/// Entries of a [`Zval`] array or object.
pub enum ZvalEntries<'a> {
    Array(indexmap::map::Iter<'a, Key, Zval>),
    Object(indexmap::map::Iter<'a, Vec<u8>, Zval>),
}

impl<'a> Iterator for ZvalEntries<'a> {
    type Item = (KeyRef<'a>, &'a Zval);

    fn next(&mut self) -> Option<Self::Item> {
        match self {
            ZvalEntries::Array(it) => it.next().map(|(k, v)| (k.as_ref(), v)),
            ZvalEntries::Object(it) => it.next().map(|(k, v)| (KeyRef::Str(k), v)),
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            ZvalEntries::Array(it) => it.size_hint(),
            ZvalEntries::Object(it) => it.size_hint(),
        }
    }
}

impl ExactSizeIterator for ZvalEntries<'_> {}

impl PhpValue for Zval {
    type Entries<'a> = ZvalEntries<'a>;

    fn view(&self) -> View<'_, Self> {
        match self {
            Zval::Null => View::Null,
            Zval::Bool(b) => View::Bool(*b),
            Zval::Int(i) => View::Int(*i),
            Zval::Float(f) => View::Float(*f),
            Zval::String(s) => View::Str(s),
            Zval::Array(a) => View::Array(ZvalEntries::Array(a.entries.iter())),
            Zval::Object(o) => View::Object(ZvalEntries::Object(o.props.iter())),
        }
    }

    fn is_list(&self) -> bool {
        matches!(self, Zval::Array(a) if a.is_list())
    }
}

/// Entries of a `serde_json::Value` list or (non-empty) object.
pub enum JsonEntries<'a> {
    List(std::iter::Enumerate<std::slice::Iter<'a, Value>>),
    Map(serde_json::map::Iter<'a>),
}

impl<'a> Iterator for JsonEntries<'a> {
    type Item = (KeyRef<'a>, &'a Value);

    fn next(&mut self) -> Option<Self::Item> {
        match self {
            JsonEntries::List(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            JsonEntries::Map(it) => it.next().map(|(k, v)| {
                let key = match numeric_key(k.as_bytes()) {
                    Some(i) => KeyRef::Int(i),
                    None => KeyRef::Str(k.as_bytes()),
                };
                (key, v)
            }),
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            JsonEntries::List(it) => it.size_hint(),
            JsonEntries::Map(it) => it.size_hint(),
        }
    }
}

impl ExactSizeIterator for JsonEntries<'_> {}

impl PhpValue for Value {
    type Entries<'a> = JsonEntries<'a>;

    fn view(&self) -> View<'_, Self> {
        match self {
            Value::Null => View::Null,
            Value::Bool(b) => View::Bool(*b),
            Value::Number(n) => match n.as_i64() {
                Some(i) => View::Int(i),
                None => View::Float(n.as_f64().unwrap_or(0.0)),
            },
            Value::String(s) => View::Str(s.as_bytes()),
            Value::Array(a) => View::Array(JsonEntries::List(a.iter().enumerate())),
            Value::Object(o) if o.is_empty() => View::Object(JsonEntries::Map(o.iter())),
            Value::Object(o) => View::Array(JsonEntries::Map(o.iter())),
        }
    }

    fn is_list(&self) -> bool {
        match self {
            Value::Array(_) => true,
            Value::Object(o) => {
                !o.is_empty() && o.keys().enumerate().all(|(i, k)| numeric_key(k.as_bytes()) == Some(i as i64))
            }
            _ => false,
        }
    }
}

impl From<&Value> for Zval {
    /// The PHP value of a request-model `serde_json::Value`.
    fn from(value: &Value) -> Self {
        match value {
            Value::Null => Zval::Null,
            Value::Bool(b) => Zval::Bool(*b),
            Value::Number(n) => match n.as_i64() {
                Some(i) => Zval::Int(i),
                None => Zval::Float(n.as_f64().unwrap_or(0.0)),
            },
            Value::String(s) => Zval::String(s.as_bytes().to_vec()),
            Value::Array(a) => Zval::Array(a.iter().map(Zval::from).collect()),
            Value::Object(o) if o.is_empty() => Zval::Object(Object::new()),
            Value::Object(o) => {
                Zval::Array(o.iter().map(|(k, v)| (Key::from_bytes(k.as_bytes()), Zval::from(v))).collect())
            }
        }
    }
}

impl From<Value> for Zval {
    fn from(value: Value) -> Self {
        Zval::from(&value)
    }
}

/// Why a [`Zval`] has no request-model `serde_json::Value`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Unrepresentable {
    /// `INF`, `-INF` or `NAN`.
    NonFiniteFloat,
    /// A string or key that is not valid UTF-8.
    Binary,
}

impl Zval {
    /// The request-model `serde_json::Value` of this value: lists become
    /// arrays, other arrays objects (integer keys as strings), `stdClass`
    /// objects objects. A non-empty `stdClass` becomes an object, which the
    /// model reads back as an associative array.
    pub fn to_json(&self) -> Result<Value, Unrepresentable> {
        Ok(match self {
            Zval::Null => Value::Null,
            Zval::Bool(b) => Value::Bool(*b),
            Zval::Int(i) => Value::from(*i),
            Zval::Float(f) => Value::Number(Number::from_f64(*f).ok_or(Unrepresentable::NonFiniteFloat)?),
            Zval::String(s) => Value::String(utf8(s)?),
            Zval::Array(a) if a.is_list() => {
                Value::Array(a.iter().map(|(_, v)| v.to_json()).collect::<Result<_, _>>()?)
            }
            Zval::Array(a) => {
                let mut m = Map::with_capacity(a.len());
                for (k, v) in a.iter() {
                    let key = match k {
                        Key::Int(i) => i.to_string(),
                        Key::Str(s) => utf8(s)?,
                    };
                    m.insert(key, v.to_json()?);
                }
                Value::Object(m)
            }
            Zval::Object(o) => {
                let mut m = Map::with_capacity(o.len());
                for (k, v) in o.iter() {
                    m.insert(utf8(k)?, v.to_json()?);
                }
                Value::Object(m)
            }
        })
    }
}

fn utf8(bytes: &[u8]) -> Result<String, Unrepresentable> {
    std::str::from_utf8(bytes).map(str::to_owned).map_err(|_| Unrepresentable::Binary)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn numeric_keys_like_php() {
        assert_eq!(numeric_key(b"0"), Some(0));
        assert_eq!(numeric_key(b"7"), Some(7));
        assert_eq!(numeric_key(b"-3"), Some(-3));
        assert_eq!(numeric_key(b"9223372036854775807"), Some(i64::MAX));
        assert_eq!(numeric_key(b"-9223372036854775808"), Some(i64::MIN));
        assert_eq!(numeric_key(b"9223372036854775808"), None);
        assert_eq!(numeric_key(b"-9223372036854775809"), None);
        for s in ["", "-", "-0", "07", "+1", " 1", "1 ", "1.0", "1e3", "0x1"] {
            assert_eq!(numeric_key(s.as_bytes()), None, "{s}");
        }
    }

    #[test]
    fn arrays_update_in_place_and_push_after_max() {
        let mut a = Array::new();
        a.insert(Key::from("a"), Zval::Int(1));
        a.insert(Key::Int(5), Zval::Int(2));
        a.insert(Key::from("a"), Zval::Int(3));
        a.push(Zval::Int(4));
        let keys: Vec<Key> = a.iter().map(|(k, _)| k.clone()).collect();
        assert_eq!(keys, vec![Key::Str(b"a".to_vec()), Key::Int(5), Key::Int(6)]);
        assert_eq!(a.get(&Key::from("a")), Some(&Zval::Int(3)));
        assert!(!a.is_list());
        assert!([Zval::Null, Zval::Null].into_iter().collect::<Array>().is_list());
    }

    #[test]
    fn request_model_round_trip() {
        let v = json!({"0": "a", "1": {"x": []}, "k": {}});
        let z = Zval::from(&v);
        assert!(z.is_list() == v.is_list());
        assert_eq!(z.to_json().unwrap(), json!({"0": "a", "1": {"x": []}, "k": {}}));
        assert_eq!(Zval::from(&json!({"0": 1, "1": 2})).to_json().unwrap(), json!([1, 2]));
    }
}

// ---------------------------------------------------------------------------
// Mutable access (php_register_variable_ex builds nested arrays in place)
// ---------------------------------------------------------------------------

impl Array {
    /// `&$array[$key]`.
    pub fn get_mut(&mut self, key: &Key) -> Option<&mut Zval> {
        self.entries.get_mut(key)
    }

    /// `unset($array[$key])`, keeping the order of the other entries.
    pub fn remove(&mut self, key: &Key) -> Option<Zval> {
        self.entries.shift_remove(key)
    }

    /// The next key [`Array::push`] would use, or `None` when it would overflow.
    pub fn next_key(&self) -> Option<i64> {
        if self.next == Some(i64::MAX) && self.entries.contains_key(&Key::Int(i64::MAX)) {
            return None;
        }
        Some(self.next.unwrap_or(0))
    }
}
