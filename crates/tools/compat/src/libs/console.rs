//! Compat adapter for `console`: maps `tests/compat/console/spec.json` operations onto `utopia-console`.
//!
//! Output to the process's stdout and stderr, prompts and `exit()` are
//! observed in a child process (this driver's stdout is the protocol): the
//! driver re-runs itself with `COMPAT_CONSOLE_CHILD` set ([`child`]), as the
//! PHP adapter runs a child PHP process.

use std::hint::black_box;
use std::time::Duration;

use serde_json::{Value, json};
use utopia_console::{Check, Command, Program};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "command.run",
    "console.log",
    "console.confirm",
    "console.exit",
    "console.title",
    "console.interactive",
    "console.execute",
    "console.loop",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("build", "Build a plain command with a flag, two options and an argument"),
    ("render", "Render a redirected pipeline of two commands as a quoted shell string"),
];

const CHILD: &str = "COMPAT_CONSOLE_CHILD";
const RESULT: &str = "COMPAT_CONSOLE_RESULT";

/// In a child driver process (`COMPAT_CONSOLE_CHILD` set), runs the console
/// function it names on the real stdio, writes what it returned to the file
/// `COMPAT_CONSOLE_RESULT` names, and exits. Does nothing otherwise.
pub fn child() {
    let Ok(spec) = std::env::var(CHILD) else { return };
    let spec: Value = serde_json::from_str(&spec).unwrap_or(Value::Null);
    let arg = spec["arg"].as_str().unwrap_or_default();
    let result = match spec["op"].as_str() {
        Some("log") => json!(utopia_console::log(arg).ok()),
        Some("success") => json!(utopia_console::success(arg).ok()),
        Some("error") => json!(utopia_console::error(arg).ok()),
        Some("info") => json!(utopia_console::info(arg).ok()),
        Some("warning") => json!(utopia_console::warning(arg).ok()),
        Some("confirm") => json!(utopia_console::confirm(arg).ok()),
        Some("exit") => utopia_console::exit(arg.parse().unwrap_or(0)),
        _ => Value::Null,
    };
    if let Ok(path) = std::env::var(RESULT) {
        let _ = std::fs::write(path, result.to_string());
    }
    std::process::exit(0);
}

/// The `compat-driver` binary: this process, or (in `cargo test`, whose
/// harness cannot act as the child) the one Cargo built next to the tests.
fn driver() -> Result<std::path::PathBuf, Fault> {
    let exe = std::env::current_exe().map_err(|e| Fault::new(e.to_string()))?;
    if exe.file_stem().is_some_and(|n| n == "compat-driver") {
        return Ok(exe);
    }
    let name = format!("compat-driver{}", std::env::consts::EXE_SUFFIX);
    exe.ancestors()
        .skip(1)
        .take(2)
        .map(|dir| dir.join(&name))
        .find(|p| p.is_file())
        .ok_or_else(|| Fault::new("the compat-driver binary is not built"))
}

/// Runs [`child`] in a new driver process with `stdin` as its input.
fn spawn(op: &str, arg: &str, stdin: &str) -> Result<Value, Fault> {
    use std::io::Write;
    use std::process::{Command as Process, Stdio};
    let exe = driver()?;
    let result = std::env::temp_dir().join(format!("compat-console-{}-{op}.json", std::process::id()));
    let _ = std::fs::remove_file(&result);
    let mut process = Process::new(exe)
        .env(CHILD, json!({"op": op, "arg": arg}).to_string())
        .env(RESULT, &result)
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| Fault::new(e.to_string()))?;
    if let Some(mut input) = process.stdin.take() {
        let _ = input.write_all(stdin.as_bytes());
    }
    let output = process.wait_with_output().map_err(|e| Fault::new(e.to_string()))?;
    let value =
        std::fs::read_to_string(&result).ok().and_then(|s| serde_json::from_str(&s).ok()).unwrap_or(Value::Null);
    let _ = std::fs::remove_file(&result);
    Ok(json!({
        "result": value,
        "stdout": bytes_value(&output.stdout),
        "stderr": bytes_value(&output.stderr),
        "status": output.status.code().unwrap_or(-1),
    }))
}

/// A command argument from JSON: strings as they are, numbers as PHP prints them.
fn arg(v: &Value) -> Result<utopia_console::Arg, Fault> {
    Ok(match v {
        Value::String(s) => s.as_str().into(),
        Value::Number(n) if n.is_i64() => n.as_i64().unwrap_or_default().into(),
        other => match crate::adapter::float(other) {
            Some(f) => f.into(),
            None => return Err(Fault::new(format!("argument {other}"))),
        },
    })
}

enum Checker {
    Validator(Box<dyn utopia_validators::Validator>),
    In(Vec<String>),
}

