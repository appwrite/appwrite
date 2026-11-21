use crate::params::Params;
use crate::route::Route;
use crate::router::PathParams;

/// Where a hook runs: the matched route, its path params and the request params.
#[derive(Clone, Copy)]
pub(super) struct At<'a> {
    pub route: Option<&'a Route>,
    pub values: &'a PathParams,
    pub params: &'a Params,
}
