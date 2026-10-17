//! Request filters for older response formats: the params a client built
//! for an older API version, rewritten to the current one before the route
//! reads them.
//!
//! PHP: `Appwrite\Utopia\Request\Filters` (`V16` ... `V27`), which
//! `app/controllers/general.php` adds by `X-Appwrite-Response-Format`. Only
//! the filters that touch a route Rust serves are here: `V17` (1.4 query
//! strings) and `V27` (bare keywords); the others match none of its routes.

use serde_json::{Map, Value};

use crate::response::Version;
use crate::{Error, ErrorType};

/// Rewrites `params` for a client of response format `format`, for the
/// route whose SDK model is `model` (`users.list`), as each filter PHP adds
/// for that format does, in PHP's order.
pub fn apply(format: Version, model: &str, params: &mut Map<String, Value>) -> Result<(), Error> {
    if format < Version(1, 5, 0) {
        v17(model, params)?;
    }
    if format < Version(2, 0, 0) {
        v27(model, params);
    }
    Ok(())
}

/// `V17::parse()`: 1.4 query strings (`equal("name", ["x"])`) become JSON
/// queries on the list routes.
fn v17(model: &str, params: &mut Map<String, Value>) -> Result<(), Error> {
    const MODELS: &[&str] = &[
        "account.listIdentities",
        "account.listLogs",
        "databases.list",
        "databases.listLogs",
        "databases.listCollections",
        "databases.listCollectionLogs",
        "databases.listAttributes",
        "databases.listIndexes",
        "databases.listDocuments",
        "databases.getDocument",
        "databases.listDocumentLogs",
        "functions.list",
        "functions.listDeployments",
        "functions.listExecutions",
        "migrations.list",
        "projects.list",
        "proxy.listRules",
        "storage.listBuckets",
        "storage.listFiles",
        "teams.list",
        "teams.listMemberships",
        "teams.listLogs",
        "users.list",
        "users.listLogs",
        "users.listIdentities",
        "vcs.listInstallations",
    ];
    if model == "account.updateRecovery" {
        params.remove("passwordAgain");
        return Ok(());
    }
    if !MODELS.contains(&model) {
        return Ok(());
    }
    // `isset($content['queries'])`
    let Some(queries) = params.get("queries").filter(|q| !q.is_null()) else { return Ok(()) };
    let mut parsed = Vec::new();
    // `foreach` over anything but an array warns and runs no iteration.
    let items: &[Value] = match queries {
        Value::Array(items) => items,
        _ => &[],
    };
    for item in items {
        let query = v17_query(item).map_err(|message| Error::with_message(ErrorType::GeneralQueryInvalid, message))?;
        parsed.push(query);
    }
    params.insert("queries".to_owned(), Value::Array(parsed));
    Ok(())
}

/// Where `V17.php` makes each `Query`, for the `TypeError` messages PHP
/// reports when an argument has the wrong type.
const V17_FILE: &str = "/usr/src/code/src/Appwrite/Utopia/Request/Filters/V17.php";

/// One query: `json_encode(array_filter($this->parseQuery($query)->toArray()))`.
fn v17_query(item: &Value) -> Result<Value, String> {
    // `parseQuery(string $filter)`, called from a file without strict types.
    let filter: Vec<u8> = match item {
        Value::String(s) => s.as_bytes().to_vec(),
        Value::Number(n) => php_number_string(&php_std::value::json_number(n)).into_bytes(),
        Value::Bool(true) => b"1".to_vec(),
        Value::Bool(false) => Vec::new(),
        other => {
            return Err(format!(
                "Appwrite\\Utopia\\Request\\Filters\\V17::parseQuery(): Argument #1 ($filter) must be of type string, {} given, called in {V17_FILE} on line 71",
                type_name(&Php::from_json(other))
            ));
        }
    };
    let query = parse_query(&filter)?;
    // `json_encode()` failing (invalid UTF-8) gives `false`.
    Ok(match php_std::json::encode(&query.to_array(), php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
        Ok(json) => Value::String(json),
        Err(_) => Value::Bool(false),
    })
}

/// A PHP value of the parser: what `parseValue()` returns, and lists of it.
#[derive(Clone, Debug, PartialEq)]
enum Php {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Str(Vec<u8>),
    List(Vec<Php>),
}

