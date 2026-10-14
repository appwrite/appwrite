//! [`Array`]: a PHP array (an ordered map with integer and string keys).

use std::fmt;
use std::hash::{BuildHasher, Hash, Hasher};
use std::sync::atomic::{AtomicU8, Ordering};
use std::sync::{Arc, OnceLock};

use hashbrown::{HashTable, hash_table};

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

/// Up to this many entries, a hash array's table is a list searched in
/// order: no index to allocate and no keys to hash.
const SMALL: usize = 8;

/// Up to this many entries, a table taken as it is (a decoded object) is
/// checked for repeated keys without hashing them, and only indexed once
/// it has been searched [`SCANS`] times: most decoded objects are read a
/// few times, or only iterated.
const LAZY: usize = 64;

/// How many lookups scan an unindexed table before it is indexed.
const SCANS: u8 = 8;

/// The hash representation's entries, in order, and past [`SMALL`] entries
/// an index of their positions by key (made on demand for a table taken
/// as it is, see [`LAZY`]).
///
/// The entries are kept as they were added (an object decoded with its
/// members in order is taken as it is), and the index only holds positions.
struct Table<X: Extension> {
    entries: Vec<(ArrayKey, Value<X>)>,
    /// When set, it holds every entry.
    index: OnceLock<Index>,
    /// Lookups that scanned the entries while unindexed: a hint (readers on
    /// several threads may lose counts), never a correctness concern.
    scans: AtomicU8,
}

impl<X: Extension> Clone for Table<X> {
    /// The same entries, and the index when it is made.
    fn clone(&self) -> Self {
        Table { entries: self.entries.clone(), index: self.index.clone(), scans: AtomicU8::new(0) }
    }
}

/// A summary of a key that equal keys share: its length and its first and
/// last eight bytes (an integer key is its value), read in at most two
/// loads, so most different keys are told apart without comparing them.
#[inline]
fn fingerprint(k: KeyRef<'_>) -> u64 {
    let s = match k {
        KeyRef::Int(i) => return i as u64,
        KeyRef::Str(s) => s,
    };
    let n = s.len();
    let word = |at: usize| u64::from_le_bytes(s[at..at + 8].try_into().unwrap_or_default());
    let half = |at: usize| u64::from(u32::from_le_bytes(s[at..at + 4].try_into().unwrap_or_default()));
    let (head, tail) = if n >= 8 {
        (word(0), word(n - 8))
    } else if n >= 4 {
        (half(0), half(n - 4))
    } else if n > 0 {
        (u64::from(s[0]) | u64::from(s[n / 2]) << 8, u64::from(s[n - 1]))
    } else {
        (0, 0)
    };
    head ^ tail.rotate_left(29) ^ (n as u64).wrapping_mul(0x9e37_79b9_7f4a_7c15)
}

/// Positions of a table's entries by key, with a fast hasher seeded randomly
/// (keys come from requests).
#[derive(Clone)]
struct Index {
    positions: HashTable<usize>,
    hasher: foldhash::fast::RandomState,
}

impl Index {
    fn with_capacity(n: usize) -> Self {
        Index { positions: HashTable::with_capacity(n), hasher: Default::default() }
    }

    fn hash(&self, k: KeyRef<'_>) -> u64 {
        self.hasher.hash_one(k)
    }

    /// The index of `entries`, or `None` when two have the same key.
    fn of<X: Extension>(entries: &[(ArrayKey, Value<X>)], capacity: usize) -> Option<Index> {
        let mut index = Index::with_capacity(capacity);
        for (i, (k, _)) in entries.iter().enumerate() {
            let hash = index.hash(k.as_ref());
            match index.positions.entry(
                hash,
                |&j| entries[j].0 == *k,
                |&j| index.hasher.hash_one(entries[j].0.as_ref()),
            ) {
                hash_table::Entry::Occupied(_) => return None,
                hash_table::Entry::Vacant(slot) => {
                    slot.insert(i);
                }
            }
        }
        Some(index)
    }

    /// The index of `entries`, whose keys are all different.
    fn build<X: Extension>(entries: &[(ArrayKey, Value<X>)], capacity: usize) -> Index {
        let mut index = Index::with_capacity(capacity.max(entries.len()));
        for (i, (k, _)) in entries.iter().enumerate() {
            let hash = index.hash(k.as_ref());
            let hasher = &index.hasher;
            index.positions.insert_unique(hash, i, |&j| hasher.hash_one(entries[j].0.as_ref()));
        }
        index
    }
}

