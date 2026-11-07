//! The `resourceToken` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('resourceToken'`): the file a resource token grants, read
//! by the `api` group's context hook.

use serde_json::Value;
use utopia_database::Document;

use crate::database::documents::Attributes;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;
use crate::{Result, datetime};

/// `APP_RESOURCE_TOKEN_ACCESS`.
const RESOURCE_TOKEN_ACCESS: i64 = 24 * 60 * 60;
/// `RESOURCE_TOKEN_MAX_AGE` and `RESOURCE_TOKEN_LEEWAY`.
const RESOURCE_TOKEN_MAX_AGE: i64 = 86_400 * 365 * 10;
const RESOURCE_TOKEN_LEEWAY: i64 = 10;

/// The file a resource token grants (the `resourceToken` resource).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResourceToken {
    pub bucket_id: String,
    pub file_id: String,
    pub bucket_internal_id: String,
    pub file_internal_id: String,
}

/// The `resourceToken` resource: the file a `token` parameter (a resource
/// token's JWT) grants, refreshing the token's `accessedAt` once a day.
pub(in crate::platform) async fn resource_token(ctx: &mut Context) -> Result<Option<ResourceToken>> {
    let Some(Value::String(jwt)) = ctx.request.params().get("token").cloned() else { return Ok(None) };
    if jwt.is_empty() || !ctx.project_found {
        return Ok(None);
    }
    let key = ctx.state.config.openssl_key_v1.clone();
    let Ok(claims) = crate::auth::jwt::decode_with_leeway(&key, &jwt, RESOURCE_TOKEN_MAX_AGE, RESOURCE_TOKEN_LEEWAY)
    else {
        return Ok(None);
    };
    let token_id = match claims.get("tokenId") {
        Some(Value::String(s)) if !s.is_empty() && s != "0" => s.clone(),
        _ => return Ok(None),
    };
    let db = ctx.db()?;
    let authorization = db.authorization();
    let token = authorization.skip(db.get_document("resourceTokens", &token_id, Vec::new(), false)).await?;
    if token.is_empty() {
        return Ok(None);
    }
    let now = datetime::now();
    if token.datetime("expire").is_some_and(|expire| expire < now) {
        return Ok(None);
    }
    if token.string_or_empty("resourceType") != "files" {
        return Err(Error::new(ErrorType::TokenResourceTypeInvalid));
    }
    let sequences = token.string_or_empty("resourceInternalId");
    let ids = token.string_or_empty("resourceId");
    let (Some((bucket_internal_id, file_internal_id)), Some((bucket_id, file_id))) = (two(&sequences), two(&ids))
    else {
        return Ok(None);
    };
    if token.datetime("accessedAt").is_none_or(|at| at < datetime::add_seconds(now, -RESOURCE_TOKEN_ACCESS)) {
        let mut updates = Document::default();
        updates.put("accessedAt", datetime::value(&now));
        authorization.skip(db.update_document("resourceTokens", token.id(), updates)).await?;
    }
    Ok(Some(ResourceToken {
        bucket_id: bucket_id.to_owned(),
        file_id: file_id.to_owned(),
        bucket_internal_id: bucket_internal_id.to_owned(),
        file_internal_id: file_internal_id.to_owned(),
    }))
}

/// `explode(':', $value)` of exactly two parts.
fn two(value: &str) -> Option<(&str, &str)> {
    let mut parts = value.split(':');
    match (parts.next(), parts.next(), parts.next()) {
        (Some(a), Some(b), None) => Some((a, b)),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn two_parts_only() {
        assert_eq!(two("a:b"), Some(("a", "b")));
        assert_eq!(two("a"), None);
        assert_eq!(two("a:b:c"), None);
        assert_eq!(two(":"), Some(("", "")));
    }
}
