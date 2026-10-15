//! Compat adapter for `dns`: maps `tests/compat/dns/spec.json` operations onto `utopia-dns`.
//!
//! Messages, records and zones cross as JSON objects, as in
//! `tests/compat/dns/Adapter.php`. `server.run` starts servers on the Network
//! adapter on local ports, with the resolvers of
//! `tests/compat/dns/fixtures/Fixture.php` ([`Fixture`]), and runs client
//! steps against them.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use php_std::random::Mt19937;
use serde_json::{Map, Value, json};
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_dns::adapter::{Http, Network, Tcp, Transport, Udp};
use utopia_dns::lookup::{Lookup, Recursive};
use utopia_dns::message::{Header, Message, Question, RCODE_FORMERR, RCODE_NOERROR, RCODE_NXDOMAIN, Record, domain};
use utopia_dns::resolver::{Memory, Proxy, Resolver};
use utopia_dns::validator::{Caa, Dns, DnsCheck, Name};
use utopia_dns::{Client, Error, Protocol, ProxyProtocol, Query, Server, Zone, zone};
use utopia_telemetry::{Counter, Histogram, Number, ObservableGauge, Telemetry, UpDownCounter};
use utopia_validators::{Input, Validator};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "header.new",
    "header.encode",
    "header.decode",
    "domain.encode",
    "domain.decode",
    "question.new",
    "question.encode",
    "question.decode",
    "record.new",
    "record.encode",
    "record.decode",
    "record.validate",
    "record.with_name",
    "record.type_name_to_code",
    "record.type_code_to_name",
    "message.new",
    "message.query",
    "message.response",
    "message.decode",
    "message.decode_error",
    "message.encode",
    "message.validate",
    "zone.new",
    "zone.is_authoritative",
    "zone.lookup",
    "memory.resolve",
    "query.new",
    "file.import",
    "file.import_error",
    "file.export",
    "proxy.parse",
    "validator.check",
    "validator.describe",
    "client.new",
    "lookup.new",
    "resolver.new",
    "adapter.new",
    "server.run",
    "peer.serve",
    "peer.run",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("decode", "Decode a response with A, AAAA and TXT answers"),
    ("encode", "Encode that response within 512 bytes"),
    ("lookup", "Answer an A query from a zone"),
    ("lookup_wildcard", "Answer a query from a zone's wildcard CNAME"),
    ("serve", "Decode a query, answer it from a zone and encode the response"),
    ("name", "Validate a record name"),
];

const PACKET: &str = "9w6BgAABAAQAAAAAA3d3dwdleGFtcGxlA2NvbQAAAQABwAwAAQABAAABLAAEXbjYIsAMAAEAAQAAASwABF242CPADAAcAAEAAAEsABAgAQ24AAAAAAAAAAAAAAABwAwAEAABAAABLAAMC2hlbGxvIHdvcmxk";

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use base64::Engine as _;

    use crate::bench::Bench;

    let packet = base64::engine::general_purpose::STANDARD.decode(PACKET).map_err(|e| Fault::new(e.to_string()))?;
    let zone = zone_from(&json!({
        "name": "example.com",
        "records": [
            {"name": "www.example.com", "type": 1, "ttl": 300, "rdata": "93.184.216.34"},
            {"name": "www.example.com", "type": 28, "ttl": 300, "rdata": "2001:db8::1"},
            {"name": "*.apps.example.com", "type": 5, "ttl": 300, "rdata": "edge.example.net"},
            {"name": "example.com", "type": 15, "ttl": 300, "rdata": "mail.example.com", "priority": 10},
        ],
        "soa": {"name": "example.com", "type": 6, "ttl": 300, "rdata": "ns1.example.com hostmaster.example.com 1 7200 3600 1209600 300"},
    }))?
    .map_err(|e| Fault::new(e.to_string()))?;
    let query = Message::query(Question::new("www.example.com", Record::TYPE_A), Some(7), true);
    let wildcard = Message::query(Question::new("site.apps.example.com", Record::TYPE_A), Some(8), true);
    let message = Message::decode(&packet).map_err(|e| Fault::new(e.to_string()))?;
    Ok(match name {
        "decode" => Bench::sync(move || {
            let _ = black_box(Message::decode(black_box(&packet)));
        }),
        "encode" => Bench::sync(move || {
            let _ = black_box(message.encode(Some(512)));
        }),
        "lookup" => {
            let mut rng = Mt19937::new(1);
            Bench::sync(move || {
                let _ = black_box(zone::lookup_with(black_box(&query), &zone, &mut rng));
            })
        }
        "lookup_wildcard" => {
            let mut rng = Mt19937::new(1);
            Bench::sync(move || {
                let _ = black_box(zone::lookup_with(black_box(&wildcard), &zone, &mut rng));
            })
        }
        "serve" => {
            let mut rng = Mt19937::new(1);
            Bench::sync(move || {
                let Ok(wire) = query.encode(None) else { return };
                let Ok(decoded) = Message::decode(black_box(&wire)) else { return };
                if let Ok(response) = zone::lookup_with(&decoded, &zone, &mut rng) {
                    let _ = black_box(response.encode(Some(512)));
                }
            })
        }
        "name" => {
            let validator = Name::new(Some(Record::TYPE_CNAME));
            let value = Value::String("_dmarc.mail.example.com".to_owned());
            Bench::sync(move || {
                black_box(validator.is_valid(black_box(&value)));
            })
        }
        _ => return Err(Fault::new(format!("dns: no benchmark `{name}`"))),
    })
}

fn err(e: &Error) -> Outcome {
    Outcome::err_bytes(e.php_class(), e.message())
}

fn error_value(e: &Error) -> Value {
    json!({"$error": {"class": e.php_class(), "message": bytes_value(e.message())}})
}

fn get<'a>(v: &'a Value, key: &str) -> Option<&'a Value> {
    v.get(key).filter(|v| !v.is_null())
}

fn int(v: &Value, key: &str, default: i64) -> Result<i64, Fault> {
    match get(v, key) {
        None => Ok(default),
        Some(n) => n.as_i64().ok_or_else(|| Fault::new(format!("`{key}` must be an integer"))),
    }
}

fn flag(v: &Value, key: &str) -> bool {
    get(v, key).and_then(Value::as_bool).unwrap_or(false)
}

