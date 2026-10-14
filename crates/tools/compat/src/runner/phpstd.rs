//! The inventory of `php-std`: every PHP function, method and engine
//! behaviour the crate reimplements, where it lives in Rust, how the compat
//! suite proves it and which Utopia crates use it.
//!
//! Everything comes from sources that run:
//!
//! - the PHP names: what each operation's PHP adapter (`tests/compat/php-std/ops/*.php`)
//!   calls, as its `doc` in the spec writes the call, or the op's `covers`;
//!   casts and operators the doc names (`(string) $v`, `$a == $b`) are
//!   engine behaviours;
//! - the Rust paths: the `php_std` functions the operation's Rust adapter
//!   (`crates/tools/compat/src/libs/php_std/*.rs`) calls, narrowed to the one
//!   named after the PHP function or whose rustdoc names it;
//! - the proof: cases per operation (`cases/*.json`), fuzz profiles and
//!   iterations (`spec.d`), quirks (by their pinning case's operation) and
//!   deviations (by the functions their symbol names);
//! - the users: the Utopia and Appwrite crates whose code reaches the Rust
//!   function, directly or through other php-std functions ([`users`]).

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use serde_json::{Value, json};

use super::cases;
use super::docs::RustItem;
use super::spec::{self, FuzzSpec};

mod users;

const LIB: &str = "php-std";
const CRATE: &str = "php_std";

/// The page's groups, by operation area (`string.*`), in display order.
const GROUPS: &[(&str, &str, &[&str])] = &[
    ("strings", "Strings", &["string"]),
    ("multibyte", "Multibyte strings", &["mb"]),
    ("encoding", "Encoding", &["encoding"]),
    ("json", "JSON", &["json"]),
    ("numbers", "Numbers and formatting", &["format"]),
    ("juggling", "Types, juggling and comparison", &["value", "types"]),
    ("arrays", "Arrays and sorting", &["sort"]),
    ("dates", "Dates and times", &["datetime"]),
    ("regex", "Regular expressions", &["pcre"]),
    ("urls", "URLs", &["url"]),
    ("filters", "Filters", &["filter"]),
    ("network", "Network", &["net"]),
    ("serialization", "Serialization", &["serialize", "igbinary"]),
    ("yaml", "YAML", &["yaml"]),
    ("paths", "Paths", &["path"]),
    ("random", "Random numbers", &["random"]),
];

/// Methods an adapter calls to handle values, not to run the function under test.
const PLUMBING: &[&str] = &[
    "len",
    "is_empty",
    "clone",
    "iter",
    "get",
    "as_bytes",
    "as_str",
    "to_vec",
    "into_iter",
    "push",
    "insert",
    "contains",
    "keys",
    "values",
    "map",
    "unwrap_or",
    "ok",
    "err",
];

/// Helpers the adapters call to observe a result, not the function under test.
const OBSERVERS: &[&str] = &["strlen", "is_float", "is_int", "count", "error_get_last", "error_clear_last"];

/// Casts and operators a doc can name, as engine behaviours.
const BEHAVIOURS: &[(&str, &str)] = &[
    ("(string)", "(string) cast"),
    ("(bool)", "(bool) cast"),
    ("(float)", "(float) cast"),
    ("(int)", "(int) cast"),
    (" === ", "=== strict comparison"),
    (" == ", "== loose comparison"),
    (" <=> ", "<=> comparison"),
    (" <= ", "< <= > >= ordering"),
    (" < ", "< <= > >= ordering"),
    (" > ", "< <= > >= ordering"),
    (" ** ", "** exponentiation"),
    ("<op>", "+ - * / arithmetic"),
    ("$a[", "Array keys and writes"),
];

/// A PHP name an operation exercises.
#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord)]
pub struct Name {
    pub name: String,
    /// `function`, `method` or `behaviour`.
    pub kind: &'static str,
}

impl Name {
    fn new(name: impl Into<String>, kind: &'static str) -> Self {
        Name { name: name.into(), kind }
    }
}

fn ident_char(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '_'
}

/// Calls written in `text`: `f(` (functions), `X::m(`, and `->m(` on what
/// a `new X(` or `X::m(` earlier in the same chain made (methods;
/// `X::__construct` for `new X(`). A chain keeps its class through fluent
/// calls; a `new` inside an argument list does not leak out of it.
fn written_calls(text: &str) -> Vec<Name> {
    let mut out = Vec::new();
    // The class of the chain at each parenthesis depth.
    let mut class: Vec<Option<String>> = vec![None];
    let mut i = 0;
    while i < text.len() {
        let rest = &text[i..];
        let Some(c) = rest.chars().next() else { break };
        let depth = class.len() - 1;
        match c {
            '(' => {
                class.push(None);
                i += 1;
                continue;
            }
            ')' => {
                // `(new X(...))->m()`: the parenthesised expression's class carries out.
                if let Some(inner) = class.pop().flatten()
                    && let Some(outer) = class.last_mut()
                    && outer.is_none()
                {
                    *outer = Some(inner);
                }
                if class.is_empty() {
                    class.push(None);
                }
                i += 1;
                continue;
            }
            _ => {}
        }
        let starts = (c.is_ascii_alphabetic() || c == '_' || c == '\\')
            && !text[..i].chars().next_back().is_some_and(ident_char);
        if !starts {
            i += c.len_utf8();
            continue;
        }
        // A (possibly namespaced) identifier.
        let len = rest.find(|c: char| !(ident_char(c) || c == '\\')).unwrap_or(rest.len());
        let word = rest[..len].trim_start_matches('\\');
        let before = text[..i].trim_end();
        let after = &rest[len..];
        i += len;
        if word.is_empty() || before.ends_with('$') {
            continue;
        }
        if before.ends_with("new") && after.starts_with('(') {
            class[depth] = Some(word.to_owned());
            out.push(Name::new(format!("{word}::__construct"), "method"));
        } else if let Some(tail) = after.strip_prefix("::") {
            let method: String = tail.chars().take_while(|c| ident_char(*c)).collect();
            if !method.is_empty() && tail[method.len()..].starts_with('(') {
                out.push(Name::new(format!("{word}::{method}"), "method"));
                class[depth] = Some(word.to_owned());
                i += 2 + method.len();
            }
        } else if after.starts_with('(') {
            if before.ends_with("->") {
                if let Some(c) = &class[depth] {
                    out.push(Name::new(format!("{c}::{word}"), "method"));
                }
            } else if word.starts_with(|c: char| c.is_ascii_lowercase() || c == '_')
                && word != "fn"
                && word != "function"
            {
                out.push(Name::new(word, "function"));
            }
        }
    }
    out
}

