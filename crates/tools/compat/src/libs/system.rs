//! Compat adapter for `system`: maps `tests/compat/system/spec.json` operations onto `utopia-system`.
//!
//! `system.fixture` runs a method on a [`Fixture`] host, the counterpart of
//! `tests/compat/system/Fixture.php`, so both runtimes parse the same
//! content; `system.machine` runs it on the real machine ([`Machine`]) and
//! reports only the shape of the result.

use std::collections::HashMap;
use std::future::Future;
use std::sync::Mutex;

use serde_json::{Map, Value, json};
use utopia_system::{Error, Host, Io, Machine, Number, System, Traffic, Uname};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, float_value};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["system.env", "system.fixture", "system.machine"];

/// A machine described by a case (see `Fixture.php` for the format).
struct Fixture {
    machine: Value,
    reads: Mutex<HashMap<String, usize>>,
    sleeps: Mutex<Vec<u64>>,
}

impl Fixture {
    fn get(&self, section: &str, key: &str) -> Option<&Value> {
        self.machine.get(section).and_then(|s| s.get(key)).filter(|v| !v.is_null())
    }
}

/// A fixture value as the PHP fixture returns it from a `string|false`
/// function: `false` is no value, other scalars are cast to strings.
fn text(value: &Value) -> Option<Vec<u8>> {
    match value {
        Value::Bool(false) | Value::Null => None,
        Value::Object(_) => bytes(value),
        other => php_std::value::to_string(other).map(String::into_bytes),
    }
}

impl Host for Fixture {
    fn uname(&self, field: Uname) -> String {
        let (key, default) = match field {
            Uname::Os => ("os", "Linux"),
            Uname::Machine => ("arch", "x86_64"),
            Uname::Host => ("hostname", "localhost"),
        };
        self.machine.get(key).and_then(Value::as_str).unwrap_or(default).to_owned()
    }

    fn read(&self, path: &str) -> Option<Vec<u8>> {
        match self.get("files", path)? {
            Value::Array(contents) => {
                let mut reads = self.reads.lock().unwrap_or_else(|e| e.into_inner());
                let read = reads.entry(path.to_owned()).or_default();
                let content = contents.get((*read).min(contents.len().saturating_sub(1)));
                *read += 1;
                content.and_then(text)
            }
            content => text(content),
        }
    }

    fn is_readable(&self, path: &str) -> bool {
        self.machine.get("files").and_then(|f| f.get(path)).is_some()
    }

    fn exec(&self, command: &str) -> Option<Vec<u8>> {
        self.get("exec", command).and_then(text)
    }

    fn scandir(&self, dir: &str) -> Option<Vec<String>> {
        let entries = self.get("dirs", dir)?.as_array()?;
        Some(entries.iter().filter_map(Value::as_str).map(str::to_owned).collect())
    }

    fn disk_total_space(&self, _dir: &str) -> Option<f64> {
        self.machine.get("disk").and_then(|d| d.get("total")).and_then(Value::as_f64)
    }

    fn disk_free_space(&self, _dir: &str) -> Option<f64> {
        self.machine.get("disk").and_then(|d| d.get("free")).and_then(Value::as_f64)
    }

    fn sleep(&self, seconds: u64) -> impl Future<Output = ()> + Send {
        self.sleeps.lock().unwrap_or_else(|e| e.into_inner()).push(seconds);
        std::future::ready(())
    }
}

/// What a method returned.
enum Returned {
    Str(String),
    Int(i64),
    Float(f64),
    Bool(bool),
    Rows(Vec<(String, (&'static str, Number, &'static str, Number))>),
}

fn number(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::from(i),
        Number::Float(f) => float_value(f),
    }
}

impl Returned {
    fn value(&self) -> Value {
        match self {
            Returned::Str(s) => Value::String(s.clone()),
            Returned::Int(i) => Value::from(*i),
            Returned::Float(f) => float_value(*f),
            Returned::Bool(b) => Value::Bool(*b),
            Returned::Rows(rows) => Value::Object(
                rows.iter()
                    .map(|(k, (a, x, b, y))| (k.clone(), json!({ *a: number(*x), *b: number(*y) })))
                    .collect::<Map<_, _>>(),
            ),
        }
    }

