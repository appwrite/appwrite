//! Compat adapter for `smtp`: maps `tests/compat/smtp/spec.json` operations onto `utopia-smtp`.
//!
//! Scripted sessions run on [`Fake`], the twin of the library's own
//! `FakeTransport` (through `tests/compat/smtp/Recording.php`); real ones on
//! [`TcpTransport`] against the Mailpit servers of `services.sh`, read back
//! through Mailpit's API. Random MIME boundaries are renamed `=_BOUNDARY<n>`
//! in order of appearance on both sides.

use std::rc::Rc;
use std::time::Duration;

use serde_json::{Map, Value, json};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use utopia_smtp::auth::{Authenticator, Login, Plain, XOAuth2};
use utopia_smtp::mime::{Part, encoding, header};
use utopia_smtp::{
    Address, Attachment, Capabilities, Client, Date, Delivery, Encryption, Envelope, Error, Message, Outcome as Class,
    Refusal, Reply, Source, TcpTransport, Timeouts, Tls, Transport, Verification,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "address.new",
    "header.line",
    "header.phrase",
    "encoding.is_ascii",
    "encoding.quoted_printable",
    "encoding.base64",
    "encoding.line_endings",
    "encoding.boundary",
    "envelope.new",
    "envelope.from_message",
    "attachment.new",
    "message.render",
    "part.render",
    "reply.new",
    "outcome.from_code",
    "capabilities.parse",
    "capabilities.none",
    "timeouts.new",
    "tls.new",
    "auth.exchange",
    "exception.new",
    "file.write",
    "file.delete",
    "client.new",
    "client.send_raw",
    "client.send",
    "client.capabilities",
    "client.command",
    "client.noop",
    "client.reset",
    "client.close",
    "client.ping",
    "client.idle",
    "client.transactions",
    "client.session",
    "fake.reply",
    "fake.state",
    "listener.new",
    "listener.accept",
    "listener.send",
    "listener.receive",
    "listener.hangup",
    "transport.new",
    "transport.connect",
    "transport.read",
    "transport.write",
    "transport.start_tls",
    "transport.is_tls",
    "transport.close",
    "mailpit.clear",
    "mailpit.count",
    "mailpit.message",
    "mailpit.raw",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("message", "Build and render a message with text, HTML and a 4 KiB attachment"),
    ("header", "Encode and fold a long non-ASCII subject"),
    ("ehlo", "Read a ten-line EHLO reply into capabilities"),
    ("send", "Send a message to Mailpit over an open session"),
];

