//! `compat appwrite`: the migration of the Appwrite API from PHP to Rust, as
//! JSON for `apps/crates` (`apps/crates/data/appwrite.json`). Everything comes
//! from the repository:
//!
//! - services and routes: the PHP modules and controllers ([`inventory`]);
//! - what Rust serves: the routes in `crates/appwrite/*` and the Traefik
//!   routers to the Rust container in the compose files ([`rust`]);
//! - tests: the E2E suites, the CI steps that run them against Rust, and the
//!   recorded runs in `tests/e2e/results` ([`e2e`]);
//! - benchmarks: which endpoints each service's k6 script has a scenario for
//!   ([`bench`]), and `tests/benchmarks/results/*.json`, written by
//!   `tests/benchmarks/api/compare.sh`, with whether the service's Rust code
//!   changed since (from git, see [`super::history`]);
//! - cross-cutting hooks: every `Http::init`/`shutdown`/`error` hook found in
//!   the code, with what `crates/appwrite/hooks.json` says about each one
//!   (what it does, and where `appwrite-core` reproduces it).
//!
//! Without Docker or a dev stack everything is still generated; only the
//! recorded runs and benchmarks are read, never produced.

pub mod bench;
pub mod e2e;
pub mod inventory;
pub mod php;
pub mod rust;

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use serde_json::{Value, json};

use inventory::{Endpoint, Hook, Inventory, Service};

use super::history::{self, History};

pub(crate) fn dirs(path: &Path) -> Vec<String> {
    let mut out: Vec<String> = std::fs::read_dir(path)
        .into_iter()
        .flatten()
        .flatten()
        .filter(|e| e.path().is_dir())
        .map(|e| e.file_name().to_string_lossy().into_owned())
        .collect();
    out.sort();
    out
}

fn read_json(path: &Path) -> Option<Value> {
    serde_json::from_str(&std::fs::read_to_string(path).ok()?).ok()
}

fn git(root: &Path, args: &[&str]) -> Option<String> {
    let out = std::process::Command::new("git").args(args).current_dir(root).output().ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

/// Where Rust stands on one PHP route.
fn endpoint_status(routed: bool, implemented: bool) -> &'static str {
    match (routed, implemented) {
        (true, true) => "served",
        // Traefik sends it to Rust, which has no such route: it answers 404.
        (true, false) => "missing",
        (false, true) => "ready",
        (false, false) => "php",
    }
}

/// A suite's service: same name, an SDK namespace of the service, or the
/// longest service name it starts with (`VCSGitHub` → `vcs`).
fn suite_service(suite: &str, services: &[Service]) -> Option<String> {
    let s = suite.to_ascii_lowercase();
    if let Some(x) = services.iter().find(|x| x.id == s) {
        return Some(x.id.clone());
    }
    if let Some(x) = services
        .iter()
        .find(|x| x.endpoints.iter().any(|e| e.sdk.iter().any(|m| m.namespace.to_ascii_lowercase() == s)))
    {
        return Some(x.id.clone());
    }
    services.iter().filter(|x| s.starts_with(&x.id)).max_by_key(|x| x.id.len()).map(|x| x.id.clone())
}

fn endpoint_json(e: &Endpoint, status: &str, rust_at: Option<&rust::RustRoute>, aliases: Vec<Value>) -> Value {
    json!({
        "method": e.method,
        "path": e.path,
        "aliases": aliases,
        "name": e.name,
        "desc": e.desc,
        "groups": e.groups,
        "scopes": e.scopes,
        "sdk": e.sdk.iter().map(|m| json!({ "method": format!("{}.{}", m.namespace, m.name), "visibility": m.visibility, "deprecated": m.deprecated })).collect::<Vec<_>>(),
        "docs": e.docs,
        "event": e.event,
        "labels": e.labels,
        "params": e.params,
        "file": e.file,
        "line": e.line,
        "rust": status,
        "rust_file": rust_at.map(|r| &r.file),
        "rust_line": rust_at.map(|r| r.line),
    })
}

/// An SDK endpoint: published in an SDK (not hidden) and in the docs.
fn is_sdk(e: &Endpoint) -> bool {
    e.docs && e.sdk.iter().any(|m| m.visibility != "hidden")
}

