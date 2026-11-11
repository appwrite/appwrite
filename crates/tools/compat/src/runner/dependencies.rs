//! A library's dependencies, for its page in `apps/crates`:
//!
//! - the Rust crate's `[dependencies]`, `[dev-dependencies]` and
//!   `[build-dependencies]` (target-specific tables included), with
//!   `workspace = true` entries resolved through the root manifest's
//!   `[workspace.dependencies]`, the version `Cargo.lock` resolved, and the
//!   comment above the entry (the crate's, else the workspace's) as a note;
//! - the Utopia crates that depend on it ("used by");
//! - the PHP package's `require` and `require-dev`, from its `composer.json`
//!   (or `composer.lock` for a package still installed with Composer), and
//!   the Utopia PHP packages requiring it;
//! - Appwrite's use of it: the `crates/appwrite/*` crates depending on it, and
//!   the PHP server requiring or importing it;
//! - the whole ecosystem as one graph of "depends on" edges (see [`edges`]).
//!
//! Manifests are read with a small TOML reader that covers what Cargo
//! manifests use: tables, `key = value` lines, dotted keys, strings, booleans,
//! arrays and inline tables, values spanning lines, and comments.

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use serde_json::{Map, Value, json};

/// Appwrite's own use of the Utopia libraries: its Rust crates
/// (`crates/appwrite/*`) and its PHP server (the root `composer.json`, and the
/// namespaces its code in `app/` and `src/` imports).
#[derive(Default)]
pub struct Appwrite {
    /// The package names of the `crates/appwrite/*` crates.
    pub crates: Vec<String>,
    /// slug → `[{ crate, dir, kinds }]`: the Appwrite crates depending on it.
    pub rust: BTreeMap<String, Vec<Value>>,
    /// slug → `{ via, kinds, files }`: `via` is `require` when the root
    /// `composer.json` requires the package, `import` when the code imports it
    /// without requiring it (a package of this monorepo, wired by the root
    /// autoload, or one installed through another package); `files` counts
    /// the PHP files importing it.
    pub php: BTreeMap<String, Value>,
    /// (kind, name) → slug → the files of that part of Appwrite importing the
    /// library, merged with the Utopia dependencies of its Rust crate
    /// (`crates/appwrite/<name>`, counted as one file): what each of
    /// Appwrite's services is built from. The kinds are those of
    /// [`component_of`]: `http`, `worker`, `schedule`, `realtime` and `mqtt`.
    pub components: BTreeMap<(String, String), BTreeMap<String, usize>>,
}

/// The node id of Appwrite in the ecosystem graph.
pub const APPWRITE: &str = "appwrite";

/// A `[table]` of a TOML file: its entries in order, each with the comment
/// lines right above it.
struct Table {
    name: String,
    entries: Vec<(String, Value, String)>,
}

/// The crates of the workspace, the lock file and the PHP lock: what every
/// library's dependencies are resolved against.
pub struct Workspace {
    /// `[workspace.dependencies]`: name → (entry, comment).
    deps: BTreeMap<String, (Value, String)>,
    /// `Cargo.lock`: package name → versions.
    versions: BTreeMap<String, Vec<String>>,
    /// `Cargo.lock`: package name → its dependency list (`name` or `name version`).
    locked: BTreeMap<String, Vec<String>>,
    /// `composer.lock`: package name → (version, require, require-dev).
    composer: BTreeMap<String, Value>,
    /// Rust package name → library slug, for the Utopia crates of this repository.
    utopia: BTreeMap<String, String>,
}

impl Workspace {
    /// `crates` maps each library's Rust package name to its slug.
    pub fn load(root: &Path, crates: BTreeMap<String, String>) -> Self {
        let manifest = std::fs::read_to_string(root.join("Cargo.toml")).unwrap_or_default();
        let deps = parse(&manifest)
            .into_iter()
            .filter(|t| t.name == "workspace.dependencies")
            .flat_map(|t| t.entries)
            .map(|(name, value, comment)| (name, (value, comment)))
            .collect();
        let mut versions: BTreeMap<String, Vec<String>> = BTreeMap::new();
        let mut locked = BTreeMap::new();
        let lock = std::fs::read_to_string(root.join("Cargo.lock")).unwrap_or_default();
        for table in parse(&lock).into_iter().filter(|t| t.name == "package") {
            let get = |k: &str| table.entries.iter().find(|(n, ..)| n == k).map(|(_, v, _)| v.clone());
            let Some(name) = get("name").and_then(|v| v.as_str().map(str::to_owned)) else {
                continue;
            };
            let version = get("version").and_then(|v| v.as_str().map(str::to_owned)).unwrap_or_default();
            versions.entry(name.clone()).or_default().push(version);
            // Workspace members have no `source`; a crates.io package of the same name would.
            if get("source").is_none() {
                let list = get("dependencies")
                    .and_then(|v| v.as_array().cloned())
                    .unwrap_or_default()
                    .iter()
                    .filter_map(|d| d.as_str().map(str::to_owned))
                    .collect();
                locked.insert(name, list);
            }
        }
        let mut composer = BTreeMap::new();
        if let Some(lock) = std::fs::read_to_string(root.join("composer.lock"))
            .ok()
            .and_then(|t| serde_json::from_str::<Value>(&t).ok())
        {
            for p in ["packages", "packages-dev"].iter().flat_map(|k| lock[k].as_array().cloned().unwrap_or_default()) {
                if let Some(name) = p["name"].as_str() {
                    composer.insert(name.to_owned(), p.clone());
                }
            }
        }
        Workspace { deps, versions, locked, composer, utopia: crates }
    }

