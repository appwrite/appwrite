//! What the Rust API serves and what Traefik sends to it.
//!
//! - Routes: every `route!(METHOD, "/path", ...)` and `Route { method:
//!   Method::X, path: "/path", .. }` in `crates/appwrite/*`, read as tokens;
//!   a crate's routes count as served when `crates/appwrite/server` registers
//!   its module (`.module(&crate_or_mod::Module)`).
//! - Routing: the Traefik routers whose service is the Rust container's, from
//!   the compose files' labels; their `Path(...)` and `PathPrefix(...)`.

use std::path::Path;
use std::str::FromStr;

use proc_macro2::{Delimiter, TokenStream, TokenTree};

#[derive(Debug, Clone, PartialEq)]
pub struct RustRoute {
    pub method: String,
    pub path: String,
    /// `crates/appwrite/users`.
    pub krate: String,
    pub file: String,
    pub line: usize,
    pub registered: bool,
}

/// Every route declared in the crates under `crates/appwrite`.
pub fn routes(root: &Path) -> Vec<RustRoute> {
    let base = root.join("crates/appwrite");
    let server = std::fs::read_to_string(base.join("server/src/main.rs")).unwrap_or_default();
    let mut out = Vec::new();
    for name in super::dirs(&base) {
        if name == "core" {
            continue;
        }
        let dir = base.join(&name);
        let lib = format!("appwrite_{}", name.replace('-', "_"));
        for file in rust_files(&dir.join("src")) {
            let Ok(src) = std::fs::read_to_string(&file) else { continue };
            let rel = file.strip_prefix(root).map(|p| p.to_string_lossy().replace('\\', "/")).unwrap_or_default();
            // A crate's module is served when the server registers it; the
            // server's own modules (`mod health;`) by their module name.
            let stem = file.file_stem().map(|s| s.to_string_lossy().into_owned()).unwrap_or_default();
            let registered = if name == "server" {
                server.contains(&format!(".module(&{stem}::"))
            } else {
                server.contains(&format!(".module(&{lib}::"))
            };
            for (method, path, line) in declared(&src) {
                out.push(RustRoute {
                    method,
                    path,
                    krate: format!("crates/appwrite/{name}"),
                    file: rel.clone(),
                    line,
                    registered,
                });
            }
        }
    }
    out.sort_by(|a, b| a.path.cmp(&b.path).then_with(|| a.method.cmp(&b.method)));
    out
}

fn rust_files(dir: &Path) -> Vec<std::path::PathBuf> {
    let mut out = Vec::new();
    let mut stack = vec![dir.to_path_buf()];
    while let Some(d) = stack.pop() {
        for e in std::fs::read_dir(&d).into_iter().flatten().flatten() {
            let p = e.path();
            if p.is_dir() {
                stack.push(p);
            } else if p.extension().is_some_and(|x| x == "rs") {
                out.push(p);
            }
        }
    }
    out.sort();
    out
}

/// `(method, path, line)` of every route in a Rust source file.
pub fn declared(src: &str) -> Vec<(String, String, usize)> {
    let Ok(stream) = TokenStream::from_str(src) else { return Vec::new() };
    let mut out = Vec::new();
    walk(stream, &mut out);
    out
}

fn literal(t: &TokenTree) -> Option<String> {
    let TokenTree::Literal(l) = t else { return None };
    let s = l.to_string();
    s.strip_prefix('"').and_then(|s| s.strip_suffix('"')).map(str::to_owned)
}

fn ident(t: &TokenTree) -> Option<String> {
    match t {
        TokenTree::Ident(i) => Some(i.to_string()),
        _ => None,
    }
}

