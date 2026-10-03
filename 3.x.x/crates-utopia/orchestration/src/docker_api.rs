use crate::adapter::{Adapter, AdapterSettings};
use crate::error::OrchestrationError;
use crate::http::{self, Endpoint};
use crate::models::{Container, Network, Stats};
use crate::php::{filter_env_key, php_empty_str, php_http_build_query};
use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use serde_json::{json, Map, Value};
use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};

/// PHP `Utopia\Orchestration\Adapter\DockerAPI`.
#[derive(Debug, Clone)]
pub struct DockerAPI {
    settings: AdapterSettings,
    endpoint: Endpoint,
    registry_auth: String,
}

impl DockerAPI {
    /// PHP `Adapter::RESTART_NO`.
    pub const RESTART_NO: &'static str = "no";
    /// PHP `Adapter::RESTART_ALWAYS`.
    pub const RESTART_ALWAYS: &'static str = "always";
    /// PHP `Adapter::RESTART_ON_FAILURE`.
    pub const RESTART_ON_FAILURE: &'static str = "on-failure";
    /// PHP `Adapter::RESTART_UNLESS_STOPPED`.
    pub const RESTART_UNLESS_STOPPED: &'static str = "unless-stopped";

    /// PHP `__construct(?string $username = null, ?string $password = null, ?string $email = null)`.
    #[must_use]
    pub fn new(username: Option<&str>, password: Option<&str>, email: Option<&str>) -> Self {
        let registry_auth = match (username, password, email) {
            (Some(u), Some(p), Some(e)) if !u.is_empty() && !p.is_empty() && !e.is_empty() => {
                let payload = json!({
                    "username": u,
                    "password": p,
                    "serveraddress": "index.docker.io/v1/",
                    "email": e,
                });
                STANDARD.encode(payload.to_string())
            }
            _ => String::new(),
        };
        Self {
            settings: AdapterSettings::default(),
            endpoint: Endpoint::unix(),
            registry_auth,
        }
    }

    /// Use HTTP (for tests / TCP Docker) instead of the unix socket.
    #[must_use]
    pub fn with_base_url(mut self, url: impl Into<String>) -> Self {
        if let Ok(endpoint) = Endpoint::from_base_url(&url.into()) {
            self.endpoint = endpoint;
        }
        self
    }

    fn call(
        &self,
        path: &str,
        method: &str,
        body: Option<&[u8]>,
        headers: &[(&str, &str)],
        timeout: i64,
    ) -> Result<(u16, String), OrchestrationError> {
        let response = http::call(&self.endpoint, method, path, body, headers, timeout)?;
        Ok((
            response.code,
            String::from_utf8_lossy(&response.body).into_owned(),
        ))
    }

    fn stream_start(
        &self,
        path: &str,
        timeout: i64,
    ) -> Result<(u16, String, String, String), OrchestrationError> {
        let body = br#"{"Detach":false}"#;
        let headers = [
            ("Content-Type", "application/json"),
            ("Content-Length", "16"),
        ];
        let response = http::call(&self.endpoint, "POST", path, Some(body), &headers, timeout)?;
        let text = String::from_utf8_lossy(&response.body);
        let (stdout, stderr) = if response.body.len() >= 8 && response.body[1] == 0 {
            http::parse_docker_stream(&response.body)
        } else {
            (text.to_string(), String::new())
        };
        Ok((response.code, text.into_owned(), stdout, stderr))
    }
}

impl Adapter for DockerAPI {
    fn create_network(&self, name: &str, internal: bool) -> Result<bool, OrchestrationError> {
        let body = json!({ "Name": name, "Internal": internal }).to_string();
        let len = body.len().to_string();
        let (code, response) = self.call(
            "/networks/create",
            "POST",
            Some(body.as_bytes()),
            &[
                ("Content-Type", "application/json"),
                ("Content-Length", &len),
            ],
            -1,
        )?;
        if code == 409 {
            return Err(OrchestrationError::Orchestration(format!(
                "Network with name \"{name}\" already exists: {response}"
            )));
        }
        if code != 201 {
            return Err(OrchestrationError::Orchestration(format!(
                "Error creating network: {response}"
            )));
        }
        Ok(!response.is_empty())
    }