fn checker(spec: Option<&Value>) -> Result<Option<Checker>, Fault> {
    let Some(spec) = spec.filter(|v| !v.is_null()) else { return Ok(None) };
    if let Some(v) = spec.get("validator") {
        return Ok(Some(Checker::Validator(super::http::validator(v)?)));
    }
    if let Some(list) = spec.get("in").and_then(Value::as_array) {
        return Ok(Some(Checker::In(list.iter().filter_map(Value::as_str).map(str::to_owned).collect())));
    }
    Err(Fault::new("unknown check"))
}

fn steps(mut command: Command, list: &[Value]) -> Result<Result<Command, utopia_console::Error>, Fault> {
    for step in list {
        let (op, x) = step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("step"))?;
        let check = checker(x.get("check"))?;
        let within = |v: &str| matches!(&check, Some(Checker::In(list)) if list.iter().any(|l| l == v));
        let with: Option<Check<'_>> = match &check {
            None => None,
            Some(Checker::Validator(v)) => Some(Check::Validator(&**v)),
            Some(Checker::In(_)) => Some(Check::Predicate(&within)),
        };
        let result = match op.as_str() {
            "flag" => command.flag(x.as_str().unwrap_or_default()),
            "option" => {
                let key = x["key"].as_str().unwrap_or_default();
                let value = arg(&x["value"])?;
                match with {
                    Some(c) => command.option_checked(key, value, c),
                    None => command.option(key, value),
                }
            }
            "argument" => {
                let value = arg(&x["value"])?;
                match with {
                    Some(c) => command.argument_checked(value, c),
                    None => command.argument(value),
                }
            }
            other => return Err(Fault::new(format!("unknown step {other}"))),
        };
        command = match result {
            Ok(c) => c,
            Err(e) => return Ok(Err(e)),
        };
    }
    Ok(Ok(command))
}

/// A command from its description (as Adapter.php's `command()`).
fn command(e: &Value) -> Result<Result<Command, utopia_console::Error>, Fault> {
    let (kind, a) = e
        .as_object()
        .filter(|o| o.len() == 1)
        .and_then(|o| o.iter().next())
        .ok_or_else(|| Fault::new(format!("command expression: {e}")))?;
    let list = |a: &Value| -> Result<Result<Vec<Command>, utopia_console::Error>, Fault> {
        let mut out = Vec::new();
        for item in a.as_array().into_iter().flatten() {
            match command(item)? {
                Ok(c) => out.push(c),
                Err(e) => return Ok(Err(e)),
            }
        }
        Ok(Ok(out))
    };
    macro_rules! tri {
        ($e:expr) => {
            match $e? {
                Ok(v) => v,
                Err(e) => return Ok(Err(e)),
            }
        };
    }
    Ok(match kind.as_str() {
        "plain" => {
            let c = match Command::new(arg(&a["executable"])?) {
                Ok(c) => c,
                Err(e) => return Ok(Err(e)),
            };
            steps(c, a.get("steps").and_then(Value::as_array).map(Vec::as_slice).unwrap_or(&[]))?
        }
        "pipe" => Command::pipe(tri!(list(a))),
        "and" => Command::and(tri!(list(a))),
        "or" => Command::or(tri!(list(a))),
        "group" => Ok(Command::group(tri!(command(a)))),
        "redirect_stdout" => Command::redirect_stdout(tri!(command(&a["command"])), arg(&a["path"])?),
        "append_stdout" => Command::append_stdout(tri!(command(&a["command"])), arg(&a["path"])?),
        "redirect_input" => Command::redirect_input(tri!(command(&a["command"])), arg(&a["path"])?),
        "then" => steps(tri!(command(&a["command"])), a["steps"].as_array().map(Vec::as_slice).unwrap_or(&[]))?,
        other => return Err(Fault::new(format!("unknown command {other}"))),
    })
}

fn error(e: &utopia_console::Error) -> Value {
    json!({"$error": {"class": e.php_class(), "message": e.to_string()}})
}

fn describe(built: Result<Command, utopia_console::Error>) -> Value {
    let command = match built {
        Ok(c) => c,
        Err(e) => return json!({"error": error(&e)}),
    };
    let array = match command.to_array() {
        Ok(a) => json!(a),
        Err(e) => error(&e),
    };
    let string = match command.to_shell() {
        Ok(s) => json!(s),
        Err(e) => error(&e),
    };
    json!({"plain": command.is_plain(), "array": array, "string": string.clone(), "cast": string})
}

