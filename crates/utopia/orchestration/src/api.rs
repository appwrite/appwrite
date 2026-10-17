//! [`DockerApi`]: Docker through the Engine API on its Unix socket.

use std::path::PathBuf;
use std::time::{Duration, Instant};

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use php_std::json::{self, Flags};
use php_std::types::{Array, ArrayKey, KeyRef, Str, Value};
use utopia_async::net::UnixStream;

use crate::{Adapter, BoxFuture, Container, Error, Io, Network, RunOptions, Settings, Stats, filter_env_key};

/// Docker through the Engine API on its Unix socket
/// (`/var/run/docker.sock` unless [`DockerApi::with_socket`] names
/// another), one connection per request.
///
/// ```no_run
/// use utopia_orchestration::{Adapter, DockerApi};
///
/// # async fn run() -> Result<(), utopia_orchestration::Error> {
/// let docker = DockerApi::new();
/// if !docker.network_exists("runtimes").await? {
///     docker.create_network("runtimes", false).await?;
/// }
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Orchestration\Adapter\DockerAPI;
///
/// $docker = new DockerAPI();
/// // if (!$docker->networkExists('runtimes')) {
/// //     $docker->createNetwork('runtimes');
/// // }
/// ```
///
/// PHP: `Utopia\Orchestration\Adapter\DockerAPI`.
#[derive(Debug, Clone)]
pub struct DockerApi {
    settings: Settings,
    socket: PathBuf,
    registry_auth: String,
}

impl Default for DockerApi {
    /// See [`DockerApi::new`].
    fn default() -> Self {
        DockerApi {
            settings: Settings::default(),
            socket: PathBuf::from("/var/run/docker.sock"),
            registry_auth: String::new(),
        }
    }
}

/// A request body and the content type it is sent with.
struct Payload<'a> {
    content_type: &'static str,
    bytes: &'a [u8],
}

/// What came back: the status (0 when no response did) and the body
/// (`None` when no response did).
///
/// PHP: the `array{response, code}` of `call()`.
struct Reply {
    status: u16,
    body: Option<Vec<u8>>,
}

impl Reply {
    fn none() -> Self {
        Reply { status: 0, body: None }
    }

    /// The body as PHP concatenates it (`false` is empty).
    fn text(&self) -> String {
        String::from_utf8_lossy(self.body.as_deref().unwrap_or_default()).into_owned()
    }

    /// The body decoded as JSON with arrays for objects (`null` when it is
    /// not JSON).
    fn json(&self) -> Value {
        json::decode(self.body.as_deref().unwrap_or_default(), Some(true), 512, Flags::NONE).unwrap_or(Value::Null)
    }
}

/// A PHP string value.
fn text(value: &str) -> Value {
    Value::Str(Str::from(value))
}

/// `['key' => value, ...]` in order.
fn object(entries: Vec<(&str, Value)>) -> Value {
    Value::Array(Array::from_entries(entries.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), v)), None))
}

/// `$value[$key] ?? null` of an array.
fn get<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => a.get_ref(KeyRef::normalize(key.as_bytes())).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// A value as a PHP string (`""` for null, `Array` for an array).
fn string(value: Option<&Value>) -> String {
    match value {
        None | Some(Value::Null) => String::new(),
        Some(Value::Array(_) | Value::Object(_)) => "Array".into(),
        Some(v) => v.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default(),
    }
}

/// A value as a PHP number.
fn number(value: Option<&Value>) -> f64 {
    value.map_or(0.0, Value::to_double)
}

/// The JSON `json_encode()` writes for a value.
fn encode(value: &Value) -> Vec<u8> {
    json::encode(value, Flags::NONE, 512).unwrap_or_default().into_bytes()
}

impl DockerApi {
    /// An adapter on `/var/run/docker.sock`.
    ///
    /// PHP: `new DockerAPI()`.
    pub fn new() -> Self {
        DockerApi::default()
    }

