//! Breached password detection (`_APP_PWNED_PASSWORDS_DSN`), a port of
//! `Appwrite\Auth\Validator\PasswordPwned` and its `None`, `Mock`, `HIBP` and
//! `Appwrite` implementations.
//!
//! - `none://`: every password is safe.
//! - `mock://`: three fixture passwords are breached (never in production).
//! - `hibp://`: the Have I Been Pwned k-anonymity range API: only the first
//!   five hex characters of the password's SHA-1 leave the server.
//! - `appwrite://secret@host[:port][/path][?tls=true]`: Appwrite's detection
//!   service, sent the SHA-1 of the password with the secret as bearer token.
//!
//! Answers are kept for an hour under PHP's cache keys. PHP keeps them in the
//! shared Redis cache; Rust keeps them in process until utopia-cache shares
//! PHP's entry format, so a cold Rust process may ask the service again.
//! Any transport failure, non-200 answer or malformed body is reported as
//! `general_pwned_passwords_unavailable` (503), as in PHP.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use bytes::Bytes;
use hmac::{Hmac, Mac};
use http_body_util::{BodyExt, Full};
use hyper_rustls::HttpsConnector;
use hyper_util::client::legacy::Client;
use hyper_util::client::legacy::connect::HttpConnector;
use hyper_util::rt::TokioExecutor;
use md5::Md5;
use serde_json::Value;
use sha1::{Digest, Sha1};
use sha2::Sha256;

use crate::{Error, ErrorType, Result};

/// `HIBP::ENDPOINT`.
pub const HIBP_ENDPOINT: &str = "https://api.pwnedpasswords.com/range";
/// `Appwrite::PATH`.
const APPWRITE_PATH: &str = "v1/detection";
const PREFIX_LENGTH: usize = 5;
const CONNECT_TIMEOUT: Duration = Duration::from_secs(3);
const REQUEST_TIMEOUT: Duration = Duration::from_secs(5);
/// `PasswordPwned::CACHE_TTL`.
const CACHE_TTL: Duration = Duration::from_secs(3600);
const CACHE_SIZE: usize = 10_000;

#[derive(Clone)]
pub enum Pwned {
    None,
    Mock,
    Hibp(Arc<Remote>),
    Appwrite(Arc<Remote>),
}

impl std::fmt::Debug for Pwned {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Pwned::None => f.write_str("Pwned::None"),
            Pwned::Mock => f.write_str("Pwned::Mock"),
            Pwned::Hibp(r) => write!(f, "Pwned::Hibp({})", r.endpoint),
            Pwned::Appwrite(r) => write!(f, "Pwned::Appwrite({})", r.endpoint),
        }
    }
}

/// What a breach service answered, as PHP caches it.
#[derive(Clone)]
enum Answer {
    /// HIBP: suffixes of the range with at least one breach.
    Range(Arc<HashMap<String, i64>>),
    /// Appwrite: `{"leaked": bool}`.
    Leaked(bool),
}

pub struct Remote {
    endpoint: String,
    secret: String,
    client: Client<HttpsConnector<HttpConnector>, Full<Bytes>>,
    cache: Mutex<HashMap<String, (Instant, Answer)>>,
}

fn unavailable() -> Error {
    Error::new(ErrorType::GeneralPwnedPasswordsUnavailable)
}

/// PHP `(int) $string`: leading whitespace, optional sign, leading digits.
fn php_int(s: &str) -> i64 {
    let t = s.trim_start_matches([' ', '\t', '\n', '\r', '\x0B', '\x0C']);
    let (sign, digits) = match t.as_bytes().first() {
        Some(b'-') => (-1, &t[1..]),
        Some(b'+') => (1, &t[1..]),
        _ => (1, t),
    };
    let end = digits.bytes().take_while(u8::is_ascii_digit).count();
    digits[..end].parse::<i64>().map(|n| sign * n).unwrap_or(if end > 0 { i64::MAX * sign } else { 0 })
}