    fn remove_network(&self, name: &str) -> Result<bool, OrchestrationError> {
        let (code, response) = self.call(&format!("/networks/{name}"), "DELETE", None, &[], -1)?;
        if code == 404 {
            return Err(OrchestrationError::Orchestration(format!(
                "Network with name \"{name}\" does not exist: {response}"
            )));
        }
        if code != 204 {
            return Err(OrchestrationError::Orchestration(format!(
                "Error removing network: {response}"
            )));
        }
        Ok(true)
    }

    fn network_connect(&self, container: &str, network: &str) -> Result<bool, OrchestrationError> {
        let body = json!({ "Container": container }).to_string();
        let len = body.len().to_string();
        let (code, response) = self.call(
            &format!("/networks/{network}/connect"),
            "POST",
            Some(body.as_bytes()),
            &[
                ("Content-Type", "application/json"),
                ("Content-Length", &len),
            ],
            -1,
        )?;
        if code != 200 {
            return Err(OrchestrationError::Orchestration(format!(
                "Error attaching network: {response}"
            )));
        }
        Ok(true)
    }

    fn network_disconnect(
        &self,
        container: &str,
        network: &str,
        force: bool,
    ) -> Result<bool, OrchestrationError> {
        let body = json!({ "Container": container, "Force": force }).to_string();
        let len = body.len().to_string();
        let (code, response) = self.call(
            &format!("/networks/{network}/disconnect"),
            "POST",
            Some(body.as_bytes()),
            &[
                ("Content-Type", "application/json"),
                ("Content-Length", &len),
            ],
            -1,
        )?;
        if code != 200 {
            return Err(OrchestrationError::Orchestration(format!(
                "Error detatching network: {response}"
            )));
        }
        Ok(true)
    }

    fn network_exists(&self, name: &str) -> Result<bool, OrchestrationError> {
        let (code, _) = self.call(&format!("/networks/{name}"), "GET", None, &[], -1)?;
        Ok(code == 200)
    }

