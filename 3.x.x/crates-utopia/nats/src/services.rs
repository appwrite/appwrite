//! Microservices (PHP `Utopia\NATS\Services`).

use crate::connection::Connection;
use crate::error::NatsError;
use crate::headers::Headers;
use crate::message::Message;
use crate::subscription::{MessageCallback, Subscription};
use parking_lot::Mutex;
use rand::RngCore;
use serde_json::{json, Map, Value};
use std::collections::HashMap;
use std::sync::Arc;
use std::time::Instant;

const API_PREFIX: &str = "$SRV";
const TYPE_PREFIX: &str = "io.nats.micro.v1";

#[derive(Debug, Clone)]
pub struct ServiceException {
    pub error_code: String,
    pub message: String,
}

impl std::fmt::Display for ServiceException {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for ServiceException {}

impl ServiceException {
    pub fn new(error_code: impl Into<String>, message: impl Into<String>) -> Self {
        Self {
            error_code: error_code.into(),
            message: message.into(),
        }
    }

    pub fn get_error_code(&self) -> &str {
        &self.error_code
    }
}

type Handler = Arc<dyn Fn(Message) -> Result<Vec<u8>, ServiceException> + Send + Sync>;

struct EndpointInner {
    name: String,
    subject: String,
    queue_group: String,
    metadata: Map<String, Value>,
    handler: Handler,
    num_requests: i64,
    num_errors: i64,
    processing_time: i64,
    last_error: Option<String>,
}

#[derive(Clone)]
pub struct Endpoint {
    inner: Arc<Mutex<EndpointInner>>,
}

impl std::fmt::Debug for Endpoint {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let inner = self.inner.lock();
        f.debug_struct("Endpoint")
            .field("name", &inner.name)
            .field("subject", &inner.subject)
            .finish_non_exhaustive()
    }
}

impl Endpoint {
    pub fn name(&self) -> String {
        self.inner.lock().name.clone()
    }
    pub fn subject(&self) -> String {
        self.inner.lock().subject.clone()
    }

    pub fn info(&self) -> Value {
        let inner = self.inner.lock();
        let metadata = if inner.metadata.is_empty() {
            json!({})
        } else {
            Value::Object(inner.metadata.clone())
        };
        json!({
            "name": inner.name,
            "subject": inner.subject,
            "queue_group": inner.queue_group,
            "metadata": metadata,
        })
    }

    pub fn stats(&self) -> Value {
        let inner = self.inner.lock();
        let average = if inner.num_requests > 0 {
            inner.processing_time / inner.num_requests
        } else {
            0
        };
        let metadata = if inner.metadata.is_empty() {
            json!({})
        } else {
            Value::Object(inner.metadata.clone())
        };
        json!({
            "name": inner.name,
            "subject": inner.subject,
            "queue_group": inner.queue_group,
            "metadata": metadata,
            "num_requests": inner.num_requests,
            "num_errors": inner.num_errors,
            "last_error": inner.last_error.clone().unwrap_or_default(),
            "processing_time": inner.processing_time,
            "average_processing_time": average,
        })
    }
}

struct ServiceInner {
    conn: Connection,
    name: String,
    version: String,
    description: String,
    metadata: Map<String, Value>,
    id: String,
    started: String,
    endpoints: HashMap<String, Endpoint>,
    subscriptions: Vec<Subscription>,
    running: bool,
}

#[derive(Clone)]
pub struct Service {
    inner: Arc<Mutex<ServiceInner>>,
}

impl std::fmt::Debug for Service {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let inner = self.inner.lock();
        f.debug_struct("Service")
            .field("name", &inner.name)
            .field("version", &inner.version)
            .finish_non_exhaustive()
    }
}

impl Service {
    pub fn new(conn: Connection, name: impl Into<String>, version: impl Into<String>) -> Self {
        Self::with_description(conn, name, version, "", Map::new())
    }

    pub fn with_description(
        conn: Connection,
        name: impl Into<String>,
        version: impl Into<String>,
        description: impl Into<String>,
        metadata: Map<String, Value>,
    ) -> Self {
        let mut id_bytes = [0u8; 11];
        rand::thread_rng().fill_bytes(&mut id_bytes);
        Self {
            inner: Arc::new(Mutex::new(ServiceInner {
                conn,
                name: name.into(),
                version: version.into(),
                description: description.into(),
                metadata,
                id: hex_upper(&id_bytes),
                started: gmt_stamp(),
                endpoints: HashMap::new(),
                subscriptions: Vec::new(),
                running: false,
            })),
        }
    }