/// Whether no two of `entries` (at most [`LAZY`]) have the same key, telling
/// them apart by [`fingerprint`]; `false` may also mean that two
/// fingerprints are alike, past [`SMALL`] entries.
fn distinct<X: Extension>(entries: &[(ArrayKey, Value<X>)]) -> bool {
    let n = entries.len();
    debug_assert!(n <= LAZY);
    if n <= 4 {
        // A few keys: compared directly (most differ in length).
        return (1..n).all(|i| (0..i).all(|j| entries[i].0 != entries[j].0));
    }
    if n <= SMALL {
        let mut prints = [0u64; SMALL];
        for (print, (k, _)) in prints.iter_mut().zip(entries) {
            *print = fingerprint(k.as_ref());
        }
        // Pairs compared by fingerprint first: only equal fingerprints (equal
        // keys, or rarely keys alike at both ends) compare bytes.
        return (1..n).all(|i| (0..i).all(|j| prints[i] != prints[j] || entries[i].0 != entries[j].0));
    }
    let mut prints = [0u64; LAZY];
    for (print, (k, _)) in prints.iter_mut().zip(entries) {
        *print = fingerprint(k.as_ref());
    }
    // Sorted, equal fingerprints are neighbours.
    let prints = &mut prints[..n];
    prints.sort_unstable();
    prints.windows(2).all(|w| w[0] != w[1])
}

impl<X: Extension> Table<X> {
    fn with_capacity(n: usize) -> Self {
        let index = if n > SMALL { OnceLock::from(Index::with_capacity(n)) } else { OnceLock::new() };
        Table { entries: Vec::with_capacity(n), index, scans: AtomicU8::new(0) }
    }

    /// A table of `entries` as they are, when their keys are all different
    /// (`entries` back otherwise).
    fn of(entries: Vec<(ArrayKey, Value<X>)>) -> Result<Self, Vec<(ArrayKey, Value<X>)>> {
        let n = entries.len();
        if n <= LAZY && distinct(&entries) {
            return Ok(Table { entries, index: OnceLock::new(), scans: AtomicU8::new(0) });
        }
        if n <= SMALL {
            return Err(entries);
        }
        // Many entries, or fingerprints alike: hashed, and indexed.
        match Index::of(&entries, n) {
            Some(index) => Ok(Table { entries, index: OnceLock::from(index), scans: AtomicU8::new(0) }),
            None => Err(entries),
        }
    }

    fn len(&self) -> usize {
        self.entries.len()
    }

    /// The index: `None` for a small table, and for one not indexed yet
    /// that was searched fewer than [`SCANS`] times (made now otherwise).
    #[inline]
    fn index(&self) -> Option<&Index> {
        if let Some(index) = self.index.get() {
            return Some(index);
        }
        if self.entries.len() <= SMALL {
            return None;
        }
        let scans = self.scans.load(Ordering::Relaxed);
        if scans < SCANS {
            self.scans.store(scans + 1, Ordering::Relaxed);
            return None;
        }
        Some(self.index.get_or_init(|| Index::build(&self.entries, 0)))
    }

    /// Where `k` is in the entries.
    fn position(&self, k: KeyRef<'_>) -> Option<usize> {
        match self.index() {
            None => self.entries.iter().position(|(key, _)| key.as_ref() == k),
            Some(index) => index.positions.find(index.hash(k), |&i| self.entries[i].0.as_ref() == k).copied(),
        }
    }

    fn get(&self, k: KeyRef<'_>) -> Option<&Value<X>> {
        self.position(k).map(|i| &self.entries[i].1)
    }

    fn get_mut(&mut self, k: KeyRef<'_>) -> Option<&mut Value<X>> {
        self.position(k).map(|i| &mut self.entries[i].1)
    }

    fn contains_key(&self, k: KeyRef<'_>) -> bool {
        self.position(k).is_some()
    }

    /// Sets `k` (in place when it exists, at the end otherwise); returns the previous value.
    #[inline]
    fn insert(&mut self, k: ArrayKey, value: Value<X>) -> Option<Value<X>> {
        // A table that grows is indexed from [`SMALL`] entries on.
        if self.entries.len() >= SMALL && self.index.get().is_none() {
            self.reindex();
        }
        if let Some(i) = self.position(k.as_ref()) {
            return Some(std::mem::replace(&mut self.entries[i].1, value));
        }
        let i = self.entries.len();
        self.entries.push((k, value));
        if let Some(index) = self.index.get_mut() {
            let entries = &self.entries;
            let hash = index.hash(entries[i].0.as_ref());
            let hasher = &index.hasher;
            index.positions.insert_unique(hash, i, |&j| hasher.hash_one(entries[j].0.as_ref()));
        }
        None
    }