/// Keywords whose has()/params() a capabilities result reports by default.
const KEYWORDS: &[&str] =
    &["PIPELINING", "SIZE", "8BITMIME", "SMTPUTF8", "STARTTLS", "AUTH", "ENHANCEDSTATUSCODES", "CHUNKING"];

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    let attachment: Vec<u8> = b"\x00\x01\x02binary\xff".repeat(400);
    let ehlo = [
        "mail.example.test",
        "PIPELINING",
        "SIZE 35882577",
        "ETRN",
        "AUTH PLAIN LOGIN XOAUTH2",
        "ENHANCEDSTATUSCODES",
        "8BITMIME",
        "DSN",
        "SMTPUTF8",
        "CHUNKING",
    ];
    let lib = |e: Error| Fault::new(e.to_string());
    Ok(match name {
        "message" => {
            let from = Address::new("jane@example.test", "Jane Doe").map_err(lib)?;
            let to = Address::new("john@example.test", "John").map_err(lib)?;
            let text = "The numbers are in.\n".repeat(20);
            let html = "<p>The numbers are in.</p>".repeat(20);
            Bench::sync(move || {
                let message = Message::builder(from.clone(), "Quarterly report")
                    .to(to.clone())
                    .text(text.as_str())
                    .html(html.as_str())
                    .attachment(Attachment::from_bytes(attachment.clone(), "report.bin", None).unwrap())
                    .message_id("bench@example.test")
                    .build()
                    .unwrap();
                black_box(message.to_bytes().unwrap());
            })
        }
        "header" => Bench::sync(|| {
            black_box(header::line(
                "Subject",
                black_box("Rapport trimestriel très détaillé sur les résultats du troisième trimestre"),
            ))
            .ok();
        }),
        "ehlo" => Bench::sync(move || {
            let reply = Reply::new(250, black_box(ehlo)).unwrap();
            black_box(Capabilities::from_reply(&reply).max_size());
        }),
        "send" => {
            let transport = native(session, &json!({"service": "smtp"}))?;
            let client = Client::new(AnyTransport::Tcp(transport))
                .with_domain("bench.example.test")
                .with_authenticator(Plain::new("jane", "secret"))
                .with_encryption(Encryption::None);
            let message = Message::builder(Address::new("jane@example.test", "").map_err(lib)?, "Bench")
                .to(Address::new("john@example.test", "").map_err(lib)?)
                .text("Body")
                .message_id("bench@example.test")
                .build()
                .map_err(lib)?;
            let shared = Rc::new((tokio::sync::Mutex::new(client), message));
            Bench::future(move || {
                let shared = shared.clone();
                async move {
                    let mut client = shared.0.lock().await;
                    black_box(client.send(&shared.1).await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("smtp: no benchmark `{name}`"))),
    })
}

/// A library error or a harness problem.
enum Fail {
    Lib(Error),
    Fault(Fault),
}

impl From<Error> for Fail {
    fn from(e: Error) -> Self {
        Fail::Lib(e)
    }
}

impl From<Fault> for Fail {
    fn from(f: Fault) -> Self {
        Fail::Fault(f)
    }
}

type R<T> = Result<T, Fail>;

fn err(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

pub async fn call(op: &str, args: &Value, s: &mut Session) -> OpResult {
    match run(op, args, s).await {
        Ok(v) => Ok(Outcome::Ok(v)),
        Err(Fail::Lib(e)) => Ok(err(&e)),
        Err(Fail::Fault(f)) => Err(f),
    }
}

fn text(v: &Value, key: &str) -> Result<String, Fault> {
    let b = bytes(v.get(key).ok_or_else(|| Fault::new(format!("missing argument `{key}`")))?)
        .ok_or_else(|| Fault::new(format!("argument `{key}` must be a string")))?;
    String::from_utf8(b).map_err(|_| Fault::new(format!("argument `{key}` is not UTF-8 (a deviation)")))
}

fn opt_text(v: &Value, key: &str) -> Result<Option<String>, Fault> {
    match v.get(key) {
        None | Some(Value::Null) => Ok(None),
        Some(_) => text(v, key).map(Some),
    }
}

fn texts(v: &Value, key: &str) -> Result<Vec<String>, Fault> {
    match v.get(key) {
        None | Some(Value::Null) => Ok(Vec::new()),
        Some(Value::Array(items)) => items
            .iter()
            .map(|i| {
                bytes(i)
                    .and_then(|b| String::from_utf8(b).ok())
                    .ok_or_else(|| Fault::new(format!("`{key}` must hold UTF-8 strings")))
            })
            .collect(),
        Some(_) => Err(Fault::new(format!("`{key}` must be a list"))),
    }
}

fn raw(v: &Value, key: &str) -> Result<Vec<u8>, Fault> {
    Args(v).bytes(key)
}

async fn run(op: &str, a: &Value, s: &mut Session) -> R<Value> {
    let args = Args(a);
    Ok(match op {
        "address.new" => address_out(&address(a)?),
        "header.line" => Value::String(header::line(&text(a, "name")?, &text(a, "value")?)?),
        "header.phrase" => Value::String(header::phrase(&text(a, "value")?)),
        "encoding.is_ascii" => Value::Bool(encoding::is_ascii(&raw(a, "value")?)),
        "encoding.quoted_printable" => bytes_value(&encoding::quoted_printable(&raw(a, "value")?)),
        "encoding.base64" => bytes_value(&encoding::base64(&raw(a, "value")?)),
        "encoding.line_endings" => bytes_value(&encoding::line_endings(&raw(a, "value")?)),
        "encoding.boundary" => {
            let boundaries: Vec<String> = (0..args.i64("count")?).map(|_| encoding::boundary()).collect();
            let mut distinct = boundaries.clone();
            distinct.sort();
            distinct.dedup();
            json!({
                "distinct": distinct.len(),
                "lengths": unique(boundaries.iter().map(|b| json!(b.len()))),
                "prefixes": unique(boundaries.iter().map(|b| json!(&b[..2]))),
                "hex": unique(boundaries.iter().map(|b| json!(b[2..].bytes().all(|c| c.is_ascii_hexdigit()) && b.len() > 2))),
            })
        }
        "envelope.new" => envelope_out(&envelope(a)?),
        "envelope.from_message" => envelope_out(&Envelope::from_message(&message(args.value("message")?)?)),
        "attachment.new" => {
            let attachment = attachment(a)?;
            let (content, path) = match attachment.source() {
                Source::Bytes(b) => (bytes_value(b), Value::Null),
                Source::Path(p) => (Value::Null, Value::String(p.to_string_lossy().into_owned())),
            };
            json!({
                "name": attachment.name(),
                "type": attachment.mime_type(),
                "content": content,
                "path": path,
                "cid": attachment.cid(),
            })
        }
        "message.render" => {
            let message = message(args.value("message")?)?;
            let string = message.to_bytes()?;
            let names = boundaries(&string);
            let mut chunks = Vec::new();
            for chunk in message.chunks() {
                chunks.push(bytes_value(&rename(&chunk?, &names)));
            }
            json!({
                "string": bytes_value(&rename(&string, &names)),
                "chunks": chunks,
                "international": message.is_international(),
                "message_id": message.message_id(),
                "date": message.date().rfc2822(),
            })
        }
        "part.render" => {
            let part = part(args.value("part")?)?;
            let head = part.head()?;
            let mut body = Vec::new();
            for chunk in part.body() {
                body.push(chunk?.into_owned());
            }
            let mut all = head.as_bytes().to_vec();
            body.iter().for_each(|c| all.extend_from_slice(c));
            let names = boundaries(&all);
            json!({
                "head": bytes_value(&rename(head.as_bytes(), &names)),
                "body": body.iter().map(|c| bytes_value(&rename(c, &names))).collect::<Vec<_>>(),
            })
        }
        "reply.new" => reply_out(&reply(a)?),
        "outcome.from_code" => Value::String(Class::from_code(code(a, "code")?)?.name().into()),
        "capabilities.parse" => {
            let code = match a.get("code") {
                Some(_) => code(a, "code")?,
                None => 250,
            };
            let reply = Reply::new(code, texts(a, "lines")?)?;
            capabilities_out(&Capabilities::from_reply(&reply), a)?
        }
        "capabilities.none" => capabilities_out(&Capabilities::none(), a)?,
        "timeouts.new" => {
            let (connect, read, write) = timeouts(a)?.seconds();
            json!({"connect": float_value(connect), "read": float_value(read), "write": float_value(write)})
        }
        "tls.new" => {
            let tls = tls(a)?;
            json!({
                "verify": format!("{:?}", tls.verify),
                "peer_name": tls.peer_name,
                "ca_file": tls.ca_file.map(|p| p.to_string_lossy().into_owned()),
                "ciphers": tls.ciphers,
            })
        }
        "auth.exchange" => {
            let authenticator = authenticator(a)?;
            let mut responses = Vec::new();
            if let Some(Value::Array(challenges)) = a.get("challenges") {
                for (step, c) in challenges.iter().enumerate() {
                    let c = bytes(c).ok_or_else(|| Fault::new("a challenge must be a string"))?;
                    responses.push(bytes_value(&authenticator.respond(&c, step)));
                }
            }
            json!({
                "mechanism": authenticator.mechanism(),
                "initial": authenticator.initial().map(|i| bytes_value(&i)),
                "responses": responses,
            })
        }
        "exception.new" => {
            let message = || opt_text(a, "message").map(Option::unwrap_or_default);
            let e = match args.str("class")? {
                "connection" => Error::Connection(message()?),
                "timeout" => Error::Timeout(message()?),
                "protocol" => Error::Protocol(message()?),
                "authentication" => Error::Authentication { message: message()?, code: 0 },
                "capability" => Error::Capability(message()?),
                "message" => Error::Message(message()?),
                "transaction" => {
                    let mut rejected = indexmap::IndexMap::new();
                    if let Some(Value::Object(r)) = a.get("rejected") {
                        for (k, v) in r {
                            rejected.insert(k.clone(), reply(v)?);
                        }
                    }
                    Refusal::new(reply(args.value("reply")?)?, message()?, rejected).into()
                }
                other => return Err(Fault::new(format!("unknown exception {other:?}")).into()),
            };
            let mut out = error_out(&e);
            out.insert("smtp".into(), Value::Bool(e.is_smtp()));
            out.insert("connection".into(), Value::Bool(e.is_connection()));
            Value::Object(out)
        }
        "file.write" => {
            let path = args.str("path")?;
            if let Some(dir) = std::path::Path::new(path).parent() {
                let _ = std::fs::create_dir_all(dir);
            }
            std::fs::write(path, raw(a, "content")?).map_err(|e| Fault::new(e.to_string()))?;
            Value::Null
        }
        "file.delete" => Value::Bool(std::fs::remove_file(args.str("path")?).is_ok()),

        "client.new" => client(a, s)?,
        "client.send_raw" => {
            let envelope = envelope(args.value("envelope")?)?;
            let content = args.value("content")?;
            let client = client_of(a, s)?;
            catching(a, async {
                let delivery = match content {
                    Value::Array(chunks) => {
                        let chunks: Vec<Vec<u8>> = chunks
                            .iter()
                            .map(|c| bytes(c).ok_or_else(|| Fault::new("a chunk must be a string")))
                            .collect::<Result<_, _>>()?;
                        client.send_chunks(&envelope, chunks).await?
                    }
                    other => {
                        let content = bytes(other).ok_or_else(|| Fault::new("content must be a string"))?;
                        client.send_raw(&envelope, &content).await?
                    }
                };
                Ok(delivery_out(&delivery))
            })
            .await?
        }
        "client.send" => {
            let message = message(args.value("message")?)?;
            let client = client_of(a, s)?;
            catching(a, async { Ok(delivery_out(&client.send(&message).await?)) }).await?
        }
        "client.capabilities" => {
            let client = client_of(a, s)?;
            catching(a, async {
                let capabilities = client.capabilities().await?.clone();
                capabilities_out(&capabilities, a)
            })
            .await?
        }
        "client.command" => {
            let command = text(a, "command")?;
            let expect = expect(a)?;
            let client = client_of(a, s)?;
            catching(a, async { Ok(reply_out(&client.command(&command, &expect).await?)) }).await?
        }
        "client.noop" => {
            let client = client_of(a, s)?;
            catching(a, async { client.noop().await.map(|()| Value::Null).map_err(Fail::from) }).await?
        }
        "client.reset" => {
            let client = client_of(a, s)?;
            catching(a, async { client.reset().await.map(|()| Value::Null).map_err(Fail::from) }).await?
        }
        "client.close" => {
            client_of(a, s)?.try_close().await?;
            Value::Null
        }
        "client.ping" => Value::Bool(client_of(a, s)?.ping().await?),
        "client.idle" => match client_of(a, s)?.idle() {
            None => json!({"session": false}),
            Some(idle) => json!({"session": true, "under_a_second": idle < Duration::from_secs(1)}),
        },
        "client.transactions" => json!(client_of(a, s)?.transactions()),

        "client.session" => {
            let handle = client(a, s)?;
            let envelope = envelope(args.value("envelope")?)?;
            let content = args.value("content")?;
            let client = s.get_mut::<Client<AnyTransport>>(&handle)?;
            let sent = async {
                Ok(delivery_out(&match content {
                    Value::Array(chunks) => {
                        let chunks: Vec<Vec<u8>> = chunks
                            .iter()
                            .map(|c| bytes(c).ok_or_else(|| Fault::new("a chunk must be a string")))
                            .collect::<Result<_, _>>()?;
                        client.send_chunks(&envelope, chunks).await?
                    }
                    other => {
                        let content = bytes(other).ok_or_else(|| Fault::new("content must be a string"))?;
                        client.send_raw(&envelope, &content).await?
                    }
                }))
            };
            let result = catching(&json!({"catch": true}), sent).await?;
            let transactions = client.transactions();
            let AnyTransport::Fake(fake) = client.transport() else {
                return Err(Fault::new("not a scripted client").into());
            };
            json!({"result": result, "written": bytes_value(&fake.written), "closed": fake.closed, "transactions": transactions})
        }
        "fake.reply" => {
            let lines = texts(a, "lines")?;
            fake(a, s)?.reply(&lines);
            Value::Null
        }
        "fake.state" => {
            let fake = fake(a, s)?;
            json!({
                "written": bytes_value(&fake.written),
                "commands": fake.commands(),
                "connected": fake.connected,
                "closed": fake.closed,
                "handshakes": fake.handshakes,
                "tls": fake.tls,
                "waits": fake.waits.iter().map(|(k, t)| json!([k, float_value(*t)])).collect::<Vec<_>>(),
            })
        }

        "listener.new" => {
            let listener =
                TcpListener::bind("127.0.0.1:0").await.map_err(|e| Fault::new(format!("cannot listen: {e}")))?;
            let port = listener.local_addr().map_err(|e| Fault::new(e.to_string()))?.port();
            s.handle(Listener { listener, port, peer: None })
        }
        "listener.accept" => {
            let listener = s.get_mut::<Listener>(args.value("listener")?)?;
            let accepted = tokio::time::timeout(Duration::from_secs(2), listener.listener.accept()).await;
            let (peer, _) = accepted
                .ok()
                .and_then(Result::ok)
                .ok_or_else(|| Fault::new("the listener never saw the connection"))?;
            listener.peer = Some(peer);
            Value::Null
        }
        "listener.send" => {
            let data = raw(a, "data")?;
            let peer = peer(a, s)?;
            peer.write_all(&data).await.map_err(|e| Fault::new(e.to_string()))?;
            json!(data.len())
        }
        "listener.receive" => {
            let length = usize::try_from(args.i64("length")?).unwrap_or(0);
            let peer = peer(a, s)?;
            let mut read = Vec::new();
            let mut chunk = vec![0; 8192];
            while read.len() < length {
                match peer.read(&mut chunk).await {
                    Ok(0) | Err(_) => break,
                    Ok(n) => read.extend_from_slice(&chunk[..n]),
                }
            }
            bytes_value(&read)
        }
        "listener.hangup" => {
            s.get_mut::<Listener>(args.value("listener")?)?.peer = None;
            Value::Null
        }

        "transport.new" => {
            let port = match a.get("listener") {
                Some(l) => s.get::<Listener>(l)?.port,
                None => u16::try_from(args.i64("port")?).map_err(|_| Fault::new("port out of range"))?,
            };
            let host = opt_text(a, "host")?.unwrap_or_else(|| "127.0.0.1".into());
            let tls = tls(a.get("tls").unwrap_or(&Value::Null))?;
            s.handle(TcpTransport::new(host, port, tls))
        }
        "transport.connect" => {
            let timeout = seconds(args.f64("timeout")?);
            let tls = args.opt_bool("tls")?.unwrap_or(false);
            transport(a, s)?.connect(timeout, tls).await?;
            Value::Null
        }
        "transport.read" => {
            let length = usize::try_from(args.i64("length")?).unwrap_or(0);
            let timeout = seconds(args.f64("timeout")?);
            let mut buffer = Vec::new();
            transport(a, s)?.read(&mut buffer, length, timeout).await?;
            bytes_value(&buffer)
        }
        "transport.write" => {
            let data = raw(a, "data")?;
            let timeout = seconds(args.f64("timeout")?);
            transport(a, s)?.write(&data, timeout).await?;
            Value::Null
        }
        "transport.start_tls" => {
            let timeout = seconds(args.f64("timeout")?);
            transport(a, s)?.start_tls(timeout).await?;
            Value::Null
        }
        "transport.is_tls" => Value::Bool(transport(a, s)?.is_tls()),
        "transport.close" => {
            transport(a, s)?.close();
            Value::Null
        }

        "mailpit.clear" => {
            http(s, api(a), "DELETE", "/api/v1/messages").await?;
            Value::Null
        }
        "mailpit.count" => json!(messages(s, api(a)).await?.len()),
        "mailpit.message" => {
            let api = api(a);
            let id = only(s, api).await?;
            let body = http(s, api, "GET", &format!("/api/v1/message/{id}")).await?;
            let m: Value = serde_json::from_slice(&body).map_err(|e| Fault::new(e.to_string()))?;
            let people = |v: &Value| -> Vec<Value> {
                v.as_array().map_or_else(Vec::new, |l| {
                    l.iter().filter(|p| p.is_object()).map(|p| json!([p["Name"], p["Address"]])).collect()
                })
            };
            let files = |key: &str| -> Vec<(Value, String)> {
                m[key].as_array().map_or_else(Vec::new, |l| {
                    l.iter().map(|f| (f.clone(), f["PartID"].as_str().unwrap_or("").to_owned())).collect()
                })
            };
            let mut out = Map::new();
            out.insert("subject".into(), m["Subject"].clone());
            out.insert("from".into(), Value::Array(people(&json!([m["From"]]))));
            out.insert("to".into(), Value::Array(people(&m["To"])));
            out.insert("cc".into(), Value::Array(people(&m["Cc"])));
            out.insert("bcc".into(), Value::Array(people(&m["Bcc"])));
            out.insert("reply_to".into(), Value::Array(people(&m["ReplyTo"])));
            out.insert("return_path".into(), m["ReturnPath"].clone());
            out.insert("text".into(), m["Text"].clone());
            out.insert("html".into(), m["HTML"].clone());
            for (key, name) in [("Attachments", "attachments"), ("Inline", "inline")] {
                let mut list = Vec::new();
                for (f, part) in files(key) {
                    let content = http(s, api, "GET", &format!("/api/v1/message/{id}/part/{part}")).await?;
                    list.push(json!({
                        "name": f["FileName"],
                        "type": f["ContentType"],
                        "cid": f["ContentID"],
                        "size": f["Size"],
                        "content": bytes_value(&content),
                    }));
                }
                out.insert(name.into(), Value::Array(list));
            }
            Value::Object(out)
        }
        "mailpit.raw" => {
            let api = api(a);
            let id = only(s, api).await?;
            let raw = http(s, api, "GET", &format!("/api/v1/message/{id}/raw")).await?;
            let at = find(&raw, b"Date: ").unwrap_or(0);
            let ours = &raw[at..];
            let mut bcc = Value::Null;
            for line in raw[..at].split(|b| *b == b'\n') {
                let line = line.strip_suffix(b"\r").unwrap_or(line);
                if let Some(v) = line.strip_prefix(b"Bcc: ") {
                    bcc = bytes_value(v);
                }
            }
            let longest = split(ours, b"\r\n").map(<[u8]>::len).max().unwrap_or(0);
            json!({"ours": bytes_value(&rename(ours, &boundaries(ours))), "bcc": bcc, "longest": longest})
        }
        _ => return Err(Fault::new(format!("smtp: unknown operation `{op}`")).into()),
    })
}

fn unique(values: impl Iterator<Item = Value>) -> Vec<Value> {
    let mut out: Vec<Value> = Vec::new();
    for v in values {
        if !out.contains(&v) {
            out.push(v);
        }
    }
    out
}

fn seconds(s: f64) -> Duration {
    Duration::try_from_secs_f64(s).unwrap_or(Duration::ZERO)
}

fn code(a: &Value, key: &str) -> Result<u16, Fault> {
    Args(a).i64(key).and_then(|c| u16::try_from(c).map_err(|_| Fault::new("a reply code is 0..=65535 (a deviation)")))
}

fn expect(a: &Value) -> Result<Vec<u16>, Fault> {
    match a.get("expect") {
        Some(Value::Array(codes)) => codes
            .iter()
            .map(|c| c.as_u64().and_then(|c| u16::try_from(c).ok()).ok_or_else(|| Fault::new("bad expected code")))
            .collect(),
        Some(c) => {
            Ok(vec![c.as_u64().and_then(|c| u16::try_from(c).ok()).ok_or_else(|| Fault::new("bad expected code"))?])
        }
        None => Err(Fault::new("missing argument `expect`")),
    }
}

fn address(a: &Value) -> R<Address> {
    Ok(Address::new(text(a, "email")?, opt_text(a, "name")?.unwrap_or_default())?)
}

fn address_out(address: &Address) -> Value {
    json!({
        "email": address.email(),
        "name": address.name(),
        "string": address.to_string(),
        "international": address.is_international(),
    })
}

fn addresses(a: &Value, key: &str) -> R<Vec<Address>> {
    match a.get(key) {
        Some(Value::Array(list)) => list.iter().map(address).collect(),
        _ => Ok(Vec::new()),
    }
}

fn envelope(a: &Value) -> R<Envelope> {
    let envelope = Envelope::new(text(a, "sender")?, texts(a, "recipients")?)?;
    Ok(if Args(a).opt_bool("utf8")?.unwrap_or(false) { envelope.with_utf8() } else { envelope })
}

fn envelope_out(envelope: &Envelope) -> Value {
    json!({"sender": envelope.sender(), "recipients": envelope.recipients(), "international": envelope.is_international()})
}

fn attachment(a: &Value) -> R<Attachment> {
    let mime = opt_text(a, "type")?;
    let attachment = match opt_text(a, "path")? {
        Some(path) => Attachment::from_path(path, opt_text(a, "name")?.as_deref(), mime.as_deref())?,
        None => Attachment::from_bytes(raw(a, "content")?, &text(a, "name")?, mime.as_deref())?,
    };
    Ok(match opt_text(a, "cid")? {
        Some(cid) => attachment.inline(&cid)?,
        None => attachment,
    })
}

fn message(m: &Value) -> R<Message> {
    let from = address(m.get("from").ok_or_else(|| Fault::new("missing `from`"))?)?;
    let to = addresses(m, "to")?;
    let cc = addresses(m, "cc")?;
    let bcc = addresses(m, "bcc")?;
    let reply_to = addresses(m, "reply_to")?;
    let attachments: Vec<Attachment> = match m.get("attachments") {
        Some(Value::Array(list)) => list.iter().map(attachment).collect::<R<_>>()?,
        _ => Vec::new(),
    };
    let mut builder = Message::builder(from, opt_text(m, "subject")?.unwrap_or_default());
    for a in to {
        builder = builder.to(a);
    }
    for a in cc {
        builder = builder.cc(a);
    }
    for a in bcc {
        builder = builder.bcc(a);
    }
    for a in reply_to {
        builder = builder.reply_to(a);
    }
    for a in attachments {
        builder = builder.attachment(a);
    }
    if let Some(v) = m.get("text").filter(|v| !v.is_null()) {
        builder = builder.text(bytes(v).ok_or_else(|| Fault::new("text must be a string"))?);
    }
    if let Some(v) = m.get("html").filter(|v| !v.is_null()) {
        builder = builder.html(bytes(v).ok_or_else(|| Fault::new("html must be a string"))?);
    }
    if let Some(Value::Object(headers)) = m.get("headers") {
        for (name, value) in headers {
            let value = bytes(value)
                .and_then(|b| String::from_utf8(b).ok())
                .ok_or_else(|| Fault::new("a header value must be a UTF-8 string"))?;
            builder = builder.header(name.clone(), value);
        }
    }
    if let Some(d) = m.get("date").filter(|v| !v.is_null()) {
        let args = Args(d);
        let offset = i32::try_from(args.opt_i64("offset")?.unwrap_or(0)).map_err(|_| Fault::new("offset"))?;
        builder = builder.date(Date::new(args.i64("timestamp")?, offset));
    }
    if let Some(id) = opt_text(m, "message_id")? {
        builder = builder.message_id(id);
    }
    Ok(builder.build()?)
}

fn part(p: &Value) -> R<Part> {
    Ok(match Args(p).str("kind")? {
        "text" => Part::text(&text(p, "type")?, &raw(p, "content")?),
        "attachment" => Part::attachment(attachment(Args(p).value("attachment")?)?),
        "multipart" => {
            let parts = match p.get("parts") {
                Some(Value::Array(list)) => list.iter().map(part).collect::<R<Vec<_>>>()?,
                _ => Vec::new(),
            };
            Part::multipart(&text(p, "subtype")?, parts)
        }
        other => return Err(Fault::new(format!("unknown part {other:?}")).into()),
    })
}

/// Each random boundary (`=_` and 32 hex digits) and the name it gets, in
/// order of appearance.
fn boundaries(rendered: &[u8]) -> Vec<(Vec<u8>, Vec<u8>)> {
    let mut names: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
    let mut offset = 0;
    while let Some(at) = find(&rendered[offset.min(rendered.len())..], b"=_").map(|i| i + offset) {
        let candidate = &rendered[at..rendered.len().min(at + 34)];
        if candidate.len() == 34 && candidate[2..].iter().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(b)) {
            if !names.iter().any(|(k, _)| k == candidate) {
                let name = format!("=_BOUNDARY{}", names.len() + 1).into_bytes();
                names.push((candidate.to_vec(), name));
            }
            offset = at + 34;
        } else {
            offset = at + 1;
        }
    }
    names
}

/// `strtr` with the boundary names.
fn rename(s: &[u8], names: &[(Vec<u8>, Vec<u8>)]) -> Vec<u8> {
    if names.is_empty() {
        return s.to_vec();
    }
    let mut out = Vec::with_capacity(s.len());
    let mut i = 0;
    'outer: while i < s.len() {
        for (from, to) in names {
            if s[i..].starts_with(from) {
                out.extend_from_slice(to);
                i += from.len();
                continue 'outer;
            }
        }
        out.push(s[i]);
        i += 1;
    }
    out
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

fn split<'a>(s: &'a [u8], sep: &'a [u8]) -> impl Iterator<Item = &'a [u8]> {
    let mut rest = Some(s);
    std::iter::from_fn(move || {
        let r = rest?;
        match find(r, sep) {
            Some(i) => {
                rest = Some(&r[i + sep.len()..]);
                Some(&r[..i])
            }
            None => {
                rest = None;
                Some(r)
            }
        }
    })
}

fn reply(a: &Value) -> R<Reply> {
    Ok(Reply::new(code(a, "code")?, texts(a, "lines")?)?)
}

fn reply_out(reply: &Reply) -> Value {
    json!({
        "code": reply.code(),
        "lines": reply.lines(),
        "status": reply.status(),
        "outcome": reply.outcome().name(),
        "text": reply.text(),
        "string": reply.to_string(),
    })
}

fn capabilities_out(capabilities: &Capabilities, a: &Value) -> R<Value> {
    let keywords = match a.get("keywords") {
        Some(_) => texts(a, "keywords")?,
        None => KEYWORDS.iter().map(|k| (*k).to_owned()).collect(),
    };
    Ok(json!({
        "keywords": keywords.iter().map(|k| json!([k, capabilities.has(k), capabilities.params(k)])).collect::<Vec<_>>(),
        "max_size": capabilities.max_size(),
        "mechanisms": capabilities.mechanisms(),
    }))
}

fn delivery_out(delivery: &Delivery) -> Value {
    json!({
        "message_id": delivery.message_id,
        "accepted": delivery.accepted,
        "rejected": delivery.rejected.iter().map(|(r, reply)| json!([r, reply_out(reply)])).collect::<Vec<_>>(),
        "complete": delivery.is_complete(),
    })
}

fn error_out(e: &Error) -> Map<String, Value> {
    let mut out = Map::new();
    out.insert("class".into(), Value::String(e.php_class().into()));
    out.insert("message".into(), Value::String(e.to_string()));
    out.insert("code".into(), json!(e.code()));
    if let Some(refusal) = e.refusal() {
        out.insert("reply".into(), reply_out(&refusal.reply));
        out.insert(
            "rejected".into(),
            Value::Array(refusal.rejected.iter().map(|(r, reply)| json!([r, reply_out(reply)])).collect()),
        );
        out.insert("transient".into(), Value::Bool(refusal.is_transient()));
        out.insert("permanent".into(), Value::Bool(refusal.is_permanent()));
    }
    out
}

/// With `catch`, a runtime failure is returned as its details rather than
/// raised, so its reply and refusals are compared too.
async fn catching(a: &Value, call: impl Future<Output = R<Value>>) -> R<Value> {
    if !Args(a).opt_bool("catch")?.unwrap_or(false) {
        return call.await;
    }
    match call.await {
        Ok(v) => Ok(json!({"ok": v})),
        Err(Fail::Lib(e)) if e.is_smtp() => Ok(json!({"error": error_out(&e)})),
        Err(e) => Err(e),
    }
}

fn timeouts(a: &Value) -> R<Timeouts> {
    let get = |key: &str, default: f64| -> Result<f64, Fault> {
        match a.get(key) {
            None => Ok(default),
            Some(v) => float(v).ok_or_else(|| Fault::new(format!("`{key}` must be a number"))),
        }
    };
    Ok(Timeouts::new(get("connect", 10.0)?, get("read", 30.0)?, get("write", 30.0)?)?)
}

fn tls(t: &Value) -> R<Tls> {
    let verify = match t.get("verify").and_then(Value::as_str).unwrap_or("full") {
        "full" => Verification::Full,
        "self_signed" => Verification::SelfSigned,
        "none" => Verification::None,
        other => return Err(Fault::new(format!("unknown verification {other:?}")).into()),
    };
    Ok(Tls {
        verify,
        peer_name: opt_text(t, "peer_name")?,
        ca_file: opt_text(t, "ca_file")?.map(Into::into),
        ciphers: opt_text(t, "ciphers")?,
    })
}

fn authenticator(a: &Value) -> R<Box<dyn Authenticator>> {
    let (user, secret) = (text(a, "username")?, text(a, "secret")?);
    Ok(match Args(a).str("mechanism")? {
        "plain" => Box::new(Plain::new(user, secret)),
        "login" => Box::new(Login::new(user, secret)),
        "xoauth2" => Box::new(XOAuth2::new(user, secret)),
        other => return Err(Fault::new(format!("unknown mechanism {other:?}")).into()),
    })
}

fn native(s: &Session, t: &Value) -> Result<TcpTransport, Fault> {
    let address = s.service(t.get("service").and_then(Value::as_str).unwrap_or("smtp"))?;
    let at = address.rfind(':').ok_or_else(|| Fault::new("a service address is host:port"))?;
    let port = address[at + 1..].parse().map_err(|_| Fault::new("bad port"))?;
    let tls = tls(t).map_err(|e| match e {
        Fail::Fault(f) => f,
        Fail::Lib(e) => Fault::new(e.to_string()),
    })?;
    Ok(TcpTransport::new(&address[..at], port, tls))
}

fn client(a: &Value, s: &mut Session) -> R<Value> {
    let t = a.get("transport").cloned().unwrap_or(Value::Null);
    let transport = if t.get("service").is_some() {
        AnyTransport::Tcp(native(s, &t)?)
    } else {
        let args = Args(&t);
        let mut fake = Fake {
            fail_handshake: args.opt_bool("fail_handshake")?.unwrap_or(false),
            greedy: args.opt_bool("greedy")?.unwrap_or(false),
            after_handshake: texts(&t, "after_handshake")?,
            fail_writing: opt_text(&t, "fail_writing")?.map(String::into_bytes),
            chunk: args.opt_i64("chunk")?.map_or(usize::MAX, |c| usize::try_from(c).unwrap_or(0)),
            ..Fake::default()
        };
        fake.reply(&texts(&t, "replies")?);
        AnyTransport::Fake(fake)
    };
    let timeouts = match a.get("timeouts") {
        Some(t) => timeouts(t)?,
        None => Timeouts::default(),
    };
    let mut client = Client::new(transport).with_domain(opt_text(a, "domain")?.unwrap_or_else(|| "localhost".into()));
    if let Some(Value::Array(list)) = a.get("authenticators") {
        for spec in list {
            client = client.with_boxed_authenticator(authenticator(spec)?);
        }
    }
    let encryption = match a.get("encryption").and_then(Value::as_str).unwrap_or("opportunistic") {
        "none" => Encryption::None,
        "opportunistic" => Encryption::Opportunistic,
        "starttls" => Encryption::StartTls,
        "implicit" => Encryption::Implicit,
        other => return Err(Fault::new(format!("unknown encryption {other:?}")).into()),
    };
    Ok(s.handle(client.with_encryption(encryption).with_timeouts(timeouts)))
}

fn client_of<'a>(a: &Value, s: &'a mut Session) -> Result<&'a mut Client<AnyTransport>, Fault> {
    s.get_mut::<Client<AnyTransport>>(Args(a).value("client")?)
}

