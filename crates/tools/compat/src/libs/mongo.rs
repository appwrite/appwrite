//! Compat adapter for `mongo`: maps `tests/compat/mongo/spec.json`
//! operations onto `utopia-mongo`, as `tests/compat/mongo/Adapter.php` maps
//! them onto utopia-php/mongo. Values cross as `spec.json` describes:
//! `{"$object": {...}}` for a document PHP decodes as a `stdClass` (the
//! server's replies), plain JSON objects for PHP arrays (`toArray()`
//! results), `{"$bson": <canonical extended JSON>}` for the other BSON types.

use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_mongo::bson::{Bson, Document};
use utopia_mongo::{
    Auth, Client, Config, Credentials, Error, Failure, Options, Reply, Session as MongoSession, Upsert,
};

use crate::adapter::{Fault, OpResult, Outcome, Session, float_value};
use crate::bench::Bench;

pub const OPS: &[&str] = &[
    "auth.continue",
    "auth.create_response",
    "auth.encode_credentials",
    "auth.get_auth_message",
    "auth.get_cnonce",
    "auth.get_salted_password",
    "auth.new",
    "auth.server_first",
    "auth.start",
    "auth.verify",
    "client.abort_transaction",
    "client.aggregate",
    "client.cleanup_stale_sessions",
    "client.close",
    "client.commit_transaction",
    "client.connect",
    "client.count",
    "client.create_collection",
    "client.create_database",
    "client.create_indexes",
    "client.create_read_concern",
    "client.create_uuid",
    "client.create_write_concern",
    "client.delete",
    "client.drop_collection",
    "client.drop_database",
    "client.drop_indexes",
    "client.end_sessions",
    "client.find",
    "client.find_and_modify",
    "client.get_cluster_time",
    "client.get_connection_info",
    "client.get_host",
    "client.get_more",
    "client.get_operation_time",
    "client.get_session_state",
    "client.insert",
    "client.insert_many",
    "client.is_replica_set",
    "client.is_transient_transaction_error",
    "client.is_unknown_transaction_commit_result",
    "client.last_document",
    "client.list_collection_names",
    "client.list_database_names",
    "client.new",
    "client.query",
    "client.select_collection",
    "client.select_database",
    "client.start_session",
    "client.start_transaction",
    "client.update",
    "client.upsert",
    "client.with_transaction",
    "exception.from_response",
    "exception.new",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("insert", "Insert one document (a fresh UUIDv7 id) and return it"),
    ("find", "Find one document by id"),
    ("update", "Update one document by id ($inc)"),
    ("count", "Count the documents matching a filter"),
    ("write_concern", "Build a write concern from options (no I/O)"),
    ("scram", "Answer a SCRAM-SHA-256 challenge (4096 PBKDF2 rounds)"),
];

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use utopia_mongo::bson::doc;

    async fn seeded(session: &Session) -> Result<Rc<Client>, Fault> {
        let mut args = Map::new();
        args.insert("database".into(), Json::String(format!("{}_bench", session.ns)));
        let client = connect(session, &Json::Object(args)).await?;
        client
            .insert("bench", doc! { "_id": "one", "name": "Ada", "n": 1 }, Options::new())
            .await
            .map_err(|e| Fault::new(e.to_string()))?;
        Ok(Rc::new(client))
    }

    Ok(match name {
        "insert" => {
            let c = seeded(session).await?;
            Bench::future(move || {
                let c = c.clone();
                async move {
                    black_box(c.insert("bench", doc! { "name": "Grace", "n": 2 }, Options::new()).await.ok());
                }
            })
        }
        "find" => {
            let c = seeded(session).await?;
            Bench::future(move || {
                let c = c.clone();
                async move {
                    black_box(c.find("bench", doc! { "_id": "one" }, Options::new()).await.ok());
                }
            })
        }
        "update" => {
            let c = seeded(session).await?;
            Bench::future(move || {
                let c = c.clone();
                async move {
                    black_box(
                        c.update("bench", doc! { "_id": "one" }, doc! { "$inc": { "n": 1 } }, Options::new(), false)
                            .await
                            .ok(),
                    );
                }
            })
        }
        "count" => {
            let c = seeded(session).await?;
            Bench::future(move || {
                let c = c.clone();
                async move {
                    black_box(c.count("bench", doc! { "name": "Ada" }, Options::new()).await.ok());
                }
            })
        }
        "write_concern" => {
            let c = client(session, &json!({}))?;
            let concern = Bson::Document(doc! { "w": "majority", "j": true, "wtimeout": 5000 });
            Bench::sync(move || {
                black_box(c.create_write_concern(black_box(&concern)).ok());
            })
        }
        "scram" => Bench::sync(|| {
            let Ok(mut auth) =
                Auth::new(Credentials { authcid: "root".into(), secret: "password".into(), ..Default::default() })
            else {
                return;
            };
            let _ = auth.start();
            let challenge = format!("r={}server,s=c2FsdA==,i=4096", auth.cnonce());
            black_box(auth.create_response(Some(challenge.as_bytes())).ok());
        }),
        _ => return Err(Fault::new(format!("mongo: no benchmark `{name}`"))),
    })
}

