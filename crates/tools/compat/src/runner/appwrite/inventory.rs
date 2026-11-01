//! The PHP API as the repository declares it: every route of every module
//! under `src/Appwrite/Platform/Modules` and every route still in
//! `app/controllers`, and every `Http::init`/`shutdown`/`error` hook, read
//! statically with [`super::php`].

use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;

use super::php::{self, Call, Class, File, Method, Scope, Value};

/// One SDK method a route is published as (`label('sdk', new Method(...))`).
#[derive(Debug, Clone, PartialEq)]
pub struct Sdk {
    pub namespace: String,
    pub name: String,
    /// `visible`, `hidden`, `platforms` (hidden from some) or `preview` (hidden unless `_APP_SDK_PREVIEW`).
    pub visibility: &'static str,
    pub deprecated: bool,
}

#[derive(Debug, Clone, Default)]
pub struct Endpoint {
    pub method: String,
    pub path: String,
    pub aliases: Vec<String>,
    /// `getName()`, for module actions.
    pub name: Option<String>,
    pub desc: String,
    pub groups: Vec<String>,
    pub scopes: Vec<String>,
    pub sdk: Vec<Sdk>,
    /// `label('docs', false)`: not part of the public API reference.
    pub docs: bool,
    /// Every label key, in declaration order.
    pub labels: Vec<String>,
    pub event: Option<String>,
    pub params: usize,
    pub file: String,
    pub line: usize,
    pub class: Option<String>,
}

#[derive(Debug, Clone, Default)]
pub struct Hook {
    /// `init`, `shutdown`, `error`, `options` or `wildcard`.
    pub kind: String,
    pub file: String,
    pub line: usize,
    pub groups: Vec<String>,
    pub injects: Vec<String>,
    /// `->desc()` or the docblock before the hook.
    pub desc: String,
    pub class: Option<String>,
    /// Route labels the hook reads (`getLabel('abuse-limit')`).
    pub labels: Vec<String>,
    /// Error types it throws (`Exception::USER_UNAUTHORIZED`).
    pub errors: Vec<String>,
    /// Response headers it sets.
    pub headers: Vec<String>,
    /// Source text of the hook, to match manifest anchors.
    pub source: String,
}

/// A service: a module, a controller file, or both under one name.
#[derive(Debug, Clone, Default)]
pub struct Service {
    pub id: String,
    pub title: String,
    pub description: String,
    /// `src/Appwrite/Platform/Modules/<Name>` and/or `app/controllers/...`.
    pub sources: Vec<String>,
    /// Registered in `src/Appwrite/Platform/Appwrite.php` (modules only).
    pub registered: bool,
    pub endpoints: Vec<Endpoint>,
}

pub struct Inventory {
    pub services: Vec<Service>,
    pub hooks: Vec<Hook>,
    /// Files that could not be read, or routes whose method or path did not evaluate.
    pub warnings: Vec<String>,
}

/// Every PHP file below `dir`, sorted, as paths relative to `root`.
pub fn php_files(root: &Path, dir: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut stack = vec![root.join(dir)];
    while let Some(d) = stack.pop() {
        for e in std::fs::read_dir(&d).into_iter().flatten().flatten() {
            let p = e.path();
            if p.is_dir() {
                stack.push(p);
            } else if p.extension().is_some_and(|x| x == "php")
                && let Ok(rel) = p.strip_prefix(root)
            {
                out.push(rel.to_string_lossy().replace('\\', "/"));
            }
        }
    }
    out.sort();
    out
}

/// Parsed classes across many files, by fully qualified name.
pub struct Index {
    pub files: Vec<File>,
    classes: BTreeMap<String, (usize, usize)>,
}

