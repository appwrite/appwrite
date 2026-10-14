//! `PATCH /v1/teams/:teamId/memberships/:membershipId/status`: accepting an
//! invitation.

use utopia_auth::Hash;
use utopia_auth::hashes::Sha;
use utopia_database::helpers::role::Role;
use utopia_database::{Document, Value};
use utopia_http::Response;
use utopia_http::{Cookie, same_site};
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Membership, User, text};
use appwrite_core::database::unique_id;
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::MembershipModel;
use appwrite_core::validators::uid;
use appwrite_core::{Error, ErrorType, Result};

use crate::base;

/// `TOKEN_EXPIRATION_LOGIN_LONG`.
const SESSION_DURATION: i64 = 31_536_000;

/// `proofForToken->hash($secret)`: SHA-256 hex.
fn token_hash(secret: &str) -> Result<String> {
    let hashed = Sha::new().hash(secret.as_bytes()).map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

fn mismatch(user: &User) -> Error {
    Error::with_message(
        ErrorType::TeamInviteMismatch,
        format!("Invite does not belong to current user ({})", user.email.as_deref().unwrap_or("")),
    )
}

/// `PATCH /v1/teams/:teamId/memberships/:membershipId/status`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let membership_id = ctx.required_str("membershipId", &uid())?;
    let user_id = ctx.required_str("userId", &uid())?;
    let secret = ctx.required_str("secret", &Text::new(256))?;
    let db = ctx.db()?;

    let Some(membership) = documents::get::<Membership>(&db, "memberships", &membership_id).await? else {
        return Err(Error::new(ErrorType::MembershipNotFound));
    };
    let authorization = db.authorization();
    let team = authorization.skip(|| db.get_document("teams", &team_id, Vec::new(), false)).await?;
    if team.is_empty() {
        return Err(Error::new(ErrorType::TeamNotFound));
    }
    let team = documents::Team::from(&team);
    if membership.team_internal_id != team.sequence.to_string() {
        return Err(Error::new(ErrorType::TeamMembershipMismatch));
    }
    if membership.secret.as_deref() != Some(token_hash(&secret)?.as_str()) {
        return Err(Error::new(ErrorType::TeamInvalidSecret));
    }
    let signed_in = ctx.roles_user().cloned();
    if user_id != membership.user_id {
        return Err(mismatch(&signed_in.unwrap_or_default()));
    }
    let has_session = signed_in.is_some();
    let target = match signed_in {
        Some(user) => user,
        None => base::user(&db, &user_id).await?.unwrap_or_default(),
    };
    if membership.user_internal_id != target.sequence.to_string() {
        return Err(mismatch(&target));
    }
    if membership.confirm == Some(true) {
        return Err(Error::new(ErrorType::MembershipAlreadyConfirmed));
    }
    let joined = datetime::value(&datetime::now());

    let mut verified = Document::default();
    verified.set("emailVerification", Value::Bool(true));
    authorization.skip(|| db.update_document("users", &target.id, verified)).await?;

    // A session for the invitee when they are not signed in.
    let mut cookies: Vec<Cookie> = Vec::new();
    let mut fallback: Option<String> = None;
    if !has_session {
        ctx.authorization.add_role(&format!("user:{}", target.id));
        let user_agent = ctx.user_agent("UNKNOWN");
        let detected = appwrite_core::detector::detect(&user_agent);
        let duration = ctx.project.auth_int("duration", SESSION_DURATION);
        let expire = datetime::add_seconds(datetime::now(), duration);
        let session_secret = utopia_auth::proofs::Token::default().token();
        let country = ctx.state.geo.country_code(&ctx.ip).await.to_lowercase();
        let mut session = Document::default();
        session.set("$id", Value::from(unique_id().as_str()));
        session.set(
            "$permissions",
            base::permissions(&[
                ("read", Role::user(&target.id, "")),
                ("update", Role::user(&target.id, "")),
                ("delete", Role::user(&target.id, "")),
            ]),
        );
        session.set("userId", Value::from(target.id.as_str()));
        session.set("userInternalId", documents::internal_id(target.sequence));
        session.set("provider", Value::from("email"));
        session.set("providerUid", text(target.email.as_deref()));
        session.set("secret", Value::from(token_hash(&session_secret)?.as_str()));
        session.set("userAgent", Value::from(user_agent.as_str()));
        session.set("ip", Value::from(ctx.ip.as_str()));
        session.set("factors", documents::strings(&["email".to_owned()]));
        session.set("countryCode", Value::from(country.as_str()));
        session.set("expire", datetime::value(&expire));
        session.set("osCode", Value::from(detected.os_code.as_str()));
        session.set("osName", Value::from(detected.os_name.as_str()));
        session.set("osVersion", Value::from(detected.os_version.as_str()));
        session.set("clientType", Value::from(detected.client_type.as_str()));
        session.set("clientCode", Value::from(detected.client_code.as_str()));
        session.set("clientName", Value::from(detected.client_name.as_str()));
        session.set("clientVersion", Value::from(detected.client_version.as_str()));
        session.set("clientEngine", Value::from(detected.client_engine.as_str()));
        session.set("clientEngineVersion", Value::from(detected.client_engine_version.as_str()));
        session.set("deviceName", text(detected.device_name.as_deref()));
        session.set("deviceBrand", text(detected.device_brand.as_deref()));
        session.set("deviceModel", text(detected.device_model.as_deref()));
        db.create_document("sessions", session).await?;
        ctx.authorization.add_role(&format!("user:{user_id}"));

        let encoded = session_store(&target.id, &session_secret)?;
        let key = format!("a_session_{}", ctx.project.id);
        if !domain_verification(ctx) {
            fallback = Some(appwrite_core::json::to_string(&serde_json::json!({ key.clone(): encoded })));
        }
        let expires = expire.and_utc().timestamp();
        let secure = ctx.request.protocol() == "https";
        let domain = cookie_domain(ctx);
        cookies.push(Cookie {
            name: format!("{key}_legacy"),
            value: Some(encoded.clone()),
            expire: Some(expires),
            path: Some("/".to_owned()),
            domain: domain.clone(),
            secure: Some(secure),
            http_only: Some(true),
            same_site: None,
        });
        cookies.push(Cookie {
            name: key,
            value: Some(encoded),
            expire: Some(expires),
            path: Some("/".to_owned()),
            domain,
            secure: Some(secure),
            http_only: Some(true),
            same_site: Some(same_site::NONE.to_owned()),
        });
    }

    let confirmed = db
        .with_transaction(|| async {
            // Re-read under a lock: a concurrent accept of the same invite
            // must not be counted twice.
            let current =
                authorization.skip(|| db.get_document("memberships", &membership.id, Vec::new(), true)).await?;
            if current.get("confirm") == Some(&Value::Bool(true)) {
                return Ok(Document::default());
            }
            let mut updates = Document::default();
            updates.set("joined", joined.clone());
            updates.set("confirm", Value::Bool(true));
            let confirmed = db.update_document("memberships", &membership.id, updates).await?;
            authorization
                .skip(|| db.increase_document_attribute("teams", &team.id, "total", Value::Int(1), None))
                .await?;
            Ok(confirmed)
        })
        .await?;
    if confirmed.is_empty() {
        return Err(Error::new(ErrorType::MembershipAlreadyConfirmed));
    }
    let membership = Membership::from(&confirmed);
    db.purge_cached_document("users", Some(&target.id)).await?;

    ctx.event_param("userId", target.id.clone());
    ctx.event_param("teamId", team.id.clone());
    ctx.event_param("membershipId", membership.id.clone());
    let team_name = team.name.clone().unwrap_or_default();
    let user_name = target.name.clone().unwrap_or_default();
    let user_email = target.email.clone().unwrap_or_default();
    let mut response = ctx.ok(&MembershipModel::new(&membership, &user_name, &user_email, &team_name, ctx.render()));
    if let Some(fallback) = fallback {
        response.add_header("x-fallback-cookies", fallback);
    }
    for cookie in cookies {
        response.add_cookie(cookie);
    }
    Ok(response)
}

