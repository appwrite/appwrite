//! A library's tasks: the programs it ships besides its API (data imports,
//! code generators, maintenance commands), which a converted crate ports as
//! binaries (crates/CONVERSION.md §2 rule 6).
//!
//! A PHP package's tasks are discovered, never listed by hand, so a new
//! script cannot be missed:
//!
//! - every `bin` of its `composer.json`;
//! - every `composer.json` script, except development tooling (scripts named
//!   `test`, `lint`, `format`, `analyze`, `check`, `refactor`, `bench` and
//!   the like, or `<name>:<anything>` of those). A script that only runs a
//!   PHP file of the package (`php import.php all --commit=true`) is a way to
//!   run that file's task, not a task of its own;
//! - every PHP file outside `src/`, `tests/` and `vendor/` that is a program:
//!   a file whose first statement (after `declare`, `namespace` and `use`)
//!   is `return` is data or configuration (`data/data.php`, `rector.php`),
//!   not a program.
//!
//! Each task is then ported (an entry of `tasks` in `spec.json`: the Rust
//! binary that replaces it and the case files that run both against
//! fixtures) or waived (`tasks_waived`, with a reason: examples,
//! benchmarks). Anything else is pending.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use serde::Deserialize;
use serde_json::{Value, json};

use super::cases;
use super::spec::{self, Spec};

/// A task declared in `spec.json`.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TaskSpec {
    /// What the task does, for the docs when the binary has no doc comment.
    #[serde(default)]
    pub doc: String,
    /// The PHP entry point it ports: a repository path (`packages/emails/import.php`,
    /// a composer `bin`), or `<package dir>/composer.json#<script>`.
    pub php: String,
    /// The Rust binary that replaces it.
    pub rust: RustTask,
    /// Case files (relative to `tests/compat/<lib>/`) that run both runtimes'
    /// tasks against fixtures.
    pub covered_by: Vec<String>,
    /// Repository paths of the data files the Rust task writes.
    #[serde(default)]
    pub writes: Vec<String>,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct RustTask {
    /// The crate's package name (`utopia-emails`).
    pub package: String,
    /// The binary (`emails-import`): `src/bin/<bin>.rs` or a `[[bin]]`.
    pub bin: String,
}

impl RustTask {
    /// How to run it from the repository root.
    pub fn command(&self) -> String {
        format!("cargo run -p {} --bin {}", self.package, self.bin)
    }
}

/// What kind of PHP entry point a task is.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Kind {
    /// A `bin` of `composer.json`.
    Bin,
    /// A `composer.json` script that does not just run one of the package's files.
    Script,
    /// A PHP program outside `src/` and `tests/`.
    File,
}

impl Kind {
    pub fn as_str(self) -> &'static str {
        match self {
            Kind::Bin => "bin",
            Kind::Script => "script",
            Kind::File => "file",
        }
    }
}

/// A PHP task.
#[derive(Debug, Clone)]
pub struct Entry {
    /// The repository path of the program, or `<dir>/composer.json#<script>`.
    pub id: String,
    pub kind: Kind,
    /// How PHP runs it, from the package directory.
    pub command: String,
    /// Composer scripts that run it (`composer import:all` → `php import.php all --commit=true`).
    pub scripts: Vec<(String, String)>,
}

/// Script names that are development tooling, not tasks of the library.
const DEV_SCRIPTS: &[&str] = &[
    "test",
    "tests",
    "lint",
    "format",
    "fix",
    "cs",
    "analyze",
    "analyse",
    "check",
    "phpstan",
    "psalm",
    "pint",
    "rector",
    "refactor",
    "bench",
    "benchmark",
    "coverage",
    "infection",
    "mutation",
];

fn dev_script(name: &str) -> bool {
    let base = name.split(':').next().unwrap_or(name);
    DEV_SCRIPTS.contains(&base)
}

/// The package directories of a spec: the parents of its `php.src` entries.
pub fn package_dirs(spec: &Spec) -> Vec<String> {
    let mut dirs: Vec<String> =
        spec.php.src.iter().filter_map(|s| s.trim_end_matches('/').strip_suffix("/src")).map(str::to_owned).collect();
    dirs.dedup();
    dirs
}

