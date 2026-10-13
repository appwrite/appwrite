//! `compat bench`: each library's benchmarks on PHP and Rust, side by side.
//!
//! Both drivers time the same named benchmarks in-process (see
//! [`crate::bench`]), each in a Linux container on the dev stack's network
//! unless `--host` runs Rust natively. `--write` stores the results with the machine and
//! runtimes they ran on in `tests/compat/<lib>/bench.json`, which
//! `compat crates` publishes on the docs site. Timings depend on the machine:
//! compare runtimes within one file, not files from different machines.
//!
//! A library's `spec.json` sorts out benchmarks that are not plain hot paths:
//! `bench.bound` ones are bound by work both runtimes wait on equally and
//! must reach parity; `bench.compat` ones time a PHP-compatibility codec no
//! Rust hot path uses, and are reported and published with their reason but
//! never fail the run.
//!
//! The gate compares wall time only. Each timing also carries the CPU time
//! and memory the driver process spent (see [`crate::bench`]), published
//! alongside but never gated: they are noisier, and PHP's and Rust's heaps
//! are not counted the same way.

use std::path::Path;
use std::process::Command;

use serde_json::{Map, Value, json};

use super::engine::Engine;
use super::spec;

/// Builds the release Rust driver and points the engine at it: benchmarks
/// of a debug build say nothing. By default the driver is built and run in a
/// Linux container on the services' Docker network (`bin/compat-rust`), like
/// the PHP driver, so both runtimes share a kernel and reach services the same
/// way. `host` (`--host`) builds and runs it natively instead; `COMPAT_RUST`
/// still wins, and runs on the host.
pub fn use_release_driver(engine: &mut Engine, root: &Path, host: bool) -> Result<(), String> {
    if std::env::var_os("COMPAT_RUST").is_some() {
        return Ok(());
    }
    if !host {
        let script = root.join("bin/compat-rust");
        let status = Command::new(&script)
            .arg("build")
            .current_dir(root)
            .status()
            .map_err(|e| format!("{}: {e}", script.display()))?;
        if !status.success() {
            return Err("building the release driver in a container failed".into());
        }
        engine.cfg.rust = vec![script.display().to_string()];
        engine.cfg.rust_in_container = true;
        return Ok(());
    }
    let status = Command::new("cargo")
        .args(["build", "--quiet", "--release", "-p", "compat", "--bin", "compat-driver"])
        .current_dir(root)
        .status()
        .map_err(|e| format!("cargo: {e}"))?;
    if !status.success() {
        return Err("building the release driver failed".into());
    }
    let target =
        std::env::var("CARGO_TARGET_DIR").map(std::path::PathBuf::from).unwrap_or_else(|_| root.join("target"));
    engine.cfg.rust = vec![target.join("release/compat-driver").display().to_string()];
    Ok(())
}

/// Runs every benchmark of each library on both runtimes and prints them;
/// with `write`, stores them in `tests/compat/<lib>/bench.json`.
pub fn run(engine: &mut Engine, root: &Path, libs: &[String], write: bool) -> Result<bool, String> {
    let mut ok = true;
    let environment = environment(engine, root)?;
    for lib in libs {
        let php = engine.bench("php", lib, &json!({}))?;
        let rust = engine.bench("rust", lib, &json!({}))?;
        let (php, rust) = (php.as_object().cloned().unwrap_or_default(), rust.as_object().cloned().unwrap_or_default());
        let names: Vec<&String> = rust.keys().collect();
        for name in php.keys().filter(|n| !rust.contains_key(*n)) {
            eprintln!("{lib}: benchmark `{name}` exists in PHP only");
            ok = false;
        }
        for name in names.iter().filter(|n| !php.contains_key(**n)) {
            eprintln!("{lib}: benchmark `{name}` exists in Rust only");
            ok = false;
        }
        let bound = spec_benchmarks(root, lib, "bound");
        let compat = spec_benchmarks(root, lib, "compat");
        let mut results = Vec::new();
        for name in names.into_iter().filter(|n| php.contains_key(*n)) {
            let args = json!({ "name": name });
            let p = engine.bench("php", lib, &args)?;
            let r = engine.bench("rust", lib, &args)?;
            let speedup = match (p["ns"].as_f64(), r["ns"].as_f64()) {
                (Some(p), Some(r)) if r > 0.0 => p / r,
                _ => 0.0,
            };
            println!(
                "{lib} {name:<28} php {:>12}  rust {:>12}  {:>8.1}x  cpu php {:>12} rust {:>12}  {:>6.1} allocs",
                time(p["ns"].as_f64()),
                time(r["ns"].as_f64()),
                speedup,
                time(p["cpu"].as_f64()),
                time(r["cpu"].as_f64()),
                r["allocations"].as_f64().unwrap_or(0.0)
            );
            // A benchmark bound by something both runtimes wait on equally (a
            // service round trip, a fixed-cost algorithm) must reach parity; a
            // PHP-compatibility codec no Rust hot path uses is only reported;
            // every other must be faster.
            let bound = bound.get(name.as_str());
            let compat = compat.get(name.as_str());
            if let Some(reason) = compat.and_then(Value::as_str) {
                println!("{lib}: `{name}` is a compat benchmark, not gated: {reason}");
            } else if speedup < if bound.is_some() { PARITY } else { 1.0 } {
                eprintln!("{lib}: `{name}` is slower in Rust");
                ok = false;
            }
            results.push(json!({
                "name": name,
                "description": rust.get(name).or_else(|| php.get(name)).cloned().unwrap_or(Value::Null),
                "php": p,
                "rust": r,
                "speedup": speedup,
                "bound": bound,
                "compat": compat,
            }));
        }
        if results.is_empty() {
            eprintln!("{lib}: no benchmarks");
            continue;
        }
        if write {
            let path = spec::lib_dir(root, lib).join("bench.json");
            let mut out = Map::new();
            out.insert("lib".into(), json!(lib));
            out.insert("environment".into(), environment.clone());
            out.insert("results".into(), Value::Array(results));
            let mut text = serde_json::to_string_pretty(&Value::Object(out)).map_err(|e| e.to_string())?;
            text.push('\n');
            std::fs::write(&path, text).map_err(|e| format!("{}: {e}", path.display()))?;
            eprintln!("wrote {}", path.display());
        }
    }
    Ok(ok)
}

