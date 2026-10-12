//! Compat adapter for `orchestration`: maps `tests/compat/orchestration/spec.json` operations onto
//! `utopia-orchestration`.
//!
//! Docker is the local daemon. [`DockerApi`] reaches it on its socket;
//! [`DockerCli`] runs `tests/compat/orchestration/docker`, which runs the
//! PHP side's own client in `compat-orchestration-php` (services.sh), so
//! both runtimes go through the same client version. The `daemon.*`
//! probes use that client too, as `Operations.php` does.

use std::time::Duration;

use serde_json::{Map, Value, json};
use utopia_console::{Command, execute};
use utopia_orchestration::{
    Adapter, Container, DockerApi, DockerCli, Error, Io, Network, Orchestration, Restart, RunOptions, Stats,
    filter_env_key, parse_command,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value, float, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "parse_command",
    "filter_env_key",
    "container.new",
    "network.new",
    "stats.new",
    "constants",
    "orchestration.new",
    "orchestration.call",
    "daemon.inspect",
    "daemon.restarted",
    "daemon.wait",
    "daemon.wait_gone",
    "daemon.cleanup",
    "fixture.write",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("parse_command", "Split a runtime start command with a quoted script"),
    ("filter_env_key", "Filter ten environment variable names"),
];

const COMMAND: &str = "sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'";

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    Ok(match name {
        "parse_command" => Bench::sync(|| {
            black_box(parse_command(black_box(COMMAND.as_bytes())).ok());
        }),
        "filter_env_key" => {
            let keys = [
                "APPWRITE_FUNCTION_ID",
                "APPWRITE FUNCTION NAME",
                "_APP_REDIS.HOST",
                "path-1",
                "ÉTÉ",
                "A$B",
                "x",
                "",
                "OPEN_RUNTIMES_SECRET",
                "a.b.c-d_e",
            ];
            Bench::sync(move || {
                for key in keys {
                    black_box(filter_env_key(black_box(key)));
                }
            })
        }
        _ => return Err(Fault::new(format!("orchestration: no benchmark `{name}`"))),
    })
}

/// The docker client the Rust side runs (see the module docs).
fn client() -> String {
    let root = std::env::current_dir().unwrap_or_default();
    root.join("tests/compat/orchestration/docker").display().to_string()
}

/// `[[key, value], ...]` as the associative array PHP callers pass: a key
/// given again replaces the value of the first (`"1"` and `1` are one key).
fn pairs(value: Option<&Value>) -> Result<Vec<(String, String)>, Fault> {
    let Some(value) = value else {
        return Ok(Vec::new());
    };
    let list = value.as_array().ok_or_else(|| Fault::new("expected a list of [key, value] pairs"))?;
    let mut out: Vec<(String, String)> = Vec::new();
    for pair in list {
        let Some([Value::String(k), Value::String(v)]) = pair.as_array().map(Vec::as_slice) else {
            return Err(Fault::new("expected a [key, value] pair of strings"));
        };
        let key = php_std::types::KeyRef::normalize(k.as_bytes());
        match out.iter_mut().find(|(e, _)| php_std::types::KeyRef::normalize(e.as_bytes()) == key) {
            Some(entry) => entry.1 = v.clone(),
            None => out.push((k.clone(), v.clone())),
        }
    }
    Ok(out)
}

fn strings(value: Option<&Value>) -> Result<Vec<String>, Fault> {
    value
        .and_then(Value::as_array)
        .map_or(&[][..], Vec::as_slice)
        .iter()
        .map(|v| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new("expected a list of strings")))
        .collect()
}

fn container_value(c: &Container) -> Value {
    json!({
        "name": c.name,
        "id": c.id,
        "status": c.status,
        "labels": c.labels.iter().map(|(k, v)| (k.clone(), Value::from(v.as_str()))).collect::<Map<_, _>>(),
    })
}

fn network_value(n: &Network) -> Value {
    json!({ "name": n.name, "id": n.id, "driver": n.driver, "scope": n.scope })
}

fn io_value(io: &Io) -> Value {
    json!({ "in": float_value(io.input), "out": float_value(io.output) })
}

fn stats_value(s: &Stats) -> Value {
    json!({
        "container_id": s.container_id,
        "container_name": s.container_name,
        "cpu_usage": float_value(s.cpu_usage),
        "memory_usage": float_value(s.memory_usage),
        "disk_io": io_value(&s.disk_io),
        "memory_io": io_value(&s.memory_io),
        "network_io": io_value(&s.network_io),
    })
}

fn io(value: Option<&Value>) -> Io {
    let get = |k: &str| value.and_then(|v| v.get(k)).and_then(float).unwrap_or(0.0);
    Io { input: get("in"), output: get("out") }
}