fn fake<'a>(a: &Value, s: &'a mut Session) -> Result<&'a mut Fake, Fault> {
    match client_of(a, s)?.transport_mut() {
        AnyTransport::Fake(f) => Ok(f),
        AnyTransport::Tcp(_) => Err(Fault::new("not a scripted client")),
    }
}

fn transport<'a>(a: &Value, s: &'a mut Session) -> Result<&'a mut TcpTransport, Fault> {
    s.get_mut::<TcpTransport>(Args(a).value("transport")?)
}

struct Listener {
    listener: TcpListener,
    port: u16,
    peer: Option<TcpStream>,
}

fn peer<'a>(a: &Value, s: &'a mut Session) -> Result<&'a mut TcpStream, Fault> {
    s.get_mut::<Listener>(Args(a).value("listener")?)?.peer.as_mut().ok_or_else(|| Fault::new("no peer"))
}

/// The library's `FakeTransport` with `Recording`'s deadlines: replies
/// queued up front and handed out a line at a time; everything written kept.
#[derive(Default)]
pub struct Fake {
    written: Vec<u8>,
    connected: bool,
    closed: bool,
    handshakes: u64,
    pending: Vec<u8>,
    tls: bool,
    fail_handshake: bool,
    greedy: bool,
    after_handshake: Vec<String>,
    fail_writing: Option<Vec<u8>>,
    chunk: usize,
    waits: Vec<(&'static str, f64)>,
}

impl Fake {
    fn reply(&mut self, lines: &[String]) {
        for line in lines {
            self.pending.extend_from_slice(line.as_bytes());
            self.pending.extend_from_slice(b"\r\n");
        }
    }

