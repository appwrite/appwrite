//! Compat adapter for `psr7`: maps `tests/compat/psr7/spec.json` operations onto `utopia-psr7`.
//!
//! Objects (URIs, streams, messages, parts, uploaded files) live behind
//! handles, as in `tests/compat/psr7/Adapter.php`; `*.with` and `*.call`
//! operations call one method by its PHP name.

use std::io::SeekFrom;

use indexmap::IndexMap;
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value as Php};
use serde_json::{Map, Value, json};
use utopia_psr7::multipart::{Body, Part, Received};
use utopia_psr7::request::{self, Target};
use utopia_psr7::response::ResponseFactory;
use utopia_psr7::server_request::{self, ServerRequestFactory};
use utopia_psr7::stream::{self, Detached, Meta, StreamFactory};
use utopia_psr7::uri::{self, UriFactory};
use utopia_psr7::xml::Element;
use utopia_psr7::{
    Error, Message, Request, Response, ServerRequest, StreamRef, TempStream, UploadedFile, UploadedFiles, Uri,
    content_type, header, method, uploaded_file,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "constants",
    "fixture.write",
    "uri.inspect",
    "uri.change",
    "header.set",
    "response.read",
    "uri.parse",
    "uri.factory",
    "uri.new",
    "uri.with",
    "uri.dump",
    "stream.new",
    "stream.factory",
    "stream.from_file",
    "stream.from_resource",
    "stream.from_detached",
    "stream.call",
    "request.new",
    "request.factory",
    "response.new",
    "response.factory",
    "server_request.new",
    "server_request.factory",
    "message.with",
    "message.get",
    "message.body",
    "message.uri",
    "message.dump",
    "response.call",
    "part.field",
    "part.file",
    "part.body",
    "part.dump",
    "multipart.body",
    "multipart.dump",
    "multipart.render",
    "received.new",
    "uploaded.new",
    "uploaded.call",
    "uploaded.normalize",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("uri_parse", "Parse a URI with credentials, a port, a path, a query and a fragment"),
    ("create_request", "Create a request from a method and URI string (Host set from the URI)"),
    ("headers", "Set three headers on a response and read one back case-insensitively"),
    ("json_request", "Build a JSON request with an Authorization header"),
    ("json_response", "Decode a JSON response body"),
    ("multipart_response", "Split a two-part multipart response and read each part's name"),
];

const BODY: &str = r#"{"id":"6f1c","name":"Ada Lovelace","email":"ada@example.com","roles":["admin","owner"],"active":true,"logins":42}"#;

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    Ok(match name {
        "uri_parse" => Bench::sync(|| {
            if let Ok(uri) =
                Uri::parse(black_box("https://user:pass@api.example.com:8443/v1/users/6f1c?limit=25&cursor=abc#top"))
            {
                black_box(uri.to_string());
            }
        }),
        "create_request" => Bench::sync(|| {
            let _ = black_box(
                request::Factory::default()
                    .create_request("get", black_box("https://api.example.com/v1/users?limit=25")),
            );
        }),
        "headers" => Bench::sync(|| {
            let response = Response::new(200, "")
                .and_then(|r| r.with_header("Content-Type", "application/json"))
                .and_then(|r| r.with_header("X-Request-Id", "abc"))
                .and_then(|r| r.with_added_header("Cache-Control", ["no-cache", "no-store"]));
            if let Ok(response) = response {
                black_box(response.header_line(black_box("cache-control")));
            }
        }),
        "json_request" => {
            let data = json!({"name": "Ada", "roles": ["admin"]});
            let headers = [("Authorization", vec!["Bearer token".to_owned()])];
            Bench::sync(move || {
                let _ = black_box(request::Factory::default().json(
                    "POST",
                    "https://api.example.com/v1/users",
                    black_box(&data),
                    &headers,
                ));
            })
        }
        "json_response" => Bench::sync(|| {
            if let Ok(response) = Response::new(200, "") {
                let response = response.with_body(StreamRef::from_bytes(BODY.as_bytes()));
                let _ = black_box(response.json(true, 512, Default::default()));
            }
        }),
        "multipart_response" => {
            let body = b"--abc123\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--abc123\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"ada.png\"\r\nContent-Type: image/png\r\n\r\nimage-bytes\r\n--abc123--\r\n";
            Bench::sync(move || {
                let response = Response::new(200, "")
                    .and_then(|r| r.with_header("Content-Type", "multipart/form-data; boundary=abc123"))
                    .map(|r| r.with_body(StreamRef::from_bytes(body)));
                if let Ok(parts) = response.and_then(|r| r.multipart()) {
                    black_box((parts[0].name(), parts[1].filename()));
                }
            })
        }
        _ => return Err(Fault::new(format!("psr7: no benchmark `{name}`"))),
    })
}

fn err(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.message())
}

/// A message behind a handle.
#[derive(Clone)]
#[allow(clippy::large_enum_variant)]
enum Msg {
    Request(Request),
    Response(Response),
    Server(ServerRequest),
}

/// Runs a closure on the message whatever its kind, keeping the kind.
macro_rules! each {
    ($msg:expr, $m:ident => $body:expr) => {
        match $msg {
            Msg::Request($m) => $body.map(Msg::Request),
            Msg::Response($m) => $body.map(Msg::Response),
            Msg::Server($m) => $body.map(Msg::Server),
        }
    };
}

macro_rules! read {
    ($msg:expr, $m:ident => $body:expr) => {
        match $msg {
            Msg::Request($m) => $body,
            Msg::Response($m) => $body,
            Msg::Server($m) => $body,
        }
    };
}

/// An argument by position.
fn arg(args: &[Value], i: usize) -> Result<&Value, Fault> {
    args.get(i).ok_or_else(|| Fault::new(format!("missing argument {i}")))
}

