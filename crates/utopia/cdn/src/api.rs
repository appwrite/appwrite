//! The request every provider sends and the reply it reads back: JSON
//! bodies encoded as PHP's `json_encode()` writes them, and responses
//! decoded as `json_decode($body, true)` reads them, so a provider's checks
//! see the same arrays PHP's do.

use php_std::json::{self, Flags};
use php_std::types::{Array, ArrayKey, KeyRef, Str, Value};
use utopia_client::{Adapter, Request};

use crate::Error;

/// The client providers use unless given another: HTTP/1.1 over TCP or TLS,
/// reaching any address.
///
/// PHP: `new Client(new Curl())`.
pub type DefaultClient = utopia_client::Client<utopia_client::Http>;

/// A new [`DefaultClient`].
pub(crate) fn default_client() -> DefaultClient {
    utopia_client::Client::new(utopia_client::Http::new())
}

/// What a provider gets back from one request.
///
/// PHP: the `array{statusCode, response, error}` each provider's
/// `request()` returns.
pub(crate) struct Reply {
    /// The status code; 0 when no response came back.
    pub status: i64,
    /// The body decoded as JSON with arrays for objects; the raw body when
    /// it is not JSON; `null` when no response came back.
    pub response: Value,
    /// The transport's message when no response came back.
    pub error: Option<String>,
}

impl Reply {
    /// Whether the status is 2xx.
    pub fn is_2xx(&self) -> bool {
        (200..300).contains(&self.status)
    }

    /// The decoded body when it is an array.
    pub fn array(&self) -> Option<&Value> {
        matches!(self.response, Value::Array(_)).then_some(&self.response)
    }
}

/// Sends one request: `body`, when given, as JSON (which sets
/// `Content-Type` and `Accept` to `application/json`), then `headers`, each
/// replacing any header of that name.
///
/// A failure of the transport to get a response becomes a [`Reply`] with
/// status 0 and its message; a request that cannot be built (an API base
/// that is not a URI) is an error.
///
/// PHP: `RequestFactory::createRequest()` or `json()`, `withHeader()` for
/// each header, then `sendRequest()`, catching `ClientExceptionInterface`.
pub(crate) async fn send<C: Adapter>(
    client: &C,
    method: &str,
    url: &str,
    body: Option<&Value>,
    headers: &[(&str, &str)],
) -> Result<Reply, Error> {
    let mut request = Request::new(method, url)?;
    if let Some(body) = body {
        let encoded = json::encode(body, Flags::NONE, 512)
            .map_err(|e| Error::Http(utopia_client::Error::InvalidArgument(e.message().to_owned())))?;
        request = request.header("Content-Type", "application/json")?.header("Accept", "application/json")?;
        request = request.body(encoded);
    }
    for (name, value) in headers {
        request = request.header(name, value)?;
    }
    let response = match client.send(request).await {
        Ok(response) => response,
        Err(e) if e.is_client() => {
            return Ok(Reply { status: 0, response: Value::Null, error: Some(e.message().to_owned()) });
        }
        Err(e) => return Err(Error::Http(e)),
    };
    let contents = response.bytes();
    let decoded = json::decode(contents, Some(true), 512, Flags::THROW_ON_ERROR)
        .unwrap_or_else(|_| Value::Str(Str::copy_from(contents)));
    Ok(Reply { status: i64::from(response.status()), response: decoded, error: None })
}

/// `$value[$key] ?? null` for an array; `None` for anything else.
pub(crate) fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::normalize(key.as_bytes())).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// `$value[$index] ?? null` for an array; `None` for anything else.
pub(crate) fn at(value: &Value, index: i64) -> Option<&Value> {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::Int(index)).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// `$value[$a][$b]...` through nested arrays, `null` anywhere being `None`.
pub(crate) fn path<'a>(value: &'a Value, keys: &[&str]) -> Option<&'a Value> {
    keys.iter().try_fold(value, |v, k| get(v, k))
}

/// The string, when the value is one.
pub(crate) fn string(value: Option<&Value>) -> Option<&str> {
    match value {
        Some(Value::Str(s)) => s.as_str(),
        _ => None,
    }
}

/// The elements of an array, in order (none for anything else).
pub(crate) fn elements(value: Option<&Value>) -> impl Iterator<Item = &Value> {
    let array = match value {
        Some(Value::Array(a)) => Some(a),
        _ => None,
    };
    array.into_iter().flat_map(|a| a.values())
}

/// A query string of these pairs, as `http_build_query()` writes a flat
/// array of strings (`urlencode()`d keys and values joined by `&`).
pub(crate) fn query(pairs: &[(&str, &str)]) -> String {
    let mut out = String::new();
    for (key, value) in pairs {
        if !out.is_empty() {
            out.push('&');
        }
        out.push_str(&php_std::encoding::urlencode(key.as_bytes()));
        out.push('=');
        out.push_str(&php_std::encoding::urlencode(value.as_bytes()));
    }
    out
}

/// `['key' => value, ...]`.
pub(crate) fn object<const N: usize>(entries: [(&str, Value); N]) -> Value {
    Value::Array(Array::from_entries(entries.map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)), None))
}

/// `[value, ...]`.
pub(crate) fn list(values: Vec<Value>) -> Value {
    Value::Array(Array::from_list(values))
}

/// A PHP string.
pub(crate) fn text(value: &str) -> Value {
    Value::Str(Str::from(value))
}

/// The first message a JSON:API or Fastly error body carries:
/// `errors[0].detail`, then `errors[0].title`, then `msg`.
pub(crate) fn fastly_message(reply: &Reply) -> Option<String> {
    if let Some(error) = &reply.error {
        return Some(error.clone());
    }
    let body = reply.array()?;
    let first = get(body, "errors").and_then(|e| at(e, 0));
    let found = first
        .and_then(|e| get(e, "detail"))
        .or_else(|| first.and_then(|e| get(e, "title")))
        .or_else(|| get(body, "msg"))?;
    Some(php_string(found))
}

/// The string PHP's `.` makes of a scalar (`1` for `true`, `Array` for an
/// array).
pub(crate) fn php_string(value: &Value) -> String {
    match value {
        Value::Array(_) | Value::Object(_) => "Array".into(),
        other => other.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default(),
    }
}
