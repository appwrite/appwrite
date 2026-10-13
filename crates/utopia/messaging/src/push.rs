//! Push adapters: [`Apns`] (Apple, HTTP/2 with an ES256 token) and [`Fcm`]
//! (Firebase, with an RS256 service-account assertion exchanged for an
//! OAuth2 access token).
//!
//! PHP: `Utopia\Messaging\Adapter\Push` and its subclasses.

use std::sync::{Mutex, MutexGuard};

use php_std::Value;

use crate::adapter::{Adapter, Base, configure};
use crate::error::Error;
use crate::http::{BoxFuture, Call, Http, Multi, Reply};
use crate::message::{Message, MessageType, Priority, Push};
use crate::response::{Response, Sent};
use crate::values::{K, Site, at, interpolate, map, result_text, scalar_text, set, text};

/// The kind of every push adapter.
///
/// PHP: `Adapter\Push::TYPE`.
pub const TYPE: &str = "push";

/// The error of a token the provider no longer knows.
///
/// PHP: `Adapter\Push::EXPIRED_MESSAGE`.
pub const EXPIRED_MESSAGE: &str = "Expired device token";

fn push(message: &Message) -> &Push {
    match message {
        Message::Push(push) => push,
        _ => unreachable!("send() checks the message type"),
    }
}

fn now() -> i64 {
    php_std::datetime::time()
}

/// Apple Push Notification service, one HTTP/2 request per device token,
/// authenticated with an ES256 token of the team's key.
///
/// PHP: `Utopia\Messaging\Adapter\Push\APNS`.
#[derive(Debug)]
pub struct Apns {
    auth_key: String,
    auth_key_id: String,
    team_id: String,
    bundle_id: String,
    sandbox: bool,
    base: Base,
}

impl Apns {
    /// Sends for the app `bundle_id` with the team's key (`auth_key`, a PEM
    /// P-256 key, and its ID).
    ///
    /// PHP: `new APNS($authKey, $authKeyId, $teamId, $bundleId, $sandbox)`.
    pub fn new(
        auth_key: impl Into<String>,
        auth_key_id: impl Into<String>,
        team_id: impl Into<String>,
        bundle_id: impl Into<String>,
    ) -> Apns {
        Apns {
            auth_key: auth_key.into(),
            auth_key_id: auth_key_id.into(),
            team_id: team_id.into(),
            bundle_id: bundle_id.into(),
            sandbox: false,
            base: Base::new(Http::new("APNS").with_http2()),
        }
    }

    /// Through the development environment.
    pub fn with_sandbox(mut self, sandbox: bool) -> Apns {
        self.sandbox = sandbox;
        self
    }

    async fn send_push(&self, message: &Push) -> Result<Sent, Error> {
        let mut payload = Value::Array(php_std::Array::new());
        if let Some(title) = message.title() {
            set(&mut payload, &["aps", "alert", "title"], text(title));
        }
        if let Some(body) = message.body() {
            set(&mut payload, &["aps", "alert", "body"], text(body));
        }
        if let Some(data) = message.data() {
            set(&mut payload, &["aps", "data"], data.clone());
        }
        if let Some(action) = message.action() {
            set(&mut payload, &["aps", "category"], text(action));
        }
        if message.critical().is_some() {
            set(&mut payload, &["aps", "sound", "critical"], Value::Int(1));
            set(&mut payload, &["aps", "sound", "name"], text("default"));
            set(&mut payload, &["aps", "sound", "volume"], Value::Float(1.0));
        }
        if let Some(sound) = message.sound() {
            if message.critical().is_some() {
                set(&mut payload, &["aps", "sound", "name"], text(sound));
            } else {
                set(&mut payload, &["aps", "sound"], text(sound));
            }
        }
        if let Some(badge) = message.badge() {
            set(&mut payload, &["aps", "badge"], Value::Int(badge));
        }
        if let Some(available) = message.content_available() {
            set(&mut payload, &["aps", "content-available"], Value::Int(i64::from(available)));
        }
        if let Some(priority) = message.priority() {
            let value = if priority == Priority::High { "10" } else { "5" };
            set(&mut payload, &["headers", "apns-priority"], text(value));
        }

        let issued = now();
        let claims =
            map([("iss", text(&self.team_id)), ("iat", Value::Int(issued)), ("exp", Value::Int(issued + 3600))]);
        let jwt = crate::jwt::encode(&claims, self.auth_key.as_bytes(), "ES256", Some(&self.auth_key_id))?;
        let endpoint =
            if self.sandbox { "https://api.development.push.apple.com" } else { "https://api.push.apple.com" };
        let urls = message.to().iter().map(|token| format!("{endpoint}/3/device/{token}")).collect();

        // Each result is taken as its request completes, in completion order.
        let response = Mutex::new(Response::new(TYPE));
        let on_result = |result: Reply| -> Result<(), Error> {
            let device = String::from_utf8_lossy(php_std::path::basename(result.url.as_bytes(), b"")).into_owned();
            if result.status_code == 200 {
                let mut response = locked(&response);
                response.increment_delivered_to();
                response.add_success(device);
                return Ok(());
            }
            let reason = at(&result.response, &[K::S("reason")]);
            let expired =
                matches!(&reason, Some(Value::Str(s)) if matches!(s.as_bytes(), b"ExpiredToken" | b"BadDeviceToken"));
            let error = if expired {
                EXPIRED_MESSAGE.to_owned()
            } else {
                let error = reason.unwrap_or_else(|| {
                    text(if result.error.is_empty() || result.error == "0" { "Unknown error" } else { &result.error })
                });
                result_text(&error, Site { argument: 2, file: "Adapter/Push/APNS.php", line: 135 })?
            };
            locked(&response).add_failure(device, error);
            Ok(())
        };
        self.base
            .request_multi(
                Multi::new(
                    "POST",
                    urls,
                    vec![
                        "Content-Type: application/json".into(),
                        format!("Authorization: Bearer {jwt}"),
                        format!("apns-topic: {}", self.bundle_id),
                        "apns-push-type: alert".into(),
                    ],
                    vec![payload],
                ),
                Some(&on_result),
            )
            .await?;
        Ok(Sent::Response(response.into_inner().unwrap_or_else(|e| e.into_inner())))
    }
}