impl Php {
    fn from_json(v: &Value) -> Php {
        match v {
            Value::Null => Php::Null,
            Value::Bool(b) => Php::Bool(*b),
            Value::Number(n) => match php_std::value::json_number(n) {
                php_std::value::Number::Int(i) => Php::Int(i),
                php_std::value::Number::Float(f) => Php::Float(f),
            },
            Value::String(s) => Php::Str(s.as_bytes().to_vec()),
            Value::Array(a) => Php::List(a.iter().map(Php::from_json).collect()),
            Value::Object(_) => Php::List(Vec::new()),
        }
    }

    fn to_php(&self) -> php_std::Value {
        match self {
            Php::Null => php_std::Value::Null,
            Php::Bool(b) => php_std::Value::Bool(*b),
            Php::Int(i) => php_std::Value::Int(*i),
            Php::Float(f) => php_std::Value::Float(*f),
            Php::Str(s) => php_std::Value::Str(php_std::Str::from(s.clone())),
            Php::List(items) => php_std::Value::Array(items.iter().map(Php::to_php).collect()),
        }
    }
}

/// PHP's type name in a `TypeError` (`true`/`false` for booleans).
fn type_name(v: &Php) -> &'static str {
    match v {
        Php::Null => "null",
        Php::Bool(true) => "true",
        Php::Bool(false) => "false",
        Php::Int(_) => "int",
        Php::Float(_) => "float",
        Php::Str(_) => "string",
        Php::List(_) => "array",
    }
}

/// `(string) $number`.
fn php_number_string(n: &php_std::value::Number) -> String {
    match n {
        php_std::value::Number::Int(i) => i.to_string(),
        php_std::value::Number::Float(f) => php_std::number::to_string(*f),
    }
}

/// A `Query` as V17 builds it.
struct Query {
    method: Vec<u8>,
    attribute: Vec<u8>,
    values: Vec<Php>,
}

impl Query {
    /// `new Query($method, $attribute, $values)` from V17's line `line`: the
    /// attribute coerced to a string (a file without strict types), the
    /// values required to be an array.
    fn new(method: &[u8], attribute: Php, values: Php, line: u32) -> Result<Query, String> {
        let error = |arg: &str, ty: &str, v: &Php| {
            format!(
                "Utopia\\Database\\Query::__construct(): Argument #{arg} must be of type {ty}, {} given, called in {V17_FILE} on line {line}",
                type_name(v)
            )
        };
        let attribute = match &attribute {
            Php::Str(s) => s.clone(),
            Php::Int(i) => i.to_string().into_bytes(),
            Php::Float(f) => php_std::number::to_string(*f).into_bytes(),
            Php::Bool(true) => b"1".to_vec(),
            Php::Bool(false) => Vec::new(),
            other => return Err(error("2 ($attribute)", "string", other)),
        };
        let values = match values {
            Php::List(items) => items,
            other => return Err(error("3 ($values)", "array", &other)),
        };
        let mut attribute = attribute;
        if attribute.is_empty() && (method == b"orderAsc" || method == b"orderDesc") {
            attribute = b"$sequence".to_vec();
        }
        Ok(Query { method: method.to_vec(), attribute, values })
    }

    /// `array_filter($query->toArray())`.
    fn to_array(&self) -> php_std::Value {
        let mut array = php_std::Array::new();
        // `array_filter` drops falsy entries: an empty or "0" method, and an
        // empty values list.
        if !falsy_string(&self.method) {
            array.set(php_std::ArrayKey::from("method"), php_std::Value::Str(php_std::Str::from(self.method.clone())));
        }
        if !falsy_string(&self.attribute) {
            array.set(
                php_std::ArrayKey::from("attribute"),
                php_std::Value::Str(php_std::Str::from(self.attribute.clone())),
            );
        }
        let logical = matches!(self.method.as_slice(), b"and" | b"or" | b"elemMatch");
        if !logical && !self.values.is_empty() {
            array.set(
                php_std::ArrayKey::from("values"),
                php_std::Value::Array(self.values.iter().map(Php::to_php).collect()),
            );
        }
        php_std::Value::Array(array)
    }
}

/// `empty()` of a string: `""` or `"0"`.
fn falsy_string(s: &[u8]) -> bool {
    s.is_empty() || s == b"0"
}

/// `$filter[$i]`: a byte, negative offsets from the end, `""` (here `None`)
/// past either end.
fn at(filter: &[u8], i: isize) -> Option<u8> {
    let len = filter.len() as isize;
    let i = if i < 0 { len + i } else { i };
    (0..len).contains(&i).then(|| filter[i as usize])
}

