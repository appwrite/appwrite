//! Compat adapter for `messaging`: maps `tests/compat/messaging/spec.json`
//! operations onto `utopia-messaging`.
//!
//! No provider is ever reached. An adapter's requests go to a [`Router`]
//! (the twin of `Router` in tests/compat/messaging/Doubles.php): `stub`
//! captures each call and answers with queued replies (the crate's
//! [`Requester`], as PHP's tests override `request()`), `fixture` and
//! `custom` send them through a [`Fixture`] transport to the
//! `messaging-http` fixture server, the provider URL rewritten to
//! `/<ns>/<scheme>/<host>/<path>`. Input the library refuses as
//! `Error::Invalid` is reported as a value, `{"$invalid": {type, value,
//! message}}`.

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use indexmap::IndexMap;
use php_std::Value as P;
use serde_json::{Map, Value, json};
use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpListener, TcpStream};
use utopia_client::{Anywhere, Client as HttpClient, Http as Transfer};
use utopia_messaging::chat::DiscordWebhook;
use utopia_messaging::email::{self, Mailgun, Resend, Sendgrid, Ses, Smtp};
use utopia_messaging::push::{Apns, Fcm};
use utopia_messaging::sms::{
    self, App, Bird, Category, Clickatell, Fast2Sms, GeoSms, Infobip, Inforu, Msg91, Msg91Parameter, OtpType, Plivo,
    Seven, Sinch, Telesign, Telnyx, TextMagic, Twilio, Vonage, WhatsApp, WhatsAppParameter,
};
use utopia_messaging::{
    Adapter, Attachment, Base, Batch, BoxFuture, Call, Configure, Delivery, Discord, Email, Error, Http, Message,
    Multi, OnResult, Priority, Push, Reason, Recipient, Reply, Requester, Response, Sent, Sms, Status, Transport,
};
use utopia_telemetry::{Attributes, Counter, Number, Record, Telemetry, Value as Attribute};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "email.new",
    "sms.new",
    "push.new",
    "discord.new",
    "attachment.new",
    "response.run",
    "exception.new",
    "no_host_answered.new",
    "enums",
    "calling_code.from_phone_number",
    "calling_code.constants",
    "jwt.encode",
    "bird.host_for",
    "whatsapp.app",
    "mime.message",
    "mime.addresses",
    "mime.attachments",
    "mime.size",
    "ses.sign",
    "smtp.hosts",
    "telemetry.new",
    "telemetry.records",
    "scripted.messages",
    "smtp_server.new",
    "smtp_server.commands",
    "adapter.new",
    "adapter.info",
    "adapter.send",
    "adapter.once",
    "adapter.call",
    "mailpit.message",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("email", "Build and validate an HTML email to two recipients with a CC and a header"),
    ("calling_code", "Find the calling code of a phone number"),
    ("mime", "Render an email as a MIME message"),
    ("ses_sign", "Sign an SES request with AWS Signature Version 4"),
    ("jwt_hs256", "Encode and sign an HS256 JWT"),
    ("send_twilio", "Send an SMS through Twilio on a stub answering at once"),
    ("send_sendgrid", "Send an email to two recipients through Sendgrid on a stub answering at once"),
];

const KEYS: &str = "tests/compat/messaging/fixtures/keys";

fn bench_email() -> Email {
    Email::builder(
        [Recipient::named("john@example.test", "John Doe"), Recipient::from("jane@example.test")],
        "Your order has shipped",
        "<p>Hello <b>John</b>, your order is on its way.</p>",
        "Acme, Inc.",
        "orders@example.test",
    )
    .cc([Recipient::named("support@example.test", "Support")])
    .html(true)
    .header("List-Unsubscribe", "<https://example.test/u?token=abc>")
    .build()
    .expect("a valid email")
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    Ok(match name {
        "email" => Bench::sync(|| {
            black_box(bench_email());
        }),
        "calling_code" => Bench::sync(|| {
            black_box(sms::calling_code::from_phone_number(black_box("+44 (20) 7946-0958")));
        }),
        "mime" => {
            let message = bench_email();
            Bench::sync(move || {
                let rendered = email::mime::message(&message, message.to(), message.cc().unwrap_or_default(), &[], &[])
                    .and_then(|m| m.to_bytes().map_err(Error::from));
                black_box(rendered.ok());
            })
        }
        "ses_sign" => {
            let ses = Ses::new("compat-access-key", "compat-secret-key", "us-east-1");
            let headers = vec![
                ("content-type".to_owned(), "application/json".to_owned()),
                ("host".to_owned(), "email.us-east-1.amazonaws.com".to_owned()),
                ("x-amz-date".to_owned(), "20260101T000000Z".to_owned()),
            ];
            Bench::sync(move || {
                black_box(ses.sign(
                    "POST",
                    "/v2/email/outbound-bulk-emails",
                    r#"{"FromEmailAddress":"a@example.test"}"#,
                    &headers,
                    "20260101T000000Z",
                ));
            })
        }
        "jwt_hs256" => {
            let claims = map_of([("iss", P::from("team")), ("iat", P::Int(1_700_000_000))]);
            Bench::sync(move || {
                black_box(utopia_messaging::jwt::encode(&claims, b"secret", "HS256", Some("kid")).ok());
            })
        }
        "send_twilio" => {
            let router = Arc::new(Router::bare());
            let twilio = Rc::new(
                Twilio::new("AC123", "token").with_from(Some("+15550000000".into())).with_requester(router.clone()),
            );
            let message = Rc::new(Message::Sms(Sms::new(["+15551234567"], "Your code is 123456")));
            Bench::future(move || {
                let (twilio, message) = (twilio.clone(), message.clone());
                async move {
                    black_box(twilio.send(&message).await.ok());
                }
            })
        }
        "send_sendgrid" => {
            let router = Arc::new(Router::bare());
            let sendgrid = Rc::new(Sendgrid::new("key").with_requester(router.clone()));
            let accepted = [json!({"statusCode": 202, "response": null})];
            let message = Rc::new(Message::Email(bench_email()));
            Bench::future(move || {
                let (sendgrid, message, router, accepted) =
                    (sendgrid.clone(), message.clone(), router.clone(), accepted.clone());
                async move {
                    router.queue(&accepted);
                    black_box(sendgrid.send(&message).await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("messaging: no benchmark `{name}`"))),
    })
}

// ---------------------------------------------------------------------------
// Values
// ---------------------------------------------------------------------------

/// A compat argument as the PHP value `Codec::decode` makes of it.
fn php(value: &Value) -> P {
    match value {
        Value::Object(o) if o.len() == 1 && o.contains_key("$bytes") => {
            let bytes = o["$bytes"].as_str().and_then(|b| BASE64.decode(b).ok()).unwrap_or_default();
            P::Str(php_std::Str::from(bytes))
        }
        Value::Object(o) if o.len() == 1 && o.contains_key("$float") => P::Float(match o["$float"].as_str() {
            Some("INF") => f64::INFINITY,
            Some("-INF") => f64::NEG_INFINITY,
            _ => f64::NAN,
        }),
        Value::Object(o) if o.is_empty() => P::Object(php_std::Array::new()),
        Value::Object(o) => P::Array(
            o.iter()
                .map(|(k, v)| (php_std::types::ArrayKey::normalize(php_std::Str::from(k.as_str())), php(v)))
                .collect(),
        ),
        Value::Array(a) => P::Array(a.iter().map(php).collect()),
        other => P::from_json(other),
    }
}

/// A PHP value as `Codec::encode` reports it.
fn out(value: &P) -> Value {
    match value {
        P::Null => Value::Null,
        P::Bool(b) => Value::Bool(*b),
        P::Int(i) => json!(i),
        P::Float(f) => crate::adapter::float_value(*f),
        P::Str(s) => bytes_value(s.as_bytes()),
        P::Array(a) if a.is_list() => Value::Array(a.values().map(out).collect()),
        P::Array(a) => Value::Object(
            a.iter()
                .map(|(k, v)| {
                    let key = match k {
                        php_std::types::KeyRef::Int(i) => i.to_string(),
                        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                    };
                    (key, out(v))
                })
                .collect(),
        ),
        P::Object(a) if a.is_empty() => Value::Object(Map::new()),
        P::Object(a) => out(&P::Array(a.clone())),
        P::Ext(_) => Value::Null,
    }
}

fn map_of<'a>(entries: impl IntoIterator<Item = (&'a str, P)>) -> P {
    P::Array(
        entries.into_iter().map(|(k, v)| (php_std::types::ArrayKey::normalize(php_std::Str::from(k)), v)).collect(),
    )
}

fn text(value: &Value) -> Result<String, Fault> {
    value.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("expected a string, got {value}")))
}

fn opt_text(a: &Args<'_>, key: &str) -> Result<Option<String>, Fault> {
    Ok(a.opt_str(key)?.map(str::to_owned))
}

fn invalid_out(reason: Reason, message: &str, value: &Option<String>) -> Value {
    json!({"$invalid": {"type": reason.as_str(), "value": value, "message": message}})
}

/// An error as the glue reports it: `$invalid` for the library's own
/// InvalidArgumentException, an error outcome otherwise.
fn report(error: &Error) -> Outcome {
    match error {
        Error::Invalid { reason, message, value } => Outcome::Ok(invalid_out(*reason, message, value)),
        other => Outcome::err(other.php_class(), other.message()),
    }
}