    /// The Rust crate in `dir`: its dependencies by kind.
    pub fn rust(&self, root: &Path, dir: &str) -> Vec<Value> {
        let Ok(manifest) = std::fs::read_to_string(root.join(dir).join("Cargo.toml")) else {
            return Vec::new();
        };
        let tables = parse(&manifest);
        let package = tables
            .iter()
            .find(|t| t.name == "package")
            .and_then(|t| t.entries.iter().find(|(k, ..)| k == "name"))
            .and_then(|(_, v, _)| v.as_str())
            .unwrap_or_default()
            .to_owned();
        let features: Vec<(String, Vec<String>)> = tables
            .iter()
            .filter(|t| t.name == "features")
            .flat_map(|t| t.entries.iter())
            .map(|(k, v, _)| {
                let list = v.as_array().into_iter().flatten().filter_map(|x| x.as_str().map(str::to_owned)).collect();
                (k.clone(), list)
            })
            .collect();
        let lock = self.locked.get(&package);
        let mut out = Vec::new();
        for table in &tables {
            let Some((target, kind, single)) = section(&table.name) else {
                continue;
            };
            // `[dev-dependencies.name]` is one dependency as a table.
            let entries: Vec<(String, Value, String)> = match single {
                Some(name) => {
                    let mut object = Map::new();
                    for (k, v, _) in &table.entries {
                        object.insert(k.clone(), v.clone());
                    }
                    vec![(name.to_owned(), Value::Object(object), String::new())]
                }
                None => table.entries.clone(),
            };
            for (name, value, comment) in entries {
                out.push(self.dependency(dir, &name, &value, &comment, kind, target, &features, lock));
            }
        }
        out
    }

    #[allow(clippy::too_many_arguments)]
    fn dependency(
        &self,
        dir: &str,
        name: &str,
        value: &Value,
        comment: &str,
        kind: &str,
        target: Option<&str>,
        features: &[(String, Vec<String>)],
        lock: Option<&Vec<String>>,
    ) -> Value {
        // `name = "1"` is a version requirement.
        let local = match value {
            Value::String(v) => json!({ "version": v }),
            other => other.clone(),
        };
        let inherited = local["workspace"] == true;
        let (shared, shared_comment) = match self.deps.get(name) {
            Some((Value::String(v), c)) if inherited => (json!({ "version": v }), c.as_str()),
            Some((v, c)) if inherited => (v.clone(), c.as_str()),
            _ => (Value::Null, ""),
        };
        let pick = |key: &str| if local[key].is_null() { shared[key].clone() } else { local[key].clone() };
        let package = pick("package").as_str().unwrap_or(name).to_owned();
        // A path in the workspace manifest is relative to the root; in the crate's, to the crate.
        let path = match (local["path"].as_str(), shared["path"].as_str()) {
            (Some(p), _) => Some(normalize(&format!("{dir}/{p}"))),
            (None, Some(p)) => Some(normalize(p)),
            _ => None,
        };
        // Cargo unions the features of the workspace entry and the crate's.
        let mut enabled: Vec<String> = Vec::new();
        for list in [&shared["features"], &local["features"]] {
            for f in list.as_array().into_iter().flatten().filter_map(Value::as_str) {
                if !enabled.iter().any(|e| e == f) {
                    enabled.push(f.to_owned());
                }
            }
        }
        let default_features = !(local["default-features"] == false
            || local["default_features"] == false
            || shared["default-features"] == false
            || shared["default_features"] == false);
        let optional = local["optional"] == true;
        // The crate features that turn an optional dependency on: those naming it
        // `dep:name` when the manifest uses that syntax (a bare `name` is then a
        // feature of the crate), else those naming it `name` or `name/feature`.
        let explicit = format!("dep:{name}");
        let uses_dep = features.iter().any(|(_, list)| list.contains(&explicit));
        let enabled_by: Vec<&String> = if optional {
            features
                .iter()
                .filter(|(_, list)| {
                    list.iter().any(|f| {
                        if uses_dep { *f == explicit } else { f == name || f.starts_with(&format!("{name}/")) }
                    })
                })
                .map(|(feature, _)| feature)
                .collect()
        } else {
            Vec::new()
        };
        let resolved = lock
            .and_then(|list| {
                list.iter().find_map(|d| {
                    let mut parts = d.split(' ');
                    (parts.next() == Some(package.as_str())).then(|| parts.next().map(str::to_owned))
                })
            })
            .and_then(|version| {
                version.or_else(|| self.versions.get(&package).filter(|v| v.len() == 1).map(|v| v[0].clone()))
            });
        let note = note(if comment.is_empty() { shared_comment } else { comment });
        let utopia = self.utopia.get(&package).cloned();
        json!({
            "name": name,
            "package": package,
            "kind": kind,
            "target": target,
            "requirement": pick("version"),
            "path": path,
            "git": pick("git"),
            "workspace": inherited,
            "optional": optional,
            "default_features": default_features,
            "features": enabled,
            "enabled_by": enabled_by,
            "locked": resolved,
            "utopia": utopia,
            "note": if note.is_empty() { None } else { Some(note) },
        })
    }