fn u16_field(v: &Value, key: &str, default: i64) -> Result<u16, Fault> {
    let n = int(v, key, default)?;
    u16::try_from(n).map_err(|_| Fault::new(format!("`{key}` outside u16 (a recorded deviation)")))
}

fn text(v: &Value, key: &str) -> Result<Vec<u8>, Fault> {
    match get(v, key) {
        None => Ok(Vec::new()),
        Some(s) => bytes(s).ok_or_else(|| Fault::new(format!("`{key}` must be a string"))),
    }
}

fn header_from(h: &Value) -> Result<Header, Fault> {
    let small = |key: &str| -> Result<u8, Fault> {
        let n = int(h, key, 0)?;
        u8::try_from(n).map_err(|_| Fault::new(format!("`{key}` outside u8 (a recorded deviation)")))
    };
    Ok(Header {
        id: u16_field(h, "id", 0)?,
        is_response: flag(h, "isResponse"),
        opcode: small("opcode")?,
        authoritative: flag(h, "authoritative"),
        truncated: flag(h, "truncated"),
        recursion_desired: flag(h, "recursionDesired"),
        recursion_available: flag(h, "recursionAvailable"),
        response_code: small("responseCode")?,
        question_count: u16_field(h, "questionCount", 0)?,
        answer_count: u16_field(h, "answerCount", 0)?,
        authority_count: u16_field(h, "authorityCount", 0)?,
        additional_count: u16_field(h, "additionalCount", 0)?,
    })
}

fn question_from(q: &Value) -> Result<Question, Fault> {
    Ok(Question::with_class(text(q, "name")?, u16_field(q, "type", 0)?, u16_field(q, "class", 1)?))
}

fn record_from(r: &Value) -> Result<Record, Fault> {
    let mut record = Record::new(text(r, "name")?, u16_field(r, "type", 0)?)
        .with_class(u16_field(r, "class", 1)?)
        .with_ttl(int(r, "ttl", 0)?)
        .with_rdata(text(r, "rdata")?);
    if get(r, "priority").is_some() {
        record = record.with_priority(int(r, "priority", 0)?);
    }
    if get(r, "weight").is_some() {
        record = record.with_weight(int(r, "weight", 0)?);
    }
    if get(r, "port").is_some() {
        record = record.with_port(int(r, "port", 0)?);
    }
    Ok(record)
}

fn records_from(v: &Value, key: &str) -> Result<Vec<Record>, Fault> {
    get(v, key).and_then(Value::as_array).map_or(Ok(Vec::new()), |list| list.iter().map(record_from).collect())
}

fn questions_from(v: &Value, key: &str) -> Result<Vec<Question>, Fault> {
    get(v, key).and_then(Value::as_array).map_or(Ok(Vec::new()), |list| list.iter().map(question_from).collect())
}

/// A message built with `Message::new` (whose errors are the library's).
fn message_from(m: &Value) -> Result<Result<Message, Error>, Fault> {
    let header = header_from(get(m, "header").ok_or_else(|| Fault::new("a message needs a header"))?)?;
    Ok(Message::new(
        header,
        questions_from(m, "questions")?,
        records_from(m, "answers")?,
        records_from(m, "authority")?,
        records_from(m, "additional")?,
    ))
}

fn zone_from(z: &Value) -> Result<Result<Zone, Error>, Fault> {
    let soa = record_from(get(z, "soa").ok_or_else(|| Fault::new("a zone needs a soa"))?)?;
    Ok(Zone::new(text(z, "name")?, records_from(z, "records")?, soa))
}

fn dump_header(h: &Header) -> Value {
    json!({
        "id": h.id,
        "isResponse": h.is_response,
        "opcode": h.opcode,
        "authoritative": h.authoritative,
        "truncated": h.truncated,
        "recursionDesired": h.recursion_desired,
        "recursionAvailable": h.recursion_available,
        "responseCode": h.response_code,
        "questionCount": h.question_count,
        "answerCount": h.answer_count,
        "authorityCount": h.authority_count,
        "additionalCount": h.additional_count,
    })
}

fn dump_question(q: &Question) -> Value {
    json!({"name": bytes_value(q.name()), "type": q.ty, "class": q.class})
}

fn dump_record(r: &Record) -> Value {
    json!({
        "name": bytes_value(r.name()),
        "type": r.ty(),
        "class": r.class(),
        "ttl": r.ttl(),
        "rdata": bytes_value(r.rdata()),
        "priority": r.priority(),
        "weight": r.weight(),
        "port": r.port(),
    })
}

fn dump_message(m: &Message) -> Value {
    json!({
        "header": dump_header(m.header()),
        "questions": m.questions().iter().map(dump_question).collect::<Vec<_>>(),
        "answers": m.answers().iter().map(dump_record).collect::<Vec<_>>(),
        "authority": m.authority().iter().map(dump_record).collect::<Vec<_>>(),
        "additional": m.additional().iter().map(dump_record).collect::<Vec<_>>(),
    })
}

fn dump_zone(z: &Zone) -> Value {
    json!({
        "name": bytes_value(z.name()),
        "records": z.records().iter().map(dump_record).collect::<Vec<_>>(),
        "soa": dump_record(z.soa()),
    })
}

fn outcome(r: Result<Value, Error>) -> Outcome {
    match r {
        Ok(v) => Outcome::ok(v),
        Err(e) => err(&e),
    }
}

fn protocol(name: &str) -> Result<Protocol, Fault> {
    match name {
        "udp" => Ok(Protocol::Udp),
        "tcp" => Ok(Protocol::Tcp),
        "https" => Ok(Protocol::Https),
        other => Err(Fault::new(format!("unknown protocol `{other}` (a recorded deviation)"))),
    }
}

