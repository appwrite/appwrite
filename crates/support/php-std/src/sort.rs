//! PHP's array sorting and set functions: `sort`, `rsort`, `asort`, `arsort`,
//! `ksort`, `krsort`, `array_unique`, `array_diff`, `array_diff_key`,
//! `array_intersect`, `in_array` and `array_search`, with the `SORT_*` flags.
//!
//! Every function here is checked against the engine by
//! `bin/compat fuzz php-std` (operations `sort.*`).
//!
//! | PHP | Rust |
//! |---|---|
//! | `sort($a, $flags)` / `rsort` | [`sort`] / [`rsort`] |
//! | `asort($a, $flags)` / `arsort` | [`asort`] / [`arsort`] |
//! | `ksort($a, $flags)` / `krsort` | [`ksort`] / [`krsort`] |
//! | `array_unique($a, $flags)` | [`array_unique`] |
//! | `array_diff($a, ...$b)`, `array_diff_key`, `array_intersect` | [`array_diff`], [`array_diff_key`], [`array_intersect`] |
//! | `in_array($n, $h, $strict)`, `array_search` | [`in_array`], [`array_search`] |
//! | `SORT_REGULAR`, `SORT_NUMERIC`, `SORT_STRING`, `SORT_FLAG_CASE`, `SORT_NATURAL`, `SORT_LOCALE_STRING` | [`SortFlags`] |
//!
//! PHP 8 sorts are stable: `zend_sort` (insertion sort up to 16 elements,
//! then a quicksort) with the original position breaking ties. PHP's
//! comparisons are not always transitive (`null == 0`, `null == ""`, but
//! `0 > ""`), so the result depends on the algorithm: [`zend_sort`] is a
//! port of it, comparison for comparison. `SORT_LOCALE_STRING` compares
//! like `strcoll` in the C locale (byte order of the C strings, so up to the
//! first NUL byte), which is what the engine
//! uses without `setlocale`. Functions that turn values into strings fail
//! like PHP when they meet an object (`Error`), so the value sorts and set
//! functions return `Result` (the skeleton in rfc/rust-database.md has `()`).

use std::collections::HashSet;

use crate::format::str_to_float;
use crate::types::value::{binary_strcmp, smart_strcmp};
use crate::types::{Array, ArrayKey, EngineError, Extension, KeyRef, Str, Value};

/// The `$flags` of the sort functions.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SortFlags {
    /// `SORT_REGULAR`: PHP 8 `<=>`.
    Regular,
    /// `SORT_NUMERIC`: as floats.
    Numeric,
    /// `SORT_STRING`: as strings, byte order.
    String,
    /// `SORT_STRING | SORT_FLAG_CASE`: as strings, ASCII case-insensitively.
    StringFoldCase,
    /// `SORT_NATURAL`: `strnatcmp`.
    Natural,
    /// `SORT_NATURAL | SORT_FLAG_CASE`: `strnatcasecmp`.
    NaturalFoldCase,
    /// `SORT_LOCALE_STRING`: `strcoll` (the C locale: byte order).
    Locale,
}

impl SortFlags {
    /// The flags as PHP code passes them (unknown values are `SORT_REGULAR`).
    pub fn from_php(flags: i64) -> Self {
        let case = flags & 8 != 0;
        match flags & !8 {
            1 => SortFlags::Numeric,
            2 if case => SortFlags::StringFoldCase,
            2 => SortFlags::String,
            5 => SortFlags::Locale,
            6 if case => SortFlags::NaturalFoldCase,
            6 => SortFlags::Natural,
            _ => SortFlags::Regular,
        }
    }

    fn by_string(self) -> bool {
        !matches!(self, SortFlags::Regular | SortFlags::Numeric)
    }
}

// ---------------------------------------------------------------------------
// zend_sort (Zend/zend_sort.c)
// ---------------------------------------------------------------------------

fn sort2<T>(v: &mut [T], a: usize, b: usize, cmp: &mut impl FnMut(&T, &T) -> i32) {
    if cmp(&v[a], &v[b]) > 0 {
        v.swap(a, b);
    }
}

