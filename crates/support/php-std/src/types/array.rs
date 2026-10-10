//! [`Array`]: a PHP array (an ordered map with integer and string keys).

use std::fmt;
use std::hash::{Hash, Hasher};

use indexmap::{Equivalent, IndexMap};

use super::{Extension, Never, Str, Value};

/// A PHP array key: an integer or a byte string.
///
/// PHP turns canonical decimal integer strings into integer keys
/// (`$a["7"]` is `$a[7]`, `"07"` and `"-0"` stay strings):
/// [`ArrayKey::normalize`] applies that rule. [`Array`] stores keys as
/// given, because some producers (igbinary, object property tables) keep
/// numeric strings as string keys.
#[derive(Clone, PartialEq, Eq)]
pub enum ArrayKey {
    Int(i64),
    Str(Str),
}

/// A borrowed [`ArrayKey`], used to look keys up and to iterate packed
/// arrays (which store no keys).
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
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

impl ArrayKey {
    /// The key PHP uses for a string offset: `"12"` is `Int(12)`; `"012"`,
    /// `"-0"`, `"1.5"` and `" 1"` stay strings.
    pub fn normalize(s: Str) -> Self {
        match numeric_key(&s) {
            Some(i) => ArrayKey::Int(i),
            None => ArrayKey::Str(s),
        }
    }

    pub fn as_ref(&self) -> KeyRef<'_> {
        match self {
            ArrayKey::Int(i) => KeyRef::Int(*i),
            ArrayKey::Str(s) => KeyRef::Str(s),
        }
    }
}

impl Hash for ArrayKey {
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.as_ref().hash(state);
    }
}

impl Hash for KeyRef<'_> {
    fn hash<H: Hasher>(&self, state: &mut H) {
        match self {
            KeyRef::Int(i) => {
                state.write_u8(0);
                i.hash(state);
            }
            KeyRef::Str(s) => {
                state.write_u8(1);
                s.hash(state);
            }
        }
    }
}

impl Equivalent<ArrayKey> for KeyRef<'_> {
    fn equivalent(&self, key: &ArrayKey) -> bool {
        *self == key.as_ref()
    }
}

impl<'a> KeyRef<'a> {
    /// The key PHP uses for a string offset (see [`ArrayKey::normalize`]).
    pub fn normalize(s: &'a [u8]) -> Self {
        match numeric_key(s) {
            Some(i) => KeyRef::Int(i),
            None => KeyRef::Str(s),
        }
    }

    pub fn to_owned(self) -> ArrayKey {
        match self {
            KeyRef::Int(i) => ArrayKey::Int(i),
            KeyRef::Str(s) => ArrayKey::Str(Str::copy_from(s)),
        }
    }
}

impl fmt::Debug for ArrayKey {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            ArrayKey::Int(i) => write!(f, "{i}"),
            ArrayKey::Str(s) => write!(f, "{s:?}"),
        }
    }
}

impl From<i64> for ArrayKey {
    fn from(i: i64) -> Self {
        ArrayKey::Int(i)
    }
}

/// A string key, normalised like `$a["..."]`.
impl From<&str> for ArrayKey {
    fn from(s: &str) -> Self {
        ArrayKey::normalize(Str::from(s))
    }
}

/// `$a[] = $v` failed: the next integer key would be past `PHP_INT_MAX`
/// ("Cannot add element to the array as the next element is already occupied").
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ArrayFull;

impl fmt::Display for ArrayFull {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str("Cannot add element to the array as the next element is already occupied")
    }
}

impl std::error::Error for ArrayFull {}

