//! `compat crates`: the documentation of every Utopia library, converted or
//! not, as JSON for `apps/crates`. Everything comes from each library's own
//! data and metadata:
//!
//! - the catalog: every PHP package in `packages/`, the `utopia-php/*`
//!   Composer dependencies not absorbed yet, and every crate in
//!   `crates/utopia` and `crates/support`;
//! - its category: `extra.utopia.category` in the package's `composer.json`,
//!   `[package.metadata.utopia] category` in a Rust-only crate's
//!   `Cargo.toml`, or `crates/categories.json` for a dependency outside the
//!   repository; the categories' titles and descriptions come from that file;
//! - its status: complete (`bin/compat check` passes), in progress (a compat
//!   spec exists), started (a crate exists), planned (PHP only), or retired:
//!   not converted, by design (its spec's `not_converted`, with the reason;
//!   crates/CONVERSION.md §8);
//! - its text: the crate's `//!` docs, or the PHP README; modules and items
//!   from their doc comments; PHP signatures and docblocks by reflection;
//! - its examples: code blocks in doc comments (see [`super::examples`]);
//! - its tasks: the PHP package's programs besides its API, and the crate's
//!   binaries that port them (see [`super::tasks`]);
//! - its dependencies: the crate's, the PHP package's requirements, and what
//!   uses it: Utopia crates, Utopia PHP packages and Appwrite (see
//!   [`super::dependencies`]);
//! - its size: lines of the crate and of the PHP package, as code,
//!   comments and blank lines, tests apart (see [`super::size`]); php-std
//!   also gets the inventory of the PHP functions it reimplements (see
//!   [`super::phpstd`]);
//! - its freshness: when its cases, crate, benchmarks and PHP package last
//!   changed, from git (see [`super::history`]); the index says when the
//!   data was generated.
//!
//! `<out>/index.json` lists categories and libraries; `<out>/<slug>.json`
//! holds one library; `<out>/graph.json` is the ecosystem's dependency graph.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use serde_json::{Map, Value, json};

use super::cases;
use super::dependencies::{self, Workspace};
use super::docs::{self, RustItem};
use super::engine::Engine;
use super::examples;
use super::history::{self, History};
use super::size::{self, Lines};
use super::spec;

struct Library {
    slug: String,
    description: String,
    /// The PHP package's description, once a crate describes the library.
    php_description: String,
    category: Option<String>,
    php: Option<Php>,
    rust: Option<Rust>,
}

struct Php {
    package: String,
    /// `monorepo` (in `packages/`) or `composer` (a dependency).
    source: &'static str,
    dir: String,
    version: Option<String>,
}

struct Rust {
    name: String,
    dir: String,
    group: String,
}