    /// Indexes the entries (their keys are different).
    #[cold]
    fn reindex(&mut self) {
        self.index = OnceLock::from(Index::build(&self.entries, self.entries.capacity().max(SMALL * 2)));
    }

    /// Removes `k`, shifting the entries after it.
    fn shift_remove(&mut self, k: &ArrayKey) -> Option<Value<X>> {
        let i = self.position(k.as_ref())?;
        if let Some(index) = self.index.get_mut() {
            if let Ok(slot) = index.positions.find_entry(index.hasher.hash_one(k.as_ref()), |&j| j == i) {
                slot.remove();
            }
            if i + 1 < self.entries.len() {
                index.positions.iter_mut().filter(|j| **j > i).for_each(|j| *j -= 1);
            }
        }
        Some(self.entries.remove(i).1)
    }

    fn last_key(&self) -> Option<&ArrayKey> {
        self.entries.last().map(|(k, _)| k)
    }

    fn reserve(&mut self, additional: usize) {
        self.entries.reserve(additional);
        match self.index.get_mut() {
            Some(index) => {
                let entries = &self.entries;
                let hasher = &index.hasher;
                index.positions.reserve(additional, |&j| hasher.hash_one(entries[j].0.as_ref()));
            }
            None if self.entries.len() + additional > SMALL => self.reindex(),
            None => {}
        }
    }

    /// Keeps the entries `keep` accepts, in order.
    fn retain(&mut self, mut keep: impl FnMut() -> bool) {
        self.entries.retain(|_| keep());
        if self.index.get().is_some() {
            self.reindex();
        }
    }

