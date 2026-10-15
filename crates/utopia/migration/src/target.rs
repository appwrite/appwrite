//! What sources and destinations share: errors, warnings, the cache, and
//! HTTP calls to the service they read or write.

use std::sync::Arc;

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::{Array, Value};

use crate::cache::Cache;
use crate::error::{Error, Result};
use crate::exception::{Exception, Warning};
use crate::transfer::{ROOT_RESOURCES, Report, ResourceSelector};

/// Seconds to wait for a remote to accept the connection.
///
/// PHP: `Target::CONNECT_TIMEOUT`.
pub const CONNECT_TIMEOUT: i64 = 10;

/// Seconds a request may go without receiving a byte before it is
/// abandoned, so transfers of any length stay alive while data arrives.
///
/// PHP: `Target::STALL_TIMEOUT` (cURL's low-speed limit of one byte a
/// second over that many seconds).
pub const STALL_TIMEOUT: i64 = 30;

/// Seconds an Appwrite SDK request may take in total (its requests are
/// single API pages and file chunks).
///
/// PHP: `Target::REQUEST_TIMEOUT`.
pub const REQUEST_TIMEOUT: i64 = 30;

/// Pins a request to addresses: it receives the request URL and returns the
/// entries (`host:port:address[,address]`) its connection must use, or an
/// error to refuse the URL.
///
/// PHP: the `\Closure(string): array<string>` of `Target::setResolver()`,
/// whose entries are cURL's `CURLOPT_RESOLVE`.
pub type Resolver = Arc<dyn Fn(&str) -> Result<Vec<String>> + Send + Sync>;

/// Where requests may connect (PHP: `Utopia\Client\Destinations`).
pub type Destinations = Arc<dyn utopia_client::Destinations>;

/// A shared [`Destinations`] as the policy a `utopia-client` client takes.
#[derive(Debug)]
pub(crate) struct Shared(pub(crate) Destinations);

impl utopia_client::Destinations for Shared {
    fn allows(&self, address: &str) -> bool {
        self.0.allows(address)
    }

    fn permits_proxy(&self) -> bool {
        self.0.permits_proxy()
    }
}

/// Downcasting of sources and destinations to their concrete types (to read
/// a mock's or a destination's own state after a transfer that owns it).
pub trait AsAny: std::any::Any {
    /// The value as `Any`.
    fn as_any(&self) -> &dyn std::any::Any;
    /// The value as `Any`, mutably.
    fn as_any_mut(&mut self) -> &mut dyn std::any::Any;
}

impl<T: std::any::Any> AsAny for T {
    fn as_any(&self) -> &dyn std::any::Any {
        self
    }

    fn as_any_mut(&mut self) -> &mut dyn std::any::Any {
        self
    }
}

/// The state every source and destination has: the cache it shares with
/// its transfer, the errors and warnings it collected, the root resource
/// of the current run, and the base URL and headers of [`Target::call`].
///
/// PHP: `Utopia\Migration\Target` (the properties; the abstract methods are
/// on [`crate::Source`] and [`crate::Destination`]).
#[derive(Default)]
pub struct Target {
    cache: Option<Arc<Cache>>,
    /// Errors collected (`getErrors()`).
    pub errors: Vec<Exception>,
    /// Warnings collected (`getWarnings()`).
    pub warnings: Vec<Warning>,
    /// Headers every [`Target::call`] sends (PHP's `$headers`, initially
    /// `Content-Type` empty).
    pub headers: IndexMap<String, String>,
    /// The base URL of [`Target::call`] paths.
    pub endpoint: String,
    /// The root resource id of the current run (`''`: all).
    pub root_resource_id: String,
    /// The root resource type of the current run.
    pub root_resource_type: String,
    /// The last report of a source (`$previousReport`).
    pub previous_report: Report,
    /// The resource selector of a destination's current run.
    pub resource_selector: Option<ResourceSelector>,
    /// Seconds [`Target::call`] waits for a connection (PHP:
    /// `static::CONNECT_TIMEOUT`, which a subclass may redeclare).
    pub connect_timeout: i64,
    /// Seconds [`Target::call`] waits without receiving a byte (PHP:
    /// `static::STALL_TIMEOUT`).
    pub stall_timeout: i64,
    /// Whether [`Target::call`] follows redirects (PHP: `$followRedirects`).
    pub follow_redirects: bool,
    /// The resolver [`Target::call`] pins each request with (PHP: `$resolver`).
    pub resolver: Option<Resolver>,
    /// Where [`Target::call`] may connect (`None`: anywhere).
    pub destinations: Option<Destinations>,
}

impl std::fmt::Debug for Target {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Target")
            .field("errors", &self.errors)
            .field("warnings", &self.warnings)
            .field("endpoint", &self.endpoint)
            .field("root_resource_id", &self.root_resource_id)
            .field("root_resource_type", &self.root_resource_type)
            .field("follow_redirects", &self.follow_redirects)
            .field("resolver", &self.resolver.is_some())
            .field("destinations", &self.destinations)
            .finish_non_exhaustive()
    }
}

/// An HTTP response of [`Target::call`]: its body, decoded when JSON.
#[derive(Debug, Clone, PartialEq)]
pub enum Body {
    /// The raw body.
    Raw(Bytes),
    /// The decoded JSON body (`json_decode($body, true)`).
    Json(Value),
}