fn error_json(error: &Error) -> (String, Value) {
    match error {
        Error::Invalid { reason, message, value } => {
            ("$invalid".into(), json!({"type": reason.as_str(), "value": value, "message": message}))
        }
        other => ("error".into(), json!({"class": other.php_class(), "message": other.message()})),
    }
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

fn recipient(value: &Value) -> Result<Recipient, Fault> {
    Ok(match value {
        Value::String(s) => Recipient::Address(s.clone()),
        Value::Object(o) => Recipient::Entry {
            email: o.get("email").filter(|v| !v.is_null()).map(text).transpose()?,
            name: o.get("name").filter(|v| !v.is_null()).map(text).transpose()?,
        },
        other => return Err(Fault::new(format!("a recipient is a string or an object, got {other}"))),
    })
}

fn recipients(value: &Value) -> Result<Vec<Recipient>, Fault> {
    value.as_array().ok_or_else(|| Fault::new("recipients must be a list"))?.iter().map(recipient).collect()
}

fn attachment(value: &Value) -> Result<Attachment, Fault> {
    let a = Args(value);
    let mut attachment = Attachment::new(a.str("name")?, a.opt_str("path")?.unwrap_or(""), a.str("type")?);
    match a.opt("content") {
        None => {}
        Some(Value::Object(o)) if o.contains_key("repeat") => {
            let times = o.get("times").and_then(Value::as_u64).unwrap_or(0) as usize;
            attachment = attachment.with_content(text(&o["repeat"])?.repeat(times));
        }
        Some(content) => {
            attachment = attachment.with_content(crate::adapter::bytes(content).ok_or_else(|| Fault::new("content"))?)
        }
    }
    Ok(attachment)
}

fn build_email(value: &Value) -> Result<Result<Email, Error>, Fault> {
    let a = Args(value);
    let to = match a.opt("to") {
        Some(to) => recipients(to)?,
        None => Vec::new(),
    };
    let mut builder = Email::builder(
        to,
        a.opt_str("subject")?.unwrap_or("Subject"),
        a.opt_str("content")?.unwrap_or("Body"),
        a.opt_str("from_name")?.unwrap_or("Sender"),
        a.opt_str("from_email")?.unwrap_or("sender@example.test"),
    );
    if let Some(name) = a.opt_str("reply_to_name")? {
        builder = builder.reply_to_name(name);
    }
    if let Some(email) = a.opt_str("reply_to_email")? {
        builder = builder.reply_to_email(email);
    }
    if let Some(cc) = a.opt("cc") {
        builder = builder.cc(recipients(cc)?);
    }
    if let Some(bcc) = a.opt("bcc") {
        builder = builder.bcc(recipients(bcc)?);
    }
    if let Some(list) = a.opt("attachments") {
        builder = builder.attachments(
            list.as_array()
                .ok_or_else(|| Fault::new("attachments"))?
                .iter()
                .map(attachment)
                .collect::<Result<_, _>>()?,
        );
    }
    builder = builder.html(a.opt_bool("html")?.unwrap_or(false));
    if let Some(Value::Object(headers)) = a.opt("headers") {
        for (name, value) in headers {
            builder = builder.header(name.as_str(), text(value)?);
        }
    }
    Ok(builder.build().map(|email| {
        if value.get("origin").is_some() { email.with_origin(opt_text(&a, "origin").ok().flatten()) } else { email }
    }))
}

fn build_sms(value: &Value) -> Result<Sms, Fault> {
    let a = Args(value);
    let to = a.array("to")?.iter().map(text).collect::<Result<Vec<_>, _>>()?;
    let mut sms = Sms::new(to, a.opt_str("content")?.unwrap_or(""));
    if let Some(from) = a.opt_str("from")? {
        sms = sms.with_from(from);
    }
    if let Some(list) = a.opt("attachments") {
        sms = sms.with_attachments(
            list.as_array().ok_or_else(|| Fault::new("attachments"))?.iter().map(text).collect::<Result<_, _>>()?,
        );
    }
    if let Some(metadata) = a.opt("metadata") {
        sms = sms.with_metadata(Some(php(metadata)));
    }
    if let Some(metadata) = value.get("set_metadata") {
        sms.set_metadata((!metadata.is_null()).then(|| php(metadata)));
    }
    if value.get("origin").is_some() {
        sms = sms.with_origin(opt_text(&a, "origin")?);
    }
    Ok(sms)
}

fn build_push(value: &Value) -> Result<Result<Push, Error>, Fault> {
    let a = Args(value);
    let to = a.array("to")?.iter().map(text).collect::<Result<Vec<_>, _>>()?;
    let mut b = Push::builder(to);
    if let Some(v) = a.opt_str("title")? {
        b = b.title(v);
    }
    if let Some(v) = a.opt_str("body")? {
        b = b.body(v);
    }
    if let Some(v) = a.opt("data") {
        b = b.data(php(v));
    }
    for (key, set) in [
        ("action", PushText::Action),
        ("sound", PushText::Sound),
        ("image", PushText::Image),
        ("icon", PushText::Icon),
        ("color", PushText::Color),
        ("tag", PushText::Tag),
        ("channel_id", PushText::Channel),
    ] {
        if let Some(v) = a.opt_str(key)? {
            b = set.apply(b, v);
        }
    }
    if let Some(v) = a.opt_i64("badge")? {
        b = b.badge(v);
    }
    if let Some(v) = a.opt_bool("content_available")? {
        b = b.content_available(v);
    }
    if let Some(v) = a.opt_bool("critical")? {
        b = b.critical(v);
    }
    if let Some(p) = a.opt_str("priority")? {
        b = b.priority(match p {
            "HIGH" => Priority::High,
            "NORMAL" => Priority::Normal,
            other => return Err(Fault::new(format!("unknown priority {other}"))),
        });
    }
    Ok(b.build().map(|push| {
        if value.get("origin").is_some() { push.with_origin(opt_text(&a, "origin").ok().flatten()) } else { push }
    }))
}

#[derive(Clone, Copy)]
enum PushText {
    Action,
    Sound,
    Image,
    Icon,
    Color,
    Tag,
    Channel,
}

impl PushText {
    fn apply(self, b: utopia_messaging::PushBuilder, v: &str) -> utopia_messaging::PushBuilder {
        match self {
            PushText::Action => b.action(v),
            PushText::Sound => b.sound(v),
            PushText::Image => b.image(v),
            PushText::Icon => b.icon(v),
            PushText::Color => b.color(v),
            PushText::Tag => b.tag(v),
            PushText::Channel => b.channel_id(v),
        }
    }
}

fn build_discord(value: &Value) -> Result<Discord, Fault> {
    let a = Args(value);
    let mut d = Discord::new(a.str("content")?);
    if let Some(v) = a.opt_str("username")? {
        d = d.with_username(v);
    }
    if let Some(v) = a.opt_str("avatar_url")? {
        d = d.with_avatar_url(v);
    }
    if let Some(v) = a.opt_bool("tts")? {
        d = d.with_tts(v);
    }
    if let Some(v) = a.opt("embeds") {
        d = d.with_embeds(php(v));
    }
    if let Some(v) = a.opt("allowed_mentions") {
        d = d.with_allowed_mentions(php(v));
    }
    if let Some(v) = a.opt("components") {
        d = d.with_components(php(v));
    }
    if let Some(v) = a.opt("attachments") {
        d = d.with_attachments(php(v));
    }
    if let Some(v) = a.opt_str("flags")? {
        d = d.with_flags(v);
    }
    if let Some(v) = a.opt_str("thread_name")? {
        d = d.with_thread_name(v);
    }
    if let Some(v) = a.opt_bool("wait")? {
        d = d.with_wait(v);
    }
    if let Some(v) = a.opt_str("thread_id")? {
        d = d.with_thread_id(v);
    }
    if value.get("origin").is_some() {
        d = d.with_origin(opt_text(&a, "origin")?);
    }
    Ok(d)
}

fn build_message(value: &Value) -> Result<Result<Message, Error>, Fault> {
    Ok(match Args(value).str("kind")? {
        "email" => build_email(value)?.map(Message::Email),
        "sms" => Ok(Message::Sms(build_sms(value)?)),
        "push" => build_push(value)?.map(Message::Push),
        "discord" => Ok(Message::Discord(build_discord(value)?)),
        other => return Err(Fault::new(format!("unknown message kind {other}"))),
    })
}

fn mailboxes(list: Option<&[utopia_messaging::Mailbox]>) -> Value {
    match list {
        None => Value::Null,
        Some(list) => Value::Array(
            list.iter()
                .map(|m| match &m.name {
                    Some(name) => json!({"email": m.email, "name": name}),
                    None => json!({"email": m.email}),
                })
                .collect(),
        ),
    }
}

fn attachment_out(a: &Attachment) -> Value {
    json!({"name": a.name(), "path": a.path(), "type": a.mime_type(), "content": a.content().map(bytes_value)})
}

fn email_out(e: &Email) -> Value {
    let headers: Map<String, Value> = e.headers().iter().map(|(n, v)| (n.clone(), json!(v))).collect();
    json!({
        "to": mailboxes(Some(e.to())),
        "subject": e.subject(),
        "content": e.content(),
        "fromName": e.from_name(),
        "fromEmail": e.from_email(),
        "replyToName": e.reply_to_name(),
        "replyToEmail": e.reply_to_email(),
        "cc": mailboxes(e.cc()),
        "bcc": mailboxes(e.bcc()),
        "attachments": e.attachments().map(|l| l.iter().map(attachment_out).collect::<Vec<_>>()),
        "html": e.is_html(),
        "headers": if headers.is_empty() { json!([]) } else { Value::Object(headers) },
        "origin": e.origin(),
    })
}

fn sms_out(s: &Sms) -> Value {
    json!({
        "to": s.to(),
        "content": s.content(),
        "from": s.from(),
        "attachments": s.attachments(),
        "metadata": s.metadata().map(out),
        "origin": s.origin(),
    })
}

fn push_out(p: &Push) -> Value {
    json!({
        "to": p.to(), "from": p.from(), "title": p.title(), "body": p.body(), "data": p.data().map(out),
        "action": p.action(), "sound": p.sound(), "image": p.image(), "icon": p.icon(), "color": p.color(),
        "tag": p.tag(), "badge": p.badge(), "contentAvailable": p.content_available(), "critical": p.critical(),
        "priority": p.priority().map(Priority::name), "channelId": p.channel_id(), "origin": p.origin(),
    })
}

fn discord_out(d: &Discord) -> Value {
    json!({
        "content": d.content(), "username": d.username(), "avatarUrl": d.avatar_url(), "tts": d.tts(),
        "embeds": d.embeds().map(out), "allowedMentions": d.allowed_mentions().map(out),
        "components": d.components().map(out), "attachments": d.attachments().map(out), "flags": d.flags(),
        "threadName": d.thread_name(), "wait": d.wait(), "threadId": d.thread_id(), "origin": d.origin(),
    })
}

fn delivery_out(d: &Delivery) -> Value {
    json!({"recipient": d.recipient, "status": d.status.as_str(), "error": d.error, "permanent": d.permanent})
}

fn response_out(r: &Response) -> Value {
    json!({"deliveredTo": r.delivered_to(), "type": r.kind(), "results": r.details().iter().map(delivery_out).collect::<Vec<_>>()})
}

fn sent_out(sent: &Sent) -> Value {
    match sent {
        Sent::Response(r) => response_out(r),
        Sent::Grouped(groups) => Value::Object(
            groups
                .iter()
                .map(|(name, batch)| {
                    let value = match batch {
                        Batch::Sent(sent) => sent_out(sent),
                        Batch::Failed(message) => json!({"type": "error", "message": message}),
                    };
                    (name.clone(), value)
                })
                .collect(),
        ),
    }
}

// ---------------------------------------------------------------------------
// Normalising what differs between runs
// ---------------------------------------------------------------------------

fn base64url(value: &str) -> Option<Vec<u8>> {
    php_std::encoding::base64_decode(value.replace('-', "+").replace('_', "/").as_bytes(), true)
}

/// Whether the fixture public key of `alg` verifies the signature.
fn verify(signed: &[u8], signature: &[u8], alg: &str) -> bool {
    use openssl::hash::MessageDigest;
    let file = format!("{KEYS}/{}.pub.pem", alg.to_ascii_lowercase());
    let Ok(pem) = std::fs::read(&file) else { return false };
    let Ok(key) = openssl::pkey::PKey::public_key_from_pem(&pem) else { return false };
    let digest = if alg.ends_with("384") {
        MessageDigest::sha384()
    } else if alg.ends_with("512") {
        MessageDigest::sha512()
    } else {
        MessageDigest::sha256()
    };
    let signature = if alg.starts_with("ES") { der(signature) } else { signature.to_vec() };
    let Ok(mut verifier) = openssl::sign::Verifier::new(digest, &key) else { return false };
    verifier.update(signed).is_ok() && verifier.verify(&signature).unwrap_or(false)
}

/// ECDSA `r || s` as DER.
fn der(rs: &[u8]) -> Vec<u8> {
    let half = rs.len() / 2;
    let int = |v: &[u8]| {
        let mut v: Vec<u8> = v.iter().copied().skip_while(|b| *b == 0).collect();
        if v.is_empty() || v[0] > 0x7f {
            v.insert(0, 0);
        }
        let mut out = vec![0x02, v.len() as u8];
        out.extend(v);
        out
    };
    let mut seq = int(&rs[..half]);
    seq.extend(int(&rs[half..]));
    let mut out = vec![0x30, seq.len() as u8];
    out.extend(seq);
    out
}

fn decode_json(bytes: &[u8]) -> P {
    php_std::json::decode(bytes, Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
        .unwrap_or(P::Null)
}

/// A JWT decoded: header, payload with iat as `<now>` and exp as `<iat+N>`,
/// and whether the fixture key of its algorithm verifies it.
fn jwt_view(token: &str) -> Value {
    let parts: Vec<&str> = token.split('.').collect();
    if parts.len() != 3 {
        return json!({"malformed": token});
    }
    let header = decode_json(&base64url(parts[0]).unwrap_or_default());
    let mut payload = decode_json(&base64url(parts[1]).unwrap_or_default());
    let signature = base64url(parts[2]).unwrap_or_default();
    let alg = match &header {
        P::Array(h) => match h.get_str("alg") {
            Some(P::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            Some(other) => {
                other.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default()
            }
            None => String::new(),
        },
        _ => String::new(),
    };
    if let P::Array(claims) = &mut payload
        && let Some(P::Int(iat)) = claims.get_str("iat").cloned()
        && (iat - php_std::datetime::time()).abs() <= 600
    {
        if let Some(P::Int(exp)) = claims.get_str("exp").cloned() {
            claims.set(
                php_std::types::ArrayKey::normalize("exp".into()),
                P::from(format!("<iat+{}>", exp - iat).as_str()),
            );
        }
        claims.set(php_std::types::ArrayKey::normalize("iat".into()), P::from("<now>"));
    }
    let signed = format!("{}.{}", parts[0], parts[1]);
    json!({"header": out(&header), "payload": out(&payload), "verified": verify(signed.as_bytes(), &signature, &alg)})
}

/// MIME text with random boundaries renamed in order, Date and Message-ID masked.
fn normalize_mime(mime: &[u8]) -> Vec<u8> {
    let mut names: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
    let mut offset = 0;
    while let Some(at) = find(&mime[offset..], b"=_").map(|i| i + offset) {
        let candidate = mime.get(at..at + 34);
        match candidate {
            Some(c) if c[2..].iter().all(|b| b"0123456789abcdef".contains(b)) => {
                if !names.iter().any(|(n, _)| n == c) {
                    names.push((c.to_vec(), format!("=_BOUNDARY{}", names.len() + 1).into_bytes()));
                }
                offset = at + 34;
            }
            _ => offset = at + 1,
        }
    }
    let mut renamed = Vec::with_capacity(mime.len());
    let mut i = 0;
    while i < mime.len() {
        if let Some((from, to)) = names.iter().find(|(n, _)| mime[i..].starts_with(n)) {
            renamed.extend_from_slice(to);
            i += from.len();
        } else {
            renamed.push(mime[i]);
            i += 1;
        }
    }
    let mut result = Vec::with_capacity(renamed.len());
    let mut start = 0;
    while start <= renamed.len() {
        let end = renamed[start..].iter().position(|b| *b == b'\n').map_or(renamed.len(), |p| start + p);
        let line = &renamed[start..end];
        if line.starts_with(b"Date: ") {
            let stop = line.iter().position(|b| *b == b'\r').unwrap_or(line.len());
            result.extend_from_slice(b"Date: <date>");
            result.extend_from_slice(&line[stop..]);
        } else if line.starts_with(b"Message-ID: <")
            && line.len() >= 13 + 33
            && line[13..45].iter().all(|b| b"0123456789abcdef".contains(b))
            && line[45] == b'@'
        {
            result.extend_from_slice(b"Message-ID: <<id>@");
            result.extend_from_slice(&line[46..]);
        } else {
            result.extend_from_slice(line);
        }
        if end < renamed.len() {
            result.push(b'\n');
        }
        start = end + 1;
    }
    result
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}

/// A captured header line, normalised as Normal::call() does.
fn normal_header(line: &str, tokens: &mut Vec<Value>) -> String {
    if let Some(token) = line.strip_prefix("Authorization: Bearer ")
        && is_jwt(token)
    {
        tokens.push(jwt_view(token));
        return "Authorization: Bearer <jwt>".into();
    }
    if line.starts_with("X-Amz-Date: ") {
        return "X-Amz-Date: <amz-date>".into();
    }
    if line.starts_with("Authorization: AWS4-HMAC-SHA256 ") {
        let mut out = line.to_owned();
        if let Some(i) = (0..out.len().saturating_sub(9)).find(|&i| {
            let b = out.as_bytes();
            b[i] == b'/' && b[i + 9] == b'/' && b[i + 1..i + 9].iter().all(u8::is_ascii_digit)
        }) {
            out.replace_range(i..i + 10, "/<date>/");
        }
        if let Some(at) = out.rfind("Signature=")
            && out.len() - at - 10 == 64
            && out[at + 10..].bytes().all(|b| b"0123456789abcdef".contains(&b))
        {
            out.replace_range(at + 10.., "<signature>");
        }
        return out;
    }
    line.to_owned()
}

fn is_jwt(token: &str) -> bool {
    let parts: Vec<&str> = token.split('.').collect();
    let ok = |p: &str| p.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-');
    parts.len() == 3 && !parts[0].is_empty() && !parts[1].is_empty() && parts.iter().all(|p| ok(p))
}

/// A captured body (one PHP array), normalised as Normal::body() does.
fn normal_body(body: &P, tokens: &mut Vec<Value>) -> P {
    let P::Array(array) = body else { return body.clone() };
    let mut array = array.clone();
    if let Some(P::Str(assertion)) = array.get_str("assertion").cloned() {
        tokens.push(jwt_view(&String::from_utf8_lossy(assertion.as_bytes())));
        array.set(php_std::types::ArrayKey::normalize("assertion".into()), P::from("<jwt>"));
    }
    let raw = match array.get_str("Content") {
        Some(P::Array(content)) => match content.get_str("Raw") {
            Some(P::Array(raw)) => raw.get_str("Data").cloned(),
            _ => None,
        },
        _ => None,
    };
    if let Some(P::Str(data)) = raw {
        let mime = php_std::encoding::base64_decode(data.as_bytes(), false).unwrap_or_default();
        let normalized = P::Str(php_std::Str::from(normalize_mime(&mime)));
        let mut wrapped = P::Array(array);
        set_path(&mut wrapped, &["Content", "Raw", "Data"], normalized);
        let P::Array(a) = wrapped else { unreachable!() };
        array = a;
    }
    P::Array(array)
}

fn set_path(target: &mut P, path: &[&str], value: P) {
    let Some((first, rest)) = path.split_first() else {
        *target = value;
        return;
    };
    if let P::Array(a) = target {
        let key = php_std::types::ArrayKey::normalize((*first).into());
        if let Some(slot) = a.get_mut(&key) {
            set_path(slot, rest, value);
        }
    }
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

/// How a routed adapter's requests are answered.
pub struct Router {
    stub: bool,
    /// Answers without capturing, for benchmarks (PHP's `bare` mode).
    bare: bool,
    fixture: String,
    ns: String,
    replies: Mutex<VecDeque<Value>>,
    captured: Mutex<Vec<Value>>,
}

impl Router {
    fn stub() -> Router {
        Router {
            stub: true,
            bare: false,
            fixture: String::new(),
            ns: String::new(),
            replies: Mutex::default(),
            captured: Mutex::default(),
        }
    }

    fn fixture(fixture: &str, ns: &str) -> Router {
        Router {
            stub: false,
            bare: false,
            fixture: fixture.to_owned(),
            ns: ns.to_owned(),
            replies: Mutex::default(),
            captured: Mutex::default(),
        }
    }

    fn bare() -> Router {
        Router { bare: true, ..Router::stub() }
    }

    fn queue(&self, replies: &[Value]) {
        self.replies.lock().unwrap().extend(replies.iter().cloned());
    }

    async fn queue_remote(&self, replies: &[Value]) -> Result<(), Fault> {
        if self.stub {
            self.queue(replies);
        } else if !replies.is_empty() {
            fixture_http(
                &self.fixture,
                "POST",
                &format!("/_queue/{}", self.ns),
                Some(&serde_json::to_vec(replies).unwrap()),
            )
            .await?;
        }
        Ok(())
    }

    async fn take(&self, sort: bool) -> Result<Value, Fault> {
        if self.stub {
            self.replies.lock().unwrap().clear();
            return Ok(Value::Array(std::mem::take(&mut *self.captured.lock().unwrap())));
        }
        let path = format!("/_take/{}{}", self.ns, if sort { "?sort=1" } else { "" });
        let body = fixture_http(&self.fixture, "GET", &path, None).await?;
        serde_json::from_slice(&body).map_err(|e| Fault::new(format!("the fixture answered: {e}")))
    }

    fn reply(&self) -> (i64, P, IndexMap<String, String>, String, i64) {
        let reply = self.replies.lock().unwrap().pop_front().unwrap_or_else(|| json!({}));
        let headers = reply
            .get("headers")
            .and_then(Value::as_object)
            .map(|h| h.iter().map(|(k, v)| (k.clone(), v.as_str().unwrap_or("").to_owned())).collect())
            .unwrap_or_default();
        (
            reply.get("statusCode").and_then(Value::as_i64).unwrap_or(200),
            // As json_decode($body, true) reads a body: objects are arrays.
            reply.get("response").map_or(P::Array(php_std::Array::new()), |r| match r {
                Value::Null => P::Null,
                other => decode_json(other.to_string().as_bytes()),
            }),
            headers,
            reply.get("error").and_then(Value::as_str).unwrap_or("").to_owned(),
            reply.get("errorCode").and_then(Value::as_i64).unwrap_or(0),
        )
    }

    /// `https://host/path?query` as `<fixture>/<ns>/https/host/path?query`.
    fn rewrite(&self, uri: &utopia_client::Uri) -> String {
        let authority = match uri.port() {
            Some(port) => format!("{}:{port}", uri.host()),
            None => uri.host().to_owned(),
        };
        let query = if uri.query().is_empty() { String::new() } else { format!("?{}", uri.query()) };
        format!("{}/{}/{}/{}{}{}", self.fixture, self.ns, uri.scheme(), authority, uri.path(), query)
    }
}

fn body_with_files(
    body: Option<&P>,
    files: &[(String, utopia_psr7::multipart::Part)],
    tokens: &mut Vec<Value>,
) -> Value {
    let Some(body) = body else { return Value::Null };
    let mut encoded = out(&normal_body(body, tokens));
    if !files.is_empty()
        && let Value::Object(o) = &mut encoded
    {
        for (key, part) in files {
            o.insert(
                key.clone(),
                json!({"$part": {
                    "name": part.name(),
                    "filename": part.filename(),
                    "type": part.content_type(),
                    "path": part.path().map(|p| p.to_string_lossy().into_owned()),
                }}),
            );
        }
    }
    encoded
}

impl Requester for Router {
    fn request<'a>(&'a self, call: Call) -> BoxFuture<'a, Result<Reply, Error>> {
        Box::pin(async move {
            if !self.bare {
                let mut tokens = Vec::new();
                let headers: Vec<String> = call.headers.iter().map(|h| normal_header(h, &mut tokens)).collect();
                let body = body_with_files(call.body.as_ref(), &call.files, &mut tokens);
                let mut entry = json!({"method": call.method, "url": call.url, "headers": headers, "body": body});
                if !tokens.is_empty() {
                    entry["jwt"] = Value::Array(tokens);
                }
                self.captured.lock().unwrap().push(entry);
            }
            let (status, response, headers, error, code) = self.reply();
            let mut reply = Reply::new(call.url, status, response);
            reply.headers = headers;
            reply.error = error;
            reply.error_code = code;
            Ok(reply)
        })
    }

    fn request_multi<'a>(
        &'a self,
        multi: Multi,
        on_result: Option<&'a OnResult<'a>>,
    ) -> BoxFuture<'a, Result<Vec<Reply>, Error>> {
        Box::pin(async move {
            let mut tokens = Vec::new();
            let headers: Vec<String> = multi.headers.iter().map(|h| normal_header(h, &mut tokens)).collect();
            let bodies: Vec<Value> = multi.bodies.iter().map(|b| out(&normal_body(b, &mut tokens))).collect();
            let mut entry = json!({"method": multi.method, "urls": multi.urls, "headers": headers, "bodies": bodies});
            if !tokens.is_empty() {
                entry["jwt"] = Value::Array(tokens);
            }
            self.captured.lock().unwrap().push(entry);
            let count = multi.urls.len().max(multi.bodies.len());
            let mut replies = Vec::with_capacity(count);
            for i in 0..count {
                let url = multi.urls.get(i).or(multi.urls.first()).cloned().unwrap_or_default();
                let (status, response, headers, error, code) = self.reply();
                let mut reply = Reply::new(url, status, response);
                reply.index = Some(i);
                reply.headers = headers;
                reply.error = error;
                reply.error_code = code;
                replies.push(reply);
            }
            // As the library's own stubs answer: in request order, each
            // handed over when `on_result` is given, which then keeps none.
            if let Some(on_result) = on_result {
                for reply in replies {
                    on_result(reply)?;
                }
                return Ok(Vec::new());
            }
            Ok(replies)
        })
    }
}

