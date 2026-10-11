//! [`Array`]: a PHP array (an ordered map with integer and string keys).

use std::fmt;
use std::hash::{Hash, Hasher};
use std::sync::Arc;

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
    /// An integer key.
    Int(i64),
    /// A string key, as bytes.
    Str(Str),
}

/// A borrowed [`ArrayKey`], used to look keys up and to iterate packed
/// arrays (which store no keys).
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum KeyRef<'a> {
    /// An integer key.
    Int(i64),
    /// A string key, as bytes.
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

    /// The key, borrowed.
    pub fn as_ref(&self) -> KeyRef<'_> {
        match self {
            ArrayKey::Int(i) => KeyRef::Int(*i),
            ArrayKey::Str(s) => KeyRef::Str(s),
        }
    }
}

impl Hash for ArrayKey {
    /// Hashes the key as its [`KeyRef`], so borrowed lookups find it.
    fn hash<H: Hasher>(&self, state: &mut H) {
        self.as_ref().hash(state);
    }
}

impl Hash for KeyRef<'_> {
    /// Hashes the key in one write (an integer and a string may collide,
    /// which equality tells apart).
    fn hash<H: Hasher>(&self, state: &mut H) {
        match self {
            KeyRef::Int(i) => state.write_u64(*i as u64),
            KeyRef::Str(s) => state.write(s),
        }
    }
}

impl Equivalent<ArrayKey> for KeyRef<'_> {
    /// Whether this borrowed key equals `key`.
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

    /// The key, owned.
    pub fn to_owned(self) -> ArrayKey {
        match self {
            KeyRef::Int(i) => ArrayKey::Int(i),
            KeyRef::Str(s) => ArrayKey::Str(Str::copy_from(s)),
        }
    }
}

impl fmt::Debug for ArrayKey {
    /// Writes the integer, or the string quoted.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            ArrayKey::Int(i) => write!(f, "{i}"),
            ArrayKey::Str(s) => write!(f, "{s:?}"),
        }
    }
}

impl From<i64> for ArrayKey {
    /// The integer key `i`.
    fn from(i: i64) -> Self {
        ArrayKey::Int(i)
    }
}

/// A string key, normalised like `$a["..."]`.
impl From<&str> for ArrayKey {
    /// The key `$a["..."]` uses: canonical integer strings become integer keys.
    fn from(s: &str) -> Self {
        ArrayKey::normalize(Str::from(s))
    }
}

/// `$a[] = $v` failed: the next integer key would be past `PHP_INT_MAX`
/// ("Cannot add element to the array as the next element is already occupied").
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ArrayFull;

impl fmt::Display for ArrayFull {
    /// Writes PHP's error message.
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
///
/// Copy-on-write, like the engine's arrays: a clone shares the elements
/// (an atomic reference count, so values can cross threads) and the first
/// mutation of a shared array copies it. Mutating a clone never changes the
/// original. A new empty array allocates nothing.
pub struct Array<X: Extension = Never> {
    /// `None`: a new empty array (an empty packed list).
    repr: Option<Arc<Repr<X>>>,
}

impl<X: Extension> Drop for Array<X> {
    /// Frees the elements without recursion, so arrays nested thousands of
    /// levels deep (which the decoders accept) do not exhaust the stack.
    fn drop(&mut self) {
        let Some(repr) = self.repr.take().and_then(Arc::into_inner) else { return };
        let mut pending: Vec<Repr<X>> = Vec::new();
        let mut current = repr;
        loop {
            let mut detach = |v: &mut Value<X>| {
                if let Value::Array(a) | Value::Object(a) = v
                    && let Some(inner) = a.repr.take().and_then(Arc::into_inner)
                {
                    pending.push(inner);
                }
            };
            match &mut current {
                Repr::Packed(v) => v.iter_mut().for_each(&mut detach),
                Repr::Hash { map, .. } => map.for_each_value(&mut detach),
            }
            drop(current);
            match pending.pop() {
                Some(next) => current = next,
                None => return,
            }
        }
    }
}

impl<X: Extension> Clone for Array<X> {
    /// Shares the elements (copied on the first mutation of either side).
    fn clone(&self) -> Self {
        Array { repr: self.repr.clone() }
    }
}

/// The large table: insertion-ordered, with a fast hasher seeded randomly
/// (keys come from requests).
type Map<X> = IndexMap<ArrayKey, Value<X>, foldhash::fast::RandomState>;

/// Up to this many entries, a hash array's table is a list searched in
/// order: no index to allocate and no keys to hash.
const SMALL: usize = 8;

/// The hash representation's entries, in order.
#[derive(Clone)]
enum Table<X: Extension> {
    /// At most [`SMALL`] entries.
    Small(Vec<(ArrayKey, Value<X>)>),
    Large(Map<X>),
}

impl<X: Extension> Table<X> {
    fn with_capacity(n: usize) -> Self {
        if n <= SMALL { Table::Small(Vec::with_capacity(n)) } else { Table::Large(map_with_capacity(n)) }
    }