fn sort3<T>(v: &mut [T], a: usize, b: usize, c: usize, cmp: &mut impl FnMut(&T, &T) -> i32) {
    if cmp(&v[a], &v[b]) <= 0 {
        if cmp(&v[b], &v[c]) <= 0 {
            return;
        }
        v.swap(b, c);
        if cmp(&v[a], &v[b]) > 0 {
            v.swap(a, b);
        }
        return;
    }
    if cmp(&v[c], &v[b]) <= 0 {
        v.swap(a, c);
        return;
    }
    v.swap(a, b);
    if cmp(&v[b], &v[c]) > 0 {
        v.swap(b, c);
    }
}

fn sort4<T>(v: &mut [T], a: usize, b: usize, c: usize, d: usize, cmp: &mut impl FnMut(&T, &T) -> i32) {
    sort3(v, a, b, c, cmp);
    if cmp(&v[c], &v[d]) > 0 {
        v.swap(c, d);
        if cmp(&v[b], &v[c]) > 0 {
            v.swap(b, c);
            if cmp(&v[a], &v[b]) > 0 {
                v.swap(a, b);
            }
        }
    }
}

#[allow(clippy::too_many_arguments)]
fn sort5<T>(v: &mut [T], a: usize, b: usize, c: usize, d: usize, e: usize, cmp: &mut impl FnMut(&T, &T) -> i32) {
    sort4(v, a, b, c, d, cmp);
    if cmp(&v[d], &v[e]) > 0 {
        v.swap(d, e);
        if cmp(&v[c], &v[d]) > 0 {
            v.swap(c, d);
            if cmp(&v[b], &v[c]) > 0 {
                v.swap(b, c);
                if cmp(&v[a], &v[b]) > 0 {
                    v.swap(a, b);
                }
            }
        }
    }
}

/// Moves `v[i]` down to `j` (`swp(k, k - 1)` for `k` from `i` to `j + 1`).
fn rotate_into<T>(v: &mut [T], j: usize, i: usize) {
    v[j..=i].rotate_right(1);
}

/// `zend_insert_sort`.
fn insert_sort<T>(v: &mut [T], cmp: &mut impl FnMut(&T, &T) -> i32) {
    let n = v.len();
    match n {
        0 | 1 => {}
        2 => sort2(v, 0, 1, cmp),
        3 => sort3(v, 0, 1, 2, cmp),
        4 => sort4(v, 0, 1, 2, 3, cmp),
        5 => sort5(v, 0, 1, 2, 3, 4, cmp),
        _ => {
            for i in 1..6 {
                let mut j = i - 1;
                if cmp(&v[j], &v[i]) <= 0 {
                    continue;
                }
                while j != 0 {
                    j -= 1;
                    if cmp(&v[j], &v[i]) <= 0 {
                        j += 1;
                        break;
                    }
                }
                rotate_into(v, j, i);
            }
            for i in 6..n {
                let mut j = i - 1;
                if cmp(&v[j], &v[i]) <= 0 {
                    continue;
                }
                loop {
                    j -= 2;
                    if cmp(&v[j], &v[i]) <= 0 {
                        j += 1;
                        if cmp(&v[j], &v[i]) <= 0 {
                            j += 1;
                        }
                        break;
                    }
                    if j == 0 {
                        break;
                    }
                    if j == 1 {
                        j -= 1;
                        if cmp(&v[i], &v[j]) > 0 {
                            j += 1;
                        }
                        break;
                    }
                }
                rotate_into(v, j, i);
            }
        }
    }
}

