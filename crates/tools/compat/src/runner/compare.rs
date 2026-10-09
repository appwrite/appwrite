//! Strict comparison of results, and the substitutions applied to arguments.
//!
//! Comparison is exact: integers and floats differ (`1` vs `1.0`), object key
//! order matters, strings compare byte for byte. The only rewrites are the
//! per-run namespace (`${ns}`), handle ids, and masks a spec or case declares
//! with a reason.

use std::collections::HashMap;

use serde_json::{Map, Value};

/// Rewrites a side's result into the comparable form.
pub fn normalize(value: &Value, ns: &str) -> Value {
    match value {
        Value::String(s) => Value::String(replace_ns(s, ns)),
        Value::Array(a) => Value::Array(a.iter().map(|v| normalize(v, ns)).collect()),
        Value::Object(o) => {
            if o.len() == 1 && o.get("$handle").is_some_and(Value::is_string) {
                let mut h = Map::new();
                h.insert("$handle".into(), Value::String("*".into()));
                return Value::Object(h);
            }
            Value::Object(o.iter().map(|(k, v)| (replace_ns(k, ns), normalize(v, ns))).collect())
        }
        other => other.clone(),
    }
}

fn replace_ns(s: &str, ns: &str) -> String {
    if ns.is_empty() || !s.contains(ns) { s.to_owned() } else { s.replace(ns, "${ns}") }
}

/// Replaces the values at `paths` (JSON pointers; `*` matches any key or
/// index) with `"<masked>"`.
pub fn mask(value: &mut Value, paths: &[String]) {
    for path in paths {
        let segments: Vec<&str> = path.split('/').skip(1).collect();
        mask_at(value, &segments);
    }
}

fn mask_at(value: &mut Value, segments: &[&str]) {
    let Some((first, rest)) = segments.split_first() else {
        *value = Value::String("<masked>".into());
        return;
    };
    match value {
        Value::Object(o) => {
            if *first == "*" {
                o.values_mut().for_each(|v| mask_at(v, rest));
            } else if let Some(v) = o.get_mut(&unescape(first)) {
                mask_at(v, rest);
            }
        }
        Value::Array(a) => {
            if *first == "*" {
                a.iter_mut().for_each(|v| mask_at(v, rest));
            } else if let Some(v) = first.parse::<usize>().ok().and_then(|i| a.get_mut(i)) {
                mask_at(v, rest);
            }
        }
        _ => {}
    }
}

fn unescape(segment: &str) -> String {
    segment.replace("~1", "/").replace("~0", "~")
}

/// The first difference between `a` and `b`, as `path: a vs b`.
pub fn diff(a: &Value, b: &Value) -> Option<String> {
    diff_at(a, b, &mut String::new())
}

fn diff_at(a: &Value, b: &Value, path: &mut String) -> Option<String> {
    let here = |path: &str, what: String| Some(format!("{}: {what}", if path.is_empty() { "/" } else { path }));
    match (a, b) {
        (Value::Number(x), Value::Number(y)) => {
            let kind = |n: &serde_json::Number| if n.is_f64() { "float" } else { "int" };
            if kind(x) != kind(y) {
                return here(path, format!("{x} ({}) vs {y} ({})", kind(x), kind(y)));
            }
            let same = match (x.as_f64(), y.as_f64()) {
                (Some(p), Some(q)) if x.is_f64() => p.to_bits() == q.to_bits() || p == q,
                _ => x == y,
            };
            if same { None } else { here(path, format!("{x} vs {y}")) }
        }
        (Value::Array(x), Value::Array(y)) => {
            for (i, (p, q)) in x.iter().zip(y).enumerate() {
                let len = path.len();
                path.push_str(&format!("/{i}"));
                if let Some(d) = diff_at(p, q, path) {
                    return Some(d);
                }
                path.truncate(len);
            }
            if x.len() != y.len() {
                return here(path, format!("{} items vs {} items", x.len(), y.len()));
            }
            None
        }
        (Value::Object(x), Value::Object(y)) => {
            let kx: Vec<&String> = x.keys().collect();
            let ky: Vec<&String> = y.keys().collect();
            if kx != ky {
                let mut sx = kx.clone();
                let mut sy = ky.clone();
                sx.sort();
                sy.sort();
                return if sx == sy {
                    here(path, format!("same keys in another order: {kx:?} vs {ky:?}"))
                } else {
                    here(path, format!("keys {kx:?} vs {ky:?}"))
                };
            }
            for (k, p) in x {
                let len = path.len();
                path.push('/');
                path.push_str(&k.replace('~', "~0").replace('/', "~1"));
                if let Some(d) = diff_at(p, &y[k], path) {
                    return Some(d);
                }
                path.truncate(len);
            }
            None
        }
        _ if a == b => None,
        _ => here(path, format!("{} vs {}", short(a), short(b))),
    }
}

