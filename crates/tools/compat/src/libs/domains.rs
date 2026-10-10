//! Compat adapter for `domains`: maps `tests/compat/domains/spec.json` operations onto `utopia-domains`.
//!
//! Registrar adapters that call an API get a [`Recorded`] transport (the twin
//! of `tests/compat/domains/Transport.php`): cases give the responses, and
//! every call reports the requests the adapter sent.

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde_json::{Map, Value, json};
use utopia_domains::api::{Answer, Api, Params};
use utopia_domains::registrar::{
    Adapter, Availability, Config, Contact, ContactKey, Contacts, Domain as Registered, Mock, NameCom,
    NameserverUpdate, OpenSrs, Price, Purchase, RegType, Registrar, Renewal, SuggestOptions, Suggestion,
    SuggestionKind, Suggestions, TransferStatus, UpdateDetails,
};
use utopia_domains::transport::{Request, Response, Transport, TransportError};
use utopia_domains::validator::{ApexDomain, PublicDomain, RegistrableDomain};
use utopia_domains::{BoxFuture, Cache, Domain, Error, MemoryStore, NoStore};
use utopia_validators::{Input, Validator};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "domain.parse",
    "validator.is_valid",
    "validator.describe",
    "validator.allow",
    "cache.new",
    "cache.load",
    "cache.save",
    "cache.purge",
    "contact.to_array",
    "registrar.new",
    "registrar.call",
    "registrar.once",
    "registrar.bare_update_nameservers",
    "api.call",
];

fn err(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.message())
}

/// Answers each request with the next recorded response and keeps every request.
#[derive(Default)]
pub struct Recorded {
    responses: Mutex<VecDeque<Response>>,
    requests: Mutex<Vec<Request>>,
}

impl Recorded {
    fn queue(&self, responses: &[Value]) -> Result<(), Fault> {
        let mut queue = self.responses.lock().unwrap_or_else(|e| e.into_inner());
        for r in responses {
            let headers = match r.get("headers") {
                Some(Value::Object(h)) => {
                    h.iter().map(|(k, v)| (k.clone(), v.as_str().unwrap_or_default().to_owned())).collect()
                }
                _ => Vec::new(),
            };
            let status = r.get("status").and_then(Value::as_u64).unwrap_or(200);
            if !(100..=599).contains(&status) {
                return Err(Fault::new(format!("a recorded response needs a status in 100..=599, got {status}")));
            }
            queue.push_back(Response {
                status: status as u16,
                headers,
                body: r.get("body").and_then(Value::as_str).unwrap_or_default().as_bytes().to_vec(),
            });
        }
        Ok(())
    }

    /// The requests since the last call: header names lower-cased and sorted.
    fn take(&self) -> Value {
        let requests = std::mem::take(&mut *self.requests.lock().unwrap_or_else(|e| e.into_inner()));
        Value::Array(
            requests
                .into_iter()
                .map(|r| {
                    let mut headers: Vec<(String, String)> =
                        r.headers.into_iter().map(|(n, v)| (n.to_ascii_lowercase(), v)).collect();
                    headers.sort_by(|a, b| a.0.cmp(&b.0));
                    let headers: Map<String, Value> = headers.into_iter().map(|(n, v)| (n, Value::String(v))).collect();
                    json!({
                        "method": r.method,
                        "url": r.url,
                        "headers": headers,
                        "body": String::from_utf8_lossy(&r.body),
                    })
                })
                .collect(),
        )
    }
}

impl Transport for Recorded {
    fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
        self.requests.lock().unwrap_or_else(|e| e.into_inner()).push(request);
        let next = self.responses.lock().unwrap_or_else(|e| e.into_inner()).pop_front();
        Box::pin(async move { next.ok_or_else(|| TransportError("no recorded response".into())) })
    }
}

/// An adapter implementing nothing but its name, to reach the trait's defaults.
struct Bare;