/// Whether `body` has `needle` not preceded by an identifier character or,
/// with `free`, by `->` or `::` (a function, not a method, call).
fn has(body: &str, needle: &str, free: bool) -> bool {
    body.match_indices(needle).any(|(i, _)| {
        let before = &body[..i];
        !before.chars().next_back().is_some_and(ident_char)
            && !(free && (before.ends_with("->") || before.ends_with("::")))
    })
}

/// Whether the PHP adapter's code makes the call `n`.
fn calls_it(body: &str, n: &Name) -> bool {
    match n.name.split_once("::") {
        Some((class, "__construct")) => {
            body.contains(&format!("new {class}(")) || body.contains(&format!("new \\{class}("))
        }
        Some((class, method)) => {
            body.contains(&format!("{class}::{method}(")) || body.contains(&format!("->{method}("))
        }
        None => has(body, &format!("{}(", n.name), true) || has(body, &format!("\\{}(", n.name), true),
    }
}

/// The class `body` instantiates first (`new X(`).
fn constructed(body: &str) -> Option<String> {
    let (_, rest) = body.split_once("new ")?;
    let name: String = rest.trim_start_matches('\\').chars().take_while(|c| ident_char(*c) || *c == '\\').collect();
    (!name.is_empty() && rest.trim_start_matches('\\')[name.len()..].starts_with('(')).then_some(name)
}

/// The PHP names an operation exercises: its `covers`, else the calls its
/// doc writes that its PHP adapter makes (a name the doc writes bare but
/// the adapter calls as `->m(` is a method of the class it constructs), and
/// the casts and operators the doc names.
pub fn php_names(doc: &str, covers: &[String], body: &str) -> Vec<Name> {
    let mut out: Vec<Name> = covers
        .iter()
        .filter(|c| !c.contains('*'))
        .map(|c| {
            let c = c.trim_end_matches("()");
            Name::new(c, if c.contains("::") { "method" } else { "function" })
        })
        .collect();
    if out.is_empty() {
        let mut found: Vec<Name> = Vec::new();
        for mut n in written_calls(doc) {
            if n.kind == "function"
                && !calls_it(body, &n)
                && body.contains(&format!("->{}(", n.name))
                && let Some(class) = constructed(body)
            {
                n = Name::new(format!("{class}::{}", n.name), "method");
            }
            if calls_it(body, &n) && !found.contains(&n) {
                found.push(n);
            }
        }
        if found.iter().any(|n| !OBSERVERS.contains(&n.name.as_str())) {
            found.retain(|n| !OBSERVERS.contains(&n.name.as_str()));
        }
        out = found;
    }
    // The doc's expression: up to the first ": " explanation.
    let expression = doc.split(": ").next().unwrap_or(doc);
    for (marker, behaviour) in BEHAVIOURS {
        let n = Name::new(*behaviour, "behaviour");
        if expression.contains(marker) && !out.contains(&n) {
            out.push(n);
        }
    }
    out
}

/// The PHP adapter's code per operation: `'<op>' => ...` up to the next one,
/// with the file's helper closures (`$name = static function ...` before
/// `return [`) it uses.
pub fn php_bodies(text: &str) -> BTreeMap<String, String> {
    let mut helpers: BTreeMap<String, String> = BTreeMap::new();
    let mut helper: Option<String> = None;
    let mut out: BTreeMap<String, String> = BTreeMap::new();
    let mut current: Option<String> = None;
    let mut listed = false;
    for line in text.lines() {
        let t = line.trim_start();
        let indent = line.len() - t.len();
        if !listed {
            if line.starts_with("return [") {
                listed = true;
                helper = None;
            } else if let Some(rest) = line.strip_prefix('$')
                && let Some((name, _)) = rest.split_once(" = ")
            {
                helper = Some(name.to_owned());
            }
            if let Some(h) = &helper {
                let body = helpers.entry(h.clone()).or_default();
                body.push_str(line);
                body.push('\n');
            }
            continue;
        }
        if indent == 4
            && let Some(rest) = t.strip_prefix('\'')
            && let Some((name, after)) = rest.split_once('\'')
            && after.trim_start().starts_with("=>")
        {
            current = Some(name.to_owned());
        } else if indent == 0 && t.starts_with("];") {
            current = None;
        }
        if let Some(op) = &current {
            let body = out.entry(op.clone()).or_default();
            body.push_str(line);
            body.push('\n');
        }
    }
    for body in out.values_mut() {
        let used: Vec<&String> =
            helpers.iter().filter(|(name, _)| has(body, &format!("${name}"), false)).map(|(_, h)| h).collect();
        let extra: String = used.into_iter().cloned().collect();
        body.push_str(&extra);
    }
    out
}

/// What a `use php_std::...` brings into scope: alias → crate path.
fn aliases(file: &syn::File) -> BTreeMap<String, String> {
    fn walk(tree: &syn::UseTree, prefix: &[String], out: &mut BTreeMap<String, String>) {
        match tree {
            syn::UseTree::Path(p) => {
                let mut next = prefix.to_vec();
                next.push(p.ident.to_string());
                walk(&p.tree, &next, out);
            }
            syn::UseTree::Name(n) => {
                let name = n.ident.to_string();
                if name == "self" {
                    if let Some(last) = prefix.last() {
                        out.insert(last.clone(), prefix[1..].join("::"));
                    }
                } else {
                    let mut path = prefix[1..].to_vec();
                    path.push(name.clone());
                    out.insert(name, path.join("::"));
                }
            }
            syn::UseTree::Rename(r) => {
                let mut path = prefix[1..].to_vec();
                path.push(r.ident.to_string());
                out.insert(r.rename.to_string(), path.join("::"));
            }
            syn::UseTree::Group(g) => g.items.iter().for_each(|t| walk(t, prefix, out)),
            syn::UseTree::Glob(_) => {}
        }
    }
    fn items(list: &[syn::Item], out: &mut BTreeMap<String, String>) {
        for item in list {
            match item {
                syn::Item::Use(u) => {
                    if let syn::UseTree::Path(p) = &u.tree
                        && p.ident == CRATE
                    {
                        walk(&p.tree, &[CRATE.to_owned()], out);
                    }
                }
                syn::Item::Mod(m) => {
                    if let Some((_, inner)) = &m.content {
                        items(inner, out);
                    }
                }
                _ => {}
            }
        }
    }
    let mut out = BTreeMap::new();
    items(&file.items, &mut out);
    out
}