/// `zend_sort`: the engine's hybrid insertion sort / quicksort. `cmp`
/// returns a C comparison result; only `> 0` matters, as in the engine.
pub fn zend_sort<T>(v: &mut [T], cmp: &mut impl FnMut(&T, &T) -> i32) {
    let mut s = v;
    loop {
        let n = s.len();
        if n <= 16 {
            insert_sort(s, cmp);
            return;
        }
        let offset = n >> 1;
        let pivot = offset;
        if n >> 10 != 0 {
            let delta = offset >> 1;
            sort5(s, 0, delta, pivot, pivot + delta, n - 1, cmp);
        } else {
            sort3(s, 0, pivot, n - 1, cmp);
        }
        s.swap(1, pivot);
        let pivot = 1;
        let mut i = pivot + 1;
        let mut j = n - 1;
        'partition: loop {
            while cmp(&s[pivot], &s[i]) > 0 {
                i += 1;
                if i == j {
                    break 'partition;
                }
            }
            j -= 1;
            if j == i {
                break 'partition;
            }
            while cmp(&s[j], &s[pivot]) > 0 {
                j -= 1;
                if j == i {
                    break 'partition;
                }
            }
            s.swap(i, j);
            i += 1;
            if i == j {
                break 'partition;
            }
        }
        s.swap(pivot, i - 1);
        let rest = s;
        if i - 1 < n - i {
            let (left, right) = rest.split_at_mut(i);
            zend_sort(&mut left[..i - 1], cmp);
            s = right;
        } else {
            let (left, right) = rest.split_at_mut(i);
            zend_sort(right, cmp);
            s = &mut left[..i - 1];
        }
    }
}

// ---------------------------------------------------------------------------
// String comparisons
// ---------------------------------------------------------------------------

/// `zend_binary_strcasecmp_l`: ASCII case-insensitive.
pub fn strcasecmp(a: &[u8], b: &[u8]) -> i32 {
    for (x, y) in a.iter().zip(b) {
        let (x, y) = (x.to_ascii_lowercase(), y.to_ascii_lowercase());
        if x != y {
            return i32::from(x) - i32::from(y);
        }
    }
    (a.len() as i64 - b.len() as i64).signum() as i32
}

/// `strnatcmp_ex`: natural order (`strnatcmp`, or `strnatcasecmp` with `fold_case`).
pub fn strnatcmp(a: &[u8], b: &[u8], fold_case: bool) -> i32 {
    // The engine reads the NUL terminator past the end.
    let at = |s: &[u8], i: usize| s.get(i).copied().unwrap_or(0);
    let digit = |c: u8| c.is_ascii_digit();
    let space = |c: u8| matches!(c, b' ' | b'\t' | b'\n' | 0x0b | 0x0c | b'\r');
    if a.is_empty() || b.is_empty() {
        return if a.len() == b.len() {
            0
        } else if a.len() > b.len() {
            1
        } else {
            -1
        };
    }
    let (aend, bend) = (a.len(), b.len());
    let (mut ap, mut bp) = (0usize, 0usize);
    let (mut ca, mut cb) = (a[0], b[0]);
    while ca == b'0' && ap + 1 < aend && digit(a[ap + 1]) {
        ap += 1;
        ca = a[ap];
    }
    while cb == b'0' && bp + 1 < bend && digit(b[bp + 1]) {
        bp += 1;
        cb = b[bp];
    }
    loop {
        while space(ca) {
            ap += 1;
            ca = at(a, ap);
        }
        while space(cb) {
            bp += 1;
            cb = at(b, bp);
        }
        if digit(ca) && digit(cb) {
            let fractional = ca == b'0' || cb == b'0';
            let result =
                if fractional { compare_left(a, &mut ap, b, &mut bp) } else { compare_right(a, &mut ap, b, &mut bp) };
            if result != 0 {
                return result;
            } else if ap == aend && bp == bend {
                return 0;
            } else if ap == aend {
                return -1;
            } else if bp == bend {
                return 1;
            }
            ca = at(a, ap);
            cb = at(b, bp);
        }
        if fold_case {
            ca = ca.to_ascii_uppercase();
            cb = cb.to_ascii_uppercase();
        }
        if ca < cb {
            return -1;
        } else if ca > cb {
            return 1;
        }
        ap += 1;
        bp += 1;
        if ap >= aend && bp >= bend {
            return 0;
        } else if ap >= aend {
            return -1;
        } else if bp >= bend {
            return 1;
        }
        ca = at(a, ap);
        cb = at(b, bp);
    }
}

