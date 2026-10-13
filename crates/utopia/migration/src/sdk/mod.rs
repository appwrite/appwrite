//! The Appwrite API, as the Appwrite PHP SDK 30.2.2 calls it.
//!
//! The official Rust SDK (`appwrite`) is not used: it pins dependency
//! versions older than the workspace's and builds request bodies from hash
//! maps, and its models fill and check fields differently, so neither its
//! requests nor its models match the PHP library's. This module
//! sends the PHP SDK's requests byte for byte (headers, query strings, JSON
//! and multipart bodies) on `utopia-client`, and hydrates its models with
//! the same checks; the service methods, models and enums the migration
//! library uses are tables generated from the PHP SDK ([`spec`]).
//!
//! PHP: `Appwrite\Client`, `Appwrite\Services\*`, `Appwrite\Models\*`.

pub mod query;
pub mod spec;
pub mod spec_types;

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::encoding::{QueryEncoding, http_build_query};
use php_std::{Array, ArrayKey, Value};
use utopia_client::{Anywhere, Http, Request};

use crate::error::{Error, Result};
use crate::http::{Part, multipart};
use spec_types::{Field, Method};

/// The SDK version the requests name.
pub const VERSION: &str = "30.2.2";

/// The response format the requests ask for.
pub const RESPONSE_FORMAT: &str = "2.0.0";

/// A client of one project's API.
///
/// PHP: `Appwrite\Client`.
#[derive(Debug, Clone)]
pub struct Client {
    endpoint: String,
    headers: IndexMap<String, String>,
    config: IndexMap<String, String>,
    timeout: Option<i64>,
    connect_timeout: Option<i64>,
}

impl Default for Client {
    fn default() -> Self {
        Self::new()
    }
}

/// A request parameter: a value, or a file.
#[derive(Debug, Clone, PartialEq)]
pub enum Input {
    /// A value.
    Value(Value),
    /// An SDK enum value: its value in JSON, nothing in a query string.
    Enum(String),
    /// A file (PHP: `CURLFile`).
    File {
        /// The bytes.
        data: Bytes,
        /// The MIME type.
        mime: String,
        /// The file name.
        name: String,
    },
}

/// A response body: decoded JSON, or the raw body.
pub type Response = Value;

impl Client {
    /// A client of `https://cloud.appwrite.io/v1` with the SDK's headers.
    pub fn new() -> Self {
        let mut headers = IndexMap::new();
        headers.insert("user-agent".to_owned(), format!("AppwritePHPSDK/{VERSION} ()"));
        headers.insert("x-sdk-name".to_owned(), "PHP".to_owned());
        headers.insert("x-sdk-platform".to_owned(), "server".to_owned());
        headers.insert("x-sdk-language".to_owned(), "php".to_owned());
        headers.insert("x-sdk-version".to_owned(), VERSION.to_owned());
        headers.insert("X-Appwrite-Response-Format".to_owned(), RESPONSE_FORMAT.to_owned());
        Self {
            endpoint: "https://cloud.appwrite.io/v1".to_owned(),
            headers,
            config: IndexMap::new(),
            timeout: None,
            connect_timeout: None,
        }
    }

    /// Sets the endpoint; it must start with `http://` or `https://`.
    pub fn set_endpoint(&mut self, endpoint: &str) -> Result<&mut Self> {
        if !endpoint.starts_with("http://") && !endpoint.starts_with("https://") {
            return Err(Error::Appwrite {
                message: format!("Invalid endpoint URL: {endpoint}"),
                code: 0,
                error_type: String::new(),
                response: String::new(),
            });
        }
        self.endpoint = endpoint.to_owned();
        Ok(self)
    }

    /// The endpoint.
    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    /// Sets the seconds a request may take in total.
    ///
    /// PHP: `Client::setTimeout()`.
    pub fn set_timeout(&mut self, seconds: i64) -> &mut Self {
        self.timeout = Some(seconds);
        self
    }

    /// Sets the seconds to wait for the connection.
    ///
    /// PHP: `Client::setConnectTimeout()`.
    pub fn set_connect_timeout(&mut self, seconds: i64) -> &mut Self {
        self.connect_timeout = Some(seconds);
        self
    }