/// The `php_std` paths `text` calls or names (`php_std::a::b(`, `a::b(` for
/// an imported `a`, `b(` for an imported `b`), as crate paths.
fn crate_paths(text: &str, aliases: &BTreeMap<String, String>) -> BTreeSet<String> {
    let mut out = BTreeSet::new();
    let chars: Vec<(usize, char)> = text.char_indices().collect();
    let mut k = 0;
    while k < chars.len() {
        let c = chars[k].1;
        if !(c.is_ascii_alphabetic() || c == '_') || (k > 0 && (ident_char(chars[k - 1].1) || chars[k - 1].1 == '.')) {
            k += 1;
            continue;
        }
        // An identifier path `a::b::c`.
        let start = chars[k].0;
        let mut end = k;
        loop {
            while end < chars.len() && ident_char(chars[end].1) {
                end += 1;
            }
            if end + 2 < chars.len() && chars[end].1 == ':' && chars[end + 1].1 == ':' && ident_char(chars[end + 2].1) {
                end += 2;
                continue;
            }
            break;
        }
        let stop = chars.get(end).map(|c| c.0).unwrap_or(text.len());
        let path = &text[start..stop];
        let call = text[stop..].starts_with('(');
        k = end.max(k + 1);
        let segments: Vec<&str> = path.split("::").collect();
        let resolved = if segments[0] == CRATE {
            (segments.len() > 1).then(|| segments[1..].join("::"))
        } else if let Some(base) = aliases.get(segments[0]) {
            let rest = &segments[1..];
            (call || !rest.is_empty())
                .then(|| if rest.is_empty() { base.clone() } else { format!("{base}::{}", rest.join("::")) })
        } else {
            None
        };
        if let Some(p) = resolved {
            out.insert(p);
        }
    }
    out
}

/// Methods `text` calls on values: `.name(`.
fn method_calls(text: &str) -> BTreeSet<String> {
    text.match_indices('.')
        .filter_map(|(i, _)| {
            let name: String = text[i + 1..].chars().take_while(|c| ident_char(*c)).collect();
            (!name.is_empty() && text[i + 1 + name.len()..].starts_with('(')).then_some(name)
        })
        .collect()
}

/// The item a crate path names (re-exports through a parent module allowed).
fn resolve<'a>(path: &str, items: &'a [RustItem]) -> Option<&'a RustItem> {
    let segments: Vec<&str> = path.split("::").collect();
    let name = *segments.last()?;
    let before = &segments[..segments.len() - 1];
    let (owner, module) = match before.last() {
        Some(o) if o.chars().next().is_some_and(char::is_uppercase) => (Some(*o), &before[..before.len() - 1]),
        _ => (None, before),
    };
    let module = module.join("::");
    let fits = |i: &&RustItem| {
        i.name == name
            && i.owner.as_deref() == owner
            && (i.module == module || i.module.starts_with(&format!("{module}::")) || module.is_empty())
    };
    items.iter().filter(fits).min_by_key(|i| (i.module != module, i.module.len()))
}

/// The Rust adapter's code per operation: each `"<op>" | ... =>` arm up to the
/// next arm (nested matches contribute their own arms).
pub fn rust_arms(text: &str) -> BTreeMap<String, String> {
    let mut out: BTreeMap<String, String> = BTreeMap::new();
    let mut current: Vec<String> = Vec::new();
    // The arm a nested `match op` sits in, and the operations it names.
    let mut outer: Vec<String> = Vec::new();
    let mut nested: BTreeSet<String> = BTreeSet::new();
    for line in text.lines() {
        let t = line.trim_start();
        if t.starts_with('"')
            && let Some((labels, _)) = t.split_once("=>")
        {
            let ops: Vec<String> = labels
                .split('|')
                .map(str::trim)
                .filter_map(|l| l.strip_prefix('"').and_then(|l| l.strip_suffix('"')))
                .map(str::to_owned)
                .collect();
            if !ops.is_empty() && ops.iter().all(|o| o.contains('.')) {
                if !outer.is_empty() && ops.iter().all(|o| outer.contains(o)) {
                    nested.extend(ops.iter().cloned());
                } else if current.len() > 1 && ops.iter().all(|o| current.contains(o)) {
                    outer = std::mem::take(&mut current);
                    nested = ops.iter().cloned().collect();
                } else {
                    outer.clear();
                    nested.clear();
                }
                current = ops;
            }
        } else if t.starts_with("_ =>") && !outer.is_empty() {
            current = outer.iter().filter(|o| !nested.contains(*o)).cloned().collect();
        } else if t.starts_with("fn ") || t.starts_with("pub ") || t.starts_with("pub(") || t.starts_with("async fn ") {
            current.clear();
            outer.clear();
        }
        for op in &current {
            let body = out.entry(op.clone()).or_default();
            body.push_str(line);
            body.push('\n');
        }
    }
    out
}

/// The bodies of a file's free functions, by name.
fn local_fns(text: &str) -> BTreeMap<String, String> {
    let mut out: BTreeMap<String, String> = BTreeMap::new();
    let mut current: Option<String> = None;
    for line in text.lines() {
        let t = line.strip_prefix("pub(super) ").or_else(|| line.strip_prefix("pub ")).unwrap_or(line);
        if let Some(rest) = t.strip_prefix("fn ").or_else(|| t.strip_prefix("async fn ")) {
            current = Some(rest.chars().take_while(|c| ident_char(*c)).collect());
        } else if line.starts_with(|c: char| !c.is_whitespace() && c != '}') {
            current = None;
        }
        if let Some(name) = &current {
            let body = out.entry(name.clone()).or_default();
            body.push_str(line);
            body.push('\n');
        }
    }
    out
}

/// One PHP name's entry.
struct Entry {
    name: Name,
    ops: BTreeSet<String>,
}

/// `name` without the PHP extension prefix its Rust module drops
/// (`mb_strtolower` → `strtolower`, `json_encode` → `encode`).
fn short(name: &str) -> &str {
    for prefix in ["mb_", "json_", "preg_", "igbinary_", "idn_", "date_", "timezone_"] {
        if let Some(rest) = name.strip_prefix(prefix) {
            return rest;
        }
    }
    name
}

