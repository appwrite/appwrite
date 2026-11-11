//! `compat build`: how long each Utopia crate takes to compile.
//!
//! The crates are built in their own target directory (`target/compat-build`)
//! so the run neither reuses nor disturbs the everyday build: once to compile
//! their dependencies, then again with `cargo build --timings` after `cargo
//! clean -p` of each crate, so every crate compiles from scratch while its
//! third-party dependencies stay compiled. Incremental compilation is off, as
//! in CI. Each crate's time is read from the timings report: `lib` is its
//! library's rustc invocation, `total` adds its build script and binaries.
//! Crates compile in parallel, as in any workspace build, so a time includes
//! the contention of the crates beside it.
//!
//! `--write` adds the run, with the commit, machine and toolchain, to
//! `tests/compat/build.json`, which keeps the latest [`KEEP`] runs (newest
//! first) for `compat crates` to publish the latest and the average of each
//! crate. Times depend on the machine: the average takes only the runs on the
//! latest run's machine.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::process::Command;
use std::time::Instant;

use serde_json::{Map, Value, json};

/// The runs `tests/compat/build.json` keeps.
pub const KEEP: usize = 10;

/// The file the runs are written to, from the repository root.
pub const FILE: &str = "tests/compat/build.json";

/// A crate to time: its library slug and its package name.
struct Crate {
    slug: String,
    package: String,
}