/// Writes every library's documentation to `out`. Without a PHP driver
/// (`engine` is `None` or fails), PHP signatures and docblocks are left out.
pub fn write(mut engine: Option<&mut Engine>, root: &Path, out: &Path) -> Result<usize, String> {
    std::fs::create_dir_all(out).map_err(|e| format!("{}: {e}", out.display()))?;
    let categories: Value =
        read_json(&root.join("crates/categories.json")).ok_or("crates/categories.json is missing or invalid")?;
    let version = workspace_version(root);
    let mut libraries: BTreeMap<String, Library> = BTreeMap::new();

    // PHP packages in the monorepo.
    for slug in dirs(&root.join("packages")) {
        let Some(manifest) = read_json(&root.join("packages").join(&slug).join("composer.json")) else {
            continue;
        };
        libraries.insert(
            slug.clone(),
            Library {
                description: manifest["description"].as_str().unwrap_or_default().to_owned(),
                php_description: String::new(),
                category: manifest["extra"]["utopia"]["category"].as_str().map(str::to_owned),
                php: Some(Php {
                    package: manifest["name"].as_str().unwrap_or_default().to_owned(),
                    source: "monorepo",
                    dir: format!("packages/{slug}"),
                    version: None,
                }),
                rust: None,
                slug,
            },
        );
    }
    // Utopia libraries still installed with Composer.
    if let Some(lock) = read_json(&root.join("composer.lock")) {
        for p in lock["packages"].as_array().into_iter().flatten() {
            let Some(slug) = p["name"].as_str().and_then(|n| n.strip_prefix("utopia-php/")) else {
                continue;
            };
            if libraries.contains_key(slug) {
                continue;
            }
            libraries.insert(
                slug.to_owned(),
                Library {
                    slug: slug.to_owned(),
                    description: p["description"].as_str().unwrap_or_default().to_owned(),
                    php_description: String::new(),
                    category: categories["external"][slug].as_str().map(str::to_owned),
                    php: Some(Php {
                        package: format!("utopia-php/{slug}"),
                        source: "composer",
                        dir: format!("vendor/utopia-php/{slug}"),
                        version: p["version"].as_str().map(str::to_owned),
                    }),
                    rust: None,
                },
            );
        }
    }
    // Rust crates.
    for group in ["utopia", "support"] {
        for slug in dirs(&root.join("crates").join(group)) {
            let dir = format!("crates/{group}/{slug}");
            let Ok(manifest) = std::fs::read_to_string(root.join(&dir).join("Cargo.toml")) else {
                continue;
            };
            let lib = libraries.entry(slug.clone()).or_insert_with(|| Library {
                slug: slug.clone(),
                description: String::new(),
                php_description: String::new(),
                category: None,
                php: None,
                rust: None,
            });
            // A crate describes itself; the PHP package's description stays for the migration view.
            if let Some(description) = field(&manifest, "package", "description") {
                lib.php_description = std::mem::replace(&mut lib.description, description);
            }
            if let Some(c) = field(&manifest, "package.metadata.utopia", "category") {
                lib.category = Some(c);
            }
            lib.rust = Some(Rust {
                name: field(&manifest, "package", "name").unwrap_or_else(|| slug.clone()),
                dir,
                group: group.to_owned(),
            });
        }
    }

    // Dependencies first: each library lists the Utopia crates that use it.
    let workspace = Workspace::load(
        root,
        libraries.values().filter_map(|l| l.rust.as_ref().map(|r| (r.name.clone(), l.slug.clone()))).collect(),
    );
    let rust_deps: BTreeMap<String, Vec<Value>> = libraries
        .values()
        .filter_map(|l| l.rust.as_ref().map(|r| (l.slug.clone(), workspace.rust(root, &r.dir))))
        .collect();
    let used_by = dependencies::used_by(&rust_deps);
    let known: BTreeMap<String, bool> = libraries.keys().map(|k| (k.clone(), true)).collect();
    let php_deps_of: BTreeMap<String, Vec<Value>> = libraries
        .values()
        .filter_map(|l| l.php.as_ref().map(|p| (l.slug.clone(), workspace.php(root, &p.package, &p.dir, &known))))
        .collect();
    let php_used_by = dependencies::used_by(&php_deps_of);
    let packages: Vec<(String, String, String)> = libraries
        .values()
        .filter_map(|l| l.php.as_ref().map(|p| (l.slug.clone(), p.package.clone(), p.dir.clone())))
        .collect();
    let appwrite = workspace.appwrite(root, &workspace.namespaces(root, &packages));

    let history = History::new(root);
    let mut php_ok = engine.is_some();
    // PHP packages only installed in the PHP driver's image: their sources, from there.
    let mut vendored: BTreeMap<String, Lines> = BTreeMap::new();
    if let Some(engine) = engine.as_deref_mut() {
        let dirs: Vec<String> = libraries
            .values()
            .filter_map(|l| l.php.as_ref().filter(|p| p.source == "composer"))
            .flat_map(|p| [format!("{}/src", p.dir), format!("{}/tests", p.dir)])
            .collect();
        match engine.php_sources(&dirs) {
            Ok(found) => {
                for (dir, files) in found.as_object().into_iter().flatten() {
                    let texts: Vec<(String, String)> = files
                        .as_object()
                        .into_iter()
                        .flatten()
                        .filter_map(|(path, text)| {
                            crate::adapter::bytes(text)
                                .map(|b| (path.clone(), String::from_utf8_lossy(&b).into_owned()))
                        })
                        .collect();
                    vendored.insert(dir.clone(), size::php(texts.iter().map(|(p, t)| (p.as_str(), t.as_str()))));
                }
            }
            Err(e) => eprintln!("compat crates: PHP unavailable, vendored PHP sizes left out ({e})"),
        }
    }
    let mut summaries = Vec::new();
    for lib in libraries.values() {
        let spec = read_json(&root.join("tests/compat").join(&lib.slug).join("spec.json"));
        // Not converted, by design: the reason, and no compat suite.
        let not_converted = spec.as_ref().and_then(|s| s["not_converted"]["reason"].as_str()).map(str::to_owned);
        let compat = spec.as_ref().filter(|_| not_converted.is_none()).map(|s| {
            let cases = cases::load_dir(&root.join("tests/compat").join(&lib.slug).join("cases"))
                .map(|files| files.iter().map(|f| f.cases.len()).sum::<usize>())
                .unwrap_or(0);
            json!({
                "complete": s["complete"].as_bool().unwrap_or(false),
                "operations": s["ops"].as_object().map(Map::len).unwrap_or(0),
                "cases": cases,
                "summary": s["description"],
            })
        });
        let status = match (&compat, &lib.rust) {
            _ if not_converted.is_some() => "retired",
            (Some(c), _) if c["complete"] == true => "complete",
            (Some(_), _) => "progress",
            (None, Some(_)) => "started",
            (None, None) => "planned",
        };

        // The PHP API, by reflection.
        let mut symbols: Vec<String> = Vec::new();
        let mut php_docs = Value::Null;
        if php_ok && let Some(engine) = engine.as_deref_mut() {
            let found = match (&lib.php, &spec) {
                (Some(php), _) => engine.inventory_of(&[format!("{}/src", php.dir)]),
                // Engine built-ins: the functions the library's operations cover.
                (None, Some(_)) => Ok(spec::load(root, &lib.slug)
                    .map(|spec| {
                        spec.ops
                            .values()
                            .flat_map(|op| op.covers.iter())
                            .filter(|c| !c.contains('*'))
                            .cloned()
                            .collect::<BTreeSet<_>>()
                            .into_iter()
                            .collect()
                    })
                    .unwrap_or_default()),
                (None, None) => Ok(Vec::new()),
            };
            match found.and_then(|s| {
                let docs = engine.php_docs(&s)?;
                Ok((s, docs))
            }) {
                Ok((s, d)) => {
                    symbols = s;
                    php_docs = d;
                }
                Err(e) => {
                    eprintln!("compat crates: PHP unavailable, PHP signatures left out ({e})");
                    php_ok = false;
                }
            }
        }

        // The Rust API.
        let (modules, items, reexports) = match &lib.rust {
            Some(r) => (docs::modules(root, &r.dir), docs::items(root, &r.dir), docs::reexports(root, &r.dir)),
            None => (Vec::new(), Vec::new(), Vec::new()),
        };
        let links = docs::link(&symbols, &items);
        let mut ports: BTreeMap<usize, Vec<&str>> = BTreeMap::new();
        for (symbol, linked) in &links {
            for (i, _) in linked {
                ports.entry(*i).or_default().push(symbol);
            }
        }
        let mut example_count = 0;
        // php-std is about PHP semantics: its docs stay whole.
        let support = lib.rust.as_ref().is_some_and(|r| r.group == "support");
        let rust_items: Vec<Value> = items
            .iter()
            .enumerate()
            .map(|(k, i)| {
                let (doc, list) = examples::split(&i.doc);
                example_count += list.len();
                let (doc, notes) = if support { (doc, String::new()) } else { migration_notes(&doc) };
                let mut v = i.to_json();
                v["doc"] = json!(doc);
                v["php_notes"] = json!(notes);
                if let Some(inherited) = &i.inherited_doc() {
                    let text = examples::split(inherited).0;
                    v["inherited_doc"] = json!(if support { text } else { migration_notes(&text).0 });
                }
                v["examples"] = Value::Array(list.iter().map(examples::Example::to_json).collect());
                v["php"] = json!(ports.get(&k).cloned().unwrap_or_default());
                v["export"] = json!(export(i, &reexports));
                v
            })
            .collect();
        let rust_of = |symbol: &str| -> Vec<String> {
            links.get(symbol).map(|l| l.iter().map(|(i, _)| items[*i].path.clone()).collect()).unwrap_or_default()
        };
        let php_api: Vec<Value> = symbols
            .iter()
            .filter_map(|symbol| {
                let d = php_docs.get(symbol)?;
                let (class, name) = match symbol.split_once("::") {
                    Some((c, m)) => (Some(c), m),
                    None => (None, symbol.trim_end_matches("()")),
                };
                Some(json!({
                    "symbol": symbol,
                    "class": class,
                    "namespace": class.map(|c| c.rsplit_once('\\').map(|(n, _)| n).unwrap_or("")).unwrap_or_else(|| name.rsplit_once('\\').map(|(n, _)| n).unwrap_or("")),
                    "name": name.rsplit('\\').next().unwrap_or(name),
                    "signature": d["signature"],
                    "static": d["static"],
                    "params": d["params"],
                    "returns": d["returns"],
                    "doc": docblock(d["doc"].as_str().unwrap_or_default()),
                    "class_doc": docblock(d["classDoc"].as_str().unwrap_or_default()),
                    "file": d["file"],
                    "line": d["line"],
                    "rust": rust_of(symbol),
                }))
            })
            .collect();

        let crate_doc = modules.iter().find(|m| m.path.is_empty()).map(|m| m.doc.clone()).unwrap_or_default();
        let (overview, overview_examples, overview_notes) = if crate_doc.is_empty() {
            let readme = lib
                .php
                .as_ref()
                .and_then(|p| std::fs::read_to_string(root.join(&p.dir).join("README.md")).ok())
                .unwrap_or_default();
            // A PHP library's README is all migration content.
            (String::new(), Vec::new(), readme_text(&readme))
        } else {
            let (text, list) = examples::split(&crate_doc);
            let (text, notes) = if support { (text, String::new()) } else { migration_notes(&text) };
            (text, list, notes)
        };
        example_count += overview_examples.len();
        // The getting started guide: `guide.md` next to the crate's manifest.
        let guide = lib
            .rust
            .as_ref()
            .and_then(|r| std::fs::read_to_string(root.join(&r.dir).join("guide.md")).ok())
            .map(|g| examples::blocks(&g))
            .unwrap_or_default();
        example_count += guide.iter().filter(|b| matches!(b, examples::Block::Example(_))).count();
        let documented = items.iter().filter(|i| !i.doc.is_empty() || i.inherits.is_some()).count();
        let tasks = super::tasks::docs(
            if php_ok { engine.as_deref_mut() } else { None },
            root,
            &lib.slug,
            lib.php.as_ref().map(|p| p.dir.as_str()),
            lib.rust.as_ref().map(|r| r.dir.as_str()),
        );
        let count = |status: &str| tasks.iter().filter(|t| t["status"] == status).count();
        let sync = super::sync::status(
            root,
            &lib.slug,
            lib.rust.as_ref().map(|r| r.dir.as_str()),
            lib.php.as_ref().is_some_and(|p| p.source == "monorepo"),
        );
        // `compat bench --write` results; the summary keeps the geometric mean of the speedups.
        // Each benchmark's `compat` reason comes from the spec (as `bench` writes it), so a
        // file timed before the benchmark was listed there shows it too; the mean leaves
        // compat benchmarks out, as no Rust hot path runs them.
        let mut bench = read_json(&root.join("tests/compat").join(&lib.slug).join("bench.json"));
        let compat_benches = super::bench::spec_benchmarks(root, &lib.slug, "compat");
        if let Some(results) = bench.as_mut().and_then(|b| b["results"].as_array_mut()) {
            for result in results.iter_mut() {
                let reason = result["name"].as_str().and_then(|n| compat_benches.get(n)).cloned();
                if let (Some(reason), Some(result)) = (reason, result.as_object_mut()) {
                    result.insert("compat".into(), reason);
                }
            }
        }
        // The geometric mean of a per-benchmark ratio over the gated benchmarks that have it:
        // the speedup (PHP time / Rust time), and PHP CPU time / Rust CPU time per call for
        // files timed since the drivers measure CPU (older ones have none: `cpu` is null).
        let mean = |ratio: &dyn Fn(&Value) -> Option<f64>| -> (Option<f64>, usize) {
            let ratios: Vec<f64> = bench
                .as_ref()
                .and_then(|b| b["results"].as_array())
                .map(|r| r.iter().filter(|x| x["compat"].is_null()).filter_map(ratio).filter(|x| *x > 0.0).collect())
                .unwrap_or_default();
            let mean =
                (!ratios.is_empty()).then(|| (ratios.iter().map(|x| x.ln()).sum::<f64>() / ratios.len() as f64).exp());
            (mean, ratios.len())
        };
        let (speedup, benchmarks) = mean(&|x| x["speedup"].as_f64());
        let (cpu, _) = mean(&|x| match (x["php"]["cpu"].as_f64(), x["rust"]["cpu"].as_f64()) {
            (Some(p), Some(r)) if r > 0.0 => Some(p / r),
            _ => None,
        });
        let updated = updated(&history, lib, bench.as_ref());
        let size = library_size(root, lib, &vendored);
        // php-std: the PHP functions it reimplements, each with its Rust path and proof.
        let php_functions = (lib.slug == "php-std")
            .then(|| {
                let module_docs = modules.iter().map(|m| (m.path.clone(), m.doc.clone())).collect();
                super::phpstd::inventory(root, &items, &module_docs)
            })
            .flatten();
        let deps = rust_deps.get(&lib.slug).cloned().unwrap_or_default();
        let of_kind = |kind: &str| deps.iter().filter(|d| d["kind"] == kind).cloned().collect::<Vec<_>>();
        let users = used_by.get(&lib.slug).cloned().unwrap_or_default();
        let php_users = php_used_by.get(&lib.slug).cloned().unwrap_or_default();
        let php_deps = php_deps_of.get(&lib.slug).cloned().unwrap_or_default();
        let appwrite_rust = appwrite.rust.get(&lib.slug).cloned().unwrap_or_default();
        let appwrite_php = appwrite.php.get(&lib.slug).cloned();
        let uses_appwrite = !appwrite_rust.is_empty() || appwrite_php.is_some();
        // Every dependent once, whichever runtime it depends through.
        let dependents =
            users.iter().chain(&php_users).filter_map(|u| u["slug"].as_str()).collect::<BTreeSet<_>>().len()
                + usize::from(uses_appwrite);
        // The same through Rust alone: what the docs show outside migration mode.
        let rust_dependents = users.len() + usize::from(!appwrite_rust.is_empty());
        let dependencies = json!({
            "rust": of_kind("normal"),
            "dev": of_kind("dev"),
            "build": of_kind("build"),
            "used_by": users,
            "php_used_by": php_users,
            "appwrite": uses_appwrite.then(|| json!({ "rust": appwrite_rust, "php": appwrite_php })),
            "php": php_deps,
        });
        let summary = json!({
            "slug": lib.slug,
            "title": title(&lib.slug),
            "category": lib.category.clone().unwrap_or_else(|| "runtime".to_owned()),
            "description": lib.description,
            "php_description": if lib.php_description.is_empty() { None } else { Some(&lib.php_description) },
            "status": status,
            "php": lib.php.as_ref().map(|p| json!({ "package": p.package, "source": p.source, "dir": p.dir, "version": p.version })),
            "rust": lib.rust.as_ref().map(|r| json!({ "name": r.name, "crate": r.name.replace('-', "_"), "dir": r.dir, "group": r.group, "version": version })),
            "compat": compat,
            "not_converted": not_converted.as_ref().map(|reason| json!({ "reason": reason })),
            "sync": {
                "state": sync["state"],
                "estimated": sync["estimated"],
                "behind": sync["behind"]["commits"],
                "releases": sync["releases"].as_array().map(Vec::len).unwrap_or(0),
                "upstream": sync["upstream"]["commits"],
            },
            "bench": speedup.map(|x| json!({ "speedup": x, "benchmarks": benchmarks, "cpu": cpu })),
            "updated": updated,
            "size": size,
            "counts": {
                "items": items.len(),
                "documented": documented,
                "examples": example_count,
                "guide": !guide.is_empty(),
                "php_symbols": php_api.len(),
                "linked": php_api.iter().filter(|p| p["rust"].as_array().is_some_and(|r| !r.is_empty())).count(),
                "php_functions": php_functions.as_ref().map(|(_, totals)| totals),
                "tasks": { "total": tasks.len(), "ported": count("ported"), "waived": count("waived"), "pending": count("pending") },
                "dependencies": {
                    "rust": dependencies["rust"].as_array().map(Vec::len).unwrap_or(0),
                    "dev": dependencies["dev"].as_array().map(Vec::len).unwrap_or(0),
                    "build": dependencies["build"].as_array().map(Vec::len).unwrap_or(0),
                    "used_by": users.len(),
                    "php_used_by": php_users.len(),
                    "appwrite": uses_appwrite,
                    "dependents": dependents,
                    "rust_dependents": rust_dependents,
                    "php": php_deps.iter().filter(|d| d["kind"] == "normal").count(),
                },
            },
        });
        let install = json!({
            "cargo": lib.rust.as_ref().map(|r| format!("[dependencies]\n{} = {{ git = \"https://github.com/appwrite/appwrite\" }}", r.name)),
            "composer": lib.php.as_ref().map(|p| format!("composer require {}", p.package)),
        });
        let detail = json!({
            "library": summary,
            "install": install,
            "overview": overview,
            "overview_notes": overview_notes,
            "sync": sync,
            "bench": bench,
            "guide": guide.iter().map(examples::Block::to_json).collect::<Vec<_>>(),
            "tasks": tasks,
            "dependencies": dependencies,
            "examples": overview_examples.iter().map(examples::Example::to_json).collect::<Vec<_>>(),
            "modules": modules.iter().map(|m| {
                let (doc, list) = examples::split(&m.doc);
                let (doc, notes) = if support { (doc, String::new()) } else { migration_notes(&doc) };
                json!({ "path": m.path, "doc": doc, "php_notes": notes, "examples": list.iter().map(examples::Example::to_json).collect::<Vec<_>>(), "file": m.file, "public": m.public })
            }).collect::<Vec<_>>(),
            "items": rust_items,
            "reexports": reexports.iter().map(|(n, s)| json!({ "name": n, "source": s })).collect::<Vec<_>>(),
            "php_api": php_api,
            "php_functions": php_functions.map(|(inventory, _)| inventory),
        });
        write_json(&out.join(format!("{}.json", lib.slug)), &detail)?;
        summaries.push(summary);
    }

    let cats: Vec<Value> = categories["categories"]
        .as_array()
        .into_iter()
        .flatten()
        .map(|c| {
            let id = c["id"].as_str().unwrap_or_default();
            let members: Vec<&Value> = summaries.iter().filter(|s| s["category"] == id).map(|s| &s["slug"]).collect();
            json!({ "id": id, "title": c["title"], "description": c["description"], "libraries": members })
        })
        .collect();
    let count = summaries.len();

    // The ecosystem's dependency graph: every library, and Appwrite using them.
    let mut nodes: Vec<Value> = summaries
        .iter()
        .map(|s| {
            json!({
                "id": s["slug"],
                "kind": "library",
                "title": s["title"],
                "category": s["category"],
                "status": s["status"],
                "description": s["description"],
                "rust": s["rust"]["name"],
                "php": s["php"]["package"],
            })
        })
        .collect();
    let root_manifest = read_json(&root.join("composer.json")).unwrap_or(Value::Null);
    nodes.push(json!({
        "id": dependencies::APPWRITE,
        "kind": "app",
        "title": "Appwrite",
        "category": null,
        "status": null,
        "description": root_manifest["description"],
        "rust": null,
        "php": root_manifest["name"],
        "crates": appwrite.crates,
        "components": appwrite
            .components
            .iter()
            .map(|((kind, name), libraries)| json!({ "kind": kind, "name": name, "libraries": libraries }))
            .collect::<Vec<_>>(),
    }));
    write_json(
        &out.join("graph.json"),
        &json!({ "nodes": nodes, "edges": dependencies::edges(&rust_deps, &php_deps_of, &appwrite) }),
    )?;

    let totals = size_totals(&summaries);
    write_json(
        &out.join("index.json"),
        &json!({
            "categories": cats,
            "libraries": summaries,
            "size": totals,
            "commit": git(root, &["rev-parse", "--short", "HEAD"]),
            "commit_date": history.head(),
            "branch": git(root, &["rev-parse", "--abbrev-ref", "HEAD"]),
            "generated_at": history::now(),
        }),
    )?;
    Ok(count)
}

