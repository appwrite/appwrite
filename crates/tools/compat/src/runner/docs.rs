//! Both APIs' documentation side by side, for `compat report`: the Rust
//! items of a crate with their doc comments, and which PHP symbol each one
//! documents.
//!
//! Rust docs name the PHP API they port in backticks (`` `getProperty($key)` ``,
//! `` `Asymmetric::generateKeyPair()` ``, `` (`Utopia\Auth\Store`) ``), so a
//! PHP symbol is linked to the items whose docs reference it: a method by
//! `Class::method`, or by `method(` inside the impl of a type whose own doc
//! names the class; a function by `function(`. A trait implementation's
//! method without docs of its own carries the trait method's references. A
//! public method named like a PHP method (snake case, `getX` as `x`,
//! `__construct` as `new`) on such a type is linked by name.

use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};

use serde_json::{Value, json};
use syn::{Attribute, ImplItem, Item, TraitItem, Visibility};

/// A public item of a crate, documented or not.
pub struct RustItem {
    pub path: String,
    /// The module that declares it (`jwt::key`; empty at the crate root).
    pub module: String,
    pub kind: &'static str,
    /// The type an `impl` or trait method belongs to.
    pub owner: Option<String>,
    /// The trait an `impl Trait for Type` method implements.
    pub implements: Option<String>,
    pub name: String,
    pub signature: String,
    pub doc: String,
    /// The trait method whose docs an undocumented implementation inherits.
    pub inherits: Option<(String, String)>,
    pub file: String,
    pub line: usize,
    /// A function's parts: `self`, `&self` or `&mut self` (`None`: an
    /// associated or free function), each parameter as (name, type), and
    /// the return type (empty for `()`).
    pub function: Option<Function>,
}

pub struct Function {
    pub receiver: Option<String>,
    pub params: Vec<(String, String)>,
    pub output: String,
    pub is_async: bool,
}

/// Tokens printed as Rust is written: `& 'a [u8]` → `&'a [u8]`.
pub fn tidy(tokens: &str) -> String {
    let mut s = tokens.to_owned();
    for (from, to) in [
        (" :: ", "::"),
        (":: ", "::"),
        (" ::", "::"),
        ("& ", "&"),
        ("' ", "'"),
        (" <", "<"),
        ("< ", "<"),
        (" >", ">"),
        (" ,", ","),
        ("( ", "("),
        (" )", ")"),
        ("[ ", "["),
        (" ]", "]"),
        ("! ", "!"),
    ] {
        s = s.replace(from, to);
    }
    s
}

fn function(sig: &syn::Signature) -> Function {
    use quote::ToTokens;
    let mut receiver = None;
    let mut params = Vec::new();
    for input in &sig.inputs {
        match input {
            syn::FnArg::Receiver(r) => {
                receiver = Some(match (&r.reference, &r.mutability) {
                    (Some(_), Some(_)) => "&mut self".to_owned(),
                    (Some(_), None) => "&self".to_owned(),
                    (None, _) => "self".to_owned(),
                });
            }
            syn::FnArg::Typed(t) => {
                let name = match &*t.pat {
                    syn::Pat::Ident(i) => i.ident.to_string(),
                    other => tidy(&other.to_token_stream().to_string()),
                };
                params.push((name, tidy(&t.ty.to_token_stream().to_string())));
            }
        }
    }
    let output = match &sig.output {
        syn::ReturnType::Default => String::new(),
        syn::ReturnType::Type(_, ty) => tidy(&ty.to_token_stream().to_string()),
    };
    Function { receiver, params, output, is_async: sig.asyncness.is_some() }
}

impl RustItem {
    pub fn to_json(&self) -> Value {
        json!({
            "path": self.path,
            "module": self.module,
            "name": self.name,
            "owner": self.owner,
            "kind": self.kind,
            "implements": self.implements,
            "signature": self.signature,
            "doc": self.doc,
            "inherits": self.inherits.as_ref().map(|(path, _)| path),
            "inherited_doc": self.inherits.as_ref().map(|(_, doc)| doc),
            "file": self.file,
            "line": self.line,
            "function": self.function.as_ref().map(|f| json!({
                "receiver": f.receiver,
                "params": f.params.iter().map(|(n, t)| json!({ "name": n, "type": t })).collect::<Vec<_>>(),
                "output": f.output,
                "async": f.is_async,
            })),
        })
    }