configure!(Apns, |_| Http::new("APNS").with_http2());

impl Adapter for Apns {
    fn name(&self) -> &str {
        "APNS"
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Push
    }

    fn max_messages_per_request(&self) -> i64 {
        5000
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_push(push(message)))
    }
}

/// Firebase Cloud Messaging (HTTP v1): the service account's RS256
/// assertion is exchanged for an access token, then one request per device
/// token.
///
/// PHP: `Utopia\Messaging\Adapter\Push\FCM`.
#[derive(Debug)]
pub struct Fcm {
    service_account: String,
    base: Base,
}

/// Where the assertion is exchanged for an access token.
///
/// PHP: `FCM::GOOGLE_TOKEN_URL`.
pub const GOOGLE_TOKEN_URL: &str = "https://www.googleapis.com/oauth2/v4/token";

impl Fcm {
    /// Sends with a service account's JSON key file contents.
    ///
    /// PHP: `new FCM($serviceAccountJSON)`.
    pub fn new(service_account: impl Into<String>) -> Fcm {
        Fcm { service_account: service_account.into(), base: Base::new(Http::new("FCM")) }
    }

    async fn send_push(&self, message: &Push) -> Result<Sent, Error> {
        let credentials = php_std::json::decode(
            self.service_account.as_bytes(),
            Some(true),
            php_std::json::DEFAULT_DEPTH,
            php_std::json::Flags::NONE,
        )
        .unwrap_or(Value::Null);
        let field = |key| at(&credentials, &[K::S(key)]).unwrap_or(Value::Null);
        let issued = now();
        let key = match field("private_key") {
            Value::Null | Value::Array(_) => {
                let given = if matches!(field("private_key"), Value::Null) { "null" } else { "array" };
                return Err(Error::Type(format!(
                    "Utopia\\Messaging\\Helpers\\JWT::encode(): Argument #2 ($key) must be of type string, {given} given, called in /usr/src/code/packages/messaging/src/Adapter/Push/FCM.php on line 63"
                )));
            }
            other => scalar_text(&other).unwrap_or_default(),
        };
        let claims = map([
            ("iss", field("client_email")),
            ("exp", Value::Int(issued + 3600)),
            ("iat", Value::Int(issued - 60)),
            ("scope", text("https://www.googleapis.com/auth/firebase.messaging")),
            ("aud", text(GOOGLE_TOKEN_URL)),
        ]);
        let jwt = crate::jwt::encode(&claims, key.as_bytes(), "RS256", None)?;
        let token = self
            .base
            .request(Call::new(
                "POST",
                GOOGLE_TOKEN_URL,
                vec!["Content-Type: application/x-www-form-urlencoded".into()],
                Some(map([
                    ("grant_type", text("urn:ietf:params:oauth:grant-type:jwt-bearer")),
                    ("assertion", text(&jwt)),
                ])),
            ))
            .await?;
        let access_token = match &token.response {
            Value::Array(_) if token.status_code == 200 => at(&token.response, &[K::S("access_token")]),
            _ => None,
        };
        let Some(access_token) = access_token else {
            let reason =
                if token.error.is_empty() { format!("HTTP {}", token.status_code) } else { token.error.clone() };
            return Err(Error::Exception(format!("Failed to obtain FCM access token: {reason}")));
        };

        let mut shared = Value::Array(php_std::Array::new());
        if let Some(title) = message.title() {
            set(&mut shared, &["message", "notification", "title"], text(title));
        }
        if let Some(body) = message.body() {
            set(&mut shared, &["message", "notification", "body"], text(body));
        }
        if let Some(data) = message.data() {
            set(&mut shared, &["message", "data"], data.clone());
        }
        if let Some(action) = message.action() {
            set(&mut shared, &["message", "android", "notification", "click_action"], text(action));
            set(&mut shared, &["message", "apns", "payload", "aps", "category"], text(action));
        }
        if let Some(image) = message.image() {
            set(&mut shared, &["message", "android", "notification", "image"], text(image));
            set(&mut shared, &["message", "apns", "payload", "aps", "mutable-content"], Value::Int(1));
            set(&mut shared, &["message", "apns", "fcm_options", "image"], text(image));
        }
        if message.critical().is_some() {
            set(&mut shared, &["message", "apns", "payload", "aps", "sound", "critical"], Value::Int(1));
        }
        if let Some(sound) = message.sound() {
            set(&mut shared, &["message", "android", "notification", "sound"], text(sound));
            if message.critical().is_some() {
                set(&mut shared, &["message", "apns", "payload", "aps", "sound", "name"], text(sound));
            } else {
                set(&mut shared, &["message", "apns", "payload", "aps", "sound"], text(sound));
            }
        }
        for (path, value) in [
            ("icon", message.icon()),
            ("color", message.color()),
            ("tag", message.tag()),
            ("channel_id", message.channel_id()),
        ] {
            if let Some(value) = value {
                set(&mut shared, &["message", "android", "notification", path], text(value));
            }
        }
        if let Some(badge) = message.badge() {
            set(&mut shared, &["message", "apns", "payload", "aps", "badge"], Value::Int(badge));
        }
        if let Some(available) = message.content_available() {
            set(
                &mut shared,
                &["message", "apns", "payload", "aps", "content-available"],
                Value::Int(i64::from(available)),
            );
        }
        if let Some(priority) = message.priority() {
            let (android, apns) = if priority == Priority::High { ("high", "10") } else { ("normal", "5") };
            set(&mut shared, &["message", "android", "priority"], text(android));
            set(&mut shared, &["message", "apns", "headers", "apns-priority"], text(apns));
        }

        let bodies = message
            .to()
            .iter()
            .map(|to| {
                let mut body = shared.clone();
                set(&mut body, &["message", "token"], text(to));
                body
            })
            .collect();
        let project = interpolate(&field("project_id"));

        // Each result is taken as its request completes, in completion order.
        let to = message.to();
        let response = Mutex::new(Response::new(TYPE));
        let on_result = |result: Reply| -> Result<(), Error> {
            let recipient = result.index.and_then(|i| to.get(i)).cloned().unwrap_or_default();
            let mut response = locked(&response);
            if result.status_code == 200 {
                response.increment_delivered_to();
                response.add_success(recipient);
            } else {
                response.add_failure(recipient, error(&result));
            }
            Ok(())
        };
        self.base
            .request_multi(
                Multi::new(
                    "POST",
                    vec![format!("https://fcm.googleapis.com/v1/projects/{project}/messages:send")],
                    vec![
                        "Content-Type: application/json".into(),
                        format!("Authorization: Bearer {}", interpolate(&access_token)),
                    ],
                    bodies,
                ),
                Some(&on_result),
            )
            .await?;
        Ok(Sent::Response(response.into_inner().unwrap_or_else(|e| e.into_inner())))
    }
}

