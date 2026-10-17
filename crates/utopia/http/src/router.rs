//! The routing table: routes by HTTP method and path template, matched
//! against request paths.
//!
//! A path `/users/:id` is stored as the template `users/:::` with the param
//! `id` at segment 1. Matching tries the request's segments with
//! placeholders substituted at every combination of param positions,
//! deepest positions first, so a static segment wins over a placeholder at
//! the leftmost position where two templates differ. A route can be
//! registered under several methods and alias paths; `*` templates match a
//! path prefix; a method-agnostic wildcard catches everything else.
//!
//! PHP: `Utopia\Http\Router`; combinations are tried in the order PHP's
//! generator yields them.

use std::collections::HashMap;

use indexmap::IndexMap;

use crate::error::{Error, Result};

/// The segment that stands for a param in a stored template.
///
/// PHP: `Router::PLACEHOLDER_TOKEN`.
pub const PLACEHOLDER: &str = ":::";
/// The path segment that matches any path suffix (`/assets/*`) or, alone,
/// any path.
///
/// PHP: `Router::WILDCARD_TOKEN`.
pub const WILDCARD: &str = "*";

/// The methods a route can be registered under.
pub const METHODS: [&str; 5] = ["GET", "POST", "PUT", "PATCH", "DELETE"];

/// Path params parsed from a request path, by name.
pub type PathParams = Vec<(String, String)>;

/// A matched route and its path params.
///
/// PHP: `RouteMatch`.
#[derive(Debug)]
pub struct Match<'a, T> {
    /// The matched route's value.
    pub route: &'a T,
    /// The route's id in the router.
    pub id: usize,
    /// The path params, by name.
    pub params: PathParams,
}

struct Entry<T> {
    value: T,
    /// Param name -> segment index, per registered template (`Route::$pathParams`).
    params: IndexMap<String, IndexMap<String, usize>>,
}

/// The routing table: values of type `T` (an application stores [`Route`](crate::Route)s)
/// by method and path template, each with a stable id.
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
    /// An empty router.
    fn default() -> Self {
        Self::new()
    }
}

impl<T> Router<T> {
    /// An empty router that refuses to override routes.
    pub fn new() -> Self {
        Self {
            entries: Vec::new(),
            routes: METHODS.iter().map(|m| (*m, IndexMap::new())).collect(),
            positions: Vec::new(),
            wildcard: None,
            allow_override: false,
        }
    }

    /// Removes every route and resets the settings.
    ///
    /// PHP: `Router::reset()`.
    pub fn reset(&mut self) {
        *self = Self::new();
    }

    /// Whether registering a route for an already registered method and
    /// template replaces it instead of failing.
    ///
    /// PHP: `getAllowOverride()`.
    pub fn allow_override(&self) -> bool {
        self.allow_override
    }

    /// Sets whether a registration may replace an existing route.
    ///
    /// PHP: `setAllowOverride($value)`.
    pub fn set_allow_override(&mut self, allow: bool) {
        self.allow_override = allow;
    }

    fn method(method: &str) -> Option<&'static str> {
        METHODS.iter().find(|m| **m == method).copied()
    }

    /// The template of a path and its params by segment index: `/users/:id`
    /// gives `users/:::` and `{id: 1}`. Records the param positions for
    /// matching.
    ///
    /// PHP: `preparePath($path)`. Positions are recorded even when
    /// registration then fails, as PHP does.
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

    /// Registers `value` for `path` under every method of `methods` (the
    /// first is the primary one) and returns its id.
    ///
    /// Fails when a method is not one of [`METHODS`], or when the template
    /// is already registered for a method and overriding is not allowed.
    ///
    /// PHP: `Router::addRoute($route)`.
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

    /// Also registers route `id` for `path` under `methods`, with the same
    /// errors as [`Router::insert`].
    ///
    /// PHP: `Router::addRouteAlias($path, $route)`.
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

    /// Sets the route used when nothing else matches, for any method.
    ///
    /// PHP: `setWildcard($route)`.
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

    /// The templates registered for `method`, each with its route id; `None`
    /// for a method outside [`METHODS`].
    ///
    /// PHP: `getRoutes()`.
    pub fn routes(&self, method: &str) -> Option<&IndexMap<String, usize>> {
        Self::method(method).and_then(|m| self.routes.get(m))
    }

    fn found(&self, id: usize, params: PathParams) -> Match<'_, T> {
        Match { route: &self.entries[id].value, id, params }
    }

    /// The route for `method` and `path`, with its path params.
    ///
    /// Templates are tried first, static segments preferred over params;
    /// then a `/*` route for the method, then `prefix/*` routes from the
    /// shortest prefix to the longest, and finally the wildcard route. A
    /// method outside [`METHODS`] only matches the wildcard route.
    ///
    /// PHP: `Router::match($method, $path)`.
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

    /// The path params of route `id` in `url`, read at the segment positions
    /// recorded for `template`. Segments are counted without dropping empty
    /// ones.
    ///
    /// PHP: `Route::resolveParams($url, $template)`.
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

    /// Registers `value` for one method and `path`, for applications that
    /// build routes at startup.
    ///
    /// # Panics
    ///
    /// When [`Router::insert`] fails, such as for a route already
    /// registered.
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
