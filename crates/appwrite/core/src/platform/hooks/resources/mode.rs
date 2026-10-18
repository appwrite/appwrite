//! The `mode` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('mode'`; hooks.json `resource-mode`).

use super::string_param;
use crate::platform::context::{Context, Mode};

/// `default` or `admin` from the `mode` param or `X-Appwrite-Mode`; admin
/// when the project the request supplied is not the one resolved.
pub(in crate::platform) fn resolve(ctx: &mut Context, supplied_project: &str) {
    // -- mode --------------------------------------------------------------
    let mode = string_param(&ctx.request, "mode")
        .unwrap_or_else(|| ctx.request.header("x-appwrite-mode").unwrap_or("default").to_owned());
    ctx.mode = if mode == "admin" { Mode::Admin } else { Mode::Default };
    if !supplied_project.is_empty() && ctx.project.id != supplied_project {
        ctx.mode = Mode::Admin;
    }
}