fn walk(stream: TokenStream, out: &mut Vec<(String, String, usize)>) {
    let tokens: Vec<TokenTree> = stream.into_iter().collect();
    for (k, t) in tokens.iter().enumerate() {
        // `route!(METHOD, "/path", ...)`
        if ident(t).as_deref() == Some("route")
            && matches!(tokens.get(k + 1), Some(TokenTree::Punct(p)) if p.as_char() == '!')
            && let Some(TokenTree::Group(g)) = tokens.get(k + 2)
            && g.delimiter() == Delimiter::Parenthesis
        {
            let inner: Vec<TokenTree> = g.stream().into_iter().collect();
            if let (Some(method), Some(path)) = (inner.first().and_then(ident), inner.get(2).and_then(literal))
                && path.starts_with('/')
            {
                out.push((method, path, t.span().start().line));
            }
            continue;
        }
        // `Route { method: Method::GET, path: "/path", .. }`
        if ident(t).as_deref() == Some("Route")
            && let Some(TokenTree::Group(g)) = tokens.get(k + 1)
            && g.delimiter() == Delimiter::Brace
        {
            let inner: Vec<TokenTree> = g.stream().into_iter().collect();
            let field = |name: &str| inner.iter().position(|x| ident(x).as_deref() == Some(name));
            let method = field("method").and_then(|i| {
                // `method: Method::GET`
                inner.get(i + 5).and_then(ident)
            });
            let path = field("path").and_then(|i| inner.get(i + 2)).and_then(literal);
            if let (Some(method), Some(path)) = (method, path)
                && path.starts_with('/')
                && path != "/*"
            {
                out.push((method, path, t.span().start().line));
                continue;
            }
        }
        if let TokenTree::Group(g) = t {
            walk(g.stream(), out);
        }
    }
}

/// A Traefik router that sends requests to the Rust API.
#[derive(Debug, Clone, PartialEq)]
pub struct Router {
    pub name: String,
    pub rule: String,
    pub paths: Vec<String>,
    pub prefixes: Vec<String>,
    pub priority: Option<String>,
    pub file: String,
    pub line: usize,
    /// The compose profile the Rust container runs under.
    pub profile: Option<String>,
}

/// Routers to the Rust container in the compose files.
pub fn routers(root: &Path) -> Vec<Router> {
    let mut out = Vec::new();
    for file in ["docker-compose.yml", "docker-compose.override.yml"] {
        let Ok(src) = std::fs::read_to_string(root.join(file)) else { continue };
        out.extend(routers_in(file, &src));
    }
    out
}

/// Labels of the compose service named `appwrite-rust`: the Traefik service
/// it defines, the routers that use that service, and its profile.
pub fn routers_in(file: &str, src: &str) -> Vec<Router> {
    let lines: Vec<&str> = src.lines().collect();
    let Some(start) = lines.iter().position(|l| l.trim_end() == "  appwrite-rust:") else { return Vec::new() };
    let end = (start + 1..lines.len())
        .find(|&i| {
            let l = lines[i];
            l.len() > 2 && l.starts_with("  ") && !l.starts_with("   ") && !l.trim_start().starts_with('#')
        })
        .unwrap_or(lines.len());
    let block = &lines[start..end];
    let label = |l: &str| -> Option<(String, String)> {
        let l = l.trim().trim_start_matches("- ").trim_matches(['"', '\'']);
        let (k, v) = l.split_once('=')?;
        Some((k.trim().to_owned(), v.trim().to_owned()))
    };
    let mut services = Vec::new();
    let mut profile = None;
    for (i, l) in block.iter().enumerate() {
        if l.trim() == "profiles:"
            && let Some(next) = block.get(i + 1)
        {
            profile = Some(next.trim().trim_start_matches("- ").to_owned());
        }
        if let Some((k, _)) = label(l)
            && let Some(rest) = k.strip_prefix("traefik.http.services.")
            && let Some((svc, _)) = rest.split_once('.')
        {
            services.push(svc.to_owned());
        }
    }
    let mut out: Vec<Router> = Vec::new();
    let mut targets: Vec<(String, String)> = Vec::new();
    for (i, l) in block.iter().enumerate() {
        let Some((k, v)) = label(l) else { continue };
        let Some(rest) = k.strip_prefix("traefik.http.routers.") else { continue };
        let Some((name, key)) = rest.split_once('.') else { continue };
        let entry = match out.iter_mut().position(|r| r.name == name) {
            Some(p) => &mut out[p],
            None => {
                out.push(Router {
                    name: name.to_owned(),
                    rule: String::new(),
                    paths: Vec::new(),
                    prefixes: Vec::new(),
                    priority: None,
                    file: file.to_owned(),
                    line: start + i + 1,
                    profile: profile.clone(),
                });
                out.last_mut().expect("just pushed")
            }
        };
        match key {
            "rule" => {
                entry.paths = matchers(&v, "Path(`");
                entry.prefixes = matchers(&v, "PathPrefix(`");
                entry.line = start + i + 1;
                entry.rule = v;
            }
            "priority" => entry.priority = Some(v),
            "service" => targets.push((name.to_owned(), v)),
            _ => {}
        }
    }
    // Routers without an explicit service use the container's only one.
    out.retain(|r| {
        !r.rule.is_empty()
            && targets.iter().find(|(n, _)| *n == r.name).is_none_or(|(_, s)| services.iter().any(|x| x == s))
    });
    out
}

