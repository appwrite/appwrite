//! Which crates use each `php_std` function, read from the source: a call
//! graph over php-std's own functions (private helpers included), and the
//! calls each Utopia and Appwrite crate makes into it.
//!
//! A path resolves through `use` imports (renames, groups, globs, `self`,
//! `super`), re-exports by any scanned crate (`pub use php_std::value as
//! php;` used as `utopia_validators::php::truthy`, `pub type Value =
//! php_std::Value<Ext>;`), and php-std's own (`php_std::Value`). Functions
//! count when called or passed as values (`.map(pv::empty)`), including
//! inside macros (`format!`, `matches!`); a name bound in scope is a local,
//! not a function.
//!
//! There is no type checker, so a method call `.m(` resolves by what its
//! receiver is known to be: `self` (the method's own type); a binding
//! written with its type (`v: &Value`) or made from a call (`let Some(u) =
//! Uri::parse(s) else ...`), that type's methods only; anything else, the
//! inherent methods named `m` on the php-std types in scope: imported,
//! written, owning a function it calls, or returned by one. That last case
//! over-counts a little (`.remove(` on a `Vec` in a file that imports
//! `Array`); trait methods (`impl Display`) are never reached by name.
//!
//! Test code (`#[cfg(test)]`, `tests/`) is not a use, and the compat suite
//! (`crates/tools`) is not a user.

use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};

use syn::punctuated::Punctuated;
use syn::visit::{self, Visit};

use super::super::docs::{excluded, test_modules, test_only};

const CRATE: &str = "php_std";

/// What a binding is known to hold, from where it was last bound.
#[derive(Debug, Clone)]
enum Binding {
    /// Written with its type: `v: &Value`.
    Typed(Vec<String>),
    /// What a function returns: `let u = Uri::parse(s)?;`, `let Some(u) = parse_url(s) else ...`.
    Returned(Vec<String>),
    /// Anything else.
    Unknown,
}

/// A method call's receiver.
#[derive(Debug, Clone)]
enum Receiver {
    This,
    Bound(Binding),
    Other,
}

/// What a piece of code refers to.
#[derive(Debug, Default)]
struct Refs {
    /// Paths written as expressions that may name functions: calls and
    /// functions passed as values (a name bound in scope is a local).
    paths: Vec<Vec<String>>,
    /// Method calls, with what their receiver is known to be.
    methods: Vec<(String, Receiver)>,
    /// Capitalised path segments written anywhere (types, mostly).
    idents: BTreeSet<String>,
    /// Paths written as types.
    types: Vec<Vec<String>>,
    /// The names bound in scope at this point of the walk, and what they hold.
    bound: BTreeMap<String, Binding>,
    /// What `use` items inside the code import (paths as written).
    uses: Vec<Alias>,
}

impl Refs {
    fn of_fn(sig: &syn::Signature, block: &syn::Block) -> Refs {
        let mut refs = Refs::default();
        sig.inputs.iter().for_each(|i| refs.visit_fn_arg(i));
        // The body in the parameters' scope.
        block.stmts.iter().for_each(|st| refs.visit_stmt(st));
        refs
    }

    /// Walks `f` in a scope of its own: bindings made inside end with it.
    fn scoped(&mut self, f: impl FnOnce(&mut Refs)) {
        let outer = self.bound.clone();
        f(self);
        self.bound = outer;
    }
}

/// The function a `let` initialiser calls, through `?` and `.ok()`-style
/// adapters: `Uri::parse(s)?` → `Uri::parse`.
fn called(expr: &syn::Expr) -> Option<Vec<String>> {
    match expr {
        syn::Expr::Try(t) => called(&t.expr),
        syn::Expr::Paren(p) => called(&p.expr),
        syn::Expr::MethodCall(m)
            if matches!(
                m.method.to_string().as_str(),
                "ok" | "unwrap" | "expect" | "unwrap_or_default" | "ok_or" | "ok_or_else" | "map_err"
            ) =>
        {
            called(&m.receiver)
        }
        syn::Expr::Call(c) => match &*c.func {
            syn::Expr::Path(p) => Some(p.path.segments.iter().map(|s| s.ident.to_string()).collect()),
            _ => None,
        },
        _ => None,
    }
}

/// The name a pattern binds the whole (unwrapped) value to: `u`, `Some(u)`, `Ok(mut u)`.
fn bound_name(pat: &syn::Pat) -> Option<String> {
    match pat {
        syn::Pat::Ident(i) => Some(i.ident.to_string()),
        syn::Pat::TupleStruct(t) if t.elems.len() == 1 => bound_name(&t.elems[0]),
        _ => None,
    }
}

impl<'ast> Visit<'ast> for Refs {
    fn visit_expr_path(&mut self, e: &'ast syn::ExprPath) {
        let local = e.path.get_ident().is_some_and(|i| self.bound.contains_key(&i.to_string()));
        if !local {
            self.paths.push(e.path.segments.iter().map(|s| s.ident.to_string()).collect());
        }
        visit::visit_expr_path(self, e);
    }

    fn visit_block(&mut self, b: &'ast syn::Block) {
        self.scoped(|r| visit::visit_block(r, b));
    }

    fn visit_expr_closure(&mut self, c: &'ast syn::ExprClosure) {
        self.scoped(|r| visit::visit_expr_closure(r, c));
    }

    fn visit_arm(&mut self, a: &'ast syn::Arm) {
        self.scoped(|r| visit::visit_arm(r, a));
    }

    fn visit_impl_item_fn(&mut self, f: &'ast syn::ImplItemFn) {
        self.scoped(|r| visit::visit_impl_item_fn(r, f));
    }

    fn visit_path(&mut self, p: &'ast syn::Path) {
        for s in &p.segments {
            let name = s.ident.to_string();
            if name.starts_with(|c: char| c.is_ascii_uppercase()) {
                self.idents.insert(name);
            }
        }
        visit::visit_path(self, p);
    }

    fn visit_pat_type(&mut self, p: &'ast syn::PatType) {
        visit::visit_pat_type(self, p);
        if let syn::Pat::Ident(i) = &*p.pat
            && let Some(path) = type_path(&p.ty)
        {
            self.bound.insert(i.ident.to_string(), Binding::Typed(path));
        }
    }