/// `setDate` → `set_date`.
fn snake(name: &str) -> String {
    let mut out = String::new();
    for c in name.chars() {
        if c.is_ascii_uppercase() {
            if !out.is_empty() {
                out.push('_');
            }
            out.push(c.to_ascii_lowercase());
        } else {
            out.push(c);
        }
    }
    out
}

/// Whether a function's rustdoc opens as a variant of PHP function `php`
/// (`PHP \`max($a, $b)\``, `` `serialize($value)` ``) or of Rust item `base`
/// (`` [`to_ascii`] with ... ``).
fn variant_of(doc: &str, php: &str, base: &str) -> bool {
    let doc = doc.trim_start();
    let doc = doc.strip_prefix("PHP ").unwrap_or(doc);
    doc.starts_with(&format!("`{php}(")) || doc.starts_with(&format!("[`{base}`]"))
}

/// Whether an item's rustdoc names the PHP function (`` `strtolower` ``,
/// `` `strtolower($s)` ``, `strtolower()`).
fn doc_names(doc: &str, php: &str) -> bool {
    let first = doc.split("\n\n").next().unwrap_or(doc);
    [format!("`{php}`"), format!("`{php}("), format!("{php}()")].iter().any(|n| first.contains(n.as_str()))
}

/// The Rust items implementing `name` among `candidates` (what its
/// operations' adapters call): those named after it, else those whose doc
/// names it; behaviours keep every candidate.
fn implementing<'a>(name: &Name, candidates: &[&'a RustItem], all: &'a [RustItem]) -> Vec<&'a RustItem> {
    if name.kind == "behaviour" {
        // The items whose doc writes the cast or operator (`(bool) $v`, `$a == $b`):
        // among what the adapter calls first, else anywhere in the crate.
        let markers: Vec<&str> = BEHAVIOURS.iter().filter(|(_, b)| *b == name.name).map(|(m, _)| *m).collect();
        let writes = |i: &&RustItem| {
            let first = i.doc.split("\n\n").next().unwrap_or_default();
            markers.iter().any(|m| first.contains(m) || first.contains(&format!("`{}", m.trim())))
        };
        let called: Vec<&RustItem> = candidates.iter().copied().filter(writes).collect();
        if !called.is_empty() || markers.is_empty() {
            return if called.is_empty() { candidates.to_vec() } else { called };
        }
        let anywhere: Vec<&RustItem> =
            all.iter().filter(|i| matches!(i.kind, "fn" | "method")).filter(writes).take(4).collect();
        return if anywhere.is_empty() { candidates.to_vec() } else { anywhere };
    }
    let (owner, php) = match name.name.split_once("::") {
        Some((class, method)) => (Some(class.rsplit('\\').next().unwrap_or(class)), method),
        None => (None, name.name.as_str()),
    };
    // A method whose rustdoc names it with its class (`DateTimeImmutable::modify($modifier)`).
    if let Some(class) = owner {
        let explicit: Vec<&RustItem> =
            all.iter().filter(|i| i.kind == "method" && i.doc.contains(&format!("`{class}::{php}"))).collect();
        if !explicit.is_empty() {
            return explicit;
        }
    }
    let mut wanted = vec![php.to_owned(), short(php).to_owned(), snake(php), snake(short(php))];
    if owner.is_some() {
        // `getOffset` → `offset`, `createFromFormat` → `from_format`, a constructor → `new` or `parse`.
        let snaked = snake(php);
        for prefix in ["get_", "create_"] {
            if let Some(rest) = snaked.strip_prefix(prefix) {
                wanted.push(rest.to_owned());
            }
        }
        if php == "__construct" {
            wanted.extend(["new".to_owned(), "parse".to_owned()]);
        }
    }
    let named: Vec<&RustItem> = candidates.iter().copied().filter(|i| wanted.contains(&i.name)).collect();
    let named = if owner.is_some() && named.len() > 1 {
        let same: Vec<&RustItem> = named.iter().copied().filter(|i| i.owner.as_deref() == owner).collect();
        if same.is_empty() { named } else { same }
    } else {
        named
    };
    if !named.is_empty() {
        return named;
    }
    if owner.is_none() {
        let documented: Vec<&RustItem> = candidates.iter().copied().filter(|i| doc_names(&i.doc, php)).collect();
        if !documented.is_empty() {
            return documented;
        }
        let anywhere: Vec<&RustItem> =
            all.iter().filter(|i| matches!(i.kind, "fn" | "method") && doc_names(&i.doc, php)).take(3).collect();
        if !anywhere.is_empty() {
            return anywhere;
        }
    }
    // Nothing named after it: what the adapter calls, when that is one thing.
    if candidates.len() == 1 { candidates.to_vec() } else { Vec::new() }
}

/// Fuzz inputs a profile spends on each `filter` value it generates: all of
/// them for a constant, an equal share of each `pick` entry otherwise.
fn shares(profile: &FuzzSpec, field: &str) -> Vec<(i64, f64)> {
    let g = &profile.args["fields"][field];
    match g["gen"].as_str() {
        Some("const") => g["value"].as_i64().map(|v| vec![(v, profile.iterations as f64)]).unwrap_or_default(),
        Some("pick") => {
            let from: Vec<i64> = g["from"].as_array().into_iter().flatten().filter_map(Value::as_i64).collect();
            let each = profile.iterations as f64 / from.len().max(1) as f64;
            let mut out: Vec<(i64, f64)> = Vec::new();
            for v in from {
                match out.iter_mut().find(|(x, _)| *x == v) {
                    Some((_, n)) => *n += each,
                    None => out.push((v, each)),
                }
            }
            out
        }
        _ => Vec::new(),
    }
}

/// `pub const NAME: i64 = 0x0101;` in a Rust source: (name, value).
fn int_consts(text: &str, prefix: &str) -> Vec<(String, i64)> {
    text.lines()
        .filter_map(|l| {
            let rest = l.trim().strip_prefix("pub const ")?;
            let (name, value) = rest.split_once(": i64 = ")?;
            if !name.starts_with(prefix) {
                return None;
            }
            let value = value.trim_end_matches(';').replace('_', "");
            let n = match value.strip_prefix("0x") {
                Some(hex) => i64::from_str_radix(hex, 16).ok()?,
                None => value.parse().ok()?,
            };
            Some((name.to_owned(), n))
        })
        .collect()
}