    /// The PHP package's `composer.json`; a Composer dependency not absorbed
    /// yet is read from `composer.lock`.
    fn manifest(&self, root: &Path, package: &str, dir: &str) -> Value {
        std::fs::read_to_string(root.join(dir).join("composer.json"))
            .ok()
            .and_then(|t| serde_json::from_str::<Value>(&t).ok())
            .or_else(|| self.composer.get(package).cloned())
            .unwrap_or(Value::Null)
    }

    /// The namespaces each PHP package autoloads: (`Utopia\Validator\`, slug).
    /// `packages` holds each library's (slug, package, directory).
    pub fn namespaces(&self, root: &Path, packages: &[(String, String, String)]) -> Vec<(String, String)> {
        let mut out = Vec::new();
        for (slug, package, dir) in packages {
            let manifest = self.manifest(root, package, dir);
            for prefix in manifest["autoload"]["psr-4"].as_object().into_iter().flat_map(|m| m.keys()) {
                if !prefix.is_empty() {
                    out.push((prefix.clone(), slug.clone()));
                }
            }
        }
        out
    }

    /// Appwrite's dependencies on the libraries. `namespaces` maps PHP
    /// namespace prefixes to slugs (see [`Workspace::namespaces`]).
    pub fn appwrite(&self, root: &Path, namespaces: &[(String, String)]) -> Appwrite {
        let mut out = Appwrite::default();
        // `crates/appwrite/<name>` → the libraries its code depends on.
        let mut rust_of: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
        let mut dirs: Vec<String> = std::fs::read_dir(root.join("crates/appwrite"))
            .into_iter()
            .flatten()
            .flatten()
            .filter(|e| e.path().join("Cargo.toml").is_file())
            .map(|e| e.file_name().to_string_lossy().into_owned())
            .collect();
        dirs.sort();
        for name in dirs {
            let dir = format!("crates/appwrite/{name}");
            let manifest = std::fs::read_to_string(root.join(&dir).join("Cargo.toml")).unwrap_or_default();
            let package = parse(&manifest)
                .into_iter()
                .find(|t| t.name == "package")
                .and_then(|t| t.entries.into_iter().find(|(k, ..)| k == "name"))
                .and_then(|(_, v, _)| v.as_str().map(str::to_owned))
                .unwrap_or_else(|| name.clone());
            out.crates.push(package.clone());
            let mut kinds: BTreeMap<String, Vec<String>> = BTreeMap::new();
            for d in self.rust(root, &dir) {
                let (Some(slug), Some(kind)) = (d["utopia"].as_str(), d["kind"].as_str()) else {
                    continue;
                };
                let list = kinds.entry(slug.to_owned()).or_default();
                if !list.iter().any(|k| k == kind) {
                    list.push(kind.to_owned());
                }
            }
            for (slug, kinds) in kinds {
                if kinds.iter().any(|k| k == "normal") {
                    rust_of.entry(name.clone()).or_default().insert(slug.clone());
                }
                out.rust.entry(slug).or_default().push(json!({ "crate": package, "dir": dir, "kinds": kinds }));
            }
        }

        let manifest = std::fs::read_to_string(root.join("composer.json"))
            .ok()
            .and_then(|t| serde_json::from_str::<Value>(&t).ok())
            .unwrap_or(Value::Null);
        let slugs: BTreeSet<&str> = namespaces.iter().map(|(_, s)| s.as_str()).collect();
        for (key, kind) in [("require", "normal"), ("require-dev", "dev")] {
            for name in manifest[key].as_object().into_iter().flat_map(|m| m.keys()) {
                let Some(slug) = name.strip_prefix("utopia-php/").filter(|s| slugs.contains(s)) else {
                    continue;
                };
                let entry = out
                    .php
                    .entry(slug.to_owned())
                    .or_insert_with(|| json!({ "via": "require", "kinds": [], "files": 0 }));
                if let Some(list) = entry["kinds"].as_array_mut() {
                    list.push(json!(kind));
                }
            }
        }
        // The code's imports, counted per file.
        let mut files: BTreeMap<String, usize> = BTreeMap::new();
        let mut stack = vec![root.join("app"), root.join("src")];
        while let Some(path) = stack.pop() {
            for entry in std::fs::read_dir(&path).into_iter().flatten().flatten() {
                let path = entry.path();
                if path.is_dir() {
                    stack.push(path);
                } else if path.extension().is_some_and(|e| e == "php") {
                    let text = std::fs::read_to_string(&path).unwrap_or_default();
                    let found: BTreeSet<&str> =
                        imports(&text).iter().filter_map(|n| namespace_of(n, namespaces)).collect();
                    let component = path.strip_prefix(root).ok().and_then(component_of);
                    for slug in found {
                        *files.entry(slug.to_owned()).or_default() += 1;
                        if let Some((kind, name)) = &component {
                            let libraries = out.components.entry(((*kind).to_owned(), name.clone())).or_default();
                            *libraries.entry(slug.to_owned()).or_default() += 1;
                        }
                    }
                }
            }
        }
        for (slug, count) in files {
            let entry =
                out.php.entry(slug).or_insert_with(|| json!({ "via": "import", "kinds": ["normal"], "files": 0 }));
            entry["files"] = json!(count);
        }
        // A component's Rust crate shares its name, lowercased (`Users` → `crates/appwrite/users`).
        for ((_, name), slugs) in out.components.iter_mut() {
            for slug in rust_of.get(&name.to_lowercase()).into_iter().flatten() {
                slugs.entry(slug.clone()).or_insert(1);
            }
        }
        out
    }