    fn visit_pat_ident(&mut self, p: &'ast syn::PatIdent) {
        self.bound.insert(p.ident.to_string(), Binding::Unknown);
        visit::visit_pat_ident(self, p);
    }

    fn visit_local(&mut self, l: &'ast syn::Local) {
        // The initialiser first: it sees the bindings before this one.
        if let Some(init) = &l.init {
            self.visit_expr(&init.expr);
            if let Some((_, diverge)) = &init.diverge {
                self.visit_expr(diverge);
            }
        }
        self.visit_pat(&l.pat);
        if let Some(init) = &l.init
            && let Some(name) = bound_name(&l.pat)
            && let Some(path) = called(&init.expr)
        {
            self.bound.insert(name, Binding::Returned(path));
        }
    }

    fn visit_type_path(&mut self, t: &'ast syn::TypePath) {
        self.types.push(t.path.segments.iter().map(|s| s.ident.to_string()).collect());
        visit::visit_type_path(self, t);
    }

    fn visit_expr_method_call(&mut self, e: &'ast syn::ExprMethodCall) {
        let receiver = match &*e.receiver {
            syn::Expr::Path(p) if p.path.is_ident("self") => Receiver::This,
            syn::Expr::Path(p) => match p.path.get_ident().and_then(|i| self.bound.get(&i.to_string())) {
                Some(b) => Receiver::Bound(b.clone()),
                None => Receiver::Other,
            },
            _ => Receiver::Other,
        };
        self.methods.push((e.method.to_string(), receiver));
        visit::visit_expr_method_call(self, e);
    }

    fn visit_macro(&mut self, m: &'ast syn::Macro) {
        // `format!(...)`, `vec![...]`, `assert!(...)`: comma-separated expressions.
        if let Ok(args) = m.parse_body_with(Punctuated::<syn::Expr, syn::Token![,]>::parse_terminated) {
            for a in &args {
                self.visit_expr(a);
            }
        } else if let Ok(first) = m.parse_body_with(|input: syn::parse::ParseStream| {
            // `matches!(expr, pattern)`: the expression, then whatever follows.
            let e: syn::Expr = input.parse()?;
            input.parse::<proc_macro2::TokenStream>()?;
            Ok(e)
        }) {
            self.visit_expr(&first);
        }
        visit::visit_macro(self, m);
    }

    fn visit_item_use(&mut self, u: &'ast syn::ItemUse) {
        written_aliases(u, &mut self.uses);
    }

    // Test code is not a use.
    fn visit_item_mod(&mut self, m: &'ast syn::ItemMod) {
        if !test_only(&m.attrs) {
            visit::visit_item_mod(self, m);
        }
    }

    fn visit_item_fn(&mut self, f: &'ast syn::ItemFn) {
        if !test_only(&f.attrs) && !f.attrs.iter().any(|a| a.path().is_ident("test")) {
            self.scoped(|r| visit::visit_item_fn(r, f));
        }
    }

    fn visit_item_impl(&mut self, i: &'ast syn::ItemImpl) {
        if !test_only(&i.attrs) {
            visit::visit_item_impl(self, i);
        }
    }
}

/// A name a `use` brings into scope.
#[derive(Debug, Clone, PartialEq, Eq)]
struct Alias {
    /// The name in scope (`*` for a glob).
    name: String,
    /// What it names: `crate::...` (the scanned crate), `php_std::...`, or external.
    path: Vec<String>,
    public: bool,
}

/// The aliases of a `use`, paths as written.
fn written_aliases(item: &syn::ItemUse, out: &mut Vec<Alias>) {
    fn walk(tree: &syn::UseTree, prefix: &[String], public: bool, out: &mut Vec<Alias>) {
        let push = |out: &mut Vec<Alias>, name: String, path: Vec<String>| out.push(Alias { name, path, public });
        match tree {
            syn::UseTree::Path(p) => {
                let mut next = prefix.to_vec();
                next.push(p.ident.to_string());
                walk(&p.tree, &next, public, out);
            }
            syn::UseTree::Name(n) if n.ident == "self" => {
                if let Some(last) = prefix.last() {
                    push(out, last.clone(), prefix.to_vec());
                }
            }
            syn::UseTree::Name(n) => {
                let mut path = prefix.to_vec();
                path.push(n.ident.to_string());
                push(out, n.ident.to_string(), path);
            }
            syn::UseTree::Rename(r) => {
                let mut path = prefix.to_vec();
                path.push(r.ident.to_string());
                push(out, r.rename.to_string(), path);
            }
            syn::UseTree::Group(g) => g.items.iter().for_each(|t| walk(t, prefix, public, out)),
            syn::UseTree::Glob(_) => push(out, "*".to_owned(), prefix.to_vec()),
        }
    }
    walk(&item.tree, &[], !matches!(item.vis, syn::Visibility::Inherited), out);
}

/// The aliases of a `use` in `module`, with `self::`/`super::` made
/// absolute (`crate::...`).
fn aliases(item: &syn::ItemUse, module: &[String], out: &mut Vec<Alias>) {
    let mut found = Vec::new();
    written_aliases(item, &mut found);
    out.extend(found.into_iter().map(|a| in_module(a, module)));
}

fn in_module(a: Alias, module: &[String]) -> Alias {
    Alias { path: absolute_use(&a.path, module), ..a }
}

/// A `use` path made absolute: `self::a` and `super::a` → `crate::...`.
fn absolute_use(path: &[String], module: &[String]) -> Vec<String> {
    match path.first().map(String::as_str) {
        Some("self") => {
            ["crate".to_owned()].into_iter().chain(module.iter().cloned()).chain(path[1..].iter().cloned()).collect()
        }
        Some("super") => {
            let mut m = module.to_vec();
            let mut rest = path;
            while rest.first().is_some_and(|s| s == "super") {
                m.pop();
                rest = &rest[1..];
            }
            ["crate".to_owned()].into_iter().chain(m).chain(rest.iter().cloned()).collect()
        }
        _ => path.to_vec(),
    }
}

