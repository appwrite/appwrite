use crate::error::{HttpError, Result};
use crate::request::Request;
use crate::response::Response;
use crate::route::Route;
use serde_json::Value;
use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;
use utopia_di::{Container, Resource};

/// Per-action context with typed accessors for params and injections.
#[derive(Clone)]
pub struct ActionContext {
    pub request: Arc<Request>,
    pub response: Response,
    pub route: Option<Arc<Route>>,
    pub params: HashMap<String, Value>,
    pub container: Container,
    pub error: Option<Arc<HttpError>>,
}

impl fmt::Debug for ActionContext {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ActionContext")
            .field("request", &self.request)
            .field("response", &self.response)
            .field("route", &self.route)
            .field("params", &self.params)
            .field("container", &self.container)
            .field("error", &self.error)
            .finish()
    }
}

impl ActionContext {
    pub fn param_str(&self, key: &str) -> Result<String> {
        match self.params.get(key) {
            Some(Value::String(s)) => Ok(s.clone()),
            Some(v) => Ok(v.to_string().trim_matches('"').to_string()),
            None => Err(HttpError::MissingParam(key.to_string())),
        }
    }

    pub fn param_value(&self, key: &str) -> Option<&Value> {
        self.params.get(key)
    }

    pub fn request(&self) -> &Request {
        &self.request
    }

    pub fn response(&self) -> &Response {
        &self.response
    }

    pub fn resource(&self, name: &str) -> Result<Resource> {
        Ok(self.container.get(name)?)
    }

    pub fn error(&self) -> Option<&HttpError> {
        self.error.as_deref()
    }
}