/// The description a stateful PHP validator reports after each value.
fn check_values(validator: &dyn Validator, values: &[Value]) -> Value {
    let mut description = validator.description();
    let mut out = Vec::with_capacity(values.len());
    for value in values {
        let verdict = validator.validate(Input::Json(value));
        let valid = verdict.as_ref().is_ok_and(|v| v.valid);
        if let Ok(v) = &verdict
            && v.decided
        {
            description = v.describe(validator);
        }
        out.push(json!({"valid": valid, "description": description}));
    }
    Value::Array(out)
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "header.new" => {
            let header = header_from(a.value("header")?)?;
            outcome(header.validate().map(|()| dump_header(&header)))
        }
        "header.encode" => {
            let header = header_from(a.value("header")?)?;
            outcome(header.validate().map(|()| bytes_value(&header.encode())))
        }
        "header.decode" => outcome(
            Header::decode(&a.bytes("data")?, a.opt_i64("offset")?.unwrap_or(0) as usize).map(|h| dump_header(&h)),
        ),
        "domain.encode" => outcome(domain::encode(&a.bytes("name")?).map(|b| bytes_value(&b))),
        "domain.decode" => outcome(
            domain::decode(&a.bytes("data")?, a.opt_i64("offset")?.unwrap_or(0) as usize)
                .map(|(name, offset)| json!({"name": bytes_value(&name), "offset": offset})),
        ),
        "question.new" => Outcome::ok(dump_question(&question_from(a.value("question")?)?)),
        "question.encode" => outcome(question_from(a.value("question")?)?.encode().map(|b| bytes_value(&b))),
        "question.decode" => outcome(
            Question::decode(&a.bytes("data")?, a.opt_i64("offset")?.unwrap_or(0) as usize)
                .map(|(q, offset)| json!({"question": dump_question(&q), "offset": offset})),
        ),
        "record.new" => Outcome::ok(dump_record(&record_from(a.value("record")?)?)),
        "record.encode" => outcome(record_from(a.value("record")?)?.encode().map(|b| bytes_value(&b))),
        "record.decode" => outcome(
            Record::decode(&a.bytes("data")?, a.opt_i64("offset")?.unwrap_or(0) as usize)
                .map(|(r, offset)| json!({"record": dump_record(&r), "offset": offset})),
        ),
        "record.validate" => outcome(record_from(a.value("record")?)?.validate().map(|()| Value::Null)),
        "record.with_name" => Outcome::ok(dump_record(&record_from(a.value("record")?)?.with_name(a.bytes("name")?))),
        "record.type_name_to_code" => Outcome::ok(json!(Record::type_name_to_code(a.str("name")?))),
        "record.type_code_to_name" => {
            let code = a.i64("code")?;
            Outcome::ok(json!(u16::try_from(code).ok().and_then(Record::type_code_to_name)))
        }
        "message.new" => outcome(message_from(a.value("message")?)?.map(|m| dump_message(&m))),
        "message.query" => {
            let id =
                a.opt_i64("id")?.map(|id| u16::try_from(id).map_err(|_| Fault::new("id outside u16"))).transpose()?;
            let rd = a.opt_bool("recursionDesired")?.unwrap_or(true);
            Outcome::ok(dump_message(&Message::query(question_from(a.value("question")?)?, id, rd)))
        }
        "message.response" => {
            let header = header_from(a.value("header")?)?;
            let code = a.i64("responseCode")?;
            let code = u8::try_from(code).map_err(|_| Fault::new("response code outside u8 (a recorded deviation)"))?;
            let r = match header.validate() {
                Err(e) => Err(e),
                Ok(()) => Message::response(&header, code)
                    .questions(questions_from(args, "questions")?)
                    .answers(records_from(args, "answers")?)
                    .authority(records_from(args, "authority")?)
                    .additional(records_from(args, "additional")?)
                    .authoritative(a.opt_bool("authoritative")?.unwrap_or(false))
                    .truncated(a.opt_bool("truncated")?.unwrap_or(false))
                    .recursion_available(a.opt_bool("recursionAvailable")?.unwrap_or(false))
                    .build(),
            };
            outcome(r.map(|m| dump_message(&m)))
        }
        "message.decode" => outcome(Message::decode(&a.bytes("packet")?).map(|m| dump_message(&m))),
        "message.decode_error" => match Message::decode(&a.bytes("packet")?) {
            Ok(_) => Outcome::ok(Value::Null),
            Err(e) if e.is_decoding() => {
                let (code, header) = match &e {
                    Error::PartialDecoding { header, .. } => (1, dump_header(header)),
                    _ => (0, Value::Null),
                };
                Outcome::ok(json!({
                    "class": e.php_class(),
                    "message": bytes_value(e.message()),
                    "code": code,
                    "header": header,
                }))
            }
            Err(e) => err(&e),
        },
        "message.encode" => {
            let max = a.opt_i64("maxSize")?.map(|m| m.max(0) as usize);
            outcome(message_from(a.value("message")?)?.and_then(|m| m.encode(max)).map(|b| bytes_value(&b)))
        }
        "message.validate" => {
            outcome(message_from(a.value("message")?)?.and_then(|m| m.validate()).map(|()| Value::Null))
        }

        "zone.new" => outcome(zone_from(a.value("zone")?)?.map(|z| dump_zone(&z))),
        "zone.is_authoritative" => {
            let name = a.bytes("name")?;
            outcome(zone_from(a.value("zone")?)?.map(|z| json!(z.is_authoritative(&name))))
        }
        "zone.lookup" => {
            let zone = zone_from(a.value("zone")?)?;
            let query = message_from(a.value("query")?)?;
            let seed = a.opt_i64("seed")?.unwrap_or(1);
            outcome(zone.and_then(|zone| {
                let query = query?;
                zone::lookup_with(&query, &zone, &mut Mt19937::new(seed)).map(|m| dump_message(&m))
            }))
        }
        "memory.resolve" => {
            let zone = zone_from(a.value("zone")?)?;
            let message = message_from(a.value("query")?)?;
            let protocol = protocol(a.opt_str("protocol")?.unwrap_or("udp"))?;
            let ip = a.opt_str("ip")?.unwrap_or("127.0.0.1").to_owned();
            let port = u16::try_from(a.opt_i64("port")?.unwrap_or(53)).map_err(|_| Fault::new("port outside u16"))?;
            let seed = a.opt_i64("seed")?.unwrap_or(1);
            match (zone, message) {
                (Err(e), _) | (_, Err(e)) => err(&e),
                (Ok(zone), Ok(message)) => {
                    let resolver = Memory::with_seed(zone, seed);
                    let query = Query { message, ip, port, protocol };
                    outcome(resolver.resolve(&query).await.map(|m| dump_message(&m)))
                }
            }
        }
        "query.new" => match message_from(a.value("query")?)? {
            Err(e) => err(&e),
            Ok(message) => {
                let protocol = protocol(a.str("protocol")?)?;
                let port = u16::try_from(a.i64("port")?).map_err(|_| Fault::new("port outside u16"))?;
                let query = Query { message, ip: a.str("ip")?.to_owned(), port, protocol };
                Outcome::ok(json!({
                    "message": dump_message(&query.message),
                    "ip": query.ip,
                    "port": query.port,
                    "protocol": query.protocol.as_str(),
                    "maxResponseSize": query.protocol.max_response_size(),
                }))
            }
        },

        "file.import" => {
            let origin = a.opt("defaultOrigin").map(|_| a.bytes("defaultOrigin")).transpose()?;
            outcome(
                zone::import(&a.bytes("content")?, origin.as_deref(), a.opt_i64("defaultTTL")?.unwrap_or(3600))
                    .map(|z| dump_zone(&z)),
            )
        }
        "file.import_error" => {
            let origin = a.opt("defaultOrigin").map(|_| a.bytes("defaultOrigin")).transpose()?;
            match zone::import(&a.bytes("content")?, origin.as_deref(), a.opt_i64("defaultTTL")?.unwrap_or(3600)) {
                Ok(_) => Outcome::ok(Value::Null),
                Err(Error::Import { content, message, previous }) => Outcome::ok(json!({
                    "message": bytes_value(&message),
                    "content": bytes_value(&content),
                    "previous": previous.map(|p| json!({"class": p.php_class(), "message": bytes_value(p.message())})),
                })),
                Err(e) => err(&e),
            }
        }
        "file.export" => match zone_from(a.value("zone")?)? {
            Err(e) => err(&e),
            Ok(zone) => {
                let file = zone::export(&zone, a.opt_bool("includeComments")?.unwrap_or(true));
                Outcome::ok(Value::Array(file.split(|&b| b == b'\n').map(bytes_value).collect()))
            }
        },

        "proxy.parse" => outcome(ProxyProtocol::parse(&a.bytes("buffer")?).map(|h| match h {
            None => Value::Null,
            Some(h) => {
                let (ip, port) = h.source.map_or((Value::Null, Value::Null), |(ip, port)| (json!(ip), json!(port)));
                json!({"length": h.length, "ip": ip, "port": port})
            }
        })),

        "validator.check" => {
            let values = a.array("values")?;
            match a.str("validator")? {
                "name" => {
                    let ty = a
                        .opt_i64("recordType")?
                        .map(|t| u16::try_from(t).map_err(|_| Fault::new("type outside u16")))
                        .transpose()?;
                    Outcome::ok(check_values(&Name::new(ty), values))
                }
                "caa" => Outcome::ok(check_values(&Caa, values)),
                other => return Err(Fault::new(format!("unknown validator `{other}`"))),
            }
        }
        "validator.describe" => match a.str("validator")? {
            "name" => {
                let ty = a.opt_i64("recordType")?.map(|t| t as u16);
                let v = Name::new(ty);
                Outcome::ok(json!({"description": v.description(), "type": v.kind().as_str(), "array": v.is_array()}))
            }
            "caa" => Outcome::ok(
                json!({"description": Caa.description(), "type": Caa.kind().as_str(), "array": Caa.is_array()}),
            ),
            "dns" => {
                let v = Dns::new(
                    a.opt_str("target")?.unwrap_or(""),
                    u16::try_from(a.opt_i64("type")?.unwrap_or(5)).map_err(|_| Fault::new("type outside u16"))?,
                    a.opt_str("server")?.unwrap_or(Dns::DEFAULT_DNS_SERVER),
                );
                Outcome::ok(
                    json!({"description": v.describe(&DnsCheck::default()), "type": v.kind().as_str(), "array": v.is_array()}),
                )
            }
            other => return Err(Fault::new(format!("unknown validator `{other}`"))),
        },

        "client.new" => {
            let port = u16::try_from(a.opt_i64("port")?.unwrap_or(53)).map_err(|_| Fault::new("port outside u16"))?;
            let timeout = Duration::from_secs(a.opt_i64("timeout")?.unwrap_or(5).max(0) as u64);
            outcome(
                Client::new(a.str("server")?, port, timeout, a.opt_bool("tcp")?.unwrap_or(false)).map(|_| Value::Null),
            )
        }
        "lookup.new" => {
            let servers: Vec<String> =
                a.array("servers")?.iter().map(|s| s.as_str().unwrap_or_default().to_owned()).collect();
            outcome(Recursive::new(&servers).map(|_| Value::Null))
        }
        "resolver.new" => match a.str("resolver")? {
            "proxy" => {
                let port =
                    u16::try_from(a.opt_i64("port")?.unwrap_or(53)).map_err(|_| Fault::new("port outside u16"))?;
                outcome(Proxy::new(a.str("server")?, port).map(|_| Value::Null))
            }
            "google" => {
                let _ = Proxy::google(a.opt_bool("backup")?.unwrap_or(false));
                Outcome::ok(Value::Null)
            }
            "cloudflare" => {
                let _ = Proxy::cloudflare(a.opt_bool("backup")?.unwrap_or(false));
                Outcome::ok(Value::Null)
            }
            other => return Err(Fault::new(format!("unknown resolver `{other}`"))),
        },
        "adapter.new" => match a.str("adapter")? {
            "native" | "swoole" => {
                let count = a.opt("transports").and_then(Value::as_array).map_or(0, Vec::len);
                let transports = (0..count).map(|_| Transport::Udp(Udp::new("127.0.0.1", 0))).collect();
                outcome(Network::new(transports).map(|_| Value::Null))
            }
            "http" => {
                let cert = a.opt_str("cert")?.map(std::path::PathBuf::from);
                let key = a.opt_str("key")?.map(std::path::PathBuf::from);
                outcome(Http::new("127.0.0.1", 8443, cert, key, false).map(|_| Value::Null))
            }
            other => return Err(Fault::new(format!("unknown adapter `{other}`"))),
        },

        "server.run" => Outcome::ok(run(a.array("servers")?, a.array("steps")?).await?),
        // Interop: a server PHP's client reaches (bound on `dns-rust-server`),
        // kept until the session resets; and steps against the server of the
        // runtime `peer.serve` named, at this runtime's address for it.
        "peer.serve" => {
            let (host, port) = endpoint(session.service("dns-rust-server")?).await?;
            let spec = json!({"adapter": "native", "transports": ["udp", "tcp", "http", "proxy"]});
            let server = start(&spec, &[], &host, Some(port)).await?;
            let _ = session.handle(server);
            Outcome::ok(json!({"runtime": "rust"}))
        }
        "peer.run" => {
            let runtime = a.value("peer")?.get("runtime").and_then(Value::as_str).unwrap_or("php");
            let (host, port) = endpoint(session.service(&format!("dns-{runtime}-server"))?).await?;
            let mut ports = Map::new();
            for (kind, p) in [("udp", port), ("tcp", port), ("http", port + 1), ("proxy", port + 2)] {
                ports.insert(kind.to_owned(), json!(p));
            }
            let server = Running { host, ports, log: Arc::default(), task: None };
            let mut out = Vec::new();
            for step in a.array("steps")? {
                out.push(self::step(step, std::slice::from_ref(&server)).await?);
            }
            Outcome::ok(Value::Array(out))
        }
        _ => return Err(Fault::new(format!("dns: unknown operation `{op}`"))),
    })
}

