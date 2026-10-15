//! `POST/GET /v1/teams/:teamId/memberships`,
//! `GET/PATCH/DELETE /v1/teams/:teamId/memberships/:membershipId`.

use serde_json::{Map, Value as Json, json};
use utopia_auth::Hash;
use utopia_auth::hashes::{Argon2, Sha};
use utopia_database::PermissionType;
use utopia_database::Role;
use utopia_database::value::from_plain;
use utopia_database::{Database, Document, Kind, Query, Value};
use utopia_emails::{Email, EmailValidator};
use utopia_http::Response;
use utopia_validators::Text;

use appwrite_core::database::documents::{self, Membership, User, text};
use appwrite_core::database::{LIMIT_COUNT, collections, unique_id};
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::{MembershipListModel, MembershipModel};
use appwrite_core::template::{self, Template};
use appwrite_core::validators::{CustomId, Phone, roles, uid};
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, Member, Privacy};

/// `APP_LIMIT_USERS`.
const LIMIT_USERS: i64 = 10_000;
/// `APP_BRANDED_EMAIL_BASE_TEMPLATE`.
const BRANDED_TEMPLATE: &str = "email-base-styled";
/// `APP_CE_CONFIG_DIR` in the worker's container, where the mail worker
/// reads the body template.
const WORKER_CONFIG_DIR: &str = "/usr/src/code/app/init/../config";

/// The proof Appwrite hashes passwords with (`proofForPassword`).
fn password_hash() -> Argon2 {
    let mut argon2 = Argon2::new();
    argon2.set_memory_cost(7168).set_time_cost(5).set_threads(1);
    argon2
}