    /// The PHP package's requirements. `dir` is its directory; a Composer
    /// dependency not absorbed yet is read from `composer.lock`.
    pub fn php(&self, root: &Path, package: &str, dir: &str, libraries: &BTreeMap<String, bool>) -> Vec<Value> {
        let manifest = self.manifest(root, package, dir);
        let mut out = Vec::new();
        for (key, kind) in [("require", "normal"), ("require-dev", "dev")] {
            for (name, requirement) in manifest[key].as_object().into_iter().flatten() {
                let platform =
                    name == "php" || name.starts_with("ext-") || name.starts_with("lib-") || !name.contains('/');
                let utopia = name.strip_prefix("utopia-php/").filter(|s| libraries.contains_key(*s));
                out.push(json!({
                    "name": name,
                    "kind": kind,
                    "requirement": requirement,
                    "platform": platform,
                    "locked": self.composer.get(name).and_then(|p| p["version"].as_str()),
                    "utopia": utopia,
                }));
            }
        }
        out
    }
}

/// The comment above an entry as a note, without the labels that head a group
/// of entries (`# Third party`, `# Utopia crates`).
fn note(comment: &str) -> String {
    comment
        .lines()
        .filter(|l| {
            let label = l.split_whitespace().count() <= 3 && !l.contains(['.', ':', ',', '(', ';', '`']);
            !label
        })
        .collect::<Vec<_>>()
        .join("\n")
}

/// `dependencies` → (target, kind, single dependency): `[build-dependencies]`,
/// `[target.'cfg(unix)'.dependencies]`, `[dev-dependencies.name]`.
fn section(table: &str) -> Option<(Option<&str>, &'static str, Option<&str>)> {
    let (target, rest) = match table.strip_prefix("target.") {
        Some(rest) => {
            // The target is quoted when it holds dots: `'cfg(target_os = "linux")'`.
            let (target, rest) = match rest.chars().next() {
                Some(q @ ('\'' | '"')) => {
                    let end = rest[1..].find(q)? + 1;
                    (&rest[1..end], rest[end + 1..].strip_prefix('.')?)
                }
                _ => rest.split_once('.')?,
            };
            (Some(target), rest)
        }
        None => (None, table),
    };
    let (head, single) = match rest.split_once('.') {
        Some((h, name)) => (h, Some(name.trim_matches(['"', '\'']))),
        None => (rest, None),
    };
    let kind = match head {
        "dependencies" => "normal",
        "dev-dependencies" | "dev_dependencies" => "dev",
        "build-dependencies" | "build_dependencies" => "build",
        _ => return None,
    };
    Some((target, kind, single))
}

/// `crates/utopia/http/../compression` → `crates/utopia/compression`.
fn normalize(path: &str) -> String {
    let mut out: Vec<&str> = Vec::new();
    for part in path.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                out.pop();
            }
            p => out.push(p),
        }
    }
    out.join("/")
}