/// The line of the first occurrence of `anchor` in `file`.
fn find_line(root: &Path, file: &str, anchor: &str) -> Option<usize> {
    let src = std::fs::read_to_string(root.join(file)).ok()?;
    let at = src.find(anchor)?;
    Some(src[..at].matches('\n').count() + 1)
}

/// Joins the hooks found in the code with `crates/appwrite/hooks.json`.
fn hooks(
    root: &Path,
    found: &[Hook],
    served_groups: &BTreeSet<String>,
    any_served: bool,
    warnings: &mut Vec<String>,
) -> Vec<Value> {
    let manifest = read_json(&root.join("crates/appwrite/hooks.json")).unwrap_or(Value::Null);
    let entries = manifest["hooks"].as_array().cloned().unwrap_or_default();
    let mut used = vec![false; found.len()];
    let mut out = Vec::new();
    let needed = |kind: &str, groups: &[String]| -> bool {
        any_served
            && match kind {
                "init" | "shutdown" => groups.iter().any(|g| served_groups.contains(g)),
                _ => true,
            }
    };
    let rust_of = |entry: &Value, warnings: &mut Vec<String>| -> Value {
        let r = &entry["rust"];
        let places: Vec<Value> = r["where"]
            .as_array()
            .into_iter()
            .flatten()
            .map(|w| {
                let file = w["file"].as_str().unwrap_or_default();
                let anchor = w["anchor"].as_str().unwrap_or_default();
                let line = find_line(root, file, anchor);
                if line.is_none() {
                    warnings.push(format!(
                        "hooks.json {}: `{anchor}` not found in {file}",
                        entry["id"].as_str().unwrap_or("?")
                    ));
                }
                json!({ "file": file, "anchor": anchor, "line": line, "found": line.is_some() })
            })
            .collect();
        json!({ "status": r["status"].as_str().unwrap_or("missing"), "note": r["note"], "where": places })
    };
    for entry in &entries {
        let id = entry["id"].as_str().unwrap_or_default();
        let php = &entry["php"];
        let file = php["file"].as_str().unwrap_or_default();
        let kind = php["kind"].as_str().unwrap_or_default();
        let anchor = php["anchor"].as_str();
        if kind == "resource" {
            let line = anchor.and_then(|a| find_line(root, file, a));
            if line.is_none() {
                warnings.push(format!("hooks.json {id}: resource not found in {file}"));
            }
            out.push(json!({
                "id": id, "title": entry["title"], "description": entry["description"], "kind": kind,
                "file": file, "line": line, "groups": [], "injects": [], "labels": [], "errors": [], "headers": [],
                "needed": any_served, "matched": line.is_some(),
                "rust": rust_of(entry, warnings),
            }));
            continue;
        }
        let candidates: Vec<usize> = found
            .iter()
            .enumerate()
            .filter(|(i, h)| {
                !used[*i] && h.file == file && h.kind == kind && anchor.is_none_or(|a| h.source.contains(a))
            })
            .map(|(i, _)| i)
            .collect();
        let hook = match candidates.as_slice() {
            [i] => {
                used[*i] = true;
                Some(&found[*i])
            }
            [] => {
                warnings.push(format!("hooks.json {id}: no {kind} hook in {file} matches"));
                None
            }
            _ => {
                warnings.push(format!(
                    "hooks.json {id}: {} {kind} hooks in {file} match; add or sharpen `anchor`",
                    candidates.len()
                ));
                None
            }
        };
        let groups: Vec<String> = hook.map(|h| h.groups.clone()).unwrap_or_default();
        out.push(json!({
            "id": id,
            "title": entry["title"],
            "description": entry["description"],
            "kind": kind,
            "file": file,
            "line": hook.map(|h| h.line),
            "class": hook.and_then(|h| h.class.clone()),
            "groups": groups,
            "injects": hook.map(|h| h.injects.clone()).unwrap_or_default(),
            "labels": hook.map(|h| h.labels.clone()).unwrap_or_default(),
            "errors": hook.map(|h| h.errors.clone()).unwrap_or_default(),
            "headers": hook.map(|h| h.headers.clone()).unwrap_or_default(),
            "needed": needed(kind, &groups),
            "matched": hook.is_some(),
            "rust": rust_of(entry, warnings),
        }));
    }
    // Hooks the manifest does not describe yet: listed from the code alone.
    for (i, h) in found.iter().enumerate() {
        if used[i] {
            continue;
        }
        warnings.push(format!("{}:{}: {} hook not described in crates/appwrite/hooks.json", h.file, h.line, h.kind));
        out.push(json!({
            "id": format!("{}:{}", h.file, h.line),
            "title": if h.desc.is_empty() { Value::Null } else { json!(h.desc.lines().next().unwrap_or("")) },
            "description": h.desc,
            "kind": h.kind,
            "file": h.file,
            "line": h.line,
            "class": h.class,
            "groups": h.groups,
            "injects": h.injects,
            "labels": h.labels,
            "errors": h.errors,
            "headers": h.headers,
            "needed": needed(&h.kind, &h.groups),
            "matched": false,
            "rust": { "status": "unknown", "note": null, "where": [] },
        }));
    }
    out
}