    fn len(&self) -> usize {
        match self {
            Table::Small(v) => v.len(),
            Table::Large(m) => m.len(),
        }
    }

    fn position(v: &[(ArrayKey, Value<X>)], k: KeyRef<'_>) -> Option<usize> {
        v.iter().position(|(key, _)| key.as_ref() == k)
    }

    fn get(&self, k: KeyRef<'_>) -> Option<&Value<X>> {
        match self {
            Table::Small(v) => Self::position(v, k).map(|i| &v[i].1),
            Table::Large(m) => m.get(&k),
        }
    }

    fn get_mut(&mut self, k: KeyRef<'_>) -> Option<&mut Value<X>> {
        match self {
            Table::Small(v) => Self::position(v, k).map(|i| &mut v[i].1),
            Table::Large(m) => m.get_mut(&k),
        }
    }

    fn contains_key(&self, k: KeyRef<'_>) -> bool {
        self.get(k).is_some()
    }

    /// Sets `k` (in place when it exists, at the end otherwise); returns the previous value.
    fn insert(&mut self, k: ArrayKey, value: Value<X>) -> Option<Value<X>> {
        match self {
            Table::Small(v) => {
                if let Some(i) = Self::position(v, k.as_ref()) {
                    return Some(std::mem::replace(&mut v[i].1, value));
                }
                if v.len() < SMALL {
                    v.push((k, value));
                    return None;
                }
                let mut m = map_with_capacity(SMALL * 2);
                m.extend(std::mem::take(v));
                m.insert(k, value);
                *self = Table::Large(m);
                None
            }
            Table::Large(m) => m.insert(k, value),
        }
    }

    /// Removes `k`, shifting the entries after it.
    fn shift_remove(&mut self, k: &ArrayKey) -> Option<Value<X>> {
        match self {
            Table::Small(v) => Self::position(v, k.as_ref()).map(|i| v.remove(i).1),
            Table::Large(m) => m.shift_remove(k),
        }
    }

    fn last_key(&self) -> Option<&ArrayKey> {
        match self {
            Table::Small(v) => v.last().map(|(k, _)| k),
            Table::Large(m) => m.last().map(|(k, _)| k),
        }
    }

    fn reserve(&mut self, additional: usize) {
        match self {
            Table::Small(v) if v.len() + additional <= SMALL => v.reserve(additional),
            Table::Small(v) => {
                let mut m = map_with_capacity(v.len() + additional);
                m.extend(std::mem::take(v));
                *self = Table::Large(m);
            }
            Table::Large(m) => m.reserve(additional),
        }
    }

    /// Keeps the entries `keep` accepts, in order.
    fn retain(&mut self, mut keep: impl FnMut() -> bool) {
        match self {
            Table::Small(v) => v.retain(|_| keep()),
            Table::Large(m) => m.retain(|_, _| keep()),
        }
    }

