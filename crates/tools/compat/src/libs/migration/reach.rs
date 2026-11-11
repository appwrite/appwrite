//! Where requests go (TargetRequestTest, TargetDestinationsTest and
//! AppwriteClientTest): endpoint templates, the resolver double, destination
//! policies and the results read back, as `Adapter.php` and `Mocks.php` have
//! them.

use std::sync::{Arc, Mutex};

use serde_json::{Value as Json, json};
use utopia_client::{IpRange, PublicInternet};
use utopia_migration::error::{Code, Error};
use utopia_migration::target::{Destinations, Resolver};

use crate::adapter::{Fault, Session};

/// TargetRequestTest's RequestTarget: a target with an endpoint.
pub struct RequestTarget(pub utopia_migration::Target);

/// The fixture's base URL and port.
fn api(s: &Session) -> Result<(String, String), Fault> {
    let base = s.service("migration-api")?.to_owned();
    let port = base.rsplit_once(':').map(|(_, p)| p.trim_end_matches('/').to_owned()).unwrap_or_default();
    Ok((base, port))
}

/// An endpoint template: `{api}` is the migration-api fixture, `{port}` its port.
pub fn endpoint(s: &Session, template: &str) -> Result<String, Fault> {
    if !template.contains('{') {
        return Ok(template.to_owned());
    }
    let (base, port) = api(s)?;
    Ok(template.replace("{api}", &base).replace("{port}", &port))
}

/// `value` with the fixture's address read back as `{api}`, its host and port
/// as `{authority}` and its port as `{port}`.
pub fn reach(s: &Session, value: Json) -> Result<Json, Fault> {
    let (base, port) = api(s)?;
    let authority = base.split_once("://").map_or(base.as_str(), |(_, rest)| rest).trim_end_matches('/').to_owned();
    let port = format!(":{port}");
    let walk_str = |t: String| t.replace(&base, "{api}").replace(&authority, "{authority}").replace(&port, ":{port}");
    fn walk(v: Json, f: &dyn Fn(String) -> String) -> Json {
        match v {
            Json::String(t) => Json::String(f(t)),
            Json::Array(a) => Json::Array(a.into_iter().map(|x| walk(x, f)).collect()),
            Json::Object(o) => Json::Object(o.into_iter().map(|(k, x)| (k, walk(x, f))).collect()),
            other => other,
        }
    }
    Ok(walk(value, &walk_str))
}

/// `public`: the public internet; `service`: the public internet and the
/// fixture's address (the loopback, where the Rust driver reaches it).
pub fn destinations(name: &str) -> Result<Destinations, Fault> {
    let range = |r: &str| IpRange::new(r).map_err(|e| Fault::new(e.to_string()));
    Ok(match name {
        "public" => Arc::new(PublicInternet::new(Vec::<IpRange>::new())),
        "service" => Arc::new(PublicInternet::new([range("127.0.0.0/8")?, range("::1")?])),
        other => return Err(Fault::new(format!("unknown destinations {other}"))),
    })
}

/// A request's exception: its class and code, and its message unless the
/// runtimes' transports word it differently (`message` false). A refused
/// destination names the address the runtime reached and the transport's own
/// error code, so only the refusal is kept.
pub fn failure(s: &Session, e: &Error, message: bool) -> Result<Json, Fault> {
    let text = e.message();
    if text.contains("not an allowed destination") {
        return Ok(json!({ "class": e.php_class(), "code": null, "message": "not an allowed destination" }));
    }
    let code = match e.code() {
        Code::Int(i) => json!(i),
        Code::Str(c) => json!(c),
    };
    let message = if message { reach(s, json!(text))? } else { Json::Null };
    Ok(json!({ "class": e.php_class(), "code": code, "message": message }))
}

/// The resolvers of TargetRequestTest and AppwriteClientTest: `pin` connects
/// the URL's host and port to the fixture's address, `refuse` errs. Records
/// every URL it receives.
pub struct ResolverFixture {
    refuse: bool,
    pub urls: Arc<Mutex<Vec<String>>>,
}

impl ResolverFixture {
    pub fn new(mode: &str) -> Result<Self, Fault> {
        let refuse = match mode {
            "pin" => false,
            "refuse" => true,
            other => return Err(Fault::new(format!("unknown resolver mode {other}"))),
        };
        Ok(Self { refuse, urls: Arc::default() })
    }

    /// The resolver, sharing the recorded URLs.
    pub fn resolver(&self) -> Resolver {
        let (refuse, urls) = (self.refuse, self.urls.clone());
        Arc::new(move |url: &str| {
            urls.lock().unwrap_or_else(|e| e.into_inner()).push(url.to_owned());
            if refuse {
                return Err(Error::exception("Refused", 0));
            }
            // `parse_url()`'s host and port.
            let authority = url.split_once("://").map_or(url, |(_, rest)| rest);
            let authority = authority.split(['/', '?', '#']).next().unwrap_or_default();
            let (host, port) = authority.rsplit_once(':').unwrap_or((authority, ""));
            Ok(vec![format!("{host}:{port}:127.0.0.1")])
        })
    }

    pub fn recorded(&self) -> Vec<String> {
        self.urls.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }
}