    /// The spans the doc (or the inherited one) sets in backticks.
    fn refs(&self) -> impl Iterator<Item = &str> {
        let doc = match &self.inherits {
            Some((_, doc)) if self.doc.is_empty() => doc.as_str(),
            _ => self.doc.as_str(),
        };
        doc.split('`').skip(1).step_by(2)
    }
}

/// Every public item under `crate_dir/src`.
pub fn items(root: &Path, crate_dir: &str) -> Vec<RustItem> {
    let src = root.join(crate_dir).join("src");
    let mut files = Vec::new();
    collect(&src, &mut files);
    files.sort();
    let tests = test_modules(&src, &files);
    let mut out = Vec::new();
    for file in files {
        let Ok(text) = std::fs::read_to_string(&file) else {
            continue;
        };
        let Ok(parsed) = syn::parse_file(&text) else {
            continue;
        };
        let rel = file.strip_prefix(&src).unwrap_or(&file);
        let mut module: Vec<String> = rel.with_extension("").iter().map(|c| c.to_string_lossy().into_owned()).collect();
        if matches!(module.last().map(String::as_str), Some("lib" | "mod")) {
            module.pop();
        }
        if excluded(&module, &tests) {
            continue;
        }
        let file_name = file.strip_prefix(root).unwrap_or(&file).to_string_lossy().into_owned();
        let lines: Vec<&str> = text.lines().collect();
        let mut walker = Walker { file: &file_name, lines: &lines, out: &mut out, implements: None };
        walker.items(&parsed.items, &module);
    }
    // Undocumented trait implementations carry their trait method's docs.
    let traits: BTreeMap<(String, String), (String, String)> = out
        .iter()
        .filter(|i| i.kind == "method" && i.implements.is_none() && !i.doc.is_empty())
        .filter_map(|i| Some(((i.owner.clone()?, i.name.clone()), (i.path.clone(), i.doc.clone()))))
        .collect();
    for item in &mut out {
        if item.doc.is_empty()
            && let Some(t) = &item.implements
        {
            item.inherits = traits.get(&(t.clone(), item.name.clone())).cloned();
        }
    }
    out
}

fn collect(dir: &Path, files: &mut Vec<PathBuf>) {
    let Ok(entries) = std::fs::read_dir(dir) else {
        return;
    };
    for entry in entries.flatten() {
        let path = entry.path();
        if path.is_dir() {
            collect(&path, files);
        } else if path.extension().is_some_and(|e| e == "rs") {
            files.push(path);
        }
    }
}

struct Walker<'a> {
    file: &'a str,
    lines: &'a [&'a str],
    out: &'a mut Vec<RustItem>,
    /// The trait of the `impl Trait for Type` being walked.
    implements: Option<String>,
}

fn doc(attrs: &[Attribute]) -> String {
    let mut lines = Vec::new();
    for attr in attrs {
        if !attr.path().is_ident("doc") {
            continue;
        }
        if let syn::Meta::NameValue(nv) = &attr.meta
            && let syn::Expr::Lit(syn::ExprLit { lit: syn::Lit::Str(s), .. }) = &nv.value
        {
            let line = s.value();
            lines.push(line.strip_prefix(' ').unwrap_or(&line).to_owned());
        }
    }
    lines.join("\n")
}

fn public(vis: &Visibility) -> bool {
    matches!(vis, Visibility::Public(_))
}

fn type_name(ty: &syn::Type) -> Option<String> {
    match ty {
        syn::Type::Path(p) => p.path.segments.last().map(|s| s.ident.to_string()),
        syn::Type::Reference(r) => type_name(&r.elem),
        _ => None,
    }
}