/// `V17::parseQuery()`: the 1.4 query parser.
fn parse_query(filter: &[u8]) -> Result<Query, String> {
    // `mb_strpos($filter, '(')` counts characters, and the loop below uses
    // that count as a byte offset, as PHP does.
    let text = String::from_utf8_lossy(filter);
    let Some(byte_start) = filter.iter().position(|&b| b == b'(') else {
        return Err("Invalid query".to_owned());
    };
    let params_start = std::str::from_utf8(&filter[..byte_start]).map_or(byte_start, |s| s.chars().count());
    let method: Vec<u8> = text.chars().take(params_start).collect::<String>().into_bytes();
    let params_end = filter.len() as isize - 1;
    let parameters_start = params_start as isize + 1;
    if method.contains(&b'.') {
        return Err("Invalid query method".to_owned());
    }

    let special = |c: Option<u8>| matches!(c, Some(b',' | b']' | b'[' | b'"' | b'\''));
    let quote = |c: u8| c == b'\'' || c == b'"';
    // `appendSymbol()`: spaces and commas only inside a string.
    let append = |in_string: bool, c: Option<u8>, current: &mut Vec<u8>| {
        if let Some(c) = c
            && (in_string || (c != b' ' && c != b','))
        {
            current.push(c);
        }
    };

    let mut params: Vec<Param> = Vec::new();
    let mut current: Vec<u8> = Vec::new();
    let mut current_array: Vec<Vec<u8>> = Vec::new();
    let mut stack_count: i64 = 0;
    let mut string_state: Option<u8> = None;

    let mut i = parameters_start;
    while i < params_end {
        let Some(c) = at(filter, i) else {
            i += 1;
            continue;
        };
        let in_string = string_state.is_some();
        let in_array = !in_string && stack_count > 0;

        if c == b'\\' {
            if !special(at(filter, i + 1)) {
                append(in_string, Some(c), &mut current);
            }
            append(in_string, at(filter, i + 1), &mut current);
            i += 2;
            continue;
        }

        if quote(c) && (at(filter, i - 1) != Some(b'\\') || at(filter, i - 2) == Some(b'\\')) {
            if in_string {
                if Some(c) == string_state {
                    string_state = None;
                }
            } else {
                string_state = Some(c);
            }
            append(in_string, Some(c), &mut current);
            i += 1;
            continue;
        }

        if !in_string {
            if c == b'[' {
                stack_count += 1;
                i += 1;
                continue;
            } else if c == b']' {
                stack_count -= 1;
                if !current.is_empty() {
                    current_array.push(std::mem::take(&mut current));
                }
                params.push(Param::List(std::mem::take(&mut current_array)));
                current.clear();
                i += 1;
                continue;
            } else if c == b',' {
                if in_array {
                    current_array.push(std::mem::take(&mut current));
                } else if current_array.is_empty() {
                    if !current.is_empty() {
                        params.push(Param::Value(std::mem::take(&mut current)));
                    }
                    current.clear();
                }
                i += 1;
                continue;
            }
        }

        append(in_string, Some(c), &mut current);
        i += 1;
    }
    if !current.is_empty() {
        params.push(Param::Value(current));
    }

    // `$arr` is never reset: each list param holds every list element so far.
    let mut arr: Vec<Php> = Vec::new();
    let mut parsed: Vec<Php> = Vec::new();
    for param in params {
        match param {
            Param::List(elements) => {
                arr.extend(elements.iter().map(|e| parse_value(e)));
                parsed.push(Php::List(arr.clone()));
            }
            Param::Value(v) => parsed.push(parse_value(&v)),
        }
    }

    let get = |n: usize| parsed.get(n).cloned();
    let m = method.as_slice();
    match m {
        b"equal" | b"notEqual" | b"lessThan" | b"lessThanEqual" | b"greaterThan" | b"greaterThanEqual"
        | b"contains" | b"search" | b"isNull" | b"isNotNull" | b"startsWith" | b"endsWith" => {
            let attribute = match get(0) {
                None | Some(Php::Null) => Php::Str(Vec::new()),
                Some(v) => v,
            };
            if parsed.len() < 2 {
                return Query::new(m, attribute, Php::List(Vec::new()), 243);
            }
            let values = match get(1) {
                Some(list @ Php::List(_)) => list,
                Some(v) => Php::List(vec![v]),
                None => Php::List(vec![Php::Null]),
            };
            Query::new(m, attribute, values, 245)
        }
        b"between" => Query::new(
            m,
            get(0).unwrap_or(Php::Null),
            Php::List(vec![get(1).unwrap_or(Php::Null), get(2).unwrap_or(Php::Null)]),
            248,
        ),
        b"select" => Query::new(m, Php::Str(Vec::new()), get(0).unwrap_or(Php::Null), 250),
        b"orderAsc" | b"orderDesc" => {
            let attribute = match get(0) {
                None | Some(Php::Null) => Php::Str(Vec::new()),
                Some(v) => v,
            };
            Query::new(m, attribute, Php::List(Vec::new()), 253)
        }
        b"limit" | b"offset" | b"cursorAfter" | b"cursorBefore" => match get(0) {
            Some(v) => Query::new(m, Php::Str(Vec::new()), Php::List(vec![v]), 260),
            None => Query::new(m, Php::Str(Vec::new()), Php::List(Vec::new()), 262),
        },
        _ => Query::new(m, Php::Str(Vec::new()), Php::List(Vec::new()), 265),
    }
}