/// A library's size: its crate (code, and tests) and its PHP package (`src`,
/// and `tests`), each `null` when absent or unreadable.
fn library_size(root: &Path, lib: &Library, vendored: &BTreeMap<String, Lines>) -> Value {
    let rust = lib.rust.as_ref().and_then(|r| size::rust(&root.join(&r.dir))).map(|(code, tests)| {
        let mut v = code.to_json();
        v["tests"] = tests.to_json();
        v
    });
    let php = lib.php.as_ref().and_then(|p| {
        let count = |dir: String| -> Option<Lines> {
            match p.source {
                "monorepo" => {
                    let files = size::php_files(&root.join(&dir));
                    (!files.is_empty()).then(|| size::php(files.iter().map(|(p, t)| (p.as_str(), t.as_str()))))
                }
                _ => vendored.get(&dir).copied(),
            }
        };
        let src = count(format!("{}/src", p.dir))?;
        let mut v = src.to_json();
        v["tests"] = count(format!("{}/tests", p.dir)).unwrap_or_default().to_json();
        Some(v)
    });
    json!({ "rust": rust, "php": php })
}

/// Lines across the ecosystem: every crate's Rust, every PHP package, and
/// for the libraries that have both, each side.
fn size_totals(summaries: &[Value]) -> Value {
    let sum = |pick: &dyn Fn(&Value) -> Option<&Value>| -> Value {
        let mut out =
            json!({ "total": 0, "code": 0, "comments": 0, "blank": 0, "files": 0, "tests": 0, "libraries": 0 });
        for s in summaries {
            if let Some(v) = pick(s).filter(|v| v.is_object()) {
                for key in ["total", "code", "comments", "blank", "files"] {
                    out[key] = json!(out[key].as_u64().unwrap_or(0) + v[key].as_u64().unwrap_or(0));
                }
                out["tests"] = json!(out["tests"].as_u64().unwrap_or(0) + v["tests"]["total"].as_u64().unwrap_or(0));
                out["libraries"] = json!(out["libraries"].as_u64().unwrap_or(0) + 1);
            }
        }
        out
    };
    json!({
        "rust": sum(&|s| Some(&s["size"]["rust"])),
        "php": sum(&|s| Some(&s["size"]["php"])),
        // Libraries with both: what the Rust replaces, and the Rust replacing it.
        "php_converted": sum(&|s| s["size"]["rust"].is_object().then_some(&s["size"]["php"])),
        "rust_converted": sum(&|s| s["size"]["php"].is_object().then_some(&s["size"]["rust"])),
    })
}