    /// An adapter with registry credentials (for Docker Hub,
    /// `index.docker.io/v1/`), kept only when all three are non-empty.
    ///
    /// PHP: `new DockerAPI($username, $password, $email)`. PHP passes them
    /// to cURL as a header line without a name, which cURL drops, so pulls
    /// never send them; neither does this adapter.
    pub fn with_credentials(username: &str, password: &str, email: &str) -> Self {
        let truthy = |s: &str| !s.is_empty() && s != "0";
        let mut api = DockerApi::default();
        if truthy(username) && truthy(password) && truthy(email) {
            let auth = object(vec![
                ("username", text(username)),
                ("password", text(password)),
                ("serveraddress", text("index.docker.io/v1/")),
                ("email", text(email)),
            ]);
            api.registry_auth = php_std::encoding::base64_encode(&encode(&auth));
        }
        api
    }

    /// The same adapter on another socket.
    pub fn with_socket(mut self, socket: impl Into<PathBuf>) -> Self {
        self.socket = socket.into();
        self
    }

    /// The `X-Registry-Auth` value the credentials make (empty without
    /// them).
    pub fn registry_auth(&self) -> &str {
        &self.registry_auth
    }

    async fn connect(&self) -> Option<hyper::client::conn::http1::SendRequest<Full<Bytes>>> {
        let stream = UnixStream::connect(&self.socket).await.ok()?;
        let (sender, connection) =
            hyper::client::conn::http1::handshake(utopia_async::hyper::Io::new(stream)).await.ok()?;
        utopia_async::spawn(async move {
            let _ = connection.await;
        });
        Some(sender)
    }

    fn request(method: &str, path: &str, payload: Option<&Payload<'_>>) -> Option<hyper::Request<Full<Bytes>>> {
        let mut builder =
            hyper::Request::builder().method(method).uri(path).header("Host", "utopia-php").header("Accept", "*/*");
        let body = match payload {
            Some(p) => {
                builder = builder.header("Content-Type", p.content_type).header("Content-Length", p.bytes.len());
                Bytes::copy_from_slice(p.bytes)
            }
            None => Bytes::new(),
        };
        builder.body(Full::new(body)).ok()
    }

    /// One request; status 0 and no body when it could not be sent or
    /// answered in time.
    ///
    /// PHP: `call($url, $method, $body, $headers, $timeout)`.
    async fn call(&self, method: &str, path: &str, payload: Option<Payload<'_>>, timeout: i64) -> Reply {
        let exchange = async {
            let mut sender = self.connect().await?;
            let request = Self::request(method, path, payload.as_ref())?;
            let response = sender.send_request(request).await.ok()?;
            let status = response.status().as_u16();
            let body = response.into_body().collect().await.ok()?.to_bytes();
            Some(Reply { status, body: Some(body.to_vec()) })
        };
        let reply = match timeout {
            t if t > 0 => utopia_async::timeout(Duration::from_secs(t as u64), exchange).await.ok().flatten(),
            _ => exchange.await,
        };
        reply.unwrap_or_else(Reply::none)
    }