impl Adapter for Bare {
    fn name(&self) -> &str {
        "bare"
    }
    async fn available(&self, _: &Config, _: &[String]) -> Result<Availability, Error> {
        unreachable!("not used")
    }
    async fn purchase(&self, _: &Config, _: &Purchase) -> Result<String, Error> {
        unreachable!("not used")
    }
    async fn suggest(&self, _: &Config, _: &[String], _: &SuggestOptions) -> Result<Suggestions, Error> {
        unreachable!("not used")
    }
    async fn tlds(&self, _: &Config) -> Result<Vec<String>, Error> {
        unreachable!("not used")
    }
    async fn domain(&self, _: &Config, _: &str) -> Result<Registered, Error> {
        unreachable!("not used")
    }
    async fn update_domain(&self, _: &Config, _: &str, _: &UpdateDetails) -> Result<bool, Error> {
        unreachable!("not used")
    }
    async fn price(&self, _: &Config, _: &str, _: i64, _: RegType, _: i64) -> Result<Price, Error> {
        unreachable!("not used")
    }
    async fn renew(&self, _: &Config, _: &str, _: i64) -> Result<Renewal, Error> {
        unreachable!("not used")
    }
    async fn transfer(&self, _: &Config, _: &str, _: &str, _: Option<f64>) -> Result<String, Error> {
        unreachable!("not used")
    }
    async fn auth_code(&self, _: &Config, _: &str) -> Result<String, Error> {
        unreachable!("not used")
    }
    async fn transfer_status(&self, _: &Config, _: &str) -> Result<TransferStatus, Error> {
        unreachable!("not used")
    }
    async fn cancel_purchase(&self, _: &Config) -> Result<bool, Error> {
        unreachable!("not used")
    }
}

enum Handle {
    Mock(Registrar<Mock>),
    NameCom(Registrar<NameCom>, Arc<Recorded>),
    OpenSrs(Registrar<OpenSrs>, Arc<Recorded>),
}

/// A PHP array with string keys as the PHP driver encodes it: `[]` when empty.
fn array(map: Map<String, Value>) -> Value {
    if map.is_empty() { Value::Array(Vec::new()) } else { Value::Object(map) }
}

fn parts(d: &Domain<'_>) -> Value {
    json!({
        "get": d.get(),
        "tld": d.tld(),
        "suffix": d.suffix(),
        "rule": d.rule(),
        "name": d.name(),
        "sub": d.sub(),
        "apex": d.apex(),
        "registerable": d.registerable(),
        "known": d.is_known(),
        "icann": d.is_icann(),
        "private": d.is_private(),
        "test": d.is_test(),
    })
}

fn validator(a: &Args<'_>) -> Result<Box<dyn Validator>, Fault> {
    Ok(match a.str("validator")? {
        "public" => Box::new(PublicDomain),
        "apex" => Box::new(ApexDomain),
        "registrable" => Box::new(RegistrableDomain),
        other => return Err(Fault::new(format!("unknown validator `{other}`"))),
    })
}

fn strings(value: Option<&Value>) -> Vec<String> {
    match value {
        Some(Value::Array(list)) => list.iter().map(|v| v.as_str().unwrap_or_default().to_owned()).collect(),
        Some(Value::String(s)) => vec![s.clone()],
        _ => Vec::new(),
    }
}

fn contact(c: &Value) -> Contact {
    let f = |k: &str| c.get(k).and_then(Value::as_str).unwrap_or_default().to_owned();
    Contact {
        firstname: f("firstname"),
        lastname: f("lastname"),
        phone: f("phone"),
        email: f("email"),
        address1: f("address1"),
        address2: f("address2"),
        address3: f("address3"),
        city: f("city"),
        state: f("state"),
        country: f("country"),
        postalcode: f("postalcode"),
        org: f("org"),
        owner: c.get("owner").and_then(Value::as_str).map(str::to_owned),
    }
}

fn contacts(a: &Value) -> Contacts {
    if let Some(c) = a.get("contact") {
        return Contacts::from(contact(c));
    }
    match a.get("contacts") {
        Some(Value::Array(list)) => Contacts::from(list.iter().map(contact).collect::<Vec<_>>()),
        Some(Value::Object(map)) => Contacts(
            map.iter()
                .map(|(k, v)| {
                    let key = match k.parse::<i64>() {
                        Ok(i) if i.to_string() == *k => ContactKey::Index(i),
                        _ => ContactKey::Role(k.clone()),
                    };
                    (key, contact(v))
                })
                .collect(),
        ),
        _ => Contacts::default(),
    }
}

fn date(d: Option<&php_std::datetime::DateTime>) -> Value {
    d.map_or(Value::Null, |d| Value::String(String::from_utf8_lossy(&d.format(b"Y-m-d\\TH:i:s.uP")).into_owned()))
}