/// A PHP array: an ordered map from [`ArrayKey`] to [`Value`].
///
/// Packed (a `Vec`, no stored keys) while it is a list `0, 1, 2, ...` whose
/// next free index is its length; an ordered hash otherwise. The switch is
/// invisible. Semantics follow the engine: assigning an existing key keeps
/// its position, a removed key assigned again goes to the end, and `push`
/// uses the next free integer key (`nNextFreeElement`): one past the largest
/// integer key inserted, except that the engine's packed arrays (integer keys
/// in ascending order, possibly with holes) set it to one past the key they
/// append, which lowers it after trailing elements were removed
/// (`[0, 1, 2]`, unset 1 and 2, `$a[1] = x`, then `$a[]` uses 2). That
/// state is tracked as the engine does (`nNumUsed`, `nTableSize`), with the
/// table size an array of `n` elements is created with (`max(8, 2^k >= n)`).
#[derive(Clone)]
pub struct Array<X: Extension = Never> {
    repr: Repr<X>,
}

#[derive(Clone)]
enum Repr<X: Extension> {
    Packed(Vec<Value<X>>),
    Hash {
        map: IndexMap<ArrayKey, Value<X>>,
        /// `nNextFreeElement`: `None` until an integer key is used.
        next: Option<i64>,
        /// Set while the engine would keep the array packed.
        packed: Option<Packed>,
    },
}

/// The engine's packed-array bookkeeping.
#[derive(Clone, Copy)]
struct Packed {
    /// `nNumUsed`: one past the last slot in use (removing trailing
    /// elements lowers it).
    used: u64,
    /// `nTableSize`.
    table: u64,
}

/// `zend_hash_check_size`: the table size of an array created for `n` elements.
fn table_size(n: usize) -> u64 {
    (n as u64).max(8).next_power_of_two()
}

impl<X: Extension> Default for Array<X> {
    fn default() -> Self {
        Self::new()
    }
}

impl<X: Extension> Array<X> {
    pub fn new() -> Self {
        Array { repr: Repr::Packed(Vec::new()) }
    }

    pub fn with_capacity(n: usize) -> Self {
        Array { repr: Repr::Packed(Vec::with_capacity(n)) }
    }

    /// `[$v0, $v1, ...]`.
    pub fn from_list(v: Vec<Value<X>>) -> Self {
        Array { repr: Repr::Packed(v) }
    }

    /// An array with these entries (in order, keys as given) and this next free index.
    pub fn from_entries(entries: impl IntoIterator<Item = (ArrayKey, Value<X>)>, next: Option<i64>) -> Self {
        let iter = entries.into_iter();
        let mut map = IndexMap::with_capacity(iter.size_hint().0);
        for (k, v) in iter {
            map.insert(k, v);
        }
        Array { repr: Repr::Hash { map, next, packed: None } }
    }

    /// `array_is_list()`: keys are exactly `0, 1, 2, ...` in order.
    pub fn is_list(&self) -> bool {
        match &self.repr {
            Repr::Packed(_) => true,
            Repr::Hash { map, .. } => map.keys().enumerate().all(|(i, k)| *k == ArrayKey::Int(i as i64)),
        }
    }

