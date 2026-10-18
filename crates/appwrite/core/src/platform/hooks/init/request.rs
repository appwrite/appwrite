//! Router, formats, locale and HTTPS (PHP: `app/controllers/general.php`,
//! the `api` group's init hook; hooks.json `request`): the response format
//! and its warning, the locale, the request filters of an older response
//! format and forced HTTPS.

use bytes::Bytes;
use http::{Method, StatusCode};
use serde_json::Value;
use utopia_http::Response;

use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::platform::hooks::resources::string_param;
use crate::response::Version;
use crate::{Result, VERSION};

/// The response format (`X-Appwrite-Response-Format`) and the locale.
pub(in crate::platform) fn formats(ctx: &mut Context) {
    let state = ctx.state.clone();
    // -- response format, locale -----------------------------------------
    // `version_compare()` against each filter's version (see
    // `Version::from_format`).
    let format = ctx
        .request
        .header("x-appwrite-response-format")
        .map(str::to_owned)
        .or_else(|| state.config.response_format.clone())
        .unwrap_or_default();
    if let Some(f) = Version::from_format(&format) {
        if crate::response::version_compare(&format, VERSION) == std::cmp::Ordering::Greater {
            ctx.response_headers.push((
                "x-appwrite-warning",
                format!(
                    "The current SDK is built for Appwrite {format}. However, the current Appwrite server version is {VERSION}. Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks"
                ),
            ));
        }
        if f < Version::parse(VERSION).expect("valid version") {
            ctx.format = Some(f);
        }
    }
    let locale = string_param(&ctx.request, "locale")
        .unwrap_or_else(|| ctx.request.header_or_empty("x-appwrite-locale").to_owned());
    if !locale.is_empty() && state.translations.has(&locale) {
        ctx.locale = locale;
    }
}

/// The request filters of an older response format.
pub(in crate::platform) async fn filters(ctx: &mut Context) -> Result<()> {
    // Request filters for an older response format: `general.php` adds them,
    // and the first read of the params runs them, before its headers.
    if let Some(format) = ctx.format
        && !ctx.route.namespace.is_empty()
    {
        let model = format!("{}.{}", ctx.route.namespace, ctx.route.sdk_method);
        let mut params = ctx.request.params().clone();
        // `V20` (before 1.8.0) reads the project database and the route's
        // collection.
        let db = if format < Version(1, 8, 0)
            && matches!(model.as_str(), "databases.getDocument" | "databases.listDocuments")
        {
            Some(ctx.db()?)
        } else {
            None
        };
        let path = |key: &str| match ctx.param(key) {
            Some(Value::String(s)) => s.clone(),
            _ => String::new(),
        };
        let related = db.as_ref().map(|db| crate::request::Related {
            db,
            database_id: path("databaseId"),
            collection_id: path("collectionId"),
        });
        crate::request::apply(format, &model, &mut params, related).await?;
        if matches!(ctx.request.method(), "POST" | "PUT" | "PATCH" | "DELETE") {
            ctx.request.set_payload(params);
        } else {
            ctx.request.set_query(params);
        }
    }
    Ok(())
}

/// Forced HTTPS (`_APP_OPTIONS_FORCE_HTTPS`).
pub(in crate::platform) fn force_https(ctx: &mut Context) -> Result<()> {
    let state = ctx.state.clone();
    // -- forced HTTPS ---------------------------------------------------------
    // `_APP_OPTIONS_FORCE_HTTPS`: plain HTTP is redirected (GET) or refused,
    // except on localhost and the migration host (proxies). The redirect is
    // sent here, before the security headers; the hooks after it still run,
    // and the action does not.
    if state.config.force_https && ctx.request.protocol() != "https" {
        let local = local_host(
            ctx.request.header_or_empty("host"),
            &ctx.request.port(),
            state.config.migration_host.as_deref(),
        );
        if !local {
            if ctx.request.method() != Method::GET {
                return Err(Error::with_message(
                    ErrorType::GeneralProtocolUnsupported,
                    "Method unsupported over HTTP. Please use HTTPS instead.",
                ));
            }
            let url = format!("https://{}{}", ctx.request.hostname(), ctx.request.uri());
            let mut response = Response::with_body(StatusCode::MOVED_PERMANENTLY, "text/html", Bytes::new());
            response.add("location", &url);
            ctx.send(response);
        }
    }
    Ok(())
}

/// Whether the `Host` header names a host forced HTTPS leaves alone:
/// `localhost` and the migration host, with or without the port (internal
/// traffic before a public domain is set up).
fn local_host(host: &str, port: &str, migration_host: Option<&str>) -> bool {
    let mut local = vec!["localhost".to_owned(), format!("localhost:{port}")];
    if let Some(migration) = migration_host.filter(|h| !h.is_empty() && *h != "0") {
        local.push(migration.to_owned());
        local.push(format!("{migration}:{port}"));
    }
    local.iter().any(|h| h == host)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn forced_https_spares_local_hosts() {
        assert!(local_host("localhost", "", None));
        assert!(local_host("localhost:8080", "8080", None));
        assert!(!local_host("localhost:8080", "80", None));
        assert!(!local_host("appwrite.io", "443", None));
        assert!(local_host("migration", "80", Some("migration")));
        assert!(local_host("migration:80", "80", Some("migration")));
        assert!(!local_host("0", "80", Some("0")));
    }
}
