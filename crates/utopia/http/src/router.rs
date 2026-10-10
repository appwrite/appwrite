//! `Utopia\Http\Router`: routes by method and path template.
//!
//! A path `/users/:id` is stored as the template `users/:::` with the param
//! `id` at segment 1. Matching tries the request's segments with
//! placeholders substituted at every combination of param positions, in the
//! order PHP's generator yields them (deepest positions first), so a static
//! segment wins over a placeholder at the leftmost position where two
//! templates differ. A route can be registered under several methods and
//! alias paths; `*` templates match a path prefix; a method-agnostic
//! wildcard catches everything else.

use std::collections::HashMap;

use indexmap::IndexMap;

use crate::error::{Error, Result};

/// `Router::PLACEHOLDER_TOKEN`.
pub const PLACEHOLDER: &str = ":::";
/// `Router::WILDCARD_TOKEN`.
pub const WILDCARD: &str = "*";

/// The methods a route can be registered under.
pub const METHODS: [&str; 5] = ["GET", "POST", "PUT", "PATCH", "DELETE"];

/// Path params parsed from a request path, by name.
pub type PathParams = Vec<(String, String)>;

/// A matched route and its path params (`RouteMatch`).
#[derive(Debug)]
pub struct Match<'a, T> {
    pub route: &'a T,
    /// The route's id in the router.
    pub id: usize,
    pub params: PathParams,
}

struct Entry<T> {
    value: T,
    /// Param name -> segment index, per registered template (`Route::$pathParams`).
    params: IndexMap<String, IndexMap<String, usize>>,
}

/// The routing table.
pub struct Router<T> {
    entries: Vec<Entry<T>>,
    /// Method -> template -> entry.
    routes: HashMap<&'static str, IndexMap<String, usize>>,
    /// Segment positions of params in any template, descending.
    positions: Vec<usize>,
    wildcard: Option<usize>,
    allow_override: bool,
}

impl<T> Default for Router<T> {
    fn default() -> Self {
        Self::new()
    }
}

impl<T> Router<T> {
    pub fn new() -> Self {
        Self {
            entries: Vec::new(),
            routes: METHODS.iter().map(|m| (*m, IndexMap::new())).collect(),
            positions: Vec::new(),
            wildcard: None,
            allow_override: false,
        }
    }

    /// `Router::reset()`.
    pub fn reset(&mut self) {
        *self = Self::new();
    }

    /// `getAllowOverride()`.
    pub fn allow_override(&self) -> bool {
        self.allow_override
    }

    /// `setAllowOverride($value)`.
    pub fn set_allow_override(&mut self, allow: bool) {
        self.allow_override = allow;
    }