/// Times each crate of `libs` (every crate in `crates/utopia` and
/// `crates/support` when empty), prints the times, and with `write` adds the
/// run to [`FILE`].
pub fn run(root: &Path, libs: &[String], write: bool) -> Result<bool, String> {
    let crates = crates(root, libs)?;
    let target = std::env::var("CARGO_TARGET_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|_| root.join("target"))
        .join("compat-build");
    let packages: Vec<String> = crates.iter().flat_map(|c| ["-p".to_owned(), c.package.clone()]).collect();
    let cargo = |args: &[&str]| -> Result<(), String> {
        let status = Command::new("cargo")
            .args(args)
            .args(&packages)
            .env("CARGO_TARGET_DIR", &target)
            .env("CARGO_INCREMENTAL", "0")
            .current_dir(root)
            .status()
            .map_err(|e| format!("cargo: {e}"))?;
        status.success().then_some(()).ok_or_else(|| format!("cargo {} failed", args.join(" ")))
    };
    eprintln!("compiling the dependencies of {} crates in {}", crates.len(), target.display());
    cargo(&["build", "--quiet"])?;
    cargo(&["clean", "--quiet"])?;
    let started = Instant::now();
    cargo(&["build", "--timings"])?;
    let wall = started.elapsed().as_secs_f64();
    let report = std::fs::read_to_string(target.join("cargo-timings/cargo-timing.html"))
        .map_err(|e| format!("the timings report: {e}"))?;
    let units = units(&report)?;

    let mut times = Map::new();
    for c in &crates {
        let mine: Vec<&Value> = units.iter().filter(|u| u["name"] == c.package.as_str()).collect();
        let lib = mine
            .iter()
            .filter(|u| u["target"].as_str().is_some_and(|t| t.trim().is_empty()))
            .find_map(|u| u["duration"].as_f64());
        let total: f64 = mine.iter().filter_map(|u| u["duration"].as_f64()).sum();
        if mine.is_empty() {
            eprintln!("{}: not in the timings report", c.slug);
            continue;
        }
        println!("{:<20} {:>8} {:>8.2}s", c.slug, lib.map(|s| format!("{s:.2}s")).unwrap_or_else(|| "-".into()), total);
        times.insert(c.slug.clone(), json!({ "lib": lib.map(round), "total": round(total) }));
    }
    println!("{} crates compiled in {wall:.1}s", times.len());

    if write {
        let path = root.join(FILE);
        let mut runs = std::fs::read_to_string(&path)
            .ok()
            .and_then(|t| serde_json::from_str::<Value>(&t).ok())
            .and_then(|f| f["runs"].as_array().cloned())
            .unwrap_or_default();
        runs.insert(0, json!({ "environment": environment(root, &report), "wall": round(wall), "crates": times }));
        runs.truncate(KEEP);
        let text = serde_json::to_string_pretty(&json!({ "runs": runs })).map_err(|e| e.to_string())? + "\n";
        std::fs::write(&path, text).map_err(|e| format!("{}: {e}", path.display()))?;
        eprintln!("wrote {FILE}");
    }
    Ok(times.len() == crates.len())
}

/// A library's build times from [`FILE`]: the latest run's, and the average
/// of the runs on the same machine; `None` when no run timed it.
pub fn summary(root: &Path, slug: &str) -> Option<Value> {
    let file: Value = serde_json::from_str(&std::fs::read_to_string(root.join(FILE)).ok()?).ok()?;
    let runs = file["runs"].as_array()?;
    let latest = runs.iter().find(|r| r["crates"][slug].is_object())?;
    let machine = &latest["environment"]["machine"];
    let same: Vec<&Value> = runs
        .iter()
        .filter(|r| &r["environment"]["machine"] == machine)
        .map(|r| &r["crates"][slug])
        .filter(|c| c.is_object())
        .collect();
    let average = |key: &str| {
        let values: Vec<f64> = same.iter().filter_map(|c| c[key].as_f64()).collect();
        (!values.is_empty()).then(|| round(values.iter().sum::<f64>() / values.len() as f64))
    };
    Some(json!({
        "lib": latest["crates"][slug]["lib"],
        "total": latest["crates"][slug]["total"],
        "average": { "lib": average("lib"), "total": average("total") },
        "runs": same.len(),
        "date": latest["environment"]["date"],
        "commit": latest["environment"]["commit"],
        "profile": latest["environment"]["profile"],
    }))
}

/// The latest run as a whole: when, where, and how long all its crates took.
pub fn latest(root: &Path) -> Option<Value> {
    let file: Value = serde_json::from_str(&std::fs::read_to_string(root.join(FILE)).ok()?).ok()?;
    let run = file["runs"].get(0)?;
    Some(json!({
        "environment": run["environment"],
        "wall": run["wall"],
        "crates": run["crates"].as_object().map(Map::len).unwrap_or(0),
    }))
}

/// The crates to time: those of `libs`, or every crate of `crates/utopia`
/// and `crates/support`.
fn crates(root: &Path, libs: &[String]) -> Result<Vec<Crate>, String> {
    let mut found = BTreeMap::new();
    for group in ["crates/utopia", "crates/support"] {
        for entry in std::fs::read_dir(root.join(group)).map_err(|e| format!("{group}: {e}"))?.flatten() {
            let slug = entry.file_name().to_string_lossy().into_owned();
            let Ok(manifest) = std::fs::read_to_string(entry.path().join("Cargo.toml")) else { continue };
            let package = manifest.lines().skip_while(|l| l.trim() != "[package]").find_map(|l| {
                l.trim().strip_prefix("name").map(|r| r.trim_start_matches([' ', '=']).trim_matches('"').to_owned())
            });
            if let Some(package) = package {
                found.insert(slug.clone(), Crate { slug, package });
            }
        }
    }
    if libs.is_empty() {
        return Ok(found.into_values().collect());
    }
    libs.iter()
        .map(|l| found.remove(l).ok_or_else(|| format!("{l}: no crate in crates/utopia or crates/support")))
        .collect()
}

/// The units of a `cargo build --timings` report: the `UNIT_DATA` array its
/// script embeds, one object per rustc or build-script invocation.
fn units(report: &str) -> Result<Vec<Value>, String> {
    let start =
        report.find("const UNIT_DATA = ").ok_or("the timings report has no UNIT_DATA")? + "const UNIT_DATA = ".len();
    // The array is followed by the rest of the script: read the first value only.
    let value = serde_json::Deserializer::from_str(&report[start..])
        .into_iter::<Value>()
        .next()
        .ok_or("UNIT_DATA is empty")?
        .map_err(|e| format!("UNIT_DATA: {e}"))?;
    value.as_array().cloned().ok_or_else(|| "UNIT_DATA is not an array".into())
}

/// Where the times come from: the commit, the machine, the toolchain and the
/// parallelism cargo used (from the report's summary).
fn environment(root: &Path, report: &str) -> Value {
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
    // `Max concurrency:</td><td>18 (jobs=16 ncpu=16)</td>`
    let jobs = report
        .split_once("jobs=")
        .and_then(|(_, r)| r.split(|c: char| !c.is_ascii_digit()).next())
        .and_then(|n| n.parse::<u64>().ok());
    json!({
        "date": super::history::now(),
        "commit": run("git", &["rev-parse", "--short=10", "HEAD"]),
        "machine": super::bench::machine(),
        "rustc": run("rustc", &["-V"]),
        "profile": "dev",
        "jobs": jobs,
    })
}

fn round(seconds: f64) -> f64 {
    (seconds * 100.0).round() / 100.0
}