    /// The type of the result and the facts `SystemTest` asserts on it.
    fn shape(&self) -> Value {
        let sign = |f: f64| if f > 0.0 { 1 } else if f < 0.0 { -1 } else { 0 };
        match self {
            Returned::Str(s) => json!({"type": "string", "empty": s.is_empty()}),
            Returned::Int(i) => json!({"type": "int", "sign": i.signum()}),
            Returned::Float(f) => json!({"type": "float", "sign": sign(*f)}),
            Returned::Bool(_) => json!({"type": "bool"}),
            Returned::Rows(rows) => json!({"type": "array", "total": rows.iter().any(|(k, _)| k == "total")}),
        }
    }
}

fn io(rows: utopia_system::IndexMap<String, Io>) -> Returned {
    Returned::Rows(rows.into_iter().map(|(k, r)| (k, ("read", r.read, "write", r.write))).collect())
}

fn traffic(rows: utopia_system::IndexMap<String, Traffic>) -> Returned {
    Returned::Rows(rows.into_iter().map(|(k, r)| (k, ("download", r.download, "upload", r.upload))).collect())
}

async fn run<H: Host>(system: &System<H>, a: &Args<'_>) -> Result<Result<Returned, Error>, Fault> {
    let duration = || -> Result<u64, Fault> {
        u64::try_from(a.i64("duration")?).map_err(|_| Fault::new("a negative duration is a deviation"))
    };
    Ok(match a.str("call")? {
        "getOS" => Ok(Returned::Str(system.os())),
        "getArch" => Ok(Returned::Str(system.arch())),
        "getArchEnum" => system.arch_enum().map(|arch| Returned::Str(arch.name().to_owned())),
        "getHostname" => Ok(Returned::Str(system.hostname())),
        "getCPUCores" => system.cpu_cores().map(Returned::Int),
        "getCPU" => system.cpu().map(Returned::Float),
        "getCPUUsage" => system.cpu_usage(duration()?).await.map(Returned::Float),
        "getMemoryTotal" => system.memory_total().map(Returned::Int),
        "getMemory" => system.memory().map(Returned::Int),
        "getMemoryFree" => system.memory_free().map(Returned::Int),
        "getMemoryAvailable" => system.memory_available().map(Returned::Int),
        "getDiskTotal" => system.disk_total(a.str("directory")?).map(Returned::Int),
        "getDiskFree" => system.disk_free(a.str("directory")?).map(Returned::Int),
        "getIOUsage" => system.io_usage(duration()?).await.map(io),
        "getNetworkUsage" => system.network_usage(duration()?).await.map(traffic),
        "isX86" => Ok(Returned::Bool(system.is_x86())),
        "isPPC" => Ok(Returned::Bool(system.is_ppc())),
        "isArm64" => Ok(Returned::Bool(system.is_arm64())),
        "isArmV7" => Ok(Returned::Bool(system.is_armv7())),
        "isArmV8" => Ok(Returned::Bool(system.is_armv8())),
        "isArch" => system.is_arch(a.str("arch")?).map(Returned::Bool),
        other => return Err(Fault::new(format!("unknown System method {other}"))),
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

pub async fn call_op(op: &str, args: &Value) -> OpResult {
    let a = Args(args);
    Ok(match op {
        "system.env" => {
            let name = a.str("name")?;
            // SAFETY: the compat driver handles one request at a time on a
            // single-threaded runtime; nothing reads the environment while
            // it changes (PHP's adapter calls putenv() the same way).
            match a.opt_str("value")? {
                Some(value) => unsafe { std::env::set_var(name, value) },
                None => unsafe { std::env::remove_var(name) },
            }
            Outcome::Ok(match args.get("default") {
                None => utopia_system::env(name).map_or(Value::Null, Value::String),
                Some(Value::Null) => utopia_system::env(name).map_or(Value::Null, Value::String),
                Some(Value::String(default)) => Value::String(utopia_system::env_or(name, default)),
                Some(_) => return Err(Fault::new("default must be a string or null")),
            })
        }
        "system.fixture" => {
            let fixture = Fixture {
                machine: a.value("machine")?.clone(),
                reads: Mutex::new(HashMap::new()),
                sleeps: Mutex::new(Vec::new()),
            };
            let system = System::with_host(fixture);
            match run(&system, &a).await? {
                Ok(returned) => {
                    let sleeps = system.host().sleeps.lock().unwrap_or_else(|e| e.into_inner()).clone();
                    Outcome::Ok(json!({"value": returned.value(), "sleeps": sleeps}))
                }
                Err(e) => err(e),
            }
        }
        "system.machine" => match run(&System::<Machine>::new(), &a).await? {
            Ok(returned) => Outcome::Ok(returned.shape()),
            Err(e) => err(e),
        },
        _ => return Err(Fault::new(format!("system: unknown operation `{op}`"))),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    call_op(op, args).await
}