    fn get_stats(
        &self,
        container: Option<&str>,
        filters: HashMap<String, String>,
    ) -> Result<Vec<Stats>, OrchestrationError> {
        let container_ids = if let Some(id) = container {
            vec![id.to_string()]
        } else {
            self.list(filters)?
                .into_iter()
                .map(|c| c.get_id().to_string())
                .collect()
        };
        let mut list = Vec::new();
        for container_id in container_ids {
            let Ok((code, response)) = self.call(
                &format!("/containers/{container_id}/stats?stream=false"),
                "GET",
                None,
                &[],
                -1,
            ) else {
                continue;
            };
            if code != 200 || response.is_empty() {
                continue;
            }
            let Ok(stats) = serde_json::from_str::<Value>(&response) else {
                continue;
            };
            if stats.get("id").is_none()
                || stats.get("precpu_stats").is_none()
                || stats.get("cpu_stats").is_none()
                || stats.get("memory_stats").is_none()
                || stats.get("networks").is_none()
            {
                continue;
            }
            let cpu_delta = stats["cpu_stats"]["cpu_usage"]["total_usage"]
                .as_f64()
                .unwrap_or(0.0)
                - stats["precpu_stats"]["cpu_usage"]["total_usage"]
                    .as_f64()
                    .unwrap_or(0.0);
            let system_cpu_delta = stats["cpu_stats"]["system_cpu_usage"]
                .as_f64()
                .unwrap_or(0.0)
                - stats["precpu_stats"]["system_cpu_usage"]
                    .as_f64()
                    .unwrap_or(0.0);
            let number_cpus = stats["cpu_stats"]["online_cpus"].as_f64().unwrap_or(0.0);
            let cpu_usage = if system_cpu_delta > 0.0 && cpu_delta > 0.0 {
                (cpu_delta / system_cpu_delta) * number_cpus
            } else {
                0.0
            };
            let mem_limit = stats["memory_stats"]["limit"].as_f64().unwrap_or(0.0);
            let mem_usage_raw = stats["memory_stats"]["usage"].as_f64().unwrap_or(0.0);
            let memory_usage = if mem_limit > 0.0 && mem_usage_raw > 0.0 {
                (mem_usage_raw / mem_limit) * 100.0
            } else {
                0.0
            };
            let mut network_in = 0.0;
            let mut network_out = 0.0;
            if let Some(networks) = stats["networks"].as_object() {
                for network in networks.values() {
                    network_in += network["rx_bytes"].as_f64().unwrap_or(0.0);
                    network_out += network["tx_bytes"].as_f64().unwrap_or(0.0);
                }
            }
            let mut disk_read = 0.0;
            let mut disk_write = 0.0;
            if let Some(entries) = stats["blkio_stats"]["io_service_bytes_recursive"].as_array() {
                for entry in entries {
                    match entry["op"].as_str() {
                        Some("Read") => disk_read += entry["value"].as_f64().unwrap_or(0.0),
                        Some("Write") => disk_write += entry["value"].as_f64().unwrap_or(0.0),
                        _ => {}
                    }
                }
            }
            let memory_in = stats["memory_stats"]["usage"].as_f64().unwrap_or(0.0);
            let memory_out = stats["memory_stats"]["max_usage"].as_f64().unwrap_or(0.0);
            let name = stats["name"].as_str().unwrap_or("").trim_start_matches('/');
            list.push(Stats::new(
                stats["id"].as_str().unwrap_or("").to_string(),
                name.to_string(),
                cpu_usage,
                memory_usage,
                HashMap::from([("in".into(), disk_read), ("out".into(), disk_write)]),
                HashMap::from([("in".into(), memory_in), ("out".into(), memory_out)]),
                HashMap::from([("in".into(), network_in), ("out".into(), network_out)]),
            ));
        }
        Ok(list)
    }

    fn list_networks(&self) -> Result<Vec<Network>, OrchestrationError> {
        let (code, response) = self.call("/networks", "GET", None, &[], -1)?;
        if code != 200 {
            return Err(OrchestrationError::Orchestration(response));
        }
        let parsed: Vec<Value> = serde_json::from_str(&response).unwrap_or_default();
        let mut list = Vec::new();
        for value in parsed {
            if let Some(name) = value.get("Name").and_then(Value::as_str) {
                list.push(Network::new(
                    name.replace('/', ""),
                    value["Id"].as_str().unwrap_or("").to_string(),
                    value["Driver"].as_str().unwrap_or("").to_string(),
                    value["Scope"].as_str().unwrap_or("").to_string(),
                ));
            }
        }
        Ok(list)
    }

    fn pull(&self, image: &str) -> Result<bool, OrchestrationError> {
        let query = php_http_build_query(&[("fromImage", image)]);
        let auth = self.registry_auth.clone();
        let (code, _) = self.call(
            "/images/create",
            "POST",
            Some(query.as_bytes()),
            &[("X-Registry-Auth", auth.as_str())],
            -1,
        )?;
        Ok(code == 200 || code == 204)
    }

