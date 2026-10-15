//! Project settings, integrations, auth settings and project variables,
//! written to the platform and project databases.

use php_std::{Array, Value};
use utopia_database::database::LENGTH_KEY;
use utopia_database::{Database as Db, Document, Kind as DbKind, Permission, PermissionType, Query, Role, id};

use super::Appwrite;
use super::normalize_date_time;
use super::schema::{attr, db_array, db_value, doc, s};
use crate::error::Result;
use crate::resource::{Kind, ResourceRef, status, types};
use crate::resources::cast_bool;

type DbValue = utopia_database::Value;

/// `bin2hex(random_bytes($bytes))`.
fn random_hex(bytes: usize) -> String {
    let mut rng = php_std::random::Mt19937::from_entropy();
    (0..bytes).map(|_| format!("{:02x}", rng.next_u32() & 0xff)).collect()
}

fn list(a: &Array) -> DbValue {
    db_value(&Value::Array(a.clone()))
}

fn permissions(r: &ResourceRef) -> DbValue {
    DbValue::Array(utopia_database::Array::from_list(r.permissions().iter().map(|p| s(p)).collect()))
}

fn array_attr(d: &Document, key: &str) -> Array {
    match attr(d, key) {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

/// A project variable key the API accepts: a C identifier of at most
/// [`LENGTH_KEY`] characters (`/^[A-Za-z_]\w*$/D`).
fn valid_variable_key(key: &str) -> bool {
    let b = key.as_bytes();
    if b.len() as i64 > LENGTH_KEY || b.is_empty() {
        return false;
    }
    (b[0].is_ascii_alphabetic() || b[0] == b'_') && b[1..].iter().all(|c| c.is_ascii_alphanumeric() || *c == b'_')
}

impl Appwrite {
    async fn project(&self) -> Result<Document> {
        Ok(self.db_for_platform.get_document("projects", &self.project_id, Vec::new(), false).await?)
    }

    async fn update_project(&self, key: &str, value: DbValue, purge: bool) -> Result<()> {
        let auth = self.db_for_platform.authorization();
        let update = doc(vec![(key, value)])?;
        auth.skip(self.db_for_platform.update_document("projects", &self.project_id, update)).await?;
        if purge {
            self.db_for_platform.purge_cached_document("projects", &self.project_id).await?;
        }
        Ok(())
    }

    async fn find_one(db: &Db, collection: &str, queries: Vec<Query>) -> Result<Document> {
        Ok(db.find_one(collection, queries).await?)
    }

    /// Imports a resource of the integrations group.
    ///
    /// PHP: `importIntegrationsResource($resource)`.
    pub(super) async fn import_integrations_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.name() {
            types::PLATFORM => {
                self.create_platform(r).await?;
            }
            types::API_KEY => {
                self.create_api_key(r).await?;
            }
            types::WEBHOOK => {
                self.create_webhook(r).await?;
            }
            types::SMTP => {
                self.create_smtp(r).await?;
            }
            _ => {}
        }
        if r.status() != status::SKIPPED {
            r.set_status(status::SUCCESS, "");
        }
        Ok(())
    }

    /// Imports a resource of the projects group.
    ///
    /// PHP: `importProjectsResource($resource)`.
    pub(super) async fn import_projects_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::ProjectVariable(_) => {
                self.create_project_variable(r).await?;
            }
            Kind::Protocols(p) => {
                let mut apis = array_attr(&self.project().await?, "apis");
                apis.set("rest".into(), Value::Bool(p.rest));
                apis.set("graphql".into(), Value::Bool(p.graphql));
                apis.set("websocket".into(), Value::Bool(p.websocket));
                self.update_project("apis", list(&apis), true).await?;
            }
            Kind::Labels(l) => {
                let mut seen: Vec<Value> = Vec::new();
                for v in l.labels.values() {
                    if !seen.iter().any(|x| x.loose_eq(v)) {
                        seen.push(v.clone());
                    }
                }
                self.update_project("labels", list(&Array::from_list(seen)), true).await?;
            }
            Kind::Services(svc) => {
                let mut services = array_attr(&self.project().await?, "services");
                for (k, v) in svc.services.iter() {
                    services.set(k.to_owned(), Value::Bool(cast_bool(v)));
                }
                self.update_project("services", list(&services), true).await?;
            }
            Kind::EmailTemplate(t) => {
                let mut templates = array_attr(&self.project().await?, "templates");
                let key = format!("email.{}-{}", t.template_id, t.locale);
                let mut existing = match templates.get_str(&key) {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                existing.set("subject".into(), Value::from(t.subject.as_str()));
                existing.set("message".into(), Value::from(t.body.as_str()));
                existing.set("senderName".into(), Value::from(t.sender_name.as_str()));
                existing.set("senderEmail".into(), Value::from(t.sender_email.as_str()));
                existing.set("replyToEmail".into(), Value::from(t.reply_to_email.as_str()));
                existing.set("replyToName".into(), Value::from(t.reply_to_name.as_str()));
                templates.set(php_std::ArrayKey::from(key.as_str()), Value::Array(existing));
                self.update_project("templates", list(&templates), false).await?;
            }
            _ => {}
        }
        if r.status() != status::SKIPPED {
            r.set_status(status::SUCCESS, "");
        }
        Ok(())
    }

    /// Imports a resource of the domains group.
    ///
    /// PHP: `importDomainsResource($resource)`.
    pub(super) async fn import_domains_resource(&mut self, r: &ResourceRef) -> Result<()> {
        if r.name() == types::RULE && !self.create_rule(r).await? {
            return Ok(());
        }
        if r.status() != status::SKIPPED {
            r.set_status(status::SUCCESS, "");
        }
        Ok(())
    }

    async fn create_project_variable(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::ProjectVariable(v) = r.kind() else { return Ok(false) };
        if !valid_variable_key(&v.key) {
            r.set_status(status::SKIPPED, "Project variable key is not a valid environment variable name");
            return Ok(false);
        }
        let existing = Self::find_one(
            &self.db_for_project,
            "variables",
            vec![Query::equal("resourceType", ["project".to_owned()]), Query::equal("key", [&v.key.to_owned()])],
        )
        .await?;
        if !existing.is_empty() {
            r.set_status(status::SKIPPED, "Project variable already exists");
            return Ok(false);
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let variable_id = id::unique()?;
        let document = doc(vec![
            ("$id", s(&variable_id)),
            ("$permissions", permissions(r)),
            ("resourceInternalId", s("")),
            ("resourceId", s("")),
            ("resourceType", s("project")),
            ("key", s(&v.key)),
            ("value", s(&v.value)),
            ("secret", DbValue::Bool(v.secret)),
            ("search", s(&format!("{variable_id} {} project", v.key))),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ])?;
        match self.db_for_project.create_document("variables", document).await {
            Ok(_) => Ok(true),
            Err(e) if e.is_a(DbKind::Duplicate) => {
                r.set_status(status::SKIPPED, "Project variable already exists");
                Ok(false)
            }
            Err(e) => Err(e.into()),
        }
    }

    async fn create_smtp(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Smtp(m) = r.kind() else { return Ok(false) };
        let mut smtp = array_attr(&self.project().await?, "smtp");
        smtp.set("enabled".into(), Value::Bool(m.enabled));
        smtp.set("senderName".into(), Value::from(m.sender_name.as_str()));
        smtp.set("senderEmail".into(), Value::from(m.sender_email.as_str()));
        smtp.set("replyToName".into(), Value::from(m.reply_to_name.as_str()));
        smtp.set("replyToEmail".into(), Value::from(m.reply_to_email.as_str()));
        smtp.set("host".into(), Value::from(m.host.as_str()));
        smtp.set("port".into(), Value::Int(m.port));
        smtp.set("username".into(), Value::from(m.username.as_str()));
        smtp.set("secure".into(), Value::from(m.secure.as_str()));
        self.update_project("smtp", list(&smtp), true).await?;
        Ok(true)
    }

    async fn create_webhook(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Webhook(w) = r.kind() else { return Ok(false) };
        let existing = Self::find_one(
            &self.db_for_platform,
            "webhooks",
            vec![
                Query::equal("projectInternalId", [&self.project_internal_id.to_owned()]),
                Query::equal("name", [&w.name.to_owned()]),
            ],
        )
        .await?;
        if !existing.is_empty() {
            r.set_status(status::SKIPPED, "Webhook already exists");
            return Ok(false);
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let document = doc(vec![
            ("$id", s(&id::unique()?)),
            ("$permissions", permissions(r)),
            ("projectInternalId", s(&self.project_internal_id)),
            ("projectId", s(&self.project_id)),
            ("name", s(&w.name)),
            ("events", list(&w.events)),
            ("url", s(&w.url)),
            ("security", DbValue::Bool(w.security)),
            ("httpUser", s(&w.http_user)),
            ("httpPass", s(&w.http_pass)),
            ("signatureKey", s(&random_hex(64))),
            ("enabled", DbValue::Bool(w.enabled)),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ])?;
        match self.db_for_platform.create_document("webhooks", document).await {
            Ok(_) => {}
            Err(e) if e.is_a(DbKind::Duplicate) => {
                r.set_status(status::SKIPPED, "Webhook already exists");
                return Ok(false);
            }
            Err(e) => return Err(e.into()),
        }
        self.db_for_platform.purge_cached_document("projects", &self.project_id).await?;
        Ok(true)
    }

    async fn create_platform(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Platform(p) = r.kind() else { return Ok(false) };
        let existing = Self::find_one(
            &self.db_for_platform,
            "platforms",
            vec![
                Query::equal("projectId", [&self.project_id.to_owned()]),
                Query::equal("type", [&p.platform_type.to_owned()]),
                Query::equal("name", [&p.name.to_owned()]),
            ],
        )
        .await?;
        if !existing.is_empty() {
            r.set_status(status::SKIPPED, "Platform already exists");
            return Ok(false);
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let document = doc(vec![
            ("$id", s(&id::unique()?)),
            ("$permissions", permissions(r)),
            ("projectInternalId", s(&self.project_internal_id)),
            ("projectId", s(&self.project_id)),
            ("type", s(&p.platform_type)),
            ("name", s(&p.name)),
            ("key", s(&p.key)),
            ("store", s(&p.store)),
            ("hostname", s(&p.hostname)),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ])?;
        match self.db_for_platform.create_document("platforms", document).await {
            Ok(_) => {}
            Err(e) if e.is_a(DbKind::Duplicate) => {
                r.set_status(status::SKIPPED, "Platform already exists");
                return Ok(false);
            }
            Err(e) => return Err(e.into()),
        }
        self.db_for_platform.purge_cached_document("projects", &self.project_id).await?;
        Ok(true)
    }

    async fn create_api_key(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::ApiKey(k) = r.kind() else { return Ok(false) };
        let existing = Self::find_one(
            &self.db_for_platform,
            "keys",
            vec![
                Query::equal("resourceType", ["projects".to_owned()]),
                Query::equal("resourceInternalId", [&self.project_internal_id.to_owned()]),
                Query::equal("name", [&k.name.to_owned()]),
            ],
        )
        .await?;
        if !existing.is_empty() {
            r.set_status(status::SKIPPED, "API key already exists");
            return Ok(false);
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let expire = if k.expire.is_empty() || k.expire == "0" { DbValue::Null } else { s(&k.expire) };
        let document = doc(vec![
            ("$id", s(&id::unique()?)),
            ("$permissions", permissions(r)),
            ("resourceInternalId", s(&self.project_internal_id)),
            ("resourceId", s(&self.project_id)),
            ("resourceType", s("projects")),
            ("name", s(&k.name)),
            ("scopes", list(&k.scopes)),
            ("expire", expire),
            ("sdks", list(&k.sdks)),
            ("accessedAt", DbValue::Null),
            ("secret", s(&format!("standard_{}", random_hex(128)))),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
        ])?;
        match self.db_for_platform.create_document("keys", document).await {
            Ok(_) => {}
            Err(e) if e.is_a(DbKind::Duplicate) => {
                r.set_status(status::SKIPPED, "API key already exists");
                return Ok(false);
            }
            Err(e) => return Err(e.into()),
        }
        self.db_for_platform.purge_cached_document("projects", &self.project_id).await?;
        Ok(true)
    }

    /// PHP: `createAuthMethods($resource)`.
    pub(super) async fn create_auth_methods(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::AuthMethods(m) = r.kind() else { return Ok(false) };
        let mut auths = array_attr(&self.project().await?, "auths");
        for (k, v) in [
            ("emailPassword", m.email_password),
            ("usersAuthMagicURL", m.magic_url),
            ("emailOtp", m.email_otp),
            ("anonymous", m.anonymous),
            ("invites", m.invites),
            ("JWT", m.jwt),
            ("phone", m.phone),
        ] {
            auths.set(k.into(), Value::Bool(v));
        }
        self.update_project("auths", list(&auths), true).await?;
        Ok(true)
    }

    /// PHP: `createPolicies($resource)`.
    pub(super) async fn create_policies(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Policies(p) = r.kind() else { return Ok(false) };
        let mut auths = array_attr(&self.project().await?, "auths");
        auths.set("passwordHistory".into(), Value::Int(p.password_history));
        auths.set("duration".into(), Value::Int(p.session_duration));
        auths.set("maxSessions".into(), Value::Int(p.sessions_limit));
        auths.set("limit".into(), Value::Int(p.user_limit));
        for (k, v) in [
            ("passwordDictionary", p.password_dictionary),
            ("personalDataCheck", p.personal_data_check),
            ("sessionAlerts", p.session_alerts),
            ("invalidateSessions", p.session_invalidation),
            ("membershipsUserId", p.memberships_user_id),
            ("membershipsUserEmail", p.memberships_user_email),
            ("membershipsUserName", p.memberships_user_name),
            ("membershipsMfa", p.memberships_user_mfa),
            ("membershipsUserPhone", p.memberships_user_phone),
        ] {
            auths.set(k.into(), Value::Bool(v));
        }
        // Policies the source did not report are left as they are.
        let flag = |b: Option<bool>| b.map(Value::Bool);
        let reported = [
            ("membershipsUserAccessedAt", flag(p.memberships_user_accessed_at)),
            ("passwordStrength", p.password_strength.clone().map(Value::Array)),
            ("mfaFactors", p.mfa_factors.clone().map(Value::Array)),
            ("canonicalEmails", flag(p.deny_aliased_email)),
            ("disposableEmails", flag(p.deny_disposable_email)),
            ("freeEmails", flag(p.deny_free_email)),
            ("corporateEmails", flag(p.deny_corporate_email)),
        ];
        for (k, v) in reported {
            if let Some(v) = v {
                auths.set(k.into(), v);
            }
        }
        self.update_project("auths", list(&auths), true).await?;
        Ok(true)
    }

    /// PHP: `createOAuth2Provider($resource)`.
    pub(super) async fn create_oauth2_provider(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::OAuth2Provider(p) = r.kind() else { return Ok(false) };
        let key = p.provider_key.clone();
        let mut providers = array_attr(&self.project().await?, "oAuthProviders");
        if let Some(app_id) = p.destination_app_id() {
            providers.set(php_std::ArrayKey::from(format!("{key}Appid").as_str()), Value::from(app_id.as_str()));
        }
        let secret_fields = p.destination_secret_fields();
        if !secret_fields.is_empty() {
            let secret_key = format!("{key}Secret");
            let existing = match providers.get_str(&secret_key) {
                Some(Value::Null) | None => String::new(),
                Some(v) => crate::resources::cast_string(v),
            };
            providers.set(
                php_std::ArrayKey::from(secret_key.as_str()),
                Value::from(merge_json_secret(&existing, &secret_fields).as_str()),
            );
        }
        providers.set(php_std::ArrayKey::from(format!("{key}Enabled").as_str()), Value::Bool(p.enabled));
        self.update_project("oAuthProviders", list(&providers), true).await?;
        Ok(true)
    }

    /// PHP: `importUserTargets($userId, $targets)`: push targets, which the
    /// server does not create with the user.
    pub(super) async fn import_user_targets(&mut self, user_id: &str, targets: &Array) -> Result<()> {
        let mut user: Option<Document> = None;
        for target in targets.values() {
            let Value::Array(t) = target else { continue };
            let get = |k: &str| t.get_str(k).cloned().unwrap_or(Value::Null);
            let provider_type = match get("providerType") {
                Value::Null => String::new(),
                v => crate::resources::cast_string(&v),
            };
            if provider_type != "push" {
                continue;
            }
            if user.is_none() {
                user = Some(self.db_for_project.get_document("users", user_id, Vec::new(), false).await?);
            }
            let user_doc = user.as_ref().expect("set above");
            let created_at = normalize_date_time(&get("$createdAt"), &Value::Null)?;
            let updated_at = normalize_date_time(&get("$updatedAt"), &Value::from(created_at.as_str()))?;
            let role = Role::user(user_id, "");
            let document = doc(vec![
                ("$id", db_value(&get("$id"))),
                ("$createdAt", s(&created_at)),
                ("$updatedAt", s(&updated_at)),
                (
                    "$permissions",
                    DbValue::Array(utopia_database::Array::from_list(vec![
                        s(&Permission::read(&role)),
                        s(&Permission::update(&role)),
                        s(&Permission::delete(&role)),
                    ])),
                ),
                ("userId", s(user_id)),
                ("userInternalId", s(&super::schema::seq(user_doc))),
                ("providerType", db_value(&get("providerType"))),
                ("providerId", db_value(&get("providerId"))),
                ("identifier", db_value(&get("identifier"))),
                ("name", db_value(&get("name"))),
                (
                    "expired",
                    match get("expired") {
                        Value::Null => DbValue::Bool(false),
                        v => db_value(&v),
                    },
                ),
            ])?;
            self.db_for_project.create_document("targets", document).await?;
        }
        if user.is_some() {
            self.db_for_project.purge_cached_document("users", user_id).await?;
        }
        Ok(())
    }

    /// Points push targets written before their provider existed at it.
    pub(super) async fn link_provider_targets(&mut self, provider_id: &str) -> Result<()> {
        let provider = self.db_for_project.get_document("providers", provider_id, Vec::new(), false).await?;
        let targets = self
            .db_for_project
            .find(
                "targets",
                vec![Query::equal("providerId", [provider_id.to_owned()]), Query::is_null("providerInternalId")],
                PermissionType::Read,
            )
            .await?;
        let mut users: indexmap::IndexSet<String> = indexmap::IndexSet::new();
        for mut target in targets {
            target.set("providerInternalId", s(&super::schema::seq(&provider)))?;
            let id = target.id().to_owned();
            if let Some(u) = target.get("userId") {
                users.insert(crate::resources::cast_string(&super::schema::plain_value(u)));
            }
            self.db_for_project.update_document("targets", &id, target).await?;
        }
        for user in users {
            self.db_for_project.purge_cached_document("users", &user).await?;
        }
        Ok(())
    }

    /// PHP: `createSubscriber($resource)`: written to the project database.
    pub(super) async fn create_subscriber(&mut self, r: &ResourceRef) -> Result<()> {
        use crate::error::Error;
        let Kind::Subscriber(sub) = r.kind() else { return Ok(()) };
        let target = match sub.provider_type.as_str() {
            "push" => self.db_for_project.get_document("targets", &sub.target_id, Vec::new(), false).await?,
            "email" | "sms" => {
                Self::find_one(
                    &self.db_for_project,
                    "targets",
                    vec![
                        Query::equal("userId", [&sub.user_id.to_owned()]),
                        Query::equal("providerType", [&sub.provider_type.to_owned()]),
                    ],
                )
                .await?
            }
            t => return Err(Error::exception(format!("Unknown provider type: {t}"), 0)),
        };
        if target.is_empty() {
            return Err(Error::exception(format!("Target not found for subscriber: {}", r.id()), 0));
        }
        let topic = self.db_for_project.get_document("topics", &sub.topic_id, Vec::new(), false).await?;
        if topic.is_empty() {
            return Err(Error::exception(format!("Topic not found: {}", sub.topic_id), 0));
        }
        let user = self.db_for_project.get_document("users", &sub.user_id, Vec::new(), false).await?;
        if user.is_empty() {
            return Err(Error::exception(format!("User not found: {}", sub.user_id), 0));
        }
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let role = Role::user(sub.user_id.as_str(), "");
        let document = doc(vec![
            ("$id", s(&r.id())),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
            (
                "$permissions",
                DbValue::Array(utopia_database::Array::from_list(vec![
                    s(&Permission::read(&role)),
                    s(&Permission::delete(&role)),
                ])),
            ),
            ("topicId", s(topic.id())),
            ("topicInternalId", s(&super::schema::seq(&topic))),
            ("targetId", s(target.id())),
            ("targetInternalId", s(&super::schema::seq(&target))),
            ("userId", s(user.id())),
            ("userInternalId", s(&super::schema::seq(&user))),
            ("providerType", s(&sub.provider_type)),
            ("search", s(&format!("{} {} {} {}", r.id(), target.id(), user.id(), sub.provider_type))),
        ])?;
        self.db_for_project.create_document("subscribers", document).await?;
        let total = match sub.provider_type.as_str() {
            "email" => "emailTotal",
            "sms" => "smsTotal",
            _ => "pushTotal",
        };
        self.db_for_project.increase_document_attribute("topics", &sub.topic_id, total, DbValue::Int(1), None).await?;
        Ok(())
    }

    /// PHP: `createMessage($resource)` for a message that is not scheduled
    /// in the future: written to the project database (as a draft unless
    /// it was sent).
    pub(super) async fn create_message_document(&mut self, r: &ResourceRef, status: &str) -> Result<()> {
        let Kind::Message(m) = r.kind() else { return Ok(()) };
        let created_at = normalize_date_time(&Value::from(r.created_at().as_str()), &Value::Null)?;
        let updated_at = normalize_date_time(&Value::from(r.updated_at().as_str()), &Value::from(created_at.as_str()))?;
        let data = &m.data;
        let field = |k: &str| match data.get_str(k) {
            Some(Value::Null) | None => String::new(),
            Some(v) => crate::resources::cast_string(v),
        };
        let search_content = match m.provider_type.as_str() {
            "email" => field("subject"),
            "sms" => field("content"),
            "push" => field("title"),
            _ => String::new(),
        };
        let search: Vec<String> =
            [r.id(), field("description"), status.to_owned(), search_content, m.provider_type.clone()]
                .into_iter()
                .filter(|p| !p.is_empty() && p != "0")
                .collect();
        let delivered_at =
            if m.delivered_at.is_empty() || m.delivered_at == "0" { DbValue::Null } else { s(&m.delivered_at) };
        let document = doc(vec![
            ("$id", s(&r.id())),
            ("$createdAt", s(&created_at)),
            ("$updatedAt", s(&updated_at)),
            ("$permissions", DbValue::Array(utopia_database::Array::new())),
            ("providerType", s(&m.provider_type)),
            ("topics", list(&m.topics)),
            ("users", list(&m.users)),
            ("targets", list(&m.targets)),
            ("scheduledAt", DbValue::Null),
            ("deliveredAt", delivered_at),
            ("deliveryErrors", list(&m.delivery_errors)),
            ("deliveredTotal", DbValue::Int(m.delivered_total)),
            ("data", DbValue::Array(db_array(data))),
            ("status", s(status)),
            ("search", s(&search.join(" "))),
        ])?;
        self.db_for_project.create_document("messages", document).await?;
        Ok(())
    }
}

/// The JSON secret `existing` with `fields` set (`mergeJsonSecret()`).
fn merge_json_secret(existing: &str, fields: &Array) -> String {
    if fields.is_empty() {
        return existing.to_owned();
    }
    let mut decoded = if existing.is_empty() {
        Array::new()
    } else {
        match php_std::json::decode(
            existing.as_bytes(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        ) {
            Ok(Value::Array(a)) => a,
            _ => Array::new(),
        }
    };
    for (k, v) in fields.iter() {
        decoded.set(k.to_owned(), v.clone());
    }
    php_std::json::encode(&Value::Array(decoded), php_std::json::Flags::NONE, php_std::json::DEFAULT_DEPTH)
        .unwrap_or_default()
}