// ----- Values -----

/// An argument as BSON: `{"$object": {...}}` and plain objects are
/// documents, `{"$bson": ...}` the type its extended JSON describes,
/// integers `int32` when they fit (as PHP's extension encodes them).
pub fn decode(value: &Json) -> Result<Bson, Fault> {
    Ok(match value {
        Json::Null => Bson::Null,
        Json::Bool(b) => Bson::Boolean(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => utopia_mongo::client::int(i),
            None => Bson::Double(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Bson::String(s.clone()),
        Json::Array(items) => Bson::Array(items.iter().map(decode).collect::<Result<_, _>>()?),
        Json::Object(map) => {
            if map.len() == 1 {
                if let Some(inner) = map.get("$object") {
                    return match inner {
                        Json::Object(fields) => Ok(Bson::Document(fields_of(fields)?)),
                        _ => Ok(Bson::Document(Document::new())),
                    };
                }
                if let Some(ext) = map.get("$bson") {
                    return Bson::try_from(ext.clone()).map_err(|e| Fault::new(format!("invalid $bson: {e}")));
                }
                if let Some(Json::String(f)) = map.get("$float") {
                    return Ok(Bson::Double(match f.as_str() {
                        "INF" => f64::INFINITY,
                        "-INF" => f64::NEG_INFINITY,
                        _ => f64::NAN,
                    }));
                }
                if map.contains_key("$bytes") {
                    return Err(Fault::new("a binary string cannot become a BSON string"));
                }
            }
            Bson::Document(fields_of(map)?)
        }
    })
}

fn fields_of(map: &Map<String, Json>) -> Result<Document, Fault> {
    map.iter().map(|(k, v)| Ok((k.clone(), decode(v)?))).collect()
}

/// A document argument: a list is a document keyed `0`, `1`, ... (PHP
/// copies an array's entries into an object), `{}` and `[]` an empty one.
fn document(value: Option<&Json>) -> Result<Document, Fault> {
    match value.map(decode).transpose()? {
        None | Some(Bson::Null) => Ok(Document::new()),
        Some(Bson::Document(d)) => Ok(d),
        Some(Bson::Array(items)) => Ok(items.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect()),
        Some(_) => Err(Fault::new("expected a document")),
    }
}

/// The server's reply shape: documents are `stdClass` in PHP.
pub fn encode(value: &Bson) -> Json {
    match value {
        Bson::Null => Json::Null,
        Bson::Boolean(b) => Json::Bool(*b),
        Bson::Int32(i) => json!(i),
        Bson::Int64(i) => json!(i),
        Bson::Double(f) => float_value(*f),
        Bson::String(s) => Json::String(s.clone()),
        Bson::Array(items) => Json::Array(items.iter().map(encode).collect()),
        Bson::Document(d) => encode_document(d),
        other => json!({ "$bson": other.clone().into_canonical_extjson() }),
    }
}

/// A document as PHP's `stdClass`: `{}` when empty.
pub fn encode_document(d: &Document) -> Json {
    if d.is_empty() {
        return json!({});
    }
    json!({ "$object": Json::Object(d.iter().map(|(k, v)| (k.clone(), encode(v))).collect()) })
}

/// `toArray()`'s shape: a PHP array (`[]` when empty), nested non-empty
/// documents arrays too, nested empty ones `stdClass` (`{}`).
pub fn encode_array(d: &Document) -> Json {
    if d.is_empty() {
        return json!([]);
    }
    Json::Object(d.iter().map(|(k, v)| (k.clone(), array_value(v))).collect())
}

fn array_value(value: &Bson) -> Json {
    match value {
        Bson::Document(d) if d.is_empty() => json!({}),
        Bson::Document(d) => Json::Object(d.iter().map(|(k, v)| (k.clone(), array_value(v))).collect()),
        Bson::Array(items) => Json::Array(items.iter().map(array_value).collect()),
        other => encode(other),
    }
}

fn encode_session(session: &MongoSession) -> Json {
    json!({ "id": encode_document(&session.id), "sessionId": session.session_id })
}

fn decode_session(value: Option<&Json>) -> Result<MongoSession, Fault> {
    let Some(Json::Object(map)) = value else {
        return Err(Fault::new("a session is {\"id\": ..., \"sessionId\": ...}"));
    };
    let id = match map.get("id").map(decode).transpose()? {
        Some(Bson::Document(d)) => d,
        _ => Document::new(),
    };
    let session_id = match map.get("sessionId") {
        Some(Json::String(s)) => s.clone(),
        _ => match id.get("id") {
            Some(Bson::Binary(b)) => b.bytes.iter().map(|b| format!("{b:02x}")).collect(),
            _ => String::new(),
        },
    };
    Ok(MongoSession { id, session_id })
}

fn encode_reply(reply: Reply) -> Json {
    match reply {
        Reply::Count(n) => encode(&n),
        Reply::Document(d) => encode_document(&d),
    }
}

// ----- Errors -----

fn err(e: &Error) -> Outcome {
    Outcome::err_bytes(e.php_class(), e.message_bytes())
}

fn inspect(e: &Error) -> Json {
    let empty = Failure::default();
    let f = e.failure().unwrap_or(&empty);
    // The arrays exception.new and exception.from_response are given stay
    // PHP arrays (their items too).
    let list = |items: &Option<Vec<Bson>>| match items {
        None => Json::Null,
        Some(items) => Json::Array(items.iter().map(array_value).collect()),
    };
    let mut out = json!({
        "class": e.php_class(),
        "message": e.message(),
        "code": e.code(),
        "transient": e.is_transient_error(),
        "network": e.is_network_error(),
        "retryable_write": e.is_retryable_write(),
        "duplicate_key": e.is_duplicate_key_error(),
        "write_concern": e.is_write_concern_error(),
        "unsent": e.is_unsent_error(),
        "timeout": e.is_timeout_error(),
        "error_labels": Json::Array(f.error_labels.iter().map(array_value).collect()),
        "write_errors": list(&f.write_errors),
        "write_concern_errors": list(&f.write_concern_errors),
        "operation_type": f.operation_type,
        "category": e.error_category(),
    });
    if let Some(result) = e.bulk_write_result() {
        out["result"] = encode_array(result);
    }
    out
}

/// An exception from arguments (`class`, `message`, `code`, ...).
fn exception(a: &Json) -> Result<Error, Fault> {
    let list = |key: &str| -> Result<Option<Vec<Bson>>, Fault> {
        match a.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(v) => match decode(v)? {
                Bson::Array(items) => Ok(Some(items)),
                Bson::Document(d) => Ok(Some(d.into_iter().map(|(_, v)| v).collect())),
                _ => Err(Fault::new(format!("`{key}` must be an array"))),
            },
        }
    };
    let failure = Failure {
        message: a.get("message").and_then(Json::as_str).unwrap_or("").to_owned(),
        code: a.get("code").and_then(Json::as_i64).unwrap_or(0),
        error_labels: list("error_labels")?.unwrap_or_default(),
        write_errors: list("write_errors")?,
        write_concern_errors: list("write_concern_errors")?,
        operation_type: a.get("operation_type").and_then(Json::as_str).map(str::to_owned),
    };
    Ok(match a.get("class").and_then(Json::as_str).unwrap_or("Exception") {
        "Exception" => Error::Exception(Box::new(failure)),
        "UnsentException" => Error::Unsent(Box::new(failure)),
        "ConnectionException" => Error::Connection(Box::new(failure)),
        "AuthenticationException" => Error::Authentication(Box::new(failure)),
        "TransactionException" => Error::Transaction(Box::new(failure)),
        "BulkWriteException" => Error::BulkWrite(
            Box::new(Failure { message: failure.message, code: failure.code, ..Failure::default() }),
            Box::new(document(a.get("result"))?),
        ),
        other => return Err(Fault::new(format!("unknown exception class `{other}`"))),
    })
}

// ----- Clients -----

type Shared = Arc<Client>;

/// The `mongo` service's URL: user, password, host and port.
fn url(session: &Session, name: &str) -> Result<(String, String, String, u16), Fault> {
    let raw = session.service(name)?;
    let rest = raw.strip_prefix("mongodb://").ok_or_else(|| Fault::new(format!("invalid `{name}` URL")))?;
    let rest = rest.split(['/', '?']).next().unwrap_or("");
    let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
    let (user, pass) = auth.split_once(':').unwrap_or((auth, ""));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "27017"));
    Ok((user.to_owned(), pass.to_owned(), host.to_owned(), port.parse().map_err(|_| Fault::new("invalid port"))?))
}

