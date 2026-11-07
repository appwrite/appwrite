//! Request filters for older response formats: the params a client built
//! for an older API version, rewritten to the current one before the route
//! reads them.
//!
//! PHP: `Appwrite\Utopia\Request\Filters` (`V16` ... `V27`), which
//! `app/controllers/general.php` adds by `X-Appwrite-Response-Format`, each
//! rewriting the routes (SDK models) it names: a service ported later gets
//! its filters with no change here.

use serde_json::{Map, Value};
use utopia_database::{Database, Document};

use crate::response::Version;
use crate::{Error, ErrorType};

/// What `V20` reads: the project database and the route's `databaseId`
/// and `collectionId` (PHP's `params`).
pub struct Related<'a> {
    pub db: &'a Database,
    pub database_id: String,
    pub collection_id: String,
}

/// Rewrites `params` for a client of response format `format`, for the
/// route whose SDK model is `model` (`users.list`), as each filter PHP adds
/// for that format does, in PHP's order.
pub async fn apply(
    format: Version,
    model: &str,
    params: &mut Map<String, Value>,
    related: Option<Related<'_>>,
) -> Result<(), Error> {
    if format < Version(1, 4, 0) {
        v16(model, params);
    }
    if format < Version(1, 5, 0) {
        v17(model, params)?;
    }
    if format < Version(1, 6, 0) {
        v18(model, params);
    }
    if format < Version(1, 7, 0) {
        v19(model, params);
    }
    if format < Version(1, 8, 0) {
        v20(model, params, related).await?;
    }
    if format < Version(1, 9, 0) {
        v21(model, params);
    }
    if format < Version(1, 9, 1) {
        v22(model, params);
    }
    if format < Version(1, 9, 2) {
        v23(model, params);
    }
    if format < Version(1, 9, 3) {
        v24(model, params);
    }
    if format < Version(1, 9, 4) {
        v25(model, params);
    }
    if format < Version(1, 9, 5) {
        v26(model, params);
    }
    if format < Version(2, 0, 0) {
        v27(model, params);
    }
    Ok(())
}

/// Whether PHP's `isset($content[$key])` holds.
fn isset(params: &Map<String, Value>, key: &str) -> bool {
    params.get(key).is_some_and(|v| !v.is_null())
}

/// `$content[$key] = $content[$key] ?? $default`.
fn fill(params: &mut Map<String, Value>, key: &str, default: Value) {
    if !isset(params, key) {
        params.insert(key.to_owned(), default);
    }
}

/// `if (isset($content[$from])) { $content[$to] = $content[$from]; unset($content[$from]); }`
fn rename(params: &mut Map<String, Value>, from: &str, to: &str) {
    if let Some(value) = params.get(from).filter(|v| !v.is_null()).cloned() {
        params.insert(to.to_owned(), value);
        params.remove(from);
    }
}

/// A scalar as a PHP `string` parameter coerces it (`None` for `null` and
/// arrays).
fn php_string(value: &Value) -> Option<String> {
    match value {
        Value::String(s) => Some(s.clone()),
        Value::Bool(true) => Some("1".to_owned()),
        Value::Bool(false) => Some(String::new()),
        Value::Number(n) => Some(php_number_string(&php_std::value::json_number(n))),
        _ => None,
    }
}

