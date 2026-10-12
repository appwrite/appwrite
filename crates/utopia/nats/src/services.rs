//! NATS micro services: request/reply endpoints with statistics, and the
//! `$SRV.PING`, `$SRV.INFO` and `$SRV.STATS` discovery protocol.

use std::sync::{Arc, Mutex, MutexGuard, PoisonError};
use std::time::Instant;

use bytes::Bytes;
use indexmap::IndexMap;
use rand::Rng;
use serde_json::{Map, Value};

use crate::connection::Connection;
use crate::error::{CallbackError, Error, Result};
use crate::fields::Object;
use crate::headers::Headers;
use crate::message::Message;
use crate::publisher::Publisher;
use crate::subscription::Subscription;

const API_PREFIX: &str = "$SRV";
const TYPE_PREFIX: &str = "io.nats.micro.v1";

/// An endpoint handler: the reply payload for a request, or an error.
/// Return [`Error::Service`] (in the box) to reply with a specific error
/// code; any other error replies with code `500` and its message.
pub type Handler = Box<dyn FnMut(&Message) -> Result<Bytes, CallbackError> + Send>;

/// An endpoint: where it listens, its handler and running statistics.
///
/// PHP: `Utopia\NATS\Services\Endpoint`.
pub struct Endpoint {
    /// The endpoint name.
    pub name: String,
    /// The subject it listens on.
    pub subject: String,
    /// Its queue group.
    pub queue_group: String,
    /// Its metadata.
    pub metadata: IndexMap<String, String>,
    handler: Handler,
    /// Requests handled.
    pub num_requests: i64,
    /// Requests that failed.
    pub num_errors: i64,
    /// Total handling time, in nanoseconds.
    pub processing_time: i64,
    /// The last failure's message.
    pub last_error: Option<String>,
}

impl Endpoint {
    /// An endpoint without statistics yet.
    ///
    /// PHP: `new Endpoint($name, $subject, $handler, $queueGroup, $metadata)`.
    pub fn new(
        name: impl Into<String>,
        subject: impl Into<String>,
        handler: Handler,
        queue_group: impl Into<String>,
        metadata: IndexMap<String, String>,
    ) -> Self {
        Endpoint {
            name: name.into(),
            subject: subject.into(),
            queue_group: queue_group.into(),
            metadata,
            handler,
            num_requests: 0,
            num_errors: 0,
            processing_time: 0,
            last_error: None,
        }
    }

    /// Its entry in an `INFO` response.
    ///
    /// PHP: `Endpoint::info()`.
    pub fn info(&self) -> Value {
        Object::new()
            .set("name", self.name.as_str())
            .set("subject", self.subject.as_str())
            .set("queue_group", self.queue_group.as_str())
            .set("metadata", metadata_object(&self.metadata))
            .build()
    }

    /// Its entry in a `STATS` response.
    ///
    /// PHP: `Endpoint::stats()`.
    pub fn stats(&self) -> Value {
        let average = if self.num_requests > 0 { self.processing_time / self.num_requests } else { 0 };
        Object::new()
            .set("name", self.name.as_str())
            .set("subject", self.subject.as_str())
            .set("queue_group", self.queue_group.as_str())
            .set("metadata", metadata_object(&self.metadata))
            .set("num_requests", self.num_requests)
            .set("num_errors", self.num_errors)
            .set("last_error", self.last_error.as_deref().unwrap_or_default())
            .set("processing_time", self.processing_time)
            .set("average_processing_time", average)
            .build()
    }

    /// Handles a request: counts it, times the handler, and queues the
    /// reply (or the error headers) on `publisher`.
    fn handle(&mut self, message: &Message, publisher: &Publisher) {
        self.num_requests += 1;
        let start = Instant::now();
        let result = (self.handler)(message);
        self.processing_time += start.elapsed().as_nanos() as i64;
        match result {
            Ok(reply) => {
                if let Some(to) = &message.reply_to {
                    publisher.publish(to.clone(), reply, None, None);
                }
            }
            Err(error) => {
                self.num_errors += 1;
                let (code, text) = match error.downcast_ref::<Error>() {
                    Some(Error::Service { code, message }) => (code.clone(), message.clone()),
                    _ => ("500".to_owned(), error.to_string()),
                };
                self.last_error = Some(text.clone());
                if let Some(to) = &message.reply_to {
                    let mut headers = Headers::new();
                    headers.set("Nats-Service-Error", text).set("Nats-Service-Error-Code", code);
                    publisher.publish(to.clone(), Bytes::new(), None, Some(headers));
                }
            }
        }
    }
}