// ---------------------------------------------------------------------------
// Fixture servers
// ---------------------------------------------------------------------------

/// The resolvers of `tests/compat/dns/fixtures/Fixture.php`.
enum Fixture {
    Zones { zones: Vec<Zone>, rng: Mutex<Mt19937> },
    Zone(Memory),
    Rules(Map<String, Value>),
    Proxy(Proxy),
    Throw,
    Invalid,
}

impl Resolver for Fixture {
    async fn resolve(&self, query: &Query) -> Result<Message, Error> {
        match self {
            Fixture::Zones { zones, rng } => {
                let message = &query.message;
                let Some(question) = message.questions().first() else {
                    return Message::response(message.header(), RCODE_FORMERR).authoritative(true).build();
                };
                let name = question.name().to_ascii_lowercase();
                for zone in zones {
                    let zone_name = zone.name();
                    if name == zone_name
                        || (name.len() > zone_name.len()
                            && name.ends_with(zone_name)
                            && name[name.len() - zone_name.len() - 1] == b'.')
                    {
                        let mut rng = rng.lock().unwrap_or_else(|e| e.into_inner());
                        return zone::lookup_with(message, zone, &mut rng);
                    }
                }
                Message::response(message.header(), RCODE_NXDOMAIN)
                    .questions(message.questions().to_vec())
                    .authority(vec![zones[0].soa().clone()])
                    .authoritative(true)
                    .build()
            }
            Fixture::Zone(memory) => memory.resolve(query).await,
            Fixture::Rules(rules) => {
                let question = &query.message.questions()[0];
                let rule = rules
                    .get(&question.ty.to_string())
                    .or_else(|| rules.get("*"))
                    .cloned()
                    .unwrap_or_else(|| json!({"rcode": 0}));
                let rcode = rule.get("rcode").and_then(Value::as_i64).unwrap_or(0) as u8;
                let answers = match rule.get("answer").and_then(Value::as_str) {
                    Some(rdata) => vec![Record::new(question.name(), question.ty).with_ttl(60).with_rdata(rdata)],
                    None => Vec::new(),
                };
                Message::response(query.message.header(), rcode)
                    .questions(query.message.questions().to_vec())
                    .answers(answers)
                    .build()
            }
            Fixture::Proxy(proxy) => proxy.resolve(query).await,
            Fixture::Throw => {
                Err(Error::Exception(format!("Resolver failed for {}", query.protocol.as_str()).into_bytes()))
            }
            Fixture::Invalid => Message::response(query.message.header(), RCODE_NOERROR)
                .questions(query.message.questions().to_vec())
                .answers(vec![Record::new("invalid.test", Record::TYPE_A).with_ttl(60).with_rdata("not-an-ip")])
                .build(),
        }
    }
}