/// The session store a client keeps (`Store` with `id` and `secret`), encoded.
fn session_store(id: &str, secret: &str) -> Result<String> {
    let mut store = utopia_auth::Store::new();
    store.set_property(b"id", php_std::Value::from(id)).set_property(b"secret", php_std::Value::from(secret));
    store.encode().map_err(|e| Error::internal(e.to_string()))
}

/// The `domainVerification` resource: the request's origin (or referer) is
/// on the API's registrable domain.
fn domain_verification(ctx: &Context) -> bool {
    let referer = ctx.request.referer("");
    let origin = ctx.request.origin(&referer);
    let origin_host = appwrite_core::network::url_host(&origin).unwrap_or_default();
    let registerable = |host: &str| utopia_domains::Domain::new(host).map(|d| d.registerable()).unwrap_or_default();
    let own = registerable(&ctx.request.hostname());
    let end = registerable(&origin_host);
    own == end && !end.is_empty()
}

/// The `cookieDomain` resource: none for local hosts and IP addresses, else
/// the request's host (the registrable domain for console root sessions).
fn cookie_domain(ctx: &Context) -> Option<String> {
    let port = ctx.request.port();
    let mut local = vec!["localhost".to_owned(), format!("localhost:{port}")];
    if let Some(host) = ctx.state.config.migration_host.as_deref().filter(|h| !h.is_empty()) {
        local.push(host.to_owned());
        local.push(format!("{host}:{port}"));
    }
    let hostname = ctx.request.hostname();
    if local.contains(&hostname) || hostname.parse::<std::net::IpAddr>().is_ok() {
        return None;
    }
    let root = utopia_system_env("_APP_CONSOLE_ROOT_SESSION") == "enabled";
    if ctx.project.is_console() && root {
        let registerable = utopia_domains::Domain::new(&hostname).map(|d| d.registerable()).unwrap_or_default();
        return Some(format!(".{registerable}"));
    }
    Some(format!(".{hostname}"))
}

fn utopia_system_env(name: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| "disabled".to_owned())
}