/// Sends a request to the fixture server, the provider URL rewritten.
struct Fixture {
    router: Arc<Router>,
    client: HttpClient<Transfer>,
}

impl Fixture {
    fn new(router: Arc<Router>) -> Fixture {
        let client = HttpClient::new(Transfer::new())
            .with_destinations(Anywhere)
            .with_timeout(30.0)
            .and_then(|c| c.with_connect_timeout(10.0))
            .expect("valid timeouts");
        Fixture { router, client }
    }
}

impl Transport for Fixture {
    fn send<'a>(
        &'a self,
        request: utopia_client::Request,
    ) -> BoxFuture<'a, Result<utopia_client::Response, utopia_client::Error>> {
        Box::pin(async move {
            let target = utopia_client::Uri::parse(&self.router.rewrite(request.uri()))?;
            let authority = match target.port() {
                Some(port) => format!("{}:{port}", target.host()),
                None => target.host().to_owned(),
            };
            let mut request = request.with_uri(target);
            if request.headers().has("Host") {
                request.headers_mut().set("Host", [authority])?;
            }
            self.client.send(request).await
        })
    }
}

async fn fixture_http(base: &str, method: &str, path: &str, body: Option<&[u8]>) -> Result<Vec<u8>, Fault> {
    let host = base.strip_prefix("http://").ok_or_else(|| Fault::new("the fixture address is http://host:port"))?;
    let fail = |e: std::io::Error| {
        Fault::new(format!(
            "the messaging-http fixture server is not reachable (tests/compat/messaging/services.sh up): {e}"
        ))
    };
    let mut stream = TcpStream::connect(host).await.map_err(fail)?;
    let body = body.unwrap_or_default();
    let head = format!(
        "{method} {path} HTTP/1.0\r\nHost: {host}\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
        body.len()
    );
    stream.write_all(head.as_bytes()).await.map_err(fail)?;
    stream.write_all(body).await.map_err(fail)?;
    let mut response = Vec::new();
    stream.read_to_end(&mut response).await.map_err(fail)?;
    let at = find(&response, b"\r\n\r\n").ok_or_else(|| Fault::new("the fixture sent no headers"))?;
    Ok(response[at + 4..].to_vec())
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/// Records every measurement of the counters it creates.
#[derive(Default)]
struct Recording {
    records: Mutex<Vec<Value>>,
}

struct Recorder(Arc<Recording>);

impl Record for Recorder {
    fn record(&self, value: Number, attributes: Attributes<'_>) {
        let amount = match value {
            Number::Int(i) => json!(i),
            Number::Float(f) => crate::adapter::float_value(f),
        };
        let attributes: Map<String, Value> = attributes
            .iter()
            .map(|(k, v)| {
                let v = match v {
                    Attribute::Str(s) => json!(s),
                    Attribute::Int(i) => json!(i),
                    Attribute::Float(f) => crate::adapter::float_value(*f),
                    Attribute::Bool(b) => json!(b),
                };
                ((*k).to_owned(), v)
            })
            .collect();
        self.0.records.lock().unwrap().push(json!({"amount": amount, "attributes": attributes}));
    }
}

struct RecordingTelemetry(Arc<Recording>);

impl Telemetry for RecordingTelemetry {
    fn counter(&self, _name: &str, _unit: Option<&str>, _description: Option<&str>) -> Counter {
        Counter::new(Arc::new(Recorder(self.0.clone())))
    }

    fn up_down_counter(&self, _: &str, _: Option<&str>, _: Option<&str>) -> utopia_telemetry::UpDownCounter {
        unimplemented!("TelemetryTest only counts")
    }

    fn histogram(&self, _: &str, _: Option<&str>, _: Option<&str>, _: Option<&[f64]>) -> utopia_telemetry::Histogram {
        unimplemented!("TelemetryTest only counts")
    }

    fn gauge(&self, _: &str, _: Option<&str>, _: Option<&str>) -> utopia_telemetry::Gauge {
        unimplemented!("TelemetryTest only counts")
    }

    fn observable_gauge(&self, _: &str, _: Option<&str>, _: Option<&str>) -> utopia_telemetry::ObservableGauge {
        unimplemented!("TelemetryTest only counts")
    }

    fn collect(&self) -> utopia_telemetry::BoxFuture<'_, bool> {
        Box::pin(async { true })
    }
}