fn fixture_zones() -> Result<Vec<Zone>, Fault> {
    let records = [
        ("dev.appwrite.io", Record::TYPE_A, 10, "180.12.3.24"),
        ("dev2.appwrite.io", Record::TYPE_A, 1800, "142.6.0.1"),
        ("dev2.appwrite.io", Record::TYPE_A, 1800, "142.6.0.2"),
        ("dev.appwrite.io", Record::TYPE_AAAA, 20, "2001:0db8:0000:0000:0000:ff00:0042:8329"),
        ("dev2.appwrite.io", Record::TYPE_AAAA, 20, "2001:0db8:0000:0000:0000:ff00:0000:0001"),
        ("dev2.appwrite.io", Record::TYPE_AAAA, 20, "2001:0db8:0000:0000:0000:ff00:0000:0002"),
        ("alias.appwrite.io", Record::TYPE_CNAME, 30, "cloud.appwrite.io"),
        ("dev.appwrite.io", Record::TYPE_TXT, 30, "awesome-secret-key"),
        ("dev.appwrite.io", Record::TYPE_MX, 30, "10 mail.appwrite.io"),
        ("dev.appwrite.io", Record::TYPE_CAA, 30, "0 issue \"letsencrypt.org\""),
        ("delegated.appwrite.io", Record::TYPE_NS, 30, "ns1.test.io"),
        ("delegated.appwrite.io", Record::TYPE_NS, 30, "ns2.test.io"),
    ]
    .into_iter()
    .map(|(name, ty, ttl, rdata)| Record::new(name, ty).with_ttl(ttl).with_rdata(rdata))
    .collect();
    let soa = Record::new("appwrite.io", Record::TYPE_SOA)
        .with_ttl(30)
        .with_rdata("ns1.appwrite.zone team.appwrite.io 1 7200 1800 1209600 3600");
    let appwrite = Zone::new("appwrite.io", records, soa).map_err(|e| Fault::new(e.to_string()))?;
    let file = std::fs::read("packages/dns/tests/resources/zone-valid-localhost.txt")
        .map_err(|e| Fault::new(e.to_string()))?;
    let localhost = zone::import(&file, None, 3600).map_err(|e| Fault::new(e.to_string()))?;
    Ok(vec![appwrite, localhost])
}

/// What a fixture server reports: errors, worker starts, telemetry.
#[derive(Default)]
struct Log {
    errors: Vec<Value>,
    workers: Vec<Value>,
    telemetry: Vec<Value>,
}