/// `filter_var()`'s filters: each filter constant (aliases joined), with the
/// cases and fuzz inputs that use it.
fn filters(root: &Path, op: &spec::OpSpec, cases: &[(String, Value)]) -> Vec<Value> {
    let text = std::fs::read_to_string(root.join("crates/support/php-std/src/filter.rs")).unwrap_or_default();
    let mut names: BTreeMap<i64, Vec<String>> = BTreeMap::new();
    for (name, value) in int_consts(&text, "FILTER_") {
        let flag = ["FILTER_FLAG_", "FILTER_REQUIRE_", "FILTER_FORCE_", "FILTER_NULL_", "FILTER_THROW_"];
        if !flag.iter().any(|f| name.starts_with(f)) {
            names.entry(value).or_default().push(name);
        }
    }
    let mut out = Vec::new();
    for (value, list) in &names {
        let n_cases = cases.iter().filter(|(_, args)| args["filter"].as_i64().unwrap_or(0x0204) == *value).count();
        let (mut inputs, mut profiles) = (0.0, 0);
        for p in &op.fuzz {
            if let Some((_, n)) = shares(p, "filter").into_iter().find(|(v, _)| v == value) {
                inputs += n;
                profiles += 1;
            }
        }
        out.push(json!({
            "name": list.join(" / "),
            "value": value,
            "cases": n_cases,
            "fuzz": { "profiles": profiles, "inputs": inputs.round() as u64 },
        }));
    }
    out
}