impl Body {
    /// The decoded array, or an empty one.
    pub fn array(&self) -> Array {
        match self {
            Body::Json(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        }
    }

    /// The raw bytes (empty for a decoded body).
    pub fn bytes(&self) -> Bytes {
        match self {
            Body::Raw(b) => b.clone(),
            Body::Json(_) => Bytes::new(),
        }
    }

    /// The body as a PHP value.
    pub fn value(&self) -> Value {
        match self {
            Body::Raw(b) => Value::Str(php_std::Str::from(b.clone())),
            Body::Json(v) => v.clone(),
        }
    }
}

/// Request parameters of [`Target::call`].
pub type Params = Array;

impl Target {
    /// A target with PHP's initial headers.
    pub fn new() -> Self {
        let mut headers = IndexMap::new();
        headers.insert("Content-Type".to_owned(), String::new());
        Self { headers, connect_timeout: CONNECT_TIMEOUT, stall_timeout: STALL_TIMEOUT, ..Self::default() }
    }

    /// Shares `cache` with the transfer.
    ///
    /// PHP: `registerCache()`.
    pub fn register_cache(&mut self, cache: Arc<Cache>) {
        self.cache = Some(cache);
    }

    /// The registered cache; an `Error` before one is registered, as reading
    /// the uninitialised PHP property is.
    pub fn cache(&self) -> Result<Arc<Cache>> {
        self.cache.clone().ok_or_else(|| Error::Other {
            class: "Error".into(),
            message: "Typed property Utopia\\Migration\\Target::$cache must not be accessed before initialization"
                .into(),
            code: 0,
        })
    }

    /// Whether a cache is registered (PHP: `isset($this->cache)`).
    pub fn has_cache(&self) -> bool {
        self.cache.is_some()
    }

    /// Whether [`Target::call`] follows redirects; it does not by default,
    /// and a redirect is then an error.
    ///
    /// PHP: `setFollowRedirects()`.
    pub fn set_follow_redirects(&mut self, follow: bool) -> &mut Self {
        self.follow_redirects = follow;
        self
    }

    /// Pins each request of [`Target::call`] to the addresses `resolver`
    /// returns for its URL (`None`: DNS decides).
    ///
    /// PHP: `setResolver()`.
    pub fn set_resolver(&mut self, resolver: Option<Resolver>) -> &mut Self {
        self.resolver = resolver;
        self
    }

    /// Where the requests of [`Target::call`] may connect. Each connection
    /// and redirect hop is checked against the address it actually reached,
    /// so DNS cannot answer differently between a check and the connection.
    /// Unset, requests may connect anywhere.
    ///
    /// PHP: `setDestinations()`.
    pub fn set_destinations(&mut self, destinations: Destinations) -> &mut Self {
        self.destinations = Some(destinations);
        self
    }

    /// Collects an error.
    ///
    /// PHP: `addError()`.
    pub fn add_error(&mut self, error: Exception) {
        self.errors.push(error);
    }

    /// Collects a warning.
    ///
    /// PHP: `addWarning()`.
    pub fn add_warning(&mut self, warning: Warning) {
        self.warnings.push(warning);
    }

    /// Checks that `resource_ids` only names root resource types.
    ///
    /// PHP: `validateResourceIds()`.
    pub fn validate_resource_ids(resource_ids: &IndexMap<String, Value>) -> Result<()> {
        for t in resource_ids.keys() {
            if !ROOT_RESOURCES.contains(&t.as_str()) {
                return Err(Error::exception(
                    format!(
                        "Invalid resource type in resourceIds: {t}. Only top-level resources are supported: {}",
                        ROOT_RESOURCES.join(", ")
                    ),
                    0,
                ));
            }
        }
        Ok(())
    }

    /// Calls the service: `method` on `path` (relative to
    /// [`Target::endpoint`] unless it contains `http`), with the target's
    /// headers merged with `headers` (an empty value is not sent) and
    /// `params` as the query string (GET and HEAD, RFC 3986) or the body
    /// (JSON when `Content-Type` is `application/json`, otherwise a form in
    /// RFC 3986), pinned to the [`Target::resolver`]'s addresses and confined
    /// to the [`Target::destinations`] when set. A redirect is an error
    /// (`<status>: Redirects are not followed`) unless
    /// [`Target::follow_redirects`]; the request has no overall time limit: it
    /// fails when the connection takes over [`Target::connect_timeout`]
    /// seconds, or when no byte arrives for [`Target::stall_timeout`]
    /// seconds. A JSON response (its `Content-Type` starts with
    /// `application/json`, in any case) is decoded. A status of 400 or more
    /// is an error whose message is the JSON body re-encoded, or
    /// `<status>: <body>`. Response headers are returned with lowercased
    /// names, each with its values joined by `, `.
    ///
    /// PHP: `Target::call()`, on `Utopia\Client` and `Utopia\Psr7`'s request
    /// factory.
    pub async fn call(
        &self,
        method: &str,
        path: &str,
        headers: &[(&str, String)],
        params: &Params,
    ) -> Result<(Body, IndexMap<String, String>)> {
        let mut merged = self.headers.clone();
        for (k, v) in headers {
            merged.insert((*k).to_owned(), v.clone());
        }
        crate::http::call(self, method, path, &merged, params).await
    }
}