    fn list(&self, filters: HashMap<String, String>) -> Result<Vec<Container>, OrchestrationError> {
        let filters_value = if filters.is_empty() {
            "{}".to_string()
        } else {
            let mut sorted = Map::new();
            for (key, value) in &filters {
                sorted.insert(key.clone(), json!([value]));
            }
            Value::Object(sorted).to_string()
        };
        let query = if filters.is_empty() {
            "all=1".to_string()
        } else {
            php_http_build_query(&[("all", "1"), ("filters", &filters_value)])
        };
        let (code, response) =
            self.call(&format!("/containers/json?{query}"), "GET", None, &[], -1)?;
        if code != 200 {
            return Err(OrchestrationError::Orchestration(response));
        }
        let parsed: Vec<Value> = serde_json::from_str(&response).unwrap_or_default();
        let mut list = Vec::new();
        for value in parsed {
            if let Some(names) = value.get("Names").and_then(Value::as_array) {
                if let Some(name) = names.first().and_then(Value::as_str) {
                    let labels = value
                        .get("Labels")
                        .and_then(Value::as_object)
                        .map(|obj| {
                            obj.iter()
                                .map(|(k, v)| {
                                    (k.clone(), v.as_str().unwrap_or(&v.to_string()).to_string())
                                })
                                .collect()
                        })
                        .unwrap_or_default();
                    list.push(Container::new(
                        name.replace('/', ""),
                        value["Id"].as_str().unwrap_or("").to_string(),
                        value["Status"].as_str().unwrap_or("").to_string(),
                        labels,
                    ));
                }
            }
        }
        Ok(list)
    }

    #[allow(clippy::too_many_arguments, clippy::too_many_lines)]
    fn run(
        &self,
        image: &str,
        name: &str,
        command: &[String],
        entrypoint: &str,
        workdir: &str,
        volumes: &[String],
        vars: &HashMap<String, String>,
        mount_folder: &str,
        labels: &HashMap<String, String>,
        hostname: &str,
        remove: bool,
        network: &str,
        restart: &str,
    ) -> Result<String, OrchestrationError> {
        let (code, _) = self.call(&format!("/images/{image}/json"), "GET", None, &[], -1)?;
        if code == 404 && !self.pull(image)? {
            return Err(OrchestrationError::Orchestration(format!(
                "Missing image \"{image}\" and failed to pull it."
            )));
        }

        let env: Vec<String> = vars
            .iter()
            .map(|(k, v)| format!("{}={v}", filter_env_key(k)))
            .collect();
        let mut labels_map = labels.clone();
        let created = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs())
            .unwrap_or(0);
        labels_map.insert(
            format!("{}-type", self.settings.namespace),
            "runtime".to_string(),
        );
        labels_map.insert(
            format!("{}-created", self.settings.namespace),
            created.to_string(),
        );

        let mut binds: Vec<Value> = volumes.iter().map(|v| json!(v)).collect();
        if !php_empty_str(mount_folder) {
            binds.push(json!(format!("{mount_folder}:/tmp")));
        }