    /// Starts an exec and reads its multiplexed output to the end: standard
    /// output and standard error, and the status.
    ///
    /// PHP: `streamCall($url, $timeout)`.
    async fn stream_call(&self, path: &str, timeout: i64) -> Result<(u16, Vec<u8>, Vec<u8>), Error> {
        let started = Instant::now();
        let mut received = 0usize;
        let mut stdout = Vec::new();
        let mut stderr = Vec::new();
        let mut status = 0;
        let body = br#"{"Detach":false}"#;
        let exchange = async {
            let mut sender = self
                .connect()
                .await
                .ok_or_else(|| Error::Orchestration("Curl Error: Couldn't connect to server".into()))?;
            let payload = Payload { content_type: "application/json", bytes: body };
            let request = Self::request("POST", path, Some(&payload)).ok_or_else(|| {
                Error::Orchestration("Curl Error: URL rejected: Malformed input to a URL function".into())
            })?;
            let response =
                sender.send_request(request).await.map_err(|e| Error::Orchestration(format!("Curl Error: {e}")))?;
            status = response.status().as_u16();
            let mut body = response.into_body();
            let mut pending: Vec<u8> = Vec::new();
            while let Some(frame) = body.frame().await {
                let frame = frame.map_err(|e| Error::Orchestration(format!("Curl Error: {e}")))?;
                let Some(chunk) = frame.data_ref() else {
                    continue;
                };
                received += chunk.len();
                pending.extend_from_slice(chunk);
                // Each frame: [stream, 0, 0, 0, size (big-endian u32)] then the payload.
                let mut offset = 0;
                while pending.len() - offset >= 8 {
                    let header = &pending[offset..offset + 8];
                    let size = u32::from_be_bytes([header[4], header[5], header[6], header[7]]) as usize;
                    if pending.len() - offset - 8 < size {
                        break;
                    }
                    let payload = &pending[offset + 8..offset + 8 + size];
                    if header[0] == 1 {
                        stdout.extend_from_slice(payload);
                    } else {
                        stderr.extend_from_slice(payload);
                    }
                    offset += 8 + size;
                }
                pending.drain(..offset);
            }
            Ok::<(), Error>(())
        };
        match timeout {
            t if t > 0 => match utopia_async::timeout(Duration::from_secs(t as u64), exchange).await {
                Ok(result) => result?,
                Err(_) => {
                    return Err(Error::Timeout(format!(
                        "Curl Error: Operation timed out after {} milliseconds with {received} bytes received",
                        started.elapsed().as_millis()
                    )));
                }
            },
            _ => exchange.await?,
        }
        Ok((status, stdout, stderr))
    }

    async fn create_network(&self, name: &str, internal: bool) -> Result<bool, Error> {
        let body = encode(&object(vec![("Name", text(name)), ("Internal", Value::Bool(internal))]));
        let reply = self
            .call("POST", "/networks/create", Some(Payload { content_type: "application/json", bytes: &body }), -1)
            .await;
        match reply.status {
            409 => Err(Error::Orchestration(format!("Network with name \"{name}\" already exists: {}", reply.text()))),
            201 => {
                let body = reply.text();
                Ok(!body.is_empty() && body != "0")
            }
            _ => Err(Error::Orchestration(format!("Error creating network: {}", reply.text()))),
        }
    }

    async fn remove_network(&self, name: &str) -> Result<bool, Error> {
        let reply = self.call("DELETE", &format!("/networks/{name}"), None, -1).await;
        match reply.status {
            404 => Err(Error::Orchestration(format!("Network with name \"{name}\" does not exist: {}", reply.text()))),
            204 => Ok(true),
            _ => Err(Error::Orchestration(format!("Error removing network: {}", reply.text()))),
        }
    }

    async fn network_connect(&self, container: &str, network: &str) -> Result<bool, Error> {
        let body = encode(&object(vec![("Container", text(container))]));
        let path = format!("/networks/{network}/connect");
        let reply =
            self.call("POST", &path, Some(Payload { content_type: "application/json", bytes: &body }), -1).await;
        if reply.status != 200 {
            return Err(Error::Orchestration(format!("Error attaching network: {}", reply.text())));
        }
        Ok(true)
    }

    async fn network_disconnect(&self, container: &str, network: &str, force: bool) -> Result<bool, Error> {
        let body = encode(&object(vec![("Container", text(container)), ("Force", Value::Bool(force))]));
        let path = format!("/networks/{network}/disconnect");
        let reply =
            self.call("POST", &path, Some(Payload { content_type: "application/json", bytes: &body }), -1).await;
        if reply.status != 200 {
            return Err(Error::Orchestration(format!("Error detatching network: {}", reply.text())));
        }
        Ok(true)
    }

    async fn network_exists(&self, name: &str) -> Result<bool, Error> {
        Ok(self.call("GET", &format!("/networks/{name}"), None, -1).await.status == 200)
    }

