//! What sources and destinations share: errors, warnings, the cache, and
//! HTTP calls to the service they read or write.

use std::sync::Arc;

use bytes::Bytes;
use indexmap::IndexMap;
use php_std::{Array, ArrayKey, Value};

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
/// second; `utopia-client` has no such limit, so [`Target::call`] does not
/// enforce it).
pub const STALL_TIMEOUT: i64 = 30;

/// Seconds an Appwrite SDK request may take in total (its requests are
/// single API pages and file chunks).
///
/// PHP: `Target::REQUEST_TIMEOUT`.
pub const REQUEST_TIMEOUT: i64 = 30;

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
#[derive(Debug, Default)]
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
        Self { headers, ..Self::default() }
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

    /// `$params` flattened to `key[sub][...]` names (PHP's `flatten()`, for
    /// multipart bodies).
    pub fn flatten(data: &Array, prefix: &str) -> Vec<(String, Value)> {
        let mut out: IndexMap<String, Value> = IndexMap::new();
        flatten_into(data, prefix, &mut out);
        out.into_iter().collect()
    }

    /// Calls the service: `method` on `path` (relative to
    /// [`Target::endpoint`] unless it contains `http`), with the target's
    /// headers merged with `headers` and `params` as the query string (GET)
    /// or the body (JSON, multipart or form encoded, by `Content-Type`).
    /// Follows redirects. A JSON response (its `Content-Type` before `;` is
    /// `application/json`) is decoded. A status of 400 or more is an error
    /// whose message is the JSON body re-encoded, or `<status>: <body>`.
    /// Response headers are returned lowercased (names and values).
    ///
    /// PHP: `Target::call()`, on cURL.
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
        crate::http::call(&self.endpoint, method, path, &merged, params).await
    }
}

fn flatten_into(data: &Array, prefix: &str, out: &mut IndexMap<String, Value>) {
    for (k, v) in data.iter() {
        let key = match k.to_owned() {
            ArrayKey::Int(i) => i.to_string(),
            ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        };
        let final_key = if prefix.is_empty() { key } else { format!("{prefix}[{key}]") };
        match v {
            Value::Array(a) => {
                // `$output += flatten(...)`: keys already set win.
                let mut nested = IndexMap::new();
                flatten_into(a, &final_key, &mut nested);
                for (nk, nv) in nested {
                    out.entry(nk).or_insert(nv);
                }
            }
            other => {
                out.insert(final_key, other.clone());
            }
        }
    }
}