fn arg_str(args: &[Value], i: usize) -> Result<String, Fault> {
    match arg(args, i)? {
        Value::String(s) => Ok(s.clone()),
        other => Err(Fault::new(format!("argument {i} must be a string, got {other}"))),
    }
}

fn arg_bytes(args: &[Value], i: usize) -> Result<Vec<u8>, Fault> {
    bytes(arg(args, i)?).ok_or_else(|| Fault::new(format!("argument {i} must be a string")))
}

fn arg_i64(args: &[Value], i: usize) -> Result<i64, Fault> {
    arg(args, i)?.as_i64().ok_or_else(|| Fault::new(format!("argument {i} must be an integer")))
}

/// A scalar as PHP's `(string)` cast turns it into a header value.
fn scalar_string(value: &Value) -> Result<String, Fault> {
    Ok(match value {
        Value::String(s) => s.clone(),
        Value::Bool(true) => "1".to_owned(),
        Value::Bool(false) => String::new(),
        Value::Number(n) => match n.as_i64() {
            Some(i) => i.to_string(),
            None => php_std::number::to_string(n.as_f64().unwrap_or(0.0)),
        },
        other => return Err(Fault::new(format!("not a scalar header value: {other}"))),
    })
}

/// A header value: a scalar or a list of them.
fn header_values(value: &Value) -> Result<Vec<String>, Fault> {
    match value {
        Value::Array(list) => list.iter().map(scalar_string).collect(),
        Value::Object(o) => o.values().map(scalar_string).collect(),
        scalar => Ok(vec![scalar_string(scalar)?]),
    }
}

/// `['Name' => value|list, ...]` headers.
fn header_list(value: Option<&Value>) -> Result<Vec<(String, Vec<String>)>, Fault> {
    match value {
        None | Some(Value::Null) => Ok(Vec::new()),
        Some(Value::Array(a)) if a.is_empty() => Ok(Vec::new()),
        Some(Value::Object(o)) => o.iter().map(|(k, v)| Ok((k.clone(), header_values(v)?))).collect(),
        Some(other) => Err(Fault::new(format!("headers must be an object, got {other}"))),
    }
}

fn str_map(value: Option<&Value>) -> Result<IndexMap<String, String>, Fault> {
    match value {
        None | Some(Value::Null) => Ok(IndexMap::new()),
        Some(Value::Array(a)) if a.is_empty() => Ok(IndexMap::new()),
        Some(Value::Object(o)) => o.iter().map(|(k, v)| Ok((k.clone(), scalar_string(v)?))).collect(),
        Some(other) => Err(Fault::new(format!("expected an object, got {other}"))),
    }
}

fn value_map(value: Option<&Value>) -> Result<IndexMap<String, Value>, Fault> {
    match value {
        None | Some(Value::Null) => Ok(IndexMap::new()),
        Some(Value::Array(a)) if a.is_empty() => Ok(IndexMap::new()),
        Some(Value::Object(o)) => Ok(o.iter().map(|(k, v)| (k.clone(), v.clone())).collect()),
        Some(other) => Err(Fault::new(format!("expected an object, got {other}"))),
    }
}

/// A PHP value as the PHP driver's Codec encodes it: lists as arrays, other
/// arrays as objects, `stdClass` as an object, strings that are not UTF-8 as
/// `{"$bytes": ...}`.
fn php_json(value: &Php) -> Value {
    match value {
        Php::Null => Value::Null,
        Php::Bool(b) => Value::Bool(*b),
        Php::Int(i) => json!(i),
        Php::Float(f) => float_value(*f),
        Php::Str(s) => bytes_value(s),
        Php::Array(a) if a.is_list() => Value::Array(a.values().map(php_json).collect()),
        Php::Array(a) | Php::Object(a) => Value::Object(
            a.iter()
                .map(|(k, v)| {
                    let key = match k {
                        KeyRef::Int(i) => i.to_string(),
                        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    (key, php_json(v))
                })
                .collect(),
        ),
        Php::Ext(_) => Value::Null,
    }
}

/// JSON as PHP sees it once decoded (`{"0": 1}` is the list `[1]`).
fn as_php(value: &Value) -> Value {
    php_json(&Php::from_json(value))
}

/// A string-keyed PHP array: `[]` when empty.
fn map_as_php<V: Into<Value> + Clone>(map: &IndexMap<String, V>) -> Value {
    if map.is_empty() {
        return json!([]);
    }
    as_php(&Value::Object(map.iter().map(|(k, v)| (k.clone(), v.clone().into())).collect()))
}

/// Headers as PHP's `getHeaders()` array.
fn headers_json<'a>(pairs: impl Iterator<Item = (&'a [u8], Vec<Php>)>) -> Value {
    let mut array = Array::new();
    for (name, values) in pairs {
        array.set(ArrayKey::normalize(Str::copy_from(name)), Php::Array(Array::from_list(values)));
    }
    php_json(&Php::Array(array))
}

fn message_headers(message: &Msg) -> Value {
    read!(message, m => headers_json(m.headers().iter().map(|(n, v)| {
        (n.as_bytes(), v.iter().map(|s| Php::Str(Str::from(s.as_str()))).collect())
    })))
}

fn dump_uri(uri: &Uri) -> Value {
    json!({
        "scheme": uri.scheme(),
        "authority": uri.authority(),
        "userInfo": uri.user_info(),
        "host": uri.host(),
        "port": uri.port(),
        "path": uri.path(),
        "query": uri.query(),
        "fragment": uri.fragment(),
        "string": uri.to_string(),
    })
}

fn uri_handle(session: &Session, value: &Value) -> Result<Uri, Fault> {
    session.get::<Uri>(value).cloned()
}

fn target<'a>(session: &Session, value: &'a Value) -> Result<Target<'a>, Fault> {
    match value {
        Value::String(s) => Ok(Target::Text(s)),
        other => Ok(Target::Uri(uri_handle(session, other)?)),
    }
}