/// An SMS adapter answering from a script (TelemetryTest's TelemetryAdapter).
struct Scripted {
    name: String,
    max: i64,
    response: Option<Value>,
    error: Option<String>,
    messages: Mutex<Vec<Sms>>,
    base: Base,
}

impl Adapter for Scripted {
    fn name(&self) -> &str {
        &self.name
    }

    fn kind(&self) -> &str {
        sms::TYPE
    }

    fn message_type(&self) -> utopia_messaging::MessageType {
        utopia_messaging::MessageType::Sms
    }

    fn max_messages_per_request(&self) -> i64 {
        self.max
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(async move {
            if let Message::Sms(sms) = message {
                self.messages.lock().unwrap().push(sms.clone());
            }
            if let Some(error) = &self.error {
                return Err(Error::Exception(error.clone()));
            }
            let canned =
                self.response.clone().unwrap_or_else(|| json!({"deliveredTo": 0, "type": "sms", "results": []}));
            let mut response = Response::new(canned["type"].as_str().unwrap_or("sms"));
            response.set_delivered_to(canned["deliveredTo"].as_i64().unwrap_or(0));
            for result in canned["results"].as_array().into_iter().flatten() {
                response.add_delivery(Delivery {
                    recipient: result["recipient"].as_str().unwrap_or("").to_owned(),
                    status: if result["status"] == "success" { Status::Success } else { Status::Failure },
                    error: result["error"].as_str().unwrap_or("").to_owned(),
                    permanent: result["permanent"].as_bool().unwrap_or(false),
                });
            }
            Ok(Sent::Response(response))
        })
    }
}

