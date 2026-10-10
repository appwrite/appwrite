//! `compat report`: everything known about each library's conversion, as
//! JSON for `apps/compat`: coverage, every case step with both
//! runtimes' results, every fuzz profile, and both APIs' docs side by side.
//!
//! `<out>/index.json` summarizes every library and `<out>/<lib>.json` holds
//! the detail; `apps/compat` renders them.

use std::path::Path;

use std::collections::BTreeMap;

use serde_json::{Map, Value, json};

use super::coverage::{coverage, matches};
use super::docs;
use super::engine::{Engine, FuzzOptions, clip};
use super::interface;
use super::spec::{self, Spec};

pub struct Options {
    pub iterations: u64,
    pub seed: u64,
}

/// Writes the report of `libs` to `out`; `false` when a library has a
/// difference or a fault.
pub fn write(engine: &mut Engine, libs: &[String], opts: &Options, out: &Path) -> Result<bool, String> {
    let data = out;
    std::fs::create_dir_all(data).map_err(|e| format!("{}: {e}", data.display()))?;
    let root = engine.cfg.root.clone();
    let mut summaries = Vec::new();
    let mut ok = true;
    for lib in libs {
        eprintln!("report: {lib}");
        let spec = spec::load(&root, lib)?;
        let cov = coverage(engine, lib)?;
        engine.start_trace();
        let run = engine.run_lib(lib, None, false)?;
        let fuzz = engine.fuzz_lib(
            lib,
            &FuzzOptions { op: None, iterations: Some(opts.iterations), seed: opts.seed, save: false },
        )?;
        let trace = engine.take_trace();
        ok &= run.ok() && fuzz.ok();

        let symbols = symbols(engine, &spec)?;
        let php = engine.php_docs(&symbols)?;
        let crate_dir = spec.rust.get("crate").and_then(Value::as_str).unwrap_or_default();
        let items = docs::items(&root, crate_dir);
        let links = docs::link(&symbols, &items);
        // What exercises each operation: case steps (and how many failed) and fuzzed inputs.
        let mut op_steps: BTreeMap<String, (u64, u64)> = BTreeMap::new();
        for case in &trace.cases {
            for step in case["steps"].as_array().into_iter().flatten() {
                let e = op_steps.entry(step["op"].as_str().unwrap_or_default().to_owned()).or_default();
                e.0 += 1;
                if step["status"] != "match" {
                    e.1 += 1;
                }
            }
        }
        let mut op_fuzz: BTreeMap<String, (u64, bool)> = BTreeMap::new();
        for f in &trace.fuzz {
            let e = op_fuzz.entry(f["op"].as_str().unwrap_or_default().to_owned()).or_insert((0, true));
            e.0 += f["inputs"].as_u64().unwrap_or(0);
            e.1 &= f["ok"].as_bool().unwrap_or(false);
        }
        let mut methods = Vec::new();
        let mut used = vec![false; items.len()];
        let doc_entries: Vec<Value> = symbols
            .iter()
            .map(|symbol| {
                let rust: Vec<Value> = links
                    .get(symbol)
                    .map(|l| {
                        l.iter()
                            .map(|(i, via)| {
                                used[*i] = true;
                                let mut v = items[*i].to_json();
                                v["via"] = json!(via);
                                v
                            })
                            .collect()
                    })
                    .unwrap_or_default();
                let ops: Vec<&String> = spec
                    .ops
                    .iter()
                    .filter(|(_, op)| op.covers.iter().any(|c| matches(c, symbol)))
                    .map(|(name, _)| name)
                    .collect();
                let waiver = spec.waivers.iter().find(|(p, _)| matches(p, symbol)).map(|(_, r)| r);
                methods.push(method(
                    symbol,
                    &php[symbol],
                    &items,
                    links.get(symbol),
                    &ops,
                    waiver,
                    &op_steps,
                    &op_fuzz,
                ));
                json!({
                    "symbol": symbol,
                    "php": php.get(symbol),
                    "rust": rust,
                    "ops": ops,
                    "waiver": waiver,
                })
            })
            .collect();
        let rust_only: Vec<Value> = items
            .iter()
            .zip(&used)
            .filter(|(i, used)| !**used && i.kind != "const" && i.implements.is_none())
            .map(|(i, _)| i.to_json())
            .collect();

        let cases_failed = trace.cases.iter().filter(|c| c["ok"] == false).count();
        let fuzz_inputs: u64 = trace.fuzz.iter().filter_map(|f| f["inputs"].as_u64()).sum();
        let configured: u64 = trace.fuzz.iter().filter_map(|f| f["iterations"].as_u64()).sum();
        let linked = doc_entries.iter().filter(|d| d["rust"].as_array().is_some_and(|r| !r.is_empty())).count();
        let php_documented =
            doc_entries.iter().filter(|d| d["php"]["doc"].as_str().is_some_and(|s| !s.is_empty())).count();
        let mut verdicts: BTreeMap<&str, usize> = BTreeMap::new();
        for m in &methods {
            *verdicts.entry(m["verdict"].as_str().unwrap_or("missing")).or_default() += 1;
        }
        let untested = methods.iter().filter(|m| m["evidence"]["steps"] == 0 && m["evidence"]["fuzz"] == 0).count();
        let summary = json!({
            "lib": lib,
            "interface": {
                "methods": methods.len(),
                "verdicts": verdicts,
                "untested": untested,
            },
            "description": spec.description,
            "complete": spec.complete,
            "php": spec.php.src,
            "crate": crate_dir,
            "coverage": {
                "symbols": cov.symbols,
                "covered": cov.covered,
                "waived": cov.waived,
                "percent": cov.percent(),
                "uncovered": cov.uncovered,
                "dangling": cov.dangling,
                "ops": cov.ops,
                "missing_php": cov.missing_php,
                "missing_rust": cov.missing_rust,
                "ops_without_cases": cov.ops_without_cases,
                "cases": cov.cases,
                "steps": cov.steps,
                "steps_without_expect": cov.steps_without_expect,
                "tests": cov.tests,
                "tests_unported": cov.tests_unported,
                "ok": cov.ok(),
            },
            "run": {
                "cases": trace.cases.len(),
                "passed": run.passed,
                "failed": cases_failed,
                "differences": run.differences.len(),
                "faults": run.faults.len(),
            },
            "fuzz": {
                "profiles": trace.fuzz.len(),
                "inputs": fuzz_inputs,
                "configured": configured,
                "differences": fuzz.differences.len(),
                "faults": fuzz.faults.len(),
            },
            "docs": {
                "symbols": symbols.len(),
                "linked": linked,
                "php_documented": php_documented,
                "rust_items": items.len(),
                "rust_only": rust_only.len(),
            },
        });
        let detail = json!({
            "summary": summary,
            "spec": spec_json(&spec),
            "cases": trace.cases,
            "fuzz": trace.fuzz,
            "docs": doc_entries,
            "interface": methods,
            "rust_only": rust_only,
            "problems": run.differences.iter().chain(&run.faults).chain(&fuzz.differences).chain(&fuzz.faults).map(|p| clip(&json!(p))).collect::<Vec<_>>(),
        });
        write_json(&data.join(format!("{lib}.json")), &detail)?;
        summaries.push(summary);
    }
    let index = json!({
        "generated": now(),
        "commit": git(&root, &["rev-parse", "--short", "HEAD"]),
        "branch": git(&root, &["rev-parse", "--abbrev-ref", "HEAD"]),
        "seed": opts.seed,
        "iterations": opts.iterations,
        "libs": summaries,
    });
    write_json(&data.join("index.json"), &index)?;
    Ok(ok)
}