    pub fn len(&self) -> usize {
        match &self.repr {
            Repr::Packed(v) => v.len(),
            Repr::Hash { map, .. } => map.len(),
        }
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// The key `push` would use, as PHP tracks it (`None`: no integer key yet, so `0`).
    pub fn next_index(&self) -> Option<i64> {
        match &self.repr {
            Repr::Packed(v) if v.is_empty() => None,
            Repr::Packed(v) => Some(v.len() as i64),
            Repr::Hash { next, .. } => *next,
        }
    }

    pub fn get(&self, k: &ArrayKey) -> Option<&Value<X>> {
        self.get_ref(k.as_ref())
    }

    pub fn get_ref(&self, k: KeyRef<'_>) -> Option<&Value<X>> {
        match &self.repr {
            Repr::Packed(v) => match k {
                KeyRef::Int(i) => usize::try_from(i).ok().and_then(|i| v.get(i)),
                KeyRef::Str(_) => None,
            },
            Repr::Hash { map, .. } => map.get(&k),
        }
    }

    /// `$a["..."]`: the key is normalised (`"7"` finds `7`).
    pub fn get_str(&self, k: &str) -> Option<&Value<X>> {
        self.get_ref(KeyRef::normalize(k.as_bytes()))
    }

    pub fn get_mut(&mut self, k: &ArrayKey) -> Option<&mut Value<X>> {
        match &mut self.repr {
            Repr::Packed(v) => match k {
                ArrayKey::Int(i) => usize::try_from(*i).ok().and_then(|i| v.get_mut(i)),
                ArrayKey::Str(_) => None,
            },
            Repr::Hash { map, .. } => map.get_mut(k),
        }
    }

    pub fn contains_key(&self, k: &ArrayKey) -> bool {
        self.get(k).is_some()
    }

    /// The hash representation (a packed `Vec` becomes an engine-packed hash).
    fn hash(&mut self) -> (&mut IndexMap<ArrayKey, Value<X>>, &mut Option<i64>, &mut Option<Packed>) {
        if let Repr::Packed(v) = &mut self.repr {
            let next = if v.is_empty() { None } else { Some(v.len() as i64) };
            let packed = Some(Packed { used: v.len() as u64, table: table_size(v.len()) });
            let map = std::mem::take(v).into_iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v)).collect();
            self.repr = Repr::Hash { map, next, packed };
        }
        match &mut self.repr {
            Repr::Hash { map, next, packed } => (map, next, packed),
            Repr::Packed(_) => unreachable!(),
        }
    }

    /// `$a[$k] = $v` (the key as given, see [`ArrayKey::normalize`]): an
    /// existing key keeps its position. Returns the previous value.
    pub fn set(&mut self, k: ArrayKey, v: Value<X>) -> Option<Value<X>> {
        if let (Repr::Packed(list), ArrayKey::Int(i)) = (&mut self.repr, &k)
            && let Ok(i) = usize::try_from(*i)
        {
            if i < list.len() {
                return Some(std::mem::replace(&mut list[i], v));
            }
            if i == list.len() {
                list.push(v);
                return None;
            }
        }
        let (map, next, packed) = self.hash();
        let ArrayKey::Int(h) = k else {
            *packed = None;
            return map.insert(k, v);
        };
        if let Some(p) = packed {
            let hu = h as u64;
            if hu < p.used {
                if let Some(old) = map.get_mut(&KeyRef::Int(h)) {
                    return Some(std::mem::replace(old, v));
                }
                // Filling a hole keeps the order: the engine converts to a hash.
                *packed = None;
            } else {
                if hu >= p.table && (hu >> 1) < p.table && (p.table >> 1) < map.len() as u64 {
                    p.table *= 2;
                }
                if hu < p.table {
                    // `add_to_packed`: the next free index is set, not raised.
                    p.used = hu + 1;
                    *next = Some(h + 1);
                    map.insert(k, v);
                    return None;
                }
                *packed = None;
            }
        }
        match *next {
            Some(n) if n > h => {}
            _ => *next = Some(if h < i64::MAX { h + 1 } else { i64::MAX }),
        }
        map.insert(k, v)
    }

    /// `$a[] = $v`.
    pub fn push(&mut self, v: Value<X>) -> Result<(), ArrayFull> {
        if let Repr::Packed(list) = &mut self.repr {
            list.push(v);
            return Ok(());
        }
        let (map, next, _) = self.hash();
        let key = next.unwrap_or(0);
        if map.contains_key(&KeyRef::Int(key)) {
            return Err(ArrayFull);
        }
        self.set(ArrayKey::Int(key), v);
        Ok(())
    }

    /// `unset($a[$k])`: the key, if set again, goes to the end.
    pub fn remove(&mut self, k: &ArrayKey) -> Option<Value<X>> {
        self.get(k)?;
        let (map, _, packed) = self.hash();
        let old = map.shift_remove(k);
        if let (Some(p), ArrayKey::Int(h)) = (packed, k)
            && *h as u64 == p.used.wrapping_sub(1)
        {
            // Trailing slots are released.
            p.used = match map.last() {
                Some((ArrayKey::Int(last), _)) => *last as u64 + 1,
                _ => 0,
            };
        }
        old
    }

    pub fn iter(&self) -> Iter<'_, X> {
        match &self.repr {
            Repr::Packed(v) => Iter::Packed(v.iter().enumerate()),
            Repr::Hash { map, .. } => Iter::Hash(map.iter()),
        }
    }

    pub fn values(&self) -> impl ExactSizeIterator<Item = &Value<X>> + DoubleEndedIterator {
        self.iter().map(|(_, v)| v)
    }

    pub fn keys(&self) -> impl ExactSizeIterator<Item = KeyRef<'_>> {
        self.iter().map(|(k, _)| k)
    }

    /// Keeps the entries `f` accepts (in order); the next free index is unchanged.
    pub fn retain(&mut self, mut f: impl FnMut(KeyRef<'_>, &Value<X>) -> bool) {
        let keep: Vec<bool> = self.iter().map(|(k, v)| f(k, v)).collect();
        if keep.iter().all(|&k| k) {
            return;
        }
        let mut keep = keep.into_iter();
        let (map, _, packed) = self.hash();
        map.retain(|_, _| keep.next().unwrap_or(true));
        if let Some(p) = packed {
            p.used = match map.last() {
                Some((ArrayKey::Int(last), _)) => *last as u64 + 1,
                _ => 0,
            };
        }
    }
}