/// Writes `<out>/appwrite.json`; returns a one-line summary.
pub fn write(root: &Path, out: &Path) -> Result<String, String> {
    let (doc, warnings) = document(root);
    std::fs::create_dir_all(out).map_err(|e| format!("{}: {e}", out.display()))?;
    let path = out.join("appwrite.json");
    std::fs::write(&path, serde_json::to_string(&doc).map_err(|e| e.to_string())?)
        .map_err(|e| format!("{}: {e}", path.display()))?;
    for w in &warnings {
        eprintln!("compat appwrite: {w}");
    }
    let summary = &doc["summary"];
    Ok(format!(
        "{} services, {} endpoints ({} served by Rust, {} benchmarked), {} hooks, {} suites",
        summary["services"],
        summary["endpoints"],
        summary["served"],
        summary["bench_covered"],
        summary["hooks"]["total"],
        summary["suites"]
    ))
}

/// Endpoints a benchmark must cover and does not: every endpoint of every
/// service has a scenario in `tests/benchmarks/api/<service>.js`, or the
/// script skips it with a reason; plus scenarios whose route is no endpoint
/// of their service.
pub fn benchmark_gaps(root: &Path) -> Vec<String> {
    let (doc, warnings) = document(root);
    let mut gaps: Vec<String> = warnings.into_iter().filter(|w| w.starts_with("tests/benchmarks/")).collect();
    for service in doc["services"].as_array().into_iter().flatten() {
        let id = service["id"].as_str().unwrap_or_default();
        if service["bench_script"].is_null() {
            gaps.push(format!("{id}: no benchmark script (tests/benchmarks/api/{id}.js)"));
            continue;
        }
        for e in doc["details"][id]["endpoints"].as_array().into_iter().flatten() {
            let route =
                format!("{} {}", e["method"].as_str().unwrap_or_default(), e["path"].as_str().unwrap_or_default());
            match e["bench"]["status"].as_str() {
                Some("covered") => {}
                Some("skipped") if e["bench"]["reason"].as_str().is_some_and(|r| !r.trim().is_empty()) => {}
                Some("skipped") => gaps.push(format!("{id}: `{route}` is skipped without a reason")),
                _ => gaps.push(format!("{id}: `{route}` has no scenario in tests/benchmarks/api/{id}.js")),
            }
        }
    }
    gaps
}