/// `new Client(...)` from arguments, defaulting to the `mongo` service and
/// database `${ns}`. The client reports PHP's host (`mongo@php`) and dials
/// this runtime's address.
fn client(session: &Session, a: &Json) -> Result<Client, Fault> {
    built(session, a)?.map_err(|e| Fault::new(format!("{}: {}", e.php_class(), e.message())))
}

/// [`client`], with the constructor's error as an error.
fn built(session: &Session, a: &Json) -> Result<Result<Client, Error>, Fault> {
    let (user, pass, host, port) = url(session, "mongo")?;
    let php_host = url(session, "mongo@php").map(|u| u.2).unwrap_or_else(|_| host.clone());
    let php_port = url(session, "mongo@php").map(|u| u.3).unwrap_or(port);
    let s = |key: &str, default: &str| a.get(key).and_then(Json::as_str).unwrap_or(default).to_owned();
    let mut config = Config::new(
        s("database", &session.ns),
        s("host", &php_host),
        a.get("port").and_then(Json::as_i64).unwrap_or(i64::from(php_port)),
        s("user", &user),
        s("password", &pass),
    );
    config.tls = a.get("tls").and_then(Json::as_bool).unwrap_or(false);
    config.auth_source = a.get("auth_source").and_then(Json::as_str).map(str::to_owned);
    if let Some(t) = a.get("timeout").and_then(Json::as_f64) {
        config.timeout = t;
    }
    config.connect_timeout = a.get("connect_timeout").and_then(Json::as_f64);
    if a.get("host").is_none() && a.get("port").is_none() {
        config.address = Some((host, port));
    }
    Ok(Client::new(config))
}

