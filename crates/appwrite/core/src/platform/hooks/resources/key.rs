//! The `apiKey` resource (PHP: `app/init/resources/request.php`,
//! `$context->set('apiKey'`; hooks.json `resource-key`).

use utopia_database::Database;

use super::team::load_team;
use crate::Result;
use crate::auth::{ApiKey, Owners};
use crate::database::project;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// Decodes `X-Appwrite-Key` and requires the project, user or organization
/// header its type names. Returns the sequence of the organization that owns
/// an organization key (empty for other keys).
pub(in crate::platform) async fn resolve(
    ctx: &mut Context,
    platform_db: &Database,
    header_project: &str,
    key_header: &str,
) -> Result<String> {
    let state = ctx.state.clone();
    // -- API key ---------------------------------------------------------------
    if !key_header.is_empty() {
        // The project's organization (`$team`) and its keys, for an
        // organization key.
        let organization = if key_header.starts_with("organization_") {
            match load_team(platform_db, ctx).await? {
                Some((id, sequence)) => {
                    let keys = project::owned_keys(platform_db, "teams", &sequence).await?;
                    Some((id, sequence, keys))
                }
                None => None,
            }
        } else {
            None
        };
        let account_user = ctx.user.as_ref().map(|u| u.id.clone()).unwrap_or_default();
        let owners = Owners {
            account: (!ctx.account_keys.is_empty()).then_some((account_user.as_str(), ctx.account_keys.as_slice())),
            organization: organization.as_ref().map(|(id, _, keys)| (id.as_str(), keys.as_slice())),
        };
        let key = ApiKey::decode(&ctx.project, key_header, &state.config.openssl_key_v1, &owners);
        if !key.project_id.is_empty() && (header_project.is_empty() || header_project != key.project_id) {
            return Err(Error::new(ErrorType::ProjectIdMissing));
        }
        let user_header = ctx.request.header_or_empty("x-appwrite-user");
        if !key.user_id.is_empty() && (user_header.is_empty() || user_header != key.user_id) {
            return Err(Error::new(ErrorType::UserIdMissing));
        }
        let organization_header = ctx.request.header_or_empty("x-appwrite-organization");
        if !key.team_id.is_empty() && (organization_header.is_empty() || organization_header != key.team_id) {
            return Err(Error::new(ErrorType::OrganizationIdMissing));
        }
        ctx.key = Some(key);
        // The organization's own sequence locks its keys' usage updates.
        return Ok(organization.map(|(_, sequence, _)| sequence).unwrap_or_default());
    }
    Ok(String::new())
}
