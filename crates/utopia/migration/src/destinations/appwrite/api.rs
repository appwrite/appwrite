//! Users, teams, storage, functions, messaging, sites and rules, written
//! through the Appwrite API.

use php_std::{Array, Value};

use super::Appwrite;
use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{Kind, ResourceRef, status, types};
use crate::resources::cast_string;
use crate::sdk::{Input, spec};
use crate::transfer::STORAGE_MAX_CHUNK_SIZE;

fn v(s: &str) -> Value {
    Value::from(s)
}

/// `$value ?: null` of a string.
fn or_null(s: &str) -> Value {
    if s.is_empty() || s == "0" { Value::Null } else { v(s) }
}

fn strings(list: &[String]) -> Value {
    Value::Array(Array::from_list(list.iter().map(|s| v(s)).collect()))
}

/// PHP `empty()`.
fn empty(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::Bool(b) => !b,
        Value::Int(i) => *i == 0,
        Value::Float(f) => *f == 0.0,
        Value::Str(s) => s.is_empty() || s.as_bytes() == b"0",
        Value::Array(a) => a.is_empty(),
        _ => false,
    }
}

/// `$enum::from($value)` of an SDK enum: whether `value` is one of its values.
fn enum_has(name: &str, value: &str) -> bool {
    spec::ENUMS.iter().any(|e| e.name == name && e.values.contains(&value))
}

/// `$array[$key] ?? null`.
fn get(a: &Array, key: &str) -> Value {
    a.get_str(key).cloned().unwrap_or(Value::Null)
}

/// `($array[$key] ?? '') ?: null`.
fn get_or_null(a: &Array, key: &str) -> Value {
    let value = get(a, key);
    if empty(&value) { Value::Null } else { value }
}

/// `'bytes <start>-<end>/<size>'`, where the last chunk's end is the size.
fn content_range(start: i64, end: i64, size: i64) -> String {
    format!("bytes {start}-{}/{size}", if end == size - 1 { size } else { end })
}

impl Appwrite {
    /// Checks the API key can read and write each type in `resources`: the
    /// calls are made to fail validation, after the scope check.
    ///
    /// PHP: the body of `report()`.
    pub(super) async fn check_scopes(&self, resources: &[String]) -> Result<()> {
        let has = |t: &str| resources.iter().any(|r| r == t);
        let mut scope = "";
        let result: Result<()> = async {
            if has(types::USER) {
                scope = "users.read";
                self.client.invoke("Users", "list", &[]).await?;
                scope = "users.write";
                self.client.invoke("Users", "create", &[("userId", v(""))]).await?;
            }
            if has(types::TEAM) {
                scope = "teams.read";
                self.client.invoke("Teams", "list", &[]).await?;
                scope = "teams.write";
                self.client.invoke("Teams", "create", &[("teamId", v("")), ("name", v(""))]).await?;
            }
            if has(types::MEMBERSHIP) {
                scope = "memberships.read";
                self.client.invoke("Teams", "listMemberships", &[("teamId", v(""))]).await?;
                scope = "memberships.write";
                self.client
                    .invoke(
                        "Teams",
                        "createMembership",
                        &[("teamId", v("")), ("roles", Value::Array(Array::new())), ("email", v(""))],
                    )
                    .await?;
            }
            if has(types::BUCKET) {
                scope = "storage.read";
                self.client.invoke("Storage", "listBuckets", &[]).await?;
                scope = "storage.write";
                self.client.invoke("Storage", "createBucket", &[("bucketId", v("")), ("name", v(""))]).await?;
            }
            if has(types::FILE) {
                scope = "files.read";
                self.client.invoke("Storage", "listFiles", &[("bucketId", v(""))]).await?;
                scope = "files.write";
                self.client.invoke("Storage", "createFile", &[("bucketId", v("")), ("fileId", v(""))]).await?;
            }
            if has(types::FUNCTION) {
                scope = "functions.read";
                self.client.invoke("Functions", "list", &[]).await?;
                scope = "functions.write";
                self.client
                    .invoke(
                        "Functions",
                        "create",
                        &[("functionId", v("")), ("name", v("")), ("runtime", v("node-18.0"))],
                    )
                    .await?;
            }
            if has(types::PROVIDER) {
                scope = "providers.read";
                self.client.invoke("Messaging", "listProviders", &[]).await?;
                scope = "providers.write";
                self.client
                    .invoke("Messaging", "createSendgridProvider", &[("providerId", v("")), ("name", v(""))])
                    .await?;
            }
            if has(types::TOPIC) {
                scope = "topics.read";
                self.client.invoke("Messaging", "listTopics", &[]).await?;
                scope = "topics.write";
                self.client.invoke("Messaging", "createTopic", &[("topicId", v("")), ("name", v(""))]).await?;
            }
            if has(types::SUBSCRIBER) {
                scope = "subscribers.read";
                self.client.invoke("Messaging", "listSubscribers", &[("topicId", v(""))]).await?;
                scope = "subscribers.write";
                self.client
                    .invoke(
                        "Messaging",
                        "createSubscriber",
                        &[("topicId", v("")), ("subscriberId", v("")), ("targetId", v(""))],
                    )
                    .await?;
            }
            if has(types::MESSAGE) {
                scope = "messages.read";
                self.client.invoke("Messaging", "listMessages", &[]).await?;
                scope = "messages.write";
                self.client
                    .invoke(
                        "Messaging",
                        "createEmail",
                        &[("messageId", v("")), ("subject", v("")), ("content", v("")), ("draft", Value::Bool(true))],
                    )
                    .await?;
            }
            if has(types::SITE) {
                scope = "sites.read";
                self.client.invoke("Sites", "list", &[]).await?;
                scope = "sites.write";
                self.client
                    .invoke(
                        "Sites",
                        "create",
                        &[
                            ("siteId", v("")),
                            ("name", v("")),
                            ("framework", v("other")),
                            ("buildRuntime", v("static-1")),
                        ],
                    )
                    .await?;
            }
            Ok(())
        }
        .await;
        match result {
            Err(e @ Error::Appwrite { code: 403, .. }) => {
                Err(Error::exception_with(format!("Missing scope: {scope}"), 403, e))
            }
            other => other,
        }
    }