impl Walker<'_> {
    /// The declaration as written, from line `line` to the body or `;`.
    fn signature(&self, line: usize) -> String {
        let mut parts: Vec<&str> = Vec::new();
        for l in self.lines.iter().skip(line.saturating_sub(1)).take(30) {
            let l = l.trim();
            if let Some(at) = l.find('{') {
                parts.push(l[..at].trim_end());
                break;
            }
            parts.push(l);
            if l.ends_with(';') {
                break;
            }
        }
        let mut sig = parts.join(" ");
        for (from, to) in [("( ", "("), (", )", ")"), (" )", ")")] {
            sig = sig.replace(from, to);
        }
        sig.trim().trim_end_matches(';').trim_end().to_owned()
    }

    fn set_function(&mut self, sig: &syn::Signature) {
        if let Some(last) = self.out.last_mut() {
            last.function = Some(function(sig));
        }
    }

    fn push(
        &mut self,
        attrs: &[Attribute],
        kind: &'static str,
        module: &[String],
        owner: Option<&str>,
        name: String,
        line: usize,
    ) {
        // Test-only items (`#[cfg(test)]`, `#[cfg(doctest)]`) are not API.
        if test_only(attrs) {
            return;
        }
        let doc = doc(attrs);
        let mut path: Vec<&str> = module.iter().map(String::as_str).collect();
        if let Some(o) = owner {
            path.push(o);
        }
        path.push(&name);
        self.out.push(RustItem {
            path: path.join("::"),
            module: module.join("::"),
            kind,
            owner: owner.map(str::to_owned),
            implements: if kind == "method" { self.implements.clone() } else { None },
            signature: self.signature(line),
            doc,
            inherits: None,
            file: self.file.to_owned(),
            line,
            name,
            function: None,
        });
    }

    fn items(&mut self, items: &[Item], module: &[String]) {
        for item in items {
            match item {
                Item::Fn(f) if public(&f.vis) => {
                    let line = f.sig.fn_token.span.start().line;
                    self.push(&f.attrs, "fn", module, None, f.sig.ident.to_string(), line);
                    self.set_function(&f.sig);
                }
                Item::Struct(s) if public(&s.vis) => {
                    self.push(&s.attrs, "struct", module, None, s.ident.to_string(), s.ident.span().start().line);
                }
                Item::Enum(e) if public(&e.vis) => {
                    self.push(&e.attrs, "enum", module, None, e.ident.to_string(), e.ident.span().start().line);
                }
                Item::Type(t) if public(&t.vis) => {
                    self.push(&t.attrs, "type", module, None, t.ident.to_string(), t.ident.span().start().line);
                }
                Item::Const(c) if public(&c.vis) => {
                    self.push(&c.attrs, "const", module, None, c.ident.to_string(), c.ident.span().start().line);
                }
                Item::Trait(t) if public(&t.vis) => {
                    let name = t.ident.to_string();
                    self.push(&t.attrs, "trait", module, None, name.clone(), t.ident.span().start().line);
                    for ti in &t.items {
                        if let TraitItem::Fn(f) = ti {
                            let line = f.sig.fn_token.span.start().line;
                            self.push(&f.attrs, "method", module, Some(&name), f.sig.ident.to_string(), line);
                            self.set_function(&f.sig);
                        }
                    }
                }
                Item::Impl(i) => {
                    let Some(owner) = type_name(&i.self_ty) else {
                        continue;
                    };
                    let of_trait = i.trait_.is_some();
                    self.implements =
                        i.trait_.as_ref().and_then(|(_, path, _)| path.segments.last()).map(|s| s.ident.to_string());
                    for ii in &i.items {
                        if let ImplItem::Fn(f) = ii
                            && (of_trait || public(&f.vis))
                        {
                            let line = f.sig.fn_token.span.start().line;
                            self.push(&f.attrs, "method", module, Some(&owner), f.sig.ident.to_string(), line);
                            self.set_function(&f.sig);
                        }
                    }
                    self.implements = None;
                }
                Item::Mod(m)
                    if (public(&m.vis) || m.content.is_some()) && !m.attrs.iter().any(|a| a.path().is_ident("cfg")) =>
                {
                    if let Some((_, content)) = &m.content {
                        let mut inner = module.to_vec();
                        inner.push(m.ident.to_string());
                        self.items(content, &inner);
                    }
                }
                _ => {}
            }
        }
    }
}