/// A scripted SMTP peer: the greeting, then one reply per command (the
/// twin of packages/messaging/tests/Support/smtp-server.php).
struct SmtpServer {
    port: u16,
    commands: tokio::sync::Mutex<Option<tokio::task::JoinHandle<Vec<String>>>>,
    done: Mutex<Option<Vec<String>>>,
}

impl SmtpServer {
    async fn start(replies: Vec<String>) -> Result<SmtpServer, Fault> {
        let listener = TcpListener::bind("127.0.0.1:0").await.map_err(|e| Fault::new(e.to_string()))?;
        let port = listener.local_addr().map_err(|e| Fault::new(e.to_string()))?.port();
        let task = tokio::spawn(async move {
            let mut commands = Vec::new();
            let mut replies: VecDeque<String> = replies.into();
            let Ok(Ok((stream, _))) = tokio::time::timeout(Duration::from_secs(10), listener.accept()).await else {
                return commands;
            };
            let (read, mut write) = stream.into_split();
            let mut reader = BufReader::new(read);
            if let Some(greeting) = replies.pop_front() {
                let _ = write.write_all(format!("{greeting}\r\n").as_bytes()).await;
            }
            while !replies.is_empty() {
                let mut line = String::new();
                match tokio::time::timeout(Duration::from_secs(10), reader.read_line(&mut line)).await {
                    Ok(Ok(n)) if n > 0 => {}
                    _ => break,
                }
                commands.push(line.trim_end_matches(['\r', '\n']).to_owned());
                let reply = replies.pop_front().unwrap_or_default();
                let _ = write.write_all(format!("{reply}\r\n").as_bytes()).await;
                if reply.starts_with("354") && !replies.is_empty() {
                    loop {
                        let mut data = String::new();
                        match reader.read_line(&mut data).await {
                            Ok(n) if n > 0 && data != ".\r\n" => continue,
                            _ => break,
                        }
                    }
                    let next = replies.pop_front().unwrap_or_default();
                    let _ = write.write_all(format!("{next}\r\n").as_bytes()).await;
                }
            }
            commands
        });
        Ok(SmtpServer { port, commands: tokio::sync::Mutex::new(Some(task)), done: Mutex::new(None) })
    }

    async fn commands(&self) -> Vec<String> {
        if let Some(task) = self.commands.lock().await.take() {
            let commands =
                tokio::time::timeout(Duration::from_secs(15), task).await.ok().and_then(Result::ok).unwrap_or_default();
            *self.done.lock().unwrap() = Some(commands);
        }
        self.done.lock().unwrap().clone().unwrap_or_default()
    }
}

/// A port nobody listens on.
async fn closed_port() -> Result<u16, Fault> {
    let listener = TcpListener::bind("127.0.0.1:0").await.map_err(|e| Fault::new(e.to_string()))?;
    Ok(listener.local_addr().map_err(|e| Fault::new(e.to_string()))?.port())
}

// ---------------------------------------------------------------------------
// Adapters
// ---------------------------------------------------------------------------

/// What an adapter handle holds.
struct Entry {
    adapter: Arc<dyn Adapter>,
    special: Special,
    router: Option<Arc<Router>>,
    /// `127.0.0.1:<port>` of the scripted SMTP servers, and the
    /// placeholder results report instead.
    ports: Vec<(String, String)>,
}

enum Special {
    None,
    Smtp(Arc<Smtp>),
    MockSms(Arc<sms::Mock>),
    WhatsApp(Arc<WhatsApp>),
    Geo(Arc<GeoSms>),
    Scripted(Arc<Scripted>),
}

fn arg_str(args: &Value, key: &str) -> Result<Option<String>, Fault> {
    opt_text(&Args(args), key)
}

fn req_str(args: &Value, key: &str) -> Result<String, Fault> {
    Args(args).str(key).map(str::to_owned)
}