/// `V16::parse()`: 1.3 params to 1.4.
fn v16(model: &str, params: &mut Map<String, Value>) {
    match model {
        "functions.create" | "functions.update" => {
            let runtime = params.get("runtime").and_then(php_string).unwrap_or_default();
            const COMMANDS: [(&str, &str); 7] = [
                ("node", "npm install"),
                ("python", "pip install --no-cache-dir -r requirements.txt"),
                ("dart", "dart pub get"),
                (
                    "php",
                    "composer update --no-interaction --ignore-platform-reqs --optimize-autoloader --prefer-dist --no-dev",
                ),
                ("ruby", "bundle install"),
                ("swift", "swift package resolve"),
                ("dotnet", "dotnet restore"),
            ];
            let commands = COMMANDS.iter().find(|(prefix, _)| runtime.starts_with(prefix)).map_or("", |(_, c)| c);
            params.insert("commands".to_owned(), Value::String(commands.to_owned()));
        }
        "functions.createExecution" => {
            let body = params.remove("data").filter(|v| !v.is_null()).unwrap_or(Value::String(String::new()));
            params.insert("body".to_owned(), body);
        }
        _ => {}
    }
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

/// `V18::parse()`: 1.5 params to 1.6.
fn v18(model: &str, params: &mut Map<String, Value>) {
    match model {
        "account.deleteMfaAuthenticator" => {
            params.remove("otp");
        }
        "functions.create" => {
            let version =
                params.remove("templateBranch").filter(|v| !v.is_null()).unwrap_or(Value::String(String::new()));
            params.insert("templateVersion".to_owned(), version);
        }
        _ => {}
    }
}

/// `V19::parse()`: 1.6 params to 1.7.
fn v19(model: &str, params: &mut Map<String, Value>) {
    match model {
        "functions.list" => v19_attribute(params, "deployment", "deploymentId"),
        "functions.listDeployments" => v19_attribute(params, "size", "deploymentSize"),
        "proxy.listRules" => {
            v19_attribute(params, "resourceType", "deploymentResourceType");
            v19_attribute(params, "resourceId", "deploymentResourceId");
        }
        "functions.create" => {
            for key in ["templateRepository", "templateOwner", "templateRootDirectory", "templateVersion"] {
                params.remove(key);
            }
        }
        "functions.listExecutions" => {
            params.remove("search");
        }
        "project.createVariable"
        | "project.listVariables"
        | "functions.createVariable"
        | "functions.updateVariable" => {
            params.insert("secret".to_owned(), Value::Bool(false));
        }
        "functions.getDeploymentDownload" => {
            params.insert("type".to_owned(), Value::String("output".to_owned()));
        }
        _ => {}
    }
}

/// `V19::convertQueryAttribute()`: each query decoded, its attribute
/// renamed, and encoded again (an undecodable one becomes `null`).
fn v19_attribute(params: &mut Map<String, Value>, old: &str, new: &str) {
    let Some(Value::Array(queries)) = params.get_mut("queries") else { return };
    for query in queries.iter_mut() {
        let text = php_string(query).unwrap_or_default();
        let mut decoded = php_std::json::decode(
            text.as_bytes(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        )
        .unwrap_or(php_std::Value::Null);
        if let php_std::Value::Array(array) = &mut decoded {
            let key = php_std::ArrayKey::from("attribute");
            if matches!(array.get(&key), Some(php_std::Value::Str(s)) if s.as_bytes() == old.as_bytes()) {
                array.set(key, php_std::Value::Str(php_std::Str::from(new.as_bytes().to_vec())));
            }
        }
        *query = match php_std::json::encode(&decoded, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH) {
            Ok(json) => Value::String(json),
            Err(_) => Value::Bool(false),
        };
    }
}

fn query_error(e: utopia_database::Error) -> Error {
    Error::with_message(ErrorType::GeneralQueryInvalid, e.message().to_owned())
}

/// `V20::parse()`: 1.7 params to 1.8. Related documents stopped coming
/// back by default, so a 1.7 client's `getDocument` and `listDocuments`
/// select every attribute and every relationship (three levels deep).
async fn v20(model: &str, params: &mut Map<String, Value>, related: Option<Related<'_>>) -> Result<(), Error> {
    if !matches!(model, "databases.getDocument" | "databases.listDocuments") {
        return Ok(());
    }
    let queries = params.entry("queries").or_insert_with(|| Value::Array(Vec::new()));
    let Value::Array(items) = queries else { return Ok(()) };
    // `array_filter()`: empty objects, blank strings and empty values out.
    items.retain(|q| match q {
        Value::Object(map) => !map.is_empty(),
        Value::String(s) => !s.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']).is_empty() && s != "0",
        other => !php_std::value::empty(other),
    });
    let texts: Vec<String> = items.iter().map(|q| php_string(q).unwrap_or_default()).collect();
    let parsed = utopia_database::Query::parse_queries(&texts, false).map_err(query_error)?;
    let selects: Vec<&utopia_database::Query> =
        parsed.iter().filter(|q| q.method() == utopia_query::Method::Select).collect();
    let star = |v: &utopia_query::Value| matches!(v, utopia_query::Value::Str(s) if s.as_bytes() == b"*");
    let needs = selects.is_empty() || selects.iter().any(|s| s.values().values().any(star));
    let mut queries: Vec<Value> = Vec::with_capacity(parsed.len() + 1);
    for query in &parsed {
        if needs && query.method() == utopia_query::Method::Select {
            continue;
        }
        queries.push(Value::String(query.to_json().map_err(query_error)?));
    }
    if needs {
        let mut selected = vec!["*".to_owned()];
        if let Some(related) = related {
            for key in related_keys(&related).await {
                if !selected.contains(&key) {
                    selected.push(key);
                }
            }
        }
        let select = utopia_database::Query::from(utopia_query::Query::select(selected));
        queries.push(Value::String(select.to_json().map_err(query_error)?));
    }
    params.insert("queries".to_owned(), Value::Array(queries));
    Ok(())
}

/// `V20::getRelatedCollectionKeys()`: `key.*` for each available
/// relationship of the collection, and of the collections it relates to,
/// up to `RELATION_MAX_DEPTH` levels, never back to a collection on the
/// path.
async fn related_keys(related: &Related<'_>) -> Vec<String> {
    if related.database_id.is_empty() || related.collection_id.is_empty() {
        return Vec::new();
    }
    let db = related.db;
    let database =
        match db.authorization().skip(db.get_document("databases", &related.database_id, Vec::new(), false)).await {
            Ok(d) if !d.is_empty() => d,
            _ => return Vec::new(),
        };
    let namespace = format!("database_{}", crate::database::documents::sequence_text(&database));
    let mut cache: Vec<(String, Option<Document>)> = Vec::new();
    let mut keys = Vec::new();
    walk(db, &namespace, &related.collection_id, None, 1, Vec::new(), &mut cache, &mut keys).await;
    keys
}

/// `V20::walkRelatedCollectionKeys()`, appending to `out`.
#[allow(clippy::too_many_arguments)]
fn walk<'a>(
    db: &'a Database,
    namespace: &'a str,
    collection_id: &'a str,
    prefix: Option<String>,
    depth: i64,
    mut visited: Vec<String>,
    cache: &'a mut Vec<(String, Option<Document>)>,
    out: &'a mut Vec<String>,
) -> std::pin::Pin<Box<dyn std::future::Future<Output = ()> + Send + 'a>> {
    Box::pin(async move {
        if depth > utopia_database::database::RELATION_MAX_DEPTH || visited.iter().any(|v| v == collection_id) {
            return;
        }
        let collection = match cache.iter().find(|(id, _)| id == collection_id) {
            Some((_, c)) => c.clone(),
            None => {
                let found =
                    match db.authorization().skip(db.get_document(namespace, collection_id, Vec::new(), false)).await {
                        Ok(c) if !c.is_empty() => Some(c),
                        _ => None,
                    };
                cache.push((collection_id.to_owned(), found.clone()));
                found
            }
        };
        let Some(collection) = collection else { return };
        visited.push(collection_id.to_owned());
        let attributes = match collection.get("attributes") {
            Some(utopia_database::Value::Array(items)) => items.values().cloned().collect::<Vec<_>>(),
            _ => Vec::new(),
        };
        // This level's keys, each followed by its relationship's nested ones.
        let mut level: Vec<String> = Vec::new();
        for attribute in attributes {
            let text = |key: &str| -> Option<String> {
                let value = match utopia_database::value::as_document(&attribute) {
                    Some(d) => d.get(key),
                    None => match &attribute {
                        utopia_database::Value::Array(a) => a.get_str(key),
                        _ => None,
                    },
                };
                value.map(|v| utopia_database::value::to_text(v).to_string())
            };
            if text("type").as_deref() != Some("relationship") || text("status").as_deref() != Some("available") {
                continue;
            }
            let key = text("key").unwrap_or_default();
            let full = match &prefix {
                Some(p) if !p.is_empty() => format!("{p}.{key}"),
                _ => key,
            };
            let target = text("relatedCollection").filter(|t| !t.is_empty());
            if target.as_ref().is_some_and(|t| visited.contains(t)) {
                continue;
            }
            level.push(format!("{full}.*"));
            if let Some(target) = target {
                let mut nested = Vec::new();
                walk(db, namespace, &target, Some(full), depth + 1, visited.clone(), cache, &mut nested).await;
                level.extend(nested);
            }
        }
        for key in level {
            if !out.contains(&key) {
                out.push(key);
            }
        }
    })
}

