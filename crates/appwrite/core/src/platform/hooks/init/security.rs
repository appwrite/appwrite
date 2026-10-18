//! CORS, security headers and origin check (PHP:
//! `app/controllers/general.php`, the `api` group's init hook; hooks.json
//! `security`). The headers themselves are added when the response is sent
//! ([`crate::platform::lifecycle`]'s `finalize`).

use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::platform::hooks::resources::hostnames::{allowed_hostnames, allowed_schemes};
use crate::{Result, network};

/// Turns the security headers on and rejects a browser origin that is not
/// an allowed platform, unless an API key is sent or the route allows any
/// origin.
pub(in crate::platform) fn security(ctx: &mut Context, key_header: &str) -> Result<()> {
    let state = ctx.state.clone();
    // `general.php` adds the security headers after the resources above.
    ctx.secured = true;

    // -- CORS / origin (I4) ----------------------------------------------------
    let origin = ctx.request.header_or_empty("origin").to_owned();
    if !origin.is_empty() && key_header.is_empty() && !ctx.route.labels.any_origin {
        let allowed =
            allowed_hostnames(&state, &ctx.project, ctx.project_found, &ctx.request, ctx.rule_domain.as_deref());
        let schemes = allowed_schemes(&state, &ctx.project, ctx.project_found);
        if let Err(description) = network::validate_origin(&origin, &allowed, &schemes) {
            return Err(Error::with_message(ErrorType::GeneralUnknownOrigin, description));
        }
    }
    Ok(())
}