/// Builds the adapter `name` from PHP's constructor parameters, routed per `mode`.
async fn build_adapter(
    name: &str,
    args: &Value,
    a: &Args<'_>,
    s: &mut Session,
    mode: &str,
) -> Result<Result<Entry, Error>, Fault> {
    let router = match mode {
        "stub" => Some(Arc::new(Router::stub())),
        "fixture" | "custom" | "default" => Some(Arc::new(Router::fixture(s.service("messaging-http")?, &s.ns))),
        _ => None,
    };
    fn routed<T: Configure>(adapter: T, router: &Option<Arc<Router>>, mode: &str) -> T {
        match (router, mode) {
            (Some(router), "stub") => adapter.with_requester(router.clone()),
            (Some(router), "fixture" | "custom") => adapter.with_transport(Arc::new(Fixture::new(router.clone()))),
            // `default` keeps the adapter's own client: its router only queues and takes.
            _ => adapter,
        }
    }
    macro_rules! plain {
        ($e:expr) => {{
            let adapter = Arc::new(routed($e, &router, mode));
            Entry { adapter, special: Special::None, router: router.clone(), ports: Vec::new() }
        }};
    }
    let from = || arg_str(args, "from");
    let entry = match name {
        "mailgun" => plain!(
            Mailgun::new(req_str(args, "apiKey")?, req_str(args, "domain")?)
                .with_eu(args.get("isEU").and_then(Value::as_bool).unwrap_or(false))
        ),
        "sendgrid" => plain!(Sendgrid::new(req_str(args, "apiKey")?)),
        "resend" => plain!(Resend::new(req_str(args, "apiKey")?)),
        "ses" => plain!(
            Ses::new(req_str(args, "accessKey")?, req_str(args, "secretKey")?, req_str(args, "region")?)
                .with_session_token(arg_str(args, "sessionToken")?)
        ),
        "smtp" | "mock_email" => {
            let (host, ports) =
                resolve_host(&req_str(args, "host").or_else(|_| Ok::<_, Fault>("maildev".into()))?, a, s).await?;
            let smtp = if name == "mock_email" {
                email::Mock::new(host, args.get("port").and_then(Value::as_i64).unwrap_or(1025))
            } else {
                let mut smtp = Smtp::new(host, args.get("port").and_then(Value::as_i64).unwrap_or(25))
                    .with_credentials(
                        arg_str(args, "username")?.unwrap_or_default(),
                        arg_str(args, "password")?.unwrap_or_default(),
                    )
                    .with_auto_tls(args.get("smtpAutoTLS").and_then(Value::as_bool).unwrap_or(false))
                    .with_x_mailer(arg_str(args, "xMailer")?.unwrap_or_default())
                    .with_keep_alive(args.get("keepAlive").and_then(Value::as_bool).unwrap_or(false));
                for (key, set) in [
                    ("timeout", Smtp::with_timeout as fn(Smtp, i64) -> Smtp),
                    ("timelimit", Smtp::with_time_limit),
                    ("pingThreshold", Smtp::with_ping_threshold),
                    ("restartThreshold", Smtp::with_restart_threshold),
                ] {
                    if let Some(v) = args.get(key).and_then(Value::as_i64) {
                        smtp = set(smtp, v);
                    }
                }
                match smtp.with_secure(arg_str(args, "smtpSecure")?.unwrap_or_default()) {
                    Ok(smtp) => smtp,
                    Err(e) => return Ok(Err(e)),
                }
            };
            let smtp = Arc::new(smtp);
            Entry { adapter: smtp.clone(), special: Special::Smtp(smtp), router: None, ports }
        }
        "bird" => {
            let key = req_str(args, "apiKey")?;
            let bird = match arg_str(args, "endpoint")? {
                Some(endpoint) => Bird::at(key, &endpoint),
                None => match Bird::new(key) {
                    Ok(bird) => bird,
                    Err(e) => return Ok(Err(e)),
                },
            };
            let mut bird = bird.with_from(from()?);
            if let Some(category) = arg_str(args, "category")? {
                bird =
                    bird.with_category(Category::from_value(&category).ok_or_else(|| Fault::new("unknown category"))?);
            }
            plain!(bird)
        }
        "clickatell" => plain!(Clickatell::new(req_str(args, "apiKey")?).with_from(from()?)),
        "fast2sms" => plain!(Fast2Sms::new(req_str(args, "apiKey")?).with_dlt(
            arg_str(args, "senderId")?.unwrap_or_default(),
            arg_str(args, "messageId")?.unwrap_or_default(),
            args.get("useDLT").and_then(Value::as_bool).unwrap_or(false),
        )),
        "infobip" => plain!(Infobip::new(req_str(args, "apiBaseUrl")?, req_str(args, "apiKey")?).with_from(from()?)),
        "inforu" => plain!(Inforu::new(req_str(args, "senderId")?, req_str(args, "apiToken")?)),
        "mock_sms" => {
            let mock =
                Arc::new(routed(sms::Mock::new(req_str(args, "user")?, req_str(args, "secret")?), &router, mode));
            Entry { adapter: mock.clone(), special: Special::MockSms(mock), router: router.clone(), ports: Vec::new() }
        }
        "msg91" => {
            plain!(Msg91::new(req_str(args, "senderId")?, req_str(args, "authKey")?, req_str(args, "templateId")?))
        }
        "plivo" => plain!(Plivo::new(req_str(args, "authId")?, req_str(args, "authToken")?).with_from(from()?)),
        "seven" => plain!(Seven::new(req_str(args, "apiKey")?).with_from(from()?)),
        "sinch" => plain!(Sinch::new(req_str(args, "servicePlanId")?, req_str(args, "apiToken")?).with_from(from()?)),
        "telesign" => plain!(Telesign::new(req_str(args, "customerId")?, req_str(args, "apiKey")?)),
        "telnyx" => plain!(Telnyx::new(req_str(args, "apiKey")?).with_from(from()?)),
        "textmagic" => plain!(TextMagic::new(req_str(args, "username")?, req_str(args, "apiKey")?).with_from(from()?)),
        "twilio" => plain!(
            Twilio::new(req_str(args, "accountSid")?, req_str(args, "authToken")?)
                .with_from(from()?)
                .with_messaging_service_sid(arg_str(args, "messagingServiceSid")?)
        ),
        "vonage" => plain!(Vonage::new(req_str(args, "apiKey")?, req_str(args, "apiSecret")?).with_from(from()?)),
        "whatsapp" => {
            let whatsapp = match WhatsApp::new(
                req_str(args, "accessToken")?,
                req_str(args, "phoneNumberId")?,
                req_str(args, "template")?,
            ) {
                Ok(w) => w,
                Err(e) => return Ok(Err(e)),
            };
            let mut whatsapp = whatsapp;
            if let Some(language) = arg_str(args, "language")? {
                whatsapp = whatsapp.with_language(language);
            }
            if let Some(version) = arg_str(args, "version")? {
                whatsapp = whatsapp.with_version(version);
            }
            let whatsapp = Arc::new(routed(whatsapp, &router, mode));
            Entry {
                adapter: whatsapp.clone(),
                special: Special::WhatsApp(whatsapp),
                router: router.clone(),
                ports: Vec::new(),
            }
        }
        "apns" => {
            let key = match args.get("authKey") {
                Some(Value::Object(o)) => {
                    let name = o.get("fixture").and_then(Value::as_str).unwrap_or_default();
                    String::from_utf8_lossy(
                        &std::fs::read(format!("{KEYS}/{name}.pem")).map_err(|e| Fault::new(e.to_string()))?,
                    )
                    .into_owned()
                }
                _ => req_str(args, "authKey")?,
            };
            plain!(
                Apns::new(key, req_str(args, "authKeyId")?, req_str(args, "teamId")?, req_str(args, "bundleId")?)
                    .with_sandbox(args.get("sandbox").and_then(Value::as_bool).unwrap_or(false))
            )
        }
        "fcm" => {
            let account = match args.get("serviceAccount") {
                Some(account) => service_account(account)?,
                None => req_str(args, "serviceAccountJSON")?,
            };
            plain!(Fcm::new(account))
        }
        "discord" => match DiscordWebhook::new(req_str(args, "webhookURL")?) {
            Ok(discord) => plain!(discord),
            Err(e) => return Ok(Err(e)),
        },
        "geosms" => {
            let default = s
                .get::<Entry>(args.get("defaultAdapter").ok_or_else(|| Fault::new("defaultAdapter"))?)?
                .adapter
                .clone();
            let geo = Arc::new(GeoSms::new(default));
            Entry { adapter: geo.clone(), special: Special::Geo(geo), router: None, ports: Vec::new() }
        }
        "scripted" => {
            let scripted = Arc::new(Scripted {
                name: arg_str(args, "name")?.unwrap_or_else(|| "Test".into()),
                max: args.get("max").and_then(Value::as_i64).unwrap_or(100),
                response: args.get("response").filter(|v| !v.is_null()).cloned(),
                error: arg_str(args, "error")?,
                messages: Mutex::default(),
                base: Base::new(Http::new("Test")),
            });
            Entry { adapter: scripted.clone(), special: Special::Scripted(scripted), router: None, ports: Vec::new() }
        }
        other => return Err(Fault::new(format!("unknown adapter {other}"))),
    };
    Ok(Ok(entry))
}

/// The FCM service account JSON of `{client_email, project_id, private_key: {fixture}}`.
fn service_account(account: &Value) -> Result<String, Fault> {
    let mut entries = Vec::new();
    for (key, value) in account.as_object().ok_or_else(|| Fault::new("serviceAccount"))? {
        let value = match value.get("fixture").and_then(Value::as_str) {
            Some(name) => P::Str(php_std::Str::from(
                std::fs::read(format!("{KEYS}/{name}.pem")).map_err(|e| Fault::new(e.to_string()))?,
            )),
            None => php(value),
        };
        entries.push((key.clone(), value));
    }
    let value = P::Array(
        entries.into_iter().map(|(k, v)| (php_std::types::ArrayKey::normalize(k.as_str().into()), v)).collect(),
    );
    php_std::json::encode(&value, php_std::json::Flags::NONE, 512).map_err(|e| Fault::new(e.message().to_owned()))
}

/// A host string with `{port<n>}`, `{closed}` and `{mailpit}` filled in.
async fn resolve_host(host: &str, a: &Args<'_>, s: &Session) -> Result<(String, Vec<(String, String)>), Fault> {
    let mut host = host.to_owned();
    let mut ports = Vec::new();
    if let Some(servers) = a.opt("servers").and_then(Value::as_array) {
        for (i, server) in servers.iter().enumerate() {
            let port = s.get::<Arc<SmtpServer>>(server)?.port.to_string();
            host = host.replace(&format!("{{port{i}}}"), &port);
            ports.push((format!("127.0.0.1:{port}"), format!("127.0.0.1:{{port{i}}}")));
        }
    }
    if host.contains("{closed}") {
        let port = closed_port().await?.to_string();
        host = host.replace("{closed}", &port);
        ports.push((format!("127.0.0.1:{port}"), "127.0.0.1:{closed}".to_owned()));
    }
    if host.contains("{mailpit}") {
        host = host.replace("{mailpit}", s.service("messaging-smtp")?);
    }
    Ok((host, ports))
}

async fn exchange(
    entry: &Entry,
    a: &Args<'_>,
    run: impl Future<Output = Result<Result<Value, Error>, Fault>>,
) -> OpResult {
    if let Some(router) = &entry.router {
        router.queue_remote(a.opt("replies").and_then(Value::as_array).map(Vec::as_slice).unwrap_or_default()).await?;
    }
    let mut out = Map::new();
    match run.await? {
        Ok(mut value) => {
            // Concurrent sends report results in completion order.
            if a.opt_bool("sort")?.unwrap_or(false)
                && let Some(Value::Array(results)) = value.get_mut("results")
            {
                results.sort_by(|x, y| {
                    let recipient = |v: &Value| v.get("recipient").and_then(Value::as_str).unwrap_or("").to_owned();
                    recipient(x).cmp(&recipient(y))
                });
            }
            out.insert("result".into(), value);
        }
        Err(error) => {
            let (key, value) = error_json(&error);
            out.insert(key, value);
        }
    }
    if let Some(router) = &entry.router {
        out.insert("requests".into(), router.take(a.opt_bool("sort")?.unwrap_or(false)).await?);
    }
    let mut out = Value::Object(out);
    if !entry.ports.is_empty() {
        let mut text = serde_json::to_string(&out).unwrap_or_default();
        for (port, name) in &entry.ports {
            text = text.replace(port.as_str(), name);
        }
        out = serde_json::from_str(&text).unwrap_or(out);
    }
    Ok(Outcome::Ok(out))
}

