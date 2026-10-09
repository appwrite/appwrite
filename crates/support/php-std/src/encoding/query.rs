//! `parse_str` (`main/php_variables.c`: `php_default_treat_data` with
//! `PARSE_STRING`, then `php_register_variable_ex` for every pair).

use crate::zval::{Array, Key, Zval};

use super::urldecode;

/// `max_input_vars` (php.ini default): pairs after this many are dropped
/// (PHP warns).
const MAX_INPUT_VARS: usize = 1000;

/// `max_input_nesting_level` (php.ini default).
const MAX_INPUT_NESTING_LEVEL: usize = 64;

/// PHP `parse_str($string, $result)`: the array `$result` holds.
///
/// The input is a C string to PHP: it ends at the first NUL byte. Pairs are
/// separated by `&` (`arg_separator.input`), empty pairs are skipped, names
/// and values are [`urldecode`]d. A name ends at its first decoded NUL byte;
/// leading spaces are dropped, and spaces and dots before the first `[`
/// become `_`. `a[b][]=v` builds nested arrays (`[]` appends); a `[` without
/// a closing `]` is kept as `_` in a top-level name and ends a nested one.
/// Names nested deeper than `max_input_nesting_level` (64) remove the
/// top-level variable; pairs beyond `max_input_vars` (1000) are ignored.
/// Numeric keys are integer keys, as everywhere in PHP arrays.
pub fn parse_str(s: &[u8]) -> Array {
    let s = match s.iter().position(|&b| b == 0) {
        Some(end) => &s[..end],
        None => s,
    };
    let mut result = Array::new();
    for (count, pair) in s.split(|&b| b == b'&').filter(|p| !p.is_empty()).enumerate() {
        if count >= MAX_INPUT_VARS {
            break;
        }
        let (name, value) = match pair.iter().position(|&b| b == b'=') {
            Some(i) => (&pair[..i], urldecode(&pair[i + 1..])),
            None => (pair, Vec::new()),
        };
        let mut name = urldecode(name);
        if let Some(end) = name.iter().position(|&b| b == 0) {
            name.truncate(end);
        }
        register(&mut result, &name, value);
    }
    result
}

/// Where a name puts its value: the containers to walk (or create), and
/// what to do at the end.
struct Path {
    /// Keys of the nested arrays, outermost first (`None` appends).
    containers: Vec<Option<Key>>,
    /// The key the value is stored at (`None` appends), or `Err` with the
    /// top-level key to remove when the name nests too deep.
    target: Result<Option<Key>, Key>,
}

/// `php_register_variable_ex`, split into reading the name ([`path`]) and
/// storing the value.
fn register(track: &mut Array, name: &[u8], value: Vec<u8>) {
    let Some(path) = path(name) else { return };
    let mut table: &mut Array = track;
    for key in &path.containers {
        let slot = match key {
            None => {
                if !table.push(Zval::Array(Array::new())) {
                    return;
                }
                let last = table.iter().last().map(|(k, _)| k.clone());
                match last.and_then(|k| table.get_mut(&k)) {
                    Some(slot) => slot,
                    None => return,
                }
            }
            Some(key) => {
                if !matches!(table.get(key), Some(Zval::Array(_))) {
                    table.insert(key.clone(), Zval::Array(Array::new()));
                }
                match table.get_mut(key) {
                    Some(slot) => slot,
                    None => return,
                }
            }
        };
        table = match slot {
            Zval::Array(a) => a,
            _ => return,
        };
    }
    match path.target {
        Ok(None) => {
            table.push(Zval::String(value));
        }
        Ok(Some(key)) => table.insert(key, Zval::String(value)),
        Err(top) => {
            track.remove(&top);
        }
    }
}