/// `proofForToken->hash($secret)`: SHA-256 hex.
fn token_hash(secret: &str) -> Result<String> {
    let hashed = Sha::new().hash(secret.as_bytes()).map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// The roles parameter (`ArrayList(Key(81), 100)`), required.
fn roles_param(ctx: &Context) -> Result<Vec<String>> {
    let value = ctx.required("roles", &roles())?;
    Ok(value.as_array().into_iter().flatten().filter_map(Json::as_str).map(str::to_owned).collect())
}

/// A string from a validated optional parameter (`''` for null).
fn string(value: Json) -> String {
    match value {
        Json::String(s) => s,
        Json::Null => String::new(),
        other => utopia_validators::php::to_string(&other).unwrap_or_default(),
    }
}

/// `POST /v1/teams/:teamId/memberships`.
pub async fn create(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let mut email = string(ctx.optional("email", json!(""), &EmailValidator::default())?);
    let user_id = string(ctx.optional("userId", json!(""), &uid())?);
    let mut phone = string(ctx.optional("phone", json!(""), &Phone::default())?);
    let roles = roles_param(ctx)?;
    let url = match ctx.param("url") {
        None | Some(Json::Null) => String::new(),
        Some(Json::String(url)) => {
            ctx.validate_redirect(url).map_err(|d| Error::invalid_param("url", &d))?;
            url.clone()
        }
        Some(_) => return Err(Error::invalid_param("url", "Invalid URI.")),
    };
    let mut name = string(ctx.optional("name", json!(""), &Text::new(128))?);
    let membership_id = string(ctx.optional("membershipId", json!(""), &CustomId)?);

    let app = ctx.is_key();
    let privileged = ctx.is_privileged();
    let server = app || privileged;

    if url.is_empty() && !server {
        return Err(Error::with_message(ErrorType::GeneralArgumentInvalid, "URL is required"));
    }
    if user_id.is_empty() && email.is_empty() && phone.is_empty() {
        return Err(Error::with_message(
            ErrorType::GeneralArgumentInvalid,
            "At least one of userId, email, or phone is required",
        ));
    }
    if !server && ctx.state.config.smtp_host.is_empty() {
        return Err(Error::new(ErrorType::GeneralSmtpDisabled));
    }

    email = email.to_lowercase();
    if name.is_empty() {
        name = email.clone();
    }
    let canonical_emails =
        ctx.plan_allows("supportsCanonicalEmailValidation") && ctx.project.auth_bool("canonicalEmails", false);
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    let team_name = team.name.clone().unwrap_or_default();

    let mut invitee: Option<User> = None;
    if !user_id.is_empty() {
        let Some(found) = base::user(&db, &user_id).await? else {
            return Err(Error::with_code(ErrorType::UserNotFound, "User with given userId doesn't exist.", 404));
        };
        if !email.is_empty() && found.email.as_deref().unwrap_or("") != email {
            return Err(Error::with_code(ErrorType::UserAlreadyExists, "Given userId and email doesn't match", 409));
        }
        if !phone.is_empty() && found.phone.as_deref().unwrap_or("") != phone {
            return Err(Error::with_code(ErrorType::UserAlreadyExists, "Given userId and phone doesn't match", 409));
        }
        email = found.email.clone().unwrap_or_default();
        phone = found.phone.clone().unwrap_or_default();
        if let Some(n) = found.name.as_deref().filter(|n| !n.is_empty()) {
            name = n.to_owned();
        }
        invitee = Some(found);
    } else if !email.is_empty() {
        invitee = find_user(&db, "email", &email).await?;
        // Aliased emails are denied at sign-up, so the account holds the
        // unaliased address. Keep the typed domain as OAuth does.
        if invitee.is_none() && canonical_emails {
            let unaliased = Email::new(&email)
                .ok()
                .and_then(|parsed| {
                    let canonical = parsed.canonical().ok()?;
                    let local = canonical.split('@').next().unwrap_or("").to_owned();
                    Some(format!("{local}@{}", parsed.domain()))
                })
                .unwrap_or_else(|| email.clone());
            if unaliased != email {
                invitee = find_user(&db, "email", &unaliased).await?;
            }
        }
        if let Some(found) = &invitee
            && !phone.is_empty()
            && found.phone.as_deref().unwrap_or("") != phone
        {
            return Err(Error::with_code(ErrorType::UserAlreadyExists, "Given email and phone doesn't match", 409));
        }
    } else if !phone.is_empty() {
        invitee = find_user(&db, "phone", &phone).await?;
    }

    let mut invitee_created = false;
    let invitee = match invitee {
        Some(found) => found,
        None => {
            invitee_created = true;
            create_invitee(ctx, &db, server, &email, &phone, &name).await?
        }
    };

    let owner = ctx.authorization.has_role(&format!("team:{}/owner", team.id));
    // Same console rule as membership role updates: an organization
    // developer is not privileged enough to invite with arbitrary roles.
    if !owner && !app && (ctx.project.is_console() || !privileged) {
        return Err(Error::with_message(
            ErrorType::UserUnauthorized,
            "User is not allowed to send invitations for this team",
        ));
    }

    let existing = db
        .find_one(
            "memberships",
            vec![
                Query::equal("userInternalId", [invitee.sequence.to_string()]),
                Query::equal("teamInternalId", [team.sequence.to_string()]),
            ],
        )
        .await?;

    let secret = utopia_auth::proofs::Token::default().token();
    let secret_hash = token_hash(&secret)?;
    let authorization = db.authorization();
    let membership: Document = if existing.is_empty() {
        let membership_id =
            if membership_id.is_empty() || membership_id == "unique()" { unique_id() } else { membership_id };
        let now = datetime::value(&datetime::now());
        let mut membership = Document::default();
        membership.put("$id", Value::from(membership_id.as_str()));
        membership.put(
            "$permissions",
            base::permissions(&[
                ("read", Role::any()),
                ("update", Role::user(&invitee.id, "")),
                ("update", Role::team(&team.id, "owner")),
                ("delete", Role::user(&invitee.id, "")),
                ("delete", Role::team(&team.id, "owner")),
            ]),
        );
        membership.put("userId", Value::from(invitee.id.as_str()));
        membership.put("userInternalId", documents::internal_id(invitee.sequence));
        membership.put("teamId", Value::from(team.id.as_str()));
        membership.put("teamInternalId", documents::internal_id(team.sequence));
        membership.put("roles", documents::strings(&roles));
        membership.put("invited", now.clone());
        membership.put("joined", if server { now } else { Value::Null });
        membership.put("confirm", Value::Bool(server));
        membership.put("secret", Value::from(secret_hash.as_str()));
        membership.put("search", Value::from(format!("{membership_id} {}", invitee.id).as_str()));

        let created = if server {
            db.with_transaction(|| async {
                let created = authorization.skip(db.create_document("memberships", membership.clone())).await?;
                authorization
                    .skip(db.increase_document_attribute("teams", &team.id, "total", Value::Int(1), None))
                    .await?;
                Ok(created)
            })
            .await
        } else {
            db.create_document("memberships", membership).await
        };
        match created {
            Ok(created) => created,
            Err(e) if e.is_a(Kind::Duplicate) => {
                if invitee_created {
                    let count = authorization
                        .skip(db.count(
                            "memberships",
                            vec![Query::equal("userInternalId", [invitee.sequence.to_string()])],
                            Some(1),
                        ))
                        .await?;
                    if count == 0 {
                        authorization.skip(db.delete_document("users", &invitee.id)).await?;
                    }
                }
                return Err(Error::new(ErrorType::MembershipAlreadyExists));
            }
            Err(e) => return Err(e.into()),
        }
    } else if existing.get("confirm") == Some(&Value::Bool(false)) {
        let invited = datetime::value(&datetime::now());
        if server {
            let id = existing.id().to_owned();
            let confirmed = db
                .with_transaction(|| async {
                    // Re-read under a lock: a concurrent invite must not count the same member twice.
                    let current = authorization.skip(db.get_document("memberships", &id, Vec::new(), true)).await?;
                    if current.get("confirm") == Some(&Value::Bool(true)) {
                        return Ok(Document::default());
                    }
                    let mut updates = Document::default();
                    updates.put("secret", Value::from(secret_hash.as_str()));
                    updates.put("invited", invited.clone());
                    updates.put("joined", datetime::value(&datetime::now()));
                    updates.put("confirm", Value::Bool(true));
                    let confirmed = authorization.skip(db.update_document("memberships", &id, updates)).await?;
                    authorization
                        .skip(db.increase_document_attribute("teams", &team.id, "total", Value::Int(1), None))
                        .await?;
                    Ok(confirmed)
                })
                .await?;
            if confirmed.is_empty() {
                return Err(Error::new(ErrorType::MembershipAlreadyConfirmed));
            }
            confirmed
        } else {
            let mut updates = Document::default();
            updates.put("secret", Value::from(secret_hash.as_str()));
            updates.put("invited", invited);
            db.update_document("memberships", existing.id(), updates).await?
        }
    } else {
        return Err(Error::new(ErrorType::MembershipAlreadyConfirmed));
    };
    let membership = Membership::from(&membership);

    if server {
        db.purge_cached_document("users", &invitee.id).await?;
    } else {
        let link = template::with_query(
            &url,
            &[
                ("membershipId", &membership.id),
                ("userId", &invitee.id),
                ("secret", &secret),
                ("teamId", &team_id),
                ("teamName", &team_name),
            ],
        );
        if !email.is_empty() {
            send_invitation(ctx, &invitee, &name, &team_name, &link).await?;
        } else if !phone.is_empty() {
            send_sms(ctx, &phone, &link).await?;
        }
    }

    ctx.event_param("userId", invitee.id.clone());
    ctx.event_param("teamId", team.id.clone());
    ctx.event_param("membershipId", membership.id.clone());
    let user_name = invitee.name.clone().unwrap_or_default();
    let user_email = invitee.email.clone().unwrap_or_default();
    Ok(ctx.created(&MembershipModel::new(&membership, &user_name, &user_email, &team_name, ctx.render())))
}

/// `findOne('users', [equal($attribute, [$value])])`.
async fn find_user(db: &Database, attribute: &str, value: &str) -> Result<Option<User>> {
    let found = db.find_one("users", vec![Query::equal(attribute, [value])]).await?;
    Ok(if found.is_empty() { None } else { Some(User::from(&found)) })
}

/// Creates the user an invitation is sent to, when none matches.
async fn create_invitee(
    ctx: &Context,
    db: &Database,
    server: bool,
    email: &str,
    phone: &str,
    name: &str,
) -> Result<User> {
    let limit = ctx.project.auth_int("limit", 0);
    // Console invites are always allowed.
    if !server && limit != 0 && !ctx.project.is_console() {
        let total = db.count("users", Vec::new(), Some(LIMIT_USERS)).await?;
        if total >= limit {
            return Err(Error::with_message(
                ErrorType::UserCountExceeded,
                "Project registration is restricted. Contact your administrator for more information.",
            ));
        }
    }
    // The email must not be another user's identity.
    if !db.find_one("identities", vec![Query::equal("providerEmail", [email])]).await?.is_empty() {
        return Err(Error::new(ErrorType::UserEmailAlreadyExists));
    }

    let parsed = Email::new(email).ok();
    let canonical = parsed.as_ref().and_then(|p| p.canonical().ok());
    let metadata = match (&parsed, &canonical) {
        (Some(p), Some(c)) => {
            Some((c.clone(), p.get() == c.as_str(), p.is_corporate(), p.is_disposable(), p.is_free()))
        }
        _ => None,
    };
    let project = &ctx.project;
    let (is_canonical, is_corporate, is_disposable, is_free) =
        metadata.as_ref().map(|m| (Some(m.1), Some(m.2), Some(m.3), Some(m.4))).unwrap_or_default();
    if ctx.plan_allows("supportsDisposableEmailValidation")
        && project.auth_bool("disposableEmails", false)
        && is_disposable == Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailDisposable));
    }
    if ctx.plan_allows("supportsCanonicalEmailValidation")
        && project.auth_bool("canonicalEmails", false)
        && is_canonical == Some(false)
    {
        return Err(Error::new(ErrorType::UserEmailNotCanonical));
    }
    if ctx.plan_allows("supportsFreeEmailValidation") && project.auth_bool("freeEmails", false) && is_free == Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailFree));
    }
    if ctx.plan_allows("supportsCorporateEmailValidation")
        && project.auth_bool("corporateEmails", false)
        && !is_corporate.unwrap_or(true)
    {
        return Err(Error::new(ErrorType::UserEmailNotCorporate));
    }

    let hash = password_hash();
    let password = utopia_auth::proofs::Token::default().token();
    let hashed = hash_password(password).await?;
    let user_id = unique_id();
    let flag = |b: Option<bool>| b.map(Value::Bool).unwrap_or(Value::Null);
    let mut user = Document::default();
    user.put("$id", Value::from(user_id.as_str()));
    user.put(
        "$permissions",
        base::permissions(&[
            ("read", Role::any()),
            ("read", Role::user(&user_id, "")),
            ("update", Role::user(&user_id, "")),
            ("delete", Role::user(&user_id, "")),
        ]),
    );
    user.put("email", text(Some(email).filter(|e| !e.is_empty())));
    user.put("phone", text(Some(phone).filter(|p| !p.is_empty())));
    user.put("emailVerification", Value::Bool(false));
    user.put("status", Value::Bool(true));
    user.put("password", Value::from(hashed.as_str()));
    user.put("hash", Value::from(hash.name()));
    user.put("hashOptions", from_plain(php_std::Value::Array(hash.options().as_array().clone())));
    // No password update time: a user invited (or signed in with OAuth)
    // may set a password without the old one.
    user.put("passwordUpdate", Value::Null);
    user.put("registration", datetime::value(&datetime::now()));
    user.put("reset", Value::Bool(false));
    user.put("name", Value::from(name));
    user.put("prefs", Value::Object(Default::default()));
    user.put("sessions", Value::Null);
    user.put("tokens", Value::Null);
    user.put("memberships", Value::Null);
    user.put("search", Value::from(format!("{user_id} {email} {name}").as_str()));
    user.put("emailCanonical", text(canonical.as_deref().filter(|_| metadata.is_some())));
    user.put("emailIsCanonical", flag(is_canonical));
    user.put("emailIsCorporate", flag(is_corporate));
    user.put("emailIsDisposable", flag(is_disposable));
    user.put("emailIsFree", flag(is_free));

    match db.authorization().skip(db.create_document("users", user)).await {
        Ok(created) => Ok(User::from(&created)),
        Err(e) if e.is_a(Kind::Duplicate) => Err(Error::new(ErrorType::UserAlreadyExists)),
        Err(e) => Err(e.into()),
    }
}