type SharedLog = Arc<Mutex<Log>>;

fn attributes(attributes: utopia_telemetry::Attributes<'_>) -> Value {
    if attributes.is_empty() {
        return json!([]);
    }
    Value::Object(
        attributes
            .iter()
            .map(|(k, v)| {
                let v = match v {
                    utopia_telemetry::Value::Str(s) => json!(s),
                    utopia_telemetry::Value::Int(i) => json!(i),
                    utopia_telemetry::Value::Float(f) => float_value(*f),
                    utopia_telemetry::Value::Bool(b) => json!(b),
                };
                ((*k).to_owned(), v)
            })
            .collect(),
    )
}

fn number(n: Number) -> Value {
    match n {
        Number::Int(i) => json!(i),
        Number::Float(f) => float_value(f),
    }
}

/// Telemetry that logs every measurement, as the PHP fixture's adapter does.
struct Recording(SharedLog);

struct Recorder {
    log: SharedLog,
    entry: Vec<Value>,
}

fn lock(log: &SharedLog) -> std::sync::MutexGuard<'_, Log> {
    log.lock().unwrap_or_else(|e| e.into_inner())
}

impl utopia_telemetry::Record for Recorder {
    fn record(&self, value: Number, attrs: utopia_telemetry::Attributes<'_>) {
        let mut entry = self.entry.clone();
        if entry.first().and_then(Value::as_str) == Some("counter") {
            entry.push(number(value));
        }
        entry.push(attributes(attrs));
        lock(&self.log).telemetry.push(Value::Array(entry));
    }
}

impl Recording {
    fn recorder(&self, entry: Vec<Value>) -> Arc<dyn utopia_telemetry::Record> {
        Arc::new(Recorder { log: self.0.clone(), entry })
    }
}

impl Telemetry for Recording {
    fn counter(&self, name: &str, _unit: Option<&str>, _description: Option<&str>) -> Counter {
        Counter::new(self.recorder(vec![json!("counter"), json!(name)]))
    }

    fn up_down_counter(&self, name: &str, _unit: Option<&str>, _description: Option<&str>) -> UpDownCounter {
        UpDownCounter::new(self.recorder(vec![json!("upDownCounter"), json!(name)]))
    }

    fn histogram(
        &self,
        name: &str,
        unit: Option<&str>,
        _description: Option<&str>,
        boundaries: Option<&[f64]>,
    ) -> Histogram {
        let boundaries: Vec<Value> = boundaries.unwrap_or_default().iter().map(|b| float_value(*b)).collect();
        Histogram::new(self.recorder(vec![json!("histogram"), json!(name), json!(unit), Value::Array(boundaries)]))
    }

    fn gauge(&self, name: &str, _unit: Option<&str>, _description: Option<&str>) -> utopia_telemetry::Gauge {
        utopia_telemetry::Gauge::new(self.recorder(vec![json!("gauge"), json!(name)]))
    }

    fn observable_gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> ObservableGauge {
        utopia_telemetry::NoTelemetry.observable_gauge(name, unit, description)
    }

    fn collect(&self) -> utopia_telemetry::BoxFuture<'_, bool> {
        Box::pin(async { true })
    }
}

/// A running fixture server: its address, its transports' ports, its log, its task.
struct Running {
    host: String,
    ports: Map<String, Value>,
    log: SharedLog,
    task: Option<utopia_async::JoinHandle<()>>,
}

impl Drop for Running {
    fn drop(&mut self) {
        if let Some(task) = &self.task {
            task.abort();
        }
    }
}

impl Running {
    fn port(&self, kind: &str) -> Result<u16, Fault> {
        self.ports
            .get(kind)
            .and_then(Value::as_u64)
            .map(|p| p as u16)
            .ok_or_else(|| Fault::new(format!("the server has no {kind} transport")))
    }
}

/// Starts a fixture server on `host`, on free ports or, with `base`, UDP
/// and TCP on `base`, HTTP on `base + 1` and PROXY-protocol TCP on `base + 2`.
async fn start(spec: &Value, running: &[Running], host: &str, base: Option<u16>) -> Result<Running, Fault> {
    let fault = |e: Error| Fault::new(e.to_string());
    let kinds: Vec<String> = match spec.get("transports").and_then(Value::as_array) {
        Some(list) => list.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
        None => vec!["udp".to_owned(), "tcp".to_owned()],
    };
    let options = spec.get("tcp").cloned().unwrap_or(Value::Null);
    let mut transports = Vec::new();
    for kind in &kinds {
        transports.push(match kind.as_str() {
            "udp" => Transport::Udp(Udp::new(host, base.unwrap_or(0))),
            "tcp" | "proxy" => {
                let port = base.map_or(0, |b| if kind == "proxy" { b + 2 } else { b });
                let mut tcp = Tcp::new(host, port);
                tcp.proxy_protocol = kind == "proxy";
                tcp.max_clients = int(&options, "maxClients", 100)? as usize;
                tcp.max_buffer_size = int(&options, "maxBufferSize", 16384)? as usize;
                tcp.max_frame_size = int(&options, "maxFrameSize", 65535)? as usize;
                tcp.idle_timeout = Duration::from_secs(int(&options, "idleTimeout", 30)?.max(0) as u64);
                Transport::Tcp(tcp)
            }
            "http" => Transport::Http(
                Http::new(host, base.map_or(0, |b| b + 1), None, None, flag(spec, "trustProxy")).map_err(fault)?,
            ),
            other => return Err(Fault::new(format!("unknown transport `{other}`"))),
        });
    }
    let resolver_spec = spec.get("resolver").cloned().unwrap_or_else(|| json!({"kind": "fixture"}));
    let seed = int(spec, "seed", 1)?;
    let resolver = match resolver_spec.get("kind").and_then(Value::as_str).unwrap_or("fixture") {
        "fixture" => Fixture::Zones { zones: fixture_zones()?, rng: Mutex::new(Mt19937::new(seed)) },
        "zone" => {
            let zone = zone_from(resolver_spec.get("zone").unwrap_or(&Value::Null))?.map_err(fault)?;
            Fixture::Zone(Memory::with_seed(zone, seed))
        }
        "rules" => Fixture::Rules(resolver_spec.get("rules").and_then(Value::as_object).cloned().unwrap_or_default()),
        "proxy" => {
            let upstream =
                spec.get("upstream").and_then(Value::as_u64).ok_or_else(|| Fault::new("a proxy needs an upstream"))?;
            let port = running.get(upstream as usize).ok_or_else(|| Fault::new("unknown upstream"))?.port("udp")?;
            Fixture::Proxy(Proxy::new("127.0.0.1", port).map_err(fault)?)
        }
        "throw" => Fixture::Throw,
        "invalid" => Fixture::Invalid,
        other => return Err(Fault::new(format!("unknown resolver `{other}`"))),
    };
    let log: SharedLog = Arc::default();
    let mut server = Server::new(resolver).set_debug(false);
    if flag(spec, "telemetry") {
        server.set_telemetry(&Recording(log.clone()));
    }
    let errors = log.clone();
    let server = server.error(move |e| {
        lock(&errors).errors.push(json!({"class": e.php_class(), "message": bytes_value(e.message())}));
    });
    let mut adapter = Network::new(transports).map_err(fault)?;
    let workers = log.clone();
    utopia_dns::adapter::Adapter::on_worker_start(
        &mut adapter,
        Box::new(move |id| lock(&workers).workers.push(json!(id))),
    );
    let bound = adapter.bind().await.map_err(fault)?;
    let mut ports = Map::new();
    for (kind, (_, address)) in kinds.iter().zip(bound.local_addrs()) {
        ports.insert(kind.clone(), json!(address.port()));
    }
    let task = utopia_async::spawn(bound.serve(Arc::new(server)));
    Ok(Running { host: "127.0.0.1".to_owned(), ports, log, task: Some(task) })
}