async fn execute(a: &Args<'_>) -> Result<Value, Fault> {
    let program = a.value("program")?;
    let stdin = a.opt_str("stdin")?.unwrap_or("").to_owned();
    let timeout = a.opt_i64("timeout")?.filter(|t| *t > 0).map(|t| Duration::from_secs(t as u64));
    let mut progress = Vec::new();
    let mut record = |chunk: &[u8]| progress.extend_from_slice(chunk);
    let on_progress: Option<utopia_console::Progress<'_>> =
        if a.opt_bool("progress")?.unwrap_or(false) { Some(&mut record) } else { None };
    let result = if let Some(e) = program.get("command") {
        let c = match command(e)? {
            Ok(c) => c,
            Err(e) => return Ok(json!({"error": error(&e)})),
        };
        utopia_console::execute(&c, stdin.as_bytes(), timeout, on_progress).await
    } else if let Some(argv) = program.get("argv").and_then(Value::as_array) {
        let words: Vec<&str> = argv.iter().map(|w| w.as_str().unwrap_or_default()).collect();
        utopia_console::execute(Program::Argv(&words), stdin.as_bytes(), timeout, on_progress).await
    } else if let Some(line) = program.get("shell").and_then(Value::as_str) {
        utopia_console::execute(line, stdin.as_bytes(), timeout, on_progress).await
    } else {
        return Err(Fault::new("program"));
    };
    Ok(match result {
        Ok(out) => json!({
            "status": out.status,
            "stdout": bytes_value(&out.stdout),
            "stderr": bytes_value(&out.stderr),
            "progress": bytes_value(&progress),
        }),
        Err(e) => json!({"error": error(&e)}),
    })
}

async fn run_loop(a: &Args<'_>) -> Result<Value, Fault> {
    let fail: Vec<i64> =
        a.opt("fail").and_then(Value::as_array).into_iter().flatten().filter_map(Value::as_i64).collect();
    let rethrow = a.opt_i64("rethrow")?.unwrap_or(1);
    let mut calls = 0_i64;
    let mut errors: Vec<String> = Vec::new();
    let sleep = Duration::from_secs(a.opt_i64("sleep")?.unwrap_or(0).max(0) as u64);
    let delay = Duration::from_secs(a.opt_i64("delay")?.unwrap_or(0).max(0) as u64);
    let callback = || {
        calls += 1;
        let call = calls;
        let failing = fail.contains(&call);
        async move {
            if call > 1000 {
                Err(None)
            } else if failing {
                Err(Some(format!("fail {call}")))
            } else {
                Ok(())
            }
        }
    };
    let result = if a.opt_bool("on_error")?.unwrap_or(false) {
        let handler = |e: Option<String>| {
            let Some(message) = e else { return Err(None) };
            errors.push(message.clone());
            if errors.len() as i64 >= rethrow { Err(Some(message)) } else { Ok(()) }
        };
        utopia_console::repeat(callback, sleep, delay, Some(handler)).await
    } else {
        utopia_console::repeat(callback, sleep, delay, None::<fn(Option<String>) -> Result<(), Option<String>>>).await
    };
    let thrown = match result {
        Err(Some(message)) => Value::String(message),
        Err(None) => return Err(Fault::new("the loop does not end")),
        Ok(never) => match never {},
    };
    Ok(json!({"calls": calls, "errors": errors, "thrown": thrown}))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "build" => Bench::sync(|| {
            let c = Command::new("tar")
                .and_then(|c| c.flag("-cz"))
                .and_then(|c| c.option("-f", "archive.tar.gz"))
                .and_then(|c| c.option("-C", "/tmp/project"))
                .and_then(|c| c.argument("."));
            black_box(c.map(|c| c.to_array().map(<[String]>::len).unwrap_or(0)).ok());
        }),
        "render" => {
            let command = Command::append_stdout(
                Command::pipe(vec![
                    Command::new("cat").and_then(|c| c.argument("app.log")).map_err(|e| Fault::new(e.to_string()))?,
                    Command::new("grep")
                        .and_then(|c| c.argument("it's an ERROR"))
                        .map_err(|e| Fault::new(e.to_string()))?,
                ])
                .map_err(|e| Fault::new(e.to_string()))?,
                "errors.log",
            )
            .map_err(|e| Fault::new(e.to_string()))?;
            Bench::sync(move || {
                black_box(command.to_shell().ok());
            })
        }
        _ => return Err(Fault::new(format!("console: no benchmark `{name}`"))),
    })
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "command.run" => describe(command(a.value("expr")?)?),
        "console.log" => {
            let method = a.str("method")?;
            if !["log", "success", "error", "info", "warning"].contains(&method) {
                return Err(Fault::new(format!("unknown method {method}")));
            }
            spawn(method, a.opt_str("message")?.unwrap_or(""), "")?
        }
        "console.confirm" => spawn("confirm", a.opt_str("question")?.unwrap_or(""), a.opt_str("input")?.unwrap_or(""))?,
        "console.exit" => spawn("exit", &a.opt_i64("status")?.unwrap_or(0).to_string(), "")?["status"].clone(),
        "console.title" => json!(utopia_console::title(a.opt_str("title")?.unwrap_or(""))),
        "console.interactive" => json!(utopia_console::is_interactive()),
        "console.execute" => execute(&a).await?,
        "console.loop" => run_loop(&a).await?,
        _ => return Err(Fault::new(format!("console: unknown operation `{op}`"))),
    }))
}