/// Hashes a password on the blocking pool.
async fn hash_password(password: String) -> Result<String> {
    let hashed = tokio::task::spawn_blocking(move || password_hash().hash(password.as_bytes()))
        .await
        .map_err(|e| Error::internal(e.to_string()))?
        .map_err(|e| Error::internal(e.to_string()))?;
    String::from_utf8(hashed).map_err(|e| Error::internal(e.to_string()))
}

/// `Utopia\Storage\Validator\FileName`.
fn file_name(name: &str) -> bool {
    if name.is_empty() || name == "0" {
        return false;
    }
    let stripped: Vec<u8> = name.bytes().filter(|b| !matches!(b, b'.' | b'-' | b'_')).collect();
    !stripped.is_empty() && stripped.iter().all(u8::is_ascii_alphanumeric)
}

/// `$value ?? ''` of a JSON object field, as a string.
fn field<'a>(object: &'a Json, key: &str) -> &'a str {
    object.get(key).and_then(Json::as_str).unwrap_or("")
}

/// Sends the invitation email (`publisherForMails`), from the project's SMTP
/// server and template when it has them.
async fn send_invitation(ctx: &mut Context, invitee: &User, name: &str, team_name: &str, link: &str) -> Result<()> {
    let state = ctx.state.clone();
    let project = ctx.project.clone();
    let platform = &state.config.platform;
    let project_name = if project.is_console() {
        field(platform, "platformName").to_owned()
    } else if project.name.is_empty() {
        "[APP-NAME]".to_owned()
    } else {
        project.name.clone()
    };

    let mut base_template = project.smtp_base_template.clone().unwrap_or_else(|| "email-base".to_owned());
    if !file_name(&base_template) {
        return Err(Error::with_message(ErrorType::GeneralBadRequest, "Invalid template path"));
    }
    let local = |name: &str| state.config.asset(&format!("app/config/locale/templates/{name}.tpl"));
    if !local(&base_template).is_file() {
        base_template = "email-base".to_owned();
    }
    let body_template = format!("{WORKER_CONFIG_DIR}/locale/templates/{base_template}.tpl");

    let mut subject = ctx.text("emails.invitation.subject", None);
    let preview = ctx.text("emails.invitation.preview", None);
    let inner = std::fs::read_to_string(local("email-inner-base")).map_err(|e| Error::internal(e.to_string()))?;
    let mut body = Template::new(inner)
        .param("{{body}}", &ctx.text("emails.invitation.body", None), false)
        .param("{{hello}}", &ctx.text("emails.invitation.hello", None), true)
        .param("{{footer}}", &ctx.text("emails.invitation.footer", None), true)
        .param("{{thanks}}", &ctx.text("emails.invitation.thanks", None), true)
        .param("{{buttonText}}", &ctx.text("emails.invitation.buttonText", None), true)
        .param("{{signature}}", &ctx.text("emails.invitation.signature", None), true)
        .render();

    let templates = &project.templates;
    let custom = templates
        .get(format!("email.invitation-{}", ctx.locale))
        .or_else(|| templates.get("email.invitation-en"))
        .filter(|t| !t.is_null())
        .cloned()
        .unwrap_or(Json::Array(Vec::new()));
    let custom_set = utopia_validators::php::truthy(&custom);

    let smtp = &project.smtp;
    let smtp_enabled = smtp.get("enabled").is_some_and(utopia_validators::php::truthy);
    let mut sender_email = state.config.system_email_address.clone();
    let mut sender_name = state.config.system_email_name.clone();
    let mut reply_to_email = String::new();
    let mut reply_to_name = String::new();
    let mut smtp_config = Json::Array(Vec::new());
    if smtp_enabled {
        let set = |target: &mut String, value: &str| {
            if !value.is_empty() && value != "0" {
                *target = value.to_owned();
            }
        };
        set(&mut sender_email, field(smtp, "senderEmail"));
        set(&mut sender_name, field(smtp, "senderName"));
        // Includes backwards compatibility: the legacy `replyTo` key.
        let reply_to = smtp.get("replyToEmail").or_else(|| smtp.get("replyTo")).and_then(Json::as_str).unwrap_or("");
        set(&mut reply_to_email, reply_to);
        set(&mut reply_to_name, field(smtp, "replyToName"));
        if custom_set {
            set(&mut sender_email, field(&custom, "senderEmail"));
            set(&mut sender_name, field(&custom, "senderName"));
            let reply_to =
                custom.get("replyToEmail").or_else(|| custom.get("replyTo")).and_then(Json::as_str).unwrap_or("");
            set(&mut reply_to_email, reply_to);
            set(&mut reply_to_name, field(&custom, "replyToName"));
            body = field(&custom, "message").to_owned();
            if let Some(s) = custom.get("subject").and_then(Json::as_str) {
                subject = s.to_owned();
            }
        }
        let or_empty = |key: &str| smtp.get(key).cloned().filter(|v| !v.is_null()).unwrap_or(json!(""));
        smtp_config = json!({
            "host": or_empty("host"),
            "port": or_empty("port"),
            "username": or_empty("username"),
            "password": or_empty("password"),
            "secure": or_empty("secure"),
            "replyToEmail": reply_to_email,
            "replyToName": reply_to_name,
            "senderEmail": sender_email,
            "senderName": sender_name,
        });
    }

    let owner = ctx.user.as_ref().and_then(|u| u.name.clone());
    let mut variables = Map::new();
    variables.insert("owner".into(), owner.map(Json::String).unwrap_or(Json::Null));
    variables.insert("direction".into(), Json::String(ctx.text("settings.direction", None)));
    // {{user}}, {{team}}, {{redirect}} and {{project}} are required in default and custom templates.
    variables.insert("user".into(), Json::String(name.to_owned()));
    variables.insert("team".into(), Json::String(team_name.to_owned()));
    variables.insert("redirect".into(), Json::String(link.to_owned()));
    variables.insert("project".into(), Json::String(project_name));
    let branded = base_template == BRANDED_TEMPLATE;
    if branded {
        for (key, from) in [
            ("accentColor", "accentColor"),
            ("logoUrl", "logoUrl"),
            ("twitter", "twitterUrl"),
            ("discord", "discordUrl"),
            ("github", "githubUrl"),
            ("terms", "termsUrl"),
            ("privacy", "privacyUrl"),
            ("platform", "platformName"),
        ] {
            variables.insert(key.into(), platform.get(from).cloned().unwrap_or(Json::Null));
        }
    }
    let custom_options =
        if branded { json!({"senderName": platform.get("emailSenderName")}) } else { Json::Array(Vec::new()) };

    let message = json!({
        "project": project.message_payload(),
        "recipient": invitee.email,
        "name": invitee.name.clone().unwrap_or_default(),
        "subject": subject,
        "template": "invitation",
        "bodyTemplate": body_template,
        "body": body,
        "preview": preview,
        "smtp": smtp_config,
        "variables": variables,
        "attachment": [],
        "customMailOptions": custom_options,
        "events": [],
        "platform": platform,
        "headers": [],
    });
    ctx.events.queued.push((state.config.queue_mails.clone(), message));
    Ok(())
}