    pub fn add_endpoint(
        &self,
        name: &str,
        subject: &str,
        handler: impl Fn(Message) -> Result<Vec<u8>, ServiceException> + Send + Sync + 'static,
        queue_group: Option<&str>,
        metadata: Map<String, Value>,
    ) -> Result<&Self, NatsError> {
        self.register_endpoint(name, subject, handler, queue_group, metadata)?;
        Ok(self)
    }

    pub fn add_group(&self, name: &str, queue_group: Option<&str>) -> Group {
        Group {
            service: self.clone(),
            prefix: name.to_owned(),
            queue_group: queue_group.map(str::to_owned),
        }
    }

    pub fn register_endpoint(
        &self,
        name: &str,
        subject: &str,
        handler: impl Fn(Message) -> Result<Vec<u8>, ServiceException> + Send + Sync + 'static,
        queue_group: Option<&str>,
        metadata: Map<String, Value>,
    ) -> Result<(), NatsError> {
        let endpoint = Endpoint {
            inner: Arc::new(Mutex::new(EndpointInner {
                name: name.to_owned(),
                subject: subject.to_owned(),
                queue_group: queue_group.unwrap_or("q").to_owned(),
                metadata,
                handler: Arc::new(handler),
                num_requests: 0,
                num_errors: 0,
                processing_time: 0,
                last_error: None,
            })),
        };
        let running = {
            let mut inner = self.inner.lock();
            inner.endpoints.insert(name.to_owned(), endpoint.clone());
            inner.running
        };
        if running {
            self.subscribe_endpoint(&endpoint)?;
        }
        Ok(())
    }

    pub fn start(&self) -> Result<&Self, NatsError> {
        {
            let inner = self.inner.lock();
            if inner.running {
                return Ok(self);
            }
        }
        self.inner.lock().running = true;
        let endpoints: Vec<Endpoint> = self.inner.lock().endpoints.values().cloned().collect();
        for endpoint in endpoints {
            self.subscribe_endpoint(&endpoint)?;
        }
        let this = self.clone();
        self.subscribe_discovery("PING", {
            let this = this.clone();
            Arc::new(move |msg| this.reply(&msg, &this.ping_response()))
        })?;
        self.subscribe_discovery("INFO", {
            let this = this.clone();
            Arc::new(move |msg| this.reply(&msg, &this.info_response()))
        })?;
        self.subscribe_discovery("STATS", {
            let this = this.clone();
            Arc::new(move |msg| this.reply(&msg, &this.stats_response()))
        })?;
        Ok(self)
    }

    pub fn run(&self) -> Result<(), NatsError> {
        self.start()?;
        self.inner.lock().conn.wait(0, None);
        Ok(())
    }

    pub fn stop(&self) {
        let mut inner = self.inner.lock();
        for sub in inner.subscriptions.drain(..) {
            sub.unsubscribe(None);
        }
        inner.running = false;
    }

    pub fn get_id(&self) -> String {
        self.inner.lock().id.clone()
    }

    pub fn get_name(&self) -> String {
        self.inner.lock().name.clone()
    }

    pub fn name(&self) -> String {
        self.get_name()
    }

    fn subscribe_endpoint(&self, endpoint: &Endpoint) -> Result<(), NatsError> {
        let subject = endpoint.inner.lock().subject.clone();
        let queue = endpoint.inner.lock().queue_group.clone();
        let ep = endpoint.clone();
        let this = self.clone();
        let handler: MessageCallback = Arc::new(move |msg| {
            this.handle_endpoint(&ep, msg);
        });
        let sub = self
            .inner
            .lock()
            .conn
            .subscribe(&subject, Some(handler), Some(&queue))?;
        self.inner.lock().subscriptions.push(sub);
        Ok(())
    }

    fn handle_endpoint(&self, endpoint: &Endpoint, msg: Message) {
        {
            let mut inner = endpoint.inner.lock();
            inner.num_requests += 1;
        }
        let start = Instant::now();
        let handler = endpoint.inner.lock().handler.clone();
        match handler(msg.clone()) {
            Ok(result) => {
                let nanos = start.elapsed().as_nanos() as i64;
                endpoint.inner.lock().processing_time += nanos;
                if let Some(reply) = &msg.reply_to {
                    let _ = self.inner.lock().conn.publish(reply, &result, None, None);
                }
            }
            Err(e) => {
                let nanos = start.elapsed().as_nanos() as i64;
                {
                    let mut inner = endpoint.inner.lock();
                    inner.processing_time += nanos;
                    inner.num_errors += 1;
                    inner.last_error = Some(e.message.clone());
                }
                if let Some(reply) = &msg.reply_to {
                    let mut headers = Headers::new();
                    headers.set("Nats-Service-Error", &e.message);
                    headers.set("Nats-Service-Error-Code", &e.error_code);
                    let _ = self
                        .inner
                        .lock()
                        .conn
                        .publish(reply, b"", None, Some(&headers));
                }
            }
        }
    }

