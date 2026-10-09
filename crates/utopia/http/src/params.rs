//! Request parameter decoding with PHP semantics.
//!
//! PHP (`parse_str`, Swoole `$request->get/post`) turns `a[]=1&a[]=2` into a
//! list, `a[k]=v` into a map and `a[0]=x&a[1]=y` into a list. The result is
//! expressed as `serde_json::Value` so validators can treat JSON bodies and
//! query strings uniformly.

use serde_json::{Map, Value};

/// Decoded request parameters.
pub type Params = Map<String, Value>;

/// Parses an `application/x-www-form-urlencoded` string (query string or
/// form body) the way PHP's `parse_str` does.
pub fn parse_query(input: &str) -> Params {
    let mut root = Map::new();
    for (key, value) in form_urlencoded::parse(input.as_bytes()) {
        insert(&mut root, &key, Value::String(value.into_owned()));
    }
    normalize_map(&mut root);
    root
}

fn insert(root: &mut Map<String, Value>, raw_key: &str, value: Value) {
    // Split `name[a][b][]` into the base name and the bracket path.
    let (base, rest) = match raw_key.find('[') {
        Some(pos) if pos > 0 && raw_key[pos..].contains(']') => (&raw_key[..pos], &raw_key[pos..]),
        _ => (raw_key, ""),
    };
    if base.is_empty() {
        return;
    }
    // PHP replaces '.' and ' ' in the top-level name with '_'.
    let base: String = base.chars().map(|c| if c == '.' || c == ' ' { '_' } else { c }).collect();

    let mut path: Vec<Option<String>> = Vec::new();
    let mut remaining = rest;
    while let Some(stripped) = remaining.strip_prefix('[') {
        match stripped.find(']') {
            Some(end) => {
                let segment = &stripped[..end];
                path.push(if segment.is_empty() { None } else { Some(segment.to_owned()) });
                remaining = &stripped[end + 1..];
            }
            None => break,
        }
    }

    if path.is_empty() {
        root.insert(base, value);
        return;
    }

    let mut current = root.entry(base).or_insert_with(|| Value::Object(Map::new()));
    for (i, segment) in path.iter().enumerate() {
        if !current.is_object() {
            *current = Value::Object(Map::new());
        }
        let map = current.as_object_mut().expect("object");
        let key = match segment {
            Some(k) => k.clone(),
            None => next_index(map).to_string(),
        };
        if i == path.len() - 1 {
            map.insert(key, value);
            return;
        }
        current = map.entry(key).or_insert_with(|| Value::Object(Map::new()));
    }
}

fn next_index(map: &Map<String, Value>) -> i64 {
    map.keys().filter_map(|k| k.parse::<i64>().ok()).filter(|i| *i >= 0).max().map(|m| m + 1).unwrap_or(0)
}

/// Converts maps whose keys are exactly `0..n-1` (in order) into lists, recursively.
fn normalize_map(map: &mut Map<String, Value>) {
    for value in map.values_mut() {
        normalize(value);
    }
}

fn normalize(value: &mut Value) {
    if let Value::Object(map) = value {
        normalize_map(map);
        let is_list = !map.is_empty() && map.keys().enumerate().all(|(i, k)| *k == i.to_string());
        if is_list {
            let items: Vec<Value> = std::mem::take(map).into_iter().map(|(_, v)| v).collect();
            *value = Value::Array(items);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn brackets() {
        let p = parse_query("queries[]=a&queries[]=b&search=x&total=false");
        assert_eq!(Value::Object(p), json!({"queries": ["a", "b"], "search": "x", "total": "false"}));
        let p = parse_query("a[0]=x&a[1]=y&b[k]=v&c[2]=z");
        assert_eq!(Value::Object(p), json!({"a": ["x", "y"], "b": {"k": "v"}, "c": {"2": "z"}}));
        let p = parse_query("a.b=1&labels%5B%5D=vip");
        assert_eq!(Value::Object(p), json!({"a_b": "1", "labels": ["vip"]}));
    }
}