    fn method(method: &str) -> Option<&'static str> {
        METHODS.iter().find(|m| **m == method).copied()
    }

    /// `preparePath($path)`: the template and its params. Records the param
    /// positions (even when registration then fails, as PHP does).
    pub fn prepare(&mut self, path: &str) -> (String, IndexMap<String, usize>) {
        let mut template = String::with_capacity(path.len());
        let mut params = IndexMap::new();
        // array_filter drops '' and '0' segments.
        for (key, part) in path.split('/').filter(|s| !s.is_empty() && *s != "0").enumerate() {
            if key != 0 {
                template.push('/');
            }
            if let Some(name) = part.strip_prefix(':') {
                template.push_str(PLACEHOLDER);
                params.insert(part.trim_start_matches(':').to_owned(), key);
                let _ = name;
                if !self.positions.contains(&key) {
                    self.positions.push(key);
                    self.positions.sort_unstable_by(|a, b| b.cmp(a));
                }
            } else {
                template.push_str(part);
            }
        }
        (template, params)
    }

    /// Registers a value without a path (the wildcard route's storage).
    pub fn push(&mut self, value: T) -> usize {
        self.entries.push(Entry { value, params: IndexMap::new() });
        self.entries.len() - 1
    }

    /// `Router::addRoute($route)`: registers `value` for `path` under every
    /// method of `methods` (the first is the primary one).
    pub fn insert(&mut self, methods: &[&str], path: &str, value: T) -> Result<usize> {
        let (template, params) = self.prepare(path);
        let primary = methods.first().copied().unwrap_or("");
        let Some(method) = Self::method(primary) else {
            return Err(Error::Generic(format!("Method ({primary}) not supported.")));
        };
        if self.routes[method].contains_key(&template) && !self.allow_override {
            return Err(Error::Generic(format!("Route for ({method}:{template}) already registered.")));
        }
        let mut additional = Vec::new();
        for extra in &methods[1..] {
            let Some(m) = Self::method(extra) else {
                return Err(Error::Generic(format!("Method ({extra}) not supported.")));
            };
            if path.is_empty() {
                return Err(Error::Generic(
                    "Additional route methods are not supported for the wildcard route.".into(),
                ));
            }
            if self.routes[m].contains_key(&template) && !self.allow_override {
                return Err(Error::Generic(format!("Route for ({m}:{template}) already registered.")));
            }
            additional.push(m);
        }
        let id = self.push(value);
        if !params.is_empty() {
            self.entries[id].params.insert(template.clone(), params);
        }
        self.routes.get_mut(method).map(|r| r.insert(template.clone(), id));
        for m in additional {
            self.routes.get_mut(m).map(|r| r.insert(template.clone(), id));
        }
        Ok(id)
    }

    /// `Router::addRouteAlias($path, $route)`: also registers route `id` for
    /// `path` under its methods.
    pub fn alias(&mut self, id: usize, methods: &[&str], path: &str) -> Result<()> {
        let (template, params) = self.prepare(path);
        let mut resolved = Vec::with_capacity(methods.len());
        for method in methods {
            let Some(m) = Self::method(method) else {
                return Err(Error::Generic(format!("Method ({method}) not supported.")));
            };
            if self.routes[m].contains_key(&template) && !self.allow_override {
                return Err(Error::Generic(format!("Route for ({m}:{template}) already registered.")));
            }
            resolved.push(m);
        }
        if !params.is_empty() {
            self.entries[id].params.insert(template.clone(), params);
        }
        for m in resolved {
            self.routes.get_mut(m).map(|r| r.insert(template.clone(), id));
        }
        Ok(())
    }

    /// `setWildcard($route)`: the route used when nothing else matches.
    pub fn set_wildcard(&mut self, id: Option<usize>) {
        self.wildcard = id;
    }

    /// The value of a route.
    pub fn get(&self, id: usize) -> Option<&T> {
        self.entries.get(id).map(|e| &e.value)
    }

    /// The value of a route, mutably.
    pub fn get_mut(&mut self, id: usize) -> Option<&mut T> {
        self.entries.get_mut(id).map(|e| &mut e.value)
    }

    /// `getRoutes()`: method -> template -> route id.
    pub fn routes(&self, method: &str) -> Option<&IndexMap<String, usize>> {
        Self::method(method).and_then(|m| self.routes.get(m))
    }

    fn found(&self, id: usize, params: PathParams) -> Match<'_, T> {
        Match { route: &self.entries[id].value, id, params }
    }

    /// `Router::match($method, $path)`.
    pub fn find(&self, method: &str, path: &str) -> Option<Match<'_, T>> {
        let Some(table) = Self::method(method).and_then(|m| self.routes.get(m)) else {
            return self.wildcard.map(|id| self.found(id, Vec::new()));
        };
        let parts: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
        let length = parts.len() as i64 - 1;
        let positions: Vec<usize> = self.positions.iter().copied().filter(|&i| (i as i64) <= length).collect();
        let mut template = String::with_capacity(path.len() + 8);
        // Every subset of `positions`, in the order PHP's combinations() yields them.
        let combinations: u64 = if positions.len() >= 63 { u64::MAX } else { 1u64 << positions.len() };
        let mut mask: u64 = 0;
        loop {
            template.clear();
            for (i, part) in parts.iter().enumerate() {
                if i > 0 {
                    template.push('/');
                }
                let placeholder = positions.iter().enumerate().any(|(bit, &pos)| pos == i && mask & (1 << bit) != 0);
                template.push_str(if placeholder { PLACEHOLDER } else { part });
            }
            if let Some(&id) = table.get(&template) {
                return Some(self.found(id, self.resolve(id, path, &template)));
            }
            mask += 1;
            if mask >= combinations {
                break;
            }
        }
        if let Some(&id) = table.get(WILDCARD) {
            return Some(self.found(id, Vec::new()));
        }
        let mut current = String::new();
        for part in &parts {
            current.push_str(part);
            current.push('/');
            let candidate = format!("{current}{WILDCARD}");
            if let Some(&id) = table.get(&candidate) {
                return Some(self.found(id, Vec::new()));
            }
        }
        self.wildcard.map(|id| self.found(id, Vec::new()))
    }

    /// `Route::resolveParams($url, $template)`: the params of route `id` in
    /// `url` (segments counted without dropping empty ones).
    pub fn resolve(&self, id: usize, url: &str, template: &str) -> PathParams {
        let entry = &self.entries[id];
        let params = if template.is_empty() {
            entry.params.get(template).or_else(|| entry.params.values().next())
        } else {
            entry.params.get(template)
        };
        let Some(params) = params else { return Vec::new() };
        let parts: Vec<&str> = url.trim_start_matches('/').split('/').collect();
        params.iter().filter_map(|(name, &index)| parts.get(index).map(|v| (name.clone(), (*v).to_owned()))).collect()
    }

    /// Legacy registration for applications that build routes at startup:
    /// panics when the route is already registered.
    pub fn add(&mut self, method: http::Method, path: &str, value: T) {
        if let Err(e) = self.insert(&[method.as_str()], path, value) {
            panic!("{e}");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn static_wins() {
        let mut r = Router::new();
        r.add(http::Method::GET, "/v1/users/:userId", "get");
        r.add(http::Method::GET, "/v1/users/identities", "identities");
        r.add(http::Method::DELETE, "/v1/users/identities/:identityId", "delete-identity");
        r.add(http::Method::GET, "/v1/users/:userId/sessions", "sessions");
        r.add(http::Method::GET, "/v1/users", "list");

        let m = r.find("GET", "/v1/users/identities").unwrap();
        assert_eq!(*m.route, "identities");
        let m = r.find("GET", "/v1/users/abc/").unwrap();
        assert_eq!(*m.route, "get");
        assert_eq!(m.params, vec![("userId".to_owned(), "abc".to_owned())]);
        let m = r.find("GET", "/v1/users/identities/sessions").unwrap();
        assert_eq!(*m.route, "sessions");
        let m = r.find("GET", "//v1//users").unwrap();
        assert_eq!(*m.route, "list");
        assert!(r.find("POST", "/v1/users/abc").is_none());
    }

    #[test]
    fn overlapping_templates() {
        for order in [["enum", "relationship"], ["relationship", "enum"]] {
            let mut r = Router::new();
            for name in order {
                let path = if name == "enum" {
                    "/v1/databases/:databaseId/collections/:collectionId/attributes/enum/:key"
                } else {
                    "/v1/databases/:databaseId/collections/:collectionId/attributes/:key/relationship"
                };
                r.insert(&["PATCH"], path, name).unwrap();
            }
            let m = r.find("PATCH", "/v1/databases/db/collections/col/attributes/enum/relationship").unwrap();
            assert_eq!(*m.route, "enum");
            assert!(m.params.contains(&("key".to_owned(), "relationship".to_owned())));
        }
    }
}