impl Index {
    pub fn new(root: &Path, paths: &[String]) -> Index {
        let mut files = Vec::new();
        let mut classes = BTreeMap::new();
        for path in paths {
            let Ok(src) = std::fs::read_to_string(root.join(path)) else { continue };
            let file = File::parse(path, src);
            for (c, class) in file.classes.iter().enumerate() {
                classes.insert(class.fqcn.to_ascii_lowercase(), (files.len(), c));
            }
            files.push(file);
        }
        Index { files, classes }
    }

    pub fn class(&self, fqcn: &str) -> Option<(&File, &Class)> {
        let (f, c) = *self.classes.get(&fqcn.to_ascii_lowercase())?;
        Some((&self.files[f], &self.files[f].classes[c]))
    }

    fn parent(&self, class: &Class) -> Option<(&File, &Class)> {
        class.extends.as_deref().and_then(|p| self.class(p))
    }

    /// A method of the class or its nearest ancestor.
    fn method<'a>(&'a self, file: &'a File, class: &'a Class, name: &str) -> Option<(&'a File, &'a Class, &'a Method)> {
        let mut cur = Some((file, class));
        let mut guard = 0;
        while let Some((f, c)) = cur {
            if let Some(m) = c.methods.get(name) {
                return Some((f, c, m));
            }
            guard += 1;
            if guard > 16 {
                return None;
            }
            cur = self.parent(c);
        }
        None
    }
}

/// Evaluation scope inside a class: `self::` is the declaring class,
/// `static::` and `$this->` the concrete class being read.
struct Ctx<'a> {
    index: &'a Index,
    file: &'a File,
    class: &'a Class,
    concrete: (&'a File, &'a Class),
    vars: BTreeMap<String, Value>,
    depth: usize,
}

impl Scope for Ctx<'_> {
    fn constant(&self, class: &str, name: &str) -> Option<Value> {
        let (mut file, mut cls) = match class.to_ascii_lowercase().as_str() {
            "self" => (self.file, self.class),
            "static" => self.concrete,
            _ => self.index.class(&self.file.resolve(class))?,
        };
        for _ in 0..16 {
            if let Some((a, b)) = cls.consts.get(name) {
                let ctx = Ctx {
                    index: self.index,
                    file,
                    class: cls,
                    concrete: self.concrete,
                    vars: BTreeMap::new(),
                    depth: self.depth + 1,
                };
                return (self.depth < 8).then(|| php::eval(file, *a, *b, &ctx));
            }
            (file, cls) = self.index.parent(cls)?;
        }
        None
    }

    fn var(&self, name: &str) -> Option<Value> {
        self.vars.get(name).cloned()
    }

    fn method(&self, name: &str) -> Option<Value> {
        self.call("static", name)
    }

    fn call(&self, class: &str, name: &str) -> Option<Value> {
        if self.depth >= 8 {
            return None;
        }
        let (start_file, start_class) = match class.to_ascii_lowercase().as_str() {
            "static" => self.concrete,
            "self" => (self.file, self.class),
            _ => self.index.class(&self.file.resolve(class))?,
        };
        let (file, class, method) = self.index.method(start_file, start_class, name)?;
        let ctx = Ctx {
            index: self.index,
            file,
            class,
            concrete: self.concrete,
            vars: BTreeMap::new(),
            depth: self.depth + 1,
        };
        php::returned(file, method, &ctx)
    }
}

/// A call in a constructor, with where it was written and the values its
/// variables had (constructor parameters passed up by `parent::__construct`).
struct Located<'a> {
    file: &'a File,
    class: &'a Class,
    call: Call,
    vars: BTreeMap<String, Value>,
}