enum Param {
    Value(Vec<u8>),
    List(Vec<Vec<u8>>),
}

/// `V17::parseValue()`.
fn parse_value(value: &[u8]) -> Php {
    // `trim()`: space, tab, newline, return, NUL and vertical tab.
    let trimmed = {
        let ws = |b: &u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\0' | 0x0B);
        let start = value.iter().position(|b| !ws(b)).unwrap_or(value.len());
        let end = value.iter().rposition(|b| !ws(b)).map_or(start, |e| e + 1);
        &value[start..end.max(start)]
    };
    match trimmed {
        b"false" => return Php::Bool(false),
        b"true" => return Php::Bool(true),
        b"null" => return Php::Null,
        _ => {}
    }
    if let Some((number, _)) = php_std::value::numeric_bytes_ex(trimmed) {
        return match number {
            php_std::value::Number::Int(i) => Php::Int(i),
            php_std::value::Number::Float(f) => Php::Float(f),
        };
    }
    if trimmed.first().is_some_and(|&b| b == b'"' || b == b'\'') {
        // `substr($value, 1, -1)`
        return Php::Str(if trimmed.len() >= 2 { trimmed[1..trimmed.len() - 1].to_vec() } else { Vec::new() });
    }
    Php::Str(trimmed.to_vec())
}

/// `V27::parse()`: bare keywords gained parentheses in 2.0.0.
fn v27(model: &str, params: &mut Map<String, Value>) {
    if model == "users.createJWT" && params.get("sessionId").and_then(Value::as_str) == Some("recent") {
        params.insert("sessionId".to_owned(), Value::String("recent()".to_owned()));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Every query of `tests/fixtures/request-v17.json`, which PHP's own
    /// `V17::parse()` produced (see that file's generator in the test).
    #[test]
    fn v17_matches_php() {
        let fixtures: Vec<Value> =
            serde_json::from_str(include_str!("../tests/fixtures/request-v17.json")).expect("fixtures");
        let mut failures = Vec::new();
        for fixture in &fixtures {
            let query = fixture["query"].clone();
            let mut params = Map::new();
            params.insert("queries".to_owned(), Value::Array(vec![query.clone()]));
            let result = v17("users.list", &mut params);
            let got = match result {
                Ok(()) => serde_json::json!({"queries": params["queries"]}),
                Err(e) => serde_json::json!({"error": {"type": e.kind.name(), "message": e.public_message()}}),
            };
            let expected = match fixture.get("error") {
                Some(error) => serde_json::json!({"error": error}),
                None => serde_json::json!({"queries": fixture["queries"]}),
            };
            if got != expected {
                failures.push(format!("{query}: rust {got} php {expected}"));
            }
        }
        assert!(failures.is_empty(), "{} of {} differ:\n{}", failures.len(), fixtures.len(), failures.join("\n"));
    }

    #[test]
    fn v27_rewrites_the_recent_keyword() {
        let mut params = Map::new();
        params.insert("sessionId".to_owned(), Value::String("recent".to_owned()));
        apply(Version(1, 9, 5), "users.createJWT", &mut params).unwrap();
        assert_eq!(params["sessionId"], "recent()");
        apply(Version(2, 0, 0), "users.createJWT", &mut params).unwrap();
        assert_eq!(params["sessionId"], "recent()");
    }
}