async fn adapter_call(entry: &Entry, method: &str, args: &Value, s: &Session) -> Result<Result<Value, Error>, Fault> {
    let a = Args(args);
    Ok(match (method, &entry.special) {
        ("set_telemetry", _) => {
            let telemetry = s.get::<Arc<Recording>>(a.value("telemetry")?)?.clone();
            entry.adapter.set_telemetry(Arc::new(RecordingTelemetry(telemetry)));
            Ok(Value::Null)
        }
        ("set_local", Special::Geo(geo)) => {
            let local = s.get::<Entry>(a.value("adapter")?)?.adapter.clone();
            geo.set_local(a.str("code")?, local);
            Ok(json!(true))
        }
        ("get_endpoint", Special::MockSms(mock)) => Ok(json!(mock.endpoint())),
        ("set_endpoint", Special::MockSms(mock)) => {
            let url = a.str("url")?.replace("{fixture}", &format!("{}/{}", s.service("messaging-http")?, s.ns));
            mock.set_endpoint(url);
            Ok(json!(true))
        }
        ("disconnect", Special::Smtp(smtp)) => {
            smtp.disconnect().await;
            Ok(Value::Null)
        }
        ("upsert_template", Special::WhatsApp(w)) => {
            let languages = a
                .opt("languages")
                .and_then(Value::as_array)
                .map(|l| l.iter().filter_map(|v| v.as_str().map(str::to_owned)).collect::<Vec<_>>())
                .unwrap_or_default();
            let otp = match a.opt_str("otpType")? {
                Some(v) => OtpType::ALL.into_iter().find(|o| o.value() == v).ok_or_else(|| Fault::new("otpType"))?,
                None => OtpType::CopyCode,
            };
            let mut apps = Vec::new();
            for app in a.opt("apps").and_then(Value::as_array).into_iter().flatten() {
                match App::new(Args(app).str("package_name")?, Args(app).str("signature_hash")?) {
                    Ok(app) => apps.push(app),
                    Err(e) => return Ok(Err(e)),
                }
            }
            w.upsert_template(
                a.str("businessAccountId")?,
                &languages,
                a.opt_i64("expirationMinutes")?,
                a.opt_bool("securityRecommendation")?.unwrap_or(true),
                a.opt_i64("timeToLive")?,
                otp,
                &apps,
            )
            .await
            .map(|v| out(&v))
        }
        ("get_template", Special::WhatsApp(w)) => w
            .template(a.str("businessAccountId")?, a.opt_str("name")?)
            .await
            .map(|list| Value::Array(list.iter().map(out).collect())),
        ("delete_template", Special::WhatsApp(w)) => {
            w.delete_template(a.str("businessAccountId")?, a.opt_str("name")?).await.map(|()| Value::Null)
        }
        (other, _) => return Err(Fault::new(format!("method {other} is not available on this adapter"))),
    })
}

// ---------------------------------------------------------------------------
// Operations
// ---------------------------------------------------------------------------

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let s = session;
    Ok(match op {
        "email.new" => match build_email(args)? {
            Ok(email) => Outcome::Ok(email_out(&email)),
            Err(e) => report(&e),
        },
        "sms.new" => Outcome::Ok(sms_out(&build_sms(args)?)),
        "push.new" => match build_push(args)? {
            Ok(push) => Outcome::Ok(push_out(&push)),
            Err(e) => report(&e),
        },
        "discord.new" => Outcome::Ok(discord_out(&build_discord(args)?)),
        "attachment.new" => Outcome::Ok(attachment_out(&attachment(args)?)),
        "response.run" => {
            let mut response = Response::new(a.str("type")?);
            for step in a.opt("steps").and_then(Value::as_array).into_iter().flatten() {
                let (key, value) =
                    step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("a step"))?;
                match key.as_str() {
                    "set_delivered_to" => response.set_delivered_to(value.as_i64().unwrap_or(0)),
                    "increment" => response.increment_delivered_to(),
                    "set_type" => response.set_kind(text(value)?),
                    "add_result" => {
                        let list = value.as_array().ok_or_else(|| Fault::new("add_result"))?;
                        response.add_result(
                            text(&list[0])?,
                            list.get(1).map(text).transpose()?.unwrap_or_default(),
                            list.get(2).and_then(Value::as_bool).unwrap_or(false),
                        );
                    }
                    other => return Err(Fault::new(format!("unknown response step {other}"))),
                }
            }
            let details: Vec<Value> = response.details().iter().map(delivery_out).collect();
            Outcome::Ok(
                json!({"deliveredTo": response.delivered_to(), "type": response.kind(), "details": details, "array": response_out(&response)}),
            )
        }
        "exception.new" => {
            let reason = Reason::from_name(a.str("type")?)
                .ok_or_else(|| Fault::new("a type outside the constants (a deviation)"))?;
            let error = Error::invalid(reason, a.str("message")?, opt_text(&a, "value")?);
            Outcome::Ok(json!({
                "class": error.php_class(),
                "type": reason.as_str(),
                "value": a.opt_str("value")?,
                "message": error.message(),
                "code": 0,
            }))
        }
        "no_host_answered.new" => {
            let e = email::NoHostAnswered::new(a.str("message")?, a.bool("permanent")?);
            Outcome::Ok(
                json!({"class": email::NoHostAnswered::CLASS, "message": e.message, "permanent": e.permanent, "connection": true}),
            )
        }
        "enums" => Outcome::Ok(json!({
            "priority": ([Priority::Normal, Priority::High].map(|p| json!([p.name(), p.value()]))),
            "category": (Category::ALL.map(|c| json!([c.name(), c.value()]))),
            "otp_type": (OtpType::ALL.map(|o| json!([o.name(), o.value(), o.requires_apps()]))),
            "msg91": (Msg91Parameter::ALL.map(|p| json!([p.name(), p.value()]))),
            "whatsapp": (WhatsAppParameter::ALL.map(|p| json!([p.name(), p.value()]))),
            "reasons": (Reason::ALL.map(Reason::as_str)),
            "whatsapp_constants": [
                json!(WhatsApp::DEFAULT_LANGUAGE), json!(WhatsApp::DEFAULT_VERSION), json!(WhatsApp::CODE_MAX_LENGTH),
                json!(WhatsApp::EXPIRATION_MAX_MINUTES), json!(WhatsApp::TIME_TO_LIVE_MIN_SECONDS),
                json!(WhatsApp::TIME_TO_LIVE_MAX_SECONDS), json!(WhatsApp::TIME_TO_LIVE_DAY),
                json!(WhatsApp::CALLBACK_DATA_MAX_LENGTH), json!(WhatsApp::TEMPLATE_NAME_MAX_LENGTH), json!(WhatsApp::APPS_MAX),
                json!(App::PACKAGE_NAME_MAX_LENGTH), json!(App::SIGNATURE_HASH_LENGTH),
            ],
        })),
        "calling_code.from_phone_number" => Outcome::Ok(json!(sms::calling_code::from_phone_number(a.str("number")?))),
        "calling_code.constants" => Outcome::Ok(Value::Object(
            sms::calling_code::ALL.iter().map(|(name, code)| ((*name).to_owned(), json!(code))).collect(),
        )),
        "jwt.encode" => {
            let key = match a.value("key")? {
                Value::Object(o) => {
                    let name = o.get("fixture").and_then(Value::as_str).unwrap_or_default();
                    std::fs::read(format!("{KEYS}/{name}.pem")).map_err(|e| Fault::new(e.to_string()))?
                }
                other => crate::adapter::bytes(other).ok_or_else(|| Fault::new("key"))?,
            };
            let algorithm = a.str("algorithm")?;
            match utopia_messaging::jwt::encode(&php(a.value("payload")?), &key, algorithm, a.opt_str("key_id")?) {
                Err(e) => report(&e),
                Ok(token) => {
                    let parts: Vec<&str> = token.split('.').collect();
                    let raw = base64url(parts[2]).unwrap_or_default();
                    let signed = format!("{}.{}", parts[0], parts[1]);
                    let verify_with = a.opt_str("verify")?.unwrap_or(algorithm);
                    Outcome::Ok(json!({
                        "header": parts[0],
                        "payload": parts[1],
                        "signature": if algorithm.starts_with("ES") { json!({"length": raw.len()}) } else { json!(parts[2]) },
                        "verified": verify(signed.as_bytes(), &raw, verify_with),
                    }))
                }
            }
        }
        "bird.host_for" => match Bird::host_for(a.str("api_key")?) {
            Ok(host) => Outcome::ok(host),
            Err(e) => report(&e),
        },
        "whatsapp.app" => match App::new(a.str("package_name")?, a.str("signature_hash")?) {
            Ok(app) => Outcome::Ok(out(&app.to_array())),
            Err(e) => report(&e),
        },
        "mime.message" => {
            let email = match build_email(a.value("email")?)? {
                Ok(email) => email,
                Err(e) => return Ok(report(&e)),
            };
            let boxes = |key: &str| -> Result<Option<Vec<utopia_messaging::Mailbox>>, Fault> {
                a.opt(key).map(mailbox_list).transpose()
            };
            let to = boxes("to")?.unwrap_or_else(|| email.to().to_vec());
            let cc = boxes("cc")?.unwrap_or_default();
            let bcc = boxes("bcc")?.unwrap_or_default();
            let headers: Vec<(String, String)> = match a.opt("headers") {
                Some(Value::Object(h)) => {
                    h.iter().map(|(k, v)| Ok((k.clone(), text(v)?))).collect::<Result<_, Fault>>()?
                }
                _ => Vec::new(),
            };
            match email::mime::message(&email, &to, &cc, &bcc, &headers).and_then(|m| m.to_bytes().map_err(Error::from))
            {
                Ok(bytes) => Outcome::Ok(bytes_value(&normalize_mime(&bytes))),
                Err(e) => report(&e),
            }
        }
        "mime.addresses" => {
            let list = mailbox_list(a.value("recipients")?)?;
            match email::mime::addresses(&list) {
                Ok(addresses) => Outcome::Ok(Value::Array(
                    addresses
                        .iter()
                        .map(|ad| json!({"email": ad.email(), "name": ad.name(), "string": ad.to_string()}))
                        .collect(),
                )),
                Err(e) => report(&e),
            }
        }
        "mime.attachments" => match build_email(a.value("email")?)? {
            Err(e) => report(&e),
            Ok(email) => match email::mime::attachments(&email) {
                Ok(list) => Outcome::Ok(Value::Array(
                    list.iter()
                        .map(|at| {
                            let (content, path) = match at.source() {
                                utopia_smtp::Source::Bytes(b) => (bytes_value(b), Value::Null),
                                utopia_smtp::Source::Path(p) => (Value::Null, json!(p.to_string_lossy())),
                            };
                            json!({"name": at.name(), "type": at.mime_type(), "content": content, "path": path})
                        })
                        .collect(),
                )),
                Err(e) => report(&e),
            },
        },
        "mime.size" => match build_email(a.value("email")?)? {
            Err(e) => report(&e),
            Ok(email) => match email::mime::size(&email) {
                Ok(size) => Outcome::Ok(json!(size)),
                Err(e) => report(&e),
            },
        },
        "ses.sign" => {
            let ses = Ses::new(a.str("access_key")?, a.str("secret_key")?, a.str("region")?)
                .with_service(a.opt_str("service")?.unwrap_or("ses"));
            let signed: Vec<(String, String)> = a
                .value("signed_headers")?
                .as_object()
                .ok_or_else(|| Fault::new("signed_headers"))?
                .iter()
                .map(|(k, v)| Ok((k.clone(), text(v)?)))
                .collect::<Result<_, Fault>>()?;
            Outcome::ok(ses.sign(a.str("method")?, a.str("path")?, a.str("payload")?, &signed, a.str("amz_date")?))
        }
        "smtp.hosts" => {
            let smtp = Smtp::new(a.str("host")?, a.opt_i64("port")?.unwrap_or(25))
                .with_auto_tls(a.opt_bool("auto_tls")?.unwrap_or(false))
                .with_secure(a.opt_str("secure")?.unwrap_or(""));
            match smtp {
                Ok(smtp) => Outcome::Ok(Value::Array(
                    smtp.hosts().iter().map(|h| json!([h.host, h.port, encryption_name(h.encryption)])).collect(),
                )),
                Err(e) => report(&e),
            }
        }
        "telemetry.new" => Outcome::Ok(s.handle(Arc::new(Recording::default()))),
        "telemetry.records" => {
            Outcome::Ok(Value::Array(s.get::<Arc<Recording>>(a.value("telemetry")?)?.records.lock().unwrap().clone()))
        }
        "scripted.messages" => {
            let entry = s.get::<Entry>(a.value("adapter")?)?;
            let Special::Scripted(scripted) = &entry.special else { return Err(Fault::new("not a scripted adapter")) };
            Outcome::Ok(Value::Array(scripted.messages.lock().unwrap().iter().map(sms_out).collect()))
        }
        "smtp_server.new" => {
            let replies = a.array("replies")?.iter().map(text).collect::<Result<Vec<_>, _>>()?;
            let server = SmtpServer::start(replies).await?;
            Outcome::Ok(s.handle(Arc::new(server)))
        }
        "smtp_server.commands" => {
            let server = s.get::<Arc<SmtpServer>>(a.value("server")?)?.clone();
            let commands = server.commands().await;
            Outcome::Ok(Value::Array(
                commands
                    .into_iter()
                    .map(|c| {
                        if c.starts_with("EHLO ") || c.starts_with("HELO ") {
                            json!(format!("{}<host>", &c[..5]))
                        } else {
                            json!(c)
                        }
                    })
                    .collect(),
            ))
        }
        "adapter.new" => {
            let mode = a.opt_str("transport")?.unwrap_or("stub").to_owned();
            let name = a.str("adapter")?.to_owned();
            let empty = json!({});
            let args_value = a.opt("args").unwrap_or(&empty).clone();
            let entry = match build_adapter(&name, &args_value, &a, s, &mode).await? {
                Ok(entry) => entry,
                Err(e) => return Ok(report(&e)),
            };
            if let Some(telemetry) = a.opt("telemetry") {
                let telemetry = s.get::<Arc<Recording>>(telemetry)?.clone();
                entry.adapter.set_telemetry(Arc::new(RecordingTelemetry(telemetry)));
            }
            Outcome::Ok(s.handle(entry))
        }
        "adapter.info" => {
            let entry = s.get::<Entry>(a.value("adapter")?)?;
            let adapter = &entry.adapter;
            Outcome::Ok(json!({
                "name": adapter.name(),
                "type": adapter.kind(),
                "messageType": adapter.message_type().class(),
                "maxMessagesPerRequest": adapter.max_messages_per_request(),
            }))
        }
        "adapter.once" => {
            let empty = json!({});
            let name = a.str("adapter")?.to_owned();
            let args_value = a.opt("args").unwrap_or(&empty).clone();
            let entry = match build_adapter(&name, &args_value, &a, s, "stub").await? {
                Ok(entry) => entry,
                Err(e) => return Ok(report(&e)),
            };
            let message = build_message(a.value("message")?)?;
            exchange(&entry, &a, async {
                Ok(match message {
                    Err(e) => Err(e),
                    Ok(message) => entry.adapter.send(&message).await.map(|sent| sent_out(&sent)),
                })
            })
            .await?
        }
        "adapter.send" => {
            let entry = s.get::<Entry>(a.value("adapter")?)?;
            let message = build_message(a.value("message")?)?;
            exchange(entry, &a, async {
                Ok(match message {
                    Err(e) => Err(e),
                    Ok(message) => entry.adapter.send(&message).await.map(|sent| sent_out(&sent)),
                })
            })
            .await?
        }
        "adapter.call" => {
            let entry = s.get::<Entry>(a.value("adapter")?)?;
            let empty = json!({});
            let method = a.str("method")?;
            let call_args = a.opt("args").unwrap_or(&empty);
            exchange(entry, &a, adapter_call(entry, method, call_args, s)).await?
        }
        "mailpit.message" => Outcome::Ok(mailpit(s, a.str("subject")?).await?),
        _ => return Err(Fault::new(format!("messaging: unknown operation `{op}`"))),
    })
}