    /// Calls `f` with each value.
    fn for_each_value(&mut self, f: impl FnMut(&mut Value<X>)) {
        match self {
            Table::Small(v) => v.iter_mut().map(|(_, v)| v).for_each(f),
            Table::Large(m) => m.values_mut().for_each(f),
        }
    }
}

#[derive(Clone)]
enum Repr<X: Extension> {
    Packed(Vec<Value<X>>),
    Hash {
        map: Table<X>,
        /// `nNextFreeElement`: `None` until an integer key is used.
        next: Option<i64>,
        /// Set while the engine would keep the array packed.
        packed: Option<Packed>,
    },
}

impl<X: Extension> Repr<X> {
    /// The hash representation (a packed `Vec` becomes an engine-packed hash).
    fn hash(&mut self) -> (&mut Table<X>, &mut Option<i64>, &mut Option<Packed>) {
        let repr = self;
        if let Repr::Packed(v) = repr {
            let next = if v.is_empty() { None } else { Some(v.len() as i64) };
            let packed = Some(Packed { used: v.len() as u64, table: table_size(v.len()) });
            // The room the list had, and at least room for the entry being added.
            let table = Table::with_capacity(v.capacity().max(v.len() + 1));
            let entries = std::mem::take(v).into_iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v));
            let map = match table {
                Table::Small(mut t) => {
                    t.extend(entries);
                    Table::Small(t)
                }
                Table::Large(mut m) => {
                    m.extend(entries);
                    Table::Large(m)
                }
            };
            *repr = Repr::Hash { map, next, packed };
        }
        match repr {
            Repr::Hash { map, next, packed } => (map, next, packed),
            Repr::Packed(_) => unreachable!(),
        }
    }

    /// `$a[$k] = $v` (the key as given, see [`ArrayKey::normalize`]): an
    /// existing key keeps its position. Returns the previous value.
    fn set(&mut self, k: ArrayKey, v: Value<X>) -> Option<Value<X>> {
        if let (Repr::Packed(list), ArrayKey::Int(i)) = (&mut *self, &k)
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
                if let Some(old) = map.get_mut(KeyRef::Int(h)) {
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
}

/// The elements, borrowed.
enum View<'a, X: Extension> {
    Packed(&'a [Value<X>]),
    Hash(&'a Table<X>),
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

/// An empty table with room for `n` entries.
fn map_with_capacity<X: Extension>(n: usize) -> Map<X> {
    IndexMap::with_capacity_and_hasher(n, foldhash::fast::RandomState::default())
}

impl<X: Extension> Default for Array<X> {
    /// An empty array.
    fn default() -> Self {
        Self::new()
    }
}

impl<X: Extension> Array<X> {
    /// An empty array.
    pub const fn new() -> Self {
        Array { repr: None }
    }

    /// An empty array with room for `n` list elements.
    pub fn with_capacity(n: usize) -> Self {
        if n == 0 { Self::new() } else { Self::from_list(Vec::with_capacity(n)) }
    }

    /// `[$v0, $v1, ...]`.
    pub fn from_list(v: Vec<Value<X>>) -> Self {
        Array { repr: Some(Arc::new(Repr::Packed(v))) }
    }

    /// An array with these entries (in order, keys as given) and this next free index.
    pub fn from_entries(entries: impl IntoIterator<Item = (ArrayKey, Value<X>)>, next: Option<i64>) -> Self {
        let iter = entries.into_iter();
        let mut map = Table::with_capacity(iter.size_hint().0);
        for (k, v) in iter {
            map.insert(k, v);
        }
        Array { repr: Some(Arc::new(Repr::Hash { map, next, packed: None })) }
    }

    fn view(&self) -> View<'_, X> {
        match self.repr.as_deref() {
            None => View::Packed(&[]),
            Some(Repr::Packed(v)) => View::Packed(v),
            Some(Repr::Hash { map, .. }) => View::Hash(map),
        }
    }

    /// The elements, unshared (copied first when a clone shares them).
    fn repr_mut(&mut self) -> &mut Repr<X> {
        Arc::make_mut(self.repr.get_or_insert_with(|| Arc::new(Repr::Packed(Vec::new()))))
    }

    /// Reserves room for `additional` more elements (nothing observable changes).
    pub fn reserve(&mut self, additional: usize) {
        if additional == 0 {
            return;
        }
        match self.repr_mut() {
            Repr::Packed(v) => v.reserve(additional),
            Repr::Hash { map, .. } => map.reserve(additional),
        }
    }

    /// `array_is_list()`: keys are exactly `0, 1, 2, ...` in order.
    pub fn is_list(&self) -> bool {
        match self.view() {
            View::Packed(_) => true,
            View::Hash(_) => self.keys().enumerate().all(|(i, k)| k == KeyRef::Int(i as i64)),
        }
    }

    /// The number of elements.
    pub fn len(&self) -> usize {
        match self.view() {
            View::Packed(v) => v.len(),
            View::Hash(map) => map.len(),
        }
    }

    /// Whether the array has no elements.
    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// The key `push` would use, as PHP tracks it (`None`: no integer key yet, so `0`).
    pub fn next_index(&self) -> Option<i64> {
        match self.repr.as_deref() {
            None => None,
            Some(Repr::Packed(v)) if v.is_empty() => None,
            Some(Repr::Packed(v)) => Some(v.len() as i64),
            Some(Repr::Hash { next, .. }) => *next,
        }
    }

    /// The value at `k`, if any (the key as given, not normalised).
    pub fn get(&self, k: &ArrayKey) -> Option<&Value<X>> {
        self.get_ref(k.as_ref())
    }

    /// The value at a borrowed key, if any (the key as given, not normalised).
    pub fn get_ref(&self, k: KeyRef<'_>) -> Option<&Value<X>> {
        match self.view() {
            View::Packed(v) => match k {
                KeyRef::Int(i) => usize::try_from(i).ok().and_then(|i| v.get(i)),
                KeyRef::Str(_) => None,
            },
            View::Hash(map) => map.get(k),
        }
    }

    /// `$a["..."]`: the key is normalised (`"7"` finds `7`).
    pub fn get_str(&self, k: &str) -> Option<&Value<X>> {
        self.get_ref(KeyRef::normalize(k.as_bytes()))
    }

    /// The value at `k`, mutably, if any (a shared array is copied first).
    pub fn get_mut(&mut self, k: &ArrayKey) -> Option<&mut Value<X>> {
        if !self.contains_key(k) {
            return None;
        }
        match self.repr_mut() {
            Repr::Packed(v) => match k {
                ArrayKey::Int(i) => usize::try_from(*i).ok().and_then(|i| v.get_mut(i)),
                ArrayKey::Str(_) => None,
            },
            Repr::Hash { map, .. } => map.get_mut(k.as_ref()),
        }
    }

    /// Whether `k` is set.
    pub fn contains_key(&self, k: &ArrayKey) -> bool {
        self.get(k).is_some()
    }

    /// The hash representation (a packed `Vec` becomes an engine-packed hash).
    fn hash(&mut self) -> (&mut Table<X>, &mut Option<i64>, &mut Option<Packed>) {
        self.repr_mut().hash()
    }

    /// `$a[$k] = $v` (the key as given, see [`ArrayKey::normalize`]): an
    /// existing key keeps its position. Returns the previous value.
    pub fn set(&mut self, k: ArrayKey, v: Value<X>) -> Option<Value<X>> {
        self.repr_mut().set(k, v)
    }

    /// `$a[] = $v`.
    pub fn push(&mut self, v: Value<X>) -> Result<(), ArrayFull> {
        if let Repr::Packed(list) = self.repr_mut() {
            list.push(v);
            return Ok(());
        }
        let (map, next, _) = self.hash();
        let key = next.unwrap_or(0);
        if map.contains_key(KeyRef::Int(key)) {
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
            p.used = match map.last_key() {
                Some(ArrayKey::Int(last)) => *last as u64 + 1,
                _ => 0,
            };
        }
        old
    }

    /// The entries in order, with borrowed keys.
    pub fn iter(&self) -> Iter<'_, X> {
        match self.view() {
            View::Packed(v) => Iter::Packed(v.iter().enumerate()),
            View::Hash(Table::Small(v)) => Iter::Small(v.iter()),
            View::Hash(Table::Large(m)) => Iter::Hash(m.iter()),
        }
    }

    /// The values in order.
    pub fn values(&self) -> impl ExactSizeIterator<Item = &Value<X>> + DoubleEndedIterator {
        self.iter().map(|(_, v)| v)
    }

    /// The keys in order.
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
        map.retain(|| keep.next().unwrap_or(true));
        if let Some(p) = packed {
            p.used = match map.last_key() {
                Some(ArrayKey::Int(last)) => *last as u64 + 1,
                _ => 0,
            };
        }
    }
}