/// A php-std function or method.
#[derive(Debug)]
struct Func {
    module: Vec<String>,
    owner: Option<String>,
    name: String,
    /// Implements a trait (`impl Display for Value`): called through the
    /// trait, so a method call by name does not reach it.
    of_trait: bool,
    refs: Refs,
    /// Types its signature names.
    signature: BTreeSet<String>,
    /// Types its return type names.
    returns: BTreeSet<String>,
    /// The `use` aliases in scope (its file's, its own).
    aliases: Vec<Alias>,
}

impl Func {
    fn path(&self) -> String {
        let mut p = self.module.clone();
        p.extend(self.owner.clone());
        p.push(self.name.clone());
        p.join("::")
    }
}

/// Capitalised identifiers in a type or signature.
fn type_idents<T: quote::ToTokens>(node: &T) -> BTreeSet<String> {
    let mut out = BTreeSet::new();
    for tt in node.to_token_stream() {
        collect_idents(tt, &mut out);
    }
    out
}

fn collect_idents(tt: proc_macro2::TokenTree, out: &mut BTreeSet<String>) {
    match tt {
        proc_macro2::TokenTree::Ident(i) => {
            let s = i.to_string();
            if s.starts_with(|c: char| c.is_ascii_uppercase()) {
                out.insert(s);
            }
        }
        proc_macro2::TokenTree::Group(g) => g.stream().into_iter().for_each(|t| collect_idents(t, out)),
        _ => {}
    }
}

/// A type's path, through references (`&mut php_std::Value` → `php_std::Value`).
fn type_path(ty: &syn::Type) -> Option<Vec<String>> {
    match ty {
        syn::Type::Path(p) => Some(p.path.segments.iter().map(|s| s.ident.to_string()).collect()),
        syn::Type::Reference(r) => type_path(&r.elem),
        _ => None,
    }
}

fn type_name(ty: &syn::Type) -> Option<String> {
    match ty {
        syn::Type::Path(p) => p.path.segments.last().map(|s| s.ident.to_string()),
        syn::Type::Reference(r) => type_name(&r.elem),
        _ => None,
    }
}

/// A source file's module path under `src` (`a/b.rs` → `a::b`, `a/mod.rs` → `a`).
fn module_of(src: &Path, file: &Path) -> Vec<String> {
    let rel = file.strip_prefix(src).unwrap_or(file);
    let mut module: Vec<String> = rel.with_extension("").iter().map(|c| c.to_string_lossy().into_owned()).collect();
    if matches!(module.last().map(String::as_str), Some("lib" | "mod" | "main")) {
        module.pop();
    }
    module
}

/// The non-test source files of a crate, with their module paths.
fn sources(src: &Path) -> Vec<(PathBuf, Vec<String>, syn::File)> {
    let mut files = Vec::new();
    rs_files(src, &mut files);
    files.sort();
    let tests = test_modules(src, &files);
    files
        .into_iter()
        .filter_map(|f| {
            let module = module_of(src, &f);
            if excluded(&module, &tests) {
                return None;
            }
            let parsed = syn::parse_file(&std::fs::read_to_string(&f).ok()?).ok()?;
            Some((f, module, parsed))
        })
        .collect()
}

fn rs_files(dir: &Path, out: &mut Vec<PathBuf>) {
    for entry in std::fs::read_dir(dir).into_iter().flatten().flatten() {
        let path = entry.path();
        if path.is_dir() {
            rs_files(&path, out);
        } else if path.extension().is_some_and(|e| e == "rs") {
            out.push(path);
        }
    }
}

/// The `use` aliases of a list of items (inline modules included).
fn item_aliases(items: &[syn::Item], module: &[String], out: &mut Vec<Alias>) {
    for item in items {
        match item {
            syn::Item::Use(u) => aliases(u, module, out),
            syn::Item::Mod(m) if !test_only(&m.attrs) => {
                if let Some((_, inner)) = &m.content {
                    let mut next = module.to_vec();
                    next.push(m.ident.to_string());
                    item_aliases(inner, &next, out);
                }
            }
            _ => {}
        }
    }
}

/// php-std's functions, and what each crate calls of them.
#[derive(Debug, Default)]
pub struct Usage {
    funcs: Vec<Func>,
    by_name: BTreeMap<String, Vec<usize>>,
    by_path: BTreeMap<String, Vec<usize>>,
    /// php-std's top-level modules.
    top: BTreeSet<String>,
    /// php-std's own re-exports (`pub use types::Value;` in the crate root):
    /// `module::name` → the path it names (`*` for a glob).
    reexports: BTreeMap<String, Vec<String>>,
    /// php-std's types (structs, enums, aliases, traits): name → modules.
    types: BTreeMap<String, Vec<Vec<String>>>,
    /// For each function, the php-std functions calling it.
    callers: Vec<BTreeSet<usize>>,
    /// For each function, the crates calling it directly.
    crates: Vec<BTreeSet<String>>,
}

impl Usage {
    /// Reads php-std at `crates/support/php-std` and its users under
    /// `crates/utopia` and `crates/appwrite` (as `appwrite-<name>`).
    pub fn scan(root: &Path) -> Usage {
        let mut usage = Usage::index(&root.join("crates/support/php-std/src"));
        let mut crates: Vec<Krate> = Vec::new();
        for (group, prefix) in [("crates/utopia", ""), ("crates/appwrite", "appwrite-")] {
            for dir in subdirs(&root.join(group)) {
                let name = dir.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
                crates.push(Krate::read(format!("{prefix}{name}"), &dir));
            }
        }
        let exports = Exports::of(&crates);
        for krate in &crates {
            for f in usage.calls_of_crate(krate, &exports) {
                usage.crates[f].insert(krate.name.clone());
            }
        }
        usage
    }