async fn connect(session: &Session, a: &Json) -> Result<Client, Fault> {
    let c = client(session, a)?;
    c.connect().await.map_err(|e| Fault::new(e.to_string()))?;
    Ok(c)
}

fn the_client(session: &Session, a: &Json) -> Result<Shared, Fault> {
    session
        .get::<Shared>(a.get("client").unwrap_or(&Json::Null))
        .cloned()
        .map_err(|_| Fault::new("`client` is not a client handle"))
}

fn options(a: &Json) -> Result<Options, Fault> {
    Ok(Options {
        session: match a.get("session") {
            None | Some(Json::Null) => None,
            s => Some(decode_session(s)?),
        },
        fields: document(a.get("options"))?,
    })
}

fn string<'a>(a: &'a Json, key: &str) -> &'a str {
    a.get(key).and_then(Json::as_str).unwrap_or("")
}

fn client_marker() -> Json {
    json!({ "$client": true })
}

/// `listDatabaseNames()` with only this run's databases and the server's own.
fn databases(mut reply: Document, ns: &str) -> Json {
    if let Ok(list) = reply.get_array_mut("databases") {
        list.retain(|d| {
            let name = match d {
                Bson::Document(d) => d.get_str("name").unwrap_or(""),
                _ => "",
            };
            matches!(name, "admin" | "config" | "local") || name == ns || name.starts_with(&format!("{ns}_"))
        });
    }
    encode_document(&reply)
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    if op.starts_with("client.") && args.get("inspect").and_then(Json::as_bool) == Some(true) {
        return Ok(match client_op(op, args, session).await? {
            Err(e) if e.failure().is_some() => Outcome::ok(json!({ "$exception": inspect(&e) })),
            Err(e) => err(&e),
            Ok(v) => Outcome::Ok(v),
        });
    }
    if op.starts_with("client.") {
        return Ok(match client_op(op, args, session).await? {
            Ok(v) => Outcome::Ok(v),
            Err(e) => err(&e),
        });
    }
    match op {
        "exception.new" => Ok(Outcome::Ok(inspect(&exception(args)?))),
        "exception.from_response" => {
            let response = document(args.get("response"))?;
            let error = Error::from_response(&response, args.get("operation_type").and_then(Json::as_str));
            Ok(Outcome::Ok(inspect(&error)))
        }
        _ if op.starts_with("auth.") => auth_op(op, args, session),
        _ => Err(Fault::new(format!("mongo: unknown operation `{op}`"))),
    }
}