/// Entries of an [`Array`] in order.
pub enum Iter<'a, X: Extension> {
    /// Over a packed array: the keys are the positions.
    Packed(std::iter::Enumerate<std::slice::Iter<'a, Value<X>>>),
    /// Over a hash array.
    Hash(indexmap::map::Iter<'a, ArrayKey, Value<X>>),
    /// Over a small hash array.
    Small(std::slice::Iter<'a, (ArrayKey, Value<X>)>),
}

impl<'a, X: Extension> Iterator for Iter<'a, X> {
    type Item = (KeyRef<'a>, &'a Value<X>);

    /// The next entry.
    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Iter::Packed(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            Iter::Hash(it) => it.next().map(|(k, v)| (k.as_ref(), v)),
            Iter::Small(it) => it.next().map(|(k, v)| (k.as_ref(), v)),
        }
    }

    /// The exact number of entries left.
    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            Iter::Packed(it) => it.size_hint(),
            Iter::Hash(it) => it.size_hint(),
            Iter::Small(it) => it.size_hint(),
        }
    }
}

impl<X: Extension> DoubleEndedIterator for Iter<'_, X> {
    /// The last entry left.
    fn next_back(&mut self) -> Option<Self::Item> {
        match self {
            Iter::Packed(it) => it.next_back().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            Iter::Hash(it) => it.next_back().map(|(k, v)| (k.as_ref(), v)),
            Iter::Small(it) => it.next_back().map(|(k, v)| (k.as_ref(), v)),
        }
    }
}