    /// The function index and call graph of php-std's source in `src`.
    fn index(src: &Path) -> Usage {
        let mut usage = Usage::default();
        let mut public: Vec<(Vec<String>, Alias)> = Vec::new();
        let files = sources(src);
        let modules: BTreeSet<Vec<String>> = files.iter().map(|(_, m, _)| m.clone()).collect();
        for (_, module, file) in files {
            if let Some(top) = module.first() {
                usage.top.insert(top.clone());
            }
            let mut file_aliases = Vec::new();
            item_aliases(&file.items, &module, &mut file_aliases);
            public.extend(file_aliases.iter().filter(|a| a.public).map(|a| (module.clone(), a.clone())));
            usage.walk(&file.items, &module, &file_aliases);
        }
        // `pub use` paths: `crate::...`, or relative to the module (`pub use types::Value;`).
        for (module, a) in public {
            let target = match a.path.first().map(String::as_str) {
                Some("crate") => a.path[1..].to_vec(),
                // A child module first (`pub use value::Value;` in `types`), as Rust resolves it.
                Some(first)
                    if modules.contains(&module.iter().chain([&first.to_owned()]).cloned().collect::<Vec<_>>()) =>
                {
                    module.iter().chain(&a.path).cloned().collect()
                }
                _ => a.path.clone(),
            };
            let key = module.iter().chain([&a.name]).cloned().collect::<Vec<_>>().join("::");
            usage.reexports.insert(key, target);
        }
        for (i, f) in usage.funcs.iter().enumerate() {
            usage.by_name.entry(f.name.clone()).or_default().push(i);
            usage.by_path.entry(f.path()).or_default().push(i);
        }
        usage.callers = vec![BTreeSet::new(); usage.funcs.len()];
        usage.crates = vec![BTreeSet::new(); usage.funcs.len()];
        for i in 0..usage.funcs.len() {
            for callee in usage.calls_of_func(i) {
                if callee != i {
                    usage.callers[callee].insert(i);
                }
            }
        }
        usage
    }

    fn walk(&mut self, items: &[syn::Item], module: &[String], file_aliases: &[Alias]) {
        let push =
            |this: &mut Usage, owner: Option<String>, of_trait: bool, sig: &syn::Signature, block: &syn::Block| {
                let refs = Refs::of_fn(sig, block);
                let mut aliases = file_aliases.to_vec();
                aliases.extend(refs.uses.iter().map(|a| in_module(a.clone(), module)));
                this.funcs.push(Func {
                    module: module.to_vec(),
                    owner,
                    name: sig.ident.to_string(),
                    of_trait,
                    signature: type_idents(sig),
                    returns: match &sig.output {
                        syn::ReturnType::Type(_, ty) => type_idents(&**ty),
                        syn::ReturnType::Default => BTreeSet::new(),
                    },
                    refs,
                    aliases,
                });
            };
        for item in items {
            match item {
                syn::Item::Fn(f) if !test_only(&f.attrs) => push(self, None, false, &f.sig, &f.block),
                syn::Item::Impl(i) if !test_only(&i.attrs) => {
                    let owner = type_name(&i.self_ty);
                    for ii in &i.items {
                        if let syn::ImplItem::Fn(f) = ii
                            && !test_only(&f.attrs)
                        {
                            push(self, owner.clone(), i.trait_.is_some(), &f.sig, &f.block);
                        }
                    }
                }
                syn::Item::Trait(t) => {
                    self.types.entry(t.ident.to_string()).or_default().push(module.to_vec());
                    for ti in &t.items {
                        if let syn::TraitItem::Fn(f) = ti
                            && let Some(block) = &f.default
                        {
                            push(self, Some(t.ident.to_string()), false, &f.sig, block);
                        }
                    }
                }
                syn::Item::Struct(syn::ItemStruct { ident, .. })
                | syn::Item::Enum(syn::ItemEnum { ident, .. })
                | syn::Item::Type(syn::ItemType { ident, .. }) => {
                    self.types.entry(ident.to_string()).or_default().push(module.to_vec());
                }
                syn::Item::Mod(m) if !test_only(&m.attrs) => {
                    if let Some((_, inner)) = &m.content {
                        let mut next = module.to_vec();
                        next.push(m.ident.to_string());
                        self.walk(inner, &next, file_aliases);
                    }
                }
                _ => {}
            }
        }
    }

    /// The functions an absolute php-std path names: exactly, or through a
    /// re-export (`Value::from_json` → `types::value::Value::from_json`,
    /// `format::add` → `format::arith::add`).
    fn lookup(&self, path: &[String]) -> Vec<usize> {
        let path = &self.canonical(path);
        let Some(name) = path.last() else { return Vec::new() };
        if let Some(found) = self.by_path.get(&path.join("::")) {
            return found.clone();
        }
        let before = &path[..path.len() - 1];
        let (owner, module) = match before.last() {
            Some(o) if o.starts_with(|c: char| c.is_ascii_uppercase()) => {
                (Some(o.as_str()), &before[..before.len() - 1])
            }
            _ => (None, before),
        };
        if module.first().is_some_and(|m| !self.top.contains(m)) {
            return Vec::new();
        }
        let candidates: Vec<usize> = self
            .by_name
            .get(name)
            .into_iter()
            .flatten()
            .copied()
            .filter(|&i| {
                let f = &self.funcs[i];
                f.owner.as_deref() == owner && f.module.starts_with(module)
            })
            .collect();
        // The nearest (`format::max` before `format::arith::max`).
        let best = candidates.iter().map(|&i| self.funcs[i].module.len()).min();
        candidates.into_iter().filter(|&i| Some(self.funcs[i].module.len()) == best).collect()
    }

    /// Paths a path stands for through `aliases`: the alias of its first
    /// segment, else each glob's module joined to it.
    fn expand(&self, segs: &[String], aliases: &[Alias]) -> Vec<Vec<String>> {
        let head = &segs[0];
        let named: Vec<Vec<String>> = aliases
            .iter()
            .filter(|a| &a.name == head)
            .map(|a| a.path.iter().chain(segs[1..].iter()).cloned().collect())
            .collect();
        if !named.is_empty() {
            return named;
        }
        aliases.iter().filter(|a| a.name == "*").map(|a| a.path.iter().chain(segs.iter()).cloned().collect()).collect()
    }

    /// The php-std functions a path written inside php-std function `f` names.
    fn resolve_internal(&self, segs: &[String], f: &Func) -> Vec<usize> {
        let mut segs = segs.to_vec();
        if segs.first().is_some_and(|s| s == "Self") {
            let Some(owner) = &f.owner else { return Vec::new() };
            segs[0] = owner.clone();
        }
        let mut tries: Vec<Vec<String>> = Vec::new();
        match segs[0].as_str() {
            "crate" | "self" | "super" => tries.push(absolute_use(&segs, &f.module)),
            _ => {
                tries.extend(self.expand(&segs, &f.aliases));
                tries.push(
                    ["crate".to_owned()]
                        .into_iter()
                        .chain(f.module.iter().cloned())
                        .chain(segs.iter().cloned())
                        .collect(),
                );
                if self.top.contains(&segs[0]) {
                    tries.push(["crate".to_owned()].into_iter().chain(segs.iter().cloned()).collect());
                }
            }
        }
        for t in tries {
            if t.first().is_some_and(|s| s == "crate") {
                let found = self.lookup(&t[1..]);
                if !found.is_empty() {
                    return found;
                }
            }
        }
        Vec::new()
    }