/// The tables of a TOML document. `[[array]]` tables each become a table of
/// that name.
fn parse(text: &str) -> Vec<Table> {
    let mut tables = vec![Table { name: String::new(), entries: Vec::new() }];
    let mut comment: Vec<String> = Vec::new();
    let mut pending = String::new();
    for line in text.lines() {
        if !pending.is_empty() {
            pending.push('\n');
            pending.push_str(line);
        } else {
            let t = line.trim();
            if t.is_empty() {
                comment.clear();
                continue;
            }
            if let Some(c) = t.strip_prefix('#') {
                comment.push(c.strip_prefix(' ').unwrap_or(c).to_owned());
                continue;
            }
            if t.starts_with('[') {
                let name = strip_comment(t).trim().trim_start_matches('[').trim_end_matches(']').trim().to_owned();
                tables.push(Table { name, entries: Vec::new() });
                comment.clear();
                continue;
            }
            pending.push_str(line);
        }
        if !balanced(&pending) {
            continue;
        }
        let entry = std::mem::take(&mut pending);
        if let Some((key, value)) = split_key(&entry) {
            let mut key_parts: Vec<String> = key_path(key);
            let mut value = Reader::new(value).value();
            // `name.workspace = true` → `name = { workspace = true }`.
            while key_parts.len() > 1 {
                let last = key_parts.pop().unwrap_or_default();
                let mut object = Map::new();
                object.insert(last, value);
                value = Value::Object(object);
            }
            let key = key_parts.pop().unwrap_or_default();
            let table = tables.last_mut().expect("the root table");
            // A dotted key repeated (`a.x = 1`, `a.y = 2`) merges into one entry.
            match table.entries.iter_mut().find(|(k, ..)| *k == key) {
                Some((_, Value::Object(existing), _)) if value.is_object() => {
                    if let Value::Object(more) = value {
                        existing.extend(more);
                    }
                }
                _ => table.entries.push((key, value, comment.join("\n"))),
            }
        }
        comment.clear();
    }
    tables
}

/// Whether brackets and braces outside strings are closed.
fn balanced(text: &str) -> bool {
    let mut depth = 0i32;
    let mut quote: Option<char> = None;
    let mut chars = text.chars();
    while let Some(c) = chars.next() {
        match (quote, c) {
            (Some('"'), '\\') => {
                chars.next();
            }
            (Some(q), c) if c == q => quote = None,
            (Some(_), _) => {}
            (None, '"' | '\'') => quote = Some(c),
            (None, '#') => {
                // A comment runs to the end of its line.
                for c in chars.by_ref() {
                    if c == '\n' {
                        break;
                    }
                }
            }
            (None, '[' | '{') => depth += 1,
            (None, ']' | '}') => depth -= 1,
            _ => {}
        }
    }
    depth <= 0
}

fn strip_comment(line: &str) -> &str {
    let mut quote: Option<char> = None;
    for (i, c) in line.char_indices() {
        match (quote, c) {
            (Some(q), c) if c == q => quote = None,
            (None, '"' | '\'') => quote = Some(c),
            (None, '#') => return &line[..i],
            _ => {}
        }
    }
    line
}

/// `key = value` → (`key`, `value`), the `=` outside a quoted key.
fn split_key(entry: &str) -> Option<(&str, &str)> {
    let mut quote: Option<char> = None;
    for (i, c) in entry.char_indices() {
        match (quote, c) {
            (Some(q), c) if c == q => quote = None,
            (None, '"' | '\'') => quote = Some(c),
            (None, '=') => return Some((entry[..i].trim(), &entry[i + 1..])),
            _ => {}
        }
    }
    None
}

/// `a."b.c".d` → `[a, b.c, d]`.
fn key_path(key: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut current = String::new();
    let mut quote: Option<char> = None;
    for c in key.chars() {
        match (quote, c) {
            (Some(q), c) if c == q => quote = None,
            (Some(_), c) => current.push(c),
            (None, '"' | '\'') => quote = Some(c),
            (None, '.') => out.push(std::mem::take(&mut current).trim().to_owned()),
            (None, c) => current.push(c),
        }
    }
    out.push(current.trim().to_owned());
    out
}

/// Reads one TOML value.
struct Reader<'a> {
    chars: std::iter::Peekable<std::str::Chars<'a>>,
}

impl<'a> Reader<'a> {
    fn new(text: &'a str) -> Self {
        Reader { chars: text.chars().peekable() }
    }

    /// Skips blanks, new lines and comments.
    fn skip(&mut self) {
        while let Some(&c) = self.chars.peek() {
            if c.is_whitespace() {
                self.chars.next();
            } else if c == '#' {
                while self.chars.peek().is_some_and(|c| *c != '\n') {
                    self.chars.next();
                }
            } else {
                break;
            }
        }
    }