fn error(e: &Error) -> Value {
    json!({ "class": e.php_class(), "message": e.message() })
}

/// An orchestrator as a handle holds it.
type Handle = Orchestration<Box<dyn Adapter>>;

async fn orchestration(a: &Args<'_>) -> Result<Result<Handle, Error>, Fault> {
    let credentials = a.opt("credentials");
    let credential = |key: &str| credentials.and_then(|c| c.get(key)).and_then(Value::as_str).unwrap_or_default();
    let adapter: Box<dyn Adapter> = match a.opt_str("adapter")?.unwrap_or("cli") {
        "cli" => {
            match DockerCli::new().with_executable(client()).login(credential("username"), credential("password")).await
            {
                Ok(cli) => Box::new(cli),
                Err(e) => return Ok(Err(e)),
            }
        }
        "api" => {
            Box::new(DockerApi::with_credentials(credential("username"), credential("password"), credential("email")))
        }
        other => return Err(Fault::new(format!("unknown adapter `{other}`"))),
    };
    let mut orchestration = Orchestration::new(adapter);
    if let Some(namespace) = a.opt_str("namespace")? {
        orchestration.set_namespace(namespace);
    }
    if let Some(cpus) = a.opt_f64("cpus")? {
        orchestration.set_cpus(cpus);
    }
    if let Some(memory) = a.opt_i64("memory")? {
        orchestration.set_memory(memory);
    }
    if let Some(swap) = a.opt_i64("swap")? {
        orchestration.set_swap(swap);
    }
    Ok(Ok(orchestration))
}

async fn call_method(o: &Handle, a: &Args<'_>) -> Result<Value, Fault> {
    let method = a.str("method")?;
    let matches = |name: &str| a.opt_str("match").ok().flatten().is_none_or(|m| name.contains(m));
    let filters = pairs(a.opt("filters"))?;
    let vars = pairs(a.opt("vars"))?;
    let mut output = Vec::new();
    let result: Result<Value, Error> = match method {
        "createNetwork" => {
            o.create_network(a.str("name")?, a.opt_bool("internal")?.unwrap_or(false)).await.map(Value::from)
        }
        "removeNetwork" => o.remove_network(a.str("name")?).await.map(Value::from),
        "networkConnect" => o.network_connect(a.str("container")?, a.str("network")?).await.map(Value::from),
        "networkDisconnect" => o
            .network_disconnect(a.str("container")?, a.str("network")?, a.opt_bool("force")?.unwrap_or(false))
            .await
            .map(Value::from),
        "networkExists" => o.network_exists(a.str("name")?).await.map(Value::from),
        // Sorted by name: the API lists networks in the daemon's map order.
        "listNetworks" => o.list_networks().await.map(|mut list| {
            list.sort_by(|a, b| a.name.as_bytes().cmp(b.name.as_bytes()));
            list.iter().filter(|n| matches(&n.name)).map(network_value).collect::<Vec<_>>().into()
        }),
        "getStats" => o
            .stats(a.opt_str("container")?, &filters)
            .await
            .map(|list| list.iter().filter(|s| matches(&s.container_name)).map(stats_value).collect::<Vec<_>>().into()),
        "pull" => o.pull(a.str("image")?).await.map(Value::from),
        "list" => o
            .list(&filters)
            .await
            .map(|list| list.iter().filter(|c| matches(&c.name)).map(container_value).collect::<Vec<_>>().into()),
        "run" => {
            let options = RunOptions {
                command: strings(a.opt("command"))?,
                entrypoint: a.opt_str("entrypoint")?.unwrap_or_default().into(),
                workdir: a.opt_str("workdir")?.unwrap_or_default().into(),
                volumes: strings(a.opt("volumes"))?,
                vars,
                mount_folder: a.opt_str("mount_folder")?.unwrap_or_default().into(),
                labels: pairs(a.opt("labels"))?,
                hostname: a.opt_str("hostname")?.unwrap_or_default().into(),
                remove: a.opt_bool("remove")?.unwrap_or(false),
                network: a.opt_str("network")?.unwrap_or_default().into(),
                restart: Restart::from(a.opt_str("restart")?.unwrap_or("no")),
            };
            o.run(a.str("image")?, a.str("name")?, &options).await.map(Value::from)
        }
        "execute" => {
            let command = strings(a.opt("command"))?;
            o.execute(a.str("name")?, &command, &mut output, &vars, a.opt_i64("timeout")?.unwrap_or(-1))
                .await
                .map(Value::from)
        }
        "remove" => o.remove(a.str("name")?, a.opt_bool("force")?.unwrap_or(false)).await.map(Value::from),
        other => return Err(Fault::new(format!("unknown orchestration method `{other}`"))),
    };
    let mut out = Map::new();
    match result {
        Ok(value) => out.insert("result".into(), value),
        Err(e) => out.insert("error".into(), error(&e)),
    };
    if method == "execute" {
        let summary = a.opt_bool("summary")?.unwrap_or(false);
        out.insert(
            "output".into(),
            if summary {
                json!({
                    "length": output.len(),
                    "start": bytes_value(&output[..output.len().min(8)]),
                    "end": bytes_value(&output[output.len().saturating_sub(8)..]),
                })
            } else {
                bytes_value(&output)
            },
        );
    }
    Ok(Value::Object(out))
}