/// Entries of an [`Array`] in order.
pub enum Iter<'a, X: Extension> {
    Packed(std::iter::Enumerate<std::slice::Iter<'a, Value<X>>>),
    Hash(indexmap::map::Iter<'a, ArrayKey, Value<X>>),
}

impl<'a, X: Extension> Iterator for Iter<'a, X> {
    type Item = (KeyRef<'a>, &'a Value<X>);

    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Iter::Packed(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            Iter::Hash(it) => it.next().map(|(k, v)| (k.as_ref(), v)),
        }
    }

    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            Iter::Packed(it) => it.size_hint(),
            Iter::Hash(it) => it.size_hint(),
        }
    }
}

impl<X: Extension> DoubleEndedIterator for Iter<'_, X> {
    fn next_back(&mut self) -> Option<Self::Item> {
        match self {
            Iter::Packed(it) => it.next_back().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            Iter::Hash(it) => it.next_back().map(|(k, v)| (k.as_ref(), v)),
        }
    }
}

impl<X: Extension> ExactSizeIterator for Iter<'_, X> {}

impl<X: Extension> IntoIterator for Array<X> {
    type Item = (ArrayKey, Value<X>);
    type IntoIter = Box<dyn Iterator<Item = (ArrayKey, Value<X>)>>;

    fn into_iter(self) -> Self::IntoIter {
        match self.repr {
            Repr::Packed(v) => Box::new(v.into_iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v))),
            Repr::Hash { map, .. } => Box::new(map.into_iter()),
        }
    }
}

impl<'a, X: Extension> IntoIterator for &'a Array<X> {
    type Item = (KeyRef<'a>, &'a Value<X>);
    type IntoIter = Iter<'a, X>;

    fn into_iter(self) -> Self::IntoIter {
        self.iter()
    }
}

/// `$a[$k] = $v` for each pair.
impl<X: Extension> FromIterator<(ArrayKey, Value<X>)> for Array<X> {
    fn from_iter<T: IntoIterator<Item = (ArrayKey, Value<X>)>>(iter: T) -> Self {
        let mut a = Array::new();
        for (k, v) in iter {
            a.set(k, v);
        }
        a
    }
}

/// A list.
impl<X: Extension> FromIterator<Value<X>> for Array<X> {
    fn from_iter<T: IntoIterator<Item = Value<X>>>(iter: T) -> Self {
        Array::from_list(iter.into_iter().collect())
    }
}

/// The same entries in the same order (structural, not PHP's `==`).
impl<X: Extension> PartialEq for Array<X> {
    fn eq(&self, other: &Self) -> bool {
        self.len() == other.len() && self.iter().zip(other.iter()).all(|(a, b)| a == b)
    }
}