impl<X: Extension> ExactSizeIterator for Iter<'_, X> {}

impl<X: Extension> IntoIterator for Array<X> {
    type Item = (ArrayKey, Value<X>);
    type IntoIter = Box<dyn Iterator<Item = (ArrayKey, Value<X>)>>;

    /// The entries in order, with owned keys.
    fn into_iter(self) -> Self::IntoIter {
        // A shared array is copied.
        let mut this = self;
        match this.repr.take().map(Arc::unwrap_or_clone) {
            None => Box::new(std::iter::empty()),
            Some(Repr::Packed(v)) => Box::new(v.into_iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v))),
            Some(Repr::Hash { map: Table::Small(v), .. }) => Box::new(v.into_iter()),
            Some(Repr::Hash { map: Table::Large(m), .. }) => Box::new(m.into_iter()),
        }
    }
}

impl<'a, X: Extension> IntoIterator for &'a Array<X> {
    type Item = (KeyRef<'a>, &'a Value<X>);
    type IntoIter = Iter<'a, X>;

    /// The entries in order, with borrowed keys.
    fn into_iter(self) -> Self::IntoIter {
        self.iter()
    }
}

/// `$a[$k] = $v` for each pair.
impl<X: Extension> FromIterator<(ArrayKey, Value<X>)> for Array<X> {
    /// An array with each pair assigned in order, as `$a[$k] = $v`.
    ///
    /// Built unshared and presized: a list for an integer first key, a hash
    /// for a string one (what assigning to an empty array leads to).
    fn from_iter<T: IntoIterator<Item = (ArrayKey, Value<X>)>>(iter: T) -> Self {
        let mut iter = iter.into_iter();
        let Some((k, v)) = iter.next() else { return Array::new() };
        let n = iter.size_hint().0 + 1;
        let mut repr = match k {
            ArrayKey::Int(_) => Repr::Packed(Vec::with_capacity(n)),
            ArrayKey::Str(_) => Repr::Hash { map: Table::with_capacity(n), next: None, packed: None },
        };
        repr.set(k, v);
        for (k, v) in iter {
            repr.set(k, v);
        }
        Array { repr: Some(Arc::new(repr)) }
    }
}