    async fn stats(&self, container: Option<&str>, filters: &[(String, String)]) -> Result<Vec<Stats>, Error> {
        // The API cannot report every container at once, so they are listed first.
        let ids: Vec<String> = match container {
            None => self.list(filters).await?.into_iter().map(|c| c.id).collect(),
            Some(container) => vec![container.to_owned()],
        };
        let mut list = Vec::new();
        for id in ids {
            let reply = self.call("GET", &format!("/containers/{id}/stats?stream=false"), None, -1).await;
            let body = reply.text();
            if reply.status != 200 || body.is_empty() || body == "0" {
                continue;
            }
            let stats = reply.json();
            let required = ["id", "precpu_stats", "cpu_stats", "memory_stats", "networks"];
            if required.iter().any(|k| get(&stats, k).is_none()) {
                continue;
            }
            let path = |keys: &[&str]| keys.iter().try_fold(&stats, |v, k| get(v, k));
            let cpu_delta = number(path(&["cpu_stats", "cpu_usage", "total_usage"]))
                - number(path(&["precpu_stats", "cpu_usage", "total_usage"]));
            let system_delta =
                number(path(&["cpu_stats", "system_cpu_usage"])) - number(path(&["precpu_stats", "system_cpu_usage"]));
            let cpus = number(path(&["cpu_stats", "online_cpus"]));
            let cpu_usage = if system_delta > 0.0 && cpu_delta > 0.0 { cpu_delta / system_delta * cpus } else { 0.0 };
            let limit = number(path(&["memory_stats", "limit"]));
            let usage = number(path(&["memory_stats", "usage"]));
            let memory_usage = if limit > 0.0 && usage > 0.0 { usage / limit * 100.0 } else { 0.0 };
            let mut network_io = Io::default();
            if let Some(Value::Array(networks)) = get(&stats, "networks") {
                for network in networks.values() {
                    network_io.input += number(get(network, "rx_bytes"));
                    network_io.output += number(get(network, "tx_bytes"));
                }
            }
            let mut disk_io = Io::default();
            if let Some(Value::Array(entries)) = path(&["blkio_stats", "io_service_bytes_recursive"]) {
                for entry in entries.values() {
                    match string(get(entry, "op")).as_str() {
                        "Read" => disk_io.input += number(get(entry, "value")),
                        "Write" => disk_io.output += number(get(entry, "value")),
                        _ => {}
                    }
                }
            }
            list.push(Stats {
                container_id: string(get(&stats, "id")),
                container_name: string(get(&stats, "name")).trim_start_matches('/').to_owned(),
                cpu_usage,
                memory_usage,
                disk_io,
                memory_io: Io { input: usage, output: number(path(&["memory_stats", "max_usage"])) },
                network_io,
            });
        }
        Ok(list)
    }

    async fn list_networks(&self) -> Result<Vec<Network>, Error> {
        let reply = self.call("GET", "/networks", None, -1).await;
        if reply.status != 200 {
            return Err(Error::Orchestration(reply.text()));
        }
        let mut list = Vec::new();
        if let Value::Array(networks) = reply.json() {
            for value in networks.values() {
                if get(value, "Name").is_some() {
                    list.push(Network::new(
                        string(get(value, "Name")).replace('/', ""),
                        string(get(value, "Id")),
                        string(get(value, "Driver")),
                        string(get(value, "Scope")),
                    ));
                }
            }
        }
        Ok(list)
    }

    async fn pull(&self, image: &str) -> Result<bool, Error> {
        let body = format!("fromImage={}", php_std::encoding::urlencode(image.as_bytes()));
        let reply = self
            .call(
                "POST",
                "/images/create",
                Some(Payload { content_type: "application/x-www-form-urlencoded", bytes: body.as_bytes() }),
                -1,
            )
            .await;
        Ok(reply.status == 200 || reply.status == 204)
    }