fn the_auth<'a>(session: &'a mut Session, a: &Json) -> Result<&'a mut Auth, Fault> {
    session
        .get_mut::<Auth>(a.get("auth").unwrap_or(&Json::Null))
        .map_err(|_| Fault::new("`auth` is not an auth handle"))
}

fn bytes_json(bytes: &[u8]) -> Json {
    crate::adapter::bytes_value(bytes)
}

fn auth_op(op: &str, a: &Json, session: &mut Session) -> OpResult {
    let r = |r: Result<Json, Error>| Ok(r.map_or_else(|e| err(&e), Outcome::Ok));
    match op {
        "auth.new" => {
            let get = |k: &str| a.get(k).and_then(Json::as_str).map(str::to_owned);
            let credentials = Credentials {
                authcid: get("authcid").unwrap_or_default(),
                secret: get("secret").unwrap_or_default(),
                authzid: get("authzid").unwrap_or_default(),
                auth_source: get("auth_source"),
            };
            match Auth::new(credentials) {
                Ok(auth) => Ok(Outcome::Ok(session.handle(auth))),
                Err(e) => Ok(err(&e)),
            }
        }
        "auth.start" => r(the_auth(session, a)?.start().map(|(c, db)| json!([encode_array(&c), db]))),
        "auth.continue" => {
            let id = decode(a.get("conversation_id").unwrap_or(&Json::Null))?;
            let payload = a.get("payload").and_then(crate::adapter::bytes).unwrap_or_default();
            r(the_auth(session, a)?.continue_with(id, &payload).map(|(c, db)| json!([encode_array(&c), db])))
        }
        "auth.create_response" => {
            let challenge = a.get("challenge").and_then(crate::adapter::bytes);
            r(the_auth(session, a)?
                .create_response(challenge.as_deref())
                .map(|out| out.map_or(Json::Bool(false), |b| bytes_json(&b))))
        }
        "auth.verify" => {
            let data = a.get("data").and_then(crate::adapter::bytes).unwrap_or_default();
            Ok(Outcome::Ok(Json::Bool(the_auth(session, a)?.verify(&data))))
        }
        "auth.get_cnonce" => Ok(Outcome::Ok(Json::String(the_auth(session, a)?.cnonce().to_owned()))),
        "auth.get_salted_password" => r(the_auth(session, a)?.salted_password().map(bytes_json)),
        "auth.get_auth_message" => r(the_auth(session, a)?.auth_message().map(bytes_json)),
        "auth.encode_credentials" => {
            Ok(Outcome::Ok(Json::String(Auth::encode_credentials(string(a, "username"), string(a, "password")))))
        }
        "auth.server_first" => {
            use base64::Engine as _;
            let salt = a.get("salt").and_then(crate::adapter::bytes).unwrap_or_default();
            let iterations = a.get("iterations").and_then(Json::as_i64).unwrap_or(4096);
            let cnonce = the_auth(session, a)?.cnonce().to_owned();
            Ok(Outcome::Ok(Json::String(format!(
                "r={cnonce}{},s={},i={iterations}",
                string(a, "suffix"),
                base64::engine::general_purpose::STANDARD.encode(salt)
            ))))
        }
        _ => Err(Fault::new(format!("mongo: unknown operation `{op}`"))),
    }
}