    /// PHP: `importFileResource($resource)`.
    pub(super) async fn import_file_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::File(_) => return self.import_file(r).await,
            Kind::Bucket(b) => {
                if !["none", "gzip", "zstd"].contains(&b.compression.as_str()) {
                    return Err(Error::exception(
                        format!("Invalid Compression: {}", b.compression),
                        Exception::CODE_VALIDATION,
                    ));
                }
                let response = self
                    .client
                    .invoke(
                        "Storage",
                        "createBucket",
                        &[
                            ("bucketId", v(&r.id())),
                            ("name", v(&b.name)),
                            ("permissions", strings(&r.permissions())),
                            ("fileSecurity", Value::Bool(b.file_security)),
                            ("enabled", Value::Bool(b.enabled)),
                            ("maximumFileSize", b.max_file_size.map_or(Value::Null, Value::Int)),
                            ("allowedFileExtensions", Value::Array(b.allowed_file_extensions.clone())),
                            ("compression", v(&b.compression)),
                            ("encryption", Value::Bool(b.encryption)),
                            ("antivirus", Value::Bool(b.anti_virus)),
                            ("transformations", Value::Bool(b.transformations)),
                        ],
                    )
                    .await?;
                r.set_id(response.string("id"));
            }
            _ => {}
        }
        r.set_status(status::SUCCESS, "");
        Ok(())
    }

    /// PHP: `importFile($file)`: one request for a small file, one per
    /// chunk for a large one.
    pub async fn import_file(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::File(f) = r.kind() else { return Ok(()) };
        let bucket_id = f.bucket.id();
        let (start, end, data) = {
            let c = f.chunk();
            (c.start, c.end, c.data.clone())
        };
        let path = format!("/storage/buckets/{bucket_id}/files");
        let params = vec![
            ("bucketId".to_owned(), Input::Value(v(&bucket_id))),
            ("fileId".to_owned(), Input::Value(v(&r.id()))),
            ("file".to_owned(), Input::File { data, mime: f.mime_type.clone(), name: f.name.clone() }),
            ("permissions".to_owned(), Input::Value(strings(&r.permissions()))),
        ];
        let project = self.project_id.clone();
        if f.size <= STORAGE_MAX_CHUNK_SIZE {
            self.client
                .call(
                    "POST",
                    &path,
                    &[("content-type", "multipart/form-data".to_owned()), ("X-Appwrite-Project", project)],
                    &params,
                )
                .await?;
            r.set_status(status::SUCCESS, "");
            f.chunk_mut().data = bytes::Bytes::new();
            return Ok(());
        }
        let response = self
            .client
            .call(
                "POST",
                &path,
                &[
                    ("content-type", "multipart/form-data".to_owned()),
                    ("content-range", content_range(start, end, f.size)),
                    ("X-Appwrite-Project", project),
                ],
                &params,
            )
            .await?;
        if end == f.size - 1 {
            r.set_status(status::SUCCESS, "");
            let encrypted = matches!(f.bucket.kind(), Kind::Bucket(b) if b.encryption);
            if (!encrypted || f.size > 20 * 1024 * 1024)
                && let Value::Array(a) = &response
                && !get(a, "signature").strict_eq(&v(&f.signature))
            {
                r.set_status(status::WARNING, "File signature mismatch, Possibly corrupted.");
            }
        }
        f.chunk_mut().data = bytes::Bytes::new();
        Ok(())
    }

    /// PHP: `importAuthResource($resource)`.
    pub(super) async fn import_auth_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::User(u) => {
                let id = r.id();
                if u.password_hash.is_some() {
                    self.import_password_user(r).await?;
                } else {
                    self.client
                        .invoke(
                            "Users",
                            "create",
                            &[
                                ("userId", v(&id)),
                                ("email", u.email.as_deref().map_or(Value::Null, v)),
                                ("phone", Value::Null),
                                ("password", Value::Null),
                                ("name", Value::Null),
                            ],
                        )
                        .await?;
                }
                let username = u.username.clone().unwrap_or_default();
                if !username.is_empty() && username != "0" {
                    self.client.invoke("Users", "updateName", &[("userId", v(&id)), ("name", v(&username))]).await?;
                }
                let phone = u.phone.clone().unwrap_or_default();
                if !phone.is_empty() && phone != "0" {
                    self.client.invoke("Users", "updatePhone", &[("userId", v(&id)), ("number", v(&phone))]).await?;
                }
                if u.email_verified {
                    self.client
                        .invoke(
                            "Users",
                            "updateEmailVerification",
                            &[("userId", v(&id)), ("emailVerification", Value::Bool(true))],
                        )
                        .await?;
                }
                if u.phone_verified {
                    self.client
                        .invoke(
                            "Users",
                            "updatePhoneVerification",
                            &[("userId", v(&id)), ("phoneVerification", Value::Bool(true))],
                        )
                        .await?;
                }
                if u.disabled {
                    self.client
                        .invoke("Users", "updateStatus", &[("userId", v(&id)), ("status", Value::Bool(false))])
                        .await?;
                }
                if !u.preferences.is_empty() {
                    self.client
                        .invoke(
                            "Users",
                            "updatePrefs",
                            &[("userId", v(&id)), ("prefs", Value::Array(u.preferences.clone()))],
                        )
                        .await?;
                }
                if !u.labels.is_empty() {
                    self.client
                        .invoke(
                            "Users",
                            "updateLabels",
                            &[("userId", v(&id)), ("labels", Value::Array(u.labels.clone()))],
                        )
                        .await?;
                }
                if !u.targets.is_empty() {
                    let targets = u.targets.clone();
                    self.import_user_targets(&id, &targets).await?;
                }
            }
            Kind::Team(t) => {
                self.client.invoke("Teams", "create", &[("teamId", v(&r.id())), ("name", v(&t.name))]).await?;
                if !t.preferences.is_empty() {
                    self.client
                        .invoke(
                            "Teams",
                            "updatePrefs",
                            &[("teamId", v(&r.id())), ("prefs", Value::Array(t.preferences.clone()))],
                        )
                        .await?;
                }
            }
            Kind::Membership(m) => {
                self.client
                    .invoke(
                        "Teams",
                        "createMembership",
                        &[
                            ("teamId", v(&m.team.id())),
                            ("roles", Value::Array(m.roles.clone())),
                            ("userId", v(&m.user.id())),
                        ],
                    )
                    .await?;
            }
            Kind::AuthMethods(_) => {
                self.create_auth_methods(r).await?;
            }
            Kind::Policies(_) => {
                self.create_policies(r).await?;
            }
            Kind::OAuth2Provider(_) => {
                self.create_oauth2_provider(r).await?;
            }
            _ => {}
        }
        r.set_status(status::SUCCESS, "");
        Ok(())
    }

    /// PHP: `importPasswordUser($user)`: the user with its password hash.
    pub async fn import_password_user(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::User(u) = r.kind() else { return Ok(()) };
        let Some(hash) = u.password_hash.as_ref().and_then(|h| match h.kind() {
            Kind::Hash(h) => Some(h),
            _ => None,
        }) else {
            return Err(Error::exception("Password hash is missing", Exception::CODE_VALIDATION));
        };
        let id = v(&r.id());
        let email = u.email.as_deref().map_or(Value::Null, v);
        let name = match u.username.as_deref() {
            None | Some("") | Some("0") => Value::Null,
            Some(n) => v(n),
        };
        match hash.algorithm.as_str() {
            "scryptModified" => {
                self.client
                    .invoke(
                        "Users",
                        "createScryptModifiedUser",
                        &[
                            ("userId", id),
                            ("email", email),
                            ("password", v(&hash.hash)),
                            ("passwordSalt", v(&hash.salt)),
                            ("passwordSaltSeparator", v(&hash.separator)),
                            ("passwordSignerKey", v(&hash.signing_key)),
                            ("name", name),
                        ],
                    )
                    .await?;
            }
            "bcrypt" | "argon2" | "phpass" => {
                let method = match hash.algorithm.as_str() {
                    "bcrypt" => "createBcryptUser",
                    "argon2" => "createArgon2User",
                    _ => "createPHPassUser",
                };
                self.client
                    .invoke(
                        "Users",
                        method,
                        &[("userId", id), ("email", email), ("password", v(&hash.hash)), ("name", name)],
                    )
                    .await?;
            }
            "sha256" => {
                self.client
                    .invoke(
                        "Users",
                        "createSHAUser",
                        &[
                            ("userId", id),
                            ("email", email),
                            ("password", v(&hash.hash)),
                            ("passwordVersion", v("sha256")),
                            ("name", name),
                        ],
                    )
                    .await?;
            }
            "scrypt" => {
                self.client
                    .invoke(
                        "Users",
                        "createScryptUser",
                        &[
                            ("userId", id),
                            ("email", email),
                            ("password", v(&hash.hash)),
                            ("passwordSalt", v(&hash.salt)),
                            ("passwordCpu", Value::Int(hash.password_cpu)),
                            ("passwordMemory", Value::Int(hash.password_memory)),
                            ("passwordParallel", Value::Int(hash.password_parallel)),
                            ("passwordLength", Value::Int(hash.password_length)),
                            ("name", name),
                        ],
                    )
                    .await?;
            }
            "plainText" => {
                self.client
                    .invoke(
                        "Users",
                        "create",
                        &[
                            ("userId", id),
                            ("email", email),
                            ("phone", u.phone.as_deref().map_or(Value::Null, v)),
                            ("password", v(&hash.hash)),
                            ("name", name),
                        ],
                    )
                    .await?;
            }
            _ => {}
        }
        Ok(())
    }

    /// PHP: `importFunctionResource($resource)`.
    pub(super) async fn import_function_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::Function(f) => {
                if !enum_has("Runtime", &f.runtime) {
                    return Err(Error::exception_with(
                        format!("Invalid Runtime: {}", f.runtime),
                        Exception::CODE_VALIDATION,
                        Error::InvalidArgument(format!("Unknown Runtime value: {}", f.runtime)),
                    ));
                }
                let build = if f.build_specification.is_empty() || f.build_specification == "0" {
                    f.specification.clone()
                } else {
                    f.build_specification.clone()
                };
                self.client
                    .invoke(
                        "Functions",
                        "create",
                        &[
                            ("functionId", v(&r.id())),
                            ("name", v(&f.name)),
                            ("runtime", v(&f.runtime)),
                            ("execute", Value::Array(f.execute.clone())),
                            ("events", Value::Array(f.events.clone())),
                            ("schedule", v(&f.schedule)),
                            ("timeout", Value::Int(f.timeout)),
                            ("enabled", Value::Bool(f.enabled)),
                            ("logging", Value::Bool(f.logging)),
                            ("entrypoint", v(&f.entrypoint)),
                            ("commands", v(&f.commands)),
                            ("scopes", Value::Array(f.scopes.clone())),
                            ("buildSpecification", or_null(&build)),
                            ("runtimeSpecification", or_null(&f.specification)),
                        ],
                    )
                    .await?;
            }
            Kind::EnvVar(e) => {
                self.client
                    .invoke(
                        "Functions",
                        "createVariable",
                        &[
                            ("functionId", v(&e.func.id())),
                            ("variableId", v(&r.id())),
                            ("key", v(&e.key)),
                            ("value", v(&e.value)),
                        ],
                    )
                    .await?;
            }
            Kind::Deployment(_) => return self.import_deployment(r).await,
            _ => {}
        }
        r.set_status(status::SUCCESS, "");
        Ok(())
    }

    async fn import_deployment(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::Deployment(d) = r.kind() else { return Ok(()) };
        let function = d.func.clone();
        if function.status() != status::SUCCESS {
            r.set_status(status::SKIPPED, format!("Parent function \"{}\" failed to import", function.id()));
            return Ok(());
        }
        let function_id = function.id();
        let (start, end, data) = {
            let c = d.chunk();
            (c.start, c.end, c.data.clone())
        };
        let params = vec![
            ("functionId".to_owned(), Input::Value(v(&function_id))),
            (
                "code".to_owned(),
                Input::File { data, mime: "application/gzip".to_owned(), name: "deployment.tar.gz".to_owned() },
            ),
            ("activate".to_owned(), Input::Value(v(if d.activated { "true" } else { "false" }))),
            ("entrypoint".to_owned(), Input::Value(v(&d.entrypoint))),
        ];
        let project = self.project_id.clone();
        if d.size <= STORAGE_MAX_CHUNK_SIZE {
            self.client
                .call(
                    "POST",
                    &format!("/functions/{function_id}/deployments"),
                    &[("content-type", "multipart/form-data".to_owned()), ("X-Appwrite-Project", project)],
                    &params,
                )
                .await?;
            r.set_status(status::SUCCESS, "");
            return Ok(());
        }
        let response = self
            .client
            .call(
                "POST",
                &format!("/v1/functions/{function_id}/deployments"),
                &[
                    ("content-type", "multipart/form-data".to_owned()),
                    ("content-range", content_range(start, end, d.size)),
                    ("x-appwrite-id", r.id()),
                    ("X-Appwrite-Project", project),
                ],
                &params,
            )
            .await?;
        self.chunked_deployment(r, &response, start, end, d.size, "Deployment creation failed")
    }

    fn chunked_deployment(
        &self,
        r: &ResourceRef,
        response: &Value,
        start: i64,
        end: i64,
        size: i64,
        failure: &str,
    ) -> Result<()> {
        let id = match response {
            Value::Array(a) => a.get_str("$id").filter(|v| !matches!(v, Value::Null)).cloned(),
            _ => None,
        };
        let Some(id) = id else {
            return Err(Error::exception(failure, Exception::CODE_INTERNAL));
        };
        if start == 0 {
            r.set_id(cast_string(&id));
        }
        if end == size - 1 {
            r.set_status(status::SUCCESS, "");
        } else {
            r.set_status(status::PENDING, "");
        }
        Ok(())
    }

    /// PHP: `importMessagingResource($resource)`.
    pub(super) async fn import_messaging_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::Provider(_) => self.create_provider(r).await?,
            Kind::Topic(t) => {
                self.client
                    .invoke(
                        "Messaging",
                        "createTopic",
                        &[
                            ("topicId", v(&r.id())),
                            ("name", v(&t.name)),
                            ("subscribe", Value::Array(t.subscribe.clone())),
                        ],
                    )
                    .await?;
            }
            Kind::Subscriber(_) => self.create_subscriber(r).await?,
            Kind::Message(_) => self.create_message(r).await?,
            _ => return Err(Error::exception(format!("Unknown messaging resource type: {}", r.name()), 0)),
        }
        r.set_status(status::SUCCESS, "");
        Ok(())
    }

    async fn create_provider(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::Provider(p) = r.kind() else { return Ok(()) };
        let c = &p.credentials;
        let o = &p.options;
        let id = v(&r.id());
        let name = v(&p.name);
        let enabled = Value::Bool(p.enabled);
        let sender = |args: &mut Vec<(&'static str, Value)>| {
            args.push(("fromName", get_or_null(o, "fromName")));
            args.push(("fromEmail", get_or_null(o, "fromEmail")));
            args.push(("replyToName", get_or_null(o, "replyToName")));
            args.push(("replyToEmail", get_or_null(o, "replyToEmail")));
        };
        let (method, args): (&str, Vec<(&str, Value)>) = match p.provider.as_str() {
            "mailgun" => {
                let mut a = vec![
                    ("providerId", id),
                    ("name", name),
                    ("apiKey", get(c, "apiKey")),
                    ("domain", get(c, "domain")),
                    ("isEuRegion", get(c, "isEuRegion")),
                ];
                sender(&mut a);
                a.push(("enabled", enabled));
                ("createMailgunProvider", a)
            }
            "sendgrid" | "resend" => {
                let mut a = vec![("providerId", id), ("name", name), ("apiKey", get(c, "apiKey"))];
                sender(&mut a);
                a.push(("enabled", enabled));
                (if p.provider == "sendgrid" { "createSendgridProvider" } else { "createResendProvider" }, a)
            }
            "smtp" => {
                let encryption = match crate::resources::cast_string(&get(o, "encryption")).as_str() {
                    "ssl" => "ssl",
                    "tls" => "tls",
                    _ => "none",
                };
                let host = match get(c, "host") {
                    Value::Null => v(""),
                    h => h,
                };
                let mut a = vec![
                    ("providerId", id),
                    ("name", name),
                    ("host", host),
                    ("port", get(c, "port")),
                    ("username", get_or_null(c, "username")),
                    ("password", get_or_null(c, "password")),
                    ("encryption", v(encryption)),
                    ("autoTLS", get(o, "autoTLS")),
                    ("mailer", get_or_null(o, "mailer")),
                ];
                sender(&mut a);
                a.push(("enabled", enabled));
                ("createSMTPProvider", a)
            }
            "msg91" => (
                "createMsg91Provider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("templateId", get(c, "templateId")),
                    ("senderId", get(c, "senderId")),
                    ("authKey", get(c, "authKey")),
                    ("enabled", enabled),
                ],
            ),
            "telesign" => (
                "createTelesignProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("from", get_or_null(o, "from")),
                    ("customerId", get(c, "customerId")),
                    ("apiKey", get(c, "apiKey")),
                    ("enabled", enabled),
                ],
            ),
            "textmagic" => (
                "createTextmagicProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("from", get_or_null(o, "from")),
                    ("username", get(c, "username")),
                    ("apiKey", get(c, "apiKey")),
                    ("enabled", enabled),
                ],
            ),
            "twilio" => (
                "createTwilioProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("from", get_or_null(o, "from")),
                    ("accountSid", get(c, "accountSid")),
                    ("authToken", get(c, "authToken")),
                    ("enabled", enabled),
                ],
            ),
            "vonage" => (
                "createVonageProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("from", get_or_null(o, "from")),
                    ("apiKey", get(c, "apiKey")),
                    ("apiSecret", get(c, "apiSecret")),
                    ("enabled", enabled),
                ],
            ),
            "fcm" => (
                "createFCMProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("serviceAccountJSON", get(c, "serviceAccountJSON")),
                    ("enabled", enabled),
                ],
            ),
            "apns" => (
                "createAPNSProvider",
                vec![
                    ("providerId", id),
                    ("name", name),
                    ("authKey", get(c, "authKey")),
                    ("authKeyId", get(c, "authKeyId")),
                    ("teamId", get(c, "teamId")),
                    ("bundleId", get(c, "bundleId")),
                    ("sandbox", get(o, "sandbox")),
                    ("enabled", enabled),
                ],
            ),
            other => return Err(Error::exception(format!("Unknown provider: {other}"), 0)),
        };
        self.client.invoke("Messaging", method, &args).await?;
        self.link_provider_targets(&r.id()).await
    }

    async fn create_message(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::Message(m) = r.kind() else { return Ok(()) };
        let mut state = m.message_status.clone();
        if state == "scheduled" {
            let scheduled = m.scheduled_at.clone();
            if !scheduled.is_empty() && scheduled != "0" {
                use php_std::datetime::{DateTime, TzInfo};
                let parse = |s: &[u8]| {
                    DateTime::new(s, None, &TzInfo::utc()).map_err(|e| Error::Other {
                        class: "DateMalformedStringException".into(),
                        message: e.message().into_owned(),
                        code: 0,
                    })
                };
                let at = parse(scheduled.as_bytes())?;
                let now = parse(b"now")?;
                let micros = |d: &DateTime| {
                    let text = String::from_utf8_lossy(&d.format(b"U u")).into_owned();
                    let mut parts = text.split(' ');
                    let seconds: i64 = parts.next().and_then(|p| p.parse().ok()).unwrap_or(0);
                    let fraction: i64 = parts.next().and_then(|p| p.parse().ok()).unwrap_or(0);
                    (seconds, fraction)
                };
                if micros(&at) > micros(&now) {
                    return self.create_scheduled_message(r).await;
                }
            }
            state = "draft".to_owned();
        }
        if state == "processing" {
            state = "draft".to_owned();
        }
        self.create_message_document(r, &state).await
    }

    async fn create_scheduled_message(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::Message(m) = r.kind() else { return Ok(()) };
        let d = &m.data;
        let list = |a: &Array| if a.is_empty() { Value::Null } else { Value::Array(a.clone()) };
        let or_empty = |k: &str| match get(d, k) {
            Value::Null => v(""),
            x => x,
        };
        let (topics, users, targets) = (list(&m.topics), list(&m.users), list(&m.targets));
        let id = v(&r.id());
        let scheduled = v(&m.scheduled_at);
        let (method, args): (&str, Vec<(&str, Value)>) = match m.provider_type.as_str() {
            "email" => (
                "createEmail",
                vec![
                    ("messageId", id),
                    ("subject", or_empty("subject")),
                    ("content", or_empty("content")),
                    ("topics", topics),
                    ("users", users),
                    ("targets", targets),
                    ("cc", get(d, "cc")),
                    ("bcc", get(d, "bcc")),
                    ("attachments", Value::Null),
                    ("draft", Value::Bool(false)),
                    ("html", get(d, "html")),
                    ("scheduledAt", scheduled),
                ],
            ),
            "sms" => (
                "createSMS",
                vec![
                    ("messageId", id),
                    ("content", or_empty("content")),
                    ("topics", topics),
                    ("users", users),
                    ("targets", targets),
                    ("draft", Value::Bool(false)),
                    ("scheduledAt", scheduled),
                ],
            ),
            "push" => (
                "createPush",
                vec![
                    ("messageId", id),
                    ("title", get(d, "title")),
                    ("body", get(d, "body")),
                    ("topics", topics),
                    ("users", users),
                    ("targets", targets),
                    ("data", get(d, "data")),
                    ("action", get(d, "action")),
                    ("image", get(d, "image")),
                    ("icon", get(d, "icon")),
                    ("sound", get(d, "sound")),
                    ("color", get(d, "color")),
                    ("tag", get(d, "tag")),
                    ("badge", get(d, "badge")),
                    ("draft", Value::Bool(false)),
                    ("scheduledAt", scheduled),
                    ("contentAvailable", get(d, "contentAvailable")),
                    ("critical", get(d, "critical")),
                    ("priority", Value::Null),
                ],
            ),
            t => return Err(Error::exception(format!("Unknown provider type: {t}"), 0)),
        };
        self.client.invoke("Messaging", method, &args).await?;
        Ok(())
    }

    /// PHP: `importSiteResource($resource)`.
    pub(super) async fn import_site_resource(&mut self, r: &ResourceRef) -> Result<()> {
        match r.kind() {
            Kind::Site(s) => {
                if !enum_has("BuildRuntime", &s.build_runtime) {
                    return Err(Error::exception_with(
                        format!("Invalid Build Runtime: {}", s.build_runtime),
                        Exception::CODE_VALIDATION,
                        Error::InvalidArgument(format!("Unknown BuildRuntime value: {}", s.build_runtime)),
                    ));
                }
                let framework = match s.framework.as_str() {
                    "analog" => "analog",
                    "angular" => "angular",
                    "astro" => "astro",
                    "flutter" | "flutter-web" => "flutter",
                    "lynx" => "lynx",
                    "nextjs" => "nextjs",
                    "nuxt" => "nuxt",
                    "react" => "react",
                    "react-native" => "react-native",
                    "remix" => "remix",
                    "svelte-kit" => "sveltekit",
                    "tanstack-start" => "tanstack-start",
                    "vite" => "vite",
                    "vue" => "vue",
                    _ => "other",
                };
                let adapter = match s.adapter.as_str() {
                    "static" => v("static"),
                    "ssr" => v("ssr"),
                    _ => Value::Null,
                };
                let build = if s.build_specification.is_empty() || s.build_specification == "0" {
                    s.specification.clone()
                } else {
                    s.build_specification.clone()
                };
                self.client
                    .invoke(
                        "Sites",
                        "create",
                        &[
                            ("siteId", v(&r.id())),
                            ("name", v(&s.name)),
                            ("framework", v(framework)),
                            ("buildRuntime", v(&s.build_runtime)),
                            ("enabled", Value::Bool(s.enabled)),
                            ("logging", Value::Bool(s.logging)),
                            ("timeout", Value::Int(s.timeout)),
                            ("installCommand", v(&s.install_command)),
                            ("buildCommand", v(&s.build_command)),
                            ("outputDirectory", v(&s.output_directory)),
                            ("adapter", adapter),
                            ("fallbackFile", v(&s.fallback_file)),
                            ("buildSpecification", or_null(&build)),
                            ("runtimeSpecification", or_null(&s.specification)),
                        ],
                    )
                    .await?;
            }
            Kind::SiteEnvVar(e) => {
                self.client
                    .invoke(
                        "Sites",
                        "createVariable",
                        &[
                            ("siteId", v(&e.site.id())),
                            ("variableId", v(&r.id())),
                            ("key", v(&e.key)),
                            ("value", v(&e.value)),
                        ],
                    )
                    .await?;
            }
            Kind::SiteDeployment(_) => return self.import_site_deployment(r).await,
            _ => {}
        }
        r.set_status(status::SUCCESS, "");
        Ok(())
    }

    async fn import_site_deployment(&mut self, r: &ResourceRef) -> Result<()> {
        let Kind::SiteDeployment(d) = r.kind() else { return Ok(()) };
        let site = d.site.clone();
        if site.status() != status::SUCCESS {
            r.set_status(status::SKIPPED, format!("Parent site \"{}\" failed to import", site.id()));
            return Ok(());
        }
        let site_id = site.id();
        let (start, end, data) = {
            let c = d.chunk();
            (c.start, c.end, c.data.clone())
        };
        let params = vec![
            ("siteId".to_owned(), Input::Value(v(&site_id))),
            (
                "code".to_owned(),
                Input::File { data, mime: "application/gzip".to_owned(), name: "deployment.tar.gz".to_owned() },
            ),
            ("activate".to_owned(), Input::Value(v(if d.activated { "true" } else { "false" }))),
        ];
        let path = format!("/sites/{site_id}/deployments");
        let project = self.project_id.clone();
        if d.size <= STORAGE_MAX_CHUNK_SIZE {
            let response = self
                .client
                .call(
                    "POST",
                    &path,
                    &[("content-type", "multipart/form-data".to_owned()), ("X-Appwrite-Project", project)],
                    &params,
                )
                .await?;
            let ok =
                matches!(&response, Value::Array(a) if a.get_str("$id").is_some_and(|v| !matches!(v, Value::Null)));
            if !ok {
                return Err(Error::exception("Site deployment creation failed", Exception::CODE_INTERNAL));
            }
            r.set_status(status::SUCCESS, "");
            return Ok(());
        }
        let response = self
            .client
            .call(
                "POST",
                &path,
                &[
                    ("content-type", "multipart/form-data".to_owned()),
                    ("content-range", content_range(start, end, d.size)),
                    ("x-appwrite-id", r.id()),
                    ("X-Appwrite-Project", project),
                ],
                &params,
            )
            .await?;
        self.chunked_deployment(r, &response, start, end, d.size, "Site deployment creation failed")
    }

    /// PHP: `createRule($resource)`: manual rules only; a domain another
    /// project owns is a warning.
    pub(super) async fn create_rule(&mut self, r: &ResourceRef) -> Result<bool> {
        let Kind::Rule(rule) = r.kind() else { return Ok(false) };
        if rule.trigger != "manual" {
            r.set_status(status::SKIPPED, "Auto-generated rule, recreated by parent resource migration");
            return Ok(false);
        }
        let branch = if rule.deployment_vcs_provider_branch.is_empty() {
            Value::Null
        } else {
            v(&rule.deployment_vcs_provider_branch)
        };
        let result = match rule.rule_type.as_str() {
            "api" => self.client.invoke("Proxy", "createAPIRule", &[("domain", v(&rule.domain))]).await.map(|_| ()),
            "redirect" => {
                let code = match rule.redirect_status_code {
                    302 => "302",
                    307 => "307",
                    308 => "308",
                    _ => "301",
                };
                let resource_type = if rule.deployment_resource_type == "site" { "site" } else { "function" };
                self.client
                    .invoke(
                        "Proxy",
                        "createRedirectRule",
                        &[
                            ("domain", v(&rule.domain)),
                            ("url", v(&rule.redirect_url)),
                            ("statusCode", v(code)),
                            ("resourceId", v(&rule.deployment_resource_id)),
                            ("resourceType", v(resource_type)),
                        ],
                    )
                    .await
                    .map(|_| ())
            }
            "deployment" => match rule.deployment_resource_type.as_str() {
                "function" => self
                    .client
                    .invoke(
                        "Proxy",
                        "createFunctionRule",
                        &[
                            ("domain", v(&rule.domain)),
                            ("functionId", v(&rule.deployment_resource_id)),
                            ("branch", branch),
                        ],
                    )
                    .await
                    .map(|_| ()),
                "site" => self
                    .client
                    .invoke(
                        "Proxy",
                        "createSiteRule",
                        &[("domain", v(&rule.domain)), ("siteId", v(&rule.deployment_resource_id)), ("branch", branch)],
                    )
                    .await
                    .map(|_| ()),
                other => {
                    r.set_status(status::SKIPPED, format!("Unsupported deployment resource type \"{other}\""));
                    return Ok(false);
                }
            },
            other => {
                r.set_status(status::SKIPPED, format!("Unsupported rule type \"{other}\""));
                return Ok(false);
            }
        };
        match result {
            Ok(()) => Ok(true),
            Err(Error::Appwrite { code: 409, .. }) => {
                r.set_status(
                    status::WARNING,
                    format!(
                        "Domain \"{}\" is owned by another project. Remove it there and re-run the migration.",
                        rule.domain
                    ),
                );
                Ok(false)
            }
            Err(e) => Err(e),
        }
    }
}