    /// Sets the project.
    pub fn set_project(&mut self, project: &str) -> &mut Self {
        self.config.insert("project".to_owned(), project.to_owned());
        self
    }

    /// Sets the API key (the `X-Appwrite-Key` header).
    pub fn set_key(&mut self, key: &str) -> &mut Self {
        self.add_header("X-Appwrite-Key", key);
        self.config.insert("key".to_owned(), key.to_owned());
        self
    }

    /// Adds a header (its name lowercased).
    pub fn add_header(&mut self, key: &str, value: &str) -> &mut Self {
        self.headers.insert(key.to_ascii_lowercase(), value.to_owned());
        self
    }

    /// A configured value (`project`, `key`), or `''`.
    pub fn config(&self, key: &str) -> String {
        self.config.get(key).cloned().unwrap_or_default()
    }

    /// The headers every request sends.
    pub fn headers(&self) -> &IndexMap<String, String> {
        &self.headers
    }

    /// Sends a request: GET parameters in the query string, others in a
    /// body of the request's `content-type` (JSON, multipart, or a form).
    /// Fails with an Appwrite error on a status of 400 or more.
    ///
    /// PHP: `Client::call($method, $path, $headers, $params)`, which builds
    /// the request with `Utopia\Psr7\Request\Factory`.
    pub async fn call(
        &self,
        method: &str,
        path: &str,
        headers: &[(&str, String)],
        params: &[(String, Input)],
    ) -> Result<Response> {
        let mut all = self.headers.clone();
        for (k, v) in headers {
            all.insert((*k).to_owned(), v.clone());
        }
        let content_type = all.get("content-type").map(|v| v.to_ascii_lowercase()).unwrap_or_default();
        if content_type.is_empty() {
            all.shift_remove("content-type");
        }
        let plain = |params: &[(String, Input)], json: bool| -> Array {
            let mut a = Array::new();
            for (k, v) in params {
                match v {
                    Input::Value(v) => {
                        a.set(ArrayKey::from(k.as_str()), v.clone());
                    }
                    Input::Enum(e) if json => {
                        a.set(ArrayKey::from(k.as_str()), Value::from(e.as_str()));
                    }
                    _ => {}
                }
            }
            a
        };
        let mut url = format!("{}{path}", self.endpoint);
        let body;
        if method == "GET" {
            // `Factory::query()`: the parameters appended to the URI's query.
            if !params.is_empty() {
                let built = query(&plain(params, false));
                let (base, existing) = match url.split_once('?') {
                    Some((base, q)) => (base.to_owned(), q.to_owned()),
                    None => (url.clone(), String::new()),
                };
                let full = if existing.is_empty() { built } else { format!("{existing}&{built}") };
                url = if full.is_empty() { base } else { format!("{base}?{full}") };
            }
            body = None;
        } else if content_type == "application/json" {
            let encoded = php_std::json::encode(
                &Value::Array(plain(params, true)),
                php_std::json::Flags::NONE,
                php_std::json::DEFAULT_DEPTH,
            )
            .map_err(json_error)?;
            body = Some(encoded.into_bytes());
            all.entry("accept".to_owned()).or_insert_with(|| "application/json".to_owned());
        } else if content_type == "multipart/form-data" {
            let mut parts = Vec::new();
            for (k, v) in params {
                match v {
                    Input::Value(v) => flatten(k, v, &mut parts),
                    // Enums are `Stringable`: a field of their value.
                    Input::Enum(e) => parts.push((k.clone(), Part::Field(Value::from(e.as_str())))),
                    Input::File { data, mime, name } => parts
                        .push((k.clone(), Part::File { data: data.clone(), mime: mime.clone(), name: name.clone() })),
                }
            }
            let (ct, multipart_body) = multipart(&parts);
            all.insert("content-type".to_owned(), ct);
            body = Some(multipart_body);
        } else {
            body = Some(query(&plain(params, false)).into_bytes());
            all.entry("content-type".to_owned()).or_insert_with(|| "application/x-www-form-urlencoded".to_owned());
        }
        let mut client = utopia_client::Client::new(Http::new())
            .with_destinations(Anywhere)
            .with_follow_redirects(true, 50)
            .map_err(transport)?;
        if let Some(seconds) = self.timeout {
            client = client.with_timeout(seconds as f64).map_err(transport)?;
        }
        if let Some(seconds) = self.connect_timeout {
            client = client.with_connect_timeout(seconds as f64).map_err(transport)?;
        }
        let mut request = Request::new(method, &url).map_err(transport)?;
        for (k, v) in &all {
            if v.is_empty() {
                continue;
            }
            request = request.header(k, v).map_err(transport)?;
        }
        if let Some(body) = body {
            request = request.body(body);
        }
        let response = client.send(request).await.map_err(transport)?;
        let status = response.status();
        // `getHeaderLine('content-type')`: its values joined, case kept.
        let mut content = String::new();
        for (name, values) in response.headers().iter() {
            if name.trim().eq_ignore_ascii_case("content-type") {
                content = values.iter().map(|v| v.trim()).collect::<Vec<_>>().join(", ");
            }
        }
        let raw = response.bytes().clone();
        let decoded = if content.starts_with("application/json") {
            php_std::json::decode(&raw, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
                .unwrap_or(Value::Null)
        } else {
            Value::Str(php_std::Str::from_bytes(raw.clone()))
        };
        if status >= 400 {
            return Err(match &decoded {
                Value::Array(a) => Error::Appwrite {
                    message: a.get_str("message").map(crate::resources::cast_string).unwrap_or_default(),
                    code: i64::from(status),
                    error_type: a.get_str("type").map(crate::resources::cast_string).unwrap_or_default(),
                    response: php_std::json::encode(&decoded, php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
                        .unwrap_or_default(),
                },
                other => {
                    let text = crate::resources::cast_string(other);
                    Error::Appwrite {
                        message: text.clone(),
                        code: i64::from(status),
                        error_type: String::new(),
                        response: text,
                    }
                }
            });
        }
        Ok(decoded)
    }

    /// Calls `service`'s method `name` with `args` (by name; missing ones
    /// take their defaults) and hydrates the response's model.
    ///
    /// PHP: `(new Appwrite\Services\<service>($client))-><name>(...$args)`.
    pub async fn invoke(&self, service: &str, name: &str, args: &[(&str, Value)]) -> Result<Model> {
        let method = spec::METHODS
            .iter()
            .find(|m| m.service == service && m.name == name)
            .ok_or_else(|| Error::exception(format!("No SDK method {service}::{name}"), 0))?;
        if method.chunked {
            // `createFile()` reads the `InputFile`'s path first; the
            // migration library only calls it with `new InputFile()`.
            return Err(Error::Other {
                class: "Error".into(),
                message: "Typed property Appwrite\\InputFile::$path must not be accessed before initialization".into(),
                code: 0,
            });
        }
        let arg = |n: &str| -> Value {
            if let Some((_, v)) = args.iter().find(|(k, _)| *k == n) {
                return v.clone();
            }
            method.args.iter().find(|a| a.name == n).and_then(|a| a.default).map_or(Value::Null, literal)
        };
        let mut path = method.path.to_owned();
        for a in method.args {
            let placeholder = format!("{{{}}}", a.name);
            if path.contains(&placeholder) {
                path = path.replace(&placeholder, &crate::resources::cast_string(&arg(a.name)));
            }
        }
        let mut params: Vec<(String, Input)> = Vec::new();
        for p in method.params {
            let v = arg(p.arg);
            if p.conditional && matches!(v, Value::Null) {
                continue;
            }
            if p.enumerated && !matches!(v, Value::Null) {
                params.push((p.key.to_owned(), Input::Enum(crate::resources::cast_string(&v))));
            } else {
                params.push((p.key.to_owned(), Input::Value(v)));
            }
        }
        let mut headers: Vec<(&str, String)> = vec![("X-Appwrite-Project", self.config("project"))];
        for (k, v) in method.headers {
            headers.push((k, (*v).to_owned()));
        }
        let response = self.call(method.http, &path, &headers, &params).await?;
        if !method.variants.is_empty() {
            let Value::Array(data) = &response else {
                return Err(Error::UnexpectedValue("Expected array response when hydrating a response model.".into()));
            };
            for (key, value, model) in method.variants {
                if matches!(data.get_str(key), Some(Value::Str(s)) if s.as_bytes() == value.as_bytes()) {
                    return hydrate(model, data);
                }
            }
            return Err(Error::UnexpectedValue("Unable to match response to any expected response model.".into()));
        }
        let Some(model) = method.model else {
            return Ok(Model { class: "", props: vec![("response", MValue::Plain(response))] });
        };
        let Value::Array(data) = response else {
            return Err(Error::UnexpectedValue("Expected array response when hydrating a response model.".into()));
        };
        hydrate(model, &data)
    }

    /// The method `service::name` of the SDK tables.
    pub fn method(service: &str, name: &str) -> Option<&'static Method> {
        spec::METHODS.iter().find(|m| m.service == service && m.name == name)
    }
}

/// `JsonException` (`JSON_THROW_ON_ERROR`) as the client rethrows it.
fn json_error(e: php_std::json::Error) -> Error {
    Error::Appwrite {
        message: e.message().to_owned(),
        code: e.code().code(),
        error_type: String::new(),
        response: String::new(),
    }
}

fn transport(e: utopia_client::Error) -> Error {
    Error::Appwrite { message: e.to_string(), code: 0, error_type: String::new(), response: String::new() }
}

/// `http_build_query($params, '', '&', PHP_QUERY_RFC3986)`.
fn query(params: &Array) -> String {
    String::from_utf8_lossy(&http_build_query(
        &Value::Array(params.clone()).to_json(),
        b"",
        None,
        QueryEncoding::Rfc3986,
    ))
    .into_owned()
}

/// `Client::flatten()`: nested arrays as `key[sub]` fields.
fn flatten(prefix: &str, value: &Value, out: &mut Vec<(String, Part)>) {
    match value {
        Value::Array(a) => {
            for (k, v) in a.iter() {
                let key = match k {
                    php_std::types::KeyRef::Int(i) => format!("{prefix}[{i}]"),
                    php_std::types::KeyRef::Str(s) => format!("{prefix}[{}]", String::from_utf8_lossy(s)),
                };
                flatten(&key, v, out);
            }
        }
        v => out.push((prefix.to_owned(), Part::Field(v.clone()))),
    }
}

/// A PHP default expression's value.
fn literal(expr: &str) -> Value {
    match expr {
        "null" => Value::Null,
        "true" => Value::Bool(true),
        "false" => Value::Bool(false),
        "[]" => Value::Array(Array::new()),
        e if e.starts_with('\'') && e.ends_with('\'') && e.len() >= 2 => Value::from(&e[1..e.len() - 1]),
        e => e.parse::<i64>().map(Value::Int).unwrap_or(Value::Null),
    }
}

/// A value of a model property.
#[derive(Debug, Clone, PartialEq)]
pub enum MValue {
    /// A plain value.
    Plain(Value),
    /// A nested model.
    Model(Box<Model>),
    /// An enum value (its class and value).
    Enum(&'static str, String),
    /// A list (or map) of hydrated values.
    List(Vec<(ArrayKey, MValue)>),
}

impl MValue {
    /// `serializeValue()`: models as their arrays, enums as their values.
    pub fn serialize(&self) -> Value {
        match self {
            MValue::Plain(v) => v.clone(),
            MValue::Model(m) => Value::Array(m.to_array()),
            MValue::Enum(_, v) => Value::from(v.as_str()),
            MValue::List(items) => {
                let mut a = Array::new();
                for (k, v) in items {
                    a.set(k.clone(), v.serialize());
                }
                Value::Array(a)
            }
        }
    }
}

/// A hydrated response model.
///
/// PHP: an `Appwrite\Models\*` instance.
#[derive(Debug, Clone, PartialEq)]
pub struct Model {
    /// The class name (`User`, `UserList`, ...; empty for a method without
    /// a model, whose one property `response` is the raw response).
    pub class: &'static str,
    /// The properties, in constructor order.
    pub props: Vec<(&'static str, MValue)>,
}

impl Model {
    /// The property `prop` (`$model->prop`).
    pub fn get(&self, prop: &str) -> Option<&MValue> {
        self.props.iter().find(|(p, _)| *p == prop).map(|(_, v)| v)
    }