    fn commands(&self) -> Vec<Value> {
        split(&self.written, b"\r\n").filter(|l| !l.is_empty()).map(bytes_value).collect()
    }
}

impl Transport for Fake {
    async fn connect(&mut self, timeout: Duration, tls: bool) -> Result<(), Error> {
        self.waits.push(("connect", timeout.as_secs_f64()));
        self.connected = true;
        self.closed = false;
        self.tls = tls;
        Ok(())
    }

    async fn read(&mut self, buffer: &mut Vec<u8>, length: usize, timeout: Duration) -> Result<usize, Error> {
        self.waits.push(("read", timeout.as_secs_f64()));
        if self.closed {
            return Err(Error::Logic("The transport is not connected".into()));
        }
        if self.pending.is_empty() {
            return Err(Error::Connection("The server closed the connection".into()));
        }
        let end = find(&self.pending, b"\r\n");
        let take = match end {
            Some(end) if !self.greedy => length.min(end + 2),
            _ => length,
        };
        let take = take.min(self.chunk).min(self.pending.len());
        buffer.extend(self.pending.drain(..take));
        Ok(take)
    }

    async fn write(&mut self, data: &[u8], timeout: Duration) -> Result<(), Error> {
        self.waits.push(("write", timeout.as_secs_f64()));
        if self.closed {
            return Err(Error::Logic("The transport is not connected".into()));
        }
        if let Some(fail) = &self.fail_writing
            && find(data, fail).is_some()
        {
            return Err(Error::Connection("The connection dropped mid-write".into()));
        }
        self.written.extend_from_slice(data);
        Ok(())
    }