    fn value(&mut self) -> Value {
        self.skip();
        match self.chars.peek().copied() {
            Some('"') => {
                self.chars.next();
                let mut s = String::new();
                while let Some(c) = self.chars.next() {
                    match c {
                        '"' => break,
                        '\\' => match self.chars.next() {
                            Some('n') => s.push('\n'),
                            Some('t') => s.push('\t'),
                            Some(c) => s.push(c),
                            None => break,
                        },
                        c => s.push(c),
                    }
                }
                Value::String(s)
            }
            Some('\'') => {
                self.chars.next();
                Value::String(self.chars.by_ref().take_while(|c| *c != '\'').collect())
            }
            Some('[') => {
                self.chars.next();
                let mut list = Vec::new();
                loop {
                    self.skip();
                    match self.chars.peek() {
                        Some(']') => {
                            self.chars.next();
                            break;
                        }
                        Some(',') => {
                            self.chars.next();
                        }
                        None => break,
                        _ => list.push(self.value()),
                    }
                }
                Value::Array(list)
            }
            Some('{') => {
                self.chars.next();
                let mut object = Map::new();
                loop {
                    self.skip();
                    match self.chars.peek() {
                        Some('}') => {
                            self.chars.next();
                            break;
                        }
                        Some(',') => {
                            self.chars.next();
                        }
                        None => break,
                        _ => {
                            let mut key = String::new();
                            while let Some(&c) = self.chars.peek() {
                                if c == '=' {
                                    self.chars.next();
                                    break;
                                }
                                key.push(c);
                                self.chars.next();
                            }
                            let mut path = key_path(key.trim());
                            let mut value = self.value();
                            while path.len() > 1 {
                                let mut inner = Map::new();
                                inner.insert(path.pop().unwrap_or_default(), value);
                                value = Value::Object(inner);
                            }
                            object.insert(path.pop().unwrap_or_default(), value);
                        }
                    }
                }
                Value::Object(object)
            }
            _ => {
                let mut word = String::new();
                while let Some(&c) = self.chars.peek() {
                    if c == ',' || c == '}' || c == ']' || c == '#' || c == '\n' {
                        break;
                    }
                    word.push(c);
                    self.chars.next();
                }
                match word.trim() {
                    "true" => Value::Bool(true),
                    "false" => Value::Bool(false),
                    w => w.parse::<i64>().map(Value::from).unwrap_or_else(|_| Value::String(w.to_owned())),
                }
            }
        }
    }
}

/// The names a PHP file imports with `use`: `use A\B;`, `use A\B as C;`,
/// `use function A\f;` and `use A\{B, C as D};`, without a leading `\`.
fn imports(text: &str) -> Vec<String> {
    let bare = |name: &str| name.split(" as ").next().unwrap_or_default().trim().trim_start_matches('\\').to_owned();
    let mut out = Vec::new();
    for line in text.lines() {
        let Some(rest) = line.trim().strip_prefix("use ").and_then(|r| r.trim().strip_suffix(';')) else {
            continue;
        };
        let rest = rest.strip_prefix("function ").or_else(|| rest.strip_prefix("const ")).unwrap_or(rest).trim();
        match rest.split_once('{') {
            Some((prefix, group)) => {
                let prefix = prefix.trim().trim_start_matches('\\');
                for name in group.trim_end_matches('}').split(',').map(bare).filter(|n| !n.is_empty()) {
                    out.push(format!("{prefix}{name}"));
                }
            }
            None => out.extend(rest.split(',').map(bare).filter(|n| !n.is_empty())),
        }
    }
    out
}

/// The part of Appwrite a file belongs to, as (kind, name), from its path
/// relative to the repository:
///
/// - `http`: an API module, `src/Appwrite/Platform/Modules/<Module>/...`
/// - `worker`: a queue worker, `src/Appwrite/Platform/Workers/<Worker>.php`
///   or a module's `Workers/<Worker>.php` (not its exceptions)
/// - `schedule`: the schedulers, `src/Appwrite/Platform/Tasks/Schedule*.php`
/// - `realtime`: the realtime server, `app/realtime.php` and `src/Appwrite/Realtime`
/// - `mqtt`: the MQTT broker, `app/mqtt.php` and `src/Appwrite/Mqtt`
///
/// Anything else (`Modules/Core.php`, shared libraries, other tasks) belongs to none.
fn component_of(path: &Path) -> Option<(&'static str, String)> {
    let stem = path.file_stem()?.to_str()?;
    if let Ok(rest) = path.strip_prefix("src/Appwrite/Platform/Modules") {
        let parts: Vec<&str> = rest.components().filter_map(|c| c.as_os_str().to_str()).collect();
        return match parts.as_slice() {
            [_, "Workers", file] if !stem.ends_with("Exception") && file.ends_with(".php") => {
                Some(("worker", stem.to_owned()))
            }
            [_, "Workers", ..] => None,
            [module, _, ..] => Some(("http", (*module).to_owned())),
            _ => None,
        };
    }
    if path.parent() == Some(Path::new("src/Appwrite/Platform/Workers")) {
        return Some(("worker", stem.to_owned()));
    }
    if path.parent() == Some(Path::new("src/Appwrite/Platform/Tasks")) && stem.starts_with("Schedule") {
        return Some(("schedule", "Schedulers".to_owned()));
    }
    if path == Path::new("app/realtime.php") || path.starts_with("src/Appwrite/Realtime") {
        return Some(("realtime", "Realtime".to_owned()));
    }
    if path == Path::new("app/mqtt.php") || path.starts_with("src/Appwrite/Mqtt") {
        return Some(("mqtt", "MQTT".to_owned()));
    }
    None
}

/// The library whose namespace holds `name`: the longest matching prefix.
fn namespace_of<'a>(name: &str, namespaces: &'a [(String, String)]) -> Option<&'a str> {
    namespaces
        .iter()
        .filter(|(prefix, _)| name.starts_with(prefix.as_str()) || prefix.strip_suffix('\\').is_some_and(|p| p == name))
        .max_by_key(|(prefix, _)| prefix.len())
        .map(|(_, slug)| slug.as_str())
}

