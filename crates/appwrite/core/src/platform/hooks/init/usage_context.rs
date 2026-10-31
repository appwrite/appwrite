//! The usage context (PHP: `app/controllers/general.php`, the `api` group's
//! init hook that fills `$usage`; hooks.json `usage-context`): the request's
//! metadata, kept for the shutdown hooks (usage, audits, onboarding).

use crate::platform::context::Context;

/// The request's metadata.
pub(in crate::platform) struct RequestInfo {
    pub(in crate::platform) path: String,
    pub(in crate::platform) method: String,
    pub(in crate::platform) user_agent: String,
    pub(in crate::platform) hostname: String,
    pub(in crate::platform) ip: String,
    pub(in crate::platform) sdk: String,
    /// `X-SDK-Name` and `X-SDK-Language` as sent (onboarding's install stages).
    pub(in crate::platform) sdk_name: String,
    pub(in crate::platform) sdk_language: String,
    pub(in crate::platform) sdk_version: String,
    pub(in crate::platform) protocol: String,
    pub(in crate::platform) accept: String,
    pub(in crate::platform) accept_language: String,
    pub(in crate::platform) size: usize,
}

impl RequestInfo {
    /// The metadata of the request `ctx` serves.
    pub(in crate::platform) fn of(ctx: &Context) -> Self {
        let request = &ctx.request;
        let origin_host = request.header("origin").and_then(crate::network::url_host);
        RequestInfo {
            path: request.path().to_owned(),
            method: request.method().to_owned(),
            user_agent: request.header_or_empty("user-agent").to_owned(),
            hostname: origin_host.unwrap_or_else(|| ctx.hostname.clone()),
            ip: ctx.ip.clone(),
            sdk: request.header_or_empty("x-sdk-name").to_lowercase(),
            sdk_name: request.header_or_empty("x-sdk-name").to_owned(),
            sdk_language: request.header_or_empty("x-sdk-language").to_owned(),
            sdk_version: request.header_or_empty("x-sdk-version").to_owned(),
            protocol: request
                .header("x-forwarded-proto")
                .map(|p| p.split(',').next().unwrap_or("").trim().to_lowercase())
                .unwrap_or_else(|| "http".to_owned()),
            accept: request.header_or_empty("accept").to_owned(),
            accept_language: request.header_or_empty("accept-language").to_owned(),
            // PHP's `Request::getSize()` adds `php://input`, which Swoole leaves
            // empty: the body is never counted. Kept for parity (a PHP bug to fix
            // in both runtimes together).
            size: request.size(),
        }
    }
}
