//! A library's dependencies, for its page in `apps/crates`:
//!
//! - the Rust crate's `[dependencies]`, `[dev-dependencies]` and
//!   `[build-dependencies]` (target-specific tables included), with
//!   `workspace = true` entries resolved through the root manifest's
//!   `[workspace.dependencies]`, the version `Cargo.lock` resolved, and the
//!   comment above the entry (the crate's, else the workspace's) as a note;
//! - the Utopia crates that depend on it ("used by");
//! - the PHP package's `require` and `require-dev`, from its `composer.json`
//!   (or `composer.lock` for a package still installed with Composer).
//!
//! Manifests are read with a small TOML reader that covers what Cargo
//! manifests use: tables, `key = value` lines, dotted keys, strings, booleans,
//! arrays and inline tables, values spanning lines, and comments.

use std::collections::BTreeMap;
use std::path::Path;

use serde_json::{Map, Value, json};

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

    /// The PHP package's requirements. `dir` is its directory; a Composer
    /// dependency not absorbed yet is read from `composer.lock`.
    pub fn php(&self, root: &Path, package: &str, dir: &str, libraries: &BTreeMap<String, bool>) -> Vec<Value> {
        let manifest = std::fs::read_to_string(root.join(dir).join("composer.json"))
            .ok()
            .and_then(|t| serde_json::from_str::<Value>(&t).ok())
            .or_else(|| self.composer.get(package).cloned())
            .unwrap_or(Value::Null);
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

/// The libraries that depend on each library's crate: slug → `[{ slug, kinds }]`.
pub fn used_by(rust: &BTreeMap<String, Vec<Value>>) -> BTreeMap<String, Vec<Value>> {
    let mut out: BTreeMap<String, BTreeMap<String, Vec<String>>> = BTreeMap::new();
    for (slug, deps) in rust {
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
    fn paths() {
        assert_eq!(normalize("crates/utopia/http/../compression"), "crates/utopia/compression");
    }
}