/// Sends the invitation link by SMS (`publisherForMessaging`).
async fn send_sms(ctx: &mut Context, phone: &str, link: &str) -> Result<()> {
    let state = ctx.state.clone();
    if state.config.sms_provider.is_empty() {
        return Err(Error::with_message(ErrorType::GeneralPhoneDisabled, "Phone provider not configured"));
    }
    let base = std::fs::read_to_string(state.config.asset("app/config/locale/templates/sms-base.tpl"))
        .map_err(|e| Error::internal(e.to_string()))?;
    let content = Template::new(base).param("{{token}}", link, false).render();
    let message = json!({
        "type": "internal",
        "project": ctx.project.message_payload(),
        "messageId": null,
        "message": {"$id": unique_id(), "data": {"content": content}},
        "recipients": [phone],
        "providerType": "SMS",
    });
    ctx.events.queued.push((state.config.queue_messaging.clone(), message));
    if let Some(code) = appwrite_core::validators::calling_code(phone) {
        ctx.metric(&format!("auth.method.phone.{code}"), 1);
    }
    ctx.metric("auth.method.phone", 1);
    Ok(())
}

/// `GET /v1/teams/:teamId/memberships`.
pub async fn list(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let mut queries = ctx.queries(&collections::MEMBERSHIPS)?;
    let search = ctx.optional_str("search", "", &Text::new(256))?.unwrap_or_default();
    let include_total = ctx.optional_bool("total", true)?;
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;

    if !search.is_empty() {
        queries.push(Query::search("search", &search));
    }
    queries.push(Query::equal("teamInternalId", [team.sequence.to_string()]));
    base::resolve_cursor(&db, "memberships", &mut queries, "Membership").await?;
    let filters = Query::group_by_type(&queries)?.filters;
    let memberships: Vec<Membership> = documents::all(&db.find("memberships", queries, PermissionType::Read).await?);
    let total = if include_total { db.count("memberships", filters, Some(LIMIT_COUNT)).await? } else { 0 };
    let memberships: Vec<Membership> = memberships.into_iter().filter(|m| !m.user_id.is_empty()).collect();

    let policy = Privacy::of(ctx);
    let mut members = Vec::with_capacity(memberships.len());
    for membership in &memberships {
        // The policy only hides other members: a member always sees their own details.
        let privacy = if base::is_self(ctx, membership) { Privacy::ALL } else { policy };
        let user = if privacy.any() { base::user(&db, &membership.user_id).await? } else { None };
        members.push(Member::of(user.as_ref(), privacy));
    }
    let team_name = team.name.clone().unwrap_or_default();
    let render = ctx.render();
    let models: Vec<MembershipModel<'_>> =
        memberships.iter().zip(members.iter()).map(|(m, member)| member.model(m, &team_name, render)).collect();
    Ok(ctx.ok(&MembershipListModel { total, memberships: models }))
}