/// A one-line rendering of a value, truncated.
pub fn short(v: &Value) -> String {
    let s = v.to_string();
    if s.len() > 300 {
        let mut end = 300;
        while !s.is_char_boundary(end) {
            end -= 1;
        }
        format!("{}…", &s[..end])
    } else {
        s
    }
}

/// Resolves `{"$ref": name, "path": "/pointer"}` against earlier bindings and
/// replaces `${ns}` in strings.
pub fn substitute(args: &Value, bindings: &HashMap<String, Value>, ns: &str) -> Result<Value, String> {
    Ok(match args {
        Value::String(s) => Value::String(s.replace("${ns}", ns)),
        Value::Array(a) => Value::Array(a.iter().map(|v| substitute(v, bindings, ns)).collect::<Result<_, _>>()?),
        Value::Object(o) => {
            if let Some(name) = o.get("$ref").and_then(Value::as_str) {
                let bound = bindings.get(name).ok_or_else(|| format!("`$ref` to unknown binding `{name}`"))?;
                return match o.get("path").and_then(Value::as_str) {
                    Some(p) => bound.pointer(p).cloned().ok_or_else(|| format!("`{name}` has nothing at `{p}`")),
                    None => Ok(bound.clone()),
                };
            }
            Value::Object(
                o.iter()
                    .map(|(k, v)| Ok((k.replace("${ns}", ns), substitute(v, bindings, ns)?)))
                    .collect::<Result<_, String>>()?,
            )
        }
        other => other.clone(),
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn ints_and_floats_differ() {
        assert!(diff(&json!(1), &json!(1.0)).is_some());
        assert!(diff(&json!(1.5), &json!(1.5)).is_none());
    }

    #[test]
    fn key_order_matters() {
        let a: Value = serde_json::from_str(r#"{"a":1,"b":2}"#).unwrap();
        let b: Value = serde_json::from_str(r#"{"b":2,"a":1}"#).unwrap();
        assert!(diff(&a, &b).unwrap().contains("another order"));
    }

    #[test]
    fn reports_the_path() {
        let d = diff(&json!({"x": [1, {"y": "a"}]}), &json!({"x": [1, {"y": "b"}]})).unwrap();
        assert!(d.starts_with("/x/1/y:"), "{d}");
    }

    #[test]
    fn normalizes_namespaces_and_handles() {
        let v = normalize(&json!({"cxab:key": "cxab:v", "h": {"$handle": "h9"}}), "cxab");
        assert_eq!(v, json!({"${ns}:key": "${ns}:v", "h": {"$handle": "*"}}));
    }

    #[test]
    fn masks_with_wildcards() {
        let mut v = json!({"items": [{"id": 1, "n": 2}, {"id": 3, "n": 4}]});
        mask(&mut v, &["/items/*/id".to_owned()]);
        assert_eq!(v, json!({"items": [{"id": "<masked>", "n": 2}, {"id": "<masked>", "n": 4}]}));
    }

    #[test]
    fn substitutes_refs_and_ns() {
        let b = HashMap::from([("l".to_owned(), json!({"$handle": "h1", "x": [5]}))]);
        let v = substitute(&json!({"a": {"$ref": "l"}, "k": "${ns}:1", "p": {"$ref": "l", "path": "/x/0"}}), &b, "n1")
            .unwrap();
        assert_eq!(v, json!({"a": {"$handle": "h1", "x": [5]}, "k": "n1:1", "p": 5}));
    }
}