fn is_digit_at(s: &[u8], i: usize) -> bool {
    s.get(i).is_some_and(u8::is_ascii_digit)
}

/// `compare_right`: right-aligned numbers, the longest run wins.
fn compare_right(a: &[u8], ap: &mut usize, b: &[u8], bp: &mut usize) -> i32 {
    let mut bias = 0;
    loop {
        let (da, db) = (is_digit_at(a, *ap), is_digit_at(b, *bp));
        if !da && !db {
            return bias;
        } else if !da {
            return -1;
        } else if !db {
            return 1;
        } else if a[*ap] < b[*bp] {
            if bias == 0 {
                bias = -1;
            }
        } else if a[*ap] > b[*bp] && bias == 0 {
            bias = 1;
        }
        *ap += 1;
        *bp += 1;
    }
}

/// `compare_left`: left-aligned numbers, the first difference wins.
fn compare_left(a: &[u8], ap: &mut usize, b: &[u8], bp: &mut usize) -> i32 {
    loop {
        let (da, db) = (is_digit_at(a, *ap), is_digit_at(b, *bp));
        if !da && !db {
            return 0;
        } else if !da {
            return -1;
        } else if !db {
            return 1;
        } else if a[*ap] < b[*bp] {
            return -1;
        } else if a[*ap] > b[*bp] {
            return 1;
        }
        *ap += 1;
        *bp += 1;
    }
}

/// `strcoll` in the C locale: byte order of the C strings (up to the first NUL).
pub fn strcoll(a: &[u8], b: &[u8]) -> i32 {
    let c = |s: &[u8]| -> usize { s.iter().position(|&b| b == 0).unwrap_or(s.len()) };
    binary_strcmp(&a[..c(a)], &b[..c(b)])
}

/// Compares two strings as a string-based flag does.
fn compare_strings(flags: SortFlags, a: &[u8], b: &[u8]) -> i32 {
    match flags {
        SortFlags::StringFoldCase => strcasecmp(a, b),
        SortFlags::Natural => strnatcmp(a, b, false),
        SortFlags::NaturalFoldCase => strnatcmp(a, b, true),
        SortFlags::Locale => strcoll(a, b),
        _ => binary_strcmp(a, b),
    }
}

// ---------------------------------------------------------------------------
// Values and keys as the comparators see them
// ---------------------------------------------------------------------------

/// A value prepared for one flag: its string or float form, computed once.
enum Form<'a, X: Extension> {
    Value(&'a Value<X>),
    Number(f64),
    /// The string; `None` for an object (the engine throws and uses "").
    Text(Option<Str>),
}

fn form<X: Extension>(v: &Value<X>, flags: SortFlags) -> Form<'_, X> {
    match flags {
        SortFlags::Regular => Form::Value(v),
        SortFlags::Numeric => Form::Number(v.to_double()),
        _ => Form::Text(v.to_php_string().ok()),
    }
}

/// The unstable data comparison (`php_array_data_compare_*_unstable_i`).
fn compare_forms<X: Extension>(flags: SortFlags, a: &Form<'_, X>, b: &Form<'_, X>) -> i32 {
    match (a, b) {
        (Form::Value(x), Form::Value(y)) => x.compare(y),
        (Form::Number(x), Form::Number(y)) => {
            if x == y {
                0
            } else if x < y {
                -1
            } else {
                1
            }
        }
        (Form::Text(x), Form::Text(y)) => {
            let (x, y) = (x.as_deref().unwrap_or(b""), y.as_deref().unwrap_or(b""));
            compare_strings(flags, x, y)
        }
        _ => 0,
    }
}

fn object_error<X: Extension>(v: &Value<X>) -> EngineError {
    match v.to_php_string() {
        Err(e) => e,
        Ok(_) => EngineError::new("Error", "unreachable"),
    }
}