/// The inventory, and its totals for the library summary.
pub fn inventory(root: &Path, items: &[RustItem], modules: &BTreeMap<String, String>) -> Option<(Value, Value)> {
    let spec = spec::load(root, LIB).ok()?;
    let dir = spec::lib_dir(root, LIB);
    // Cases by operation (a case counts once per operation it runs).
    let mut cases_of: BTreeMap<String, Vec<(String, Value)>> = BTreeMap::new();
    let mut case_ops: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
    let mut total_cases = 0;
    for file in cases::load_dir(&dir.join("cases")).unwrap_or_default() {
        let file_name = file.path.file_name().map(|f| f.to_string_lossy().into_owned()).unwrap_or_default();
        for case in &file.cases {
            total_cases += 1;
            let mut seen = BTreeSet::new();
            for step in &case.steps {
                if seen.insert(step.op.clone()) {
                    cases_of.entry(step.op.clone()).or_default().push((case.name.clone(), step.args.clone()));
                }
            }
            case_ops.insert(format!("{file_name}#{}", case.name), seen);
        }
    }
    let mut php_body: BTreeMap<String, String> = BTreeMap::new();
    // The suite's own classes (`Typed`), which are not PHP's.
    let mut local: BTreeSet<String> = BTreeSet::new();
    for file in files(&dir.join("ops"), "php") {
        let text = std::fs::read_to_string(file).unwrap_or_default();
        for line in text.lines() {
            if let Some(class) =
                line.strip_prefix("use Tests\\").and_then(|l| l.trim_end_matches(';').rsplit('\\').next())
            {
                local.insert(class.to_owned());
            }
        }
        php_body.extend(php_bodies(&text));
    }
    // What each operation's Rust adapter calls.
    let mut rust_calls: BTreeMap<String, Vec<String>> = BTreeMap::new();
    for file in files(&root.join("crates/tools/compat/src/libs/php_std"), "rs") {
        let text = std::fs::read_to_string(&file).unwrap_or_default();
        let Ok(parsed) = syn::parse_file(&text) else { continue };
        let names = aliases(&parsed);
        let fns = local_fns(&text);
        // Types the adapter imports, whose methods its arms call on values (`.modify(`).
        let types: Vec<&str> = names
            .values()
            .filter_map(|p| resolve(p, items))
            .filter(|i| matches!(i.kind, "struct" | "enum"))
            .map(|i| i.name.as_str())
            .collect();
        for (op, arm) in rust_arms(&text) {
            let mut found = crate_paths(&arm, &names);
            // Methods called on values, in the arm or the adapter's helpers it calls (`uri_value`).
            let mut code = arm.clone();
            for (name, body) in &fns {
                if arm.contains(&format!("{name}(")) {
                    code.push_str(body);
                }
            }
            for method in method_calls(&code).into_iter().filter(|m| !PLUMBING.contains(&m.as_str())) {
                for item in items.iter().filter(|i| i.kind == "method" && i.name == method) {
                    if item.owner.as_deref().is_some_and(|o| types.contains(&o)) {
                        found.insert(item.path.clone());
                    }
                }
            }
            // The arm hands over to helpers of the adapter: what they call
            // (`unserialize(` → `igbinary::unserialize`).
            for (name, body) in &fns {
                if arm.contains(&format!("{name}(")) {
                    found.extend(crate_paths(body, &names));
                }
            }
            rust_calls.entry(op).or_default().extend(found);
        }
    }
    let area = |op: &str| op.split('.').next().unwrap_or_default().to_owned();
    // Each operation's PHP names, merged into entries by name.
    let mut entries: BTreeMap<String, Entry> = BTreeMap::new();
    for (op, s) in &spec.ops {
        let mut found = php_names(&s.doc, &s.covers, php_body.get(op).map(String::as_str).unwrap_or_default());
        found.retain(|n| !n.name.split_once("::").is_some_and(|(class, _)| local.contains(class)));
        // Outside juggling, a cast or operator in a doc is how the result is
        // read (`microtime(true) > 1e9`), not what is tested, unless nothing else is.
        let juggling = matches!(area(op).as_str(), "value" | "types");
        if !juggling && found.iter().any(|n| n.kind != "behaviour") {
            found.retain(|n| n.kind != "behaviour");
        }
        if found.is_empty() {
            // The PHP value model itself: named by what the doc says it checks.
            let what = s.doc.split_once(": ").map(|(_, w)| w).unwrap_or(&s.doc);
            let what = what
                .split([',', ';', '('])
                .next()
                .unwrap_or_default()
                .split(' ')
                .take(10)
                .collect::<Vec<_>>()
                .join(" ");
            let mut chars = what.trim_end_matches([',', ':', '.']).chars();
            let name = chars
                .next()
                .map(|c| c.to_uppercase().collect::<String>() + chars.as_str())
                .unwrap_or_else(|| op.clone());
            found.push(Name::new(name, "behaviour"));
        }
        for name in found {
            entries
                .entry(name.name.clone())
                .or_insert_with(|| Entry { name: name.clone(), ops: BTreeSet::new() })
                .ops
                .insert(op.clone());
        }
    }
    let usage = users::Usage::scan(root);
    let mut users_total: BTreeSet<String> = BTreeSet::new();
    let group_of = |areas: &[String]| {
        GROUPS.iter().find(|(_, _, a)| areas.iter().any(|x| a.contains(&x.as_str()))).map(|g| g.0).unwrap_or("other")
    };
    let mut functions = Vec::new();
    for e in entries.values() {
        let mut areas: Vec<String> = e.ops.iter().map(|o| area(o)).collect();
        // A behaviour belongs with juggling when any of its operations does.
        if e.name.kind == "behaviour" && areas.iter().any(|a| a == "value" || a == "types") {
            areas.retain(|a| a == "value" || a == "types");
        }
        let candidates: Vec<&RustItem> = {
            let mut seen = BTreeSet::new();
            e.ops
                .iter()
                .flat_map(|o| rust_calls.get(o).into_iter().flatten())
                .filter_map(|p| resolve(p, items))
                .filter(|i| matches!(i.kind, "fn" | "method") && seen.insert(i.path.clone()))
                .collect()
        };
        let mut rust = implementing(&e.name, &candidates, items);
        // The function of the same name beside it (`filter_var` beside `filter_var_with`),
        // which crates may call instead of what the adapter calls.
        if e.name.kind == "function" {
            let modules: BTreeSet<&str> = rust.iter().map(|i| i.module.as_str()).collect();
            let twin: Vec<&RustItem> = items
                .iter()
                .filter(|i| i.kind == "fn" && i.name == e.name.name && modules.contains(i.module.as_str()))
                .filter(|i| !rust.iter().any(|r| r.path == i.path))
                .collect();
            rust.splice(0..0, twin);
            // Its variants beside it, documented as the PHP function or as the
            // item (`max_pair`: "PHP `max($a, $b)` ...", `to_ascii_info`:
            // "[`to_ascii`] with ..."), unless another PHP function is named so (`date_parse`).
            let variants: Vec<&RustItem> = items
                .iter()
                .filter(|i| i.kind == "fn" && modules.contains(i.module.as_str()))
                .filter(|i| !rust.iter().any(|r| r.path == i.path))
                .filter(|i| i.name == e.name.name || !entries.contains_key(&i.name))
                .filter(|i| {
                    rust.iter().any(|r| {
                        r.kind == "fn"
                            && i.name.starts_with(&format!("{}_", r.name))
                            && variant_of(&i.doc, &e.name.name, &r.name)
                    })
                })
                .collect();
            rust.extend(variants);
        }
        // Its group: that of the Rust module implementing it when an operation
        // area is named after it (`basename`: `path`), else its operations' most common area.
        let module = rust.first().map(|i| i.module.split("::").next().unwrap_or_default().to_owned());
        let group = match module.filter(|m| areas.contains(m)) {
            Some(m) => group_of(&[m]),
            None => {
                let most = areas
                    .iter()
                    .max_by_key(|a| (areas.iter().filter(|b| b == a).count(), std::cmp::Reverse((*a).clone())));
                group_of(&most.cloned().into_iter().collect::<Vec<_>>())
            }
        };
        let mut n_cases = BTreeSet::new();
        for op in &e.ops {
            for (case, _) in cases_of.get(op).into_iter().flatten() {
                n_cases.insert((op.clone(), case.clone()));
            }
        }
        let (mut profiles, mut inputs) = (0, 0);
        for op in &e.ops {
            for f in &spec.ops[op].fuzz {
                profiles += 1;
                inputs += f.iterations;
            }
        }
        let token_match = |symbol: &str| {
            let short_name = e.name.name.rsplit("::").next().unwrap_or(&e.name.name);
            symbol.split(|c: char| !(ident_char(c) || c == '\\' || c == ':')).map(|t| t.trim_end_matches(':')).any(
                |t| {
                    e.name.kind != "behaviour"
                        && (t == e.name.name || (e.name.kind == "method" && t.ends_with(&format!("::{short_name}"))))
                },
            )
        };
        let quirks: Vec<Value> = spec
            .quirks
            .iter()
            .filter(|q| {
                case_ops.get(&q.case).is_some_and(|ops| ops.iter().any(|o| e.ops.contains(o))) || token_match(&q.symbol)
            })
            .map(|q| json!({ "symbol": q.symbol, "description": q.description, "case": q.case }))
            .collect();
        let deviations: Vec<Value> = spec
            .deviations
            .iter()
            .filter(|d| token_match(&d.symbol))
            .map(|d| json!({ "symbol": d.symbol, "php": d.php, "reason": d.reason }))
            .collect();
        // Each crate reaching it, and the php-std items it goes through when
        // it calls none of `rust` itself.
        let mut reach: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
        for i in &rust {
            for (krate, via) in usage.users(&i.path) {
                match reach.get_mut(&krate) {
                    Some(v) if v.is_empty() => {}
                    Some(v) if via.is_empty() => v.clear(),
                    Some(v) => v.extend(via),
                    None => {
                        reach.insert(krate, via);
                    }
                }
            }
        }
        users_total.extend(reach.keys().cloned());
        let used_by: Vec<&String> = reach.keys().collect();
        let used_via: BTreeMap<&String, &BTreeSet<String>> = reach.iter().filter(|(_, v)| !v.is_empty()).collect();
        let docs: Vec<&str> = e.ops.iter().map(|o| spec.ops[o].doc.as_str()).filter(|d| !d.is_empty()).collect();
        let mut entry = json!({
            "name": e.name.name,
            "kind": e.name.kind,
            "group": group,
            "ops": e.ops,
            "doc": docs,
            "rust": rust.iter().map(|i| i.path.clone()).collect::<Vec<_>>(),
            "cases": n_cases.len(),
            "fuzz": { "profiles": profiles, "inputs": inputs },
            "quirks": quirks,
            "deviations": deviations,
            "used_by": used_by,
            "used_via": used_via,
        });
        if e.name.name == "filter_var"
            && let Some(op) = spec.ops.get("filter.filter_var")
        {
            entry["variants"] =
                json!(filters(root, op, cases_of.get("filter.filter_var").map(Vec::as_slice).unwrap_or_default()));
        }
        functions.push(entry);
    }
    let groups: Vec<Value> = GROUPS
        .iter()
        .filter_map(|(id, title, areas)| {
            let n = functions.iter().filter(|f| f["group"] == *id).count();
            let module_doc = areas.iter().find_map(|a| modules.get(*a).cloned()).unwrap_or_default();
            (n > 0).then(|| json!({ "id": id, "title": title, "areas": areas, "functions": n, "doc": module_doc }))
        })
        .collect();
    let fuzz_inputs: u64 = spec.ops.values().flat_map(|o| &o.fuzz).map(|f| f.iterations).sum();
    let fuzz_profiles: usize = spec.ops.values().map(|o| o.fuzz.len()).sum();
    let kinds = |k: &str| functions.iter().filter(|f| f["kind"] == k).count();
    let totals = json!({
        "entries": functions.len(),
        "functions": kinds("function"),
        "methods": kinds("method"),
        "behaviours": kinds("behaviour"),
        "groups": groups.len(),
        "operations": spec.ops.len(),
        "cases": total_cases,
        "fuzz": { "profiles": fuzz_profiles, "inputs": fuzz_inputs },
        "quirks": spec.quirks.len(),
        "deviations": spec.deviations.len(),
        "used_by": users_total.len(),
    });
    Some((json!({ "totals": totals, "groups": groups, "functions": functions }), totals))
}