/// The tasks of the PHP package in `dir` (relative to `root`), by id.
pub fn discover(root: &Path, dir: &str) -> Vec<Entry> {
    let base = root.join(dir);
    let manifest: Value = std::fs::read_to_string(base.join("composer.json"))
        .ok()
        .and_then(|t| serde_json::from_str(&t).ok())
        .unwrap_or(Value::Null);
    let mut entries: Vec<Entry> = Vec::new();

    let mut files = Vec::new();
    programs(&base, &base, &mut files);
    files.sort();
    for rel in files {
        entries.push(Entry {
            id: format!("{dir}/{rel}"),
            kind: Kind::File,
            command: format!("php {rel}"),
            scripts: Vec::new(),
        });
    }
    for bin in manifest["bin"].as_array().into_iter().flatten().filter_map(Value::as_str) {
        let id = format!("{dir}/{bin}");
        if let Some(e) = entries.iter_mut().find(|e| e.id == id) {
            e.kind = Kind::Bin;
            continue;
        }
        let name = bin.rsplit('/').next().unwrap_or(bin);
        entries.push(Entry { id, kind: Kind::Bin, command: format!("vendor/bin/{name}"), scripts: Vec::new() });
    }
    if let Some(scripts) = manifest["scripts"].as_object() {
        for (name, command) in scripts {
            if dev_script(name) {
                continue;
            }
            let commands: Vec<&str> = match command {
                Value::String(s) => vec![s.as_str()],
                Value::Array(list) => list.iter().filter_map(Value::as_str).collect(),
                _ => Vec::new(),
            };
            let line = commands.join(" && ");
            let runs = match commands.as_slice() {
                [only] => runs_php_file(only),
                _ => None,
            };
            if let Some(file) = runs
                && let Some(e) = entries.iter_mut().find(|e| e.id == format!("{dir}/{file}"))
            {
                e.scripts.push((name.clone(), line));
                continue;
            }
            entries.push(Entry {
                id: format!("{dir}/composer.json#{name}"),
                kind: Kind::Script,
                command: format!("composer {name}"),
                scripts: vec![(name.clone(), line)],
            });
        }
    }
    entries
}

/// The PHP file a script runs, when it only runs one (`php import.php all`,
/// `@php bin/tool`).
fn runs_php_file(command: &str) -> Option<String> {
    let mut words = command.split_whitespace();
    let php = words.next()?;
    if php != "php" && php != "@php" {
        return None;
    }
    while let Some(word) = words.next() {
        match word {
            // Options of the PHP binary that take a value (`-d memory_limit=1G`).
            "-d" | "-c" | "-z" => {
                words.next();
            }
            "-f" => return words.next().map(|f| f.trim_start_matches("./").to_owned()),
            w if w.starts_with('-') => {}
            file => return Some(file.trim_start_matches("./").to_owned()),
        }
    }
    None
}

/// PHP programs under `dir`, relative to `base`: not in `src/`, `tests/`,
/// `vendor/` or hidden directories, and not data or configuration.
fn programs(base: &Path, dir: &Path, out: &mut Vec<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    for entry in entries.flatten() {
        let path = entry.path();
        let name = entry.file_name().to_string_lossy().into_owned();
        if name.starts_with('.') {
            continue;
        }
        if path.is_dir() {
            if dir == base && matches!(name.as_str(), "src" | "tests" | "vendor" | "node_modules") {
                continue;
            }
            programs(base, &path, out);
        } else if name.ends_with(".php")
            && let Ok(source) = std::fs::read_to_string(&path)
            && !returns_first(&source)
            && let Ok(rel) = path.strip_prefix(base)
        {
            out.push(rel.to_string_lossy().into_owned());
        }
    }
}

/// Whether a PHP file's first statement, after the opening tag, comments and
/// `declare`, `namespace` and `use` statements, is `return`: data or
/// configuration an `include` reads, not a program.
pub fn returns_first(source: &str) -> bool {
    let mut rest = source.trim_start_matches('\u{feff}');
    if let Some(after) = rest.strip_prefix("#!") {
        rest = after.split_once('\n').map_or("", |(_, r)| r);
    }
    rest = rest.trim_start();
    let Some(after) = rest.strip_prefix("<?php") else { return false };
    rest = after;
    loop {
        rest = rest.trim_start();
        if let Some(after) = rest.strip_prefix("/*") {
            rest = after.split_once("*/").map_or("", |(_, r)| r);
        } else if rest.starts_with("//") || (rest.starts_with('#') && !rest.starts_with("#[")) {
            rest = rest.split_once('\n').map_or("", |(_, r)| r);
        } else if ["declare", "namespace", "use"].iter().any(|k| keyword(rest, k)) {
            rest = rest.split_once(';').map_or("", |(_, r)| r);
        } else {
            return keyword(rest, "return");
        }
    }
}