struct State {
    name: String,
    version: String,
    description: String,
    metadata: IndexMap<String, String>,
    id: String,
    started: String,
    endpoints: IndexMap<String, Arc<Mutex<Endpoint>>>,
    subscriptions: Vec<Subscription>,
    running: bool,
}

/// A NATS micro service. Cheap to clone: clones share the service.
///
/// Endpoints and discovery responders are subscriptions on the
/// connection; drive them with [`Connection::wait`] (or [`Service::run`]).
///
/// ```
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// use utopia_nats::services::Service;
/// # let options = utopia_nats::ConnectionOptions { connect_timeout: std::time::Duration::from_millis(200),
/// #     ..utopia_nats::ConnectionOptions::new([std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into())]) };
/// # let Ok(mut connection) = utopia_nats::Connection::connect(options).await else { return Ok(()) };
/// let service = Service::new("doc_echo", "1.0.0", "Echoes", Default::default());
/// service
///     .add_endpoint(&mut connection, "echo", "doc_echo.echo", Box::new(|msg| Ok(msg.data.clone())), None, Default::default())
///     .await?;
/// service.start(&mut connection).await?;
/// let reply = connection.request("doc_echo.echo", "hi", None, None).await?;
/// assert_eq!(reply.data, "hi");
/// service.stop(&mut connection).await;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\NATS\ConnectionOptions;
/// use Utopia\NATS\Services\Service;
/// use Utopia\NATS\Exception\ConnectionException;
///
/// try {
///     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
/// } catch (ConnectionException) {
///     return;
/// }
/// $service = new Service($connection, 'doc_echo', '1.0.0', 'Echoes');
/// $service->addEndpoint('echo', 'doc_echo.echo', fn ($msg) => $msg->data);
/// $service->start();
/// $connection->request('doc_echo.echo', 'hi')->data; // 'hi'
/// $service->stop();
/// ```
///
/// PHP: `Utopia\NATS\Services\Service`.
#[derive(Clone)]
pub struct Service {
    state: Arc<Mutex<State>>,
}

impl std::fmt::Debug for Service {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let state = self.lock();
        f.debug_struct("Service").field("name", &state.name).field("id", &state.id).finish_non_exhaustive()
    }
}

