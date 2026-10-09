use std::collections::HashMap;

use http::Method;

/// Path parameters captured during routing (name, raw segment).
pub type PathParams = Vec<(&'static str, String)>;

/// Result of a successful match.
pub struct Match<'a, T> {
    pub route: &'a T,
    pub params: PathParams,
}

struct Node<T> {
    statics: HashMap<String, Node<T>>,
    param: Option<(&'static str, Box<Node<T>>)>,
    routes: Vec<(Method, T)>,
}

impl<T> Default for Node<T> {
    fn default() -> Self {
        Self { statics: HashMap::new(), param: None, routes: Vec::new() }
    }
}

/// Segment trie router.
///
/// Matching rules follow `Utopia\Http\Router`: empty segments are ignored
/// (so trailing slashes do not matter), `:name` segments capture a raw path
/// segment, and at each position a static segment is preferred over a
/// parameter. `HEAD` requests match `GET` routes.
pub struct Router<T> {
    root: Node<T>,
}

impl<T> Default for Router<T> {
    fn default() -> Self {
        Self { root: Node::default() }
    }
}

impl<T> Router<T> {
    pub fn new() -> Self {
        Self::default()
    }

    /// Registers `route` for `method` and `path` (e.g. `/v1/users/:userId`).
    pub fn add(&mut self, method: Method, path: &'static str, route: T) {
        let mut node = &mut self.root;
        for segment in path.split('/').filter(|s| !s.is_empty()) {
            if let Some(name) = segment.strip_prefix(':') {
                let entry = node.param.get_or_insert_with(|| (name, Box::new(Node::default())));
                assert_eq!(entry.0, name, "conflicting parameter names at {path}");
                node = &mut entry.1;
            } else {
                node = node.statics.entry(segment.to_owned()).or_default();
            }
        }
        assert!(!node.routes.iter().any(|(m, _)| *m == method), "duplicate route {method} {path}");
        node.routes.push((method, route));
    }

    /// Finds the route for `method` and `path`.
    pub fn find(&self, method: &Method, path: &str) -> Option<Match<'_, T>> {
        let method = if *method == Method::HEAD { &Method::GET } else { method };
        let segments: Vec<&str> = path.split('/').filter(|s| !s.is_empty()).collect();
        let mut params = Vec::new();
        let route = Self::walk(&self.root, &segments, method, &mut params)?;
        Some(Match { route, params })
    }

    fn walk<'a>(node: &'a Node<T>, segments: &[&str], method: &Method, params: &mut PathParams) -> Option<&'a T> {
        let Some((first, rest)) = segments.split_first() else {
            return node.routes.iter().find(|(m, _)| m == method).map(|(_, r)| r);
        };
        if let Some(child) = node.statics.get(*first)
            && let Some(found) = Self::walk(child, rest, method, params)
        {
            return Some(found);
        }
        if let Some((name, child)) = &node.param {
            params.push((name, (*first).to_owned()));
            if let Some(found) = Self::walk(child, rest, method, params) {
                return Some(found);
            }
            params.pop();
        }
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn static_wins() {
        let mut r = Router::new();
        r.add(Method::GET, "/v1/users/:userId", "get");
        r.add(Method::GET, "/v1/users/identities", "identities");
        r.add(Method::DELETE, "/v1/users/identities/:identityId", "delete-identity");
        r.add(Method::GET, "/v1/users/:userId/sessions", "sessions");
        r.add(Method::GET, "/v1/users", "list");

        let m = r.find(&Method::GET, "/v1/users/identities").unwrap();
        assert_eq!(*m.route, "identities");
        let m = r.find(&Method::GET, "/v1/users/abc/").unwrap();
        assert_eq!(*m.route, "get");
        assert_eq!(m.params, vec![("userId", "abc".to_owned())]);
        let m = r.find(&Method::GET, "/v1/users/identities/sessions").unwrap();
        assert_eq!(*m.route, "sessions");
        let m = r.find(&Method::HEAD, "//v1//users").unwrap();
        assert_eq!(*m.route, "list");
        assert!(r.find(&Method::POST, "/v1/users/abc").is_none());
    }
}