/// Whether `text` starts with the keyword `word` (not a longer name).
fn keyword(text: &str, word: &str) -> bool {
    text.len() >= word.len()
        && text[..word.len()].eq_ignore_ascii_case(word)
        && text[word.len()..].chars().next().is_none_or(|c| !(c.is_alphanumeric() || c == '_'))
}

/// The tasks of a library with a spec: discovered, ported, waived, pending
/// and what is wrong with the declared ones.
#[derive(Debug, Default)]
pub struct Report {
    pub entries: Vec<Entry>,
    /// Task id -> spec task name, for ported tasks.
    pub ported: BTreeMap<String, String>,
    /// Task id -> reason.
    pub waived: BTreeMap<String, String>,
    pub pending: Vec<String>,
    /// Declared tasks that do not hold (entry not found, binary missing,
    /// no cases).
    pub broken: Vec<String>,
    /// Waivers that match no task.
    pub dangling: Vec<String>,
}

impl Report {
    pub fn total(&self) -> usize {
        self.entries.len()
    }

    pub fn ok(&self) -> bool {
        self.pending.is_empty() && self.broken.is_empty() && self.dangling.is_empty()
    }
}

/// Checks a library's tasks against its spec.
pub fn check(root: &Path, lib: &str, spec: &Spec) -> Report {
    let mut r = Report::default();
    for dir in package_dirs(spec) {
        r.entries.extend(discover(root, &dir));
    }
    let ids: BTreeSet<&str> = r.entries.iter().map(|e| e.id.as_str()).collect();
    for (name, task) in &spec.tasks {
        if !ids.contains(task.php.as_str()) {
            r.broken.push(format!("{name}: the PHP task `{}` does not exist", task.php));
        }
        if let Some(why) = rust_bin_missing(root, spec, &task.rust) {
            r.broken.push(format!("{name}: {why}"));
        }
        if task.covered_by.is_empty() {
            r.broken.push(format!("{name}: no case file covers it (`covered_by`)"));
        }
        for file in &task.covered_by {
            let path = spec::lib_dir(root, lib).join(file);
            match cases::load_file(&path) {
                Ok(f) if !f.cases.is_empty() => {}
                Ok(_) => r.broken.push(format!("{name}: {file} has no cases")),
                Err(e) => r.broken.push(format!("{name}: {e}")),
            }
        }
        r.ported.insert(task.php.clone(), name.clone());
    }
    for entry in &r.entries {
        if r.ported.contains_key(&entry.id) {
            continue;
        }
        if let Some(reason) = spec.tasks_waived.get(&entry.id) {
            r.waived.insert(entry.id.clone(), reason.clone());
        } else {
            r.pending.push(entry.id.clone());
        }
    }
    r.ported.retain(|id, _| ids.contains(id.as_str()));
    for (id, reason) in &spec.tasks_waived {
        if !ids.contains(id.as_str()) {
            r.dangling.push(id.clone());
        } else if reason.trim().is_empty() {
            r.broken.push(format!("{id}: waived without a reason"));
        }
    }
    r
}

/// Why the Rust binary of a task does not exist, if it does not.
fn rust_bin_missing(root: &Path, spec: &Spec, rust: &RustTask) -> Option<String> {
    let Some(dir) = spec.rust["crate"].as_str() else {
        return Some("the spec names no Rust crate (`rust.crate`)".to_owned());
    };
    let manifest = std::fs::read_to_string(root.join(dir).join("Cargo.toml")).unwrap_or_default();
    let package = manifest
        .lines()
        .find_map(|l| l.trim().strip_prefix("name = \"").and_then(|r| r.strip_suffix('"')))
        .unwrap_or_default();
    if package != rust.package {
        return Some(format!("{dir} is the package `{package}`, not `{}`", rust.package));
    }
    let declared = manifest.split("[[bin]]").skip(1).any(|t| t.contains(&format!("name = \"{}\"", rust.bin)));
    if declared || root.join(dir).join("src/bin").join(format!("{}.rs", rust.bin)).is_file() {
        None
    } else {
        Some(format!("{dir} has no binary `{}` (src/bin/{}.rs)", rust.bin, rust.bin))
    }
}