    /// A method's type, as a full path (`json::Error`).
    fn owner_of(&self, i: usize) -> Option<String> {
        let f = &self.funcs[i];
        let owner = f.owner.as_ref()?;
        Some(f.module.iter().chain([owner]).cloned().collect::<Vec<_>>().join("::"))
    }

    /// A php-std path through php-std's own re-exports to where the item
    /// is declared (`Value::from_json` → `types::value::Value::from_json`).
    fn canonical(&self, path: &[String]) -> Vec<String> {
        let mut path = path.to_vec();
        'next: for _ in 0..8 {
            for k in (1..=path.len()).rev() {
                let target = self.reexports.get(&path[..k].join("::")).or_else(|| {
                    // A glob: `pub use arith::*;` in `format` makes `format::add` `format::arith::add`.
                    let glob = path[..k - 1].iter().chain([&"*".to_owned()]).cloned().collect::<Vec<_>>().join("::");
                    let base = self.reexports.get(&glob)?;
                    let candidate: Vec<String> = base.iter().chain([&path[k - 1]]).cloned().collect();
                    let declared = self.by_path.contains_key(&candidate.join("::"))
                        || self.types.get(&path[k - 1]).is_some_and(|ms| ms.iter().any(|m| m == base));
                    declared.then_some(base).map(|_| base)
                });
                if let Some(target) = target {
                    let glob = !self.reexports.contains_key(&path[..k].join("::"));
                    let mut next = target.clone();
                    if glob {
                        next.push(path[k - 1].clone());
                    }
                    next.extend(path[k..].iter().cloned());
                    if next != path {
                        path = next;
                        continue 'next;
                    }
                }
            }
            break;
        }
        path
    }

    /// The php-std types a `php_std::...` path (without `php_std`) names,
    /// as full paths: the declared one, else the nearest so named.
    fn types_at(&self, path: &[String]) -> Vec<String> {
        let path = &self.canonical(path);
        let Some((name, prefix)) = path.split_last() else { return Vec::new() };
        if self.types.get(name).is_some_and(|ms| ms.iter().any(|m| m.as_slice() == prefix)) {
            return vec![path.join("::")];
        }
        let found: Vec<&Vec<String>> =
            self.types.get(name).into_iter().flatten().filter(|m| m.starts_with(prefix)).collect();
        let best = found.iter().map(|m| m.len()).min();
        found
            .into_iter()
            .filter(|m| Some(m.len()) == best)
            .map(|m| m.iter().chain([name]).cloned().collect::<Vec<_>>().join("::"))
            .collect()
    }

    /// The php-std types `name` stands for inside php-std module `module`:
    /// what a `use` imports under it, the module's own, else the only one so named.
    fn type_in(&self, name: &str, module: &[String], aliases: &[Alias]) -> Vec<String> {
        if let Some(a) = aliases.iter().find(|a| a.name == name && a.path.first().is_some_and(|s| s == "crate")) {
            return self.types_at(&a.path[1..]);
        }
        let Some(modules) = self.types.get(name) else { return Vec::new() };
        let full = |m: &Vec<String>| m.iter().chain([&name.to_owned()]).cloned().collect::<Vec<_>>().join("::");
        if let Some(m) = modules.iter().find(|m| m.as_slice() == module) {
            return vec![full(m)];
        }
        if modules.len() == 1 {
            return vec![full(&modules[0])];
        }
        Vec::new()
    }

    /// Inherent methods named `name` on the types in `scope`.
    fn methods(&self, name: &str, scope: &BTreeSet<String>) -> Vec<usize> {
        self.by_name
            .get(name)
            .into_iter()
            .flatten()
            .copied()
            .filter(|&i| !self.funcs[i].of_trait && self.owner_of(i).is_some_and(|o| scope.contains(&o)))
            .collect()
    }

    fn calls_of_func(&self, i: usize) -> BTreeSet<usize> {
        let f = &self.funcs[i];
        let mut out: BTreeSet<usize> = BTreeSet::new();
        for p in f.refs.paths.iter() {
            out.extend(self.resolve_internal(p, f));
        }
        let mut scope: BTreeSet<String> =
            f.refs.idents.union(&f.signature).flat_map(|n| self.type_in(n, &f.module, &f.aliases)).collect();
        scope.extend(self.owner_of(i));
        // The types declared beside it (`code.message()` on a field of its own module's type).
        for (name, modules) in &self.types {
            if modules.contains(&f.module) {
                scope.insert(f.module.iter().chain([name]).cloned().collect::<Vec<_>>().join("::"));
            }
        }
        let holds = |b: &Binding| -> Option<BTreeSet<String>> {
            let foreign = |p: &[String]| {
                p.len() > 1
                    && !matches!(p[0].as_str(), "crate" | "self" | "super" | "Self")
                    && !self.top.contains(&p[0])
                    && !f.aliases.iter().any(|a| a.name == p[0])
            };
            match b {
                Binding::Typed(t) if foreign(t) => Some(BTreeSet::new()),
                Binding::Typed(t) => Some(
                    t.last().map(|l| self.type_in(l, &f.module, &f.aliases)).unwrap_or_default().into_iter().collect(),
                ),
                Binding::Returned(c) => {
                    let callees = self.resolve_internal(c, f);
                    if callees.is_empty() {
                        return foreign(c).then(BTreeSet::new);
                    }
                    Some(callees.iter().flat_map(|&c| self.returned(c)).collect())
                }
                Binding::Unknown => None,
            }
        };
        self.method_calls(&f.refs.methods, self.owner_of(i).as_deref(), &holds, &mut scope, &mut out);
        out
    }

    /// The php-std types function `c` returns.
    fn returned(&self, c: usize) -> Vec<String> {
        let f = &self.funcs[c];
        let mut out: Vec<String> = f.returns.iter().flat_map(|n| self.type_in(n, &f.module, &f.aliases)).collect();
        if f.returns.contains("Self") {
            out.extend(self.owner_of(c));
        }
        out
    }

    /// Resolves method calls: on `self`, the owner's methods; on a binding
    /// whose type is known (`holds`: written, or returned by the function
    /// that made it), that type's (none outside php-std); otherwise the
    /// methods of the types in `scope`, widened by what the resolved calls
    /// return (two rounds: `a.b().c()`).
    fn method_calls(
        &self,
        methods: &[(String, Receiver)],
        owner: Option<&str>,
        holds: &dyn Fn(&Binding) -> Option<BTreeSet<String>>,
        scope: &mut BTreeSet<String>,
        out: &mut BTreeSet<usize>,
    ) {
        for _ in 0..2 {
            for &c in out.iter() {
                scope.extend(self.returned(c));
            }
            for (m, receiver) in methods {
                match receiver {
                    Receiver::This if owner.is_some() => {
                        let own = self.methods(m, &owner.into_iter().map(str::to_owned).collect());
                        if own.is_empty() {
                            out.extend(self.methods(m, scope));
                        } else {
                            out.extend(own);
                        }
                    }
                    Receiver::Bound(b) => match holds(b) {
                        Some(types) => out.extend(self.methods(m, &types)),
                        None => out.extend(self.methods(m, scope)),
                    },
                    _ => out.extend(self.methods(m, scope)),
                }
            }
        }
    }

    /// The php-std functions a crate's code calls.
    fn calls_of_crate(&self, krate: &Krate, exports: &Exports) -> BTreeSet<usize> {
        let mut out = BTreeSet::new();
        // Each file's references, and what it imports from php-std (directly
        // or through a crate's re-export).
        let files: Vec<(Refs, Vec<Alias>)> = krate
            .files
            .iter()
            .map(|(module, file)| {
                let mut refs = Refs::default();
                refs.visit_file(file);
                let mut written = Vec::new();
                item_aliases(&file.items, module, &mut written);
                written.extend(refs.uses.iter().map(|a| in_module(a.clone(), module)));
                // The file's own aliases of php-std types (`type Value = php_std::Value<Object>;`).
                written.extend(type_aliases(&file.items));
                let imported = written
                    .into_iter()
                    .filter_map(|a| Some(Alias { path: exports.resolve(&krate.lib, &a.path)?, ..a }))
                    .collect();
                (refs, imported)
            })
            .collect();
        // The crate's own functions returning php-std types (`fn to_php(&self) -> php_std::Value`).
        let mut returning: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
        for ((_, file), (_, imported)) in krate.files.iter().zip(&files) {
            for sig in signatures(&file.items) {
                let mut out = Refs::default();
                out.visit_return_type(&sig.output);
                let types: BTreeSet<String> = out
                    .types
                    .iter()
                    .filter_map(|t| self.php_path(t, &krate.lib, imported, exports))
                    .flat_map(|p| self.types_at(&p[1..]))
                    .collect();
                if !types.is_empty() {
                    returning.entry(sig.ident.to_string()).or_default().extend(types);
                }
            }
        }
        for (refs, imported) in &files {
            let imported = imported.as_slice();
            let mut calls: BTreeSet<usize> = BTreeSet::new();
            for p in refs.paths.iter() {
                calls.extend(self.resolve_external(p, &krate.lib, imported, exports));
            }
            // Types in scope: imported or written from php-std, and the
            // owners of the functions it calls (`Uri::parse`).
            let mut scope: BTreeSet<String> = BTreeSet::new();
            let paths = imported.iter().map(|a| a.path.clone());
            let written = refs.types.iter().filter_map(|t| self.php_path(t, &krate.lib, imported, exports));
            for p in paths.chain(written) {
                if p.last().is_some_and(|l| l.starts_with(|c: char| c.is_ascii_uppercase())) {
                    scope.extend(self.types_at(&p[1..]));
                }
            }
            scope.extend(calls.iter().filter_map(|&c| self.owner_of(c)));
            for name in refs.methods.iter().map(|(m, _)| m).chain(refs.paths.iter().filter_map(|p| p.last())) {
                scope.extend(returning.get(name).into_iter().flatten().cloned());
            }
            let holds = |b: &Binding| -> Option<BTreeSet<String>> {
                match b {
                    Binding::Typed(t) => Some(
                        self.php_path(t, &krate.lib, imported, exports)
                            .map(|p| self.types_at(&p[1..]).into_iter().collect())
                            .unwrap_or_default(),
                    ),
                    Binding::Returned(c) => {
                        let callees = self.resolve_external(c, &krate.lib, imported, exports);
                        if callees.is_empty() {
                            // The crate's own function returning a php-std type, else unknown.
                            return c.last().and_then(|l| returning.get(l)).cloned();
                        }
                        Some(callees.iter().flat_map(|&c| self.returned(c)).collect())
                    }
                    Binding::Unknown => None,
                }
            };
            self.method_calls(&refs.methods, None, &holds, &mut scope, &mut calls);
            out.extend(calls);
        }
        out
    }

    /// The `php_std::...` path a path in a user crate stands for.
    fn php_path(&self, segs: &[String], lib: &str, imported: &[Alias], exports: &Exports) -> Option<Vec<String>> {
        if let Some(p) = exports.resolve(lib, segs) {
            return Some(p);
        }
        self.expand(segs, imported).into_iter().next()
    }

    /// The php-std functions a path in a user crate names.
    fn resolve_external(&self, segs: &[String], lib: &str, imported: &[Alias], exports: &Exports) -> Vec<usize> {
        let mut tries: Vec<Vec<String>> = exports.resolve(lib, segs).into_iter().collect();
        if tries.is_empty() {
            tries = self.expand(segs, imported);
        }
        for t in tries {
            let found = self.lookup(&t[1..]);
            if !found.is_empty() {
                return found;
            }
        }
        Vec::new()
    }

    /// The crates whose code reaches the php-std item at `path` (as the
    /// inventory writes it: `module::Owner::name`), each with the php-std
    /// items it reaches it through (empty: it calls the item itself).
    pub fn users(&self, path: &str) -> BTreeMap<String, BTreeSet<String>> {
        let start: Vec<usize> = self.by_path.get(path).cloned().unwrap_or_default();
        let mut out: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
        let mut seen: BTreeSet<usize> = BTreeSet::new();
        let mut queue: Vec<usize> = start.clone();
        while let Some(f) = queue.pop() {
            if !seen.insert(f) {
                continue;
            }
            for c in &self.crates[f] {
                let via = out.entry(c.clone()).or_default();
                if !start.contains(&f) {
                    via.insert(self.funcs[f].path());
                }
            }
            queue.extend(self.callers[f].iter().copied());
        }
        // A crate calling the item itself needs no "via".
        for f in &start {
            for c in &self.crates[*f] {
                out.insert(c.clone(), BTreeSet::new());
            }
        }
        out
    }
}