/// A PHP symbol split into class (short and full) and member.
struct Symbol<'a> {
    full: &'a str,
    class: Option<&'a str>,
    short_class: Option<&'a str>,
    member: &'a str,
}

fn split(symbol: &str) -> Symbol<'_> {
    match symbol.split_once("::") {
        Some((class, member)) => Symbol {
            full: symbol,
            class: Some(class),
            short_class: Some(class.rsplit('\\').next().unwrap_or(class)),
            member,
        },
        None => {
            let f = symbol.trim_end_matches("()");
            Symbol { full: symbol, class: None, short_class: None, member: f.rsplit('\\').next().unwrap_or(f) }
        }
    }
}

/// `getProperty` → `get_property`.
fn snake(name: &str) -> String {
    let mut out = String::new();
    for (i, c) in name.chars().enumerate() {
        if c.is_ascii_uppercase() {
            if i > 0 {
                out.push('_');
            }
            out.push(c.to_ascii_lowercase());
        } else {
            out.push(c);
        }
    }
    out
}

/// A backticked reference as `(class, member)`: `Asymmetric::generateKeyPair($bits)`
/// → (`Asymmetric`, `generateKeyPair`), `new Verifier(...)` → (`Verifier`, `__construct`).
fn reference(text: &str) -> Option<(Option<&str>, &str)> {
    let text = text.trim().trim_start_matches("$this->").trim_start_matches("->").trim_start_matches('\\');
    if let Some(rest) = text.strip_prefix("new ") {
        let class = rest.split(['(', ' ']).next()?;
        return Some((Some(class.rsplit('\\').next()?), "__construct"));
    }
    let head = text.split('(').next()?;
    if !text.contains('(') && !head.contains("::") {
        return None;
    }
    let head = head.trim();
    match head.rsplit_once("::") {
        Some((class, member)) => Some((Some(class.rsplit('\\').next()?), member)),
        None => Some((None, head.rsplit('\\').next()?)),
    }
}