/// The calls the class's constructor makes, its parents' included.
fn constructor<'a>(
    index: &'a Index,
    file: &'a File,
    class: &'a Class,
    concrete: (&'a File, &'a Class),
    vars: BTreeMap<String, Value>,
    depth: usize,
) -> Vec<Located<'a>> {
    if depth > 12 {
        return Vec::new();
    }
    let Some(ctor) = class.methods.get("__construct") else {
        return match index.parent(class) {
            Some((pf, pc)) => constructor(index, pf, pc, concrete, vars, depth + 1),
            None => Vec::new(),
        };
    };
    let mut out = Vec::new();
    let t = &file.tokens;
    let (from, to) = ctor.body;
    // `parent::__construct(args)` at the top level of the body.
    let mut k = from;
    while k + 3 < to {
        if t[k].punct_is("(") || t[k].punct_is("[") || t[k].punct_is("{") {
            k = php::closing(t, k) + 1;
            continue;
        }
        if matches!(&t[k].tok, php::Tok::Name(n) if n.eq_ignore_ascii_case("parent"))
            && t[k + 1].punct_is("::")
            && matches!(&t[k + 2].tok, php::Tok::Name(n) if n == "__construct")
            && t[k + 3].punct_is("(")
            && let Some((pf, pc)) = index.parent(class)
        {
            let close = php::closing(t, k + 3);
            let ctx = Ctx { index, file, class, concrete, vars: vars.clone(), depth: 0 };
            let args: Vec<Value> =
                php::split_args(t, k + 4, close).into_iter().map(|(a, b)| php::eval(file, a, b, &ctx)).collect();
            let params = pc.methods.get("__construct").map(|m| m.params.clone()).unwrap_or_default();
            let bound = params.into_iter().zip(args).collect();
            out.extend(constructor(index, pf, pc, concrete, bound, depth + 1));
            k = close + 1;
            continue;
        }
        k += 1;
    }
    // Local variables: `$name = expr;` statements at the top level of the body.
    let mut vars = vars;
    let mut k = from;
    while k < to {
        let end = php::statement_end(t, k, to);
        if end > k + 2
            && let php::Tok::Var(name) = &t[k].tok
            && t[k + 1].punct_is("=")
        {
            let ctx = Ctx { index, file, class, concrete, vars: vars.clone(), depth: 0 };
            let value = php::eval(file, k + 2, end, &ctx);
            vars.insert(name.clone(), value);
        }
        k = end + 1;
    }
    for call in file.calls(from, to) {
        out.push(Located { file, class, call, vars: vars.clone() });
    }
    out
}

trait PunctIs {
    fn punct_is(&self, p: &str) -> bool;
}

impl PunctIs for php::Token {
    fn punct_is(&self, p: &str) -> bool {
        matches!(&self.tok, php::Tok::Punct(x) if x == p)
    }
}

/// `Action::HTTP_REQUEST_METHOD_GET` or `'GET'` → `GET`.
fn http_method(v: &Value) -> Option<String> {
    match v {
        Value::Str(s) => Some(s.to_uppercase()),
        Value::Const(c) => c.rsplit('_').next().map(str::to_uppercase),
        _ => None,
    }
}

fn sdk_methods(v: &Value) -> Vec<Sdk> {
    let one = |m: &Value| -> Option<Sdk> {
        let Value::New { class, .. } = m else { return None };
        if php::short(class) != "Method" {
            return None;
        }
        let text = |name: &str, pos: usize| m.arg(name, pos).and_then(Value::as_str).unwrap_or("").to_owned();
        let visibility = match m.arg("hide", usize::MAX) {
            None | Some(Value::Bool(false)) => "visible",
            Some(Value::Bool(true)) => "hidden",
            Some(Value::Array(_)) => "platforms",
            Some(_) => "preview",
        };
        let deprecated = matches!(m.arg("deprecated", usize::MAX), Some(Value::Bool(true)) | Some(Value::New { .. }));
        Some(Sdk { namespace: text("namespace", 0), name: text("name", 2), visibility, deprecated })
    };
    match v {
        Value::Array(items) => items.iter().filter_map(|(_, m)| one(m)).collect(),
        m => one(m).into_iter().collect(),
    }
}