/// When a library's parts last changed (commit dates): its compat cases and
/// spec, its crate, its benchmark results and its PHP package; whether the
/// crate changed since the benchmarks ran (`bench_stale`); and the latest of
/// them all, with the benchmark run (`at`, which the site sorts by).
fn updated(history: &History, lib: &Library, bench: Option<&Value>) -> Value {
    let compat = format!("tests/compat/{}", lib.slug);
    let cases = history.changed(&[&format!("{compat}/cases"), &format!("{compat}/spec.json")]);
    let krate = lib.rust.as_ref().and_then(|r| history.changed(&[&r.dir]));
    let bench_changed = bench.and_then(|_| history.changed(&[&format!("{compat}/bench.json")]));
    let php = lib.php.as_ref().filter(|p| p.source == "monorepo").and_then(|p| history.changed(&[&p.dir]));
    let ran = bench.and_then(|b| b["environment"]["date"].as_str());
    let bench_stale = match (bench.and_then(|b| b["environment"]["commit"].as_str()), &lib.rust) {
        (Some(commit), Some(r)) => history.changed_since(commit, &[&r.dir]),
        _ => None,
    };
    json!({
        "cases": cases,
        "crate": krate,
        "bench": bench_changed,
        "bench_stale": bench_stale,
        "php": php,
        "at": history::latest([cases.as_deref(), krate.as_deref(), bench_changed.as_deref(), ran, php.as_deref()]),
    })
}

