//! The `allowedHostnames` and `allowedSchemes` resources, and the `rule`
//! resource that adds a custom domain to them (PHP:
//! `app/init/resources/request.php`, `$context->set('allowedHostnames'`;
//! hooks.json `resource-hostnames`).

use http::Method;
use utopia_database::{Database, Query};
use utopia_http::Request;

use crate::database::Project;
use crate::database::documents::Attributes;
use crate::platform::State;
use crate::{Result, network};

/// The `allowedHostnames` resource.
pub(in crate::platform) fn allowed_hostnames(
    ctx_state: &State,
    project: &Project,
    project_found: bool,
    request: &Request,
    rule_domain: Option<&str>,
) -> Vec<String> {
    let mut allowed = ctx_state.config.platform_hostnames();
    if let Some(host) = network::url_host(&ctx_state.config.console_url) {
        allowed.push(host);
    }
    if project_found && !project.is_console() && !project.id.is_empty() {
        allowed.extend(network::platform_hostnames(&project.platforms));
    }
    if request.method() == Method::OPTIONS {
        let origin = request.header("origin").filter(|o| !o.is_empty()).or_else(|| request.header("referer"));
        if let Some(host) = origin.and_then(network::url_host) {
            allowed.push(host);
        }
    }
    if let Some(domain) = rule_domain.filter(|d| !d.is_empty()) {
        allowed.push(domain.to_owned());
    }
    let mut unique: Vec<String> = Vec::with_capacity(allowed.len());
    for h in allowed {
        if !unique.contains(&h) {
            unique.push(h);
        }
    }
    unique
}

/// The `allowedSchemes` resource: the console's schemes, and for a project
/// `exp`, its callback scheme and its platforms' schemes.
pub(in crate::platform) fn allowed_schemes(state: &State, project: &Project, project_found: bool) -> Vec<String> {
    let mut schemes = state.config.console_schemas.clone();
    if project_found && !project.is_console() {
        schemes.push("exp".to_owned());
        schemes.push(format!("appwrite-callback-{}", project.id));
        schemes.extend(network::platform_schemes(&project.platforms));
    }
    let mut unique: Vec<String> = Vec::with_capacity(schemes.len());
    for s in schemes {
        if !unique.contains(&s) {
            unique.push(s);
        }
    }
    unique
}

/// PHP's `rule` resource: the custom domain rule of the request's origin
/// (or referer), when it serves the project or a trusted one.
pub(in crate::platform) async fn rule_domain(
    state: &State,
    platform_db: &Database,
    project: &Project,
    request: &Request,
) -> Result<Option<String>> {
    let source = request.header("origin").and_then(network::url_host).filter(|h| !h.is_empty());
    let Some(domain) =
        source.or_else(|| request.header("referer").and_then(network::url_host)).filter(|h| !h.is_empty())
    else {
        return Ok(None);
    };
    let authorization = platform_db.authorization();
    let rule = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        let id = hex::encode(Md5::digest(domain.as_bytes()));
        authorization.skip(platform_db.get_document("rules", &id, Vec::new(), false)).await?
    } else {
        authorization.skip(platform_db.find_one("rules", vec![Query::equal("domain", [domain.as_str()])])).await?
    };
    if rule.is_empty() {
        return Ok(None);
    }
    // A project without a sequence (the console's) matches no rule.
    let permits = !project.sequence.is_empty() && rule.string_or_empty("projectInternalId") == project.sequence;
    let rule_project = rule.string_or_empty("projectId");
    let trusted = !rule_project.is_empty() && state.config.console_trusted_projects.contains(&rule_project);
    if !permits && !trusted {
        return Ok(None);
    }
    Ok(Some(rule.string_or_empty("domain")))
}
