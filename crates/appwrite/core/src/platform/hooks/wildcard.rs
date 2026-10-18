//! Unknown routes (PHP: `app/controllers/general.php`, `Http::wildcard()`;
//! hooks.json `wildcard`).

use http::Method;
use utopia_http::Response;

use crate::Result;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::platform::{BoxFuture, Labels, Route};

/// Fallback route for unmatched paths (`general.php` wildcard).
pub(in crate::platform) fn wildcard_route() -> Route {
    fn not_found(_ctx: &mut Context) -> BoxFuture<'_, Result<Response>> {
        Box::pin(async { Err(Error::new(ErrorType::GeneralRouteNotFound)) })
    }
    Route {
        method: Method::GET,
        path: "/*",
        name: "wildcard",
        namespace: "",
        sdk_method: "",
        scopes: &["global"],
        event: None,
        audit: None,
        groups: &["api"],
        labels: Labels::NONE,
        action: not_found,
    }
}