/// How close to PHP a bound benchmark must come.
const PARITY: f64 = 0.95;

/// `bench.<kind>` in the library's spec.json, each benchmark with its reason:
/// `bound` (bound by something both runtimes wait on equally) or `compat` (a
/// PHP-compatibility codec no Rust hot path uses).
pub(crate) fn spec_benchmarks(root: &Path, lib: &str, kind: &str) -> Map<String, Value> {
    std::fs::read_to_string(spec::lib_dir(root, lib).join("spec.json"))
        .ok()
        .and_then(|t| serde_json::from_str::<Value>(&t).ok())
        .and_then(|s| s["bench"][kind].as_object().cloned())
        .unwrap_or_default()
}

/// Where the numbers come from: the commit, the machine and both runtimes.
fn environment(engine: &mut Engine, root: &Path) -> Result<Value, String> {
    let run = |cmd: &str, args: &[&str]| -> String {
        Command::new(cmd)
            .args(args)
            .current_dir(root)
            .output()
            .ok()
            .filter(|o| o.status.success())
            .map(|o| String::from_utf8_lossy(&o.stdout).trim().to_owned())
            .unwrap_or_default()
    };
    let cpu = if cfg!(target_os = "macos") {
        run("sysctl", &["-n", "machdep.cpu.brand_string"])
    } else {
        std::fs::read_to_string("/proc/cpuinfo")
            .unwrap_or_default()
            .lines()
            .find_map(|l| {
                l.strip_prefix("model name").and_then(|r| r.split_once(':')).map(|(_, v)| v.trim().to_owned())
            })
            .unwrap_or_default()
    };
    let php = engine.hello_info("php")?;
    let rust = engine.hello_info("rust")?;
    let php_image = if engine.cfg.php.first().is_some_and(|c| c.ends_with("bin/compat-php")) {
        json!(std::env::var("COMPAT_IMAGE").unwrap_or_else(|_| "appwrite-dev".into()))
    } else {
        Value::Null
    };
    let (rust_version, rust_runs, rust_image) = if engine.cfg.rust_in_container {
        (
            run("bin/compat-rust", &["version"]),
            "in a container on the same Docker network (bin/compat-rust)",
            json!(run("bin/compat-rust", &["image"])),
        )
    } else {
        (run("rustc", &["-V"]), "natively on the host", Value::Null)
    };
    Ok(json!({
        "date": run("date", &["-u", "+%Y-%m-%dT%H:%M:%SZ"]),
        "commit": run("git", &["rev-parse", "--short=10", "HEAD"]),
        "machine": { "os": std::env::consts::OS, "arch": std::env::consts::ARCH, "cpu": cpu },
        "php": {
            "version": php["version"],
            "opcache": php["opcache"],
            "jit": php["jit"],
            "runs": "in a container of the dev image (bin/compat-php)",
            "image": php_image,
            "os": php["os"],
            "kernel": php["kernel"],
        },
        "rust": {
            "version": rust_version,
            "profile": "release",
            "runs": rust_runs,
            "image": rust_image,
            "os": rust["os"],
            "kernel": rust["kernel"],
        },
    }))
}

fn time(ns: Option<f64>) -> String {
    match ns {
        Some(ns) if ns >= 1e6 => format!("{:.2} ms", ns / 1e6),
        Some(ns) if ns >= 1e3 => format!("{:.2} µs", ns / 1e3),
        Some(ns) => format!("{ns:.1} ns"),
        None => "-".into(),
    }
}