/// The ecosystem's "depends on" edges, `{ source, target, runtime, kinds }`
/// (plus `crates` and `via` for Appwrite's): the libraries' Rust
/// dependencies and PHP requirements on other libraries, and Appwrite's
/// (node [`APPWRITE`]). `rust` and `php` map each slug to its entries (with
/// `utopia` and `kind`).
pub fn edges(
    rust: &BTreeMap<String, Vec<Value>>,
    php: &BTreeMap<String, Vec<Value>>,
    appwrite: &Appwrite,
) -> Vec<Value> {
    let mut out = Vec::new();
    for (runtime, deps) in [("rust", rust), ("php", php)] {
        for (target, users) in used_by(deps) {
            for u in users {
                out.push(json!({ "source": u["slug"], "target": target, "runtime": runtime, "kinds": u["kinds"] }));
            }
        }
    }
    for (target, crates) in &appwrite.rust {
        let mut kinds: Vec<&Value> = Vec::new();
        for k in crates.iter().flat_map(|c| c["kinds"].as_array().into_iter().flatten()) {
            if !kinds.contains(&k) {
                kinds.push(k);
            }
        }
        let names: Vec<&Value> = crates.iter().map(|c| &c["crate"]).collect();
        out.push(json!({ "source": APPWRITE, "target": target, "runtime": "rust", "kinds": kinds, "crates": names }));
    }
    for (target, d) in &appwrite.php {
        out.push(
            json!({ "source": APPWRITE, "target": target, "runtime": "php", "kinds": d["kinds"], "via": d["via"] }),
        );
    }
    out.sort_by_key(|e| (e["source"].to_string(), e["target"].to_string(), e["runtime"].to_string()));
    out
}