/// `V21::parse()`: 1.8.0 params to 1.9.0.
fn v21(model: &str, params: &mut Map<String, Value>) {
    let unique = || Value::String("unique()".to_owned());
    match model {
        "project.createWebPlatform" => {
            fill(params, "platformId", unique());
            params.remove("store");
        }
        "project.updateWebPlatform" => {
            params.remove("store");
        }
        "project.createApplePlatform"
        | "project.createAndroidPlatform"
        | "project.createWindowsPlatform"
        | "project.createLinuxPlatform"
        | "project.updateApplePlatform"
        | "project.updateAndroidPlatform"
        | "project.updateWindowsPlatform"
        | "project.updateLinuxPlatform" => {
            if model.starts_with("project.create") {
                fill(params, "platformId", unique());
            }
            params.remove("store");
            let new = if model.ends_with("ApplePlatform") {
                "bundleIdentifier"
            } else if model.ends_with("AndroidPlatform") {
                "applicationId"
            } else if model.ends_with("WindowsPlatform") {
                "packageIdentifierName"
            } else {
                "packageName"
            };
            // `$content[$new] ?? $content['key'] ?? null`, then no `key`.
            let key = params.remove("key").filter(|v| !v.is_null()).unwrap_or(Value::Null);
            fill(params, new, key);
            params.remove("hostname");
        }
        "project.listPlatforms" => fill(params, "queries", limit(5000)),
        "webhooks.create" => fill(params, "webhookId", unique()),
        "project.createKey" | "organization.createProjectKey" => fill(params, "keyId", unique()),
        "project.createVariable" => fill(params, "variableId", unique()),
        // `APP_LIMIT_SUBQUERY`.
        "project.listVariables" => fill(params, "queries", limit(1000)),
        "functions.createTemplateDeployment" | "sites.createTemplateDeployment"
            if params.get("version").is_some_and(|v| !php_std::value::empty(v)) =>
        {
            let version = params.remove("version").unwrap_or(Value::Null);
            params.insert("type".to_owned(), Value::String("tag".to_owned()));
            params.insert("reference".to_owned(), version);
        }
        "functions.create" | "sites.create" | "functions.update" | "sites.update"
            if params.get("specification").is_some_and(|v| !php_std::value::empty(v)) =>
        {
            let specification = params.remove("specification").unwrap_or(Value::Null);
            params.insert("buildSpecification".to_owned(), specification.clone());
            params.insert("runtimeSpecification".to_owned(), specification);
        }
        _ => {}
    }
}