/// `GET /v1/teams/:teamId/memberships/:membershipId`.
pub async fn get(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let membership_id = ctx.required_str("membershipId", &uid())?;
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    let membership = match documents::get::<Membership>(&db, "memberships", &membership_id).await? {
        Some(m) if !m.user_id.is_empty() => m,
        _ => return Err(Error::new(ErrorType::MembershipNotFound)),
    };
    if membership.team_internal_id != team.sequence.to_string() {
        return Err(Error::new(ErrorType::TeamMembershipMismatch));
    }
    // The policy only hides other members: a member always sees their own details.
    let privacy = if base::is_self(ctx, &membership) { Privacy::ALL } else { Privacy::of(ctx) };
    let user = if privacy.any() { base::user(&db, &membership.user_id).await? } else { None };
    let member = Member::of(user.as_ref(), privacy);
    let team_name = team.name.clone().unwrap_or_default();
    Ok(ctx.ok(&member.model(&membership, &team_name, ctx.render())))
}

/// `PATCH /v1/teams/:teamId/memberships/:membershipId`.
pub async fn update(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let membership_id = ctx.required_str("membershipId", &uid())?;
    let roles = roles_param(ctx)?;
    let db = ctx.db()?;
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    let Some(membership) = documents::get::<Membership>(&db, "memberships", &membership_id).await? else {
        return Err(Error::new(ErrorType::MembershipNotFound));
    };
    if membership.team_internal_id != team.sequence.to_string() {
        return Err(Error::new(ErrorType::TeamMembershipMismatch));
    }
    let Some(profile) = base::user(&db, &membership.user_id).await? else {
        return Err(Error::new(ErrorType::UserNotFound));
    };

    let privileged = ctx.is_privileged();
    let app = ctx.is_key();
    let owner = ctx.authorization.has_role(&format!("team:{}/owner", team.id));
    if ctx.project.is_console() {
        // Up to 2 owners tell whether only one exists.
        let owners = db
            .count(
                "memberships",
                vec![Query::contains("roles", ["owner"]), Query::equal("teamInternalId", [team.sequence.to_string()])],
                Some(2),
            )
            .await?;
        let own = ctx.user.as_ref().is_some_and(|u| u.sequence.to_string() == membership.user_internal_id);
        if owners == 1 && owner && own && !roles.iter().any(|r| r == "owner") {
            return Err(Error::with_message(
                ErrorType::MembershipDowngradeProhibited,
                "There must be at least one owner in the organization.",
            ));
        }
    }
    // Console organization developers and admins are bare privileged roles
    // under `X-Appwrite-Organization`: only the team owner or a key may
    // change roles.
    if !owner && !app && (ctx.project.is_console() || !privileged) {
        return Err(Error::with_message(ErrorType::UserUnauthorized, "User is not allowed to modify roles"));
    }

    let mut updates = Document::default();
    updates.put("roles", documents::strings(&roles));
    let membership = Membership::from(&db.update_document("memberships", &membership.id, updates).await?);
    db.purge_cached_document("users", &profile.id).await?;

    ctx.event_param("userId", profile.id.clone());
    ctx.event_param("teamId", team.id.clone());
    ctx.event_param("membershipId", membership.id.clone());
    let team_name = team.name.clone().unwrap_or_default();
    let user_name = profile.name.clone().unwrap_or_default();
    let user_email = profile.email.clone().unwrap_or_default();
    Ok(ctx.ok(&MembershipModel::new(&membership, &user_name, &user_email, &team_name, ctx.render())))
}