    async fn list(&self, filters: &[(String, String)]) -> Result<Vec<Container>, Error> {
        let mut query = String::from("all=1");
        if !filters.is_empty() {
            let mut sorted: Vec<(&str, Value)> = Vec::new();
            for (key, value) in filters {
                let entry = Value::Array(Array::from_list(vec![text(value)]));
                match sorted
                    .iter_mut()
                    .find(|(k, _)| KeyRef::normalize(k.as_bytes()) == KeyRef::normalize(key.as_bytes()))
                {
                    Some(existing) => existing.1 = entry,
                    None => sorted.push((key, entry)),
                }
            }
            let filters = encode(&object(sorted));
            query.push_str("&filters=");
            query.push_str(&php_std::encoding::urlencode(&filters));
        }
        let reply = self.call("GET", &format!("/containers/json?{query}"), None, -1).await;
        if reply.status != 200 {
            return Err(Error::Orchestration(reply.text()));
        }
        let mut list = Vec::new();
        if let Value::Array(containers) = reply.json() {
            for value in containers.values() {
                let Some(name) = get(value, "Names").and_then(|n| match n {
                    Value::Array(a) => a.get_ref(KeyRef::Int(0)).filter(|v| !matches!(v, Value::Null)),
                    _ => None,
                }) else {
                    continue;
                };
                let labels = match get(value, "Labels") {
                    Some(Value::Array(labels)) => labels
                        .iter()
                        .map(|(k, v)| {
                            let key = match k {
                                KeyRef::Int(i) => i.to_string(),
                                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                            };
                            (key, string(Some(v)))
                        })
                        .collect(),
                    _ => Vec::new(),
                };
                list.push(Container::new(
                    string(Some(name)).replace('/', ""),
                    string(get(value, "Id")),
                    string(get(value, "Status")),
                    labels,
                ));
            }
        }
        Ok(list)
    }