/// `[Query::limit($n)]`: PHP hands the route the `Query` itself, which its
/// `Queries` validator reads as this JSON.
fn limit(n: i64) -> Value {
    Value::Array(vec![Value::String(format!("{{\"method\":\"limit\",\"values\":[{n}]}}"))])
}

/// `V22::parse()`: 1.9.0 params to 1.9.1.
fn v22(model: &str, params: &mut Map<String, Value>) {
    match model {
        "project.updateService" => {
            rename(params, "service", "serviceId");
            rename(params, "status", "enabled");
        }
        "project.updateProtocol" => {
            rename(params, "api", "protocolId");
            rename(params, "status", "enabled");
            if params.get("protocolId").and_then(Value::as_str) == Some("realtime") {
                params.insert("protocolId".to_owned(), Value::String("websocket".to_owned()));
            }
        }
        "project.createKey"
        | "project.updateKey"
        | "organization.createProjectKey"
        | "organization.updateProjectKey" => scopes(params),
        "webhooks.create" | "webhooks.update" => {
            rename(params, "security", "tls");
            rename(params, "httpUser", "authUsername");
            rename(params, "httpPass", "authPassword");
        }
        _ => {}
    }
}

/// `parseKeyScopes()`: scopes that are not an array become `[]`.
fn scopes(params: &mut Map<String, Value>) {
    if !matches!(params.get("scopes"), Some(Value::Array(_) | Value::Object(_))) {
        params.insert("scopes".to_owned(), Value::Array(Vec::new()));
    }
}