/// The signatures of the functions and methods among `items` (inline modules included).
fn signatures(items: &[syn::Item]) -> Vec<&syn::Signature> {
    let mut out = Vec::new();
    for item in items {
        match item {
            syn::Item::Fn(f) => out.push(&f.sig),
            syn::Item::Impl(i) => out.extend(i.items.iter().filter_map(|ii| match ii {
                syn::ImplItem::Fn(f) => Some(&f.sig),
                _ => None,
            })),
            syn::Item::Mod(m) if !test_only(&m.attrs) => {
                if let Some((_, inner)) = &m.content {
                    out.extend(signatures(inner));
                }
            }
            _ => {}
        }
    }
    out
}

/// A crate using php-std: its name, the name its code is imported by, and
/// its non-test source.
struct Krate {
    name: String,
    /// `utopia_database`.
    lib: String,
    files: Vec<(Vec<String>, syn::File)>,
}

impl Krate {
    fn read(name: String, dir: &Path) -> Krate {
        let manifest = std::fs::read_to_string(dir.join("Cargo.toml")).unwrap_or_default();
        let package = manifest
            .lines()
            .find_map(|l| l.trim().strip_prefix("name = \"").and_then(|n| n.strip_suffix('"')).map(str::to_owned))
            .unwrap_or_else(|| name.clone());
        let files = sources(&dir.join("src")).into_iter().map(|(_, module, file)| (module, file)).collect();
        Krate { name, lib: package.replace('-', "_"), files }
    }
}