/// The tasks of a library for the docs (`compat crates`): each PHP task with
/// its state, and for ported ones the Rust binary, its documentation and
/// what it writes.
pub fn docs(root: &Path, lib: &str, php_dir: Option<&str>, rust_dir: Option<&str>) -> Vec<Value> {
    let spec = spec::load(root, lib).ok();
    let report = spec.as_ref().map(|s| check(root, lib, s));
    let entries = match (&report, php_dir) {
        (Some(r), _) => r.entries.clone(),
        (None, Some(dir)) => discover(root, dir),
        (None, None) => Vec::new(),
    };
    let mut out = Vec::new();
    for entry in &entries {
        let php = json!({
            "id": entry.id,
            "kind": entry.kind.as_str(),
            "command": entry.command,
            "scripts": entry.scripts.iter().map(|(n, c)| json!({ "name": n, "command": c })).collect::<Vec<_>>(),
        });
        let declared = spec.as_ref().and_then(|s| s.tasks.iter().find(|(_, t)| t.php == entry.id));
        if let Some((name, task)) = declared {
            let source = rust_dir
                .map(|d| root.join(d).join("src/bin").join(format!("{}.rs", task.rust.bin)))
                .and_then(|p| std::fs::read_to_string(p).ok())
                .unwrap_or_default();
            let doc = module_doc(&source);
            let doc = if doc.is_empty() { task.doc.clone() } else { doc };
            out.push(json!({
                "name": name,
                "status": "ported",
                "bin": task.rust.bin,
                "package": task.rust.package,
                "command": task.rust.command(),
                "doc": doc,
                "writes": task.writes,
                "cases": task.covered_by,
                "php": php,
            }));
        } else if let Some(reason) = report.as_ref().and_then(|r| r.waived.get(&entry.id)) {
            out.push(
                json!({ "name": entry.id.rsplit(['/', '#']).next(), "status": "waived", "reason": reason, "php": php }),
            );
        } else {
            out.push(json!({ "name": entry.id.rsplit(['/', '#']).next(), "status": "pending", "php": php }));
        }
    }
    out
}

/// The `//!` doc comment at the top of a Rust source file.
fn module_doc(source: &str) -> String {
    let mut lines = Vec::new();
    for line in source.lines() {
        let t = line.trim_start();
        if let Some(text) = t.strip_prefix("//!") {
            lines.push(text.strip_prefix(' ').unwrap_or(text));
        } else if !t.is_empty() || !lines.is_empty() {
            break;
        }
    }
    lines.join("\n").trim().to_owned()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn data_and_configuration_are_not_programs() {
        assert!(returns_first("<?php\n\nreturn [\n    'a.com',\n];\n"));
        assert!(returns_first("<?php\n/** Lists */\n\nreturn ['com' => []];"));
        assert!(returns_first(
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure();\n"
        ));
        assert!(!returns_first("<?php\n\n$data = file_get_contents('x');\nreturn;\n"));
        assert!(!returns_first("#!/usr/bin/env php\n<?php\nrequire 'vendor/autoload.php';\n"));
        assert!(!returns_first("<?php\n// returns nothing\n$cli->run();\n"));
        assert!(!returns_first("<?php\nreturned();\n"));
    }

    #[test]
    fn scripts_that_run_a_file() {
        assert_eq!(runs_php_file("php import.php all --commit=true").as_deref(), Some("import.php"));
        assert_eq!(runs_php_file("@php -d memory_limit=1G ./bin/tool").as_deref(), Some("bin/tool"));
        assert_eq!(runs_php_file("phpunit --testsuite unit"), None);
    }

    #[test]
    fn discovers_the_packages_tasks() {
        let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
        let ids = |dir: &str| discover(&root, dir).into_iter().map(|e| e.id).collect::<Vec<_>>();
        // The import script, with the five composer scripts that run it; not
        // its data files or rector.php.
        let emails = discover(&root, "packages/emails");
        assert_eq!(emails.len(), 1);
        assert_eq!(emails[0].id, "packages/emails/import.php");
        assert_eq!(emails[0].scripts.len(), 5);
        assert_eq!(ids("packages/domains"), ["packages/domains/data/import.php"]);
        assert_eq!(ids("packages/agents"), ["packages/agents/scripts/sync-openrouter-models.php"]);
        assert_eq!(ids("packages/nats").len(), 4);
        assert!(ids("packages/dsn").is_empty());
    }

    #[test]
    fn development_scripts() {
        assert!(dev_script("test") && dev_script("test:e2e") && dev_script("bench") && dev_script("lint"));
        assert!(!dev_script("import") && !dev_script("import:all") && !dev_script("sync"));
    }
}