/// `V23::parse()`: 1.9.1 params to 1.9.2.
fn v23(model: &str, params: &mut Map<String, Value>) {
    match model {
        "project.getEmailTemplate" | "project.deleteEmailTemplate" => rename(params, "type", "templateId"),
        "project.updateEmailTemplate" => {
            rename(params, "type", "templateId");
            rename(params, "replyTo", "replyToEmail");
        }
        "project.updateSMTP" => rename(params, "replyTo", "replyToEmail"),
        "project.updateMembershipPrivacyPolicy" => {
            params.insert("userId".to_owned(), Value::Bool(false));
            params.insert("userPhone".to_owned(), Value::Bool(false));
            rename(params, "mfa", "userMFA");
        }
        "project.updateSessionAlertPolicy" => rename(params, "alerts", "enabled"),
        "project.updateUserLimitPolicy"
        | "project.updatePasswordHistoryPolicy"
        | "project.updateSessionLimitPolicy" => {
            if let Some(limit) = params.get("limit").filter(|v| !v.is_null()).cloned() {
                // `=== 0`: the integer zero only.
                let zero = limit.is_i64() && limit.as_i64() == Some(0);
                params.insert("total".to_owned(), if zero { Value::Null } else { limit });
                params.remove("limit");
            }
        }
        "project.updateAuthMethod" => {
            rename(params, "status", "enabled");
            rename(params, "method", "methodId");
        }
        _ => {}
    }
}

/// `V24::parse()`: 1.9.2 params to 1.9.3.
fn v24(model: &str, params: &mut Map<String, Value>) {
    if model == "project.createStandardKey" {
        fill(params, "keyId", Value::String("unique()".to_owned()));
        scopes(params);
    }
}

/// `V25::parse()`: 1.9.3 params to 1.9.4.
fn v25(model: &str, params: &mut Map<String, Value>) {
    if matches!(model, "functions.createVariable" | "sites.createVariable") {
        fill(params, "variableId", Value::String("unique()".to_owned()));
    }
}

/// `V26::parse()`: 1.9.4 params to 1.9.5.
fn v26(model: &str, params: &mut Map<String, Value>) {
    const METADATA: [&str; 9] = [
        "description",
        "logo",
        "url",
        "legalName",
        "legalCountry",
        "legalState",
        "legalCity",
        "legalAddress",
        "legalTaxId",
    ];
    if model == "projects.create" {
        for key in METADATA {
            params.remove(key);
        }
    }
}

