//! Stub builder (`Mock` / `ResponseTemplate`).

use std::sync::Arc;

use base64::Engine;
use serde_json::{json, Value};

use crate::matchers::{merge_matchers, Matcher};
use crate::respond::Respond;
use crate::server::MockServer;

#[derive(Debug, Clone)]
pub struct ResponseTemplate {
    status: u16,
    body: Option<String>,
    base64_body: Option<String>,
    json_body: Option<Value>,
    headers: Vec<(String, String)>,
}

impl ResponseTemplate {
    #[must_use]
    pub fn new(status: u16) -> Self {
        Self {
            status,
            body: None,
            base64_body: None,
            json_body: None,
            headers: Vec::new(),
        }
    }

    #[must_use]
    pub fn set_body_string(mut self, body: impl Into<String>) -> Self {
        self.body = Some(body.into());
        self.base64_body = None;
        self.json_body = None;
        self
    }

    #[must_use]
    pub fn set_body_bytes(mut self, body: impl AsRef<[u8]>) -> Self {
        self.base64_body = Some(base64::engine::general_purpose::STANDARD.encode(body.as_ref()));
        self.body = None;
        self.json_body = None;
        self
    }

    #[must_use]
    pub fn set_body_json(mut self, body: impl Into<Value>) -> Self {
        self.json_body = Some(body.into());
        self.body = None;
        self.base64_body = None;
        self
    }

    #[must_use]
    pub fn insert_header(mut self, name: impl Into<String>, value: impl Into<String>) -> Self {
        self.headers.push((name.into(), value.into()));
        self
    }

    pub(crate) fn to_json(&self) -> Value {
        let mut response = serde_json::Map::new();
        response.insert("status".into(), json!(self.status));
        if let Some(body) = &self.body {
            response.insert("body".into(), Value::String(body.clone()));
        }
        if let Some(body) = &self.base64_body {
            response.insert("base64Body".into(), Value::String(body.clone()));
        }
        if let Some(body) = &self.json_body {
            response.insert("jsonBody".into(), body.clone());
        }
        if !self.headers.is_empty() || self.json_body.is_some() {
            let mut map = serde_json::Map::new();
            if self.json_body.is_some() {
                map.insert(
                    "Content-Type".into(),
                    Value::String("application/json".into()),
                );
            }
            for (name, value) in &self.headers {
                map.insert(name.clone(), Value::String(value.clone()));
            }
            response.insert("headers".into(), Value::Object(map));
        }
        Value::Object(response)
    }
}

impl Respond for ResponseTemplate {
    fn respond(&self, _request: &crate::RecordedRequest) -> ResponseTemplate {
        self.clone()
    }
}

enum ResponseKind {
    Template(ResponseTemplate),
    Dynamic(Arc<dyn Respond>),
}

pub struct Mock {
    matchers: Vec<Matcher>,
    response: Option<ResponseKind>,
}

impl std::fmt::Debug for Mock {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Mock")
            .field("matchers", &self.matchers)
            .field("has_response", &self.response.is_some())
            .finish()
    }
}

impl Mock {
    #[must_use]
    pub fn given(matcher: Matcher) -> Self {
        Self {
            matchers: vec![matcher],
            response: None,
        }
    }

    #[must_use]
    pub fn and(mut self, matcher: Matcher) -> Self {
        self.matchers.push(matcher);
        self
    }

    /// Fixed response (native WireMock stub).
    #[must_use]
    pub fn respond_with(mut self, response: ResponseTemplate) -> Self {
        self.response = Some(ResponseKind::Template(response));
        self
    }

    /// Dynamic responder proxied through WireMock (for stateful / `Respond` mocks).
    #[must_use]
    pub fn respond_with_dyn(mut self, respond: impl Respond + 'static) -> Self {
        self.response = Some(ResponseKind::Dynamic(Arc::new(respond)));
        self
    }

    pub async fn mount(self, server: &MockServer) {
        let matchers = self.matchers;
        match self
            .response
            .expect("Mock::respond_with must be called before mount")
        {
            ResponseKind::Template(response) => {
                let mapping = json!({
                    "request": merge_matchers(&matchers),
                    "response": response.to_json(),
                });
                server.post_mapping(mapping).await;
            }
            ResponseKind::Dynamic(respond) => {
                server
                    .mount_respond_with(merge_matchers(&matchers), respond)
                    .await;
            }
        }
    }
}