/// Applies one builder call (`setHttpPath`, `label`, ...) to an endpoint.
fn apply(e: &mut Endpoint, name: &str, args: &[Value]) {
    let arg = |i: usize| args.get(i);
    match name {
        "setHttpMethod" => {
            if let Some(m) = arg(0).and_then(http_method) {
                e.method = m;
            }
        }
        "setHttpPath" => {
            if let Some(Value::Str(p)) = arg(0) {
                e.path = p.clone();
            }
        }
        "httpAlias" | "alias" => {
            if let Some(Value::Str(p)) = arg(0) {
                e.aliases.push(p.clone());
            }
        }
        "desc" => e.desc = arg(0).and_then(Value::as_str).unwrap_or("").to_owned(),
        "groups" => e.groups = arg(0).map(Value::strings).unwrap_or_default(),
        "param" => e.params += 1,
        "label" => {
            let Some(key) = arg(0).and_then(Value::as_str) else { return };
            let value = arg(1).cloned().unwrap_or(Value::Null);
            if !e.labels.iter().any(|l| l == key) {
                e.labels.push(key.to_owned());
            }
            match key {
                "scope" => e.scopes = value.strings(),
                "sdk" => e.sdk = sdk_methods(&value),
                "docs" => e.docs = value != Value::Bool(false),
                "event" => e.event = value.as_str().map(str::to_owned),
                _ => {}
            }
        }
        _ => {}
    }
}