/// `V27::parse()`: bare keywords gained parentheses in 2.0.0, and the CSV
/// and JSON migrations name the database and the collection apart.
fn v27(model: &str, params: &mut Map<String, Value>) {
    match model {
        "migrations.createCSVImport"
        | "migrations.createCSVExport"
        | "migrations.createJSONImport"
        | "migrations.createJSONExport" => {
            let Some(Value::String(resource)) = params.get("resourceId") else { return };
            let Some((database_id, collection_id)) = resource.split_once(':') else { return };
            let (database_id, collection_id) = (database_id.to_owned(), collection_id.to_owned());
            fill(params, "databaseId", Value::String(database_id));
            fill(params, "collectionId", Value::String(collection_id));
            params.remove("resourceId");
        }
        "users.createJWT" if params.get("sessionId").and_then(Value::as_str) == Some("recent") => {
            params.insert("sessionId".to_owned(), Value::String("recent()".to_owned()));
        }
        _ => {}
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

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

    fn params(value: Value) -> Map<String, Value> {
        match value {
            Value::Object(map) => map,
            _ => unreachable!(),
        }
    }

    async fn filtered(format: Version, model: &str, value: Value) -> Value {
        let mut map = params(value);
        apply(format, model, &mut map, None).await.unwrap();
        Value::Object(map)
    }

    #[utopia_async::test]
    async fn v27_rewrites_the_recent_keyword() {
        let mut params = Map::new();
        params.insert("sessionId".to_owned(), Value::String("recent".to_owned()));
        apply(Version(1, 9, 5), "users.createJWT", &mut params, None).await.unwrap();
        assert_eq!(params["sessionId"], "recent()");
        apply(Version(2, 0, 0), "users.createJWT", &mut params, None).await.unwrap();
        assert_eq!(params["sessionId"], "recent()");
    }

    #[utopia_async::test]
    async fn v27_splits_the_migration_resource() {
        let got = filtered(Version(1, 9, 5), "migrations.createCSVImport", json!({"resourceId": "db:col:x"})).await;
        assert_eq!(got, json!({"databaseId": "db", "collectionId": "col:x"}));
        let kept = filtered(Version(1, 9, 5), "migrations.createCSVImport", json!({"resourceId": "db"})).await;
        assert_eq!(kept, json!({"resourceId": "db"}));
        let explicit =
            filtered(Version(1, 9, 5), "migrations.createJSONExport", json!({"resourceId": "a:b", "databaseId": "c"}))
                .await;
        assert_eq!(explicit, json!({"databaseId": "c", "collectionId": "b"}));
    }

    #[utopia_async::test]
    async fn filters_apply_below_their_version_only() {
        // V16 (before 1.4.0) runs for 1.3, not for 1.4.
        let old = filtered(Version(1, 3, 0), "functions.create", json!({"runtime": "node-18.0"})).await;
        assert_eq!(old["commands"], "npm install");
        let new = filtered(Version(1, 4, 0), "functions.create", json!({"runtime": "node-18.0"})).await;
        assert!(new.get("commands").is_none());
        // V16 renames an execution's data, then V18 and V19 run on the result.
        let execution = filtered(Version(1, 3, 0), "functions.createExecution", json!({"data": "x"})).await;
        assert_eq!(execution, json!({"body": "x"}));
        let empty = filtered(Version(1, 3, 0), "functions.createExecution", json!({})).await;
        assert_eq!(empty, json!({"body": ""}));
    }

    #[utopia_async::test]
    async fn v18_and_v19_reshape_functions() {
        let mfa =
            filtered(Version(1, 5, 0), "account.deleteMfaAuthenticator", json!({"otp": "1", "type": "totp"})).await;
        assert_eq!(mfa, json!({"type": "totp"}));
        // V18 moves the branch to the version, V19 (still before 1.7) drops it.
        let create = filtered(Version(1, 5, 0), "functions.create", json!({"templateBranch": "main"})).await;
        assert_eq!(create, json!({}));
        let queries = filtered(
            Version(1, 6, 0),
            "functions.list",
            json!({"queries": ["{\"method\":\"equal\",\"attribute\":\"deployment\",\"values\":[\"x\"]}", "nope"]}),
        )
        .await;
        assert_eq!(
            queries["queries"],
            json!(["{\"method\":\"equal\",\"attribute\":\"deploymentId\",\"values\":[\"x\"]}", "null"])
        );
        let variable = filtered(Version(1, 6, 0), "functions.createVariable", json!({"key": "k"})).await;
        assert_eq!(variable, json!({"key": "k", "secret": false, "variableId": "unique()"}));
    }

    #[utopia_async::test]
    async fn v20_selects_everything_for_documents() {
        let none = filtered(Version(1, 7, 0), "databases.listDocuments", json!({})).await;
        assert_eq!(none["queries"], json!(["{\"method\":\"select\",\"values\":[\"*\"]}"]));
        let limited = filtered(
            Version(1, 7, 0),
            "databases.getDocument",
            json!({"queries": ["", "{\"method\":\"limit\",\"values\":[1]}", "{\"method\":\"select\",\"values\":[\"*\"]}"]}),
        )
        .await;
        assert_eq!(
            limited["queries"],
            json!(["{\"method\":\"limit\",\"values\":[1]}", "{\"method\":\"select\",\"values\":[\"*\"]}"])
        );
        let chosen = filtered(
            Version(1, 7, 0),
            "databases.listDocuments",
            json!({"queries": ["{\"method\":\"select\",\"values\":[\"name\"]}"]}),
        )
        .await;
        assert_eq!(chosen["queries"], json!(["{\"method\":\"select\",\"values\":[\"name\"]}"]));
        let mut invalid = params(json!({"queries": ["{"]}));
        let error = apply(Version(1, 7, 0), "databases.listDocuments", &mut invalid, None).await.unwrap_err();
        assert_eq!(error.kind, ErrorType::GeneralQueryInvalid);
    }

    #[utopia_async::test]
    async fn v21_to_v26_rename_project_params() {
        let apple = filtered(
            Version(1, 8, 0),
            "project.createApplePlatform",
            json!({"key": "com.x", "store": "s", "hostname": "h", "name": "n"}),
        )
        .await;
        assert_eq!(apple, json!({"name": "n", "platformId": "unique()", "bundleIdentifier": "com.x"}));
        let platforms = filtered(Version(1, 8, 0), "project.listPlatforms", json!({})).await;
        assert_eq!(platforms["queries"], json!(["{\"method\":\"limit\",\"values\":[5000]}"]));
        let protocol =
            filtered(Version(1, 9, 0), "project.updateProtocol", json!({"api": "realtime", "status": true})).await;
        assert_eq!(protocol, json!({"protocolId": "websocket", "enabled": true}));
        let key = filtered(Version(1, 9, 0), "project.updateKey", json!({"scopes": "x"})).await;
        assert_eq!(key, json!({"scopes": []}));
        let limit = filtered(Version(1, 9, 1), "project.updateUserLimitPolicy", json!({"limit": 0})).await;
        assert_eq!(limit, json!({"total": null}));
        let limit = filtered(Version(1, 9, 1), "project.updateUserLimitPolicy", json!({"limit": 5})).await;
        assert_eq!(limit, json!({"total": 5}));
        let standard = filtered(Version(1, 9, 2), "project.createStandardKey", json!({})).await;
        assert_eq!(standard, json!({"keyId": "unique()", "scopes": []}));
        let site = filtered(Version(1, 9, 3), "sites.createVariable", json!({})).await;
        assert_eq!(site, json!({"variableId": "unique()"}));
        let project = filtered(Version(1, 9, 4), "projects.create", json!({"name": "p", "logo": "l"})).await;
        assert_eq!(project, json!({"name": "p"}));
        let current = filtered(Version(1, 9, 5), "projects.create", json!({"name": "p", "logo": "l"})).await;
        assert_eq!(current, json!({"name": "p", "logo": "l"}));
    }
}