/// The PHP symbols to document: the library's public API, or, for engine
/// built-ins (no PHP source), the functions its operations cover.
fn symbols(engine: &mut Engine, spec: &Spec) -> Result<Vec<String>, String> {
    if !spec.php.src.is_empty() {
        return engine.inventory(spec);
    }
    let mut all: Vec<String> =
        spec.ops.values().flat_map(|op| op.covers.iter()).filter(|c| !c.contains('*')).cloned().collect();
    all.sort();
    all.dedup();
    Ok(all)
}

fn spec_json(spec: &Spec) -> Value {
    let ops: Map<String, Value> = spec
        .ops
        .iter()
        .map(|(name, op)| {
            (
                name.clone(),
                json!({
                    "doc": op.doc,
                    "covers": op.covers,
                    "mask": op.mask.as_ref().map(|m| json!({ "paths": m.paths, "reason": m.reason })),
                    "fuzz": op.fuzz.iter().map(|f| json!({ "name": f.name, "iterations": f.iterations, "isolate": f.isolate })).collect::<Vec<_>>(),
                }),
            )
        })
        .collect();
    json!({
        "description": spec.description,
        "services": spec.services,
        "state": spec.state,
        "ops": ops,
        "waivers": spec.waivers,
        "tests_waived": spec.tests_waived,
        "quirks": spec.quirks.iter().map(|q| json!({ "symbol": q.symbol, "description": q.description, "case": q.case })).collect::<Vec<_>>(),
        "deviations": spec.deviations.iter().map(|d| json!({ "symbol": d.symbol, "php": d.php, "reason": d.reason })).collect::<Vec<_>>(),
    })
}