/// The error a string-based sort of these values throws (any object, once
/// two or more values are compared).
fn string_error<X: Extension>(flags: SortFlags, a: &Array<X>) -> Result<(), EngineError> {
    if flags.by_string() && a.len() > 1 {
        for v in a.values() {
            if matches!(v, Value::Object(_) | Value::Ext(_)) {
                return Err(object_error(v));
            }
        }
    }
    Ok(())
}

/// `php_array_key_compare_*_unstable_i`.
fn compare_keys(flags: SortFlags, a: KeyRef<'_>, b: KeyRef<'_>) -> i32 {
    let text = |k: KeyRef<'_>| -> Vec<u8> {
        match k {
            KeyRef::Int(i) => i.to_string().into_bytes(),
            KeyRef::Str(s) => s.to_vec(),
        }
    };
    match flags {
        SortFlags::Regular => match (a, b) {
            (KeyRef::Int(x), KeyRef::Int(y)) => {
                if x > y {
                    1
                } else {
                    -1
                }
            }
            (KeyRef::Str(x), KeyRef::Str(y)) => smart_strcmp(x, y),
            _ => {
                let v = |k: KeyRef<'_>| -> Value {
                    match k {
                        KeyRef::Int(i) => Value::Int(i),
                        KeyRef::Str(s) => Value::Str(Str::copy_from(s)),
                    }
                };
                v(a).compare(&v(b))
            }
        },
        SortFlags::Numeric => match (a, b) {
            (KeyRef::Int(x), KeyRef::Int(y)) => {
                if x > y {
                    1
                } else {
                    -1
                }
            }
            _ => {
                let d = |k: KeyRef<'_>| match k {
                    KeyRef::Int(i) => i as f64,
                    KeyRef::Str(s) => str_to_float(s),
                };
                let (x, y) = (d(a), d(b));
                if x == y {
                    0
                } else if x < y {
                    -1
                } else {
                    1
                }
            }
        },
        _ => compare_strings(flags, &text(a), &text(b)),
    }
}

// ---------------------------------------------------------------------------
// sort, rsort, asort, arsort, ksort, krsort
// ---------------------------------------------------------------------------

/// The stable comparator: the unstable result (negated for a reverse
/// sort), then the original position.
fn stable(r: i32, reverse: bool, ia: usize, ib: usize) -> i32 {
    let r = if reverse { -r } else { r };
    if r != 0 { r } else { (ia as i64 - ib as i64).signum() as i32 }
}