/// The libraries that depend on each library: slug → `[{ slug, kinds }]`.
/// `deps` maps each library to its Rust dependencies or PHP requirements
/// (entries with `utopia` and `kind`).
pub fn used_by(deps: &BTreeMap<String, Vec<Value>>) -> BTreeMap<String, Vec<Value>> {
    let mut out: BTreeMap<String, BTreeMap<String, Vec<String>>> = BTreeMap::new();
    for (slug, deps) in deps {
        for d in deps {
            let Some(target) = d["utopia"].as_str() else {
                continue;
            };
            if target == slug {
                continue;
            }
            let kinds = out.entry(target.to_owned()).or_default().entry(slug.clone()).or_default();
            let kind = d["kind"].as_str().unwrap_or("normal").to_owned();
            if !kinds.contains(&kind) {
                kinds.push(kind);
            }
        }
    }
    out.into_iter()
        .map(|(target, users)| {
            (target, users.into_iter().map(|(slug, kinds)| json!({ "slug": slug, "kinds": kinds })).collect())
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_manifest_entries() {
        let tables = parse(
            "[package]\nname = \"a\" # trailing\n\n[dependencies]\n# Why b.\nb.workspace = true\nc = { workspace = true, features = [\"x\",\n  \"y\"], optional = true }\nd = \"1.2\"\n\n[dev-dependencies.e]\nworkspace = true\n",
        );
        let deps = tables.iter().find(|t| t.name == "dependencies").expect("dependencies");
        assert_eq!(deps.entries[0].0, "b");
        assert_eq!(deps.entries[0].1, json!({ "workspace": true }));
        assert_eq!(deps.entries[0].2, "Why b.");
        assert_eq!(deps.entries[1].1, json!({ "workspace": true, "features": ["x", "y"], "optional": true }));
        assert_eq!(deps.entries[2].1, json!("1.2"));
        let package = tables.iter().find(|t| t.name == "package").expect("package");
        assert_eq!(package.entries[0].1, json!("a"));
        assert!(tables.iter().any(|t| t.name == "dev-dependencies.e"));
    }

    #[test]
    fn sections() {
        assert_eq!(section("dependencies"), Some((None, "normal", None)));
        assert_eq!(section("dev-dependencies.utopia-telemetry"), Some((None, "dev", Some("utopia-telemetry"))));
        assert_eq!(section("target.'cfg(unix)'.build-dependencies"), Some((Some("cfg(unix)"), "build", None)));
        assert_eq!(section("features"), None);
    }

    #[test]
    fn notes_without_group_labels() {
        assert_eq!(
            note("Third party\nMulti-needle substring search; pure Rust."),
            "Multi-needle substring search; pure Rust."
        );
        assert_eq!(note("Utopia crates"), "");
    }

    #[test]
    fn php_imports() {
        let text = "<?php\nnamespace Appwrite;\n\nuse Utopia\\Validator\\Text;\nuse \\Utopia\\Http\\Http as App;\nuse function Utopia\\Console\\log;\nuse Utopia\\Database\\{Document, Query as Q};\n    use Traits\\Thing;\n$f = function () use ($x) {};\n";
        assert_eq!(
            imports(text),
            [
                "Utopia\\Validator\\Text",
                "Utopia\\Http\\Http",
                "Utopia\\Console\\log",
                "Utopia\\Database\\Document",
                "Utopia\\Database\\Query",
                "Traits\\Thing"
            ]
        );
    }

    #[test]
    fn namespaces_by_longest_prefix() {
        let namespaces = vec![
            ("Utopia\\Validator\\".to_owned(), "validators".to_owned()),
            ("Utopia\\Database\\".to_owned(), "database".to_owned()),
            ("Utopia\\Database\\Adapter\\Mongo\\".to_owned(), "mongo".to_owned()),
        ];
        assert_eq!(namespace_of("Utopia\\Validator\\Text", &namespaces), Some("validators"));
        assert_eq!(namespace_of("Utopia\\Database\\Validator\\Key", &namespaces), Some("database"));
        assert_eq!(namespace_of("Utopia\\Database\\Adapter\\Mongo\\Client", &namespaces), Some("mongo"));
        assert_eq!(namespace_of("Utopia\\Validator", &namespaces), Some("validators"));
        assert_eq!(namespace_of("Utopia\\ValidatorX\\A", &namespaces), None);
    }

    #[test]
    fn ecosystem_edges() {
        let dep = |utopia: &str, kind: &str| json!({ "utopia": utopia, "kind": kind });
        let rust = BTreeMap::from([
            ("http".to_owned(), vec![dep("validators", "normal"), dep("validators", "dev"), dep("http", "normal")]),
            ("config".to_owned(), vec![dep("validators", "dev")]),
        ]);
        let php = BTreeMap::from([(
            "http".to_owned(),
            vec![json!({ "utopia": null, "kind": "normal" }), dep("validators", "normal")],
        )]);
        let appwrite = Appwrite {
            crates: vec!["appwrite-core".to_owned(), "appwrite-users".to_owned()],
            rust: BTreeMap::from([(
                "http".to_owned(),
                vec![
                    json!({ "crate": "appwrite-core", "kinds": ["normal"] }),
                    json!({ "crate": "appwrite-users", "kinds": ["normal", "dev"] }),
                ],
            )]),
            php: BTreeMap::from([(
                "validators".to_owned(),
                json!({ "via": "import", "kinds": ["normal"], "files": 3 }),
            )]),
            components: BTreeMap::new(),
        };
        let edges = edges(&rust, &php, &appwrite);
        let brief: Vec<(String, String, String, String)> = edges
            .iter()
            .map(|e| {
                (
                    e["source"].as_str().unwrap_or_default().to_owned(),
                    e["target"].as_str().unwrap_or_default().to_owned(),
                    e["runtime"].as_str().unwrap_or_default().to_owned(),
                    e["kinds"].to_string(),
                )
            })
            .collect();
        let s = |a: &str, b: &str, c: &str, d: &str| (a.to_owned(), b.to_owned(), c.to_owned(), d.to_owned());
        assert_eq!(
            brief,
            [
                s("appwrite", "http", "rust", "[\"normal\",\"dev\"]"),
                s("appwrite", "validators", "php", "[\"normal\"]"),
                s("config", "validators", "rust", "[\"dev\"]"),
                s("http", "validators", "php", "[\"normal\"]"),
                s("http", "validators", "rust", "[\"normal\",\"dev\"]"),
            ]
        );
        assert_eq!(edges[0]["crates"], json!(["appwrite-core", "appwrite-users"]));
        assert_eq!(edges[1]["via"], json!("import"));
    }

    #[test]
    fn components() {
        let of = |p: &str| component_of(Path::new(p)).map(|(kind, name)| format!("{kind}/{name}"));
        assert_eq!(
            of("src/Appwrite/Platform/Modules/Databases/Http/Databases/Create.php").as_deref(),
            Some("http/Databases")
        );
        assert_eq!(of("src/Appwrite/Platform/Modules/VCS/Module.php").as_deref(), Some("http/VCS"));
        assert_eq!(of("src/Appwrite/Platform/Modules/Functions/Workers/Builds.php").as_deref(), Some("worker/Builds"));
        assert_eq!(of("src/Appwrite/Platform/Modules/Functions/Workers/BuildException.php"), None);
        assert_eq!(of("src/Appwrite/Platform/Workers/Mails.php").as_deref(), Some("worker/Mails"));
        assert_eq!(of("src/Appwrite/Platform/Tasks/ScheduleFunctions.php").as_deref(), Some("schedule/Schedulers"));
        assert_eq!(of("src/Appwrite/Platform/Tasks/Maintenance.php"), None);
        assert_eq!(of("app/realtime.php").as_deref(), Some("realtime/Realtime"));
        assert_eq!(of("src/Appwrite/Realtime/Message/Envelope.php").as_deref(), Some("realtime/Realtime"));
        assert_eq!(of("app/mqtt.php").as_deref(), Some("mqtt/MQTT"));
        assert_eq!(of("src/Appwrite/Platform/Modules/Core.php"), None);
        assert_eq!(of("src/Appwrite/Event/Event.php"), None);
        assert_eq!(of("app/init.php"), None);
    }

    #[test]
    fn paths() {
        assert_eq!(normalize("crates/utopia/http/../compression"), "crates/utopia/compression");
    }
}