/// The backtick arguments of every `Name(`...`)` in a rule.
fn matchers(rule: &str, open: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut rest = rule;
    while let Some(p) = rest.find(open) {
        // `PathPrefix(` also contains `Path(`... only when preceded by a word boundary.
        let before = rest[..p].chars().next_back();
        let after = &rest[p + open.len()..];
        if before.is_none_or(|c| !c.is_alphanumeric())
            && let Some(end) = after.find('`')
        {
            out.push(after[..end].to_owned());
        }
        rest = after;
    }
    out
}

/// Whether a router sends `path` (a route pattern such as `/v1/users/:userId`) to Rust.
pub fn routed(routers: &[Router], path: &str) -> bool {
    routers.iter().any(|r| r.paths.iter().any(|p| p == path) || r.prefixes.iter().any(|p| path.starts_with(p.as_str())))
}

/// `/v1/users/:userId` and `/v1/users/:id` are the same route.
pub fn shape(path: &str) -> String {
    path.split('/').map(|s| if s.starts_with(':') { ":" } else { s }).collect::<Vec<_>>().join("/")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_route_macros_and_literals() {
        let src = r#"
            fn routes() -> Vec<Route> {
                vec![
                    // route!(GET, "/v1/commented", ...) is not code.
                    route!(POST, "/v1/users", "createUser", "create", WRITE, None, None, users::create),
                    route!(GET, "/v1/users/:userId", "getUser", "get", READ, None, None, users::get),
                ]
            }
            fn other() -> Route {
                Route { method: Method::GET, path: "/v1/health/version", name: "getVersion" }
            }
            fn wildcard() -> Route { Route { method: Method::GET, path: "/*" } }
        "#;
        let found: Vec<(String, String)> = declared(src).into_iter().map(|(m, p, _)| (m, p)).collect();
        assert_eq!(
            found,
            [
                ("POST".into(), "/v1/users".into()),
                ("GET".into(), "/v1/users/:userId".into()),
                ("GET".into(), "/v1/health/version".into())
            ]
        );
    }

    #[test]
    fn reads_traefik_rules() {
        let compose = "services:\n  appwrite-rust:\n    profiles:\n      - rust\n    labels:\n      - traefik.http.services.appwrite_rust.loadbalancer.server.port=8080\n      - traefik.http.routers.r_http.rule=(Host(`localhost`)) && (Path(`/v1/users`) || PathPrefix(`/v1/users/`))\n      - traefik.http.routers.r_http.priority=1000\n      - traefik.http.routers.r_http.service=appwrite_rust\n  appwrite:\n    labels:\n      - traefik.http.routers.php.rule=PathPrefix(`/`)\n";
        let r = routers_in("docker-compose.override.yml", compose);
        assert_eq!(r.len(), 1);
        assert_eq!(r[0].paths, ["/v1/users"]);
        assert_eq!(r[0].prefixes, ["/v1/users/"]);
        assert_eq!(r[0].profile.as_deref(), Some("rust"));
        assert!(routed(&r, "/v1/users/:userId/sessions"));
        assert!(routed(&r, "/v1/users"));
        assert!(!routed(&r, "/v1/teams"));
    }

    #[test]
    fn reads_the_repository() {
        let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..");
        let r = routes(&root);
        assert!(r.iter().any(|r| r.method == "PATCH" && r.path == "/v1/users/:userId/status" && r.registered));
        assert!(!routers(&root).is_empty());
    }
}
