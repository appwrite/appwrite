use crate::router::PathParams;

/// A request's match: the route's id and its path params.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RouteMatch {
    /// The route's id (see [`Http::route`](crate::Http::route)).
    pub id: usize,
    /// The path params, by name.
    pub params: PathParams,
}