fn registered(d: &Registered) -> Value {
    json!({
        "domain": d.domain,
        "createdAt": date(d.created_at.as_ref()),
        "expiresAt": date(d.expires_at.as_ref()),
        "autoRenew": d.auto_renew,
        "nameservers": d.nameservers,
    })
}

fn suggestion(s: &Suggestion) -> Value {
    let mut o = Map::new();
    o.insert("available".into(), s.available.into());
    o.insert("price".into(), s.price.map_or(Value::Null, float_value));
    if let Some(kind) = &s.purchase_type {
        o.insert("renewalPrice".into(), s.renewal_price.map_or(Value::Null, float_value));
        o.insert("purchaseType".into(), kind.clone().into());
    }
    o.insert("type".into(), s.kind.as_str().into());
    Value::Object(o)
}

fn nameserver_update(u: &NameserverUpdate) -> Value {
    let mut o = Map::new();
    if let Some(code) = &u.code {
        o.insert("code".into(), code.clone().into());
    }
    if let Some(text) = &u.text {
        o.insert("text".into(), text.clone().into());
    }
    o.insert("successful".into(), u.successful.into());
    if let Some(ns) = &u.nameservers {
        o.insert("nameservers".into(), ns.clone().into());
    }
    if let Some(error) = &u.error {
        o.insert("error".into(), error.clone().into());
    }
    Value::Object(o)
}

fn price(p: &Price) -> Value {
    json!({ "price": float_value(p.price), "premium": p.premium })
}

/// One registrar method, encoded as PHP's adapter encodes it.
async fn invoke<A: Adapter>(r: &Registrar<A>, method: &str, a: &Value) -> Result<Result<Value, Error>, Fault> {
    let args = Args(a);
    let domain = || args.str("domain");
    Ok(match method {
        "getName" => Ok(r.name().into()),
        "available" => r
            .available(&strings(a.get("domains")))
            .await
            .map(|m| array(m.into_iter().map(|(k, v)| (k, Value::Bool(v))).collect())),
        "purchase" => {
            let purchase = Purchase {
                domain: domain()?.to_owned(),
                contacts: contacts(a),
                period_years: args.opt_i64("periodYears")?.unwrap_or(1),
                nameservers: strings(a.get("nameservers")),
                autorenew: args.opt_bool("autorenewEnabled")?.unwrap_or(false),
                price: args.opt_f64("purchasePrice")?,
            };
            r.purchase(&purchase).await.map(Value::from)
        }
        "suggest" => {
            let options = SuggestOptions {
                tlds: strings(a.get("tlds")),
                limit: args.opt_i64("limit")?,
                filter: match args.opt_str("filterType")? {
                    None => None,
                    Some("premium") => Some(SuggestionKind::Premium),
                    Some("suggestion") => Some(SuggestionKind::Suggestion),
                    Some(other) => return Err(Fault::new(format!("filter type `{other}` is not representable"))),
                },
                price_max: args.opt_i64("priceMax")?,
                price_min: args.opt_i64("priceMin")?,
            };
            r.suggest(&strings(a.get("query")), &options)
                .await
                .map(|s| array(s.iter().map(|(d, s)| (d.clone(), suggestion(s))).collect()))
        }
        "tlds" => r.tlds().await.map(Value::from),
        "getDomain" => r.domain(domain()?).await.map(|d| registered(&d)),
        "updateDomain" => r
            .update_domain(domain()?, &UpdateDetails { auto_renew: args.opt_bool("autoRenew")? })
            .await
            .map(Value::from),
        "updateNameservers" => {
            r.update_nameservers(domain()?, &strings(a.get("nameservers"))).await.map(|u| nameserver_update(&u))
        }
        "getPrice" => {
            let reg_type = match args.opt_str("regType")? {
                None => RegType::New,
                Some(t) => {
                    RegType::parse(t).ok_or_else(|| Fault::new(format!("reg type `{t}` is not representable")))?
                }
            };
            let years = args.opt_i64("periodYears")?.unwrap_or(1);
            let ttl = args.opt_i64("ttl")?.unwrap_or(3600);
            r.price(domain()?, years, reg_type, ttl).await.map(|p| price(&p))
        }
        "renew" => r
            .renew(domain()?, args.i64("periodYears")?)
            .await
            .map(|n: Renewal| json!({ "orderId": n.order_id, "expiresAt": date(n.expires_at.as_ref()) })),
        "transfer" => {
            r.transfer(domain()?, args.str("authCode")?, args.opt_f64("purchasePrice")?).await.map(Value::from)
        }
        "getAuthCode" => r.auth_code(domain()?).await.map(Value::from),
        "checkTransferStatus" => r.transfer_status(domain()?).await.map(
            |t| json!({ "status": t.status.as_str(), "reason": t.reason, "timestamp": date(t.timestamp.as_ref()) }),
        ),
        "cancelPurchase" => r.cancel_purchase().await.map(Value::from),
        other => return Err(Fault::new(format!("unknown registrar method `{other}`"))),
    })
}