fn stream_handle(session: &Session, value: &Value) -> Result<StreamRef, Fault> {
    session.get::<StreamRef>(value).cloned()
}

fn message_handle(session: &Session, value: &Value) -> Result<Msg, Fault> {
    session.get::<Msg>(value).cloned()
}

fn metadata_json(meta: Vec<(&'static str, Meta)>) -> Value {
    let map: Map<String, Value> = meta
        .into_iter()
        .map(|(k, v)| {
            let v = match v {
                Meta::Bool(b) => json!(b),
                Meta::Int(i) => json!(i),
                Meta::Str(s) => json!(s),
            };
            (k.to_owned(), v)
        })
        .collect();
    if map.is_empty() { json!([]) } else { Value::Object(map) }
}

fn stream_call(stream: &StreamRef, method: &str, args: &[Value]) -> OpResult {
    let mut s = stream.lock();
    let r: Result<Value, Error> = match method {
        "toString" => Ok(bytes_value(&s.to_bytes())),
        "read" => {
            let length = arg_i64(args, 0)?;
            if length < 1 {
                Err(Error::Runtime("Read length must be greater than zero.".into()))
            } else {
                s.read(length as usize).map(|b| bytes_value(&b))
            }
        }
        "write" => s.write(&arg_bytes(args, 0)?).map(|n| json!(n)),
        "seek" => {
            let offset = arg_i64(args, 0)?;
            let whence = args.get(1).and_then(Value::as_i64).unwrap_or(0);
            let position = match whence {
                0 if offset >= 0 => SeekFrom::Start(offset as u64),
                0 => {
                    // A negative absolute offset fails like PHP's fseek().
                    return Ok(err(&Error::Runtime("Unable to seek stream.".into())));
                }
                1 => SeekFrom::Current(offset),
                2 => SeekFrom::End(offset),
                other => return Err(Fault::new(format!("unknown whence {other}"))),
            };
            s.seek(position).map(|()| Value::Null)
        }
        "rewind" => s.rewind().map(|()| Value::Null),
        "tell" => s.tell().map(|t| json!(t)),
        "eof" => Ok(json!(s.eof())),
        "getSize" => s.size().map(|n| json!(n)),
        "getContents" => s.contents().map(|b| bytes_value(&b)),
        "close" => {
            s.close();
            Ok(Value::Null)
        }
        "detach" => s.detach().map(|d| match d {
            None => Value::Null,
            Some(Detached::Memory { data, position }) => {
                json!({"contents": bytes_value(data.get(position..).unwrap_or_default())})
            }
            Some(Detached::File { mut file, .. }) => {
                use std::io::Read;
                let mut rest = Vec::new();
                let _ = file.read_to_end(&mut rest);
                json!({"contents": bytes_value(&rest)})
            }
        }),
        "isReadable" => Ok(json!(s.is_readable())),
        "isWritable" => Ok(json!(s.is_writable())),
        "isSeekable" => Ok(json!(s.is_seekable())),
        "getMetadata" => s.metadata().map(|meta| match args.first().and_then(Value::as_str) {
            None => metadata_json(meta),
            Some(key) => metadata_json(meta).get(key).cloned().unwrap_or(Value::Null),
        }),
        other => return Err(Fault::new(format!("unknown stream method `{other}`"))),
    };
    Ok(match r {
        Ok(v) => Outcome::ok(v),
        Err(e) => err(&e),
    })
}

/// An uploaded files tree: handles become their files.
fn files_tree(session: &Session, value: &Value) -> Result<Vec<(ArrayKey, UploadedFiles)>, Fault> {
    let entries: Vec<(ArrayKey, &Value)> = match value {
        Value::Object(o) => o.iter().map(|(k, v)| (ArrayKey::from(k.as_str()), v)).collect(),
        Value::Array(a) => a.iter().enumerate().map(|(i, v)| (ArrayKey::Int(i as i64), v)).collect(),
        Value::Null => Vec::new(),
        other => return Err(Fault::new(format!("not an uploaded files tree: {other}"))),
    };
    entries
        .into_iter()
        .map(|(k, v)| {
            let node = if v.get("$handle").is_some() {
                UploadedFiles::File(session.get::<UploadedFile>(v)?.clone())
            } else {
                UploadedFiles::Tree(files_tree(session, v)?)
            };
            Ok((k, node))
        })
        .collect()
}

fn files_php(tree: &[(ArrayKey, UploadedFiles)]) -> Php {
    let mut array = Array::new();
    for (key, node) in tree {
        let value = match node {
            UploadedFiles::File(file) => {
                let mut leaf = Array::new();
                leaf.set(ArrayKey::from("size"), Php::Int(file.size()));
                leaf.set(ArrayKey::from("error"), Php::Int(file.error()));
                leaf.set(
                    ArrayKey::from("clientFilename"),
                    file.client_filename().map_or(Php::Null, |s| Php::Str(Str::from(s))),
                );
                leaf.set(
                    ArrayKey::from("clientMediaType"),
                    file.client_media_type().map_or(Php::Null, |s| Php::Str(Str::from(s))),
                );
                Php::Array(leaf)
            }
            UploadedFiles::Tree(children) => files_php(children),
        };
        array.set(key.clone(), value);
    }
    Php::Array(array)
}

fn dump_message(message: &Msg) -> Value {
    let mut dump = Map::new();
    dump.insert("protocol".into(), json!(read!(message, m => m.protocol_version())));
    dump.insert("headers".into(), message_headers(message));
    match message {
        Msg::Request(r) => {
            dump.insert("method".into(), json!(r.method()));
            dump.insert("target".into(), json!(r.request_target()));
            dump.insert("uri".into(), json!(r.uri().to_string()));
        }
        Msg::Response(r) => {
            dump.insert("status".into(), json!(r.status()));
            dump.insert("reason".into(), json!(r.reason()));
        }
        Msg::Server(r) => {
            dump.insert("method".into(), json!(r.method()));
            dump.insert("target".into(), json!(r.request_target()));
            dump.insert("uri".into(), json!(r.uri().to_string()));
            dump.insert("serverParams".into(), map_as_php(r.server_params()));
            dump.insert("cookieParams".into(), map_as_php(r.cookie_params()));
            dump.insert("queryParams".into(), map_as_php(r.query_params()));
            dump.insert("uploadedFiles".into(), php_json(&files_php(r.uploaded_files())));
            dump.insert("parsedBody".into(), r.parsed_body().map_or(Value::Null, as_php));
            dump.insert("attributes".into(), map_as_php(r.attributes()));
        }
    }
    Value::Object(dump)
}

fn message_with(session: &Session, message: Msg, name: &str, args: &[Value]) -> Result<Result<Msg, Error>, Fault> {
    Ok(match name {
        "withProtocolVersion" => {
            let v = arg_str(args, 0)?;
            each!(message, m => Ok::<_, Error>(m.with_protocol_version(&v)))
        }
        "withHeader" => {
            let (n, v) = (arg_str(args, 0)?, header_values(arg(args, 1)?)?);
            each!(message, m => m.with_header(&n, v))
        }
        "withAddedHeader" => {
            let (n, v) = (arg_str(args, 0)?, header_values(arg(args, 1)?)?);
            each!(message, m => m.with_added_header(&n, v))
        }
        "withoutHeader" => {
            let n = arg_str(args, 0)?;
            each!(message, m => Ok::<_, Error>(m.without_header(&n)))
        }
        "withBody" => {
            let body = stream_handle(session, arg(args, 0)?)?;
            each!(message, m => Ok::<_, Error>(m.with_body(body)))
        }
        "withMethod" => {
            let v = arg_str(args, 0)?;
            match message {
                Msg::Request(r) => Ok(Msg::Request(r.with_method(&v))),
                Msg::Server(r) => Ok(Msg::Server(r.with_method(&v))),
                Msg::Response(_) => return Err(Fault::new("not a request")),
            }
        }
        "withRequestTarget" => {
            let v = arg_str(args, 0)?;
            match message {
                Msg::Request(r) => Ok(Msg::Request(r.with_request_target(&v))),
                Msg::Server(r) => Ok(Msg::Server(r.with_request_target(&v))),
                Msg::Response(_) => return Err(Fault::new("not a request")),
            }
        }
        "withUri" => {
            let uri = uri_handle(session, arg(args, 0)?)?;
            let preserve = args.get(1).and_then(Value::as_bool).unwrap_or(false);
            match message {
                Msg::Request(r) => Ok(Msg::Request(r.with_uri(uri, preserve))),
                Msg::Server(r) => Ok(Msg::Server(r.with_uri(uri, preserve))),
                Msg::Response(_) => return Err(Fault::new("not a request")),
            }
        }
        "withStatus" => {
            let code = u16::try_from(arg_i64(args, 0)?).map_err(|_| Fault::new("status out of range"))?;
            let reason = args.get(1).and_then(Value::as_str).unwrap_or_default().to_owned();
            match message {
                Msg::Response(r) => r.with_status(code, &reason).map(Msg::Response),
                _ => return Err(Fault::new("not a response")),
            }
        }
        _ => {
            let Msg::Server(r) = message else {
                return Err(Fault::new(format!("`{name}` needs a server request")));
            };
            match name {
                "withCookieParams" => Ok(r.with_cookie_params(str_map(args.first())?)),
                "withQueryParams" => Ok(r.with_query_params(value_map(args.first())?)),
                "withUploadedFiles" => {
                    let tree = arg(args, 0)?.get("$files").ok_or_else(|| Fault::new("expected {\"$files\": ...}"))?;
                    Ok(r.with_uploaded_files(files_tree(session, tree)?))
                }
                "withParsedBody" => r.with_parsed_body(arg(args, 0)?.clone()),
                "withAttribute" => Ok(r.with_attribute(&arg_str(args, 0)?, arg(args, 1)?.clone())),
                "withoutAttribute" => Ok(r.without_attribute(&arg_str(args, 0)?)),
                other => return Err(Fault::new(format!("unknown message method `{other}`"))),
            }
            .map(Msg::Server)
        }
    })
}

fn message_get(message: &Msg, name: &str, args: &[Value]) -> Result<Value, Fault> {
    Ok(match name {
        "getProtocolVersion" => json!(read!(message, m => m.protocol_version())),
        "getHeaders" => message_headers(message),
        "hasHeader" => json!(read!(message, m => m.has_header(&arg_str(args, 0)?))),
        "getHeader" => json!(read!(message, m => m.header(&arg_str(args, 0)?).to_vec())),
        "getHeaderLine" => json!(read!(message, m => m.header_line(&arg_str(args, 0)?))),
        "getMethod" | "getRequestTarget" => match (message, name) {
            (Msg::Request(r), "getMethod") => json!(r.method()),
            (Msg::Server(r), "getMethod") => json!(r.method()),
            (Msg::Request(r), _) => json!(r.request_target()),
            (Msg::Server(r), _) => json!(r.request_target()),
            _ => return Err(Fault::new("not a request")),
        },
        "getStatusCode" | "getReasonPhrase" => match message {
            Msg::Response(r) if name == "getStatusCode" => json!(r.status()),
            Msg::Response(r) => json!(r.reason()),
            _ => return Err(Fault::new("not a response")),
        },
        _ => {
            let Msg::Server(r) = message else {
                return Err(Fault::new(format!("`{name}` needs a server request")));
            };
            match name {
                "getServerParams" => map_as_php(r.server_params()),
                "getCookieParams" => map_as_php(r.cookie_params()),
                "getQueryParams" => map_as_php(r.query_params()),
                "getUploadedFiles" => php_json(&files_php(r.uploaded_files())),
                "getParsedBody" => r.parsed_body().map_or(Value::Null, as_php),
                "getAttributes" => map_as_php(r.attributes()),
                "getAttribute" => match r.attribute(&arg_str(args, 0)?) {
                    Some(v) => as_php(v),
                    None => args.get(1).cloned().unwrap_or(Value::Null),
                },
                other => return Err(Fault::new(format!("unknown message getter `{other}`"))),
            }
        }
    })
}

fn dump_xml(element: &Element) -> Value {
    json!({
        "name": element.name,
        "attributes": element.attributes.iter().map(|(n, v)| json!([n, v])).collect::<Vec<_>>(),
        "text": element.text,
        "children": element.children.iter().map(dump_xml).collect::<Vec<_>>(),
    })
}

fn opt_bytes(value: Option<Vec<u8>>) -> Value {
    value.map_or(Value::Null, |b| bytes_value(&b))
}

fn dump_received(part: &Received, lookup: &[Value]) -> Result<Value, Fault> {
    let headers = headers_json(
        part.headers().iter().map(|(n, v)| (n.as_slice(), v.iter().map(|s| Php::Str(Str::copy_from(s))).collect())),
    );
    let lookup = lookup
        .iter()
        .map(|name| {
            let name = bytes(name).ok_or_else(|| Fault::new("lookup names must be strings"))?;
            Ok(json!([
                part.header(&name).iter().map(|v| bytes_value(v)).collect::<Vec<_>>(),
                bytes_value(&part.header_line(&name)),
            ]))
        })
        .collect::<Result<Vec<_>, Fault>>()?;
    Ok(json!({
        "headers": headers,
        "body": bytes_value(part.body()),
        "name": opt_bytes(part.name()),
        "filename": opt_bytes(part.filename()),
        "contentType": opt_bytes(part.content_type()),
        "lookup": lookup,
    }))
}

fn response_call(message: &Msg, name: &str, args: &[Value], lookup: &[Value]) -> OpResult {
    let Msg::Response(response) = message else {
        return Err(Fault::new("not a response"));
    };
    let r: Result<Value, Error> = match name {
        "contentType" => Ok(json!(response.content_type())),
        "text" => Ok(bytes_value(&response.text())),
        "form" => Ok(php_json(&Php::Array(response.form()))),
        "json" => {
            let associative = args.first().and_then(Value::as_bool).unwrap_or(true);
            let depth = args.get(1).and_then(Value::as_i64).unwrap_or(512);
            let flags = php_std::json::Flags::from_php(args.get(2).and_then(Value::as_i64).unwrap_or(0));
            response.json(associative, depth, flags).map(|v| php_json(&v))
        }
        "xml" => response.xml().map(|e| dump_xml(&e)),
        "multipart" => match response.multipart() {
            Ok(parts) => Ok(Value::Array(parts.iter().map(|p| dump_received(p, lookup)).collect::<Result<_, _>>()?)),
            Err(e) => Err(e),
        },
        other => return Err(Fault::new(format!("unknown response method `{other}`"))),
    };
    Ok(match r {
        Ok(v) => Outcome::ok(v),
        Err(e) => err(&e),
    })
}

fn dump_part(part: &Part) -> Value {
    let headers: IndexMap<String, Value> = part.headers().iter().map(|(k, v)| (k.clone(), json!(v))).collect();
    json!({
        "name": part.name(),
        "content": bytes_value(part.content()),
        "path": part.path().map(|p| p.to_string_lossy().into_owned()),
        "size": part.size(),
        "filename": part.filename(),
        "contentType": part.content_type(),
        "headers": map_as_php(&headers),
    })
}

fn part_headers(value: Option<&Value>) -> Result<Vec<(String, String)>, Fault> {
    Ok(str_map(value)?.into_iter().collect())
}

/// `multipart()`'s parts: a Part handle, or a scalar field named by its key.
fn parts_arg(session: &Session, value: &Value) -> Result<Vec<Part>, Fault> {
    let items = value.get("$parts").ok_or_else(|| Fault::new("expected {\"$parts\": ...}"))?;
    let entries: Vec<(String, &Value)> = match items {
        Value::Object(o) => o.iter().map(|(k, v)| (k.clone(), v)).collect(),
        Value::Array(a) => a.iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
        other => return Err(Fault::new(format!("parts must be a list or object, got {other}"))),
    };
    entries
        .into_iter()
        .map(|(key, v)| {
            if v.get("$handle").is_some() {
                Ok(session.get::<Part>(v)?.clone())
            } else {
                Ok(Part::field(&key, scalar_string(v)?.as_bytes(), Vec::new()))
            }
        })
        .collect()
}

fn request_factory(session: &Session, name: &str, args: &[Value]) -> Result<Result<Request, Error>, Fault> {
    let factory = request::Factory::default();
    let method = arg_str(args, 0)?;
    let uri = target(session, arg(args, 1)?)?;
    let headers_at = |i: usize| -> Result<Vec<(String, Vec<String>)>, Fault> { header_list(args.get(i)) };
    let pairs = |list: &[(String, Vec<String>)]| -> Vec<(String, Vec<String>)> { list.to_vec() };
    fn borrow(list: &[(String, Vec<String>)]) -> Vec<(&str, Vec<String>)> {
        list.iter().map(|(n, v)| (n.as_str(), v.clone())).collect()
    }
    Ok(match name {
        "createRequest" => factory.create_request(&method, uri),
        "json" => {
            let h = pairs(&headers_at(3)?);
            factory.json(&method, uri, arg(args, 2)?, &borrow(&h))
        }
        "form" => {
            let h = pairs(&headers_at(3)?);
            factory.form(&method, uri, arg(args, 2)?, &borrow(&h))
        }
        "text" => {
            let h = pairs(&headers_at(3)?);
            factory.text(&method, uri, &arg_bytes(args, 2)?, &borrow(&h))
        }
        "xml" => {
            let h = pairs(&headers_at(3)?);
            factory.xml(&method, uri, &arg_bytes(args, 2)?, &borrow(&h))
        }
        "body" => {
            let h = pairs(&headers_at(4)?);
            factory.body(&method, uri, &arg_bytes(args, 2)?, &arg_str(args, 3)?, &borrow(&h))
        }
        "query" => {
            let h = pairs(&headers_at(3)?);
            factory.query(&method, uri, arg(args, 2)?, &borrow(&h))
        }
        "multipart" => {
            let parts = parts_arg(session, arg(args, 2)?)?;
            let h = pairs(&headers_at(3)?);
            factory.multipart(&method, uri, parts, &borrow(&h))
        }
        other => return Err(Fault::new(format!("unknown factory method `{other}`"))),
    })
}

fn uri_with(uri: &Uri, name: &str, args: &[Value]) -> Result<Result<Uri, Error>, Fault> {
    Ok(match name {
        "withScheme" => Ok(uri.with_scheme(&arg_str(args, 0)?)),
        "withUserInfo" => {
            let user = arg_str(args, 0)?;
            let password = args.get(1).and_then(Value::as_str).map(str::to_owned);
            Ok(uri.with_user_info(&user, password.as_deref()))
        }
        "withHost" => Ok(uri.with_host(&arg_str(args, 0)?)),
        "withPort" => match arg(args, 0)? {
            Value::Null => uri.with_port(None),
            v => {
                let port = v.as_i64().ok_or_else(|| Fault::new("port must be an integer"))?;
                match u16::try_from(port) {
                    Ok(p) => uri.with_port(Some(p)),
                    Err(_) => return Err(Fault::new("port outside u16 (a recorded deviation)")),
                }
            }
        },
        "withPath" => Ok(uri.with_path(&arg_str(args, 0)?)),
        "withQuery" => Ok(uri.with_query(&arg_str(args, 0)?)),
        "withFragment" => Ok(uri.with_fragment(&arg_str(args, 0)?)),
        other => return Err(Fault::new(format!("unknown URI method `{other}`"))),
    })
}

/// Replaces the random boundary of a multipart request with `{boundary}`.
fn render(session: &Session, value: &Value) -> Result<Value, Fault> {
    let Msg::Request(request) = message_handle(session, value)? else {
        return Err(Fault::new("not a request"));
    };
    let boundary = request
        .body()
        .downcast::<Body, _>(|b| b.boundary().to_owned())
        .ok_or_else(|| Fault::new("not a multipart body"))?;
    let body = request.body().to_bytes();
    let (body, _) = php_std::string::str_replace(boundary.as_bytes(), b"{boundary}", &body);
    Ok(json!({
        "contentType": request.header_line(header::CONTENT_TYPE).replace(&boundary, "{boundary}"),
        "body": bytes_value(&body),
        "prefix": boundary.get(..7),
        "length": boundary.len(),
        "hex": boundary.get(7..).is_some_and(|h| h.bytes().all(|b| b.is_ascii_hexdigit() && !b.is_ascii_uppercase())),
    }))
}

fn constants() -> Value {
    json!({
        "method": {
            "CONNECT": method::CONNECT, "DELETE": method::DELETE, "GET": method::GET, "HEAD": method::HEAD,
            "OPTIONS": method::OPTIONS, "PATCH": method::PATCH, "POST": method::POST, "PUT": method::PUT,
            "TRACE": method::TRACE,
        },
        "header": {
            "ACCEPT": header::ACCEPT, "ACCEPT_ENCODING": header::ACCEPT_ENCODING, "AUTHORIZATION": header::AUTHORIZATION,
            "CACHE_CONTROL": header::CACHE_CONTROL, "CONTENT_DISPOSITION": header::CONTENT_DISPOSITION,
            "CONTENT_ENCODING": header::CONTENT_ENCODING, "CONTENT_LENGTH": header::CONTENT_LENGTH,
            "CONTENT_TYPE": header::CONTENT_TYPE, "COOKIE": header::COOKIE, "DATE": header::DATE, "ETAG": header::ETAG,
            "HOST": header::HOST, "IF_MATCH": header::IF_MATCH, "IF_NONE_MATCH": header::IF_NONE_MATCH,
            "LOCATION": header::LOCATION, "REFERER": header::REFERER, "RETRY_AFTER": header::RETRY_AFTER,
            "SET_COOKIE": header::SET_COOKIE, "TRACEPARENT": header::TRACEPARENT, "USER_AGENT": header::USER_AGENT,
        },
        "contentType": {
            "FORM_URLENCODED": content_type::FORM_URLENCODED, "HTML": content_type::HTML, "JSON": content_type::JSON,
            "MERGE_PATCH_JSON": content_type::MERGE_PATCH_JSON, "MULTIPART_FORM_DATA": content_type::MULTIPART_FORM_DATA,
            "OCTET_STREAM": content_type::OCTET_STREAM, "PLAIN_TEXT": content_type::PLAIN_TEXT,
            "PROBLEM_JSON": content_type::PROBLEM_JSON, "XML": content_type::XML,
        },
    })
}

fn handle_or_err<T: std::any::Any + Send>(session: &mut Session, r: Result<T, Error>) -> Outcome {
    match r {
        Ok(v) => Outcome::ok(session.handle(v)),
        Err(e) => err(&e),
    }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let list = |key: &str| -> Vec<Value> { args.get(key).and_then(Value::as_array).cloned().unwrap_or_default() };
    Ok(match op {
        "constants" => Outcome::ok(constants()),
        // Test setup, as the PHP tests call file_put_contents(): no library call.
        "fixture.write" => {
            let path = std::path::Path::new(a.str("path")?);
            if let Some(dir) = path.parent() {
                std::fs::create_dir_all(dir).map_err(|e| Fault::new(e.to_string()))?;
            }
            let content = a.opt("content").map(|_| a.bytes("content")).transpose()?.unwrap_or_default();
            std::fs::write(path, content).map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(Value::Null)
        }

        // One-shot forms for fuzzing.
        "uri.inspect" => match Uri::parse(a.str("uri")?) {
            Ok(uri) => Outcome::ok(dump_uri(&uri)),
            Err(e) => err(&e),
        },
        "uri.change" => match Uri::parse(a.str("uri")?) {
            Ok(uri) => match uri_with(&uri, a.str("method")?, &list("args"))? {
                Ok(uri) => Outcome::ok(dump_uri(&uri)),
                Err(e) => err(&e),
            },
            Err(e) => err(&e),
        },
        "header.set" => {
            let values = header_values(a.value("value")?)?;
            let name = a.str("name")?;
            match Response::new(200, "").and_then(|r| r.with_header(name, values)) {
                Ok(r) => Outcome::ok(message_headers(&Msg::Response(r))),
                Err(e) => err(&e),
            }
        }
        "response.read" => {
            let body = a.opt("body").map(|_| a.bytes("body")).transpose()?.unwrap_or_default();
            let mut response = Response::new(200, "").map_err(|e| Fault::new(e.to_string()))?;
            response = response.with_body(StreamRef::from_bytes(&body));
            if let Some(content_type) = a.opt_str("contentType")? {
                response = match response.with_header(header::CONTENT_TYPE, content_type) {
                    Ok(r) => r,
                    Err(e) => return Ok(err(&e)),
                };
            }
            response_call(&Msg::Response(response), a.str("method")?, &list("args"), &list("lookup"))?
        }
        "uri.parse" => handle_or_err(session, Uri::parse(a.str("uri")?)),
        "uri.factory" => handle_or_err(session, uri::Factory.create_uri(a.opt_str("uri")?.unwrap_or_default())),
        "uri.new" => {
            let port = match a.opt_i64("port")? {
                None => None,
                Some(p) => Some(u16::try_from(p).map_err(|_| Fault::new("port outside u16 (a recorded deviation)"))?),
            };
            let s = |k: &str| -> Result<String, Fault> { Ok(a.opt_str(k)?.unwrap_or_default().to_owned()) };
            let uri = Uri::from_parts(uri::Parts {
                scheme: s("scheme")?,
                user_info: s("userInfo")?,
                host: s("host")?,
                port,
                path: s("path")?,
                query: s("query")?,
                fragment: s("fragment")?,
            });
            Outcome::ok(session.handle(uri))
        }
        "uri.with" => {
            let uri = uri_handle(session, a.value("uri")?)?;
            let r = uri_with(&uri, a.str("method")?, &list("args"))?;
            handle_or_err(session, r)
        }
        "uri.dump" => Outcome::ok(dump_uri(&uri_handle(session, a.value("uri")?)?)),

        "stream.new" => {
            let content = a.opt("content").map(|_| a.bytes("content")).transpose()?.unwrap_or_default();
            Outcome::ok(session.handle(StreamRef::new(TempStream::new(&content))))
        }
        "stream.factory" => {
            let content = a.opt("content").map(|_| a.bytes("content")).transpose()?.unwrap_or_default();
            Outcome::ok(session.handle(stream::Factory.create_stream(&content)))
        }
        "stream.from_file" => {
            let path = a.str("path")?;
            let mode = a.opt_str("mode")?.unwrap_or("r");
            handle_or_err(session, stream::Factory.create_stream_from_file(std::path::Path::new(path), mode))
        }
        "stream.from_resource" => {
            let content = a.opt("content").map(|_| a.bytes("content")).transpose()?.unwrap_or_default();
            let position = content.len();
            let detached = Detached::Memory { data: content, position };
            Outcome::ok(session.handle(stream::Factory.create_stream_from_resource(detached)))
        }
        "stream.from_detached" => {
            let stream = stream_handle(session, a.value("stream")?)?;
            let detached = stream.lock().detach();
            match detached {
                Ok(Some(d)) => Outcome::ok(session.handle(StreamRef::new(TempStream::from_detached(d)))),
                Ok(None) => return Err(Fault::new("nothing detached")),
                Err(e) => err(&e),
            }
        }
        "stream.call" => stream_call(&stream_handle(session, a.value("stream")?)?, a.str("method")?, &list("args"))?,

        "request.new" => {
            let uri = uri_handle(session, a.value("uri")?)?;
            let mut request = Request::new(a.str("method")?, uri);
            if let Some(body) = a.opt("body") {
                request = request.with_body(stream_handle(session, body)?);
            }
            Outcome::ok(session.handle(Msg::Request(request)))
        }
        "request.factory" => {
            let r = request_factory(session, a.str("method")?, &list("args"))?;
            handle_or_err(session, r.map(Msg::Request))
        }
        "response.new" => {
            let status =
                u16::try_from(a.opt_i64("status")?.unwrap_or(200)).map_err(|_| Fault::new("status outside u16"))?;
            let r = Response::new(status, a.opt_str("reason")?.unwrap_or_default());
            let r = match (r, a.opt("body")) {
                (Ok(response), Some(body)) => Ok(response.with_body(stream_handle(session, body)?)),
                (r, _) => r,
            };
            handle_or_err(session, r.map(Msg::Response))
        }
        "response.factory" => {
            let args = list("args");
            let status = u16::try_from(args.first().and_then(Value::as_i64).unwrap_or(200))
                .map_err(|_| Fault::new("status outside u16"))?;
            let reason = args.get(1).and_then(Value::as_str).unwrap_or_default().to_owned();
            handle_or_err(session, utopia_psr7::response::Factory.create_response(status, &reason).map(Msg::Response))
        }
        "server_request.new" => {
            let uri = uri_handle(session, a.value("uri")?)?;
            let body = a.opt("body").map(|b| stream_handle(session, b)).transpose()?;
            let parts = server_request::Parts {
                method: a.str("method")?.to_owned(),
                uri,
                server_params: value_map(a.opt("serverParams"))?,
                cookie_params: str_map(a.opt("cookieParams"))?,
                query_params: value_map(a.opt("queryParams"))?,
                uploaded_files: a.opt("uploadedFiles").map(|t| files_tree(session, t)).transpose()?.unwrap_or_default(),
                parsed_body: a.opt("parsedBody").cloned(),
                attributes: value_map(a.opt("attributes"))?,
                request_target: a.opt_str("requestTarget")?.map(str::to_owned),
                body,
                headers: header_list(a.opt("headers"))?,
            };
            Outcome::ok(session.handle(Msg::Server(ServerRequest::from_parts(parts))))
        }
        "server_request.factory" => {
            let uri = target(session, a.value("uri")?)?;
            let r = server_request::Factory::default().create_server_request(
                a.str("method")?,
                uri,
                value_map(a.opt("serverParams"))?,
            );
            handle_or_err(session, r.map(Msg::Server))
        }
        "message.with" => {
            let message = message_handle(session, a.value("message")?)?;
            let r = message_with(session, message, a.str("method")?, &list("args"))?;
            handle_or_err(session, r)
        }
        "message.get" => {
            Outcome::ok(message_get(&message_handle(session, a.value("message")?)?, a.str("method")?, &list("args"))?)
        }
        "message.body" => {
            let message = message_handle(session, a.value("message")?)?;
            let body = read!(&message, m => m.body().clone());
            Outcome::ok(session.handle(body))
        }
        "message.uri" => {
            let uri = match message_handle(session, a.value("message")?)? {
                Msg::Request(r) => r.uri().clone(),
                Msg::Server(r) => r.uri().clone(),
                Msg::Response(_) => return Err(Fault::new("not a request")),
            };
            Outcome::ok(session.handle(uri))
        }
        "message.dump" => Outcome::ok(dump_message(&message_handle(session, a.value("message")?)?)),
        "response.call" => response_call(
            &message_handle(session, a.value("message")?)?,
            a.str("method")?,
            &list("args"),
            &list("lookup"),
        )?,

        "part.field" => {
            let part = Part::field(a.str("name")?, &a.bytes("value")?, part_headers(a.opt("headers"))?);
            Outcome::ok(session.handle(part))
        }
        "part.file" => {
            let r = Part::file(
                a.str("name")?,
                a.str("path")?,
                a.opt_str("filename")?,
                a.opt_str("contentType")?,
                part_headers(a.opt("headers"))?,
            );
            handle_or_err(session, r)
        }
        "part.body" => {
            let part = Part::body(
                a.str("name")?,
                &a.bytes("body")?,
                a.opt_str("filename")?,
                a.opt_str("contentType")?,
                part_headers(a.opt("headers"))?,
            );
            Outcome::ok(session.handle(part))
        }
        "part.dump" => Outcome::ok(dump_part(session.get::<Part>(a.value("part")?)?)),
        "multipart.body" => {
            let parts = list("parts").iter().map(|p| session.get::<Part>(p).cloned()).collect::<Result<Vec<_>, _>>()?;
            handle_or_err(session, Body::new(a.str("boundary")?, parts).map(StreamRef::new))
        }
        "multipart.dump" => {
            let body = stream_handle(session, a.value("body")?)?;
            let dump = body
                .downcast::<Body, _>(
                    |b| json!({"boundary": b.boundary(), "parts": b.parts().iter().map(dump_part).collect::<Vec<_>>()}),
                )
                .ok_or_else(|| Fault::new("not a multipart body"))?;
            Outcome::ok(dump)
        }
        "multipart.render" => Outcome::ok(render(session, a.value("message")?)?),
        "received.new" => {
            let headers = match a.opt("headers") {
                Some(Value::Object(o)) => o
                    .iter()
                    .map(|(k, v)| {
                        let values = match v {
                            Value::Array(list) => list
                                .iter()
                                .map(|s| bytes(s).ok_or_else(|| Fault::new("header values must be strings")))
                                .collect::<Result<Vec<_>, _>>()?,
                            other => vec![bytes(other).ok_or_else(|| Fault::new("header values must be strings"))?],
                        };
                        Ok((k.as_bytes().to_vec(), values))
                    })
                    .collect::<Result<Vec<_>, Fault>>()?,
                _ => Vec::new(),
            };
            let body = a.opt("body").map(|_| a.bytes("body")).transpose()?.unwrap_or_default();
            Outcome::ok(dump_received(&Received::new(headers, body), &list("lookup"))?)
        }

        "uploaded.new" => {
            let file = UploadedFile::new(
                a.str("file")?,
                a.i64("size")?,
                a.i64("error")?,
                a.opt_str("clientFilename")?.map(str::to_owned),
                a.opt_str("clientMediaType")?.map(str::to_owned),
            );
            Outcome::ok(session.handle(file))
        }
        "uploaded.call" => {
            let file = session.get::<UploadedFile>(a.value("file")?)?.clone();
            let args = list("args");
            match a.str("method")? {
                "getStream" => handle_or_err(session, file.stream()),
                "moveTo" => match file.move_to(arg_str(&args, 0)?) {
                    Ok(()) => Outcome::ok(Value::Null),
                    Err(e) => err(&e),
                },
                "getSize" => Outcome::ok(json!(file.size())),
                "getError" => Outcome::ok(json!(file.error())),
                "getClientFilename" => Outcome::ok(json!(file.client_filename())),
                "getClientMediaType" => Outcome::ok(json!(file.client_media_type())),
                other => return Err(Fault::new(format!("unknown uploaded file method `{other}`"))),
            }
        }
        "uploaded.normalize" => {
            let files = match Php::from_json(a.opt("files").unwrap_or(&Value::Null)) {
                Php::Array(a) => a,
                _ => Array::new(),
            };
            Outcome::ok(php_json(&files_php(&uploaded_file::normalize_files(&files))))
        }
        _ => return Err(Fault::new(format!("psr7: unknown operation `{op}`"))),
    })
}