impl Service {
    /// A service that is not started yet, with a random id.
    ///
    /// PHP: `new Service($connection, $name, $version, $description, $metadata)`.
    pub fn new(
        name: impl Into<String>,
        version: impl Into<String>,
        description: impl Into<String>,
        metadata: IndexMap<String, String>,
    ) -> Self {
        let mut raw = [0u8; 11];
        rand::rng().fill_bytes(&mut raw);
        let id: String = raw.iter().map(|b| format!("{b:02X}")).collect();
        let seconds =
            std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64);
        let started = String::from_utf8_lossy(&php_std::datetime::gmdate(b"Y-m-d\\TH:i:s\\Z", seconds)).into_owned();
        Service {
            state: Arc::new(Mutex::new(State {
                name: name.into(),
                version: version.into(),
                description: description.into(),
                metadata,
                id,
                started,
                endpoints: IndexMap::new(),
                subscriptions: Vec::new(),
                running: false,
            })),
        }
    }

    fn lock(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(PoisonError::into_inner)
    }

    /// Registers an endpoint (queue group `q` unless given); subscribes it
    /// at once when the service runs.
    ///
    /// PHP: `Service::addEndpoint()`.
    pub async fn add_endpoint(
        &self,
        conn: &mut Connection,
        name: &str,
        subject: &str,
        handler: Handler,
        queue_group: Option<&str>,
        metadata: IndexMap<String, String>,
    ) -> Result<&Self> {
        self.register_endpoint(conn, name, subject, handler, queue_group, metadata).await?;
        Ok(self)
    }

    /// A group of endpoints under the subject prefix `name`, with a default
    /// queue group.
    ///
    /// PHP: `Service::addGroup()`.
    pub fn add_group(&self, name: &str, queue_group: Option<&str>) -> Group {
        Group { service: self.clone(), prefix: name.to_owned(), queue_group: queue_group.map(str::to_owned) }
    }

    /// Registers an endpoint (what [`Service::add_endpoint`] and groups
    /// call).
    ///
    /// PHP: `Service::registerEndpoint()`.
    pub async fn register_endpoint(
        &self,
        conn: &mut Connection,
        name: &str,
        subject: &str,
        handler: Handler,
        queue_group: Option<&str>,
        metadata: IndexMap<String, String>,
    ) -> Result<()> {
        let endpoint =
            Arc::new(Mutex::new(Endpoint::new(name, subject, handler, queue_group.unwrap_or("q"), metadata)));
        let running = {
            let mut state = self.lock();
            state.endpoints.insert(name.to_owned(), endpoint.clone());
            state.running
        };
        if running {
            self.subscribe_endpoint(conn, endpoint).await?;
        }
        Ok(())
    }

    /// Subscribes the endpoints and the discovery responders. The caller
    /// drives the connection.
    ///
    /// PHP: `Service::start()`.
    pub async fn start(&self, conn: &mut Connection) -> Result<&Self> {
        let (endpoints, name, id) = {
            let mut state = self.lock();
            if state.running {
                return Ok(self);
            }
            state.running = true;
            (state.endpoints.values().cloned().collect::<Vec<_>>(), state.name.clone(), state.id.clone())
        };
        for endpoint in endpoints {
            self.subscribe_endpoint(conn, endpoint).await?;
        }
        for verb in ["PING", "INFO", "STATS"] {
            for subject in [
                format!("{API_PREFIX}.{verb}"),
                format!("{API_PREFIX}.{verb}.{name}"),
                format!("{API_PREFIX}.{verb}.{name}.{id}"),
            ] {
                let service = self.clone();
                let publisher = conn.publisher();
                let handler = Box::new(move |message: Message| -> Result<(), CallbackError> {
                    if let Some(reply) = &message.reply_to {
                        let response = match verb {
                            "PING" => service.ping_response(),
                            "INFO" => service.info_response(),
                            _ => service.stats_response(),
                        };
                        publisher.publish(reply.clone(), crate::json::encode(&response)?, None, None);
                    }
                    Ok(())
                });
                let subscription = conn.subscribe(&subject, Some(handler), None).await?;
                self.lock().subscriptions.push(subscription);
            }
        }
        Ok(self)
    }

    /// Starts, then processes messages until the connection fails.
    ///
    /// PHP: `Service::run()`.
    pub async fn run(&self, conn: &mut Connection) -> Result<()> {
        self.start(conn).await?;
        conn.wait(0, None).await
    }

    /// Unsubscribes everything (ignoring failures) and stops.
    ///
    /// PHP: `Service::stop()`.
    pub async fn stop(&self, conn: &mut Connection) {
        let subscriptions = {
            let mut state = self.lock();
            state.running = false;
            std::mem::take(&mut state.subscriptions)
        };
        for subscription in subscriptions {
            let _ = conn.unsubscribe(&subscription, None).await;
        }
    }

    /// The service instance id.
    ///
    /// PHP: `Service::getId()`.
    pub fn id(&self) -> String {
        self.lock().id.clone()
    }

    /// The service name.
    ///
    /// PHP: `Service::getName()`.
    pub fn name(&self) -> String {
        self.lock().name.clone()
    }

    /// The `STATS` entry of an endpoint.
    pub fn endpoint_stats(&self, name: &str) -> Option<Value> {
        let endpoint = self.lock().endpoints.get(name).cloned()?;
        let endpoint = endpoint.lock().unwrap_or_else(PoisonError::into_inner);
        Some(endpoint.stats())
    }

    async fn subscribe_endpoint(&self, conn: &mut Connection, endpoint: Arc<Mutex<Endpoint>>) -> Result<()> {
        let (subject, queue) = {
            let endpoint = endpoint.lock().unwrap_or_else(PoisonError::into_inner);
            (endpoint.subject.clone(), endpoint.queue_group.clone())
        };
        let publisher = conn.publisher();
        let handler = Box::new(move |message: Message| -> Result<(), CallbackError> {
            endpoint.lock().unwrap_or_else(PoisonError::into_inner).handle(&message, &publisher);
            Ok(())
        });
        let subscription = conn.subscribe(&subject, Some(handler), Some(&queue)).await?;
        self.lock().subscriptions.push(subscription);
        Ok(())
    }

    fn endpoints(&self, entry: fn(&Endpoint) -> Value) -> Value {
        let endpoints: Vec<Arc<Mutex<Endpoint>>> = self.lock().endpoints.values().cloned().collect();
        Value::Array(endpoints.iter().map(|e| entry(&e.lock().unwrap_or_else(PoisonError::into_inner))).collect())
    }

    /// The `PING` response.
    pub fn ping_response(&self) -> Value {
        let state = self.lock();
        Object::new()
            .set("type", format!("{TYPE_PREFIX}.ping_response"))
            .set("name", state.name.as_str())
            .set("id", state.id.as_str())
            .set("version", state.version.as_str())
            .set("metadata", metadata_object(&state.metadata))
            .build()
    }

    /// The `INFO` response.
    pub fn info_response(&self) -> Value {
        let endpoints = self.endpoints(Endpoint::info);
        let state = self.lock();
        Object::new()
            .set("type", format!("{TYPE_PREFIX}.info_response"))
            .set("name", state.name.as_str())
            .set("id", state.id.as_str())
            .set("version", state.version.as_str())
            .set("description", state.description.as_str())
            .set("metadata", metadata_object(&state.metadata))
            .set("endpoints", endpoints)
            .build()
    }

    /// The `STATS` response.
    pub fn stats_response(&self) -> Value {
        let endpoints = self.endpoints(Endpoint::stats);
        let state = self.lock();
        Object::new()
            .set("type", format!("{TYPE_PREFIX}.stats_response"))
            .set("name", state.name.as_str())
            .set("id", state.id.as_str())
            .set("version", state.version.as_str())
            .set("started", state.started.as_str())
            .set("metadata", metadata_object(&state.metadata))
            .set("endpoints", endpoints)
            .build()
    }
}