    /// Calls `f` with each value.
    fn for_each_value(&mut self, f: impl FnMut(&mut Value<X>)) {
        self.entries.iter_mut().map(|(_, v)| v).for_each(f);
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
            let mut map = Table::with_capacity(v.capacity().max(v.len() + 1));
            for (i, v) in std::mem::take(v).into_iter().enumerate() {
                map.insert(ArrayKey::Int(i as i64), v);
            }
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

    /// An identity for the elements: the same for clones that share them
    /// and never reused while one of them is alive (a write to a shared
    /// array copies it first). `None` for a new empty array.
    pub fn id(&self) -> Option<usize> {
        self.repr.as_ref().map(|r| Arc::as_ptr(r) as usize)
    }

    /// The elements, unshared (copied first when a clone shares them).
    fn repr_mut(&mut self) -> &mut Repr<X> {
        // `zend_array_dup()` of an empty array makes an uninitialized one
        // that keeps the next free index: its first integer key below the
        // minimum table size makes it packed again, setting that index.
        if let Some(r) = &self.repr
            && Arc::strong_count(r) > 1
            && let Repr::Hash { map, next, .. } = &**r
            && map.len() == 0
        {
            let next = *next;
            let packed = Some(Packed { used: 0, table: table_size(0) });
            self.repr = Some(Arc::new(Repr::Hash { map: Table::with_capacity(0), next, packed }));
        }
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

    /// A new array with the same keys, in the same order, and the value `f`
    /// makes of each entry (`array_map()` with one array, which keeps the
    /// keys): the same array in every other respect, next free index
    /// included. No key is copied into a new table or looked up again.
    pub fn map_values(&self, mut f: impl FnMut(KeyRef<'_>, &Value<X>) -> Value<X>) -> Array<X> {
        let Some(repr) = self.repr.as_deref() else { return Array::new() };
        let repr = match repr {
            Repr::Packed(v) => Repr::Packed(v.iter().enumerate().map(|(i, v)| f(KeyRef::Int(i as i64), v)).collect()),
            Repr::Hash { map, next, packed } => Repr::Hash {
                map: Table {
                    entries: map.entries.iter().map(|(k, v)| (k.clone(), f(k.as_ref(), v))).collect(),
                    index: map.index.clone(),
                    scans: AtomicU8::new(0),
                },
                next: *next,
                packed: *packed,
            },
        };
        Array { repr: Some(Arc::new(repr)) }
    }

    /// The entries in order, with borrowed keys.
    pub fn iter(&self) -> Iter<'_, X> {
        match self.view() {
            View::Packed(v) => Iter::Packed(v.iter().enumerate()),
            View::Hash(map) => Iter::Hash(map.entries.iter()),
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
    Hash(std::slice::Iter<'a, (ArrayKey, Value<X>)>),
}

impl<'a, X: Extension> Iterator for Iter<'a, X> {
    type Item = (KeyRef<'a>, &'a Value<X>);

    /// The next entry.
    fn next(&mut self) -> Option<Self::Item> {
        match self {
            Iter::Packed(it) => it.next().map(|(i, v)| (KeyRef::Int(i as i64), v)),
            Iter::Hash(it) => it.next().map(|(k, v)| (k.as_ref(), v)),
        }
    }

    /// The exact number of entries left.
    fn size_hint(&self) -> (usize, Option<usize>) {
        match self {
            Iter::Packed(it) => it.size_hint(),
            Iter::Hash(it) => it.size_hint(),
        }
    }
}

impl<X: Extension> DoubleEndedIterator for Iter<'_, X> {
    /// The last entry left.
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

    /// The entries in order, with owned keys.
    fn into_iter(self) -> Self::IntoIter {
        // A shared array is copied.
        let mut this = self;
        match this.repr.take().map(Arc::unwrap_or_clone) {
            None => Box::new(std::iter::empty()),
            Some(Repr::Packed(v)) => Box::new(v.into_iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v))),
            Some(Repr::Hash { map, .. }) => Box::new(map.entries.into_iter()),
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
        // Taken as they come, and indexed once (or not at all) when the keys
        // are strings and all different; assigned one by one otherwise.
        Self::from_pairs(iter.into_iter().collect())
    }
}

impl<X: Extension> Array<X> {
    /// Each pair assigned in order to a new array, as `$a[$k] = $v`
    /// ([`FromIterator`]). String keys, all different (a decoded object),
    /// make the table as they are, with no copy.
    pub(crate) fn from_pairs(entries: Vec<(ArrayKey, Value<X>)>) -> Self {
        if entries.is_empty() || !entries.iter().all(|(k, _)| matches!(k, ArrayKey::Str(_))) {
            return Self::assign(entries.into_iter());
        }
        match Table::of(entries) {
            Ok(map) => Array { repr: Some(Arc::new(Repr::Hash { map, next: None, packed: None })) },
            Err(entries) => Self::assign(entries.into_iter()),
        }
    }

    /// Each pair assigned in order to a new array, as `$a[$k] = $v`.
    fn assign(mut iter: impl Iterator<Item = (ArrayKey, Value<X>)>) -> Self {
        let Some((k, v)) = iter.next() else { return Array::new() };
        let n = iter.size_hint().0 + 1;
        let mut repr = match k {
            ArrayKey::Int(_) => Repr::Packed(Vec::with_capacity(n)),
            ArrayKey::Str(_) => Repr::Hash { map: Table::with_capacity(n), next: None, packed: None },
        };
        repr.set(k, v);
        for (k, v) in iter {
            match (&mut repr, k) {
                // A string key on a hash only goes into its table.
                (Repr::Hash { map, packed, .. }, k @ ArrayKey::Str(_)) => {
                    *packed = None;
                    map.insert(k, v);
                }
                (repr, k) => {
                    repr.set(k, v);
                }
            }
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
    fn large_tables_find_keys_after_removals_and_repeats() {
        // Past eight entries the table is indexed: lookups, repeated keys
        // (kept in place, as decoded objects assign them) and removals in the
        // middle must keep every position right.
        let pairs: Vec<(ArrayKey, Value)> =
            (0..20).map(|i| (ArrayKey::from(format!("k{i}").as_str()), Value::Int(i))).collect();
        let mut a = A::from_pairs(pairs.clone());
        assert_eq!(a.len(), 20);
        assert_eq!(a.get_str("k13"), Some(&Value::Int(13)));
        a.remove(&ArrayKey::from("k3"));
        a.remove(&ArrayKey::from("k19"));
        a.set("k3".into(), Value::Null);
        assert_eq!(a.get_str("k4"), Some(&Value::Int(4)));
        assert_eq!(a.get_str("k18"), Some(&Value::Int(18)));
        assert_eq!(keys(&a).last(), Some(&ArrayKey::from("k3")));
        assert_eq!(a.get_str("k19"), None);
        let mut repeated = pairs;
        repeated.push((ArrayKey::from("k5"), Value::Bool(true)));
        let b = A::from_pairs(repeated);
        assert_eq!(b.len(), 20);
        assert_eq!(keys(&b)[5], ArrayKey::from("k5"));
        assert_eq!(b.get_str("k5"), Some(&Value::Bool(true)));
        let mut grown = A::new();
        for i in 0..12 {
            grown.set(ArrayKey::from(format!("g{i}").as_str()), Value::Int(i));
        }
        grown.retain(|k, _| k != KeyRef::Str(b"g2"));
        assert_eq!(grown.get_str("g11"), Some(&Value::Int(11)));
        assert_eq!(grown.get_str("g2"), None);
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