/// Runs the docker client with these arguments; its exit status and
/// standard output.
async fn docker(args: &[&str]) -> Result<(i32, Vec<u8>), Fault> {
    let mut command = Command::new(client()).map_err(|e| Fault::new(e.to_string()))?;
    for arg in args {
        command = command.argument(*arg).map_err(|e| Fault::new(e.to_string()))?;
    }
    let output = execute(&command, b"", None, None).await.map_err(|e| Fault::new(e.to_string()))?;
    Ok((output.status, output.stdout))
}

async fn inspect(container: &str) -> Result<Value, Fault> {
    let (status, stdout) = docker(&["inspect", container]).await?;
    if status != 0 {
        return Ok(Value::Null);
    }
    let parsed: Value = serde_json::from_slice(&stdout).unwrap_or(Value::Null);
    let Some(i) = parsed.get(0) else {
        return Ok(Value::Null);
    };
    let at = |path: &[&str]| path.iter().try_fold(i, |v, k| v.get(k)).cloned().unwrap_or(Value::Null);
    let networks: Vec<Value> = i
        .pointer("/NetworkSettings/Networks")
        .and_then(Value::as_object)
        .map(|n| n.keys().map(|k| Value::from(k.as_str())).collect())
        .unwrap_or_default();
    // The client passes --env flags through a map, so their order is not kept.
    let mut env: Vec<Value> = at(&["Config", "Env"]).as_array().cloned().unwrap_or_default();
    env.sort_by(|a, b| a.as_str().unwrap_or_default().cmp(b.as_str().unwrap_or_default()));
    // The client passes --volume flags through a map, so their order is not kept.
    let binds = match at(&["HostConfig", "Binds"]) {
        Value::Array(mut binds) => {
            binds.sort_by(|a, b| a.as_str().unwrap_or_default().cmp(b.as_str().unwrap_or_default()));
            Value::Array(binds)
        }
        other => other,
    };
    Ok(json!({
        "name": at(&["Name"]).as_str().unwrap_or_default().trim_start_matches('/'),
        "image": at(&["Config", "Image"]),
        "cmd": at(&["Config", "Cmd"]),
        "entrypoint": at(&["Config", "Entrypoint"]),
        "workdir": at(&["Config", "WorkingDir"]),
        "hostname": at(&["Config", "Hostname"]),
        "env": env,
        "labels": at(&["Config", "Labels"]),
        "binds": binds,
        "memory": at(&["HostConfig", "Memory"]),
        "swap": at(&["HostConfig", "MemorySwap"]),
        "nano_cpus": at(&["HostConfig", "NanoCpus"]),
        "cpu_quota": at(&["HostConfig", "CpuQuota"]),
        "cpu_period": at(&["HostConfig", "CpuPeriod"]),
        "auto_remove": at(&["HostConfig", "AutoRemove"]),
        "network_mode": at(&["HostConfig", "NetworkMode"]),
        "restart": at(&["HostConfig", "RestartPolicy", "Name"]),
        "networks": networks,
    }))
}