/// A group of endpoints: a subject prefix and a default queue group,
/// inherited by nested groups.
///
/// PHP: `Utopia\NATS\Services\Group`.
#[derive(Debug, Clone)]
pub struct Group {
    service: Service,
    prefix: String,
    queue_group: Option<String>,
}

impl Group {
    /// A nested group: `prefix.name`, inheriting the queue group unless
    /// given.
    ///
    /// PHP: `Group::addGroup()`.
    pub fn add_group(&self, name: &str, queue_group: Option<&str>) -> Group {
        Group {
            service: self.service.clone(),
            prefix: format!("{}.{name}", self.prefix),
            queue_group: queue_group.map(str::to_owned).or_else(|| self.queue_group.clone()),
        }
    }

    /// Registers an endpoint at `prefix.subject` (`prefix.name` when no
    /// subject is given).
    ///
    /// PHP: `Group::addEndpoint()`.
    pub async fn add_endpoint(
        &self,
        conn: &mut Connection,
        name: &str,
        handler: Handler,
        subject: Option<&str>,
        queue_group: Option<&str>,
        metadata: IndexMap<String, String>,
    ) -> Result<&Self> {
        let subject = format!("{}.{}", self.prefix, subject.unwrap_or(name));
        let queue = queue_group.or(self.queue_group.as_deref());
        self.service.register_endpoint(conn, name, &subject, handler, queue, metadata).await?;
        Ok(self)
    }
}

/// Metadata as a JSON object (`{}` when empty, never `[]`).
fn metadata_object(metadata: &IndexMap<String, String>) -> Value {
    Value::Object(metadata.iter().map(|(k, v)| (k.clone(), Value::String(v.clone()))).collect::<Map<String, Value>>())
}
