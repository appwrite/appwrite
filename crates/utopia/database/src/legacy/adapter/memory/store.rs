//! The adapter's state, kept as the PHP arrays the PHP adapter keeps
//! (`$databases`, `$data`, `$permissions`, ...), with PHP's array semantics:
//! insertion order, integer keys for numeric strings, `unset` then set
//! appending, `[]` taking the next free integer key. The helpers here are
//! PHP's nested-array idioms: `$a[k1][k2] = v` creating the arrays on the
//! way, `unset($a[k1][k2])` creating nothing, `$a[k1][k2] ?? null`.

use crate::value::{Array, ArrayKey, KeyRef, Str, Value};

/// An array key from text (`$a['key']`).
pub fn k(s: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(s))
}

/// The key a value becomes as an array key (`$a[$value]`): integers stay,
/// strings are normalized, `null` is `""`, booleans and floats integers.
pub fn key_of(v: &Value) -> ArrayKey {
    match v {
        Value::Int(i) => ArrayKey::Int(*i),
        Value::Str(s) => ArrayKey::normalize(s.clone()),
        Value::Null => ArrayKey::normalize(Str::EMPTY),
        Value::Bool(b) => ArrayKey::Int(i64::from(*b)),
        Value::Float(f) => ArrayKey::Int(php_std::format::dval_to_lval(*f)),
        other => ArrayKey::normalize(other.to_php_string().unwrap_or(Str::EMPTY)),
    }
}

/// A key as the value `foreach ($a as $key => ...)` gives.
pub fn key_value(key: KeyRef<'_>) -> Value {
    match key {
        KeyRef::Int(i) => Value::Int(i),
        KeyRef::Str(s) => Value::Str(Str::from(s.to_vec())),
    }
}

/// `$a[k1][k2]...` (`None` when a step is missing or not an array).
pub fn get<'a>(a: &'a Array, path: &[ArrayKey]) -> Option<&'a Value> {
    let (last, init) = path.split_last()?;
    let mut cur = a;
    for key in init {
        match cur.get(key) {
            Some(Value::Array(next)) => cur = next,
            _ => return None,
        }
    }
    cur.get(last)
}

/// `$a[k1][k2]...` as an array (`None` when missing or not an array).
pub fn get_array<'a>(a: &'a Array, path: &[ArrayKey]) -> Option<&'a Array> {
    match get(a, path) {
        Some(Value::Array(x)) => Some(x),
        _ => None,
    }
}

/// `isset($a[k1][k2]...)`.
pub fn isset(a: &Array, path: &[ArrayKey]) -> bool {
    get(a, path).is_some_and(|v| !matches!(v, Value::Null))
}

/// `&$a[k1][k2]...`: the array there, creating (or replacing a non-array
/// with) an empty array at every step, as PHP's write context does.
pub fn vivify<'a>(a: &'a mut Array, path: &[ArrayKey]) -> &'a mut Array {
    let mut cur = a;
    for key in path {
        if !matches!(cur.get(key), Some(Value::Array(_))) {
            cur.set(key.clone(), Value::Array(Array::new()));
        }
        cur = match cur.get_mut(key) {
            Some(Value::Array(next)) => next,
            _ => unreachable!("an array was just stored"),
        };
    }
    cur
}

/// `$a[k1][k2]... = $value`.
pub fn set(a: &mut Array, path: &[ArrayKey], value: Value) {
    if let Some((last, init)) = path.split_last() {
        vivify(a, init).set(last.clone(), value);
    }
}

/// `$a[k1][k2]...[] = $value`: the key it got.
pub fn push(a: &mut Array, path: &[ArrayKey], value: Value) -> i64 {
    let target = vivify(a, path);
    let next = target.next_index().unwrap_or(0);
    target.set(ArrayKey::Int(next), value);
    next
}

/// `unset($a[k1][k2]...)`: nothing is created on the way.
pub fn unset(a: &mut Array, path: &[ArrayKey]) {
    let Some((last, init)) = path.split_last() else { return };
    let mut cur = a;
    for key in init {
        match cur.get_mut(key) {
            Some(Value::Array(next)) => cur = next,
            _ => return,
        }
    }
    cur.remove(last);
}

/// PHP's `empty($value)`.
pub fn empty(v: Option<&Value>) -> bool {
    match v {
        None | Some(Value::Null) => true,
        Some(Value::Bool(b)) => !b,
        Some(Value::Int(i)) => *i == 0,
        Some(Value::Float(f)) => *f == 0.0,
        Some(Value::Str(s)) => s.is_empty() || s.as_bytes() == b"0",
        Some(Value::Array(a)) => a.is_empty(),
        Some(Value::Object(_) | Value::Ext(_)) => false,
    }
}

/// A string value.
pub fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// `array_merge($a, $b)`: string keys of `$b` overwrite in place, integer
/// keys are renumbered after `$a`'s.
pub fn array_merge(arrays: &[&Array]) -> Array {
    let mut out = Array::new();
    for a in arrays {
        for (key, value) in a.iter() {
            match key {
                KeyRef::Int(_) => {
                    let _ = out.push(value.clone());
                }
                KeyRef::Str(_) => {
                    out.set(key.to_owned(), value.clone());
                }
            }
        }
    }
    out
}

/// `foreach ($a as $key => &$value)`.
pub fn for_each_mut(a: &mut Array, mut f: impl FnMut(ArrayKey, &mut Value)) {
    let keys: Vec<ArrayKey> = a.keys().map(|key| key.to_owned()).collect();
    for key in keys {
        if let Some(value) = a.get_mut(&key) {
            f(key, value);
        }
    }
}