/// A client operation: its encoded result or the client's error.
async fn client_op(op: &str, a: &Json, session: &mut Session) -> Result<Result<Json, Error>, Fault> {
    if op == "client.new" {
        let c = match built(session, a)? {
            Ok(c) => c,
            Err(e) => return Ok(Err(e)),
        };
        if a.get("connect").and_then(Json::as_bool) != Some(false)
            && let Err(e) = c.connect().await
        {
            return Ok(Err(e));
        }
        return Ok(Ok(session.handle(Arc::new(c))));
    }
    if matches!(
        op,
        "client.is_transient_transaction_error"
            | "client.is_unknown_transaction_commit_result"
            | "client.create_write_concern"
            | "client.create_read_concern"
    ) {
        let c = client(session, &json!({}))?;
        return Ok(Ok(match op {
            "client.is_transient_transaction_error" => Json::Bool(c.is_transient_transaction_error(&exception(a)?)),
            "client.is_unknown_transaction_commit_result" => {
                Json::Bool(c.is_unknown_transaction_commit_result(&exception(a)?))
            }
            "client.create_write_concern" => {
                let concern = decode(a.get("concern").unwrap_or(&Json::Null))?;
                return Ok(c.create_write_concern(&concern).map(|d| encode_array(&d.to_document())));
            }
            _ => {
                let concern = decode(a.get("concern").unwrap_or(&Json::Null))?;
                return Ok(c.create_read_concern(&concern).map(|d| encode_array(&d.to_document())));
            }
        }));
    }
    let c = the_client(session, a)?;
    let collection = string(a, "collection").to_owned();
    let db = a.get("db").and_then(Json::as_str);
    Ok(match op {
        "client.connect" => c.connect().await.map(|_| client_marker()),
        "client.get_host" => Ok(Json::String(c.host().to_owned())),
        "client.create_uuid" => Ok(Json::String(c.create_uuid())),
        "client.select_database" => Ok({
            c.select_database();
            client_marker()
        }),
        "client.create_database" => Ok({
            c.create_database();
            client_marker()
        }),
        "client.select_collection" => Ok({
            c.select_collection(string(a, "name"));
            client_marker()
        }),
        "client.query" => {
            let command = document(a.get("command"))?;
            let s = match a.get("session") {
                None | Some(Json::Null) => None,
                s => Some(decode_session(s)?),
            };
            c.query_in(s.as_ref(), command, db).await.map(encode_reply)
        }
        "client.list_database_names" => c.list_database_names().await.map(|d| databases(d, &session.ns)),
        "client.drop_database" => c.drop_database(document(a.get("options"))?, db).await.map(Json::Bool),
        "client.create_collection" => c.create_collection(string(a, "name"), options(a)?).await.map(Json::Bool),
        "client.drop_collection" => c.drop_collection(string(a, "name"), options(a)?).await.map(Json::Bool),
        "client.list_collection_names" => {
            c.list_collection_names(document(a.get("filter"))?, options(a)?).await.map(|d| encode_document(&d))
        }
        "client.create_indexes" => {
            let indexes = documents(a.get("indexes"))?;
            c.create_indexes(&collection, indexes, options(a)?).await.map(Json::Bool)
        }
        "client.drop_indexes" => {
            let indexes = decode(a.get("indexes").unwrap_or(&json!([])))?;
            c.drop_indexes(&collection, indexes, options(a)?).await.map(|_| client_marker())
        }
        "client.insert" => {
            c.insert(&collection, document(a.get("document"))?, options(a)?).await.map(|d| encode_array(&d))
        }
        "client.insert_many" => c
            .insert_many(&collection, documents(a.get("documents"))?, options(a)?)
            .await
            .map(|docs| Json::Array(docs.iter().map(encode_array).collect())),
        "client.last_document" => c.last_document(&collection).await.map(|d| encode_array(&d)),
        "client.update" => c
            .update(
                &collection,
                document(a.get("where"))?,
                document(a.get("updates"))?,
                options(a)?,
                a.get("multi").and_then(Json::as_bool).unwrap_or(false),
            )
            .await
            .map(|n| json!(n)),
        "client.upsert" => {
            let mut operations = Vec::new();
            for op in a.get("operations").and_then(Json::as_array).cloned().unwrap_or_default() {
                operations.push(Upsert {
                    filter: document(op.get("filter"))?,
                    update: document(op.get("update"))?,
                    multi: op.get("multi").and_then(Json::as_bool).unwrap_or(false),
                });
            }
            c.upsert(&collection, operations, options(a)?).await.map(|n| json!(n))
        }
        "client.find" => {
            c.find(&collection, document(a.get("filters"))?, options(a)?).await.map(|d| encode_document(&d))
        }
        "client.aggregate" => {
            let pipeline = match decode(a.get("pipeline").unwrap_or(&json!([])))? {
                Bson::Array(items) => items,
                _ => Vec::new(),
            };
            c.aggregate(&collection, pipeline, options(a)?).await.map(|d| encode_document(&d))
        }
        "client.find_and_modify" => {
            let update = Bson::Document(document(a.get("update"))?);
            let remove = a.get("remove").and_then(Json::as_bool).unwrap_or(false);
            c.find_and_modify(&collection, update, remove, document(a.get("filters"))?, options(a)?)
                .await
                .map(|d| encode_document(&d))
        }
        "client.get_more" => {
            let cursor = match decode(a.get("cursor_id").unwrap_or(&Json::Null))? {
                Bson::Int32(i) => i64::from(i),
                Bson::Int64(i) => i,
                _ => 0,
            };
            let batch = a.get("batch_size").and_then(Json::as_i64).unwrap_or(25);
            c.get_more(cursor, &collection, batch).await.map(|d| encode_document(&d))
        }
        "client.delete" => c
            .delete(
                &collection,
                document(a.get("filters"))?,
                a.get("limit").and_then(Json::as_i64).unwrap_or(1),
                document(a.get("delete_options"))?,
                options(a)?,
            )
            .await
            .map(|n| json!(n)),
        "client.count" => c.count(&collection, document(a.get("filters"))?, options(a)?).await.map(|n| json!(n)),
        "client.start_session" => c.start_session(document(a.get("options"))?).await.map(|s| encode_session(&s)),
        "client.start_transaction" => {
            c.start_transaction(&decode_session(a.get("session"))?, document(a.get("options"))?).await.map(Json::Bool)
        }
        "client.commit_transaction" => c
            .commit_transaction(&decode_session(a.get("session"))?, document(a.get("options"))?)
            .await
            .map(|d| encode_document(&d)),
        "client.abort_transaction" => c
            .abort_transaction(&decode_session(a.get("session"))?, document(a.get("options"))?)
            .await
            .map(|d| encode_document(&d)),
        "client.end_sessions" => {
            let mut sessions = Vec::new();
            for s in a.get("sessions").and_then(Json::as_array).cloned().unwrap_or_default() {
                sessions.push(decode_session(Some(&s))?);
            }
            c.end_sessions(&sessions, document(a.get("options"))?).await.map(|d| encode_document(&d))
        }
        "client.with_transaction" => return with_transaction(&c, a, session).await,
        "client.is_replica_set" => c.is_replica_set().await.map(Json::Bool),
        "client.close" => {
            c.close().await;
            Ok(Json::Null)
        }
        "client.get_operation_time" => Ok(c.operation_time().as_ref().map_or(Json::Null, encode)),
        "client.get_cluster_time" => Ok(c.cluster_time().as_ref().map_or(Json::Null, encode)),
        "client.get_session_state" => Ok(match c.session_state(&decode_session(a.get("session"))?) {
            None => json!({ "error": "Session not found" }),
            Some(s) => json!({
                "sessionId": s.session_id,
                "state": s.state.as_str(),
                "txnNumber": s.txn_number,
                "lastUse": s.last_use,
                "retryableWriteNumber": s.retryable_write_number,
            }),
        }),
        "client.get_connection_info" => {
            let i = c.connection_info();
            Ok(json!({
                "connected": i.connected,
                "host": i.host,
                "port": i.port,
                "database": i.database,
                "activeSessions": i.active_sessions,
                "clusterTimeSet": i.cluster_time_set,
                "operationTimeSet": i.operation_time_set,
            }))
        }
        "client.cleanup_stale_sessions" => {
            c.cleanup_stale_sessions().await;
            Ok(Json::Null)
        }
        _ => return Err(Fault::new(format!("mongo: unknown operation `{op}`"))),
    })
}