    async fn run(&self, image: &str, name: &str, options: &RunOptions) -> Result<String, Error> {
        if self.call("GET", &format!("/images/{image}/json"), None, -1).await.status == 404 && !self.pull(image).await?
        {
            return Err(Error::Orchestration(format!("Missing image \"{image}\" and failed to pull it.")));
        }
        let settings = &self.settings;
        let mut vars: Vec<(String, String)> = Vec::new();
        for (key, value) in &options.vars {
            let key = filter_env_key(key);
            let line = format!("{key}={value}");
            match vars.iter_mut().find(|(k, _)| KeyRef::normalize(k.as_bytes()) == KeyRef::normalize(key.as_bytes())) {
                Some(existing) => existing.1 = line,
                None => vars.push((key, line)),
            }
        }
        let mut labels: Vec<(String, String)> = Vec::new();
        let mut label = |key: String, value: String| match labels
            .iter_mut()
            .find(|(k, _)| KeyRef::normalize(k.as_bytes()) == KeyRef::normalize(key.as_bytes()))
        {
            Some(existing) => existing.1 = value,
            None => labels.push((key, value)),
        };
        for (key, value) in &options.labels {
            label(key.clone(), value.clone());
        }
        label(format!("{}-type", settings.namespace), "runtime".into());
        label(format!("{}-created", settings.namespace), php_std::datetime::time().to_string());

        let empty = |s: &str| s.is_empty() || s == "0";
        let mut binds: Vec<Value> = options.volumes.iter().map(|v| text(v)).collect();
        if !empty(&options.mount_folder) {
            binds.push(text(&format!("{}:/tmp", options.mount_folder)));
        }
        let labels = Value::Object(Array::from_entries(
            labels.into_iter().map(|(k, v)| (ArrayKey::Str(Str::from(k)), text(&v))),
            None,
        ));
        let host = object(vec![
            ("Binds", Value::Array(Array::from_list(binds))),
            ("CpuQuota", Value::Float(settings.cpus * 100000.0)),
            ("CpuPeriod", Value::Int(100000)),
            ("Memory", Value::Float(settings.memory as f64 * 1e6)),
            ("MemorySwap", Value::Float(settings.swap as f64 * 1e6)),
            ("AutoRemove", Value::Bool(options.remove)),
            ("NetworkMode", if empty(&options.network) { Value::Null } else { text(&options.network) }),
            ("RestartPolicy", object(vec![("Name", text(options.restart.as_str()))])),
        ]);
        let mut fields = vec![];
        if !empty(&options.hostname) {
            fields.push(("Hostname", text(&options.hostname)));
        }
        if !empty(&options.entrypoint) {
            // A list: Engine 29 (API 1.52) decodes Entrypoint into []string only, earlier daemons accept both.
            fields.push(("Entrypoint", Value::Array(Array::from_list(vec![text(&options.entrypoint)]))));
        }
        if !empty(image) {
            fields.push(("Image", text(image)));
        }
        if !options.command.is_empty() {
            fields.push(("Cmd", Value::Array(Array::from_list(options.command.iter().map(|c| text(c)).collect()))));
        }
        if !empty(&options.workdir) {
            fields.push(("WorkingDir", text(&options.workdir)));
        }
        fields.push(("Labels", labels));
        if !vars.is_empty() {
            fields
                .push(("Env", Value::Array(Array::from_list(vars.into_iter().map(|(_, line)| text(&line)).collect()))));
        }
        fields.push(("HostConfig", host));
        let body = encode(&object(fields));

        let reply = self
            .call(
                "POST",
                &format!("/containers/create?name={name}"),
                Some(Payload { content_type: "application/json", bytes: &body }),
                -1,
            )
            .await;
        match reply.status {
            404 => return Err(Error::Orchestration(format!("Container image \"{image}\" not found."))),
            409 => return Err(Error::Orchestration(format!("Container with name \"{name}\" already exists."))),
            201 => {}
            status => {
                return Err(Error::Orchestration(format!(
                    "Failed to create function environment: {} Response Code: {status}",
                    reply.text()
                )));
            }
        }
        let id = string(get(&reply.json(), "Id"));
        let start = self
            .call(
                "POST",
                &format!("/containers/{id}/start"),
                Some(Payload { content_type: "application/x-www-form-urlencoded", bytes: b"{}" }),
                -1,
            )
            .await;
        if start.status != 204 {
            return Err(Error::Orchestration(format!("Failed to start container: {}", start.text())));
        }
        Ok(id)
    }

    async fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut Vec<u8>,
        vars: &[(String, String)],
        timeout: i64,
    ) -> Result<bool, Error> {
        let mut env: Vec<(String, String)> = Vec::new();
        for (key, value) in vars {
            let key = filter_env_key(key);
            let line = format!("{key}={value}");
            match env.iter_mut().find(|(k, _)| KeyRef::normalize(k.as_bytes()) == KeyRef::normalize(key.as_bytes())) {
                Some(existing) => existing.1 = line,
                None => env.push((key, line)),
            }
        }
        let body = encode(&object(vec![
            ("Env", Value::Array(Array::from_list(env.into_iter().map(|(_, line)| text(&line)).collect()))),
            ("Cmd", Value::Array(Array::from_list(command.iter().map(|c| text(c)).collect()))),
            ("AttachStdout", Value::Bool(true)),
            ("AttachStderr", Value::Bool(true)),
        ]));
        let reply = self
            .call(
                "POST",
                &format!("/containers/{name}/exec"),
                Some(Payload { content_type: "application/json", bytes: &body }),
                timeout,
            )
            .await;
        if reply.status != 201 {
            return Err(Error::Orchestration(format!(
                "Failed to create execute command: {} Response Code: {}",
                reply.text(),
                reply.status
            )));
        }
        let exec = string(get(&reply.json(), "Id"));
        let (status, stdout, stderr) = self.stream_call(&format!("/exec/{exec}/start"), timeout).await?;
        *output = stdout;
        output.extend_from_slice(&stderr);
        if status != 200 {
            return Err(Error::Orchestration(format!("Failed to create execute command: 1 Response Code: {status}")));
        }
        let reply = self.call("GET", &format!("/exec/{exec}/json"), None, -1).await;
        if reply.status != 200 {
            return Err(Error::Orchestration(format!(
                "Failed to inspect status of execute command: {} Response Code: {}",
                reply.text(),
                reply.status
            )));
        }
        let inspected = reply.json();
        let exit = get(&inspected, "ExitCode");
        if get(&inspected, "Running") == Some(&Value::Bool(true)) || exit != Some(&Value::Int(0)) {
            return Err(Error::Orchestration(format!("Failed to execute command. Exit code: {}", string(exit))));
        }
        Ok(true)
    }

    async fn remove(&self, name: &str, force: bool) -> Result<bool, Error> {
        let path = format!("/containers/{name}{}", if force { "?force=true" } else { "" });
        let reply = self.call("DELETE", &path, None, -1).await;
        if reply.status != 204 {
            return Err(Error::Orchestration(format!(
                "Failed to remove container: {} Response Code: {}",
                reply.text(),
                reply.status
            )));
        }
        Ok(true)
    }
}