/// The migration as the JSON `write` stores, with what to warn about.
fn document(root: &Path) -> (Value, Vec<String>) {
    let Inventory { services, hooks: found, mut warnings } = inventory::read(root);
    let rust_routes = rust::routes(root);
    let routers = rust::routers(root);
    let suites = e2e::suites(root);
    let rust_runs = e2e::ci_rust_runs(root);
    let results = e2e::results(root);
    let coverage = bench::coverage(root);
    let history = History::new(root);
    let mut declared: BTreeSet<(String, String)> = BTreeSet::new();
    let benchmarks: Vec<Value> = {
        let dir = root.join("tests/benchmarks/results");
        let mut files: Vec<_> = std::fs::read_dir(&dir).into_iter().flatten().flatten().map(|e| e.path()).collect();
        files.sort();
        files.iter().filter(|p| p.extension().is_some_and(|x| x == "json")).filter_map(|p| read_json(p)).collect()
    };

    let served_routes: Vec<&rust::RustRoute> = rust_routes.iter().filter(|r| r.registered).collect();
    let rust_at = |method: &str, path: &str| -> Option<&rust::RustRoute> {
        let shape = rust::shape(path);
        served_routes.iter().copied().find(|r| r.method == method && rust::shape(&r.path) == shape)
    };
    let mut matched_rust: BTreeSet<(String, String)> = BTreeSet::new();
    let mut served_groups: BTreeSet<String> = BTreeSet::new();
    let mut any_served = false;

    let mut summaries = Vec::new();
    let mut details = serde_json::Map::new();
    let suite_of: BTreeMap<String, Option<String>> =
        suites.iter().map(|s| (s.name.clone(), suite_service(&s.name, &services))).collect();

    for s in &services {
        let mut counts: BTreeMap<&str, usize> = BTreeMap::new();
        let mut sdk_total = 0;
        let mut sdk_served = 0;
        let cover = coverage.get(&s.id);
        let mut bench_counts: BTreeMap<&str, usize> = BTreeMap::new();
        let endpoints: Vec<Value> = s
            .endpoints
            .iter()
            .map(|e| {
                let at = rust_at(&e.method, &e.path);
                if let Some(r) = at {
                    matched_rust.insert((r.method.clone(), rust::shape(&r.path)));
                }
                let status = endpoint_status(rust::routed(&routers, &e.path), at.is_some());
                *counts.entry(status).or_default() += 1;
                if status == "served" {
                    served_groups.extend(e.groups.iter().cloned());
                    any_served = true;
                }
                if is_sdk(e) {
                    sdk_total += 1;
                    sdk_served += usize::from(status == "served");
                }
                let aliases = e
                    .aliases
                    .iter()
                    .map(|a| {
                        let at = rust_at(&e.method, a);
                        json!({ "path": a, "rust": endpoint_status(rust::routed(&routers, a), at.is_some()) })
                    })
                    .collect();
                let mut out = endpoint_json(e, status, at, aliases);
                let routes: Vec<String> =
                    std::iter::once(&e.path).chain(&e.aliases).map(|p| format!("{} {p}", e.method)).collect();
                let bench = match cover {
                    Some(c) if routes.iter().any(|r| c.routes.contains(r)) => json!({ "status": "covered" }),
                    Some(c) => match routes.iter().find_map(|r| c.skipped.get(r)) {
                        Some(reason) => json!({ "status": "skipped", "reason": reason }),
                        None => json!({ "status": "missing" }),
                    },
                    None => json!({ "status": "missing" }),
                };
                declared.extend(routes.into_iter().map(|r| (s.id.clone(), r)));
                *bench_counts
                    .entry(if bench["status"] == "covered" {
                        "covered"
                    } else if bench["status"] == "skipped" {
                        "skipped"
                    } else {
                        "missing"
                    })
                    .or_default() += 1;
                out["bench"] = bench;
                out
            })
            .collect();
        let total = s.endpoints.len();
        let served = counts.get("served").copied().unwrap_or(0);
        let status = if total > 0 && served == total {
            "rust"
        } else if served > 0 || counts.contains_key("missing") {
            "partial"
        } else {
            "php"
        };
        let my_suites: Vec<&e2e::Suite> =
            suites.iter().filter(|x| suite_of.get(&x.name).and_then(|v| v.as_deref()) == Some(s.id.as_str())).collect();
        let my_results: Vec<&Value> =
            results.iter().filter(|r| my_suites.iter().any(|x| r["suite"].as_str() == Some(x.name.as_str()))).collect();
        let my_bench: Vec<&Value> =
            benchmarks.iter().filter(|b| b["service"].as_str() == Some(s.id.as_str())).collect();
        // The service's Rust code: the crates serving its routes, and its own crate.
        let mut rust_dirs: BTreeSet<String> =
            s.endpoints.iter().filter_map(|e| rust_at(&e.method, &e.path)).map(|r| r.krate.clone()).collect();
        let own = format!("crates/appwrite/{}", s.id);
        if root.join(&own).is_dir() {
            rust_dirs.insert(own);
        }
        let rust_dirs: Vec<&str> = rust_dirs.iter().map(String::as_str).collect();
        let suites_json: Vec<Value> = my_suites
            .iter()
            .map(|x| {
                let runs: Vec<&e2e::RustRun> = rust_runs.iter().filter(|r| r.suite == x.name).collect();
                json!({
                    "name": x.name,
                    "dir": x.dir,
                    "tests": x.tests(),
                    "classes": x.classes.iter().map(|c| json!({ "name": c.name, "file": c.file, "tests": c.tests })).collect::<Vec<_>>(),
                    "rust": runs.iter().map(|r| json!({ "workflow": r.workflow, "job": r.job, "line": r.line })).collect::<Vec<_>>(),
                })
            })
            .collect();
        // The newest run per suite and runtime, for the overview.
        let mut latest: BTreeMap<(String, String), &Value> = BTreeMap::new();
        for r in &my_results {
            let key = (r["suite"].as_str().unwrap_or("").to_owned(), r["runtime"].as_str().unwrap_or("").to_owned());
            if latest.get(&key).is_none_or(|old| old["date"].as_str() < r["date"].as_str()) {
                latest.insert(key, r);
            }
        }
        let latest: Vec<Value> = latest
            .values()
            .map(|r| {
                json!({
                    "suite": r["suite"], "runtime": r["runtime"], "date": r["date"], "commit": r["commit"],
                    "tests": r["tests"], "passed": r["passed"], "failures": r["failures"], "errors": r["errors"], "skipped": r["skipped"],
                })
            })
            .collect();
        summaries.push(json!({
            "id": s.id,
            "title": s.title,
            "description": s.description,
            "sources": s.sources,
            "registered": s.registered,
            "status": status,
            "counts": {
                "endpoints": total,
                "served": served,
                "ready": counts.get("ready").copied().unwrap_or(0),
                "missing": counts.get("missing").copied().unwrap_or(0),
                "sdk": sdk_total,
                "sdk_served": sdk_served,
                "bench_covered": bench_counts.get("covered").copied().unwrap_or(0),
                "bench_skipped": bench_counts.get("skipped").copied().unwrap_or(0),
                "bench_missing": bench_counts.get("missing").copied().unwrap_or(0),
            },
            "tests": {
                "suites": my_suites.iter().map(|x| &x.name).collect::<Vec<_>>(),
                "classes": my_suites.iter().map(|x| x.classes.len()).sum::<usize>(),
                "tests": my_suites.iter().map(|x| x.tests()).sum::<usize>(),
                "rust": my_suites.iter().any(|x| rust_runs.iter().any(|r| r.suite == x.name)),
                "latest": latest,
            },
            "bench": my_bench.first().map(|b| json!({
                "date": b["environment"]["date"],
                "file": b["file"],
                "commit": b["environment"]["commit"],
                // The Rust code changed after the commit the benchmark ran on.
                "stale": b["environment"]["commit"].as_str().and_then(|c| history.changed_since(c, &rust_dirs)),
            })),
            "rust_changed": history.changed(&rust_dirs),
            "bench_script": cover.map(|c| &c.script),
        }));
        details.insert(
            s.id.clone(),
            json!({
                "endpoints": endpoints,
                "suites": suites_json,
                "results": my_results,
                "benchmarks": my_bench,
            }),
        );
    }

    // Scenarios and skips whose route is no endpoint of their service (a typo, or a route that moved).
    for (service, c) in &coverage {
        for route in c.routes.iter().chain(c.skipped.keys()) {
            if !declared.contains(&(service.clone(), route.clone())) {
                warnings.push(format!("{}: `{route}` is not a route of the {service} service", c.script));
            }
        }
    }

    // Rust routes no PHP route matches (e.g. a route PHP dropped, or a typo).
    let rust_only: Vec<Value> = served_routes
        .iter()
        .filter(|r| !matched_rust.contains(&(r.method.clone(), rust::shape(&r.path))))
        .map(|r| json!({ "method": r.method, "path": r.path, "file": r.file, "line": r.line, "routed": rust::routed(&routers, &r.path) }))
        .collect();
    let unassigned: Vec<Value> = suites
        .iter()
        .filter(|s| suite_of.get(&s.name).is_some_and(Option::is_none))
        .map(|s| json!({ "name": s.name, "dir": s.dir, "tests": s.tests(), "classes": s.classes.len() }))
        .collect();

    let hook_list = hooks(root, &found, &served_groups, any_served, &mut warnings);
    let mut crates: BTreeMap<String, (usize, bool)> = BTreeMap::new();
    for r in &rust_routes {
        let c = crates.entry(r.krate.clone()).or_default();
        c.0 += 1;
        c.1 |= r.registered;
    }

    let endpoints: usize = services.iter().map(|s| s.endpoints.len()).sum();
    let count = |status: &str| summaries.iter().filter(|s| s["status"] == status).count();
    let sum = |key: &str| summaries.iter().map(|s| s["counts"][key].as_u64().unwrap_or(0)).sum::<u64>();
    let hook_count = |status: &str, needed_only: bool| {
        hook_list.iter().filter(|h| h["rust"]["status"] == status && (!needed_only || h["needed"] == true)).count()
    };
    let routing_files: BTreeSet<&str> = routers.iter().map(|r| r.file.as_str()).collect();
    let doc = json!({
        "commit": git(root, &["rev-parse", "--short", "HEAD"]),
        "commit_date": history.head(),
        "branch": git(root, &["rev-parse", "--abbrev-ref", "HEAD"]),
        "generated_at": history::now(),
        // When the inputs behind the hooks and the routing last changed.
        "updated": {
            "hooks": history.changed(&["crates/appwrite/hooks.json", "crates/appwrite/core"]),
            "routing": history.changed(&routing_files.into_iter().collect::<Vec<_>>()),
            "server": history.changed(&["crates/appwrite/server"]),
        },
        "summary": {
            "services": services.len(),
            "rust": count("rust"),
            "partial": count("partial"),
            "php": count("php"),
            "endpoints": endpoints,
            "served": sum("served"),
            "ready": sum("ready"),
            "missing": sum("missing"),
            "sdk": sum("sdk"),
            "sdk_served": sum("sdk_served"),
            "bench_covered": sum("bench_covered"),
            "bench_skipped": sum("bench_skipped"),
            "bench_missing": sum("bench_missing"),
            "suites": suites.len(),
            "tests": suites.iter().map(e2e::Suite::tests).sum::<usize>(),
            "rust_suites": suites.iter().filter(|s| rust_runs.iter().any(|r| r.suite == s.name)).count(),
            "hooks": {
                "total": hook_list.len(),
                "needed": hook_list.iter().filter(|h| h["needed"] == true).count(),
                "done": hook_count("done", false),
                "done_needed": hook_count("done", true),
                "partial": hook_count("partial", false),
                "missing": hook_count("missing", false),
                "missing_needed": hook_count("missing", true),
            },
        },
        "routing": routers.iter().map(|r| json!({
            "name": r.name, "rule": r.rule, "paths": r.paths, "prefixes": r.prefixes, "priority": r.priority,
            "file": r.file, "line": r.line, "profile": r.profile,
        })).collect::<Vec<_>>(),
        "rust": {
            "crates": crates.iter().map(|(dir, (routes, registered))| json!({ "dir": dir, "routes": routes, "registered": registered })).collect::<Vec<_>>(),
            "routes": served_routes.len(),
            "unmatched": rust_only,
        },
        "services": summaries,
        "details": details,
        "hooks": hook_list,
        "benchmarks": benchmarks.iter().map(|b| json!({
            "service": b["service"], "file": b["file"], "workload": b["workload"], "environment": b["environment"], "steps": b["steps"],
        })).collect::<Vec<_>>(),
        "unassigned_suites": unassigned,
        "warnings": warnings,
    });
    (doc, warnings)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn statuses() {
        assert_eq!(endpoint_status(true, true), "served");
        assert_eq!(endpoint_status(true, false), "missing");
        assert_eq!(endpoint_status(false, true), "ready");
        assert_eq!(endpoint_status(false, false), "php");
    }
}