fn mock_method(mock: &Mock, method: &str, a: &Value) -> Result<Option<Value>, Fault> {
    let args = Args(a);
    Ok(Some(match method {
        "getPurchasedDomains" => mock.purchased().into(),
        "getTransferredDomains" => mock.transferred().into(),
        "reset" => {
            mock.reset();
            Value::Null
        }
        "addTakenDomain" => {
            mock.add_taken(args.str("domain")?);
            Value::Null
        }
        "addPremiumDomain" => {
            mock.add_premium(args.str("domain")?, args.f64("price")?);
            Value::Null
        }
        _ => return Ok(None),
    }))
}

fn outcome(result: Result<Value, Error>) -> Map<String, Value> {
    let mut out = Map::new();
    match result {
        Ok(v) => out.insert("result".into(), v),
        Err(e) => {
            out.insert("error".into(), json!({ "class": e.php_class(), "message": e.message(), "code": e.code() }))
        }
    };
    out
}

fn config(a: &Args<'_>, session: &Session) -> Result<Config, Fault> {
    let cache = match a.opt("cache") {
        Some(handle) => Some(session.get::<Cache>(handle)?.clone()),
        None => None,
    };
    Ok(Config {
        default_nameservers: strings(a.opt("nameservers")),
        cache,
        connect_timeout: Duration::from_secs(a.opt_i64("connectTimeout")?.unwrap_or(5).max(0) as u64),
        timeout: Duration::from_secs(a.opt_i64("timeout")?.unwrap_or(10).max(0) as u64),
    })
}

fn answer(a: Answer) -> Value {
    match a {
        Answer::Json(v) => v,
        Answer::Text(t) => Value::String(t),
    }
}

/// A registrar from `registrar.new` arguments.
fn build(a: &Args<'_>, session: &Session) -> Result<Handle, Fault> {
    let spec = a.value("adapter")?;
    let s = Args(spec);
    let config = config(a, session)?;
    Ok(match s.str("type")? {
        "mock" => Handle::Mock(Registrar::with_config(
            Mock::with(strings(spec.get("taken")), strings(spec.get("tlds")), s.opt_f64("price")?.unwrap_or(12.99)),
            config,
        )),
        "namecom" => {
            let recorded = Arc::new(Recorded::default());
            let adapter = NameCom::new(
                s.str("username")?,
                s.str("token")?,
                s.opt_str("endpoint")?.unwrap_or("https://api.name.com"),
                Some(recorded.clone()),
            );
            Handle::NameCom(Registrar::with_config(adapter, config), recorded)
        }
        "opensrs" => {
            let recorded = Arc::new(Recorded::default());
            let adapter = OpenSrs::new(
                s.str("key")?,
                s.str("username")?,
                s.str("password")?,
                s.opt_str("endpoint")?.unwrap_or("https://horizon.opensrs.net:55443"),
                Some(recorded.clone()),
            );
            Handle::OpenSrs(Registrar::with_config(adapter, config), recorded)
        }
        other => return Err(Fault::new(format!("unknown adapter `{other}`"))),
    })
}