fn documents(value: Option<&Json>) -> Result<Vec<Document>, Fault> {
    match value.map(decode).transpose()? {
        None | Some(Bson::Null) => Ok(Vec::new()),
        Some(Bson::Array(items)) => items
            .into_iter()
            .map(|v| match v {
                Bson::Document(d) => Ok(d),
                Bson::Array(list) => Ok(list.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect()),
                _ => Err(Fault::new("expected documents")),
            })
            .collect(),
        Some(Bson::Document(d)) => d
            .into_iter()
            .map(|(_, v)| match v {
                Bson::Document(d) => Ok(d),
                _ => Err(Fault::new("expected documents")),
            })
            .collect(),
        Some(_) => Err(Fault::new("expected documents")),
    }
}

/// `withTransaction()` with a callback that runs `steps` (client
/// operations, each given the callback's session) and returns `return`, or
/// throws `throw` ({message, code}).
async fn with_transaction(c: &Shared, a: &Json, session: &mut Session) -> Result<Result<Json, Error>, Fault> {
    let s = decode_session(a.get("session"))?;
    let callback = a.get("callback").cloned().unwrap_or(json!({}));
    let fields = document(a.get("options"))?;
    let calls: std::cell::RefCell<Vec<Json>> = std::cell::RefCell::new(Vec::new());
    let client_handle = a.get("client").cloned().unwrap_or(Json::Null);
    let session_cell = utopia_async::sync::Mutex::new(session);
    let calls_cell = &calls;
    let result = c
        .with_transaction(
            &s,
            |inner: MongoSession| {
                let callback = callback.clone();
                let client_handle = client_handle.clone();
                let session_cell = &session_cell;
                async move {
                    let mut results = Vec::new();
                    for step in callback.get("steps").and_then(Json::as_array).cloned().unwrap_or_default() {
                        let mut args = step.get("args").cloned().unwrap_or(json!({}));
                        if let Json::Object(map) = &mut args {
                            map.insert("client".into(), client_handle.clone());
                            map.insert("session".into(), encode_session(&inner));
                        }
                        let op = step.get("op").and_then(Json::as_str).unwrap_or("").to_owned();
                        let outcome = {
                            let mut guard = session_cell.lock().await;
                            Box::pin(client_op(&op, &args, &mut guard)).await
                        };
                        match outcome {
                            Ok(Ok(v)) => results.push(v),
                            Ok(Err(e)) => return Err(e),
                            Err(fault) => return Err(Error::exception(format!("fault: {}", fault.0), 0)),
                        }
                    }
                    calls_cell.borrow_mut().push(Json::Array(results));
                    if let Some(t) = callback.get("throw") {
                        return Err(Error::exception(
                            t.get("message").and_then(Json::as_str).unwrap_or(""),
                            t.get("code").and_then(Json::as_i64).unwrap_or(0),
                        ));
                    }
                    Ok(callback.get("return").cloned().unwrap_or(Json::Null))
                }
            },
            fields,
        )
        .await;
    Ok(result.map(|r| json!({ "result": r, "calls": calls.into_inner() })))
}