/// Parses an HIBP range body: `SUFFIX:COUNT` lines; padding entries count 0.
fn parse_range(body: &str) -> HashMap<String, i64> {
    let mut breaches = HashMap::new();
    for line in body.split('\n') {
        let line = line.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
        let Some(separator) = line.find(':') else { continue };
        if line.is_empty() {
            continue;
        }
        let count = php_int(&line[separator + 1..]);
        if count > 0 {
            breaches.insert(line[..separator].to_ascii_uppercase(), count);
        }
    }
    breaches
}

impl Remote {
    fn new(endpoint: String, secret: String) -> std::result::Result<Self, String> {
        let mut http = HttpConnector::new();
        http.set_connect_timeout(Some(CONNECT_TIMEOUT));
        http.set_nodelay(true);
        http.enforce_http(false);
        let https = hyper_rustls::HttpsConnectorBuilder::new()
            .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
            .map_err(|e| format!("TLS setup failed: {e}"))?
            .https_or_http()
            .enable_http1()
            .wrap_connector(http);
        let client = Client::builder(TokioExecutor::new()).pool_max_idle_per_host(8).build(https);
        Ok(Self { endpoint, secret, client, cache: Mutex::new(HashMap::new()) })
    }

    fn cache_prefix(&self) -> String {
        format!("pwned-passwords:{}:", hex::encode(Md5::digest(self.endpoint.as_bytes())))
    }

    /// `PasswordPwned::remember`.
    async fn remember<F>(&self, key: String, resolve: F) -> Result<Answer>
    where
        F: Future<Output = Result<Answer>>,
    {
        if let Ok(cache) = self.cache.lock()
            && let Some((at, answer)) = cache.get(&key)
            && at.elapsed() < CACHE_TTL
        {
            return Ok(answer.clone());
        }
        let answer = resolve.await?;
        if let Ok(mut cache) = self.cache.lock() {
            if cache.len() >= CACHE_SIZE {
                cache.clear();
            }
            cache.insert(key, (Instant::now(), answer.clone()));
        }
        Ok(answer)
    }

    async fn send(&self, request: http::Request<Full<Bytes>>) -> Result<Bytes> {
        let exchange = async {
            let response = self.client.request(request).await.map_err(|_| unavailable())?;
            if response.status() != 200 {
                return Err(unavailable());
            }
            Ok(response.into_body().collect().await.map_err(|_| unavailable())?.to_bytes())
        };
        tokio::time::timeout(REQUEST_TIMEOUT, exchange).await.map_err(|_| unavailable())?
    }

    /// `HIBP::isPwned`.
    async fn hibp(&self, password: &str) -> Result<bool> {
        let hash = hex::encode_upper(Sha1::digest(password.as_bytes()));
        let (prefix, suffix) = hash.split_at(PREFIX_LENGTH);
        let key = format!("{}{prefix}", self.cache_prefix());
        let answer = self
            .remember(key, async {
                let request = http::Request::get(format!("{}/{prefix}", self.endpoint))
                    .header("user-agent", "Appwrite")
                    .header("add-padding", "true")
                    .body(Full::new(Bytes::new()))
                    .map_err(|_| unavailable())?;
                let body = self.send(request).await?;
                Ok(Answer::Range(Arc::new(parse_range(&String::from_utf8_lossy(&body)))))
            })
            .await?;
        Ok(matches!(answer, Answer::Range(r) if r.contains_key(suffix)))
    }