/// One `registrar.call`: the result or error, and the requests sent.
async fn run(handle: &Handle, a: &Args<'_>) -> Result<Map<String, Value>, Fault> {
    let method = a.str("method")?;
    let empty = Value::Object(Map::new());
    let call_args = a.opt("args").unwrap_or(&empty);
    let responses = a.opt("responses").and_then(Value::as_array).map(Vec::as_slice).unwrap_or_default();
    Ok(match handle {
        Handle::Mock(r) => match mock_method(r.adapter(), method, call_args)? {
            Some(v) => outcome(Ok(v)),
            None => outcome(invoke(r, method, call_args).await?),
        },
        Handle::NameCom(r, recorded) => {
            recorded.queue(responses)?;
            let mut out = outcome(invoke(r, method, call_args).await?);
            out.insert("requests".into(), recorded.take());
            out
        }
        Handle::OpenSrs(r, recorded) => {
            recorded.queue(responses)?;
            let mut out = outcome(invoke(r, method, call_args).await?);
            out.insert("requests".into(), recorded.take());
            out
        }
    })
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "domain.parse" => match Domain::new(a.str("domain")?) {
            Ok(d) => Outcome::Ok(parts(&d)),
            Err(e) => err(&e),
        },
        "validator.is_valid" => {
            let v = validator(&a)?;
            match v.validate(Input::Json(a.opt("value").unwrap_or(&Value::Null))) {
                Ok(verdict) => Outcome::ok(verdict.valid),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        "validator.describe" => {
            let v = validator(&a)?;
            Outcome::Ok(json!({ "description": v.description(), "type": v.kind().as_str(), "array": v.is_array() }))
        }
        "validator.allow" => {
            PublicDomain::allow(strings(a.opt("domains")));
            Outcome::Ok(Value::Null)
        }
        "cache.new" => {
            let cache = match a.opt_str("store")? {
                Some("none") => Cache::new(NoStore),
                _ => Cache::new(MemoryStore::new()),
            };
            Outcome::Ok(session.handle(cache))
        }
        "cache.load" => {
            let cache = session.get::<Cache>(a.value("cache")?)?.clone();
            Outcome::Ok(cache.load(a.str("domain")?, a.i64("ttl")?).await.unwrap_or(Value::Bool(false)))
        }
        "cache.save" => {
            let cache = session.get::<Cache>(a.value("cache")?)?.clone();
            let data = a.value("data")?;
            Outcome::Ok(if cache.save(a.str("domain")?, data).await { data.clone() } else { Value::Bool(false) })
        }
        "cache.purge" => {
            let cache = session.get::<Cache>(a.value("cache")?)?.clone();
            Outcome::ok(cache.purge(a.str("domain")?).await)
        }
        "contact.to_array" => {
            let c = contact(a.value("contact")?);
            Outcome::Ok(Value::Object(
                c.fields().iter().map(|(k, v)| ((*k).to_owned(), Value::String(v.to_string()))).collect(),
            ))
        }
        "registrar.new" => {
            let handle = build(&a, session)?;
            Outcome::Ok(session.handle(handle))
        }
        "registrar.call" => {
            let handle = session.get::<Handle>(a.value("registrar")?)?;
            Outcome::Ok(Value::Object(run(handle, &a).await?))
        }
        "registrar.once" => {
            let handle = build(&a, session)?;
            Outcome::Ok(Value::Object(run(&handle, &a).await?))
        }
        "registrar.bare_update_nameservers" => {
            let r = Registrar::new(Bare);
            match r.update_nameservers(a.str("domain")?, &strings(a.opt("nameservers"))).await {
                Ok(u) => Outcome::Ok(nameserver_update(&u)),
                Err(e) => err(&e),
            }
        }
        "api.call" => {
            let api = Api::new(session.service("domains-http")?, a.str("key")?, a.str("secret")?);
            let params = match a.opt("params") {
                None => Params::none(),
                Some(Value::String(s)) => Params::Text(s.clone()),
                Some(v) => Params::Fields(v.clone()),
            };
            let headers: Vec<(String, String)> = match a.opt("headers") {
                Some(Value::Object(h)) => {
                    h.iter().map(|(k, v)| (k.clone(), v.as_str().unwrap_or_default().to_owned())).collect()
                }
                _ => Vec::new(),
            };
            let headers: Vec<(&str, &str)> = headers.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
            match api.call(a.str("method")?, a.opt_str("path")?.unwrap_or(""), &params, &headers).await {
                Ok(v) => Outcome::Ok(answer(v)),
                Err(e) => err(&e),
            }
        }
        _ => return Err(Fault::new(format!("domains: unknown operation `{op}`"))),
    })
}