    async fn start_tls(&mut self, _timeout: Duration) -> Result<(), Error> {
        if self.fail_handshake {
            return Err(Error::Connection("Handshake failed".into()));
        }
        self.handshakes += 1;
        self.tls = true;
        let lines = self.after_handshake.clone();
        self.reply(&lines);
        Ok(())
    }

    fn is_tls(&self) -> bool {
        self.tls
    }

    fn close(&mut self) {
        self.closed = true;
    }
}

/// The scripted transport or the real one, so one client type serves both.
pub enum AnyTransport {
    Fake(Fake),
    Tcp(TcpTransport),
}

impl Transport for AnyTransport {
    async fn connect(&mut self, timeout: Duration, tls: bool) -> Result<(), Error> {
        match self {
            AnyTransport::Fake(t) => t.connect(timeout, tls).await,
            AnyTransport::Tcp(t) => t.connect(timeout, tls).await,
        }
    }

    async fn read(&mut self, buffer: &mut Vec<u8>, length: usize, timeout: Duration) -> Result<usize, Error> {
        match self {
            AnyTransport::Fake(t) => t.read(buffer, length, timeout).await,
            AnyTransport::Tcp(t) => t.read(buffer, length, timeout).await,
        }
    }

    async fn write(&mut self, data: &[u8], timeout: Duration) -> Result<(), Error> {
        match self {
            AnyTransport::Fake(t) => t.write(data, timeout).await,
            AnyTransport::Tcp(t) => t.write(data, timeout).await,
        }
    }

