use std::any::Any;
use std::sync::Arc;

use serde_json::Value;

use crate::error::Error;
use crate::http::Http;
use crate::request::Request;
use crate::resources::{Resource, Resources};
use crate::response::Response;
use crate::route::Route;
use crate::router::PathParams;

/// What a callback receives: its params and injections, the request being
/// dispatched and its response.
pub struct Scope<'a> {
    /// The application dispatching the call.
    pub http: &'a Http,
    /// The request being dispatched.
    pub request: &'a Request,
    /// The response being built.
    pub response: &'a mut Response,
    /// The matched route (`route` injection); `None` outside a route.
    pub route: Option<&'a Route>,
    /// Path params of the match (`params` injection).
    pub path: &'a PathParams,
    /// The request's resource container (see [`Http::context`]).
    pub context: &'a Arc<Resources>,
    pub(crate) args: Vec<(String, Value)>,
    pub(crate) injected: Vec<(String, Option<Resource>)>,
}

impl Scope<'_> {
    /// A declared param's resolved value: from the path, the request or the
    /// default.
    pub fn param(&self, key: &str) -> Option<&Value> {
        self.args.iter().find(|(k, _)| k == key).map(|(_, v)| v)
    }

    /// Every declared param, in declaration order.
    pub fn params(&self) -> &[(String, Value)] {
        &self.args
    }

    /// A declared injection, as a resource of type `T`; `None` when it is not
    /// declared or not a `T`.
    pub fn resource<T: Any + Send + Sync>(&self, name: &str) -> Option<&T> {
        self.injected.iter().find(|(n, _)| n == name).and_then(|(_, r)| r.as_ref()).and_then(|r| r.downcast_ref::<T>())
    }

    /// The error being handled (`error` injection in error hooks).
    pub fn error(&self) -> Option<Arc<Error>> {
        self.context.get("error").ok().and_then(|e| e.downcast::<Error>().ok())
    }

    /// The names of the declared injections.
    pub fn injections(&self) -> impl Iterator<Item = &str> {
        self.injected.iter().map(|(n, _)| n.as_str())
    }
}