fn write_json(path: &Path, value: &Value) -> Result<(), String> {
    // U+FFFD (cases on invalid UTF-8 use it) is written as its escape, the same
    // JSON value: some hosts refuse files carrying the raw character.
    let text = serde_json::to_string(value).map_err(|e| e.to_string())?.replace('\u{FFFD}', "\\ufffd");
    std::fs::write(path, text).map_err(|e| format!("{}: {e}", path.display()))
}

fn git(root: &Path, args: &[&str]) -> Option<String> {
    let out = std::process::Command::new("git").args(args).current_dir(root).output().ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

/// The current UTC time, RFC 3339.
fn now() -> String {
    let secs = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_secs()).unwrap_or(0);
    let (days, rem) = (secs / 86_400, secs % 86_400);
    // Civil date from days since 1970-01-01 (Howard Hinnant's algorithm).
    let z = days as i64 + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    format!("{year:04}-{month:02}-{day:02}T{:02}:{:02}:{:02}Z", rem / 3600, rem % 3600 / 60, rem % 60)
}

/// One public PHP method against its closest Rust counterpart, with what
/// exercises it. Verdicts: `same`, `compatible`, `review`, `differs`,
/// `type` (only a Rust type stands for it) or `missing`.
#[allow(clippy::too_many_arguments)]
fn method(
    symbol: &str,
    php: &Value,
    items: &[docs::RustItem],
    links: Option<&Vec<(usize, &'static str)>>,
    ops: &[&String],
    waiver: Option<&String>,
    op_steps: &BTreeMap<String, (u64, u64)>,
    op_fuzz: &BTreeMap<String, (u64, bool)>,
) -> Value {
    let rank = |v: &str| match v {
        "same" => 0,
        "compatible" => 1,
        "review" => 2,
        _ => 3,
    };
    let linked: Vec<usize> = links.map(|l| l.iter().map(|(i, _)| *i).collect()).unwrap_or_default();
    let mut candidates: Vec<Value> = linked
        .iter()
        .filter_map(|i| items[*i].function.as_ref().map(|f| interface::compare(symbol, php, &items[*i], f)))
        .collect();
    // Prefer a counterpart on the class's own type (or its trait), then the closest fit.
    let class = symbol.split_once("::").map(|(c, _)| c.rsplit('\\').next().unwrap_or(c).to_ascii_lowercase());
    let elsewhere = |c: &Value| {
        let path = c["rust"].as_str().unwrap_or_default().to_ascii_lowercase();
        class.as_ref().is_some_and(|k| !path.split("::").any(|seg| seg == k))
    };
    candidates.sort_by_key(|c| (rank(c["verdict"].as_str().unwrap_or("differs")), elsewhere(c)));
    if let Some(first) = candidates.first_mut()
        && elsewhere(first)
        && let Some(notes) = first["notes"].as_array_mut()
    {
        notes.push(json!("the Rust counterpart is on another type"));
    }
    let best = candidates.first().cloned();
    let verdict = match &best {
        Some(b) => b["verdict"].as_str().unwrap_or("review").to_owned(),
        None if !linked.is_empty() => "type".to_owned(),
        None => "missing".to_owned(),
    };
    let (mut steps, mut failing, mut fuzz, mut fuzz_ok) = (0, 0, 0, true);
    for op in ops {
        if let Some((n, f)) = op_steps.get(op.as_str()) {
            steps += n;
            failing += f;
        }
        if let Some((n, ok)) = op_fuzz.get(op.as_str()) {
            fuzz += n;
            fuzz_ok &= ok;
        }
    }
    json!({
        "symbol": symbol,
        "php": {
            "signature": php["signature"],
            "static": php["static"],
            "params": php["params"],
            "returns": php["returns"],
            "file": php["file"],
            "line": php["line"],
        },
        "verdict": verdict,
        "best": best,
        "others": candidates.iter().skip(1).map(|c| json!({ "rust": c["rust"], "verdict": c["verdict"] })).collect::<Vec<_>>(),
        "types": linked.iter().filter(|i| items[**i].function.is_none()).map(|i| items[*i].path.clone()).collect::<Vec<_>>(),
        "waiver": waiver,
        "evidence": { "ops": ops, "steps": steps, "failing": failing, "fuzz": fuzz, "fuzz_ok": fuzz_ok },
    })
}