fn sort_values<X: Extension>(
    a: &mut Array<X>,
    flags: SortFlags,
    reverse: bool,
    renumber: bool,
) -> Result<(), EngineError> {
    if a.is_empty() || (a.len() == 1 && !renumber) {
        return Ok(());
    }
    string_error(flags, a)?;
    let next = a.next_index();
    let entries: Vec<(ArrayKey, Value<X>)> = std::mem::take(a).into_iter().collect();
    let mut order: Vec<(usize, Form<'_, X>)> =
        entries.iter().enumerate().map(|(i, (_, v))| (i, form(v, flags))).collect();
    zend_sort(&mut order, &mut |x, y| stable(compare_forms(flags, &x.1, &y.1), reverse, x.0, y.0));
    let order: Vec<usize> = order.into_iter().map(|(i, _)| i).collect();
    let mut slots: Vec<Option<(ArrayKey, Value<X>)>> = entries.into_iter().map(Some).collect();
    let sorted = order.into_iter().filter_map(|i| slots[i].take());
    *a = if renumber { Array::from_list(sorted.map(|(_, v)| v).collect()) } else { Array::from_entries(sorted, next) };
    Ok(())
}

fn sort_keys<X: Extension>(a: &mut Array<X>, flags: SortFlags, reverse: bool) {
    if a.len() < 2 {
        return;
    }
    let next = a.next_index();
    let mut entries: Vec<(usize, ArrayKey, Value<X>)> =
        std::mem::take(a).into_iter().enumerate().map(|(i, (k, v))| (i, k, v)).collect();
    zend_sort(&mut entries, &mut |x, y| stable(compare_keys(flags, x.1.as_ref(), y.1.as_ref()), reverse, x.0, y.0));
    *a = Array::from_entries(entries.into_iter().map(|(_, k, v)| (k, v)), next);
}

/// `sort($a, $flags)`: values in order, keys renumbered.
pub fn sort<X: Extension>(a: &mut Array<X>, flags: SortFlags) -> Result<(), EngineError> {
    sort_values(a, flags, false, true)
}

/// `rsort($a, $flags)`.
pub fn rsort<X: Extension>(a: &mut Array<X>, flags: SortFlags) -> Result<(), EngineError> {
    sort_values(a, flags, true, true)
}

/// `asort($a, $flags)`: values in order, keys kept.
pub fn asort<X: Extension>(a: &mut Array<X>, flags: SortFlags) -> Result<(), EngineError> {
    sort_values(a, flags, false, false)
}

/// `arsort($a, $flags)`.
pub fn arsort<X: Extension>(a: &mut Array<X>, flags: SortFlags) -> Result<(), EngineError> {
    sort_values(a, flags, true, false)
}

/// `ksort($a, $flags)`.
pub fn ksort<X: Extension>(a: &mut Array<X>, flags: SortFlags) {
    sort_keys(a, flags, false)
}

/// `krsort($a, $flags)`.
pub fn krsort<X: Extension>(a: &mut Array<X>, flags: SortFlags) {
    sort_keys(a, flags, true)
}

// ---------------------------------------------------------------------------
// array_unique, array_diff, array_diff_key, array_intersect
// ---------------------------------------------------------------------------

/// `array_unique($a, $flags)` (PHP's default flag is `SORT_STRING`): the
/// first of each group of equal values, keys kept.
pub fn array_unique<X: Extension>(a: &Array<X>, flags: SortFlags) -> Result<Array<X>, EngineError> {
    if a.len() <= 1 {
        return Ok(a.clone());
    }
    if flags == SortFlags::String {
        string_error(flags, a)?;
        let mut seen: HashSet<Str> = HashSet::with_capacity(a.len());
        let mut out = Array::new();
        for (k, v) in a.iter() {
            let s = v.to_php_string().unwrap_or_default();
            if seen.insert(s) {
                out.set(k.to_owned(), v.clone());
            }
        }
        return Ok(out);
    }
    string_error(flags, a)?;
    let mut tmp: Vec<(usize, KeyRef<'_>, Form<'_, X>)> =
        a.iter().enumerate().map(|(i, (k, v))| (i, k, form(v, flags))).collect();
    let mut cmp =
        |x: &(usize, KeyRef<'_>, Form<'_, X>), y: &(usize, KeyRef<'_>, Form<'_, X>)| compare_forms(flags, &x.2, &y.2);
    zend_sort(&mut tmp, &mut cmp);
    let mut removed: HashSet<usize> = HashSet::new();
    let mut last = 0;
    for c in 1..tmp.len() {
        if cmp(&tmp[last], &tmp[c]) != 0 {
            last = c;
        } else if tmp[last].0 > tmp[c].0 {
            removed.insert(tmp[last].0);
            last = c;
        } else {
            removed.insert(tmp[c].0);
        }
    }
    let mut out = a.clone();
    let mut i = 0;
    out.retain(|_, _| {
        i += 1;
        !removed.contains(&(i - 1))
    });
    Ok(out)
}

/// `array_diff($a, ...$others)`: the entries of `a` whose string value is
/// in none of `others`, keys kept.
pub fn array_diff<X: Extension>(a: &Array<X>, others: &[&Array<X>]) -> Result<Array<X>, EngineError> {
    let text = |v: &Value<X>| v.to_php_string();
    match a.len() {
        0 => return Ok(Array::new()),
        1 => {
            let search = text(a.values().next().unwrap_or(&Value::Null))?;
            let mut found = false;
            for other in others {
                if found {
                    break;
                }
                for v in other.values() {
                    if text(v)? == search {
                        found = true;
                        break;
                    }
                }
            }
            return Ok(if found { Array::new() } else { a.clone() });
        }
        _ => {}
    }
    if others.iter().all(|o| o.is_empty()) {
        return Ok(a.clone());
    }
    let mut exclude: HashSet<Str> = HashSet::new();
    for other in others {
        for v in other.values() {
            exclude.insert(text(v)?);
        }
    }
    let mut out = Array::with_capacity(a.len());
    for (k, v) in a.iter() {
        if !exclude.contains(&text(v)?) {
            out.set(k.to_owned(), v.clone());
        }
    }
    Ok(out)
}

/// `array_diff_key($a, ...$others)`: the entries of `a` whose key is in none of `others`.
pub fn array_diff_key<X: Extension>(a: &Array<X>, others: &[&Array<X>]) -> Array<X> {
    let mut out = Array::new();
    for (k, v) in a.iter() {
        if !others.iter().any(|o| o.get_ref(k).is_some()) {
            out.set(k.to_owned(), v.clone());
        }
    }
    out
}

/// `array_intersect($a, ...$others)`: the entries of `a` whose string value
/// is in every one of `others`, keys kept (`php_array_intersect`: sorted
/// copies walked together).
pub fn array_intersect<X: Extension>(a: &Array<X>, others: &[&Array<X>]) -> Result<Array<X>, EngineError> {
    let arrays: Vec<&Array<X>> = std::iter::once(a).chain(others.iter().copied()).collect();
    let mut error: Option<EngineError> = None;
    // Each list: (position in its array, string form), sorted by string. A
    // list of two or more is sorted, which converts every value; the single
    // value of a shorter list is converted when the walk first compares it.
    let mut lists: Vec<Vec<(usize, Str)>> = Vec::with_capacity(arrays.len());
    for arr in &arrays {
        let mut list: Vec<(usize, Str)> = Vec::with_capacity(arr.len());
        for (i, v) in arr.values().enumerate() {
            let s = match v.to_php_string() {
                Ok(s) => s,
                Err(e) => {
                    if arr.len() > 1 {
                        error.get_or_insert(e);
                    }
                    Str::EMPTY
                }
            };
            list.push((i, s));
        }
        if list.len() > 1 {
            zend_sort(&mut list, &mut |x, y| binary_strcmp(&x.1, &y.1));
        }
        lists.push(list);
    }
    let touch = |list: usize, entry: usize, error: &mut Option<EngineError>| {
        let arr = arrays[list];
        if arr.len() == 1
            && let Some(v) = arr.values().nth(entry)
            && let Err(e) = v.to_php_string()
        {
            error.get_or_insert(e);
        }
    };
    let mut deleted: HashSet<usize> = HashSet::new();
    let mut ptrs = vec![0usize; lists.len()];
    let first = &lists[0];
    'out: while ptrs[0] < first.len() {
        let mut c = 1;
        for i in 1..lists.len() {
            while ptrs[i] < lists[i].len() {
                touch(0, first[ptrs[0]].0, &mut error);
                touch(i, lists[i][ptrs[i]].0, &mut error);
                c = binary_strcmp(&first[ptrs[0]].1, &lists[i][ptrs[i]].1);
                if c > 0 {
                    ptrs[i] += 1;
                } else {
                    break;
                }
            }
            if ptrs[i] >= lists[i].len() {
                // Another list is exhausted: the rest of the first goes.
                while ptrs[0] < first.len() {
                    deleted.insert(first[ptrs[0]].0);
                    ptrs[0] += 1;
                }
                break 'out;
            }
            if c != 0 {
                break;
            }
            ptrs[i] += 1;
        }
        // Delete (not everywhere) or skip (everywhere) the run of equal values.
        loop {
            if c != 0 {
                deleted.insert(first[ptrs[0]].0);
            }
            ptrs[0] += 1;
            if ptrs[0] >= first.len() {
                break 'out;
            }
            if binary_strcmp(&first[ptrs[0] - 1].1, &first[ptrs[0]].1) != 0 {
                break;
            }
        }
    }
    if let Some(e) = error {
        return Err(e);
    }
    let mut out = a.clone();
    let mut i = 0;
    out.retain(|_, _| {
        i += 1;
        !deleted.contains(&(i - 1))
    });
    Ok(out)
}