/// An array assigned entry by entry, as [`FromIterator`] builds it, for
/// the decoders: they read the entry count first, so the table is sized
/// once, and each entry goes straight into it.
pub(crate) struct Builder<X: Extension = Never> {
    /// An empty list (no allocation) until the first entry decides.
    repr: Repr<X>,
    /// Room to make when the first entry decides the representation.
    capacity: usize,
}

impl<X: Extension> Builder<X> {
    /// An empty array with room for `capacity` entries.
    pub(crate) fn new(capacity: usize) -> Self {
        Builder { repr: Repr::Packed(Vec::new()), capacity }
    }

    /// `$a[$k] = $v` (the key as given).
    #[inline]
    pub(crate) fn set(&mut self, k: ArrayKey, v: Value<X>) {
        match (&mut self.repr, k) {
            // A string key on a hash only goes into its table.
            (Repr::Hash { map, packed, .. }, k @ ArrayKey::Str(_)) => {
                *packed = None;
                map.insert(k, v);
            }
            // The next element of a list.
            (Repr::Packed(list), ArrayKey::Int(i)) if i as u64 == list.len() as u64 => {
                if list.capacity() == 0 {
                    list.reserve_exact(self.capacity.max(1));
                }
                list.push(v);
            }
            // A string key first: a hash.
            (Repr::Packed(list), k @ ArrayKey::Str(_)) if list.is_empty() => {
                let mut map = Table::with_capacity(self.capacity);
                map.insert(k, v);
                self.repr = Repr::Hash { map, next: None, packed: None };
            }
            (repr, k) => {
                repr.set(k, v);
            }
        }
    }

    /// The array.
    pub(crate) fn finish(self) -> Array<X> {
        match self.repr {
            Repr::Packed(list) if list.capacity() == 0 => Array::new(),
            repr => Array { repr: Some(Arc::new(repr)) },
        }
    }
}

/// A list.
impl<X: Extension> FromIterator<Value<X>> for Array<X> {
    /// A list of the values.
    fn from_iter<T: IntoIterator<Item = Value<X>>>(iter: T) -> Self {
        Array::from_list(iter.into_iter().collect())
    }
}

/// The same entries in the same order (structural, not PHP's `==`).
impl<X: Extension> PartialEq for Array<X> {
    /// Whether both have the same entries in the same order.
    fn eq(&self, other: &Self) -> bool {
        self.len() == other.len() && self.iter().zip(other.iter()).all(|(a, b)| a == b)
    }
}

impl<X: Extension> fmt::Debug for Array<X> {
    /// Writes the entries as a map.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_map().entries(self.iter()).finish()
    }
}

impl ArrayKey {
    /// The key PHP uses for the string offset `key` (`$a["7"]` is `$a[7]`):
    /// [`ArrayKey::normalize`] of a copy of `key`.
    ///
    /// ```
    /// use php_std::ArrayKey;
    ///
    /// assert_eq!(ArrayKey::from_bytes(b"7"), ArrayKey::Int(7));
    /// assert_eq!(ArrayKey::from_bytes(b"07"), ArrayKey::Str("07".into()));
    /// ```
    pub fn from_bytes(key: &[u8]) -> Self {
        match numeric_key(key) {
            Some(i) => ArrayKey::Int(i),
            None => ArrayKey::Str(Str::copy_from(key)),
        }
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
    fn clones_never_alias() {
        let original: A = [Value::Int(0), Value::Int(1)].into_iter().collect();
        let mut copy = original.clone();
        copy.set("k".into(), Value::Null);
        copy.remove(&ArrayKey::Int(0));
        *copy.get_mut(&ArrayKey::Int(1)).unwrap() = Value::Bool(true);
        assert_eq!(keys(&original), vec![ArrayKey::Int(0), ArrayKey::Int(1)]);
        assert_eq!(original.get(&ArrayKey::Int(1)), Some(&Value::Int(1)));
        let mut again = copy.clone();
        again.push(Value::Null).unwrap();
        assert_eq!(copy.len(), 2);
        assert_eq!(again.len(), 3);
        // Deep nesting is freed without recursion.
        let mut deep = A::new();
        for _ in 0..200_000 {
            deep = [Value::Array(deep)].into_iter().collect();
        }
        drop(deep);
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