/// What each crate re-exports from php-std, by the name it exports it under
/// (`pub use php_std::value as php;`, `pub type Value = php_std::Value<Ext>;`).
struct Exports {
    libs: BTreeSet<String>,
    names: BTreeMap<(String, String), Vec<String>>,
}

impl Exports {
    fn of(crates: &[Krate]) -> Exports {
        let mut exports = Exports { libs: crates.iter().map(|k| k.lib.clone()).collect(), names: BTreeMap::new() };
        let mut public: Vec<(String, Alias)> = Vec::new();
        for krate in crates {
            for (module, file) in &krate.files {
                let mut found = Vec::new();
                item_aliases(&file.items, module, &mut found);
                found.extend(type_aliases(&file.items));
                public.extend(found.into_iter().filter(|a| a.public).map(|a| (krate.lib.clone(), a)));
            }
        }
        // Re-exports of re-exports settle within a few rounds.
        for _ in 0..3 {
            for (lib, a) in &public {
                if let Some(p) = exports.resolve(lib, &a.path) {
                    exports.names.insert((lib.clone(), a.name.clone()), p);
                }
            }
        }
        exports
    }

    /// `path`, written in crate `lib`, as a `php_std::...` path: written so,
    /// or through a crate's re-export (`crate::php::truthy`,
    /// `utopia_validators::php::truthy`; modules in between are skipped).
    fn resolve(&self, lib: &str, path: &[String]) -> Option<Vec<String>> {
        let (from, rest) = match path.first().map(String::as_str) {
            Some(CRATE) => return Some(path.to_vec()),
            Some("crate" | "self" | "super") => (lib, &path[1..]),
            Some(other) if self.libs.contains(other) => (other, &path[1..]),
            _ => return None,
        };
        rest.iter().enumerate().find_map(|(k, seg)| {
            let base = self.names.get(&(from.to_owned(), seg.clone()))?;
            Some(base.iter().chain(&rest[k + 1..]).cloned().collect())
        })
    }
}

/// `pub type Value = php_std::Value<Ext>;` as an alias of `php_std::Value`.
fn type_aliases(items: &[syn::Item]) -> Vec<Alias> {
    items
        .iter()
        .filter_map(|item| match item {
            syn::Item::Type(t) => match &*t.ty {
                syn::Type::Path(p) => Some(Alias {
                    name: t.ident.to_string(),
                    path: p.path.segments.iter().map(|s| s.ident.to_string()).collect(),
                    public: !matches!(t.vis, syn::Visibility::Inherited),
                }),
                _ => None,
            },
            _ => None,
        })
        .collect()
}