// ---------------------------------------------------------------------------
// in_array, array_search
// ---------------------------------------------------------------------------

/// `in_array($needle, $haystack, $strict)`.
pub fn in_array<X: Extension>(needle: &Value<X>, haystack: &Array<X>, strict: bool) -> bool {
    array_search(needle, haystack, strict).is_some()
}

/// `array_search($needle, $haystack, $strict)`: the key of the first match.
pub fn array_search<'a, X: Extension>(needle: &Value<X>, haystack: &'a Array<X>, strict: bool) -> Option<KeyRef<'a>> {
    haystack.iter().find(|(_, v)| if strict { needle.strict_eq(v) } else { needle.loose_eq(v) }).map(|(k, _)| k)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::types::Never;
    use serde_json::json;

    fn arr(j: serde_json::Value) -> Array<Never> {
        match Value::<Never>::from_json(&j) {
            Value::Array(a) => a,
            _ => Array::new(),
        }
    }

    #[test]
    fn zend_sort_sorts_every_size() {
        for n in [0usize, 1, 2, 3, 5, 6, 7, 16, 17, 100, 1500] {
            let mut v: Vec<i64> = (0..n as i64).map(|i| (i * 7919) % 101).collect();
            let mut expected = v.clone();
            expected.sort();
            zend_sort(&mut v, &mut |a, b| (a - b).signum() as i32);
            assert_eq!(v, expected, "{n}");
        }
    }

    #[test]
    fn sorts_like_php() {
        let mut a = arr(json!({"x": 10, "y": "9", "z": 1.5}));
        sort(&mut a, SortFlags::Regular).unwrap();
        assert_eq!(Value::Array(a).to_json(), json!([1.5, "9", 10]));
        let mut a = arr(json!(["img12", "img10", "IMG2", "img1"]));
        sort(&mut a, SortFlags::NaturalFoldCase).unwrap();
        assert_eq!(Value::Array(a).to_json(), json!(["img1", "IMG2", "img10", "img12"]));
        let mut a = arr(json!({"b": 1, "a": 1, "10": 1, "9": 1}));
        ksort(&mut a, SortFlags::Regular);
        assert_eq!(
            a.keys().map(KeyRef::to_owned).collect::<Vec<_>>(),
            vec![ArrayKey::Int(9), ArrayKey::Int(10), ArrayKey::from("a"), ArrayKey::from("b")]
        );
        let mut a = arr(json!([1, {}]));
        assert_eq!(sort(&mut a, SortFlags::String).unwrap_err().class, "Error");
        // strcoll() stops at NUL: "a\0b" and "a" are equal, the sort is stable.
        let mut a = arr(json!(["a\u{0}b", "b", "a"]));
        sort(&mut a, SortFlags::Locale).unwrap();
        assert_eq!(Value::Array(a).to_json(), json!(["a\u{0}b", "a", "b"]));
    }

    #[test]
    fn set_functions_like_php() {
        let a = arr(json!(["a", "1", 1, 1.0, "b", "a"]));
        assert_eq!(
            Value::Array(array_unique(&a, SortFlags::String).unwrap()).to_json(),
            json!({"0": "a", "1": "1", "4": "b"})
        );
        assert_eq!(
            Value::Array(array_unique(&a, SortFlags::Regular).unwrap()).to_json(),
            json!({"0": "a", "1": "1", "4": "b"})
        );
        let b = arr(json!(["1", "x"]));
        assert_eq!(Value::Array(array_diff(&a, &[&b]).unwrap()).to_json(), json!({"0": "a", "4": "b", "5": "a"}));
        assert_eq!(Value::Array(array_intersect(&a, &[&b]).unwrap()).to_json(), json!({"1": "1", "2": 1, "3": 1.0}));
        assert!(in_array(&Value::from("1e0"), &a, false));
        assert!(!in_array(&Value::from("1e0"), &a, true));
        assert_eq!(array_search(&Value::Int(1), &a, true), Some(KeyRef::Int(2)));
    }
}