impl<X: Extension> fmt::Debug for Array<X> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_map().entries(self.iter()).finish()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    type A = Array<Never>;

    fn keys(a: &A) -> Vec<ArrayKey> {
        a.keys().map(KeyRef::to_owned).collect()
    }

    #[test]
    fn numeric_keys_like_php() {
        assert_eq!(numeric_key(b"0"), Some(0));
        assert_eq!(numeric_key(b"-3"), Some(-3));
        assert_eq!(numeric_key(b"9223372036854775807"), Some(i64::MAX));
        assert_eq!(numeric_key(b"-9223372036854775808"), Some(i64::MIN));
        assert_eq!(numeric_key(b"9223372036854775808"), None);
        for s in ["", "-", "-0", "07", "+1", " 1", "1 ", "1.0", "1e3", "0x1"] {
            assert_eq!(numeric_key(s.as_bytes()), None, "{s}");
        }
    }

    #[test]
    fn set_keeps_position_and_push_uses_next_free() {
        let mut a = A::new();
        a.set("a".into(), Value::Int(1));
        a.set(ArrayKey::Int(5), Value::Int(2));
        a.set("a".into(), Value::Int(3));
        a.push(Value::Int(4)).unwrap();
        assert_eq!(keys(&a), vec![ArrayKey::Str("a".into()), ArrayKey::Int(5), ArrayKey::Int(6)]);
        assert_eq!(a.get_str("a"), Some(&Value::Int(3)));
        assert_eq!(a.get_str("6"), Some(&Value::Int(4)));
        assert!(!a.is_list());
    }

    #[test]
    fn unset_then_set_appends_and_keeps_next_free() {
        let mut a: A = [Value::Int(0), Value::Int(1), Value::Int(2)].into_iter().collect();
        assert!(a.is_list());
        a.remove(&ArrayKey::Int(2));
        a.push(Value::Null).unwrap();
        assert_eq!(keys(&a), vec![ArrayKey::Int(0), ArrayKey::Int(1), ArrayKey::Int(3)]);
        a.remove(&ArrayKey::Int(0));
        a.set(ArrayKey::Int(0), Value::Bool(true));
        assert_eq!(keys(&a), vec![ArrayKey::Int(1), ArrayKey::Int(3), ArrayKey::Int(0)]);
        let mut b = A::new();
        b.set(ArrayKey::Int(-5), Value::Null);
        b.push(Value::Null).unwrap();
        assert_eq!(keys(&b), vec![ArrayKey::Int(-5), ArrayKey::Int(-4)]);
        let mut c = A::new();
        c.set(ArrayKey::Int(i64::MAX), Value::Null);
        assert_eq!(c.push(Value::Null), Err(ArrayFull));
    }

    #[test]
    fn packed_appends_set_the_next_free_index() {
        // [0, 1, 2], unset 1 and 2, $a[1] = x: the engine's packed array
        // appends at 1 and the next free index becomes 2.
        let mut a: A = [Value::Int(0), Value::Int(1), Value::Int(2)].into_iter().collect();
        a.remove(&ArrayKey::Int(2));
        a.remove(&ArrayKey::Int(1));
        a.set(ArrayKey::Int(1), Value::Null);
        a.push(Value::Null).unwrap();
        assert_eq!(keys(&a), vec![ArrayKey::Int(0), ArrayKey::Int(1), ArrayKey::Int(2)]);
        // A hash keeps the largest: ['x' => 1, 6 => 2], unset 6, $a[] uses 7.
        let mut b = A::new();
        b.set("x".into(), Value::Null);
        b.set(ArrayKey::Int(6), Value::Null);
        b.remove(&ArrayKey::Int(6));
        b.push(Value::Null).unwrap();
        assert_eq!(keys(&b), vec![ArrayKey::from("x"), ArrayKey::Int(7)]);
    }
}