impl Adapter for DockerApi {
    fn settings(&self) -> &Settings {
        &self.settings
    }

    fn settings_mut(&mut self) -> &mut Settings {
        &mut self.settings
    }

    /// `POST /networks/create`; fails when the name is taken.
    fn create_network<'a>(&'a self, name: &'a str, internal: bool) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.create_network(name, internal))
    }

    /// `DELETE /networks/<name>`; fails when there is none.
    fn remove_network<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.remove_network(name))
    }

    /// `POST /networks/<network>/connect`.
    fn network_connect<'a>(&'a self, container: &'a str, network: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_connect(container, network))
    }

    /// `POST /networks/<network>/disconnect`.
    fn network_disconnect<'a>(
        &'a self,
        container: &'a str,
        network: &'a str,
        force: bool,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_disconnect(container, network, force))
    }

    /// Whether `GET /networks/<name>` answers 200.
    fn network_exists<'a>(&'a self, name: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.network_exists(name))
    }

    /// `GET /networks`.
    fn list_networks<'a>(&'a self) -> BoxFuture<'a, Result<Vec<Network>, Error>> {
        Box::pin(self.list_networks())
    }

    /// A one-shot `GET /containers/<id>/stats` of the container, or of each
    /// listed one, skipping those that do not answer.
    fn stats<'a>(
        &'a self,
        container: Option<&'a str>,
        filters: &'a [(String, String)],
    ) -> BoxFuture<'a, Result<Vec<Stats>, Error>> {
        Box::pin(self.stats(container, filters))
    }

    /// `POST /images/create`; whether Docker accepted it.
    fn pull<'a>(&'a self, image: &'a str) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.pull(image))
    }

    /// `GET /containers/json?all=1` with the filters.
    fn list<'a>(&'a self, filters: &'a [(String, String)]) -> BoxFuture<'a, Result<Vec<Container>, Error>> {
        Box::pin(self.list(filters))
    }

    /// Pulls the image when missing, creates the container and starts it.
    fn run<'a>(
        &'a self,
        image: &'a str,
        name: &'a str,
        options: &'a RunOptions,
    ) -> BoxFuture<'a, Result<String, Error>> {
        Box::pin(self.run(image, name, options))
    }

    /// Creates an exec, starts it and reads its output (standard output,
    /// then standard error), then checks its exit code.
    fn execute<'a>(
        &'a self,
        name: &'a str,
        command: &'a [String],
        output: &'a mut Vec<u8>,
        vars: &'a [(String, String)],
        timeout: i64,
    ) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.execute(name, command, output, vars, timeout))
    }

    /// `DELETE /containers/<name>`.
    fn remove<'a>(&'a self, name: &'a str, force: bool) -> BoxFuture<'a, Result<bool, Error>> {
        Box::pin(self.remove(name, force))
    }
}