fn mailbox_list(value: &Value) -> Result<Vec<utopia_messaging::Mailbox>, Fault> {
    value
        .as_array()
        .ok_or_else(|| Fault::new("recipients must be a list"))?
        .iter()
        .map(|r| match r {
            Value::String(s) => Ok(utopia_messaging::Mailbox { email: s.clone(), name: None }),
            Value::Object(o) => Ok(utopia_messaging::Mailbox {
                email: o.get("email").map(text).transpose()?.unwrap_or_default(),
                name: o.get("name").filter(|v| !v.is_null()).map(text).transpose()?,
            }),
            other => Err(Fault::new(format!("a recipient, got {other}"))),
        })
        .collect()
}

fn encryption_name(e: utopia_smtp::Encryption) -> &'static str {
    match e {
        utopia_smtp::Encryption::None => "None",
        utopia_smtp::Encryption::Opportunistic => "Opportunistic",
        utopia_smtp::Encryption::StartTls => "StartTls",
        utopia_smtp::Encryption::Implicit => "Implicit",
    }
}

async fn mailpit(s: &Session, subject: &str) -> Result<Value, Fault> {
    let api = s.service("messaging-mailpit")?.to_owned();
    let get = |path: String| {
        let api = api.clone();
        async move {
            let body = fixture_http(&api, "GET", &path, None).await?;
            serde_json::from_slice::<Value>(&body).map_err(|e| Fault::new(format!("Mailpit answered: {e}")))
        }
    };
    let query = php_std::encoding::rawurlencode(format!("subject:\"{subject}\"").as_bytes());
    let found = get(format!("/api/v1/search?query={query}")).await?;
    let messages = found["messages"].as_array().cloned().unwrap_or_default();
    if messages.len() != 1 {
        return Err(Fault::new(format!("expected exactly one message with subject {subject}, got {}", messages.len())));
    }
    let id = messages[0]["ID"].as_str().unwrap_or_default().to_owned();
    let m = get(format!("/api/v1/message/{id}")).await?;
    let headers = get(format!("/api/v1/message/{id}/headers")).await?;
    let people = |v: &Value| -> Value {
        Value::Array(
            v.as_array().map_or_else(Vec::new, |l| l.iter().map(|p| json!([p["Name"], p["Address"]])).collect()),
        )
    };
    let mut selected = Map::new();
    if let Value::Object(h) = &headers {
        for name in ["List-Unsubscribe", "List-Unsubscribe-Post", "X-Mailer", "X-Tag"] {
            if let Some(v) = h.get(name) {
                selected.insert(name.to_owned(), v.clone());
            }
        }
    }
    Ok(json!({
        "subject": m["Subject"],
        "from": people(&json!([m["From"]])),
        "to": people(&m["To"]),
        "cc": people(&m["Cc"]),
        "bcc": people(&m["Bcc"]),
        "reply_to": people(&m["ReplyTo"]),
        "text": String::from_utf8_lossy(php_std::string::trim(m["Text"].as_str().unwrap_or("").as_bytes(), b" \t\n\r\0\x0B")),
        "html": m["HTML"],
        "attachments": m["Attachments"].as_array().map_or_else(Vec::new, |l| l.iter().map(|f| json!([f["FileName"], f["ContentType"], f["Size"]])).collect()),
        "headers": if selected.is_empty() { json!([]) } else { Value::Object(selected) },
    }))
}