    /// `Appwrite::isPwned`.
    async fn appwrite(&self, password: &str) -> Result<bool> {
        let hash = hex::encode_upper(Sha1::digest(password.as_bytes()));
        let mut mac = Hmac::<Sha256>::new_from_slice(self.secret.as_bytes()).map_err(|_| unavailable())?;
        mac.update(hash.as_bytes());
        let key = format!("{}{}", self.cache_prefix(), hex::encode(mac.finalize().into_bytes()));
        let answer = self
            .remember(key, async {
                let request = http::Request::post(&self.endpoint)
                    .header("user-agent", "Appwrite")
                    .header("content-type", "application/json")
                    .header("authorization", format!("Bearer {}", self.secret))
                    .body(Full::new(Bytes::from(serde_json::json!({ "hash": hash }).to_string())))
                    .map_err(|_| unavailable())?;
                let body = self.send(request).await?;
                let json: Value = serde_json::from_slice(&body).map_err(|_| unavailable())?;
                match json.get("leaked") {
                    Some(Value::Bool(b)) if json.is_object() => Ok(Answer::Leaked(*b)),
                    _ => Err(unavailable()),
                }
            })
            .await?;
        Ok(matches!(answer, Answer::Leaked(true)))
    }
}

impl Pwned {
    pub fn from_dsn(dsn: &str, production: bool) -> std::result::Result<Self, String> {
        let scheme = dsn.split("://").next().unwrap_or("");
        match scheme {
            "none" => Ok(Pwned::None),
            "mock" if production => Err("The mock breach validator cannot be used in production.".into()),
            "mock" => Ok(Pwned::Mock),
            "hibp" => Ok(Pwned::Hibp(Arc::new(Remote::new(HIBP_ENDPOINT.to_owned(), String::new())?))),
            "appwrite" => {
                let dsn = utopia_dsn::Dsn::parse(dsn).ok_or("Invalid _APP_PWNED_PASSWORDS_DSN")?;
                let scheme = if dsn.param("tls").as_deref() == Some("true") { "https" } else { "http" };
                let port = dsn.port.map(|p| format!(":{p}")).unwrap_or_default();
                let path = dsn.path.as_deref().filter(|p| !p.is_empty()).unwrap_or(APPWRITE_PATH);
                let endpoint = format!("{scheme}://{}{port}/{path}", dsn.host);
                Ok(Pwned::Appwrite(Arc::new(Remote::new(endpoint, dsn.user.unwrap_or_default())?)))
            }
            other => Err(format!("Unknown _APP_PWNED_PASSWORDS_DSN scheme: {other}")),
        }
    }

    /// `PasswordPwned::isValid()` inverted: whether the password is breached
    /// (non-strings and passwords outside 8..256 bytes count as breached).
    pub async fn is_pwned(&self, password: &str) -> Result<bool> {
        if !(8..=256).contains(&password.len()) {
            return Ok(true);
        }
        match self {
            Pwned::None => Ok(false),
            Pwned::Mock => {
                Ok(matches!(password, "pwned-fixture-common" | "pwned-fixture-uncommon" | "pwned-fixture-rare"))
            }
            Pwned::Hibp(remote) => remote.hibp(password).await,
            Pwned::Appwrite(remote) => remote.appwrite(password).await,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_padded_ranges() {
        let r = parse_range(
            "0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:0\r\nabc:12\nbad line\n:3\n",
        );
        assert_eq!(r.get("0018A45C4D1DEF81644B54AB7F969B88D65"), Some(&1));
        assert!(!r.contains_key("00D4F6E8FA6EECAD2A3AA415EEC418D38EC"), "padding entries are not breaches");
        assert_eq!(r.get("ABC"), Some(&12));
        assert_eq!(r.get(""), Some(&3));
    }

    #[test]
    fn appwrite_endpoints_follow_the_dsn() {
        let Pwned::Appwrite(r) =
            Pwned::from_dsn("appwrite://s3cret@detect.example:8443/custom?tls=true", true).unwrap()
        else {
            panic!()
        };
        assert_eq!(r.endpoint, "https://detect.example:8443/custom");
        assert_eq!(r.secret, "s3cret");
        let Pwned::Appwrite(r) = Pwned::from_dsn("appwrite://k@detect", true).unwrap() else { panic!() };
        assert_eq!(r.endpoint, "http://detect/v1/detection");
    }

    #[test]
    fn php_int_casts() {
        assert_eq!(php_int("12"), 12);
        assert_eq!(php_int(" 7x"), 7);
        assert_eq!(php_int("x"), 0);
        assert_eq!(php_int("-3"), -3);
    }
}