/// `DELETE /v1/teams/:teamId/memberships/:membershipId`.
pub async fn delete(ctx: &mut Context) -> Result<Response> {
    let team_id = ctx.required_str("teamId", &uid())?;
    let membership_id = ctx.required_str("membershipId", &uid())?;
    let db = ctx.db()?;
    let Some(membership) = documents::get::<Membership>(&db, "memberships", &membership_id).await? else {
        return Err(Error::new(ErrorType::TeamInviteNotFound));
    };
    let Some(profile) = base::user(&db, &membership.user_id).await? else {
        return Err(Error::new(ErrorType::UserNotFound));
    };
    let (_, team) = base::team_or_404(&db, &team_id).await?;
    if membership.team_internal_id != team.sequence.to_string() {
        return Err(Error::new(ErrorType::TeamMembershipMismatch));
    }

    if ctx.project.is_console() {
        // Up to 2 owners tell whether only one exists.
        let owners = db
            .count(
                "memberships",
                vec![Query::contains("roles", ["owner"]), Query::equal("teamInternalId", [team.sequence.to_string()])],
                Some(2),
            )
            .await?;
        let own = ctx.user.as_ref().is_some_and(|u| u.sequence.to_string() == membership.user_internal_id);
        // The only owner cannot leave: no other role can add an owner or
        // delete the organization.
        if owners == 1 && own && membership.roles.iter().any(|r| r == "owner") {
            return Err(Error::with_message(
                ErrorType::MembershipDeletionProhibited,
                "There must be at least one owner in the organization.",
            ));
        }
    }

    match db.delete_document("memberships", &membership.id).await {
        Ok(_) => {}
        Err(e) if e.is_a(Kind::Authorization) => return Err(Error::new(ErrorType::UserUnauthorized)),
        Err(e) => return Err(e.into()),
    }
    db.purge_cached_document("users", &profile.id).await?;

    // The team's primary member moves to the next confirmed member.
    if team.user_internal_id.as_deref().is_some_and(|id| id == membership.user_internal_id) {
        let successor = db
            .find_one(
                "memberships",
                vec![Query::equal("teamInternalId", [team.sequence.to_string()]), Query::equal("confirm", [true])],
            )
            .await?;
        if !successor.is_empty() {
            let mut updates = Document::default();
            updates.put("userId", successor.get("userId").cloned().unwrap_or(Value::Null));
            updates.put("userInternalId", successor.get("userInternalId").cloned().unwrap_or(Value::Null));
            db.update_document("teams", &team.id, updates).await?;
        }
    }

    // Only confirmed members are counted.
    if membership.confirm == Some(true) {
        let authorization = db.authorization();
        authorization
            .skip(db.decrease_document_attribute("teams", &team.id, "total", Value::Int(1), Some(Value::Int(0))))
            .await?;
    }

    ctx.event_param("teamId", team.id.clone());
    ctx.event_param("userId", profile.id.clone());
    ctx.event_param("membershipId", membership.id.clone());
    // `$response->output($membership)`: the stored membership, without the
    // member details the other routes add.
    ctx.event_payload(&MembershipModel::new(&membership, "", "", "", ctx.render()));
    Ok(ctx.no_content())
}
