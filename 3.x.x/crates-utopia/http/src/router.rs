use crate::error::{HttpError, Result};
use crate::route::Route;
use parking_lot::RwLock;
use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

const PLACEHOLDER: &str = ":*:";
const WILDCARD: &str = "*";

#[derive(Clone, Debug)]
pub struct RouteMatch {
    pub route: Arc<Route>,
    pub params: HashMap<String, String>,
}

#[derive(Default)]
struct RouterInner {
    routes: HashMap<String, HashMap<String, Arc<Route>>>,
    param_indexes: Vec<usize>,
    wildcard: Option<Arc<Route>>,
    allow_override: bool,
}

/// Utopia-compatible router (`:param`, `*`, aliases, multi-method).
#[derive(Clone, Default)]
pub struct Router {
    inner: Arc<RwLock<RouterInner>>,
}

impl fmt::Debug for Router {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Router").finish_non_exhaustive()
    }
}

impl Router {
    pub fn new() -> Self {
        let mut routes = HashMap::new();
        for m in ["GET", "POST", "PUT", "PATCH", "DELETE"] {
            routes.insert(m.to_string(), HashMap::new());
        }
        Self {
            inner: Arc::new(RwLock::new(RouterInner {
                routes,
                param_indexes: Vec::new(),
                wildcard: None,
                allow_override: false,
            })),
        }
    }

    pub fn set_allow_override(&self, value: bool) {
        self.inner.write().allow_override = value;
    }

    pub fn get_allow_override(&self) -> bool {
        self.inner.read().allow_override
    }

    pub fn set_wildcard(&self, route: Arc<Route>) {
        self.inner.write().wildcard = Some(route);
    }

    pub fn add_route(&self, route: Arc<Route>) -> Result<()> {
        let (path, params) = prepare_path(route.path());
        let methods = route.methods().to_vec();
        if methods.is_empty() {
            return Err(HttpError::EmptyMethods);
        }
        let mut inner = self.inner.write();
        for method in &methods {
            if !inner.routes.contains_key(method) {
                return Err(HttpError::UnsupportedMethod(method.clone()));
            }
            if inner.routes[method].contains_key(&path) && !inner.allow_override {
                return Err(HttpError::DuplicateRoute {
                    method: method.clone(),
                    path: path.clone(),
                });
            }
        }
        for (key, index) in &params {
            route.set_path_param(key, *index, &path);
            if !inner.param_indexes.contains(index) {
                inner.param_indexes.push(*index);
            }
        }
        for method in methods {
            inner
                .routes
                .get_mut(&method)
                .unwrap()
                .insert(path.clone(), route.clone());
        }
        Ok(())
    }

    pub fn add_route_alias(&self, alias_path: &str, route: Arc<Route>) -> Result<()> {
        let (alias, params) = prepare_path(alias_path);
        let methods = route.methods().to_vec();
        let mut inner = self.inner.write();
        for method in &methods {
            if !inner.routes.contains_key(method) {
                return Err(HttpError::UnsupportedMethod(method.clone()));
            }
            if inner.routes[method].contains_key(&alias) && !inner.allow_override {
                return Err(HttpError::DuplicateRoute {
                    method: method.clone(),
                    path: alias.clone(),
                });
            }
        }
        for (key, index) in &params {
            route.set_path_param(key, *index, &alias);
            if !inner.param_indexes.contains(index) {
                inner.param_indexes.push(*index);
            }
        }
        for method in methods {
            inner
                .routes
                .get_mut(&method)
                .unwrap()
                .insert(alias.clone(), route.clone());
        }
        route.add_alias_path(alias_path);
        Ok(())
    }

    pub fn match_route(&self, method: &str, path: &str) -> Option<RouteMatch> {
        let inner = self.inner.read();
        let method = if method == "HEAD" { "GET" } else { method };
        let Some(table) = inner.routes.get(method) else {
            return inner.wildcard.clone().map(|route| RouteMatch {
                route,
                params: HashMap::new(),
            });
        };

        let parts: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
        let static_key = parts.join("/");

        // Fast path: exact static template (no placeholder substitution).
        if let Some(route) = table.get(&static_key) {
            let params = route.resolve_params_from_parts(&parts, &static_key);
            return Some(RouteMatch {
                route: route.clone(),
                params,
            });
        }

        let length = parts.len().saturating_sub(1);
        let filtered: Vec<usize> = inner
            .param_indexes
            .iter()
            .copied()
            .filter(|i| *i <= length)
            .collect();

        // Parametric routes only when placeholder indexes exist for this path length.
        if !filtered.is_empty() {
            // Skip the empty sample - already tried as static_key above.
            for sample in combinations(&filtered).into_iter().skip(1) {
                let sample: Vec<usize> = sample.into_iter().filter(|i| *i <= length).collect();
                if sample.is_empty() {
                    continue;
                }
                let mut template_parts = parts.clone();
                for i in &sample {
                    if *i < template_parts.len() {
                        template_parts[*i] = PLACEHOLDER;
                    }
                }
                let template = template_parts.join("/");
                if let Some(route) = table.get(&template) {
                    let params = route.resolve_params_from_parts(&parts, &template);
                    return Some(RouteMatch {
                        route: route.clone(),
                        params,
                    });
                }
            }
        }

        if let Some(route) = table.get(WILDCARD) {
            return Some(RouteMatch {
                route: route.clone(),
                params: HashMap::new(),
            });
        }

        let mut current = String::new();
        for part in &parts {
            current.push_str(part);
            current.push('/');
            let template = format!("{current}{WILDCARD}");
            if let Some(route) = table.get(&template) {
                return Some(RouteMatch {
                    route: route.clone(),
                    params: HashMap::new(),
                });
            }
        }

        inner.wildcard.clone().map(|route| RouteMatch {
            route,
            params: HashMap::new(),
        })
    }

    pub fn reset(&self) {
        let mut inner = self.inner.write();
        *inner = RouterInner {
            routes: {
                let mut routes = HashMap::new();
                for m in ["GET", "POST", "PUT", "PATCH", "DELETE"] {
                    routes.insert(m.to_string(), HashMap::new());
                }
                routes
            },
            param_indexes: Vec::new(),
            wildcard: None,
            allow_override: false,
        };
    }
}

pub fn prepare_path(path: &str) -> (String, HashMap<String, usize>) {
    let parts: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
    let mut prepare = String::new();
    let mut params = HashMap::new();
    for (key, part) in parts.iter().enumerate() {
        if key != 0 {
            prepare.push('/');
        }
        if let Some(name) = part.strip_prefix(':') {
            prepare.push_str(PLACEHOLDER);
            params.insert(name.to_string(), key);
        } else {
            prepare.push_str(part);
        }
    }
    (prepare, params)
}

fn combinations(set: &[usize]) -> Vec<Vec<usize>> {
    let mut results = vec![vec![]];
    let mut out = vec![vec![]];
    for &element in set {
        let mut next = Vec::new();
        for combination in &results {
            let mut ret = vec![element];
            ret.extend(combination.iter().copied());
            next.push(ret.clone());
            out.push(ret);
        }
        results.extend(next);
    }
    out
}