/// Facts about a hook body: labels it reads, errors it throws, headers it sets.
fn facts(file: &File, from: usize, to: usize, hook: &mut Hook) {
    use php::Tok;
    let t = &file.tokens;
    let mut labels = BTreeSet::new();
    let mut errors = BTreeSet::new();
    let mut headers = BTreeSet::new();
    for k in from..to.saturating_sub(2).max(from) {
        match (&t[k].tok, &t[k + 1].tok, &t[k + 2].tok) {
            (Tok::Name(c), Tok::Punct(p), Tok::Name(n))
                if p == "::" && c.ends_with("Exception") && n.chars().all(|x| x.is_ascii_uppercase() || x == '_') =>
            {
                errors.insert(n.clone());
            }
            (Tok::Punct(a), Tok::Name(m), Tok::Punct(p)) if a == "->" && p == "(" => {
                if let Some(Tok::Str(s)) = t.get(k + 3).map(|x| &x.tok) {
                    match m.as_str() {
                        "getLabel" => {
                            labels.insert(s.clone());
                        }
                        "addHeader" => {
                            headers.insert(s.clone());
                        }
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    hook.labels = labels.into_iter().collect();
    hook.errors = errors.into_iter().collect();
    hook.headers = headers.into_iter().collect();
}

/// The body of a hook's callback: an inline closure, or `$this->action(...)`.
fn callback_body(index: &Index, file: &File, class: Option<&Class>, call: &Call) -> Option<(usize, usize)> {
    let (a, b) = *call.args.first()?;
    let t = &file.tokens;
    if let Some(open) = (a..b).find(|&j| t[j].punct_is("{")) {
        return Some((open + 1, php::closing(t, open)));
    }
    let class = class?;
    let name = (a..b).find_map(|j| match (&t[j].tok, t.get(j + 1).map(|x| &x.tok)) {
        (php::Tok::Punct(p), Some(php::Tok::Name(n))) if p == "->" => Some(n.clone()),
        _ => None,
    })?;
    let (f, _, m) = index.method(file, class, &name)?;
    // Facts are read from the file the method lives in; only same-file bodies are supported.
    (std::ptr::eq(f, file)).then_some(m.body)
}

/// Reads every module and controller.
pub fn read(root: &Path) -> Inventory {
    let mut warnings = Vec::new();
    let modules_dir = "src/Appwrite/Platform/Modules";
    let index = Index::new(root, &php_files(root, modules_dir));
    let config = services_config(root);

    // Modules the platform registers.
    let registered: BTreeSet<String> = std::fs::read_to_string(root.join("src/Appwrite/Platform/Appwrite.php"))
        .map(|src| {
            let f = File::parse("Appwrite.php", src);
            f.tokens
                .windows(2)
                .filter_map(|w| match (&w[0].tok, &w[1].tok) {
                    (php::Tok::Name(n), php::Tok::Name(c)) if n == "new" && c.ends_with("\\Module") => {
                        c.split('\\').next().map(str::to_owned)
                    }
                    _ => None,
                })
                .collect()
        })
        .unwrap_or_default();

    let mut services: BTreeMap<String, Service> = BTreeMap::new();
    let mut hooks = Vec::new();

    for module in super::dirs(&root.join(modules_dir)) {
        let base = format!("{modules_dir}/{module}/");
        // `Http` (or `HTTP`), as the directory is really spelled: file paths keep that case.
        let http = super::dirs(&root.join(&base))
            .into_iter()
            .find(|d| d.eq_ignore_ascii_case("http"))
            .map(|d| format!("{base}{d}/"));
        let Some(http) = http else { continue };
        // Classes the module's HTTP services register (`new X()`, `X::getName()`, `X::class`).
        let mut wired: BTreeSet<String> = BTreeSet::new();
        for f in index.files.iter().filter(|f| {
            f.path.starts_with(&format!("{base}Services/"))
                && !f.path.ends_with("/Workers.php")
                && !f.path.ends_with("/Tasks.php")
        }) {
            let t = &f.tokens;
            for k in 0..t.len().saturating_sub(2) {
                let reference = match (&t[k].tok, &t[k + 1].tok, &t[k + 2].tok) {
                    (php::Tok::Name(n), php::Tok::Name(c), _) if n == "new" => Some(c),
                    (php::Tok::Name(c), php::Tok::Punct(p), php::Tok::Name(m))
                        if p == "::" && (m == "getName" || m == "class") =>
                    {
                        Some(c)
                    }
                    _ => None,
                };
                if let Some(c) = reference {
                    wired.insert(f.resolve(c).to_ascii_lowercase());
                }
            }
        }
        let id = module.to_ascii_lowercase();
        let service = services.entry(id.clone()).or_insert_with(|| Service {
            title: module.clone(),
            description: config.get(&id).map(|c| c.1.clone()).unwrap_or_default(),
            id: id.clone(),
            registered: registered.contains(&module),
            ..Default::default()
        });
        service.sources.push(format!("{modules_dir}/{module}"));

        for file in index.files.iter().filter(|f| f.path.starts_with(&http)) {
            for class in &file.classes {
                if class.kind != "class" || class.is_abstract {
                    continue;
                }
                if !wired.is_empty() && !wired.contains(&class.fqcn.to_ascii_lowercase()) {
                    continue;
                }
                let calls = constructor(&index, file, class, (file, class), BTreeMap::new(), 0);
                let value = |l: &Located, i: usize| -> Value {
                    let ctx = Ctx {
                        index: &index,
                        file: l.file,
                        class: l.class,
                        concrete: (file, class),
                        vars: l.vars.clone(),
                        depth: 0,
                    };
                    l.call.args.get(i).map(|(a, b)| php::eval(l.file, *a, *b, &ctx)).unwrap_or(Value::Null)
                };
                // A hook declared as an action class (`setType(Action::TYPE_INIT)`).
                if let Some(kind) = calls.iter().find(|l| l.call.name == "setType").map(|l| value(l, 0)) {
                    let kind = match kind {
                        Value::Const(c) => c.rsplit("TYPE_").next().unwrap_or("").to_ascii_lowercase(),
                        other => other.text(),
                    };
                    let mut hook = Hook {
                        kind,
                        file: file.path.clone(),
                        line: class.line,
                        class: Some(class.fqcn.clone()),
                        desc: class.doc.as_deref().map(php::doc_text).unwrap_or_default(),
                        source: file.src.clone(),
                        ..Default::default()
                    };
                    for l in &calls {
                        match l.call.name.as_str() {
                            "groups" => hook.groups = value(l, 0).strings(),
                            "inject" => hook.injects.extend(value(l, 0).as_str().map(str::to_owned)),
                            "desc" => hook.desc = value(l, 0).as_str().unwrap_or("").to_owned(),
                            "callback" => {
                                if let Some((a, b)) = callback_body(&index, l.file, Some(l.class), &l.call) {
                                    facts(l.file, a, b, &mut hook);
                                }
                            }
                            _ => {}
                        }
                    }
                    hooks.push(hook);
                    continue;
                }
                if !calls.iter().any(|l| l.call.name == "setHttpPath") {
                    continue;
                }
                let mut e = Endpoint {
                    docs: true,
                    file: file.path.clone(),
                    line: class.line,
                    class: Some(class.fqcn.clone()),
                    ..Default::default()
                };
                for l in &calls {
                    let args: Vec<Value> = (0..l.call.args.len().min(2)).map(|i| value(l, i)).collect();
                    apply(&mut e, &l.call.name, &args);
                }
                // `getName()`: the registered id.
                if let Some((f, c, m)) = index.method(file, class, "getName") {
                    let ctx = Ctx {
                        index: &index,
                        file: f,
                        class: c,
                        concrete: (file, class),
                        vars: BTreeMap::new(),
                        depth: 0,
                    };
                    e.name = php::returned(f, m, &ctx).and_then(|v| v.as_str().map(str::to_owned));
                }
                if e.method.is_empty() || !e.path.starts_with('/') {
                    warnings.push(format!("{}: route method or path not read ({} {})", file.path, e.method, e.path));
                    continue;
                }
                service.endpoints.push(e);
            }
        }
    }

    // Leftover controllers.
    for path in php_files(root, "app/controllers") {
        let Ok(src) = std::fs::read_to_string(root.join(&path)) else {
            warnings.push(format!("{path}: unreadable"));
            continue;
        };
        let file = File::parse(&path, src);
        let rel = path.trim_start_matches("app/controllers/");
        // `api/locale.php` → `locale`; `web/home.php` → `web/home` as `app/config/services.php` keys it.
        let key = rel.trim_end_matches(".php").trim_start_matches("api/").to_owned();
        let statics = file.statics("Http");
        for s in &statics {
            let first = s.args.first().map(|(a, b)| php::eval(&file, *a, *b, &php::Plain));
            let args_of = |c: &Call| -> Vec<Value> {
                c.args.iter().take(2).map(|(a, b)| php::eval(&file, *a, *b, &php::Plain)).collect()
            };
            match s.name.as_str() {
                "get" | "post" | "put" | "patch" | "delete" => {
                    let id = key.replace("web/", "").replace('/', "-");
                    let service = services.entry(id.clone()).or_insert_with(|| {
                        let (title, description) =
                            config.get(&key).cloned().unwrap_or_else(|| (title_of(&id), String::new()));
                        Service { id: id.clone(), title, description, registered: true, ..Default::default() }
                    });
                    if !service.sources.contains(&path) {
                        service.sources.push(path.clone());
                    }
                    let mut e = Endpoint {
                        method: s.name.to_uppercase(),
                        path: first.and_then(|v| v.as_str().map(str::to_owned)).unwrap_or_default(),
                        docs: true,
                        file: path.clone(),
                        line: s.line,
                        ..Default::default()
                    };
                    for c in &s.chain {
                        apply(&mut e, &c.name, &args_of(c));
                    }
                    service.endpoints.push(e);
                }
                "init" | "shutdown" | "error" | "options" | "wildcard" => {
                    let mut hook = Hook {
                        kind: s.name.clone(),
                        file: path.clone(),
                        line: s.line,
                        desc: s.doc.as_deref().map(php::doc_text).unwrap_or_default(),
                        source: file.text(s.range.0, s.range.1),
                        ..Default::default()
                    };
                    for c in &s.chain {
                        let args = args_of(c);
                        match c.name.as_str() {
                            "groups" => hook.groups = args.first().map(Value::strings).unwrap_or_default(),
                            "inject" => hook.injects.extend(args.first().and_then(Value::as_str).map(str::to_owned)),
                            "desc" => hook.desc = args.first().and_then(Value::as_str).unwrap_or("").to_owned(),
                            "action" => {
                                if let Some((a, b)) = callback_body(&index, &file, None, c) {
                                    facts(&file, a, b, &mut hook);
                                }
                            }
                            _ => {}
                        }
                    }
                    hooks.push(hook);
                }
                _ => {}
            }
        }
    }

    let mut services: Vec<Service> = services.into_values().filter(|s| !s.endpoints.is_empty()).collect();
    for s in &mut services {
        s.endpoints
            .sort_by(|a, b| a.path.cmp(&b.path).then_with(|| method_rank(&a.method).cmp(&method_rank(&b.method))));
    }
    services.sort_by_key(|s| s.title.to_ascii_lowercase());
    Inventory { services, hooks, warnings }
}

/// Orders methods as REST reads them: list/get, create, update, delete.
pub fn method_rank(m: &str) -> usize {
    ["GET", "POST", "PUT", "PATCH", "DELETE"].iter().position(|x| *x == m).unwrap_or(9)
}

/// `graphql` → `GraphQL`, `general` → `General`.
fn title_of(id: &str) -> String {
    let mut c = id.chars();
    c.next().map(|f| f.to_uppercase().collect::<String>() + c.as_str()).unwrap_or_default()
}

/// `app/config/services.php`: key → (name, subtitle).
fn services_config(root: &Path) -> BTreeMap<String, (String, String)> {
    let Ok(src) = std::fs::read_to_string(root.join("app/config/services.php")) else { return BTreeMap::new() };
    let file = File::parse("app/config/services.php", src);
    let t = &file.tokens;
    let Some(open) = (0..t.len()).find(|&k| t[k].punct_is("[")) else { return BTreeMap::new() };
    let close = php::closing(t, open);
    let Value::Array(items) = php::eval(&file, open, close + 1, &php::Plain) else { return BTreeMap::new() };
    items
        .into_iter()
        .filter_map(|(k, v)| {
            let key = k?.as_str()?.to_owned();
            let Value::Array(fields) = v else { return None };
            let field = |name: &str| {
                fields
                    .iter()
                    .find(|(k, _)| k.as_ref().and_then(Value::as_str) == Some(name))
                    .and_then(|(_, v)| v.as_str())
                    .unwrap_or("")
                    .to_owned()
            };
            Some((key, (field("name"), field("subtitle"))))
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn root() -> std::path::PathBuf {
        Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..")
    }

    fn endpoint(path_in_repo: &str) -> Endpoint {
        let root = root();
        let module = path_in_repo.split('/').take(5).collect::<Vec<_>>().join("/");
        let index = Index::new(&root, &php_files(&root, &module));
        let file = index.files.iter().find(|f| f.path == path_in_repo).expect("file is indexed");
        let class = file.classes.iter().find(|c| !c.is_abstract).expect("a concrete class");
        let calls = constructor(&index, file, class, (file, class), BTreeMap::new(), 0);
        let mut e = Endpoint { docs: true, ..Default::default() };
        for l in &calls {
            let ctx = Ctx {
                index: &index,
                file: l.file,
                class: l.class,
                concrete: (file, class),
                vars: l.vars.clone(),
                depth: 0,
            };
            let args: Vec<Value> = l.call.args.iter().take(2).map(|(a, b)| php::eval(l.file, *a, *b, &ctx)).collect();
            apply(&mut e, &l.call.name, &args);
        }
        e
    }

    #[test]
    fn reads_a_module_action() {
        let e = endpoint("src/Appwrite/Platform/Modules/Teams/Http/Teams/Create.php");
        assert_eq!(e.method, "POST");
        assert_eq!(e.path, "/v1/teams");
        assert_eq!(e.groups, ["api", "teams"]);
        assert_eq!(e.scopes, ["teams.write"]);
        assert_eq!(e.event.as_deref(), Some("teams.[teamId].create"));
        assert_eq!(
            e.sdk,
            [Sdk { namespace: "teams".into(), name: "create".into(), visibility: "visible", deprecated: false }]
        );
        assert_eq!(e.params, 3);
    }

    #[test]
    fn reads_scope_arrays_and_users_routes() {
        let e = endpoint("src/Appwrite/Platform/Modules/Users/Http/Users/Sessions/XList.php");
        assert_eq!(e.method, "GET");
        assert_eq!(e.path, "/v1/users/:userId/sessions");
        assert_eq!(e.scopes, ["users.read", "sessions.read"]);
        assert_eq!(e.sdk[0].namespace, "users");
        assert_eq!(e.sdk[0].name, "listSessions");
    }

    #[test]
    fn reads_a_parent_constructor_with_arguments() {
        // `parent::__construct(Action::HTTP_REQUEST_METHOD_GET)` and `self::PREFIX . '/*'` in the base.
        let e = endpoint("src/Appwrite/Platform/Modules/S3/Http/S3/Get.php");
        assert_eq!(e.method, "GET");
        assert_eq!(e.path, "/v1/s3");
        assert_eq!(e.aliases, ["/v1/s3/*"]);
        assert_eq!(e.groups, ["s3"]);
    }

    #[test]
    fn reads_a_path_from_an_overridden_method() {
        // `setHttpPath($this->getPath())` in an abstract base.
        let e = endpoint("src/Appwrite/Platform/Modules/Console/Http/Redirects/Login/Get.php");
        assert_eq!(e.method, "GET");
        assert!(e.path.starts_with('/'), "{}", e.path);
        assert_eq!(e.groups, ["web"]);
    }

    #[test]
    fn reads_local_variables_and_static_calls() {
        // `$providerId = static::getProviderId();` then `->setHttpPath('/v1/project/oauth2/' . $providerId)`.
        let e = endpoint("src/Appwrite/Platform/Modules/Project/Http/Project/OAuth2/Amazon/Update.php");
        assert_eq!(e.method, "PATCH");
        assert_eq!(e.path, "/v1/project/oauth2/amazon");
    }

    #[test]
    fn reads_controllers_and_hooks() {
        let inv = read(&root());
        let account = inv.services.iter().find(|s| s.id == "account").expect("account");
        assert!(account.sources.iter().any(|s| s == "app/controllers/api/account.php"));
        assert!(account.sources.iter().any(|s| s == "src/Appwrite/Platform/Modules/Account"));
        let locale = inv.services.iter().find(|s| s.id == "locale").expect("locale");
        assert!(locale.endpoints.iter().any(|e| e.method == "GET" && e.path == "/v1/locale"));
        let users = inv.services.iter().find(|s| s.id == "users").expect("users");
        assert!(users.endpoints.iter().any(|e| e.method == "PATCH" && e.path == "/v1/users/:userId/status"));
        // The abuse hook reads its labels and throws the rate limit error.
        let abuse = inv
            .hooks
            .iter()
            .find(|h| h.kind == "init" && h.labels.iter().any(|l| l == "abuse-limit"))
            .expect("abuse hook");
        assert_eq!(abuse.file, "app/controllers/shared/api.php");
        assert!(abuse.errors.iter().any(|e| e == "GENERAL_RATE_LIMIT_EXCEEDED"));
        assert!(abuse.headers.iter().any(|h| h == "X-RateLimit-Limit"));
        // Module hooks are action classes.
        assert!(inv.hooks.iter().any(|h| h.file.ends_with("Webhooks/Http/Init.php") && h.kind == "init"));
        assert!(inv.warnings.is_empty(), "{:?}", inv.warnings);
    }
}