        let mut body = json!({
            "Hostname": hostname,
            "Entrypoint": entrypoint,
            "Image": image,
            "Cmd": command,
            "WorkingDir": workdir,
            "Labels": labels_map,
            "Env": env,
            "HostConfig": {
                "Binds": binds,
                "CpuQuota": self.settings.cpus * 100_000.0,
                "CpuPeriod": 100_000,
                "Memory": (self.settings.memory as f64) * 1e6,
                "MemorySwap": (self.settings.swap as f64) * 1e6,
                "AutoRemove": remove,
                "NetworkMode": if php_empty_str(network) { Value::Null } else { json!(network) },
                "RestartPolicy": { "Name": restart },
            },
        });
        if let Some(obj) = body.as_object_mut() {
            obj.retain(|_, v| !php_empty_json(v));
        }
        let encoded = body.to_string();
        let len = encoded.len().to_string();
        let (code, response) = self.call(
            &format!("/containers/create?name={name}"),
            "POST",
            Some(encoded.as_bytes()),
            &[
                ("Content-Type", "application/json"),
                ("Content-Length", &len),
            ],
            -1,
        )?;
        if code == 404 {
            return Err(OrchestrationError::Orchestration(format!(
                "Container image \"{image}\" not found."
            )));
        }
        if code == 409 {
            return Err(OrchestrationError::Orchestration(format!(
                "Container with name \"{name}\" already exists."
            )));
        }
        if code != 201 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to create function environment: {response} Response Code: {code}"
            )));
        }
        let parsed: Value = serde_json::from_str(&response).unwrap_or(Value::Null);
        let container_id = parsed["Id"].as_str().unwrap_or("").to_string();
        let (start_code, start_response) = self.call(
            &format!("/containers/{container_id}/start"),
            "POST",
            Some(b"{}"),
            &[],
            -1,
        )?;
        if start_code != 204 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to start container: {start_response}"
            )));
        }
        Ok(container_id)
    }

    fn execute(
        &self,
        name: &str,
        command: &[String],
        output: &mut String,
        vars: &HashMap<String, String>,
        timeout: i64,
    ) -> Result<bool, OrchestrationError> {
        let env: Vec<String> = vars
            .iter()
            .map(|(k, v)| format!("{}={v}", filter_env_key(k)))
            .collect();
        let body = json!({
            "Env": env,
            "Cmd": command,
            "AttachStdout": true,
            "AttachStderr": true,
        })
        .to_string();
        let len = body.len().to_string();
        let (code, response) = self.call(
            &format!("/containers/{name}/exec"),
            "POST",
            Some(body.as_bytes()),
            &[
                ("Content-Type", "application/json"),
                ("Content-Length", &len),
            ],
            timeout,
        )?;
        if code != 201 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to create execute command: {response} Response Code: {code}"
            )));
        }
        let parsed: Value = serde_json::from_str(&response).unwrap_or(Value::Null);
        let exec_id = parsed["Id"].as_str().unwrap_or("");
        let (scode, raw, stdout, stderr) =
            self.stream_start(&format!("/exec/{exec_id}/start"), timeout)?;
        *output = format!("{stdout}{stderr}");
        if scode != 200 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to create execute command: {raw} Response Code: {scode}"
            )));
        }
        let (icode, iresp) = self.call(&format!("/exec/{exec_id}/json"), "GET", None, &[], -1)?;
        if icode != 200 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to inspect status of execute command: {iresp} Response Code: {icode}"
            )));
        }
        let inspect: Value = serde_json::from_str(&iresp).unwrap_or(Value::Null);
        if inspect["Running"] == true || inspect["ExitCode"] != 0 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to execute command. Exit code: {}",
                inspect["ExitCode"]
            )));
        }
        Ok(true)
    }

    fn remove(&self, name: &str, force: bool) -> Result<bool, OrchestrationError> {
        let path = if force {
            format!("/containers/{name}?force=true")
        } else {
            format!("/containers/{name}")
        };
        let (code, response) = self.call(&path, "DELETE", None, &[], -1)?;
        if code != 204 {
            return Err(OrchestrationError::Orchestration(format!(
                "Failed to remove container: {response} Response Code: {code}"
            )));
        }
        Ok(true)
    }

    fn set_namespace(&mut self, namespace: impl Into<String>) -> &mut Self {
        self.settings.namespace = namespace.into();
        self
    }
    fn set_cpus(&mut self, cores: f64) -> &mut Self {
        self.settings.cpus = cores;
        self
    }
    fn set_memory(&mut self, mb: i64) -> &mut Self {
        self.settings.memory = mb;
        self
    }
    fn set_swap(&mut self, mb: i64) -> &mut Self {
        self.settings.swap = mb;
        self
    }
}

fn php_empty_json(value: &Value) -> bool {
    match value {
        Value::Null | Value::Bool(false) => true,
        Value::Number(n) => n.as_f64() == Some(0.0),
        Value::String(s) => php_empty_str(s),
        Value::Array(a) => a.is_empty(),
        Value::Object(o) => o.is_empty(),
        Value::Bool(true) => false,
    }
}