/// Reads a variable name the way `php_register_variable_ex` does, on a
/// NUL-terminated copy so the C string semantics carry over exactly.
/// `None` when the name is empty (the pair is dropped).
fn path(name: &[u8]) -> Option<Path> {
    let start = name.iter().position(|&b| b != b' ').unwrap_or(name.len());
    let mut buf = Vec::with_capacity(name.len() - start + 1);
    buf.extend_from_slice(&name[start..]);
    buf.push(0);
    let cstr = |buf: &[u8], from: usize| -> usize { buf[from..].iter().position(|&b| b == 0).map_or(buf.len(), |n| from + n) };

    let mut p = 0;
    let mut bracket = None;
    while buf[p] != 0 {
        match buf[p] {
            b' ' | b'.' => buf[p] = b'_',
            b'[' => {
                buf[p] = 0;
                bracket = Some(p);
                break;
            }
            _ => {}
        }
        p += 1;
    }
    let var_len = p;
    if var_len == 0 {
        return None;
    }
    let top = Key::from_bytes(&buf[..var_len]);
    let Some(mut ip) = bracket else {
        return Some(Path { containers: Vec::new(), target: Ok(Some(top)) });
    };

    // `index` is the key the next container (or the value) goes under, as
    // a range of `buf`; `None` appends.
    let mut index: Option<(usize, usize)> = Some((0, var_len));
    let mut containers = Vec::new();
    let mut level = 0;
    let key = |buf: &[u8], r: Option<(usize, usize)>| r.map(|(s, e)| Key::from_bytes(&buf[s..e]));
    loop {
        level += 1;
        if level > MAX_INPUT_NESTING_LEVEL {
            return Some(Path { containers, target: Err(top) });
        }
        ip += 1;
        let index_s = ip;
        // One space after `[` is skipped when looking for `]`: `[ ]` appends.
        if buf[ip] == b' ' {
            ip += 1;
        }
        let next = if buf[ip] == b']' {
            None
        } else {
            match buf[ip..].iter().take_while(|&&b| b != 0).position(|&b| b == b']') {
                Some(n) => {
                    ip += n;
                    buf[ip] = 0;
                    Some((index_s, ip))
                }
                None => {
                    // Not an index: the `[` and what follows become part of
                    // the name (a top-level one) or are dropped (a nested one).
                    buf[index_s - 1] = b'_';
                    let end = cstr(&buf, index_s);
                    for b in &mut buf[index_s..end] {
                        if matches!(*b, b' ' | b'.' | b'[') {
                            *b = b'_';
                        }
                    }
                    let index = index.map(|(s, _)| (s, cstr(&buf, s)));
                    return Some(Path { containers, target: Ok(key(&buf, index)) });
                }
            }
        };
        containers.push(key(&buf, index));
        index = next;
        ip += 1;
        if buf[ip] == b'[' {
            buf[ip] = 0;
        } else {
            return Some(Path { containers, target: Ok(key(&buf, index)) });
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn parsed(s: &str) -> serde_json::Value {
        Zval::Array(parse_str(s.as_bytes())).to_json().unwrap()
    }

    #[test]
    fn names_like_php() {
        assert_eq!(
            parsed("a[b]=1&a[b][c]=2&x.y=3& z=4&q[=5&r[a=6&s[a][b=7&t[]=1&t[]=2"),
            json!({"a": {"b": {"c": "2"}}, "x_y": "3", "z": "4", "q_": "5", "r_a": "6", "s": {"a": "7"}, "t": ["1", "2"]})
        );
        assert_eq!(parsed("a=1&&b&=2&c=%41+b"), json!({"a": "1", "b": "", "c": "A b"}));
        assert_eq!(parsed("1=a&0=b"), json!({"1": "a", "0": "b"}));
        assert_eq!(parsed("a%00b=1&c=d%00e"), json!({"a": "1", "c": "d\u{0}e"}));
        assert_eq!(parsed("a[b]c=1&a.b[c.d]=2"), json!({"a": {"b": "1"}, "a_b": {"c.d": "2"}}));
    }

    #[test]
    fn nesting_limit_removes_the_variable() {
        let deep = format!("a=1&a{}=2", "[x]".repeat(65));
        assert_eq!(parsed(&deep), json!([]));
        let ok = format!("a{}=2", "[]".repeat(64));
        assert!(parsed(&ok).get("a").is_some());
    }
}