trait Inherited {
    fn inherited_doc(&self) -> Option<String>;
}

impl Inherited for RustItem {
    fn inherited_doc(&self) -> Option<String> {
        self.inherits.as_ref().map(|(_, doc)| doc.clone())
    }
}

/// The root re-export a user imports an item by.
fn export(i: &RustItem, reexports: &[(String, String)]) -> Option<String> {
    if i.owner.is_some() {
        return None;
    }
    reexports
        .iter()
        .find(|(n, source)| *source == i.path || (n == &i.name && source.ends_with(&format!("::{}", i.name))))
        .map(|(n, _)| n.clone())
}

/// `user-agent` → `User agent`, `http` → `HTTP`.
fn title(slug: &str) -> String {
    const UPPER: [&str; 11] = ["http", "dns", "dsn", "cdn", "cli", "smtp", "mqtt", "nats", "vcs", "di", "psr7"];
    match slug {
        "openapi" => return "OpenAPI".into(),
        "psr7" => return "PSR-7".into(),
        "php-std" => return "PHP standard library".into(),
        _ => {}
    }
    if UPPER.contains(&slug) {
        return slug.to_uppercase();
    }
    let mut words = slug.split('-');
    let first = words.next().unwrap_or_default();
    let mut out = first[..1].to_uppercase() + &first[1..];
    for w in words {
        out.push(' ');
        out.push_str(w);
    }
    out
}