async fn run(specs: &[Value], steps: &[Value]) -> Result<Value, Fault> {
    let mut running: Vec<Running> = Vec::new();
    for spec in specs {
        let server = start(spec, &running, "127.0.0.1", None).await?;
        running.push(server);
    }
    let mut out = Vec::with_capacity(steps.len());
    let mut result = Ok(());
    for step in steps {
        match self::step(step, &running).await {
            Ok(value) => out.push(value),
            Err(fault) => {
                result = Err(fault);
                break;
            }
        }
    }
    drop(running);
    result.map(|()| Value::Array(out))
}

fn lift(r: Result<Value, Error>) -> Value {
    r.unwrap_or_else(|e| error_value(&e))
}

async fn step(step: &Value, running: &[Running]) -> Result<Value, Fault> {
    let server = step.get("server").and_then(Value::as_u64).map_or(0, |i| i as usize);
    let server = running.get(server);
    let host = server.map_or("127.0.0.1", |s| s.host.as_str());
    let port = |kind: &str| -> Result<u16, Fault> { server.ok_or_else(|| Fault::new("no such server"))?.port(kind) };
    let kind = step.get("kind").and_then(Value::as_str).unwrap_or_default();
    Ok(match kind {
        "query" => {
            let tcp = step.get("transport").and_then(Value::as_str) == Some("tcp");
            let timeout = Duration::from_secs(int(step, "timeout", 2)?.max(0) as u64);
            let client = Client::new(host, port(if tcp { "tcp" } else { "udp" })?, timeout, tcp)
                .map_err(|e| Fault::new(e.to_string()))?;
            let question =
                Question::with_class(text(step, "name")?, u16_field(step, "type", 1)?, u16_field(step, "class", 1)?);
            let id = u16_field(step, "id", 1)?;
            let rd = step.get("recursionDesired").and_then(Value::as_bool).unwrap_or(true);
            lift(client.query(&Message::query(question, Some(id), rd)).await.map(|m| dump_message(&m)))
        }
        "raw" => raw(step, host, &port).await?,
        "doh" => doh(step, host, port("http")?).await?,
        "recursive" => {
            let mut servers = Vec::new();
            for target in step.get("servers").and_then(Value::as_array).cloned().unwrap_or_default() {
                let address = match target.as_u64() {
                    Some(i) => format!(
                        "127.0.0.1:{}",
                        running.get(i as usize).ok_or_else(|| Fault::new("unknown server"))?.port("tcp")?
                    ),
                    None => format!("127.0.0.1:{}", closed_port().await?),
                };
                servers.push(address);
            }
            let lookup = Recursive::new(&servers).map_err(|e| Fault::new(e.to_string()))?;
            let hostname = String::from_utf8_lossy(&text(step, "hostname")?).into_owned();
            json!(lookup.addresses(&hostname).await)
        }
        "proxy" => {
            let proxy = Proxy::new(host, port("udp")?).map_err(|e| Fault::new(e.to_string()))?;
            let message = Message::query(
                Question::new(text(step, "name")?, u16_field(step, "type", 1)?),
                Some(u16_field(step, "id", 1)?),
                true,
            );
            let query = Query { message, ip: "127.0.0.1".to_owned(), port: 5353, protocol: Protocol::Udp };
            lift(proxy.resolve(&query).await.map(|m| dump_message(&m)))
        }
        "validate" => {
            let validator = Dns::new(
                step.get("target").and_then(Value::as_str).unwrap_or_default(),
                u16_field(step, "type", 5)?,
                "127.0.0.1",
            )
            .with_port(port("udp")?);
            let mut last = DnsCheck::default();
            let mut out = Vec::new();
            for value in step.get("values").and_then(Value::as_array).cloned().unwrap_or_default() {
                let check = match value.as_str() {
                    Some(value) => validator.check(Some(value)).await,
                    None => Ok(last.clone().refuse()),
                };
                match check {
                    Ok(check) => {
                        out.push(json!({
                            "valid": check.valid,
                            "description": validator.describe(&check),
                            "records": check.records,
                            "value": check.value,
                            "count": check.count,
                            "reason": check.reason,
                        }));
                        last = check;
                    }
                    Err(e) => out.push(error_value(&e)),
                }
            }
            Value::Array(out)
        }
        "errors" | "workers" | "telemetry" => {
            utopia_async::sleep(Duration::from_millis(50)).await;
            let server = server.ok_or_else(|| Fault::new("no such server"))?;
            let log = lock(&server.log);
            Value::Array(match kind {
                "errors" => log.errors.clone(),
                "workers" => log.workers.clone(),
                _ => log.telemetry.clone(),
            })
        }
        "sleep" => {
            utopia_async::sleep(Duration::from_millis(int(step, "ms", 0)?.max(0) as u64)).await;
            Value::Null
        }
        other => return Err(Fault::new(format!("unknown step `{other}`"))),
    })
}