/// For each PHP symbol, the indexes of the Rust items documenting it and how
/// each was linked.
pub fn link(symbols: &[String], items: &[RustItem]) -> BTreeMap<String, Vec<(usize, &'static str)>> {
    let parsed: Vec<Symbol> = symbols.iter().map(|s| split(s)).collect();
    let classes: BTreeSet<&str> = parsed.iter().filter_map(|s| s.class).collect();
    // Rust type → the PHP classes its doc names (or that share its name).
    let mut owners: BTreeMap<&str, BTreeSet<&str>> = BTreeMap::new();
    for item in items.iter().filter(|i| matches!(i.kind, "struct" | "enum" | "trait" | "type")) {
        for class in &classes {
            let short = class.rsplit('\\').next().unwrap_or(class);
            let named = item.refs().any(|r| {
                let r = r.trim().trim_start_matches('\\');
                r == *class
                    || r == short
                    || r.starts_with(&format!("{class}::"))
                    || r.starts_with(&format!("{short}::"))
            });
            if named || item.name.eq_ignore_ascii_case(short) {
                owners.entry(item.name.as_str()).or_default().insert(class);
            }
        }
    }
    let mut by_member: BTreeMap<&str, Vec<usize>> = BTreeMap::new();
    for (k, s) in parsed.iter().enumerate() {
        by_member.entry(s.member).or_default().push(k);
    }
    let mut links: BTreeMap<String, Vec<(usize, &'static str)>> = BTreeMap::new();
    for (index, item) in items.iter().enumerate() {
        let item_classes = item.owner.as_deref().and_then(|o| owners.get(o));
        for r in item.refs() {
            let Some((class, member)) = reference(r) else {
                continue;
            };
            let Some(candidates) = by_member.get(member) else {
                continue;
            };
            let chosen: Vec<usize> = match class {
                Some(c) => candidates.iter().copied().filter(|k| parsed[*k].short_class == Some(c)).collect(),
                None => {
                    let owned: Vec<usize> = candidates
                        .iter()
                        .copied()
                        .filter(|k| parsed[*k].class.is_some_and(|c| item_classes.is_some_and(|set| set.contains(c))))
                        .collect();
                    if !owned.is_empty() {
                        owned
                    } else if candidates.len() == 1 && (parsed[candidates[0]].class.is_none() || item.owner.is_none()) {
                        candidates.clone()
                    } else {
                        Vec::new()
                    }
                }
            };
            for k in chosen {
                add(&mut links, parsed[k].full, index, "doc reference");
            }
        }
    }
    for (index, item) in items.iter().enumerate().filter(|(_, i)| i.kind == "method") {
        let Some(set) = item.owner.as_deref().and_then(|o| owners.get(o)) else {
            continue;
        };
        for s in &parsed {
            if s.class.is_some_and(|c| set.contains(c))
                && same_name(s.member, &item.name)
                && !links.get(s.full).is_some_and(|l| !l.is_empty())
            {
                add(&mut links, s.full, index, "same name");
            }
        }
    }
    // A method PHP declares on each class and Rust once, on a trait the type
    // implements (`isArray()` and the `Validator` trait's `is_array`).
    let mut traits_of: BTreeMap<&str, BTreeSet<&str>> = BTreeMap::new();
    for item in items {
        if let (Some(owner), Some(t)) = (&item.owner, &item.implements) {
            traits_of.entry(owner.as_str()).or_default().insert(t.as_str());
        }
    }
    for s in &parsed {
        let Some(class) = s.class else {
            continue;
        };
        let types = owners.iter().filter(|(_, set)| set.contains(class)).map(|(t, _)| *t);
        let traits: BTreeSet<&str> = types.filter_map(|t| traits_of.get(t)).flatten().copied().collect();
        let found: Vec<usize> = items
            .iter()
            .enumerate()
            .filter(|(_, i)| {
                i.kind == "method"
                    && i.implements.is_none()
                    && i.owner.as_deref().is_some_and(|o| traits.contains(o))
                    && same_name(s.member, &i.name)
            })
            .map(|(k, _)| k)
            .collect();
        for index in found {
            add(&mut links, s.full, index, "trait method");
        }
    }
    links
}

fn add(links: &mut BTreeMap<String, Vec<(usize, &'static str)>>, symbol: &str, item: usize, via: &'static str) {
    let entry = links.entry(symbol.to_owned()).or_default();
    if !entry.iter().any(|(i, _)| *i == item) {
        entry.push((item, via));
    }
}

/// A PHP method and a Rust method with the same name in each language's
/// conventions: `isValid` / `is_valid`, `getValidator` / `validator`, `__construct` / `new`.
fn same_name(php: &str, rust: &str) -> bool {
    let snaked = snake(php);
    snaked == rust
        || php == "__construct" && rust == "new"
        || php.strip_prefix("get").is_some_and(|rest| !rest.is_empty() && snake(rest).trim_start_matches('_') == rust)
}

/// A module of a crate: its path (empty for the crate root), its `//!` docs
/// and whether it is declared `pub`.
pub struct Module {
    pub path: String,
    pub doc: String,
    pub file: String,
    pub public: bool,
}

/// Every module under `crate_dir/src`, files and inline `mod { }` blocks.
pub fn modules(root: &Path, crate_dir: &str) -> Vec<Module> {
    let src = root.join(crate_dir).join("src");
    let mut files = Vec::new();
    collect(&src, &mut files);
    files.sort();
    let tests = test_modules(&src, &files);
    let mut out = Vec::new();
    let mut public: BTreeMap<String, bool> = BTreeMap::new();
    for file in files {
        let Ok(text) = std::fs::read_to_string(&file) else {
            continue;
        };
        let Ok(parsed) = syn::parse_file(&text) else {
            continue;
        };
        let rel = file.strip_prefix(&src).unwrap_or(&file);
        let mut module: Vec<String> = rel.with_extension("").iter().map(|c| c.to_string_lossy().into_owned()).collect();
        if matches!(module.last().map(String::as_str), Some("lib" | "mod")) {
            module.pop();
        }
        if matches!(module.first().map(String::as_str), Some("bin" | "main")) || excluded(&module, &tests) {
            continue;
        }
        let file_name = file.strip_prefix(root).unwrap_or(&file).to_string_lossy().into_owned();
        out.push(Module { path: module.join("::"), doc: doc(&parsed.attrs), file: file_name.clone(), public: true });
        declared(&parsed.items, &module, &file_name, &mut public, &mut out);
    }
    for m in &mut out {
        if !m.path.is_empty() {
            m.public = public.get(&m.path).copied().unwrap_or(m.public);
        }
    }
    out
}

/// `mod` declarations: their visibility, and inline modules as modules.
fn declared(items: &[Item], module: &[String], file: &str, public: &mut BTreeMap<String, bool>, out: &mut Vec<Module>) {
    for item in items {
        if let Item::Mod(m) = item
            && !test_only(&m.attrs)
        {
            let mut path = module.to_vec();
            path.push(m.ident.to_string());
            let key = path.join("::");
            let is_pub = matches!(m.vis, Visibility::Public(_));
            public.insert(key.clone(), is_pub);
            if let Some((_, content)) = &m.content {
                if m.attrs.iter().any(|a| a.path().is_ident("cfg")) {
                    continue;
                }
                out.push(Module { path: key, doc: doc(&m.attrs), file: file.to_owned(), public: is_pub });
                declared(content, &path, file, public, out);
            }
        }
    }
}

/// The crate root's `pub use` re-exports, as (exported name, source path):
/// `pub use key::{KeyPair, Rsa}` gives (`KeyPair`, `key::KeyPair`), ...
pub fn reexports(root: &Path, crate_dir: &str) -> Vec<(String, String)> {
    let Ok(text) = std::fs::read_to_string(root.join(crate_dir).join("src/lib.rs")) else {
        return Vec::new();
    };
    let Ok(parsed) = syn::parse_file(&text) else {
        return Vec::new();
    };
    let mut out = Vec::new();
    for item in &parsed.items {
        if let Item::Use(u) = item
            && matches!(u.vis, Visibility::Public(_))
        {
            tree(&u.tree, &mut Vec::new(), &mut out);
        }
    }
    out
}

fn tree(t: &syn::UseTree, prefix: &mut Vec<String>, out: &mut Vec<(String, String)>) {
    let joined = |prefix: &[String], last: &str| {
        let mut p = prefix.to_vec();
        p.push(last.to_owned());
        p.join("::")
    };
    match t {
        syn::UseTree::Path(p) => {
            prefix.push(p.ident.to_string());
            tree(&p.tree, prefix, out);
            prefix.pop();
        }
        syn::UseTree::Name(n) => out.push((n.ident.to_string(), joined(prefix, &n.ident.to_string()))),
        syn::UseTree::Rename(r) => out.push((r.rename.to_string(), joined(prefix, &r.ident.to_string()))),
        syn::UseTree::Glob(_) => out.push(("*".to_owned(), prefix.join("::"))),
        syn::UseTree::Group(g) => {
            for t in &g.items {
                tree(t, prefix, out);
            }
        }
    }
}

fn test_only(attrs: &[Attribute]) -> bool {
    use quote::ToTokens;
    attrs.iter().any(|a| a.path().is_ident("cfg") && a.meta.to_token_stream().to_string().contains("test"))
}

/// Modules declared `#[cfg(test)]` anywhere in the crate (their files hold tests, not API).
fn test_modules(src: &Path, files: &[PathBuf]) -> BTreeSet<String> {
    let mut out = BTreeSet::new();
    for file in files {
        let Ok(parsed) =
            std::fs::read_to_string(file).map_err(|_| ()).and_then(|t| syn::parse_file(&t).map_err(|_| ()))
        else {
            continue;
        };
        let rel = file.strip_prefix(src).unwrap_or(file);
        let mut module: Vec<String> = rel.with_extension("").iter().map(|c| c.to_string_lossy().into_owned()).collect();
        if matches!(module.last().map(String::as_str), Some("lib" | "mod")) {
            module.pop();
        }
        for item in &parsed.items {
            if let Item::Mod(m) = item
                && test_only(&m.attrs)
            {
                let mut path = module.clone();
                path.push(m.ident.to_string());
                out.insert(path.join("::"));
            }
        }
    }
    out
}

fn excluded(module: &[String], tests: &BTreeSet<String>) -> bool {
    (1..=module.len()).any(|n| tests.contains(&module[..n].join("::")))
}