/// A README as overview text: no title, badges or HTML.
fn readme_text(readme: &str) -> String {
    let mut out = Vec::new();
    let mut titled = false;
    let mut alert = false;
    for line in readme.lines() {
        let t = line.trim_start();
        if !titled && t.starts_with("# ") {
            titled = true;
            continue;
        }
        // GitHub alerts (`> [!IMPORTANT]`) carry repository notices, such as the mirror's.
        if t.starts_with("> [!") {
            alert = true;
            continue;
        }
        if alert {
            if t.starts_with('>') {
                continue;
            }
            alert = false;
        }
        if t.starts_with("[![") || t.starts_with("![") || t.starts_with('<') {
            continue;
        }
        out.push(line);
    }
    out.join("\n").trim().to_owned()
}

/// A PHP docblock without its comment markers.
fn docblock(doc: &str) -> String {
    let doc = doc.trim().trim_start_matches("/**").trim_end_matches("*/");
    doc.lines()
        .map(|l| {
            let t = l.trim_start();
            t.strip_prefix("* ").or_else(|| t.strip_prefix('*')).unwrap_or(t)
        })
        .collect::<Vec<_>>()
        .join("\n")
        .trim()
        .to_owned()
}

fn dirs(path: &Path) -> Vec<String> {
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

fn write_json(path: &Path, value: &Value) -> Result<(), String> {
    let text = serde_json::to_string(value).map_err(|e| e.to_string())?.replace('\u{FFFD}', "\\ufffd");
    std::fs::write(path, text).map_err(|e| format!("{}: {e}", path.display()))
}

/// `key = "value"` in a manifest's `[table]`.
fn field(manifest: &str, table: &str, key: &str) -> Option<String> {
    let header = format!("[{table}]");
    let mut inside = false;
    for line in manifest.lines() {
        let line = line.trim();
        if line.starts_with('[') {
            inside = line == header;
            continue;
        }
        if !inside {
            continue;
        }
        if let Some((k, v)) = line.split_once('=')
            && k.trim() == key
        {
            let v = v.trim();
            return v.strip_prefix('"').and_then(|v| v.strip_suffix('"')).map(str::to_owned);
        }
    }
    None
}

fn workspace_version(root: &Path) -> String {
    let manifest = std::fs::read_to_string(root.join("Cargo.toml")).unwrap_or_default();
    field(&manifest, "workspace.package", "version").unwrap_or_default()
}

fn git(root: &Path, args: &[&str]) -> Option<String> {
    let out = std::process::Command::new("git").args(args).current_dir(root).output().ok()?;
    out.status.success().then(|| String::from_utf8_lossy(&out.stdout).trim().to_owned())
}

/// Doc text for the official docs, and the PHP notes that belong to the
/// migration view: paragraphs and tables that mention PHP, sections under a
/// heading that does, and a leading PHP name (`` `getHost()`: ``). Code
/// blocks stay with the text.
pub fn migration_notes(doc: &str) -> (String, String) {
    let mut keep: Vec<String> = Vec::new();
    let mut notes: Vec<String> = Vec::new();
    let mut php_section = false;
    for block in paragraphs(doc) {
        let first = block.lines().next().unwrap_or_default().trim_start();
        if first.starts_with('#') {
            php_section = mentions_php(first);
            if php_section {
                notes.push(block);
            } else {
                keep.push(block);
            }
            continue;
        }
        if php_section || (!first.starts_with("```") && mentions_php(&block)) {
            notes.push(block);
            continue;
        }
        keep.push(block);
    }
    // `getHost()`: the host. → the host. (and `getHost()` becomes a note)
    if let Some(lead) = keep.first_mut()
        && let Some((name, rest)) = php_lead(lead)
    {
        notes.insert(0, format!("PHP: `{name}`"));
        *lead = rest;
        if lead.trim().is_empty() {
            keep.remove(0);
        }
    }
    (keep.join("\n\n"), notes.join("\n\n"))
}

fn mentions_php(text: &str) -> bool {
    // "PHP" as a word: `PHPass` names a hash, not the runtime.
    let word = text.match_indices("PHP").any(|(i, _)| {
        let before = text[..i].chars().next_back().is_none_or(|c| !c.is_alphanumeric());
        let after = text[i + 3..].chars().next().is_none_or(|c| !c.is_alphanumeric());
        before && after
    });
    word || text.contains("utopia-php") || text.contains("Utopia\\")
}

/// A leading `` `name(...)` `` followed by `:` or `.` naming a PHP method.
fn php_lead(text: &str) -> Option<(String, String)> {
    let rest = text.strip_prefix('`')?;
    let (name, after) = rest.split_once('`')?;
    let php = name.contains('(')
        && (name.chars().next().is_some_and(|c| c.is_ascii_lowercase())
            || name.contains("::")
            || name.starts_with("new "));
    if !php || name.contains("::") && !name.contains('(') {
        return None;
    }
    let after = after.trim_start();
    let after = after.strip_prefix(':').or_else(|| after.strip_prefix(" and").map(|_| after)).unwrap_or(after);
    let after = after.trim_start_matches(['.', ',']).trim_start();
    // Capitalise what is left: "the host." → "The host."
    let mut chars = after.chars();
    let rest = match chars.next() {
        Some(c) => c.to_uppercase().collect::<String>() + chars.as_str(),
        None => String::new(),
    };
    Some((name.to_owned(), rest))
}

/// Blank-line separated blocks, keeping fenced code in one block.
fn paragraphs(doc: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut current: Vec<&str> = Vec::new();
    let mut fenced = false;
    for line in doc.lines() {
        if line.trim_start().starts_with("```") {
            fenced = !fenced;
        }
        if !fenced && line.trim().is_empty() {
            if !current.is_empty() {
                out.push(current.join("\n"));
                current.clear();
            }
            continue;
        }
        current.push(line);
    }
    if !current.is_empty() {
        out.push(current.join("\n"));
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn php_is_a_word() {
        assert!(mentions_php("PHP returns a string."));
        assert!(mentions_php("As in PHP."));
        assert!(!mentions_php("The registered name of the PHPass hash."));
        assert!(!mentions_php("Reads a file."));
    }

    #[test]
    fn php_notes_move_out_of_the_docs() {
        let (doc, notes) = migration_notes(
            "`getHost()`: the host name.\n\nPHP returns a string.\n\n| PHP | Rust |\n|---|---|\n| a | b |\n\nPlain text.",
        );
        assert_eq!(doc, "The host name.\n\nPlain text.");
        assert!(notes.starts_with("PHP: `getHost()`"));
        assert!(notes.contains("PHP returns a string.") && notes.contains("| PHP | Rust |"));
        let (doc, notes) = migration_notes(
            "Reads a file.\n\n# Differences from PHP\n\nPaths are bytes.\n\n# Errors\n\nFails when missing.",
        );
        assert_eq!(doc, "Reads a file.\n\n# Errors\n\nFails when missing.");
        assert!(notes.contains("Paths are bytes."));
    }

    #[test]
    fn titles() {
        assert_eq!(title("user-agent"), "User agent");
        assert_eq!(title("http"), "HTTP");
        assert_eq!(title("circuit-breaker"), "Circuit breaker");
        assert_eq!(title("psr7"), "PSR-7");
    }

    #[test]
    fn readme_without_badges() {
        let r =
            "# Utopia DSN\n\n> [!IMPORTANT]\n> A read-only mirror.\n\n[![Build](x)](y)\n<img src=x>\n\nParses DSNs.\n";
        assert_eq!(readme_text(r), "Parses DSNs.");
    }
}
