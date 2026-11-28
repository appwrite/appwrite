use crate::router::PathParams;

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