async fn wait_gone(name: &str, seconds: i64) -> Result<bool, Fault> {
    let deadline = std::time::Instant::now() + Duration::from_secs(seconds.max(0) as u64);
    let filter = format!("name=^{name}$");
    loop {
        let (_, stdout) = docker(&["ps", "-aq", "--filter", &filter]).await?;
        if stdout.iter().all(u8::is_ascii_whitespace) {
            return Ok(true);
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
        if std::time::Instant::now() >= deadline {
            return Ok(false);
        }
    }
}

async fn cleanup(name: &str) -> Result<bool, Fault> {
    let filter = format!("name={name}");
    let (_, ids) = docker(&["ps", "-aq", "--filter", &filter]).await?;
    let ids = String::from_utf8_lossy(&ids).into_owned();
    let ids: Vec<&str> = ids.split_whitespace().collect();
    if !ids.is_empty() {
        docker(&[&["rm", "-f"][..], &ids].concat()).await?;
    }
    let (_, networks) = docker(&["network", "ls", "-q", "--filter", &filter]).await?;
    let networks = String::from_utf8_lossy(&networks).into_owned();
    let networks: Vec<&str> = networks.split_whitespace().collect();
    if !networks.is_empty() {
        docker(&[&["network", "rm"][..], &networks].concat()).await?;
    }
    Ok(true)
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "parse_command" => match parse_command(&a.bytes("command")?) {
            Ok(pieces) => Outcome::Ok(pieces.iter().map(|p| bytes_value(p)).collect::<Vec<_>>().into()),
            Err(e) => Outcome::err(e.php_class(), e.message()),
        },
        "filter_env_key" => Outcome::ok(filter_env_key(a.str("key")?)),
        "container.new" => {
            let mut c = Container::new(
                a.opt_str("name")?.unwrap_or_default(),
                a.opt_str("id")?.unwrap_or_default(),
                a.opt_str("status")?.unwrap_or_default(),
                pairs(a.opt("labels"))?,
            );
            if let Some(Value::Object(set)) = a.opt("set") {
                for (field, value) in set {
                    let text = || value.as_str().map(str::to_owned).ok_or_else(|| Fault::new("expected a string"));
                    match field.as_str() {
                        "name" => c.name = text()?,
                        "id" => c.id = text()?,
                        "status" => c.status = text()?,
                        "labels" => c.labels = pairs(Some(value))?,
                        other => return Err(Fault::new(format!("unknown container field {other}"))),
                    }
                }
            }
            Outcome::Ok(container_value(&c))
        }
        "network.new" => {
            let mut n = Network::new(
                a.opt_str("name")?.unwrap_or_default(),
                a.opt_str("id")?.unwrap_or_default(),
                a.opt_str("driver")?.unwrap_or_default(),
                a.opt_str("scope")?.unwrap_or_default(),
            );
            if let Some(Value::Object(set)) = a.opt("set") {
                for (field, value) in set {
                    let text = value.as_str().map(str::to_owned).ok_or_else(|| Fault::new("expected a string"))?;
                    match field.as_str() {
                        "name" => n.name = text,
                        "id" => n.id = text,
                        "driver" => n.driver = text,
                        "scope" => n.scope = text,
                        other => return Err(Fault::new(format!("unknown network field {other}"))),
                    }
                }
            }
            Outcome::Ok(network_value(&n))
        }
        "stats.new" => Outcome::Ok(stats_value(&Stats {
            container_id: a.str("container_id")?.into(),
            container_name: a.str("container_name")?.into(),
            cpu_usage: a.f64("cpu_usage")?,
            memory_usage: a.f64("memory_usage")?,
            disk_io: io(a.opt("disk_io")),
            memory_io: io(a.opt("memory_io")),
            network_io: io(a.opt("network_io")),
        })),
        "constants" => Outcome::Ok(json!({
            "RESTART_NO": Restart::No.as_str(),
            "RESTART_ALWAYS": Restart::Always.as_str(),
            "RESTART_ON_FAILURE": Restart::OnFailure.as_str(),
            "RESTART_UNLESS_STOPPED": Restart::UnlessStopped.as_str(),
        })),
        "orchestration.new" => match orchestration(&a).await? {
            Ok(o) => Outcome::Ok(session.handle(o)),
            Err(e) => Outcome::Ok(json!({ "error": error(&e) })),
        },
        "orchestration.call" => {
            let handle = session.get::<Handle>(a.value("orchestration")?)?;
            Outcome::Ok(call_method(handle, &a).await?)
        }
        "daemon.inspect" => Outcome::Ok(inspect(a.str("container")?).await?),
        "daemon.restarted" => {
            let (_, stdout) = docker(&["inspect", "--format", "{{.RestartCount}}", a.str("container")?]).await?;
            Outcome::ok(String::from_utf8_lossy(&stdout).trim().parse::<i64>().unwrap_or(0) > 0)
        }
        "daemon.wait" => {
            tokio::time::sleep(Duration::from_secs_f64(a.f64("seconds")?)).await;
            Outcome::ok(true)
        }
        "daemon.wait_gone" => Outcome::ok(wait_gone(a.str("name")?, a.opt_i64("seconds")?.unwrap_or(30)).await?),
        "daemon.cleanup" => Outcome::ok(cleanup(a.str("match")?).await?),
        "fixture.write" => {
            let path = a.str("path")?;
            if !path.starts_with("/tmp/compat-fs/") || path.contains("..") {
                return Err(Fault::new("fixture.write writes under /tmp/compat-fs only"));
            }
            let path = std::path::Path::new(path);
            if let Some(dir) = path.parent() {
                std::fs::create_dir_all(dir).map_err(|e| Fault::new(e.to_string()))?;
            }
            std::fs::write(path, a.bytes("content")?).map_err(|e| Fault::new(e.to_string()))?;
            Outcome::ok(path.display().to_string())
        }
        _ => return Err(Fault::new(format!("orchestration: unknown operation `{op}`"))),
    })
}