fn subdirs(dir: &Path) -> Vec<PathBuf> {
    let mut out: Vec<_> =
        std::fs::read_dir(dir).into_iter().flatten().flatten().map(|e| e.path()).filter(|p| p.is_dir()).collect();
    out.sort();
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    /// php-std and one user crate, written to a temporary tree.
    fn tree(files: &[(&str, &str)]) -> (PathBuf, Usage) {
        let root = std::env::temp_dir().join(format!(
            "compat-phpstd-users-{}-{}",
            std::process::id(),
            std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_nanos()
        ));
        for (path, text) in files {
            let p = root.join(path);
            std::fs::create_dir_all(p.parent().unwrap()).unwrap();
            std::fs::write(p, text).unwrap();
        }
        let usage = Usage::scan(&root);
        (root, usage)
    }

    fn users(u: &Usage, path: &str) -> Vec<String> {
        u.users(path)
            .into_iter()
            .map(
                |(c, via)| {
                    if via.is_empty() {
                        c
                    } else {
                        format!("{c} via {}", via.into_iter().collect::<Vec<_>>().join(","))
                    }
                },
            )
            .collect()
    }

    const PHP_STD: &[(&str, &str)] = &[
        (
            "crates/support/php-std/src/lib.rs",
            "pub mod string;\npub mod types;\npub mod igbinary;\npub use types::Value;\n",
        ),
        (
            "crates/support/php-std/src/string.rs",
            "pub fn trim(s: &[u8]) -> &[u8] { strip(s) }\n\
             fn strip(s: &[u8]) -> &[u8] { s }\n\
             pub fn strrev(s: &[u8]) -> Vec<u8> { s.to_vec() }\n\
             pub fn empty(s: &[u8]) -> bool { s.is_empty() }\n\
             #[cfg(test)]\nmod tests { #[test] fn t() { super::strrev(b\"\"); } }\n",
        ),
        ("crates/support/php-std/src/types.rs", "pub mod value;\npub use value::Value;\n"),
        (
            "crates/support/php-std/src/types/value.rs",
            "use crate::string::trim;\n\
             pub struct Value;\n\
             impl Value {\n\
                 pub fn from_json() -> Value { Value }\n\
                 pub fn truthy(&self) -> bool { self.text(); true }\n\
                 fn text(&self) -> Vec<u8> { trim(b\"x\").to_vec() }\n\
                 pub fn unused(&self) {}\n\
             }\n",
        ),
        (
            "crates/support/php-std/src/igbinary.rs",
            "pub fn unserialize(b: &[u8]) -> Result<crate::Value, Error> { Err(Error) }\n\
             pub struct Error;\n\
             impl Error { pub fn php_class(&self) -> &str { \"\" } }\n",
        ),
    ];

    #[test]
    fn imports_reexports_methods_and_internal_calls() {
        let mut files = PHP_STD.to_vec();
        files.extend([
            // `use` of a function, a renamed module, a glob, and a method on a re-exported type.
            (
                "crates/utopia/cache/src/codec.rs",
                "use php_std::igbinary;\nuse php_std::Value as Php;\n\
                 pub fn decode(b: &[u8]) -> bool { igbinary::unserialize(b).map(|v: Php| v.truthy()).unwrap_or(false) }\n",
            ),
            ("crates/utopia/cache/src/lib.rs", "mod codec;\n"),
            // A crate's own re-export, used from another module, and a function passed as a value.
            ("crates/utopia/domains/src/lib.rs", "pub use php_std::string as ps;\nmod check;\n"),
            (
                "crates/utopia/domains/src/check.rs",
                "pub fn f(v: Vec<&[u8]>) -> usize { v.into_iter().filter(|s| crate::ps::empty(s)).count() + format!(\"{:?}\", php_std::Value::from_json()).len() }\n",
            ),
            // Glob import; test-only use does not count.
            ("crates/appwrite/core/src/lib.rs", "use php_std::string::*;\npub fn g() -> usize { trim(b\" \").len() }\n#[cfg(test)]\nmod tests { fn t() { php_std::string::strrev(b\"\"); } }\n"),
            ("crates/utopia/vcs/tests/it.rs", "fn t() { php_std::string::strrev(b\"\"); }\n"),
            // Another crate's re-export (`pub use`, `pub type`), imported by its package name.
            ("crates/utopia/validators/Cargo.toml", "[package]\nname = \"utopia-validators\"\n"),
            (
                "crates/utopia/validators/src/lib.rs",
                "pub use php_std::string as php;\npub type Value = php_std::Value;\n",
            ),
            (
                "crates/appwrite/users/src/lib.rs",
                "use utopia_validators::{php, Value as V};\n\
                 pub fn h(v: &V) -> bool { php::empty(b\"\") && v.truthy() }\n",
            ),
            // A binding's type decides its methods: written, or returned by the call
            // that made it; a local shadows a function; a later scope does not.
            (
                "crates/utopia/dns/src/lib.rs",
                "use php_std::igbinary::{self, Error};\nuse php_std::string::strrev;\n\
                 pub fn a(e: &std::io::Error) -> String { e.php_class() }\n\
                 pub fn b(s: &[u8]) -> bool { let strrev = 1; let Ok(v) = igbinary::unserialize(s) else { return false }; v.truthy() && strrev > 0 }\n\
                 pub fn c(e: Error) -> usize { { let x = 1; } e.php_class().len() }\n",
            ),
            // A type of its own with the same name is not php-std's.
            (
                "crates/utopia/emails/src/lib.rs",
                "struct Value;\nimpl Value { fn unused(&self) {} }\npub fn k() { Value.unused(); let _ = trim; }\nfn trim() {}\n",
            ),
        ]);
        let (root, u) = tree(&files);
        assert_eq!(users(&u, "igbinary::unserialize"), ["cache", "dns"]);
        assert_eq!(users(&u, "types::value::Value::truthy"), ["appwrite-users", "cache", "dns"]);
        assert_eq!(users(&u, "string::empty"), ["appwrite-users", "domains"]);
        assert_eq!(users(&u, "types::value::Value::from_json"), ["domains"]);
        // Direct from appwrite-core; through `Value::truthy` → `text` → `trim` from cache.
        assert_eq!(
            users(&u, "string::trim"),
            [
                "appwrite-core",
                "appwrite-users via types::value::Value::truthy",
                "cache via types::value::Value::truthy",
                "dns via types::value::Value::truthy"
            ]
        );
        assert_eq!(
            users(&u, "string::strip"),
            [
                "appwrite-core via string::trim",
                "appwrite-users via types::value::Value::truthy",
                "cache via types::value::Value::truthy",
                "dns via types::value::Value::truthy"
            ]
        );
        assert!(users(&u, "string::strrev").is_empty());
        assert!(users(&u, "types::value::Value::unused").is_empty());
        // `c`'s `Error` is php-std's; `a`'s is not.
        assert_eq!(users(&u, "igbinary::Error::php_class"), ["dns"]);
        std::fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn use_paths_made_absolute() {
        let m = vec!["a".to_owned(), "b".to_owned()];
        let s = |v: &[&str]| v.iter().map(|x| (*x).to_owned()).collect::<Vec<_>>();
        assert_eq!(absolute_use(&s(&["self", "x"]), &m), s(&["crate", "a", "b", "x"]));
        assert_eq!(absolute_use(&s(&["super", "super", "x"]), &m), s(&["crate", "x"]));
        assert_eq!(absolute_use(&s(&["php_std", "x"]), &m), s(&["php_std", "x"]));
    }
}