    async fn start_tls(&mut self, timeout: Duration) -> Result<(), Error> {
        match self {
            AnyTransport::Fake(t) => t.start_tls(timeout).await,
            AnyTransport::Tcp(t) => t.start_tls(timeout).await,
        }
    }

    fn is_tls(&self) -> bool {
        match self {
            AnyTransport::Fake(t) => t.is_tls(),
            AnyTransport::Tcp(t) => t.is_tls(),
        }
    }

    fn close(&mut self) {
        match self {
            AnyTransport::Fake(t) => t.close(),
            AnyTransport::Tcp(t) => t.close(),
        }
    }
}

fn api(a: &Value) -> &str {
    a.get("api").and_then(Value::as_str).unwrap_or("smtp-api")
}

/// One HTTP/1.0 request to Mailpit's API; the body of the answer.
async fn http(s: &Session, api: &str, method: &str, path: &str) -> Result<Vec<u8>, Fault> {
    let base = s.service(api)?;
    let host = base.strip_prefix("http://").ok_or_else(|| Fault::new("an API address is http://host:port"))?;
    let fail = |e: std::io::Error| Fault::new(format!("Mailpit did not answer {method} {path}: {e}"));
    let mut stream = TcpStream::connect(host).await.map_err(fail)?;
    let request = format!("{method} {path} HTTP/1.0\r\nHost: {host}\r\nConnection: close\r\n\r\n");
    stream.write_all(request.as_bytes()).await.map_err(fail)?;
    let mut response = Vec::new();
    stream.read_to_end(&mut response).await.map_err(fail)?;
    let at = find(&response, b"\r\n\r\n").ok_or_else(|| Fault::new("Mailpit sent no headers"))?;
    Ok(response[at + 4..].to_vec())
}

async fn messages(s: &Session, api: &str) -> Result<Vec<Value>, Fault> {
    let body = http(s, api, "GET", "/api/v1/messages").await?;
    let v: Value = serde_json::from_slice(&body).map_err(|e| Fault::new(e.to_string()))?;
    v.get("messages").and_then(Value::as_array).cloned().ok_or_else(|| Fault::new("no message list"))
}

async fn only(s: &Session, api: &str) -> Result<String, Fault> {
    let messages = messages(s, api).await?;
    if messages.len() != 1 {
        return Err(Fault::new(format!("expected exactly one delivered message, got {}", messages.len())));
    }
    Ok(messages[0]["ID"].as_str().unwrap_or("").to_owned())
}