/// The value behind a lock no holder can leave inconsistent.
fn locked<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// A failed send's reason: an expired token, FCM's message, or the
/// transport failure with the status and cURL error number.
///
/// PHP: `FCM::getError()`.
pub fn error(result: &Reply) -> String {
    let status = at(&result.response, &[K::S("error"), K::S("status")]);
    if let Some(Value::Array(_)) = at(&result.response, &[K::S("error")])
        && matches!(&status, Some(Value::Str(s)) if matches!(s.as_bytes(), b"UNREGISTERED" | b"NOT_FOUND"))
    {
        return EXPIRED_MESSAGE.to_owned();
    }
    if let Some(Value::Array(_)) = at(&result.response, &[K::S("error")])
        && let Some(Value::Str(message)) = at(&result.response, &[K::S("error"), K::S("message")])
        && !message.is_empty()
    {
        return String::from_utf8_lossy(message.as_bytes()).into_owned();
    }
    let details = format!("HTTP status {}; cURL error code {}", result.status_code, result.error_code);
    if result.error.is_empty() {
        format!("Request failed ({details})")
    } else {
        format!("{} ({details})", result.error)
    }
}

configure!(Fcm, |_| Http::new("FCM"));

impl Adapter for Fcm {
    fn name(&self) -> &str {
        "FCM"
    }

    fn kind(&self) -> &str {
        TYPE
    }

    fn message_type(&self) -> MessageType {
        MessageType::Push
    }

    fn max_messages_per_request(&self) -> i64 {
        5000
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn process<'a>(&'a self, message: &'a Message) -> BoxFuture<'a, Result<Sent, Error>> {
        Box::pin(self.send_push(push(message)))
    }
}