fn files(dir: &Path, ext: &str) -> Vec<std::path::PathBuf> {
    fn walk(dir: &Path, ext: &str, out: &mut Vec<std::path::PathBuf>) {
        for entry in std::fs::read_dir(dir).into_iter().flatten().flatten() {
            let path = entry.path();
            if path.is_dir() {
                walk(&path, ext, out);
            } else if path.extension().is_some_and(|e| e == ext) {
                out.push(path);
            }
        }
    }
    let mut out = Vec::new();
    walk(dir, ext, &mut out);
    out.sort();
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(doc: &str, covers: &[&str], body: &str) -> Vec<String> {
        let covers: Vec<String> = covers.iter().map(|c| (*c).to_owned()).collect();
        php_names(doc, &covers, body).into_iter().map(|n| n.name).collect()
    }

    #[test]
    fn functions_the_adapter_calls() {
        assert_eq!(
            names("strtolower($string): ASCII-only lowercase", &[], "fn (array $a) => strtolower(...$a),"),
            ["strtolower"]
        );
        assert_eq!(
            names(
                "['json' => json_encode($v, $flags, $depth), 'error' => json_last_error()]",
                &[],
                "json_encode($v); json_last_error();"
            ),
            ["json_encode", "json_last_error"]
        );
        // Named in the doc but not called: left out.
        assert_eq!(names("mb_strlen($s), like strlen($s)", &[], "mb_strlen($a['s'])"), ["mb_strlen"]);
        // Observers go when something else is tested.
        assert_eq!(
            names(
                "[strlen(microtime()), is_float(microtime(true))]",
                &[],
                "[\\strlen(microtime()), \\is_float(microtime(true))]"
            ),
            ["microtime"]
        );
        assert_eq!(names("ip2long($ip): the address", &["ip2long()"], ""), ["ip2long"]);
        assert_eq!(names("x", &["Uri\\Rfc3986\\Uri::parse", "Utopia\\*"], ""), ["Uri\\Rfc3986\\Uri::parse"]);
    }

    #[test]
    fn methods_and_constructors() {
        let body =
            "(new DateTimeImmutable($a['s']))->modify($a['m'])->format($a['f']); DateTime::createFromFormat($f, $s)";
        assert_eq!(
            names("(new DateTimeImmutable($base))->modify($s)->format($format)", &[], body),
            ["DateTimeImmutable::__construct", "DateTimeImmutable::modify", "DateTimeImmutable::format"]
        );
        assert_eq!(
            names("DateTime::createFromFormat($format, $s, $tz)->format($out)", &[], body),
            ["DateTime::createFromFormat", "DateTime::format"]
        );
        // A `new` in an argument list does not take over the chain.
        let body = "(new DateTimeImmutable($a['at']))->setTimezone(new DateTimeZone($a['tz']))->format($a['format'])";
        assert_eq!(
            names("(new DateTimeImmutable('@...'))->setTimezone(new DateTimeZone($tz))->format($format)", &[], body),
            [
                "DateTimeImmutable::__construct",
                "DateTimeImmutable::setTimezone",
                "DateTimeZone::__construct",
                "DateTimeImmutable::format"
            ]
        );
        // Written bare in the doc, called on an object in the adapter.
        let body = "$d = new DateTimeImmutable($a['s']); return [$d->getTimestamp(), $d->getOffset()];";
        assert_eq!(
            names("[getTimestamp(), getOffset()] of new DateTimeImmutable($s)", &[], body),
            ["DateTimeImmutable::getTimestamp", "DateTimeImmutable::getOffset", "DateTimeImmutable::__construct"]
        );
    }

    #[test]
    fn behaviours_from_casts_and_operators() {
        assert_eq!(names("$a == $b", &[], "$a['a'] == $a['b']"), ["== loose comparison"]);
        assert_eq!(
            names("gettype($v), (bool) $v, (string) $v (or the Error), (float) $v", &[], "gettype($v); (bool) $v"),
            ["gettype", "(string) cast", "(bool) cast", "(float) cast"]
        );
        // Only the expression counts, not the explanation after ": ".
        assert_eq!(names("is_numeric($v): true for \"1\" == 1", &[], "is_numeric($a['v'])"), ["is_numeric"]);
    }

    #[test]
    fn php_adapter_bodies() {
        let text = "<?php\nreturn [\n    'a.one' => fn (array $a) => one(...$a),\n    'a.two' => function (array $a) {\n        return two($a);\n    },\n];\n";
        let bodies = php_bodies(text);
        assert!(bodies["a.one"].contains("one(") && !bodies["a.one"].contains("two("));
        assert!(bodies["a.two"].contains("return two($a);"));
        // Helper closures go with the operations using them.
        let text = "<?php\n$read = static function (string $s): array {\n    return [three($s)];\n};\n\nreturn [\n    'a.three' => fn (array $a) => $read($a['s']),\n    'a.four' => fn (array $a) => four($a),\n];\n";
        let bodies = php_bodies(text);
        assert!(bodies["a.three"].contains("three($s)"));
        assert!(!bodies["a.four"].contains("three("));
    }

    #[test]
    fn rust_adapter_arms_and_paths() {
        let text = "use php_std::string::{self, Pad};\nuse php_std::url::rfc3986::Uri;\n\nfn helper(a: &[u8]) -> Vec<u8> { string::strrev(a) }\n\npub async fn call(op: &str) -> OpResult {\n    match op {\n        \"s.lower\" => ok(string::strtolower(&s()?)),\n        \"s.trim\" | \"s.ltrim\" => {\n            let p = Pad::Left;\n            match op {\n                \"s.trim\" => string::trim(&s, b\" \"),\n                _ => string::ltrim(&s, b\" \"),\n            }\n        }\n        \"s.rev\" => helper(&s()?),\n        \"s.uri\" => Uri::parse(&s()?),\n        _ => Err(()),\n    }\n}\n";
        let arms = rust_arms(text);
        assert!(arms["s.lower"].contains("strtolower") && !arms["s.lower"].contains("trim"));
        assert!(arms["s.trim"].contains("string::trim") && !arms["s.trim"].contains("string::ltrim"));
        assert!(arms["s.ltrim"].contains("let p") && arms["s.ltrim"].contains("string::ltrim"));
        let parsed = syn::parse_file(text).unwrap();
        let names = aliases(&parsed);
        assert_eq!(names["string"], "string");
        assert_eq!(names["Uri"], "url::rfc3986::Uri");
        let paths = crate_paths(&arms["s.lower"], &names);
        assert!(paths.contains("string::strtolower"));
        assert!(crate_paths(&arms["s.uri"], &names).contains("url::rfc3986::Uri::parse"));
        assert!(local_fns(text)["helper"].contains("string::strrev"));
        assert!(crate_paths("php_std::json::encode(&v)", &BTreeMap::new()).contains("json::encode"));
        assert_eq!(
            method_calls("d.modify(&s)?.format(f); x.len"),
            BTreeSet::from(["modify".to_owned(), "format".to_owned()])
        );
    }

    fn item(module: &str, owner: Option<&str>, name: &str, doc: &str) -> RustItem {
        let path = [Some(module), owner, Some(name)]
            .into_iter()
            .flatten()
            .filter(|s| !s.is_empty())
            .collect::<Vec<_>>()
            .join("::");
        RustItem {
            path,
            module: module.to_owned(),
            kind: if owner.is_some() { "method" } else { "fn" },
            owner: owner.map(str::to_owned),
            implements: None,
            name: name.to_owned(),
            signature: String::new(),
            doc: doc.to_owned(),
            inherits: None,
            file: String::new(),
            line: 0,
            function: None,
        }
    }

    #[test]
    fn rust_items_implementing_a_php_name() {
        let all = vec![
            item("string", None, "strtolower", "PHP `strtolower`: ASCII only."),
            item("string", None, "to_php_string", "A value as a string."),
            item("datetime", Some("DateTime"), "offset", "The UTC offset."),
            item("datetime", Some("DateTime"), "parse", "Parses a date."),
            item("datetime", Some("DateTime"), "with_date", "A copy.\n\nPHP: `DateTimeImmutable::setDate()`."),
            item("value", None, "loose_eq", "PHP 8 `$a == $b`."),
            item("value", None, "le_numbers", "PHP 8 `$a <= $b` for two numbers."),
        ];
        let refs: Vec<&RustItem> = all.iter().filter(|i| i.name != "with_date").collect();
        let paths = |found: Vec<&RustItem>| found.into_iter().map(|i| i.path.clone()).collect::<Vec<_>>();
        // Named after the PHP function, among what the adapter calls.
        assert_eq!(paths(implementing(&Name::new("strtolower", "function"), &refs[..2], &all)), ["string::strtolower"]);
        // A getter without `get`, a constructor as `parse`.
        let methods = &refs[2..4];
        assert_eq!(
            paths(implementing(&Name::new("DateTimeImmutable::getOffset", "method"), methods, &all)),
            ["datetime::DateTime::offset"]
        );
        assert_eq!(
            paths(implementing(&Name::new("DateTime::__construct", "method"), methods, &all)),
            ["datetime::DateTime::parse"]
        );
        // Named with its class in a rustdoc.
        assert_eq!(
            paths(implementing(&Name::new("DateTimeImmutable::setDate", "method"), methods, &all)),
            ["datetime::DateTime::with_date"]
        );
        // Nothing named after it among several calls: none rather than a guess.
        assert!(implementing(&Name::new("DateTime::getLastErrors", "method"), methods, &all).is_empty());
        // A behaviour: the item whose doc writes the operator, found anywhere.
        assert_eq!(paths(implementing(&Name::new("== loose comparison", "behaviour"), &[], &all)), ["value::loose_eq"]);
        assert_eq!(
            paths(implementing(&Name::new("< <= > >= ordering", "behaviour"), &[], &all)),
            ["value::le_numbers"]
        );
    }

    #[test]
    fn rust_names_of_php_functions() {
        assert_eq!(short("mb_strtolower"), "strtolower");
        assert_eq!(short("json_encode"), "encode");
        assert_eq!(snake("setDate"), "set_date");
        assert!(doc_names("PHP `strtolower`: ASCII only.", "strtolower"));
        assert!(doc_names("`strtotime($datetime, $base)` in UTC.", "strtotime"));
        assert!(!doc_names("Like strtolower but wider.", "strtolower"));
        assert_eq!(
            int_consts("pub const FILTER_VALIDATE_INT: i64 = 0x0101;\npub const X: i64 = 3;", "FILTER_"),
            [("FILTER_VALIDATE_INT".to_owned(), 257)]
        );
    }

    #[test]
    fn variants_of_a_function() {
        assert!(variant_of("PHP `max($a, $b)` of two floats", "max", "max"));
        assert!(variant_of("`serialize($value)`, appended to `out`.", "serialize", "serialize"));
        assert!(variant_of("[`to_ascii`] with what PHP's `$idna_info` receives.", "idn_to_ascii", "to_ascii"));
        // An engine the function shares is not a variant of it.
        assert!(!variant_of("PHP numeric-string parsing (`is_numeric` + `+ 0`).", "is_numeric", "numeric"));
        assert!(!variant_of("[`numeric_str_ex`] on bytes.", "is_numeric", "numeric"));
    }
}
