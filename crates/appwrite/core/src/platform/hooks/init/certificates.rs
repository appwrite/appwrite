//! The certificate for the main domain (PHP: `app/controllers/general.php`,
//! the init hook that creates the domain's `rules` document; hooks.json
//! `certificates`).

use utopia_database::{Document, Query};

use crate::Result;
use crate::error::{Error, ErrorType};
use crate::platform::context::Context;

/// `general.php`'s certificate hook: the first request to one of the
/// platform's own hostnames (a known, non-test domain) creates its `rules`
/// document and queues a certificate for it. Each hostname is looked at
/// once per process.
pub(in crate::platform) async fn certify(ctx: &Context) -> Result<()> {
    let state = &ctx.state;
    let hostname = ctx.request.hostname();
    let seen = |name: &str| state.certified.lock().unwrap_or_else(|e| e.into_inner()).contains(name);
    let remember = |name: &str| {
        state.certified.lock().unwrap_or_else(|e| e.into_inner()).insert(name.to_owned());
    };
    if seen(&hostname) {
        return Ok(());
    }
    let domain = utopia_domains::Domain::new(&hostname)
        .map_err(|e| Error::with_message(ErrorType::GeneralUnknown, e.to_string()))?;
    let name = domain.get().to_owned();
    if name.is_empty() || !domain.is_known() || domain.is_test() {
        remember(&name);
        return Ok(());
    }
    if ctx.request.uri().starts_with("/.well-known/acme-challenge") {
        return Ok(());
    }
    if !state.config.platform_hostnames().contains(&name) {
        return Ok(());
    }
    // Whatever happens next, the hostname is not looked at again.
    let issued = issue(ctx, &name).await;
    remember(&name);
    issued
}

/// Creates the `rules` document of a platform hostname and queues its
/// certificate, unless the rule exists.
async fn issue(ctx: &Context, domain: &str) -> Result<()> {
    let state = &ctx.state;
    let platform_db = ctx.platform_db()?;
    let authorization = platform_db.authorization();
    let existing = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        let id = hex::encode(Md5::digest(domain.as_bytes()));
        authorization.skip(platform_db.get_document("rules", &id, Vec::new(), false)).await?
    } else {
        authorization.skip(platform_db.find_one("rules", vec![Query::equal("domain", [domain])])).await?
    };
    if !existing.is_empty() {
        return Ok(());
    }
    // A domain under one of Appwrite's own is Appwrite's.
    let owner =
        if state.config.appwrite_domains.iter().any(|d| domain.ends_with(d.as_str())) { "Appwrite" } else { "" };
    let id = if state.config.rules_md5 {
        use md5::{Digest, Md5};
        hex::encode(Md5::digest(domain.as_bytes()))
    } else {
        crate::database::unique_id()
    };
    let console = state.projects.console();
    let mut rule = Document::default();
    rule.put("$id", id.as_str().into());
    rule.put("domain", domain.into());
    rule.put("type", "api".into());
    rule.put("status", "verifying".into());
    rule.put("projectId", console.id.as_str().into());
    rule.put("projectInternalId", console.sequence.as_str().into());
    rule.put("search", format!("{id} {domain}").as_str().into());
    rule.put("owner", owner.into());
    rule.put("region", console.region.as_str().into());
    let rule = match authorization.skip(platform_db.create_document("rules", rule)).await {
        Ok(rule) => rule,
        Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
            tracing::info!("Certificate already exists");
            return Ok(());
        }
        Err(e) => return Err(e.into()),
    };
    // `RuleCreated` has no listener in CE.
    tracing::info!("Issuing a TLS certificate for the main domain ({domain}) in a few seconds...");
    let message = serde_json::json!({
        "project": {
            "$id": console.id,
            "$sequence": console.sequence,
            "database": console.database,
        },
        "domain": utopia_database::Value::Array(rule.array_copy()).to_json(),
        "skipRenewCheck": true,
        "validationDomain": null,
        "action": "generation",
        "skipDomainValidation": false,
    });
    ctx.enqueue(&state.config.queue_certificates, &message).await;
    Ok(())
}