/// `host:port`, with the host resolved to an address.
async fn endpoint(service: &str) -> Result<(String, u16), Fault> {
    let (host, port) = service.rsplit_once(':').ok_or_else(|| Fault::new(format!("not host:port: {service}")))?;
    let port: u16 = port.parse().map_err(|_| Fault::new(format!("bad port in {service}")))?;
    let address = utopia_async::net::lookup_host((host, port))
        .await
        .map_err(|e| Fault::new(e.to_string()))?
        .find(std::net::SocketAddr::is_ipv4)
        .ok_or_else(|| Fault::new(format!("cannot resolve {host}")))?;
    Ok((address.ip().to_string(), port))
}

/// A local port nothing listens on.
async fn closed_port() -> Result<u16, Fault> {
    let listener = utopia_async::net::TcpListener::bind("127.0.0.1:0").await.map_err(|e| Fault::new(e.to_string()))?;
    let port = listener.local_addr().map_err(|e| Fault::new(e.to_string()))?.port();
    drop(listener);
    Ok(port)
}

async fn raw(step: &Value, host: &str, port: &dyn Fn(&str) -> Result<u16, Fault>) -> Result<Value, Fault> {
    let transport = step.get("transport").and_then(Value::as_str).unwrap_or("udp");
    let io = |e: std::io::Error| Fault::new(e.to_string());
    if transport == "udp" {
        let socket = utopia_async::net::UdpSocket::bind("0.0.0.0:0").await.map_err(io)?;
        socket.send_to(&text(step, "data")?, (host, port("udp")?)).await.map_err(io)?;
        let mut buffer = vec![0u8; 65535];
        return Ok(match utopia_async::timeout(Duration::from_secs(3), socket.recv_from(&mut buffer)).await {
            Ok(Ok((n, _))) => bytes_value(&buffer[..n]),
            _ => Value::Null,
        });
    }
    let mut stream = utopia_async::net::TcpStream::connect((host, port(transport)?)).await.map_err(io)?;
    let chunks: Vec<Vec<u8>> = match step.get("chunks").and_then(Value::as_array) {
        Some(list) => list.iter().map(|c| bytes(c).unwrap_or_default()).collect(),
        None => vec![text(step, "data")?],
    };
    for chunk in chunks {
        let _ = stream.write_all(&chunk).await;
        utopia_async::sleep(Duration::from_millis(20)).await;
    }
    // Until the connection closes, `frames` complete answers arrive (waiting
    // up to 5 seconds), or nothing arrives for `wait` seconds.
    let wait = step.get("wait").and_then(Value::as_f64).unwrap_or(0.5);
    let frames = step.get("frames").and_then(Value::as_u64).map(|n| n as usize);
    let deadline = std::time::Instant::now() + Duration::from_secs(5);
    let mut data = Vec::new();
    let mut buffer = vec![0u8; 65536];
    let mut closed = false;
    while frames.is_none_or(|n| frame_count(&data) < n) {
        let limit = match frames {
            Some(_) => deadline.saturating_duration_since(std::time::Instant::now()),
            None => Duration::from_secs_f64(wait),
        };
        match utopia_async::timeout(limit, stream.read(&mut buffer)).await {
            Ok(Ok(0)) | Ok(Err(_)) => {
                closed = true;
                break;
            }
            Ok(Ok(n)) => data.extend_from_slice(&buffer[..n]),
            Err(_) => break,
        }
    }
    Ok(json!({"data": bytes_value(&data), "closed": closed}))
}

/// How many complete length-prefixed frames `data` holds.
fn frame_count(data: &[u8]) -> usize {
    let (mut count, mut at) = (0, 0);
    while data.len() >= at + 2 {
        let length = usize::from(u16::from_be_bytes([data[at], data[at + 1]]));
        if data.len() < at + 2 + length {
            break;
        }
        at += 2 + length;
        count += 1;
    }
    count
}

/// One HTTP/1.1 request, as PHP's `file_get_contents()` sends it.
async fn doh(step: &Value, host: &str, port: u16) -> Result<Value, Fault> {
    let io = |e: std::io::Error| Fault::new(e.to_string());
    let method = step.get("method").and_then(Value::as_str).unwrap_or("GET");
    let path = step.get("path").and_then(Value::as_str).unwrap_or("/");
    let body = text(step, "body")?;
    let mut request = format!("{method} {path} HTTP/1.1\r\nHost: {host}:{port}\r\nConnection: close\r\n").into_bytes();
    if let Some(content_type) = step.get("contentType").and_then(Value::as_str) {
        request.extend_from_slice(format!("Content-Type: {content_type}\r\n").as_bytes());
    }
    if let Some(headers) = step.get("headers").and_then(Value::as_object) {
        for (name, value) in headers {
            request.extend_from_slice(format!("{name}: {}\r\n", value.as_str().unwrap_or_default()).as_bytes());
        }
    }
    if !body.is_empty() {
        request.extend_from_slice(format!("Content-Length: {}\r\n", body.len()).as_bytes());
    }
    request.extend_from_slice(b"\r\n");
    request.extend_from_slice(&body);
    let mut stream = utopia_async::net::TcpStream::connect((host, port)).await.map_err(io)?;
    stream.write_all(&request).await.map_err(io)?;
    let mut response = Vec::new();
    let _ = utopia_async::timeout(Duration::from_secs(5), stream.read_to_end(&mut response)).await;
    let mut headers = [httparse::EMPTY_HEADER; 32];
    let mut parsed = httparse::Response::new(&mut headers);
    let Ok(httparse::Status::Complete(head)) = parsed.parse(&response) else {
        return Ok(json!({"status": 0, "contentType": null, "body": null}));
    };
    let content_type = parsed
        .headers
        .iter()
        .find(|h| h.name.eq_ignore_ascii_case("content-type"))
        .map(|h| String::from_utf8_lossy(h.value).trim().to_owned());
    Ok(json!({
        "status": parsed.code.unwrap_or(0),
        "contentType": content_type,
        "body": bytes_value(&response[head..]),
    }))
}