    /// The property `prop`, serialized.
    pub fn value(&self, prop: &str) -> Value {
        self.get(prop).map_or(Value::Null, MValue::serialize)
    }

    /// The property `prop` as a string.
    pub fn string(&self, prop: &str) -> String {
        match self.value(prop) {
            Value::Null => String::new(),
            v => crate::resources::cast_string(&v),
        }
    }

    /// The items of a list property that holds models.
    pub fn models(&self, prop: &str) -> Vec<&Model> {
        match self.get(prop) {
            Some(MValue::List(items)) => items
                .iter()
                .filter_map(|(_, v)| match v {
                    MValue::Model(m) => Some(&**m),
                    _ => None,
                })
                .collect(),
            _ => Vec::new(),
        }
    }

    /// A nested model property.
    pub fn model(&self, prop: &str) -> Option<&Model> {
        match self.get(prop) {
            Some(MValue::Model(m)) => Some(m),
            _ => None,
        }
    }

    /// `toArray()`.
    pub fn to_array(&self) -> Array {
        let Some(spec) = spec::MODELS.iter().find(|m| m.name == self.class) else {
            return Array::new();
        };
        let mut out = Array::new();
        for (key, prop) in spec.to_array {
            out.set(ArrayKey::from(*key), self.value(prop));
        }
        if let Some(f) = spec.fields.iter().find(|f| f.additional)
            && let Value::Array(extra) = self.value(f.prop)
        {
            for (k, v) in extra.iter() {
                out.set(k.to_owned(), v.clone());
            }
        }
        out
    }
}

fn class(name: &str) -> String {
    format!("Appwrite\\Models\\{name}")
}

fn enum_spec(name: &str) -> Option<&'static spec_types::Enum> {
    spec::ENUMS.iter().find(|e| e.name == name)
}

/// `static::hydrateTypedValue($type, $value, $allowsNull)`.
fn hydrate_typed(ty: &'static str, value: &Value, allows_null: bool) -> Result<MValue> {
    if matches!(value, Value::Null) {
        return Ok(MValue::Plain(Value::Null));
    }
    let is_model = spec::MODELS.iter().any(|m| m.name == ty);
    let enumeration = enum_spec(ty);
    if !is_model && enumeration.is_none() {
        return Ok(MValue::Plain(value.clone()));
    }
    if allows_null && matches!(value, Value::Str(s) if s.is_empty()) {
        return Ok(MValue::Plain(Value::Null));
    }
    if let Some(e) = enumeration {
        if matches!(value, Value::Array(_)) {
            return Err(Error::TypeError(format!(
                "Appwrite\\Enums\\{ty}::from(): Argument #1 ($value) must be of type string, array given"
            )));
        }
        let v = crate::resources::cast_string(value);
        return match e.values.iter().find(|x| **x == v) {
            Some(x) => Ok(MValue::Enum(e.name, (*x).to_owned())),
            None => Err(Error::InvalidArgument(format!("Unknown {ty} value: {v}"))),
        };
    }
    // `$type::from((string) $value)`: a model gets a string.
    match value {
        Value::Array(a) => Ok(MValue::Model(Box::new(hydrate(ty, a)?))),
        _ => Err(Error::TypeError(format!(
            "{}::from(): Argument #1 ($data) must be of type array, string given",
            class(ty)
        ))),
    }
}

/// `Model::from($data)`: absent keys given their defaults, the required
/// keys checked, nested models and enums hydrated, and the constructor
/// called in strict mode (the models declare `strict_types`).
pub fn hydrate(name: &'static str, data: &Array) -> Result<Model> {
    let spec = spec::MODELS
        .iter()
        .find(|m| m.name == name)
        .ok_or_else(|| Error::exception(format!("No SDK model {name}"), 0))?;
    let mut filled;
    let data = if spec.defaults.iter().any(|(key, _)| data.get_str(key).is_none()) {
        filled = data.clone();
        for (key, json) in spec.defaults {
            if filled.get_str(key).is_none() {
                let value = php_std::json::decode(
                    json.as_bytes(),
                    Some(true),
                    php_std::json::DEFAULT_DEPTH,
                    php_std::json::Flags::NONE,
                )
                .unwrap_or(Value::Null);
                filled.set(ArrayKey::from(*key), value);
            }
        }
        &filled
    } else {
        data
    };
    for key in spec.required {
        if data.get_str(key).is_none() {
            return Err(Error::InvalidArgument(format!("Missing required field \"{key}\" for {}.", class(name))));
        }
    }
    let ctor = constructor(spec.name);
    let mut props = Vec::with_capacity(spec.fields.len());
    for (position, field) in spec.fields.iter().enumerate() {
        let value = if field.additional {
            additional(spec.known.unwrap_or(&[]), data)
        } else {
            data.get_str(field.key).cloned().unwrap_or(Value::Null)
        };
        let hydrated = match field.hydrate {
            Some(h) if field.list => match &value {
                Value::Array(items) => {
                    let mut list = Vec::new();
                    for (k, item) in items.iter() {
                        list.push((k.to_owned(), hydrate_typed(h, item, field.allows_null)?));
                    }
                    MValue::List(list)
                }
                v => MValue::Plain(coerce(field, v, ctor, position + 1)?),
            },
            Some(h) => {
                let v = hydrate_typed(h, &value, field.allows_null)?;
                if matches!(v, MValue::Plain(Value::Null)) && !field.nullable {
                    return Err(Error::TypeError(format!(
                        "{ctor}(): Argument #{} (${}) must be of type {}, null given",
                        position + 1,
                        field.prop,
                        qualified(field.ty)
                    )));
                }
                v
            }
            None => MValue::Plain(coerce(field, &value, ctor, position + 1)?),
        };
        props.push((field.prop, hydrated));
    }
    Ok(Model { class: spec.name, props })
}

/// `Appwrite\\Models\\<name>::__construct`, interned once per model.
fn constructor(name: &'static str) -> &'static str {
    use std::collections::HashMap;
    use std::sync::{Mutex, OnceLock};
    static NAMES: OnceLock<Mutex<HashMap<&'static str, &'static str>>> = OnceLock::new();
    let mut names = NAMES.get_or_init(Default::default).lock().unwrap_or_else(|e| e.into_inner());
    names.entry(name).or_insert_with(|| Box::leak(format!("{}::__construct", class(name)).into_boxed_str()))
}

fn qualified(ty: &str) -> String {
    if spec::MODELS.iter().any(|m| m.name == ty) {
        class(ty)
    } else if enum_spec(ty).is_some() {
        format!("Appwrite\\Enums\\{ty}")
    } else {
        ty.to_owned()
    }
}

/// `extractAdditionalPropertiesFromFields($data, $knownFields)`.
fn additional(known: &[&str], data: &Array) -> Value {
    if !known.contains(&"data")
        && let Some(Value::Array(inner)) = data.get_str("data")
    {
        return Value::Array(inner.clone());
    }
    let mut out = Array::new();
    for (k, v) in data.iter() {
        let is_known = match k {
            php_std::types::KeyRef::Str(s) => known.iter().any(|kn| kn.as_bytes() == s),
            php_std::types::KeyRef::Int(i) => known.iter().any(|kn| kn.parse::<i64>().ok() == Some(i)),
        };
        if !is_known {
            out.set(k.to_owned(), v.clone());
        }
    }
    Value::Array(out)
}

/// A property value as its declared type, as the engine passes arguments
/// in strict mode: only the type itself (or `null` for a nullable one), an
/// `int` widened for a `float`, and a `TypeError` for anything else.
fn coerce(field: &Field, value: &Value, ctor: &'static str, position: usize) -> Result<Value> {
    let accepted = match (field.ty, value) {
        (_, Value::Null) if field.nullable => Some(Value::Null),
        ("string", Value::Str(_)) | ("int", Value::Int(_)) | ("float", Value::Float(_)) | ("bool", Value::Bool(_)) => {
            Some(value.clone())
        }
        ("float", Value::Int(i)) => Some(Value::Float(*i as f64)),
        ("array", Value::Array(_)) => Some(value.clone()),
        ("string" | "int" | "float" | "bool" | "array", _) => None,
        _ => Some(value.clone()),
    };
    accepted.ok_or_else(|| {
        let given = match value {
            Value::Null => "null",
            Value::Bool(_) => "bool",
            Value::Int(_) => "int",
            Value::Float(_) => "float",
            Value::Str(_) => "string",
            Value::Array(_) => "array",
            Value::Object(_) => "stdClass",
            Value::Ext(_) => "object",
        };
        let expected = if field.nullable { format!("?{}", field.ty) } else { field.ty.to_owned() };
        Error::TypeError(format!(
            "{ctor}(): Argument #{position} (${}) must be of type {expected}, {given} given",
            field.prop
        ))
    })
}
