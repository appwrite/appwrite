//! CORS, security headers and origin check (PHP:
//! `app/controllers/general.php`, the `api` group's init hook; hooks.json
//! `security`). The headers themselves are added when the response is sent
//! ([`crate::platform::lifecycle`]'s `finalize`).

use crate::auth::roles;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::{Result, network};

/// Turns the security headers on and rejects a browser origin that is not
/// an allowed platform, unless a valid, unexpired key of the `keys` role
/// authenticates the request or the route allows any origin.
pub(in crate::platform) fn security(ctx: &mut Context) -> Result<()> {
    // `general.php` adds the security headers after the resources above.
    ctx.secured = true;

    // -- CORS / origin (I4) ----------------------------------------------------
    let origin = ctx.request.header_or_empty("origin").to_owned();
    let authenticated_by_key = ctx.key.as_ref().is_some_and(|k| k.role == roles::KEYS && !k.expired);
    if !origin.is_empty() && !authenticated_by_key && !ctx.route.labels.any_origin {
        let allowed = ctx.allowed_hostnames();
        let schemes = ctx.allowed_schemes();
        if let Err(description) = network::validate_origin(&origin, &allowed, &schemes) {
            return Err(Error::with_message(ErrorType::GeneralUnknownOrigin, description));
        }
    }
    Ok(())
}