    fn subscribe_discovery(&self, verb: &str, callback: MessageCallback) -> Result<(), NatsError> {
        let name = self.inner.lock().name.clone();
        let id = self.inner.lock().id.clone();
        let subjects = [
            format!("{API_PREFIX}.{verb}"),
            format!("{API_PREFIX}.{verb}.{name}"),
            format!("{API_PREFIX}.{verb}.{name}.{id}"),
        ];
        for subject in subjects {
            let cb = Arc::clone(&callback);
            let sub = self.inner.lock().conn.subscribe(&subject, Some(cb), None)?;
            self.inner.lock().subscriptions.push(sub);
        }
        Ok(())
    }

    fn reply(&self, msg: &Message, payload: &str) {
        if let Some(reply) = &msg.reply_to {
            let _ = self
                .inner
                .lock()
                .conn
                .publish(reply, payload.as_bytes(), None, None);
        }
    }

    fn metadata_object(&self) -> Value {
        let inner = self.inner.lock();
        if inner.metadata.is_empty() {
            json!({})
        } else {
            Value::Object(inner.metadata.clone())
        }
    }

    fn ping_response(&self) -> String {
        let inner = self.inner.lock();
        serde_json::to_string(&json!({
            "type": format!("{TYPE_PREFIX}.ping_response"),
            "name": inner.name,
            "id": inner.id,
            "version": inner.version,
            "metadata": if inner.metadata.is_empty() { json!({}) } else { Value::Object(inner.metadata.clone()) },
        }))
        .unwrap_or_else(|_| "{}".into())
    }

    fn info_response(&self) -> String {
        let inner = self.inner.lock();
        let endpoints: Vec<Value> = inner.endpoints.values().map(Endpoint::info).collect();
        serde_json::to_string(&json!({
            "type": format!("{TYPE_PREFIX}.info_response"),
            "name": inner.name,
            "id": inner.id,
            "version": inner.version,
            "description": inner.description,
            "metadata": if inner.metadata.is_empty() { json!({}) } else { Value::Object(inner.metadata.clone()) },
            "endpoints": endpoints,
        }))
        .unwrap_or_else(|_| "{}".into())
    }

    fn stats_response(&self) -> String {
        let inner = self.inner.lock();
        let endpoints: Vec<Value> = inner.endpoints.values().map(Endpoint::stats).collect();
        serde_json::to_string(&json!({
            "type": format!("{TYPE_PREFIX}.stats_response"),
            "name": inner.name,
            "id": inner.id,
            "version": inner.version,
            "started": inner.started,
            "metadata": if inner.metadata.is_empty() { json!({}) } else { Value::Object(inner.metadata.clone()) },
            "endpoints": endpoints,
        }))
        .unwrap_or_else(|_| "{}".into())
    }
}

#[derive(Clone, Debug)]
pub struct Group {
    service: Service,
    prefix: String,
    queue_group: Option<String>,
}

impl Group {
    pub fn add_group(&self, name: &str, queue_group: Option<&str>) -> Self {
        Self {
            service: self.service.clone(),
            prefix: format!("{}.{name}", self.prefix),
            queue_group: queue_group
                .map(str::to_owned)
                .or_else(|| self.queue_group.clone()),
        }
    }

    pub fn add_endpoint(
        &self,
        name: &str,
        handler: impl Fn(Message) -> Result<Vec<u8>, ServiceException> + Send + Sync + 'static,
        subject: Option<&str>,
        queue_group: Option<&str>,
        metadata: Map<String, Value>,
    ) -> Result<&Self, NatsError> {
        let subj = format!("{}.{}", self.prefix, subject.unwrap_or(name));
        self.service.register_endpoint(
            name,
            &subj,
            handler,
            queue_group.or(self.queue_group.as_deref()),
            metadata,
        )?;
        Ok(self)
    }
}

fn hex_upper(bytes: &[u8]) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let mut out = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        out.push(HEX[(b >> 4) as usize] as char);
        out.push(HEX[(b & 0x0f) as usize] as char);
    }
    out
}

fn gmt_stamp() -> String {
    time::OffsetDateTime::now_utc()
        .format(
            &time::format_description::parse("[year]-[month]-[day]T[hour]:[minute]:[second]Z")
                .unwrap(),
        )
        .unwrap_or_default()
}
