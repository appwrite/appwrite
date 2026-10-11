//! `compat bench`: each library's benchmarks on PHP and Rust, side by side.
//!
//! Both drivers time the same named benchmarks in-process (see
//! [`crate::bench`]). `--write` stores the results with the machine and
//! runtimes they ran on in `tests/compat/<lib>/bench.json`, which
//! `compat crates` publishes on the docs site. Timings depend on the machine:
//! compare runtimes within one file, not files from different machines.

use std::path::Path;
use std::process::Command;

use serde_json::{Map, Value, json};

use super::engine::Engine;
use super::spec;

/// Builds the release Rust driver and points the engine at it: benchmarks
/// of a debug build say nothing. `COMPAT_RUST` still wins.
pub fn use_release_driver(engine: &mut Engine, root: &Path) -> Result<(), String> {
    if std::env::var_os("COMPAT_RUST").is_some() {
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
                "{lib} {name:<28} php {:>12}  rust {:>12}  {:>8.1}x  {:>6.1} allocs",
                time(p["ns"].as_f64()),
                time(r["ns"].as_f64()),
                speedup,
                r["allocations"].as_f64().unwrap_or(0.0)
            );
            if speedup < 1.0 {
                eprintln!("{lib}: `{name}` is slower in Rust");
                ok = false;
            }
            results.push(json!({
                "name": name,
                "description": rust.get(name).or_else(|| php.get(name)).cloned().unwrap_or(Value::Null),
                "php": p,
                "rust": r,
                "speedup": speedup,
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
    Ok(json!({
        "date": run("date", &["-u", "+%Y-%m-%dT%H:%M:%SZ"]),
        "commit": run("git", &["rev-parse", "--short=10", "HEAD"]),
        "machine": { "os": std::env::consts::OS, "arch": std::env::consts::ARCH, "cpu": cpu },
        "php": {
            "version": php["version"],
            "opcache": php["opcache"],
            "jit": php["jit"],
            "runs": "in a container of the dev image (bin/compat-php)",
        },
        "rust": { "version": run("rustc", &["-V"]), "profile": "release" },
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
