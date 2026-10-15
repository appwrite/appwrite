//! OAuth2 sign-in (`Appwrite\Auth\OAuth2` and its providers, and the
//! OAuth2 routes of `account.php`): the providers' authorization URLs and
//! states, the browser flow's callback and redirect, the session refresh
//! and the native ID token sign-in.
//!
//! Every provider's authorization URL is ported. The code exchange and the
//! user lookup call the provider: they are ported for the mock providers PHP
//! tests with (served by PHP's own `/v1/mock` routes); the other providers'
//! exchange answers `project_provider_unsupported`, so no production third
//! party is ever called from here.

use serde_json::{Map, Value as Json, json};
use utopia_database::{Document, Query, Value};
use utopia_http::Cookie;
use utopia_http::Response;
use utopia_validators::{ArrayList, Range, Text, WhiteList};

use appwrite_core::database::documents::{self, Attributes, Session, User};
use appwrite_core::datetime;
use appwrite_core::platform::Context;
use appwrite_core::response::SessionModel;
use appwrite_core::{Error, ErrorType, Result};

use crate::base::{self, Metadata, UrlParts, token_type};

/// `$oauthDefaultSuccess` / `$oauthDefaultFailure`.
const DEFAULT_SUCCESS: &str = "/auth/oauth2/success";
const DEFAULT_FAILURE: &str = "/auth/oauth2/failure";

/// `app/config/oAuthProviders.php`: key, name, mock.
pub const PROVIDERS: &[(&str, &str, bool)] = &[
    ("amazon", "Amazon", false),
    ("apple", "Apple", false),
    ("appwrite", "Appwrite", false),
    ("auth0", "Auth0", false),
    ("authentik", "Authentik", false),
    ("autodesk", "Autodesk", false),
    ("bitbucket", "BitBucket", false),
    ("bitly", "Bitly", false),
    ("box", "Box", false),
    ("cloudflare", "Cloudflare", false),
    ("dailymotion", "Dailymotion", false),
    ("discord", "Discord", false),
    ("disqus", "Disqus", false),
    ("dropbox", "Dropbox", false),
    ("etsy", "Etsy", false),
    ("facebook", "Facebook", false),
    ("figma", "Figma", false),
    ("fusionauth", "FusionAuth", false),
    ("github", "GitHub", false),
    ("gitlab", "GitLab", false),
    ("google", "Google", false),
    ("huggingface", "Hugging Face", false),
    ("kakao", "Kakao", false),
    ("keycloak", "Keycloak", false),
    ("kick", "Kick", false),
    ("linkedin", "LinkedIn", false),
    ("microsoft", "Microsoft", false),
    ("notion", "Notion", false),
    ("oidc", "OpenID Connect", false),
    ("okta", "Okta", false),
    ("paypal", "PayPal", false),
    ("paypalSandbox", "PayPal Sandbox", false),
    ("podio", "Podio", false),
    ("resend", "Resend", false),
    ("salesforce", "Salesforce", false),
    ("slack", "Slack", false),
    ("spotify", "Spotify", false),
    ("stripe", "Stripe", false),
    ("tiktok", "TikTok", false),
    ("tradeshift", "Tradeshift", false),
    ("tradeshiftBox", "Tradeshift Sandbox", false),
    ("twitch", "Twitch", false),
    ("webflow", "Webflow", false),
    ("wordpress", "WordPress", false),
    ("x", "X", false),
    ("yahoo", "Yahoo", false),
    ("yammer", "Yammer", false),
    ("yandex", "Yandex", false),
    ("zoho", "Zoho", false),
    ("zoom", "Zoom", false),
    ("mock", "Mock", true),
    ("mock-unverified", "MockUnverified", true),
    ("mock-no-email", "MockNoEmail", true),
];

/// The providers' keys (`WhiteList(array_keys(oAuthProviders), true)`).
fn provider_keys() -> Vec<&'static str> {
    PROVIDERS.iter().map(|(k, _, _)| *k).collect()
}

fn provider_name(provider: &str) -> &'static str {
    PROVIDERS.iter().find(|(k, _, _)| *k == provider).map(|(_, n, _)| *n).unwrap_or("")
}

/// The providers with native ID token sign-in, and their `idToken` profile:
/// issuers, JWKS URL, whether a nonce is required.
fn id_token_profile(provider: &str) -> Option<(&'static [&'static str], &'static str, bool)> {
    match provider {
        "apple" => Some((&["https://appleid.apple.com"], "https://appleid.apple.com/auth/keys", true)),
        "google" => Some((
            &["https://accounts.google.com", "accounts.google.com"],
            "https://www.googleapis.com/oauth2/v3/certs",
            false,
        )),
        "mock" => Some((&["https://localhost/v1/mock"], "http://localhost/v1/mock/tests/general/oauth2/jwks", false)),
        "mock-unverified" => {
            Some((&["https://localhost/v1/mock"], "http://localhost/v1/mock/tests/general/oauth2/jwks", true))
        }
        _ => None,
    }
}

// ---- encoding helpers --------------------------------------------------------

/// `json_encode($value)` with PHP's default flags (escaped slashes).
pub fn php_json(value: &Json) -> String {
    php_std::json::encode(
        &php_std::Value::<php_std::Never>::from_json(value),
        php_std::json::Flags::NONE,
        php_std::json::DEFAULT_DEPTH,
    )
    .unwrap_or_default()
}

/// `json_decode($text, true)`: `None` for invalid JSON.
fn php_decode(text: &str) -> Option<Json> {
    php_std::json::decode(text.as_bytes(), Some(true), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE)
        .ok()
        .map(|v| v.to_json())
}

fn base64url(bytes: &[u8]) -> String {
    php_std::encoding::base64_encode(bytes).replace('+', "-").replace('/', "_").trim_end_matches('=').to_owned()
}

fn base64url_decode(text: &str) -> Option<Vec<u8>> {
    php_std::encoding::base64_decode(text.replace('-', "+").replace('_', "/").as_bytes(), true)
}

/// `html_entity_decode($text)` for the entities a provider sends back.
fn html_entity_decode(text: &str) -> String {
    text.replace("&quot;", "\"")
        .replace("&#039;", "'")
        .replace("&#39;", "'")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&amp;", "&")
}

// ---- providers ------------------------------------------------------------------

/// A provider adapter (`new $className($client, $appId, $appSecret, $callback, $state, $scopes)`).
pub struct Provider {
    pub name: String,
    pub app_id: String,
    pub app_secret: String,
    pub callback: String,
    pub state: Map<String, Json>,
    pub scopes: Vec<String>,
    /// The PKCE verifier (`getPKCEVerifier()`), made on first use.
    pkce: Option<String>,
    tokens: Option<Map<String, Json>>,
    user: Option<Map<String, Json>>,
}

/// The default scopes of each provider.
fn default_scopes(provider: &str) -> &'static [&'static str] {
    match provider {
        "amazon" => &["profile"],
        "apple" => &["name", "email"],
        "appwrite" | "huggingface" | "oidc" | "linkedin" | "yahoo" => &["openid", "profile", "email"],
        "auth0" | "authentik" | "fusionauth" | "keycloak" | "okta" => &["openid", "profile", "email", "offline_access"],
        "autodesk" => &["user-profile:read"],
        "box" => &["manage_app_users"],
        "cloudflare" => &["user-details.read", "offline_access"],
        "dailymotion" => &["userinfo", "email"],
        "discord" => &["identify", "email"],
        "disqus" => &["read", "email"],
        "etsy" => &["email_r", "profile_r"],
        "facebook" | "mock" | "mock-unverified" | "mock-no-email" => &["email"],
        "figma" => &["current_user:read"],
        "github" => &["user:email"],
        "gitea" => &["read:user"],
        "gitlab" => &["read_user", "openid", "email"],
        "google" => &[
            "https://www.googleapis.com/auth/userinfo.email",
            "https://www.googleapis.com/auth/userinfo.profile",
            "openid",
        ],
        "kick" => &["user:read"],
        "microsoft" => &["offline_access", "user.read"],
        "paypal" | "paypalSandbox" => &["openid", "profile", "email"],
        "salesforce" => &["openid", "profile"],
        "slack" => &["openid", "email", "profile"],
        "spotify" => &["user-read-email"],
        "stripe" => &["read_write"],
        "tiktok" => &["user.info.basic"],
        "tradeshift" | "tradeshiftBox" => &["openid", "offline"],
        "twitch" => &["user:read:email"],
        "webflow" => &["authorized_user:read"],
        "wordpress" => &["auth"],
        "x" => &["tweet.read", "users.read", "users.email", "offline.access"],
        "zoho" => &["email", "profile"],
        "zoom" => &["user_info:read"],
        _ => &[],
    }
}

/// Whether the provider keeps a PKCE verifier in its state.
fn uses_pkce(provider: &str) -> bool {
    matches!(provider, "appwrite" | "cloudflare" | "etsy" | "kick" | "resend" | "x")
}

impl Provider {
    pub fn new(
        name: &str,
        app_id: &str,
        app_secret: &str,
        callback: &str,
        state: Map<String, Json>,
        scopes: &[String],
    ) -> Self {
        let mut all: Vec<String> = default_scopes(name).iter().map(|s| (*s).to_owned()).collect();
        for scope in scopes {
            if !all.contains(scope) {
                all.push(scope.clone());
            }
        }
        Self {
            name: name.to_owned(),
            app_id: app_id.to_owned(),
            app_secret: app_secret.to_owned(),
            callback: callback.to_owned(),
            state,
            scopes: all,
            pkce: None,
            tokens: None,
            user: None,
        }
    }

    /// The app secret as JSON (`getAppSecret()`); `lenient` providers read
    /// a plain secret as `{clientSecret}`, the others refuse it.
    fn secret(&self, lenient: bool) -> Result<Map<String, Json>> {
        match serde_json::from_str::<Json>(&self.app_secret) {
            Ok(Json::Object(m)) => Ok(m),
            Ok(_) | Err(_) if lenient => {
                let mut m = Map::new();
                m.insert("clientSecret".into(), Json::String(self.app_secret.clone()));
                Ok(m)
            }
            Ok(Json::Array(a)) if a.is_empty() => Ok(Map::new()),
            Ok(_) => Ok(Map::new()),
            Err(_) => Err(Error::internal("Invalid secret")),
        }
    }

    /// `implode($glue, $secret['prompt'] ?? [])`.
    fn prompt(&self, lenient: bool, glue: &str) -> Result<String> {
        let secret = self.secret(lenient)?;
        Ok(match secret.get("prompt") {
            Some(Json::Array(items)) => items.iter().map(json_text).collect::<Vec<_>>().join(glue),
            Some(Json::String(s)) if self.name == "oidc" => s.clone(),
            _ => String::new(),
        })
    }

    fn secret_field(&self, key: &str, default: &str) -> Result<String> {
        Ok(self.secret(false)?.get(key).map(json_text).unwrap_or_else(|| default.to_owned()))
    }

    fn joined_scopes(&self, glue: &str) -> String {
        self.scopes.join(glue)
    }

    /// `json_encode($this->state)`.
    fn state_json(&self) -> String {
        php_json(&Json::Object(self.state.clone()))
    }

    /// `getPKCEVerifier()`.
    fn pkce_verifier(&mut self) -> String {
        if self.pkce.is_none() {
            let mut bytes = [0u8; 64];
            utopia_auth::random_bytes(&mut bytes);
            self.pkce = Some(base64url(&bytes));
        }
        self.pkce.clone().unwrap_or_default()
    }

    /// `getPKCEChallenge()`.
    fn pkce_challenge(&mut self) -> String {
        use sha2::Digest;
        base64url(&sha2::Sha256::digest(self.pkce_verifier().as_bytes()))
    }

    /// `withPKCEState($state)`: the verifier encrypted into the state.
    fn pkce_state(&mut self) -> Result<String> {
        let key = utopia_system::env_or("_APP_OPENSSL_KEY_V1", "").into_owned();
        if key.is_empty() {
            return Err(Error::internal(format!(
                "{} OAuth2 requires _APP_OPENSSL_KEY_V1 to encrypt PKCE state.",
                self.name
            )));
        }
        let verifier = self.pkce_verifier();
        let (data, iv, tag) = appwrite_core::crypto::seal(&key, verifier.as_bytes());
        let mut state = self.state.clone();
        state.insert("_pkce".into(), json!({"data": base64url(&data), "iv": hex::encode(iv), "tag": hex::encode(tag)}));
        Ok(php_json(&Json::Object(state)))
    }

    /// `getLoginURL()`.
    pub fn login_url(&mut self) -> Result<String> {
        let state = self.state_json();
        let id = Json::String(self.app_id.clone());
        let callback = Json::String(self.callback.clone());
        let s = |v: String| Json::String(v);
        let query = |pairs: Vec<(&str, Json)>| {
            let map: Map<String, Json> =
                pairs.into_iter().filter(|(_, v)| !v.is_null()).map(|(k, v)| (k.to_owned(), v)).collect();
            base::build_query(&map)
        };
        let optional = |v: String| if v.is_empty() || v == "0" { Json::Null } else { Json::String(v) };
        let spaced = self.joined_scopes(" ");
        Ok(match self.name.as_str() {
            "amazon" => format!(
                "https://www.amazon.com/ap/oa?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("state", s(php_std::encoding::urlencode(state.as_bytes()))),
                    ("redirect_uri", callback),
                ])
            ),
            "apple" => format!(
                "https://appleid.apple.com/auth/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("response_type", s("code".into())),
                    ("response_mode", s("form_post".into())),
                    ("scope", s(spaced)),
                ])
            ),
            "appwrite" | "kick" | "resend" | "cloudflare" | "etsy" | "x" => return self.pkce_url(),
            "auth0" => format!(
                "https://{}/authorize?{}",
                self.secret_field("auth0Domain", "")?,
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                    ("prompt", optional(self.prompt(false, " ")?)),
                ])
            ),
            "authentik" => format!(
                "https://{}/application/o/authorize/?{}",
                self.secret_field("authentikDomain", "")?,
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                ])
            ),
            "autodesk" => format!(
                "https://developer.api.autodesk.com/authentication/v1/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                ])
            ),
            "bitbucket" => format!(
                "https://bitbucket.org/site/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "bitly" => format!(
                "https://bitly.com/oauth/authorize?{}",
                query(vec![("client_id", id), ("redirect_uri", callback), ("state", s(state))])
            ),
            "box" => format!(
                "https://account.box.com/api/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(self.joined_scopes(","))),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                ])
            ),
            "dailymotion" => format!(
                "https://www.dailymotion.com/oauth/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("state", s(state)),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                ])
            ),
            "discord" => format!(
                "https://discordapp.com/api/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("redirect_uri", callback),
                    ("prompt", optional(self.prompt(true, " ")?)),
                ])
            ),
            "disqus" => format!(
                "https://disqus.com/api/oauth/2.0/authorize/?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("state", s(state)),
                    ("redirect_uri", callback),
                    ("scope", s(self.joined_scopes(","))),
                ])
            ),
            "dropbox" => format!(
                "https://www.dropbox.com/oauth2/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("response_type", s("code".into())),
                ])
            ),
            "facebook" => format!(
                "https://www.facebook.com/v2.8/dialog/oauth?{}",
                query(vec![("client_id", id), ("redirect_uri", callback), ("scope", s(spaced)), ("state", s(state))])
            ),
            "figma" => format!(
                "https://www.figma.com/oauth?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "fusionauth" => format!(
                "https://{}/oauth2/authorize?{}",
                self.secret_field("fusionAuthDomain", "")?,
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                ])
            ),
            "github" => format!(
                "https://github.com/login/oauth/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                    ("prompt", optional(self.prompt(true, " ")?)),
                ])
            ),
            "gitlab" => {
                let secret = self.secret(true)?;
                let endpoint = secret.get("endpoint").map(json_text).unwrap_or_else(|| "https://gitlab.com".into());
                let endpoint = if endpoint.is_empty() || endpoint == "0" {
                    "https://gitlab.com".to_owned()
                } else {
                    endpoint.trim_end_matches('/').to_owned()
                };
                format!(
                    "{endpoint}/oauth/authorize?{}",
                    query(vec![
                        ("client_id", id),
                        ("redirect_uri", callback),
                        ("scope", s(spaced)),
                        ("state", s(state)),
                        ("response_type", s("code".into())),
                    ])
                )
            }
            "google" => {
                let prompt = self.prompt(true, " ")?;
                let prompt = if prompt.is_empty() { "consent".to_owned() } else { prompt };
                format!(
                    "https://accounts.google.com/o/oauth2/v2/auth?{}",
                    query(vec![
                        ("client_id", id),
                        ("redirect_uri", callback),
                        ("scope", s(spaced)),
                        ("state", s(state)),
                        ("response_type", s("code".into())),
                        ("access_type", s("offline".into())),
                        ("prompt", s(prompt)),
                    ])
                )
            }
            "huggingface" => format!(
                "https://huggingface.co/oauth/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "kakao" => {
                let mut pairs = vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                    ("state", s(state)),
                    ("prompt", optional(self.prompt(true, ",")?)),
                ];
                if !self.scopes.is_empty() {
                    pairs.push(("scope", s(self.joined_scopes(","))));
                }
                format!("https://kauth.kakao.com/oauth/authorize?{}", query(pairs))
            }
            "keycloak" => format!(
                "https://{}/realms/{}/protocol/openid-connect/auth?{}",
                self.secret_field("keycloakDomain", "")?,
                php_std::encoding::rawurlencode(self.secret_field("keycloakRealm", "")?.as_bytes()),
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                ])
            ),
            "linkedin" => format!(
                "https://www.linkedin.com/oauth/v2/authorization?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "microsoft" => format!(
                "https://login.microsoftonline.com/{}/oauth2/v2.0/authorize?{}",
                self.secret_field("tenantID", "")?,
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                    ("response_mode", s("query".into())),
                    ("prompt", optional(self.prompt(false, " ")?)),
                ])
            ),
            "mock" | "mock-unverified" | "mock-no-email" => format!(
                "http://localhost/v1/mock/tests/general/oauth2?{}",
                query(vec![("client_id", id), ("redirect_uri", callback), ("scope", s(spaced)), ("state", s(state))])
            ),
            "notion" => format!(
                "https://api.notion.com/v1/oauth/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                    ("state", s(state)),
                    ("owner", s("user".into())),
                ])
            ),
            "oidc" => {
                let secret = self.secret(false)?;
                let mut pairs = vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                ];
                let prompt = self.prompt(false, " ")?;
                if !prompt.is_empty() && prompt != "0" {
                    pairs.push(("prompt", s(prompt)));
                }
                match secret.get("maxAge") {
                    None | Some(Json::Null) => {}
                    Some(Json::String(v)) if v.is_empty() => {}
                    Some(v) => pairs.push(("max_age", json!(php_intval(v)))),
                }
                let endpoint = secret.get("authorizationEndpoint").map(json_text).unwrap_or_default();
                if endpoint.is_empty() {
                    return Err(Error::with_code(
                        ErrorType::ProjectProviderUnsupported,
                        "OpenID Connect discovery is not supported by this server",
                        400,
                    ));
                }
                format!("{endpoint}?{}", query(pairs))
            }
            "okta" => format!(
                "https://{}/oauth2/{}/v1/authorize?{}",
                self.secret_field("oktaDomain", "")?,
                self.secret_field("authorizationServerId", "default")?,
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                    ("scope", s(spaced)),
                    ("response_type", s("code".into())),
                    ("prompt", optional(self.prompt(false, " ")?)),
                ])
            ),
            "paypal" | "paypalSandbox" => {
                // PHP's sandbox class redeclares `$environment`, which the
                // parent's private `$endpoint` lookup never sees: both use live.
                let endpoint = "https://www.paypal.com/";
                format!(
                    "{endpoint}connect/?{}",
                    query(vec![
                        ("flowEntry", s("static".into())),
                        ("response_type", s("code".into())),
                        ("client_id", id),
                        ("scope", s(spaced)),
                        ("redirect_uri", s(self.callback.replace("localhost", "127.0.0.1"))),
                        ("state", s(state)),
                    ])
                )
            }
            "podio" => format!(
                "https://podio.com/oauth/authorize?{}",
                query(vec![("client_id", id), ("state", s(state)), ("redirect_uri", callback)])
            ),
            "salesforce" => format!(
                "https://login.salesforce.com/services/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                    ("prompt", optional(self.prompt(true, " ")?)),
                ])
            ),
            "slack" => format!(
                "https://slack.com/oauth/v2/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("user_scope", s(spaced)),
                    ("redirect_uri", callback),
                    ("state", s(state))
                ])
            ),
            "spotify" => format!(
                "https://accounts.spotify.com/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                ])
            ),
            "stripe" => format!(
                "https://connect.stripe.com/oauth/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "tiktok" => format!(
                "https://www.tiktok.com/v2/auth/authorize/?{}",
                query(vec![
                    ("client_key", id),
                    ("response_type", s("code".into())),
                    ("scope", s(self.joined_scopes(","))),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                ])
            ),
            "tradeshift" | "tradeshiftBox" => {
                // As PayPal's: the sandbox class's `$environment` is not the
                // one the parent reads, so both use the live API.
                let domain = "api.tradeshift.com";
                format!(
                    "https://{domain}/tradeshift/auth/login?{}",
                    query(vec![
                        ("response_type", s("code".into())),
                        ("client_id", id),
                        ("scope", s(spaced)),
                        ("redirect_uri", s(self.callback.replace("localhost", "127.0.0.1"))),
                        ("state", s(state)),
                    ])
                )
            }
            "twitch" => format!(
                "https://id.twitch.tv/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("redirect_uri", callback),
                    ("force_verify", json!(true)),
                    ("state", s(state)),
                ])
            ),
            "webflow" => format!(
                "https://webflow.com/oauth/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "wordpress" => format!(
                "https://public-api.wordpress.com/oauth2/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                    ("scope", json!(self.scopes)),
                    ("state", s(state)),
                ])
            ),
            "yahoo" => format!(
                "https://api.login.yahoo.com/oauth2/request_auth?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                ])
            ),
            // Yammer declares no scopes: adding one fails in PHP
            // (`$scopes` must not be accessed before initialization).
            "yammer" if !self.scopes.is_empty() => {
                return Err(Error::internal(
                    "Typed property Appwrite\\Auth\\OAuth2::$scopes must not be accessed before initialization",
                ));
            }
            "yammer" => format!(
                "https://www.yammer.com/oauth2/oauth2/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("response_type", s("code".into())),
                    ("redirect_uri", callback),
                    ("state", s(state)),
                ])
            ),
            "yandex" => format!(
                "https://oauth.yandex.com/authorize?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            "zoho" => format!(
                "https://accounts.zoho.com/oauth/v2/auth?{}",
                query(vec![
                    ("response_type", s("code".into())),
                    ("client_id", id),
                    ("state", s(state)),
                    ("redirect_uri", callback),
                    ("scope", s(spaced)),
                    ("prompt", optional(self.prompt(true, " ")?)),
                ])
            ),
            "zoom" => format!(
                "https://zoom.us/oauth/authorize?{}",
                query(vec![
                    ("client_id", id),
                    ("redirect_uri", callback),
                    ("response_type", s("code".into())),
                    ("scope", s(spaced)),
                    ("state", s(state)),
                ])
            ),
            _ => return Err(Error::new(ErrorType::ProjectProviderUnsupported)),
        })
    }

    /// The PKCE providers' authorization URLs.
    fn pkce_url(&mut self) -> Result<String> {
        let state = self.pkce_state()?;
        let challenge = self.pkce_challenge();
        let s = |v: &str| Json::String(v.to_owned());
        let id = self.app_id.clone();
        let callback = self.callback.clone();
        let spaced = self.joined_scopes(" ");
        let query = |pairs: Vec<(&str, Json)>| {
            base::build_query(&pairs.into_iter().map(|(k, v)| (k.to_owned(), v)).collect::<Map<String, Json>>())
        };
        Ok(match self.name.as_str() {
            "appwrite" => format!(
                "https://cloud.appwrite.io/v1/oauth2/console/authorize?{}",
                query(vec![
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("state", s(&state)),
                    ("scope", s(&spaced)),
                    ("response_type", s("code")),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
            "cloudflare" => format!(
                "https://dash.cloudflare.com/oauth2/auth?{}",
                query(vec![
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("response_type", s("code")),
                    ("scope", s(&spaced)),
                    ("state", s(&state)),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
            "etsy" => format!(
                "https://www.etsy.com/oauth/connect/oauth/authorize?{}",
                query(vec![
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("response_type", s("code")),
                    ("state", s(&state)),
                    ("scope", json!(self.scopes)),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
            "kick" => format!(
                "https://id.kick.com/oauth/authorize?{}",
                query(vec![
                    ("response_type", s("code")),
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("scope", s(&spaced)),
                    ("state", s(&state)),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
            "resend" => format!(
                "https://api.resend.com/oauth/authorize?{}",
                query(vec![
                    ("response_type", s("code")),
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("scope", s(&spaced)),
                    ("state", s(&state)),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
            _ => format!(
                "https://x.com/i/oauth2/authorize?{}",
                query(vec![
                    ("response_type", s("code")),
                    ("client_id", s(&id)),
                    ("redirect_uri", s(&callback)),
                    ("scope", s(&spaced)),
                    ("state", s(&base64url(state.as_bytes()))),
                    ("code_challenge", s(&challenge)),
                    ("code_challenge_method", s("S256")),
                ])
            ),
        })
    }

    /// `parseState($state)`: `None` where PHP's `json_decode` gives no array.
    pub fn parse_state(&mut self, state: &str) -> Option<Json> {
        let text = match self.name.as_str() {
            "amazon" => String::from_utf8_lossy(&php_std::encoding::urldecode(html_entity_decode(state).as_bytes()))
                .into_owned(),
            "salesforce" | "yahoo" | "yandex" => html_entity_decode(state),
            "x" => String::from_utf8(base64url_decode(state)?).ok()?,
            _ => state.to_owned(),
        };
        let parsed = php_decode(&text)?;
        if uses_pkce(&self.name) {
            let Json::Object(mut parsed) = parsed else { return None };
            if let Some(pkce) = parsed.remove("_pkce") {
                self.pkce = match pkce {
                    Json::String(s) => Some(s),
                    Json::Object(p) => Some(decrypt_pkce(&p)),
                    _ => self.pkce.take(),
                };
            }
            return Some(Json::Object(parsed));
        }
        Some(parsed)
    }

    // ---- the code exchange (mock providers) ---------------------------------------

    /// `getTokens($code)`.
    async fn tokens(&mut self, code: &str) -> Result<Map<String, Json>> {
        if let Some(tokens) = &self.tokens {
            return Ok(tokens.clone());
        }
        if !matches!(self.name.as_str(), "mock" | "mock-unverified" | "mock-no-email") {
            return Err(Error::new(ErrorType::ProjectProviderUnsupported));
        }
        let mut query = Map::new();
        query.insert("client_id".into(), Json::String(self.app_id.clone()));
        query.insert("redirect_uri".into(), Json::String(self.callback.clone()));
        query.insert("client_secret".into(), Json::String(self.app_secret.clone()));
        query.insert("code".into(), Json::String(code.to_owned()));
        let body =
            request(&format!("http://localhost/v1/mock/tests/general/oauth2/token?{}", base::build_query(&query)))
                .await?;
        let tokens = match php_decode(&body) {
            Some(Json::Object(m)) => m,
            _ => Map::new(),
        };
        self.tokens = Some(tokens.clone());
        Ok(tokens)
    }

    /// `refreshTokens($refreshToken)`.
    async fn refresh(&mut self, refresh_token: &str) -> Result<Map<String, Json>> {
        if !matches!(self.name.as_str(), "mock" | "mock-unverified" | "mock-no-email") {
            return Err(Error::new(ErrorType::ProjectProviderUnsupported));
        }
        let mut query = Map::new();
        query.insert("client_id".into(), Json::String(self.app_id.clone()));
        query.insert("client_secret".into(), Json::String(self.app_secret.clone()));
        query.insert("refresh_token".into(), Json::String(refresh_token.to_owned()));
        query.insert("grant_type".into(), Json::String("refresh_token".into()));
        let body =
            request(&format!("http://localhost/v1/mock/tests/general/oauth2/token?{}", base::build_query(&query)))
                .await?;
        let mut tokens = match php_decode(&body) {
            Some(Json::Object(m)) => m,
            _ => Map::new(),
        };
        if tokens.get("refresh_token").is_none_or(|t| json_text(t).is_empty()) {
            tokens.insert("refresh_token".into(), Json::String(refresh_token.to_owned()));
        }
        self.tokens = Some(tokens.clone());
        Ok(tokens)
    }

    /// `getUser($accessToken)`.
    async fn user(&mut self, access_token: &str) -> Result<Map<String, Json>> {
        if let Some(user) = &self.user {
            return Ok(user.clone());
        }
        let path = match self.name.as_str() {
            "mock" => "user",
            "mock-unverified" => "user-unverified",
            "mock-no-email" => "user-no-email",
            _ => return Err(Error::new(ErrorType::ProjectProviderUnsupported)),
        };
        let token = php_std::encoding::urlencode(access_token.as_bytes());
        let body = request(&format!("http://localhost/v1/mock/tests/general/oauth2/{path}?token={token}")).await?;
        let user = match php_decode(&body) {
            Some(Json::Object(m)) => m,
            _ => Map::new(),
        };
        self.user = Some(user.clone());
        Ok(user)
    }

    async fn user_field(&mut self, access_token: &str, key: &str) -> Result<String> {
        Ok(self.user(access_token).await?.get(key).map(json_text).unwrap_or_default())
    }
}

/// `decryptPKCEVerifier()`.
fn decrypt_pkce(payload: &Map<String, Json>) -> String {
    let field = |k: &str| payload.get(k).and_then(Json::as_str).unwrap_or("").to_owned();
    let (data, iv, tag) = (field("data"), field("iv"), field("tag"));
    if data.is_empty() || iv.is_empty() || tag.is_empty() {
        return String::new();
    }
    let key = utopia_system::env_or("_APP_OPENSSL_KEY_V1", "").into_owned();
    let (Some(data), Ok(iv), Ok(tag)) = (base64url_decode(&data), hex::decode(&iv), hex::decode(&tag)) else {
        return String::new();
    };
    appwrite_core::crypto::open(&key, &data, &iv, &tag).and_then(|v| String::from_utf8(v).ok()).unwrap_or_default()
}

/// A JSON scalar as PHP's string cast.
fn json_text(value: &Json) -> String {
    match value {
        Json::String(s) => s.clone(),
        Json::Null => String::new(),
        Json::Bool(true) => "1".into(),
        Json::Bool(false) => String::new(),
        Json::Number(n) => n.to_string(),
        other => other.to_string(),
    }
}

fn php_intval(value: &Json) -> i64 {
    match value {
        Json::Number(n) => n.as_i64().unwrap_or_else(|| n.as_f64().unwrap_or(0.0) as i64),
        Json::String(s) => php_std::format::intval(php_std::format::Arg::Str(s.as_bytes()), 10),
        Json::Bool(b) => i64::from(*b),
        _ => 0,
    }
}

/// A provider request failed: its body and status (`OAuth2\Exception`).
struct ProviderError {
    body: String,
}

impl ProviderError {
    /// `getError()`: the provider's error, else its message.
    fn error(&self) -> String {
        match php_decode(&self.body) {
            Some(Json::Object(decoded)) => match decoded.get("error") {
                Some(Json::Object(e)) => e.get("status").map(json_text).unwrap_or_else(|| "Unknown error".into()),
                Some(Json::Array(_)) if decoded.get("errors").is_none() => "Unknown error".into(),
                Some(e) if !e.is_null() => json_text(e),
                _ => decoded.get("message").map(json_text).unwrap_or_else(|| "Unknown error".into()),
            },
            _ => String::new(),
        }
    }

    fn message(&self) -> String {
        match php_decode(&self.body) {
            Some(Json::Object(decoded)) => {
                let description = match decoded.get("error") {
                    Some(Json::Object(e)) => e.get("message").map(json_text).unwrap_or_else(|| "No description".into()),
                    _ => decoded.get("error_description").map(json_text).unwrap_or_else(|| "No description".into()),
                };
                format!("{}: {description}", self.error())
            }
            _ => self.body.clone(),
        }
    }
}

thread_local! {
    static LAST_PROVIDER_ERROR: std::cell::RefCell<Option<String>> = const { std::cell::RefCell::new(None) };
}

/// `request('GET', $url)`: the body; a status of 400 or more fails with the
/// provider's error (kept for [`provider_failure`]).
async fn request(url: &str) -> Result<String> {
    let ranges: Vec<utopia_client::IpRange> = utopia_system::env_or("_APP_ALLOWED_INTERNAL_ADDRESSES", "")
        .split(',')
        .map(str::trim)
        .filter(|r| !r.is_empty())
        .filter_map(|r| utopia_client::IpRange::new(r).ok())
        .collect();
    let client = utopia_client::Client::new(utopia_client::Http::new())
        .with_destinations(utopia_client::PublicInternet::new(ranges));
    let request = utopia_client::Request::new("GET", url)
        .and_then(|r| r.header("user-agent", "Appwrite OAuth2"))
        .map_err(|e| Error::internal(e.to_string()))?;
    let response = match client.send(request).await {
        Ok(r) => r,
        Err(e) => return Err(provider_error(e.to_string(), 400)),
    };
    let body = response.text().into_owned();
    if response.status() >= 400 {
        return Err(provider_error(body, response.status()));
    }
    Ok(body)
}

/// An OAuth2 provider exception: carried as an `Error` whose message is the
/// provider's error (`getError() ?: getMessage()`).
fn provider_error(body: String, status: u16) -> Error {
    let error = ProviderError { body };
    let detail = {
        let e = error.error();
        if e.is_empty() { error.message() } else { e }
    };
    let kind = match status {
        400 => ErrorType::GeneralBadRequest,
        _ => ErrorType::UserOauth2ProviderError,
    };
    let mut err = Error::with_code(kind, detail, status);
    err.kind = ErrorType::UserOauth2ProviderFailure;
    err
}

// ---- routes -------------------------------------------------------------------------

/// `$callbackBase`: the API's own origin, with the request's port.
fn callback_base(ctx: &Context) -> String {
    let protocol = ctx.state.config.link_scheme;
    let port = base::port(ctx);
    let mut out = format!("{protocol}://{}", ctx.hostname);
    let default = if protocol == "https" { "443" } else { "80" };
    if port != default {
        out.push(':');
        out.push_str(&port);
    }
    out
}

/// The provider's app ID and secret from the project.
fn credentials(ctx: &Context, provider: &str) -> (String, String) {
    let app_id = ctx.project.oauth_string(&format!("{provider}Appid"));
    let secret = match ctx.project.oauth_providers.get(format!("{provider}Secret")) {
        None | Some(Json::Null) => "{}".to_owned(),
        Some(Json::Object(m)) if m.contains_key("version") => {
            let key =
                utopia_system::env_or(&format!("_APP_OPENSSL_KEY_V{}", json_text(&m["version"])), "").into_owned();
            let field = |k: &str| m.get(k).map(json_text).unwrap_or_default();
            let data = php_std::encoding::base64_decode(field("data").as_bytes(), false).unwrap_or_default();
            let (iv, tag) =
                (hex::decode(field("iv")).unwrap_or_default(), hex::decode(field("tag")).unwrap_or_default());
            appwrite_core::crypto::open(&key, &data, &iv, &tag)
                .and_then(|v| String::from_utf8(v).ok())
                .unwrap_or_default()
        }
        Some(v) => json_text(v),
    };
    (app_id, secret)
}

/// The OAuth2 nonce cookie (`a_oauth2_<project>`), with the newest nonce
/// first and at most five kept.
fn nonce_cookie(ctx: &Context, nonce: &str, response: &mut Response) {
    let name = format!("a_oauth2_{}", ctx.project.id);
    let held = ctx.request.cookie(&name).unwrap_or("").to_owned();
    let mut nonces = vec![nonce.to_owned()];
    nonces.extend(
        held.split(',').filter(|n| n.len() == 32 && n.bytes().all(|b| b.is_ascii_hexdigit())).map(str::to_owned),
    );
    nonces.truncate(5);
    response.add_cookie(Cookie {
        name,
        value: Some(nonces.join(",")),
        expire: Some(chrono::Utc::now().timestamp() + 3600),
        path: Some("/".into()),
        domain: None,
        secure: Some(ctx.state.config.link_scheme == "https"),
        http_only: Some(true),
        same_site: Some("Lax".into()),
    });
}

/// A redirect to `url`, uncached.
fn redirect_response(url: &str) -> Response {
    let mut response = moved(url);
    response.add("cache-control", "no-store, no-cache, must-revalidate, max-age=0");
    response.add("pragma", "no-cache");
    response
}

/// `$response->redirect($url, 301)`: an empty body of the default
/// `text/html` type.
fn moved(url: &str) -> Response {
    let mut response = Response::with_body(http::StatusCode::MOVED_PERMANENTLY, "text/html", bytes::Bytes::new());
    response.add("location", url);
    response
}

/// `GET /v1/account/sessions/oauth2/:provider`.
pub async fn create_session(ctx: &mut Context) -> Result<Response> {
    start(ctx, false).await
}

/// `GET /v1/account/tokens/oauth2/:provider`.
pub async fn create_token(ctx: &mut Context) -> Result<Response> {
    start(ctx, true).await
}

/// Starts the browser flow: the provider's authorization URL.
async fn start(ctx: &mut Context, token: bool) -> Result<Response> {
    let keys = provider_keys();
    let provider = ctx.required_str("provider", &WhiteList::strict(&keys))?;
    let redirect = base::Redirect::of(ctx);
    let success = ctx.optional_str("success", "", &redirect)?.unwrap_or_default();
    let failure = ctx.optional_str("failure", "", &redirect)?.unwrap_or_default();
    let scopes = ctx.optional("scopes", json!([]), &ArrayList::new(Text::new(4096), 100))?;
    let printable: Vec<String> = (0x20u8..=0x7E).map(|c| char::from(c).to_string()).collect();
    let state_param =
        ctx.optional_str("state", "", &Text::with_min(256, 0).with_allow_list(printable))?.unwrap_or_default();
    let scopes: Vec<String> = scopes.as_array().map(|a| a.iter().map(json_text).collect()).unwrap_or_default();

    let callback = format!("{}/v1/account/sessions/oauth2/callback/{provider}/{}", callback_base(ctx), ctx.project.id);
    if !ctx.project.oauth_flag(&format!("{provider}Enabled")) {
        return Err(Error::with_message(
            ErrorType::ProjectProviderDisabled,
            "This provider is disabled. Please enable the provider from your Appwrite console to continue.",
        ));
    }
    let (app_id, app_secret) = credentials(ctx, &provider);
    if app_id.is_empty() || app_id == "0" || app_secret.is_empty() || app_secret == "0" {
        return Err(Error::with_message(
            ErrorType::ProjectProviderDisabled,
            "This provider is disabled. Please configure the provider app ID and app secret key from your Appwrite console to continue.",
        ));
    }
    let base = base::console_base(ctx);
    let success = if success.is_empty() { format!("{base}{DEFAULT_SUCCESS}") } else { success };
    let failure = if failure.is_empty() { format!("{base}{DEFAULT_FAILURE}") } else { failure };

    let mut bytes = [0u8; 16];
    utopia_auth::random_bytes(&mut bytes);
    let nonce = hex::encode(bytes);
    let mut state = Map::new();
    state.insert("success".into(), Json::String(success));
    state.insert("failure".into(), Json::String(failure));
    state.insert("token".into(), Json::Bool(token));
    state.insert("nonce".into(), Json::String(nonce.clone()));
    state.insert("state".into(), Json::String(state_param));
    let mut adapter = Provider::new(&provider, &app_id, &app_secret, &callback, state, &scopes);
    let url = adapter.login_url()?;
    let mut response = redirect_response(&url);
    nonce_cookie(ctx, &nonce, &mut response);
    Ok(response)
}

/// `GET|POST /v1/account/sessions/oauth2/callback/:provider/:projectId`:
/// forwards the provider's answer to the redirect route.
pub async fn callback(ctx: &mut Context) -> Result<Response> {
    let project_id = ctx.required_str("projectId", &Text::new(1024))?;
    let keys = provider_keys();
    let provider = ctx.required_str("provider", &WhiteList::strict(&keys))?;
    ctx.optional_str("code", "", &Text::with_min(2048, 0))?;
    ctx.optional_str("state", "", &Text::new(2560))?;
    ctx.optional_str("error", "", &Text::with_min(2048, 0))?;
    ctx.optional_str("error_description", "", &Text::with_min(2048, 0))?;
    let mut params = ctx.request.params().clone();
    params.insert("project".into(), Json::String(project_id));
    params.remove("projectId");
    let url =
        format!("{}/v1/account/sessions/oauth2/{provider}/redirect?{}", callback_base(ctx), base::build_query(&params));
    Ok(redirect_response(&url))
}

/// The failure of the redirect flow: a redirect to the app's failure URL
/// carrying the error (when there is one), and the error itself.
struct Failure {
    url: Option<UrlParts>,
    state: Option<String>,
    console_host: String,
    native: String,
    project: String,
}

impl Failure {
    /// `$failureRedirect($type, $message)`.
    fn fail(&self, ctx: &mut Context, error: Error) -> Result<Response> {
        let Some(failure) = &self.url else { return Err(error) };
        let mut query = base::parse_query(failure.query.as_deref().unwrap_or(""));
        if let Some(state) = self.state.as_deref().filter(|s| !s.is_empty()) {
            query.insert("state".into(), Json::String(state.to_owned()));
        }
        query.insert(
            "error".into(),
            Json::String(php_json(&json!({"message": error.message, "type": error.kind.name(), "code": error.code}))),
        );
        let mut target = failure.clone();
        if failure.host.as_deref() == Some(self.console_host.as_str())
            && failure.path.as_deref() == Some(DEFAULT_FAILURE)
        {
            query.insert("project".into(), Json::String(self.project.clone()));
            target = UrlParts { scheme: Some(self.native.clone()), ..UrlParts::default() };
        }
        target.query = Some(base::build_query(&query));
        // PHP sends the redirect, then throws: the error hook runs, and its
        // response is never sent.
        ctx.failed = true;
        Ok(moved(&unparse(&target)))
    }
}

/// `URLParser::unparse($url)`.
fn unparse(url: &UrlParts) -> String {
    let path = url.path.as_ref().map(|p| if p.starts_with('/') { p.clone() } else { format!("/{p}") });
    let mut out = String::new();
    if let Some(scheme) = &url.scheme {
        out.push_str(scheme);
        out.push_str("://");
    }
    let user = url.user.clone().unwrap_or_default();
    let pass = url.pass.as_ref().filter(|p| !p.is_empty() && *p != "0").map(|p| format!(":{p}")).unwrap_or_default();
    out.push_str(&user);
    if !(user.is_empty() || user == "0") || !pass.is_empty() {
        out.push_str(&pass);
        out.push('@');
    }
    out.push_str(url.host.as_deref().unwrap_or(""));
    if let Some(port) = url.port {
        out.push_str(&format!(":{port}"));
    }
    out.push_str(path.as_deref().unwrap_or(""));
    if let Some(query) = url.query.as_deref().filter(|q| !q.is_empty() && *q != "0") {
        out.push('?');
        out.push_str(query);
    }
    if let Some(fragment) = &url.fragment {
        out.push('#');
        out.push_str(fragment);
    }
    out
}

/// `URLParser::parse($url)` (the scheme alone for a URL `parse_url` refuses).
fn parse(url: &str) -> UrlParts {
    let parts = UrlParts::parse(url)
        .unwrap_or_else(|| UrlParts { scheme: url.split_once(':').map(|(s, _)| s.to_owned()), ..UrlParts::default() });
    // Merged over defaults: every part but the port is set.
    let set = |part: Option<String>| Some(part.unwrap_or_default());
    UrlParts {
        scheme: set(parts.scheme),
        host: set(parts.host),
        port: parts.port,
        user: set(parts.user),
        pass: set(parts.pass),
        path: set(parts.path),
        query: set(parts.query),
        fragment: set(parts.fragment),
    }
}

/// `GET /v1/account/sessions/oauth2/:provider/redirect`: completes the
/// browser flow.
pub async fn redirect(ctx: &mut Context) -> Result<Response> {
    let keys = provider_keys();
    let provider = ctx.required_str("provider", &WhiteList::strict(&keys))?;
    let code = ctx.optional_str("code", "", &Text::with_min(2048, 0))?.unwrap_or_default();
    let state = ctx.optional_str("state", "", &Text::new(2560))?.unwrap_or_default();
    let error = ctx.optional_str("error", "", &Text::with_min(2048, 0))?.unwrap_or_default();
    let error_description = ctx.optional_str("error_description", "", &Text::with_min(2048, 0))?.unwrap_or_default();

    let callback = format!("{}/v1/account/sessions/oauth2/callback/{provider}/{}", callback_base(ctx), ctx.project.id);
    let (app_id, app_secret) = credentials(ctx, &provider);
    let enabled = ctx.project.oauth_flag(&format!("{provider}Enabled"));
    let name = provider_name(&provider);
    let mut adapter = Provider::new(&provider, &app_id, &app_secret, &callback, Map::new(), &[]);

    let mut state_map = Map::new();
    state_map.insert("success".into(), Json::String(ctx.project.url.clone()));
    state_map.insert("failure".into(), Json::String(String::new()));
    if !state.is_empty() {
        match adapter.parse_state(&state) {
            Some(Json::Object(parsed)) => state_map.extend(parsed),
            Some(Json::Array(items)) => {
                // `array_merge` renumbers a list's keys after the defaults'.
                for (i, v) in items.into_iter().enumerate() {
                    state_map.insert(i.to_string(), v);
                }
            }
            _ => {
                return Err(Error::with_message(
                    ErrorType::GeneralServerError,
                    "Failed to parse login state params as passed from OAuth2 provider",
                ));
            }
        }
    }
    let field = |key: &str| state_map.get(key).map(json_text).unwrap_or_default();
    let success = field("success");
    let failure_url = field("failure");
    let app_state = match state_map.get("state") {
        Some(Json::String(s)) => Some(s.clone()),
        _ => None,
    };
    let token = state_map.get("token").is_some_and(|t| {
        !matches!(t, Json::Null | Json::Bool(false)) && json_text(t) != "0" && !json_text(t).is_empty()
    });

    // Allow the project's custom domains as redirects.
    let mut redirect = base::Redirect::of(ctx);
    let domains: Vec<String> = [&success, &failure_url]
        .iter()
        .filter_map(|u| appwrite_core::network::url_host(u))
        .filter(|d| !d.is_empty())
        .fold(Vec::new(), |mut acc, d| {
            if !acc.contains(&d) {
                acc.push(d);
            }
            acc
        });
    if !domains.is_empty() {
        let platform = ctx.platform_db()?;
        let rules = platform
            .authorization()
            .skip(|| {
                platform.find(
                    "rules",
                    vec![
                        Query::equal("domain", domains.iter().map(String::as_str).collect::<Vec<_>>()),
                        Query::equal("projectInternalId", [ctx.project.sequence.as_str()]),
                        Query::limit(2),
                    ],
                    utopia_database::Database::PERMISSION_READ,
                )
            })
            .await?;
        for rule in rules {
            redirect.hosts.push(rule.string_or_empty("domain"));
        }
    }
    if redirect.allows(&success).is_err() {
        return Err(Error::new(ErrorType::ProjectInvalidSuccessUrl));
    }
    if !failure_url.is_empty() && redirect.allows(&failure_url).is_err() {
        return Err(Error::new(ErrorType::ProjectInvalidFailureUrl));
    }
    let console_host = appwrite_core::network::url_host(&base::platform(ctx, "consoleUrl")).unwrap_or_default();
    let failure = Failure {
        url: (!failure_url.is_empty()).then(|| parse(&failure_url)),
        state: app_state.clone(),
        console_host: console_host.clone(),
        native: format!("appwrite-callback-{}", ctx.project.id),
        project: ctx.project.id.clone(),
    };

    if !enabled {
        return failure.fail(
            ctx,
            Error::with_message(
                ErrorType::ProjectProviderDisabled,
                "This provider is disabled. Please enable the provider from your Appwrite console to continue.",
            ),
        );
    }

    // The nonce cookie binds the flow to the browser that started it.
    let held = ctx.request.cookie(&format!("a_oauth2_{}", ctx.project.id)).unwrap_or("").to_owned();
    let state_nonce = match state_map.get("nonce") {
        Some(Json::String(s)) => s.clone(),
        _ => String::new(),
    };
    let bound = held
        .split(',')
        .filter(|n| !n.is_empty())
        .any(|n| utopia_auth::hash_equals(n.as_bytes(), state_nonce.as_bytes()));
    let mut user = ctx.user.clone();
    if !bound {
        if !token {
            return failure.fail(ctx, Error::new(ErrorType::UserOauth2StateInvalid));
        }
        user = None;
    }

    if !error.is_empty() {
        let mut message = format!("The {name} OAuth2 provider returned an error: {error}");
        if !error_description.is_empty() {
            message.push_str(&format!(": {error_description}"));
        }
        return failure.fail(ctx, Error::with_message(ErrorType::UserOauth2ProviderError, message));
    }
    if code.is_empty() {
        return failure.fail(
            ctx,
            Error::with_message(
                ErrorType::UserOauth2ProviderError,
                "Missing OAuth2 code. Please contact the Appwrite team for additional support.",
            ),
        );
    }

    let exchanged = async {
        let tokens = adapter.tokens(&code).await?;
        let access = tokens.get("access_token").map(json_text).unwrap_or_default();
        let refresh = tokens.get("refresh_token").map(json_text).unwrap_or_default();
        let expiry = tokens.get("expires_in").map(php_intval).unwrap_or(0);
        let id = adapter.user_field(&access, "id").await?;
        Ok::<_, Error>((access, refresh, expiry, id))
    }
    .await;
    let (access_token, refresh_token, expiry, oauth_id) = match exchanged {
        Ok(v) => v,
        Err(e) if e.kind == ErrorType::UserOauth2ProviderFailure => {
            let message = ErrorType::UserOauth2ProviderFailure
                .description()
                .replacen("%s", name, 1)
                .replacen("%s", &e.message, 1);
            return failure.fail(ctx, Error::with_message(ErrorType::UserOauth2ProviderFailure, message));
        }
        Err(e) => return Err(e),
    };
    if oauth_id.is_empty() {
        return failure.fail(ctx, Error::new(ErrorType::UserMissingId));
    }
    let mut oauth_name = adapter.user_field(&access_token, "name").await?;
    if oauth_name.is_empty()
        && let Some(Json::String(param)) = ctx.request.params().get("user")
        && let Some(decoded) = php_decode(param)
        && let (Some(first), Some(last)) = (decoded.pointer("/name/firstName"), decoded.pointer("/name/lastName"))
    {
        oauth_name = format!("{} {}", json_text(first), json_text(last));
    }
    let provider_email = adapter.user_field(&access_token, "email").await?;
    let verified_claim = adapter.user(&access_token).await?.get("verified").cloned();
    let is_verified = match verified_claim {
        None | Some(Json::Null) => true,
        Some(v) => !matches!(v, Json::Bool(false)) && !json_text(&v).is_empty() && json_text(&v) != "0",
    };
    let photo = adapter.user_field(&access_token, "photo").await?;

    let resolved =
        resolve_user(ctx, &failure, user, token, &provider, &oauth_id, &provider_email, is_verified, &oauth_name)
            .await?;
    let (mut user, new_user, new_target, session_upgrade) = match resolved {
        Resolved::User { user, new_user, new_target, upgrade } => (*user, new_user, new_target, upgrade),
        Resolved::Redirect(response) => return Ok(*response),
    };

    let db = ctx.db()?;
    // The identity.
    let identity = db
        .find_one(
            "identities",
            vec![
                Query::equal("userInternalId", [documents::internal_id(user.sequence)]),
                Query::equal("provider", [provider.as_str()]),
                Query::equal("providerUid", [oauth_id.as_str()]),
            ],
        )
        .await?;
    let token_expiry = datetime::add_seconds(datetime::now(), expiry);
    if identity.is_empty() {
        if !provider_email.is_empty() {
            let others = db
                .find(
                    "identities",
                    vec![
                        Query::equal("providerEmail", [provider_email.as_str()]),
                        Query::not_equal("userInternalId", documents::internal_id(user.sequence)),
                    ],
                    utopia_database::Database::PERMISSION_READ,
                )
                .await?;
            if !others.is_empty() {
                return failure.fail(ctx, Error::new(ErrorType::GeneralBadRequest));
            }
        }
        let mut document = Document::default();
        document.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
        document.set("$permissions", base::user_permissions(&user.id));
        document.set("userInternalId", documents::internal_id(user.sequence));
        document.set("userId", Value::from(user.id.as_str()));
        document.set("provider", Value::from(provider.as_str()));
        document.set("providerUid", Value::from(oauth_id.as_str()));
        document.set("providerEmail", Value::from(provider_email.as_str()));
        document.set("providerAccessToken", Value::from(access_token.as_str()));
        document.set("providerRefreshToken", Value::from(refresh_token.as_str()));
        document.set("providerAccessTokenExpiry", datetime::value(&token_expiry));
        document.set("photo", Value::from(photo.as_str()));
        match db.create_document("identities", document).await {
            Ok(_) => {}
            Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                if let Some(new_user) = &new_user {
                    let _ = db
                        .authorization()
                        .skip(|| async {
                            if let Some(target) = &new_target {
                                db.delete_document("targets", target).await?;
                            }
                            db.delete_document("users", new_user).await
                        })
                        .await;
                }
                return failure.fail(ctx, Error::new(ErrorType::UserAlreadyExists));
            }
            Err(e) => return Err(e.into()),
        }
    } else {
        let mut updates = Document::default();
        updates.set("providerAccessToken", Value::from(access_token.as_str()));
        updates.set("providerRefreshToken", Value::from(refresh_token.as_str()));
        updates.set("providerAccessTokenExpiry", datetime::value(&token_expiry));
        updates.set("photo", Value::from(photo.as_str()));
        db.update_document("identities", identity.id(), updates).await?;
    }

    let mut updates = Document::default();
    if user.name.as_deref().is_none_or(|n| n.is_empty() || n == "0") {
        user.name = Some(oauth_name.clone());
        updates.set("name", Value::from(oauth_name.as_str()));
    }
    updates.set("status", Value::Bool(true));
    updates.set("email", documents::text(user.email.as_deref()));
    updates.set("emailVerification", Value::Bool(user.email_verification == Some(true)));
    let meta = Metadata {
        canonical: user.email_canonical.clone(),
        is_canonical: user.email_is_canonical,
        is_corporate: user.email_is_corporate,
        is_disposable: user.email_is_disposable,
        is_free: user.email_is_free,
    };
    meta.apply(&mut updates);
    db.update_document("users", &user.id, updates).await?;
    ctx.authorization.add_role(&format!("user:{}", user.id));

    let mut success_url = parse(&success);
    let mut query = base::parse_query(success_url.query.as_deref().unwrap_or(""));
    let duration = base::duration(ctx);
    let expire = datetime::add_seconds(datetime::now(), duration);
    let mut response_cookies: Option<(String, chrono::NaiveDateTime)> = None;
    let mut fallback: Option<String> = None;
    let mut session_created: Option<Session> = None;
    if token {
        let secret = base::token_secret(64)?;
        let mut document = Document::default();
        document.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
        document.set("userId", Value::from(user.id.as_str()));
        document.set("userInternalId", documents::internal_id(user.sequence));
        document.set("type", Value::Int(token_type::OAUTH2));
        document.set("secret", Value::from(base::token_hash(&secret)?.as_str()));
        document.set("expire", datetime::value(&expire));
        document.set("userAgent", Value::from(ctx.user_agent("UNKNOWN").as_str()));
        document.set("ip", Value::from(ctx.ip.as_str()));
        document.set("$permissions", base::owner_permissions(&user.id));
        let created = db.create_document("tokens", document).await?;
        ctx.events.event = Some("users.[userId].tokens.[tokenId].create".to_owned());
        ctx.event_param("userId", user.id.clone());
        ctx.event_param("tokenId", created.id().to_owned());
        // No payload is set: PHP's event carries the response's last output,
        // the user its database listener rendered, or nothing.
        let listened = ctx.listened.lock().unwrap_or_else(|e| e.into_inner()).users.last().map(User::from);
        ctx.events.payload = Some(match listened {
            Some(created) => {
                bytes::Bytes::from(appwrite_core::response::current(&appwrite_core::response::UserModel {
                    user: &created,
                    render: ctx.render(),
                }))
            }
            None => bytes::Bytes::from_static(b"[]"),
        });
        let mut claims = Map::new();
        claims.insert("secret".into(), Json::String(secret));
        claims.insert("provider".into(), Json::String(provider.clone()));
        query.insert(
            "secret".into(),
            Json::String(appwrite_core::auth::jwt::encode(&ctx.state.config.openssl_key_v1, claims, 60)),
        );
        query.insert("userId".into(), Json::String(user.id.clone()));
    } else {
        let secret = base::token_secret(256)?;
        let mut session = base::new_session(ctx, &user, &provider, &secret, &["email", "oauth2"], &expire).await?;
        session.set("providerUid", Value::from(oauth_id.as_str()));
        session.set("providerAccessToken", Value::from(access_token.as_str()));
        session.set("providerRefreshToken", Value::from(refresh_token.as_str()));
        session.set("providerAccessTokenExpiry", datetime::value(&token_expiry));
        let mut session = Session::from(&db.create_document("sessions", session).await?);
        session.expire = Some(expire);
        let encoded = base::encode_store(&user.id, &secret)?;
        if !base::domain_verification(ctx) {
            let mut cookies = Map::new();
            cookies.insert(ctx.store.key.clone(), Json::String(encoded.clone()));
            fallback = Some(appwrite_core::json::to_string(&Json::Object(cookies)));
        }
        ctx.event_param("userId", user.id.clone());
        ctx.event_param("sessionId", session.id.clone());
        let model =
            SessionModel { session: &session, country_name: "", current: false, secret: None, render: ctx.render() };
        ctx.event_payload(&model);
        if success_url.host.as_deref() == Some(console_host.as_str())
            && success_url.path.as_deref() == Some(DEFAULT_SUCCESS)
        {
            query.insert("project".into(), Json::String(ctx.project.id.clone()));
            query.insert("domain".into(), base::cookie_domain(ctx).map(Json::String).unwrap_or(Json::Null));
            query.insert("key".into(), Json::String(ctx.store.key.clone()));
            query.insert("secret".into(), Json::String(encoded.clone()));
            success_url =
                UrlParts { scheme: Some(format!("appwrite-callback-{}", ctx.project.id)), ..UrlParts::default() };
        }
        response_cookies = Some((encoded, expire));
        session_created = Some(session);
    }

    if session_upgrade && let Some(session) = &session_created {
        for target in user.targets.iter().filter(|t| t.provider_type == "push") {
            let mut updates = Document::default();
            updates.set("sessionId", Value::from(session.id.as_str()));
            let sequence = db.get_document("sessions", &session.id, Vec::new(), false).await?;
            updates.set("sessionInternalId", sequence.sequence().cloned().unwrap_or(Value::Null));
            db.update_document("targets", &target.id, updates).await?;
        }
    }
    db.purge_cached_document("users", Some(&user.id)).await?;
    if let Some(state) = app_state.filter(|s| !s.is_empty()) {
        query.insert("state".into(), Json::String(state));
    }
    success_url.query = Some(base::build_query(&query));
    let mut response = redirect_response(&unparse(&success_url));
    if let Some(fallback) = fallback {
        response.add("x-fallback-cookies", &fallback);
    }
    if let Some((encoded, expire)) = response_cookies {
        base::add_session_cookies_only(ctx, &mut response, &encoded, &expire);
    }
    Ok(response)
}

/// What the redirect flow signs in as.
enum Resolved {
    User { user: Box<User>, new_user: Option<String>, new_target: Option<String>, upgrade: bool },
    Redirect(Box<Response>),
}

/// The provider email as stored, the emails it may match, its metadata and
/// whether it was canonicalized (`parseEmail()`).
fn parse_email(ctx: &Context, provider_email: &str, verified: bool) -> Result<(String, Vec<String>, Metadata, bool)> {
    let parsed = utopia_emails::Email::new(provider_email).map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
    let canonical = parsed.canonical().map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
    let canonicalize = ctx.plan_allows("supportsCanonicalEmailValidation")
        && ctx.project.auth_bool("canonicalEmails", false)
        && verified;
    let email = if canonicalize {
        let local = canonical.split('@').next().unwrap_or("");
        let domain = provider_email.to_lowercase().split_once('@').map(|(_, d)| d.to_owned()).unwrap_or_default();
        format!("{local}@{domain}")
    } else {
        provider_email.to_owned()
    };
    let mut emails: Vec<String> = Vec::new();
    for e in [&email, &provider_email.to_owned(), &canonical] {
        if !e.is_empty() && e != "0" && !emails.contains(e) {
            emails.push(e.clone());
        }
    }
    let meta = Metadata {
        is_canonical: Some(email.to_lowercase() == canonical),
        canonical: Some(canonical),
        is_corporate: Some(parsed.is_corporate()),
        is_disposable: Some(parsed.is_disposable()),
        is_free: Some(parsed.is_free()),
    };
    Ok((email, emails, meta, canonicalize))
}

/// The user of the flow: the signed-in caller (linking the identity), the
/// user of a session or identity of this provider account, a user with the
/// same verified email, or a new user.
#[allow(clippy::too_many_arguments)]
async fn resolve_user(
    ctx: &mut Context,
    failure: &Failure,
    user: Option<User>,
    token: bool,
    provider: &str,
    oauth_id: &str,
    provider_email: &str,
    is_verified: bool,
    name: &str,
) -> Result<Resolved> {
    let db = ctx.db()?;
    let mut upgrade = false;
    let fail = |ctx: &mut Context, e: Error| failure.fail(ctx, e).map(|r| Resolved::Redirect(Box::new(r)));
    if let Some(user) = &user {
        let sequence = documents::internal_id(user.sequence);
        let taken = db
            .find_one(
                "identities",
                vec![
                    Query::equal("provider", [provider]),
                    Query::equal("providerUid", [oauth_id]),
                    Query::not_equal("userInternalId", sequence.clone()),
                ],
            )
            .await?;
        if !taken.is_empty() {
            return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
        }
        if !provider_email.is_empty() {
            let identity = db
                .find_one(
                    "identities",
                    vec![Query::equal("providerEmail", [provider_email]), Query::not_equal("userInternalId", sequence)],
                )
                .await?;
            if !identity.is_empty() {
                return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
            }
            let others = db
                .find(
                    "users",
                    vec![Query::equal("email", [provider_email]), Query::not_equal("$id", user.id.as_str())],
                    utopia_database::Database::PERMISSION_READ,
                )
                .await?;
            if !others.is_empty() {
                return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
            }
        }
        upgrade = true;
    }

    // The session flow replaces the caller's session with the new one; the
    // token flow leaves it until the app exchanges the token.
    let mut user = user;
    if !token
        && let Some(current) =
            user.as_ref().and_then(|u| appwrite_core::auth::session_verify(&u.sessions, &ctx.store.secret).cloned())
    {
        let document = db.get_document("sessions", &current.id, Vec::new(), false).await?;
        if !document.is_empty() {
            db.delete_document("sessions", document.id()).await?;
            if let Some(u) = &user {
                db.purge_cached_document("users", Some(&u.id)).await?;
            }
        }
    }

    if user.is_none() {
        let session = db
            .find_one("sessions", vec![Query::equal("provider", [provider]), Query::equal("providerUid", [oauth_id])])
            .await?;
        if !session.is_empty() {
            user = documents::load_user(&db, &session.string_or_empty("userId")).await?;
        }
    }

    let mut new_user: Option<String> = None;
    let mut new_target: Option<String> = None;
    if user.is_none() {
        let identity = db
            .find_one("identities", vec![Query::equal("provider", [provider]), Query::equal("providerUid", [oauth_id])])
            .await?;
        if !identity.is_empty() {
            user = documents::load_user(&db, &identity.string_or_empty("userId")).await?;
        }
        let mut email = provider_email.to_owned();
        let mut emails = vec![email.clone()];
        let mut meta = Metadata::default();
        let mut canonicalize = false;
        if user.is_none() && !provider_email.is_empty() {
            match parse_email(ctx, provider_email, is_verified) {
                Ok((e, list, m, c)) => {
                    email = e;
                    emails = list;
                    meta = m;
                    canonicalize = c;
                }
                Err(e) => return fail(ctx, e),
            }
        }
        if user.is_none() && !email.is_empty() {
            let found = db
                .find_one("users", vec![Query::equal("email", emails.iter().map(String::as_str).collect::<Vec<_>>())])
                .await?;
            if !found.is_empty() {
                if !is_verified {
                    return fail(ctx, Error::new(ErrorType::GeneralBadRequest));
                }
                let mut found = User::from(&found);
                let domain = |e: &str| utopia_emails::Email::new(e).map(|p| p.domain().to_owned()).unwrap_or_default();
                if domain(found.email.as_deref().unwrap_or("")) == domain(provider_email) {
                    found.email_verification = Some(true);
                }
                user = Some(found);
            }
        }
        if user.is_none() && !provider_email.is_empty() {
            let identity = db.find_one("identities", vec![Query::equal("providerEmail", [provider_email])]).await?;
            if !identity.is_empty() {
                if !is_verified {
                    return fail(ctx, Error::new(ErrorType::GeneralBadRequest));
                }
                user = documents::load_user(&db, &identity.string_or_empty("userId")).await?;
            }
        }
        if user.is_none() {
            let limit = ctx.project.auth_int("limit", 0);
            if limit != 0 && db.count("users", Vec::new(), Some(10_000)).await? >= limit {
                return fail(ctx, Error::new(ErrorType::UserCountExceeded));
            }
            if !email.is_empty()
                && let Err(e) = base::check_email_policy(ctx, &meta, canonicalize)
            {
                return fail(ctx, e);
            }
            let user_id = appwrite_core::database::unique_id();
            let mut document = base::new_user(&user_id);
            document.set("email", if email.is_empty() { Value::Null } else { Value::from(email.as_str()) });
            document.set("emailVerification", Value::Bool(!email.is_empty() && is_verified));
            base::set_default_hash(&mut document);
            document.set("name", Value::from(name));
            document.set("mfa", Value::Bool(false));
            document.set("authenticators", Value::Null);
            document.set("search", Value::from(format!("{user_id} {email} {name}").as_str()));
            meta.apply(&mut document);
            let created = match base::create_user(ctx, document).await {
                Ok(u) => u,
                Err(e) if e.kind == ErrorType::UserAlreadyExists => return fail(ctx, e),
                Err(e) => return Err(e),
            };
            new_user = Some(created.id.clone());
            if !email.is_empty() {
                let target = db.create_document("targets", base::new_target(&created, "email", &email)).await;
                match target {
                    Ok(t) => new_target = Some(t.id().to_owned()),
                    Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                        return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
                    }
                    Err(e) => return Err(e.into()),
                }
            }
            user = Some(created);
        }
    }
    let Some(mut user) = user else {
        return Err(Error::new(ErrorType::UserNotFound));
    };
    ctx.authorization.add_role(&format!("user:{}", user.id));
    ctx.authorization.add_role("users");
    if user.status == Some(false) {
        return fail(ctx, Error::new(ErrorType::UserBlocked));
    }

    // A user without an email takes the provider's.
    if user.email.as_deref().is_none_or(|e| e.is_empty() || e == "0") && !provider_email.is_empty() {
        let (email, emails, meta, canonicalize) = match parse_email(ctx, provider_email, is_verified) {
            Ok(v) => v,
            Err(e) => return fail(ctx, e),
        };
        let others = db
            .find(
                "users",
                vec![
                    Query::equal("email", emails.iter().map(String::as_str).collect::<Vec<_>>()),
                    Query::not_equal("$id", user.id.as_str()),
                ],
                utopia_database::Database::PERMISSION_READ,
            )
            .await?;
        if !others.is_empty() {
            return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
        }
        if let Err(e) = base::check_email_policy(ctx, &meta, canonicalize) {
            return fail(ctx, e);
        }
        user.email = Some(email.clone());
        user.email_verification = Some(user.email_verification == Some(true) || is_verified);
        user.email_canonical = meta.canonical.clone();
        user.email_is_canonical = meta.is_canonical;
        user.email_is_corporate = meta.is_corporate;
        user.email_is_disposable = meta.is_disposable;
        user.email_is_free = meta.is_free;
        match db.create_document("targets", base::new_target(&user, "email", &email)).await {
            Ok(_) => {}
            Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                let existing = db
                    .authorization()
                    .skip(|| db.find_one("targets", vec![Query::equal("identifier", [email.as_str()])]))
                    .await?;
                if existing.is_empty() || existing.string_or_empty("userInternalId") != user.sequence.to_string() {
                    return fail(ctx, Error::new(ErrorType::UserAlreadyExists));
                }
            }
            Err(e) => return Err(e.into()),
        }
    }
    Ok(Resolved::User { user: Box::new(user), new_user, new_target, upgrade })
}

/// The refreshed tokens of an OAuth2 session (`PATCH /v1/account/sessions/:sessionId`).
pub struct Refreshed {
    pub access_token: String,
    pub refresh_token: String,
    pub expires_in: i64,
    pub photo: String,
}

/// `$oauth2->refreshTokens($refreshToken)` for the session's provider.
pub async fn refresh(ctx: &mut Context, provider: &str, refresh_token: &str) -> Result<Refreshed> {
    if !provider_keys().contains(&provider) {
        return Err(Error::new(ErrorType::ProjectProviderUnsupported));
    }
    let (app_id, app_secret) = credentials(ctx, provider);
    let mut adapter = Provider::new(provider, &app_id, &app_secret, "", Map::new(), &[]);
    let tokens = adapter.refresh(refresh_token).await?;
    let access_token = tokens.get("access_token").map(json_text).unwrap_or_default();
    let photo = adapter.user_field(&access_token, "photo").await.unwrap_or_default();
    Ok(Refreshed {
        refresh_token: tokens.get("refresh_token").map(json_text).unwrap_or_default(),
        expires_in: tokens.get("expires_in").map(php_intval).unwrap_or(0),
        access_token,
        photo,
    })
}

// ---- native ID token sign-in ---------------------------------------------------------

/// The signing keys of a JWKS URL, as PEM by key ID, with when they were
/// fetched (`Appwrite\Auth\OIDC\Jwks`, kept in the process).
/// A URL's keys: when they were fetched, the keys, and the last refresh.
type JwksEntry = (i64, Map<String, Json>, i64);

static JWKS: std::sync::LazyLock<std::sync::Mutex<std::collections::HashMap<String, JwksEntry>>> =
    std::sync::LazyLock::new(Default::default);

/// `Jwks::TTL` and `REFRESH_COOLDOWN`.
const JWKS_TTL: i64 = 21_600;
const JWKS_COOLDOWN: i64 = 60;

/// A DER length.
fn der_length(length: usize) -> Vec<u8> {
    if length < 0x80 {
        return vec![length as u8];
    }
    let bytes: Vec<u8> = (length as u32).to_be_bytes().into_iter().skip_while(|b| *b == 0).collect();
    let mut out = vec![0x80 | bytes.len() as u8];
    out.extend(bytes);
    out
}

/// The PEM `SubjectPublicKeyInfo` of an RSA modulus and exponent.
fn rsa_pem(modulus: &[u8], exponent: &[u8]) -> String {
    let integer = |bytes: &[u8]| {
        let mut b = bytes.to_vec();
        if b[0] & 0x80 != 0 {
            b.insert(0, 0);
        }
        [vec![0x02], der_length(b.len()), b].concat()
    };
    let sequence = |bytes: Vec<u8>| [vec![0x30], der_length(bytes.len()), bytes].concat();
    let public_key = sequence([integer(modulus), integer(exponent)].concat());
    let algorithm = sequence(b"\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00".to_vec());
    let bit_string = [vec![0x00], public_key].concat();
    let info = sequence([algorithm, vec![0x03], der_length(bit_string.len()), bit_string].concat());
    let encoded = php_std::encoding::base64_encode(&info);
    let mut pem = String::from("-----BEGIN PUBLIC KEY-----\n");
    for chunk in encoded.as_bytes().chunks(64) {
        pem.push_str(&String::from_utf8_lossy(chunk));
        pem.push('\n');
    }
    pem.push_str("-----END PUBLIC KEY-----\n");
    pem
}

/// `Jwks::fetch()`: the RSA signing keys of a JWKS document.
async fn fetch_jwks(url: &str) -> Result<Map<String, Json>> {
    let failed = || {
        Error::with_message(
            ErrorType::UserOauth2ProviderError,
            "Failed to fetch the provider signing keys. Please try again.",
        )
    };
    let body = request(url).await.map_err(|_| failed())?;
    let Some(Json::Object(document)) = php_decode(&body) else {
        return Err(Error::with_message(
            ErrorType::UserOauth2ProviderError,
            "The provider returned an invalid signing key document. Please try again.",
        ));
    };
    let Some(Json::Array(keys)) = document.get("keys") else {
        return Err(Error::with_message(
            ErrorType::UserOauth2ProviderError,
            "The provider returned an invalid signing key document. Please try again.",
        ));
    };
    let mut pems = Map::new();
    for key in keys {
        if key.get("kty").and_then(Json::as_str) != Some("RSA") {
            continue;
        }
        if !matches!(key.get("use"), None | Some(Json::Null)) && key.get("use").and_then(Json::as_str) != Some("sig") {
            continue;
        }
        let decode = |k: &str| key.get(k).and_then(Json::as_str).and_then(base64url_decode).filter(|b| !b.is_empty());
        let (Some(Json::String(kid)), Some(n), Some(e)) = (key.get("kid"), decode("n"), decode("e")) else { continue };
        if kid.is_empty() {
            continue;
        }
        pems.insert(kid.clone(), Json::String(rsa_pem(&n, &e)));
    }
    Ok(pems)
}

/// `Jwks::getKey($jwksUrl, $kid)`.
async fn jwks_key(url: &str, kid: &str) -> Result<Option<String>> {
    let now = chrono::Utc::now().timestamp();
    let cached = JWKS.lock().ok().and_then(|c| c.get(url).cloned());
    let (keys, cooldown) = match cached {
        Some((at, keys, cooldown)) if now - at < JWKS_TTL => (keys, cooldown),
        _ => {
            let keys = fetch_jwks(url).await?;
            if let Ok(mut cache) = JWKS.lock() {
                cache.insert(url.to_owned(), (now, keys.clone(), 0));
            }
            (keys, 0)
        }
    };
    if let Some(Json::String(pem)) = keys.get(kid) {
        return Ok(Some(pem.clone()));
    }
    if now - cooldown < JWKS_COOLDOWN {
        return Ok(None);
    }
    let keys = fetch_jwks(url).await?;
    if let Ok(mut cache) = JWKS.lock() {
        cache.insert(url.to_owned(), (now, keys.clone(), now));
    }
    Ok(keys.get(kid).and_then(Json::as_str).map(str::to_owned))
}

/// `IdTokenVerifier::verify()`: the token's claims, when it is signed by
/// the provider, current, issued by it, for the app and with its nonce.
async fn verify_id_token(
    issuers: &[&str],
    jwks_url: &str,
    nonce_required: bool,
    id_token: &str,
    audiences: &[String],
    nonce: &str,
) -> Result<Map<String, Json>> {
    let invalid = |m: &str| Error::with_message(ErrorType::UserOauth2TokenInvalid, m.to_owned());
    let parts: Vec<&str> = id_token.split('.').collect();
    if parts.len() != 3 {
        return Err(invalid("Malformed token"));
    }
    let segment = |p: &str| {
        php_std::encoding::base64_decode(p.replace('-', "+").replace('_', "/").as_bytes(), false)
            .and_then(|b| String::from_utf8(b).ok())
            .and_then(|t| php_decode(&t))
    };
    let Some(Json::Object(header)) = segment(parts[0]) else {
        return Err(invalid("Malformed token"));
    };
    let kid = match header.get("kid") {
        Some(Json::String(kid)) if !kid.is_empty() => kid.clone(),
        _ => return Err(invalid("Missing key ID")),
    };
    let Some(pem) = jwks_key(jwks_url, &kid).await? else {
        return Err(invalid("Unknown signing key"));
    };
    let check = utopia_auth::jwt::Rs256::new(pem.as_bytes()).map_err(|_| invalid("Invalid signing key"))?;

    // `adhocore/jwt` decode: the header's algorithm, the signature, then the times.
    match header.get("alg").map(json_text).unwrap_or_default().as_str() {
        "" => return Err(invalid("Invalid token: Missing header algo")),
        "HS256" | "HS384" | "HS512" | "RS384" | "RS512" => return Err(invalid("Invalid token: Signature failed")),
        "RS256" => {}
        _ => return Err(invalid("Invalid token: Unsupported header algo")),
    }
    use utopia_auth::jwt::SignatureCheck;
    let signature = base64url_decode(parts[2]).unwrap_or_default();
    let input = format!("{}.{}", parts[0], parts[1]);
    if !check.verify_signature(input.as_bytes(), &signature).unwrap_or(false) {
        return Err(invalid("Invalid token: Signature failed"));
    }
    let claims = match segment(parts[1]) {
        Some(Json::Object(claims)) => claims,
        _ => Map::new(),
    };
    let now = chrono::Utc::now().timestamp();
    let number = |k: &str| claims.get(k).and_then(|v| v.as_f64().or_else(|| v.as_str().and_then(|s| s.parse().ok())));
    const MAX_AGE: f64 = 86_400.0;
    const SKEW: f64 = 60.0;
    if let Some(exp) = number("exp")
        && now as f64 >= exp + SKEW
    {
        return Err(invalid("Invalid token: Expired"));
    }
    if let Some(iat) = number("iat")
        && now as f64 >= iat + MAX_AGE - SKEW
    {
        return Err(invalid("Invalid token: Expired"));
    }
    if let Some(nbf) = number("nbf")
        && now as f64 <= nbf - SKEW
    {
        return Err(invalid("Invalid token: Not now"));
    }
    if number("exp").is_none() {
        return Err(invalid("Token expired"));
    }
    if let Some(iat) = number("iat")
        && iat > (now as f64) + SKEW
    {
        return Err(invalid("Token issued in the future"));
    }
    if !claims.get("iss").and_then(Json::as_str).is_some_and(|i| issuers.contains(&i)) {
        return Err(invalid("Invalid issuer"));
    }
    let claimed: Vec<Json> = match claims.get("aud") {
        Some(Json::Array(a)) => a.clone(),
        Some(a) => vec![a.clone()],
        None => Vec::new(),
    };
    if !claimed.iter().any(|a| a.as_str().is_some_and(|a| audiences.iter().any(|x| x == a))) {
        return Err(invalid("Audience mismatch. Add the token's client ID to the provider configuration."));
    }
    let token_nonce = claims.get("nonce").and_then(Json::as_str).filter(|n| !n.is_empty());
    if nonce_required && token_nonce.is_none() {
        return Err(invalid("Nonce required"));
    }
    if let Some(token_nonce) = token_nonce {
        if nonce.is_empty() {
            return Err(invalid("Nonce required"));
        }
        use sha2::Digest;
        let hashed = hex::encode(sha2::Sha256::digest(nonce.as_bytes()));
        if !utopia_auth::hash_equals(token_nonce.as_bytes(), nonce.as_bytes())
            && !utopia_auth::hash_equals(token_nonce.as_bytes(), hashed.as_bytes())
        {
            return Err(invalid("Nonce mismatch"));
        }
    }
    if claims.get("sub").and_then(Json::as_str).is_none_or(|s| s.is_empty()) {
        return Err(invalid("Missing subject"));
    }
    Ok(claims)
}

/// The email policies as the ID token flow checks them: in CE only on the
/// console (its plan check has no empty-plan default).
fn id_token_email_policy(ctx: &Context, meta: &Metadata, email: &str, canonicalized: bool) -> Result<()> {
    if email.is_empty() {
        return Ok(());
    }
    let gate = |flag: &str| {
        ctx.project.is_console()
            || ctx
                .state
                .hooks
                .plan(&ctx.project)
                .is_some_and(|p| p.get(flag).is_some_and(utopia_validators::php::truthy))
    };
    let auths = &ctx.project;
    if gate("supportsDisposableEmailValidation")
        && auths.auth_bool("disposableEmails", false)
        && meta.is_disposable == Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailDisposable));
    }
    if gate("supportsCanonicalEmailValidation")
        && auths.auth_bool("canonicalEmails", false)
        && meta.is_canonical == Some(false)
        && !canonicalized
    {
        return Err(Error::new(ErrorType::UserEmailNotCanonical));
    }
    if gate("supportsFreeEmailValidation") && auths.auth_bool("freeEmails", false) && meta.is_free == Some(true) {
        return Err(Error::new(ErrorType::UserEmailFree));
    }
    if gate("supportsCorporateEmailValidation")
        && auths.auth_bool("corporateEmails", false)
        && meta.is_corporate != Some(true)
    {
        return Err(Error::new(ErrorType::UserEmailNotCorporate));
    }
    Ok(())
}

/// `parseEmail()` of the ID token flow (its canonical gate has no
/// empty-plan default either).
fn id_token_email(
    ctx: &Context,
    provider_email: &str,
    verified: bool,
) -> Result<(String, Vec<String>, Metadata, bool)> {
    let parsed = utopia_emails::Email::new(provider_email).map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
    let canonical = parsed.canonical().map_err(|_| Error::new(ErrorType::GeneralInvalidEmail))?;
    let gate = ctx.project.is_console()
        || ctx
            .state
            .hooks
            .plan(&ctx.project)
            .is_some_and(|p| p.get("supportsCanonicalEmailValidation").is_some_and(utopia_validators::php::truthy));
    let canonicalize = gate && ctx.project.auth_bool("canonicalEmails", false) && verified;
    let email = if canonicalize {
        let local = canonical.split('@').next().unwrap_or("");
        let domain = provider_email.to_lowercase().split_once('@').map(|(_, d)| d.to_owned()).unwrap_or_default();
        format!("{local}@{domain}")
    } else {
        provider_email.to_owned()
    };
    let mut emails: Vec<String> = Vec::new();
    for e in [&email, &provider_email.to_owned(), &canonical] {
        if !e.is_empty() && e != "0" && !emails.contains(e) {
            emails.push(e.clone());
        }
    }
    let meta = Metadata {
        is_canonical: Some(email.to_lowercase() == canonical),
        canonical: Some(canonical),
        is_corporate: Some(parsed.is_corporate()),
        is_disposable: Some(parsed.is_disposable()),
        is_free: Some(parsed.is_free()),
    };
    Ok((email, emails, meta, canonicalize))
}

/// `POST /v1/account/sessions/id-token`.
pub async fn id_token_session(ctx: &mut Context) -> Result<Response> {
    let id_token_providers: Vec<&str> =
        PROVIDERS.iter().map(|(k, _, _)| *k).filter(|k| id_token_profile(k).is_some()).collect();
    let provider = ctx.required_str("provider", &WhiteList::strict(&id_token_providers))?;
    let id_token = ctx.required_str("idToken", &Text::with_min(8192, 0))?;
    let nonce = ctx.optional_str("nonce", "", &Text::with_min(256, 0))?.unwrap_or_default();
    let access_token = ctx.optional_str("accessToken", "", &Text::with_min(4096, 0))?.unwrap_or_default();
    let expiry = ctx.optional("accessTokenExpiry", json!(0), &Range::new(0, 31_536_000))?;
    let expiry = Range::value(&expiry).unwrap_or(0);
    let name_param = ctx.optional_str("name", "", &Text::with_min(128, 0))?.unwrap_or_default();
    let Some((issuers, jwks_url, nonce_required)) = id_token_profile(&provider) else {
        return Err(Error::with_message(
            ErrorType::ProjectProviderUnsupported,
            "This provider does not support ID token sign-in.",
        ));
    };
    if !ctx.project.oauth_flag(&format!("{provider}NativeEnabled")) {
        return Err(Error::with_message(
            ErrorType::ProjectProviderDisabled,
            "Native sign-in is disabled for this provider. Enable it from your Appwrite console to continue.",
        ));
    }
    let mut audiences: Vec<String> = vec![ctx.project.oauth_string(&format!("{provider}Appid"))];
    if let Some(Json::Array(ids)) = ctx.project.oauth_providers.get(format!("{provider}ClientIds")) {
        audiences.extend(ids.iter().map(json_text));
    }
    audiences.retain(|a| !a.is_empty() && a != "0");
    if audiences.is_empty() {
        return Err(Error::with_message(
            ErrorType::ProjectProviderDisabled,
            "Configure a client ID or native client IDs for this provider to accept ID tokens.",
        ));
    }
    let claims = verify_id_token(issuers, jwks_url, nonce_required, &id_token, &audiences, &nonce).await?;
    let sub = claims.get("sub").map(json_text).unwrap_or_default();
    let provider_email = match claims.get("email") {
        Some(Json::String(e)) => e.clone(),
        _ => String::new(),
    };
    let is_verified = match claims.get("email_verified") {
        Some(Json::Bool(b)) => *b,
        Some(Json::String(s)) => matches!(s.to_lowercase().trim(), "1" | "true" | "on" | "yes"),
        Some(Json::Number(n)) => n.as_i64() == Some(1),
        _ => false,
    };
    let name = match claims.get("name") {
        Some(Json::String(n)) if !n.is_empty() => n.clone(),
        _ => name_param,
    };
    let photo = match claims.get("picture") {
        Some(Json::String(p)) => p.clone(),
        _ => String::new(),
    };

    let db = ctx.db()?;
    let mut user = ctx.user.clone();
    let mut upgrade = false;
    if let Some(u) = &user {
        let sequence = documents::internal_id(u.sequence);
        let taken = db
            .find_one(
                "identities",
                vec![
                    Query::equal("provider", [provider.as_str()]),
                    Query::equal("providerUid", [sub.as_str()]),
                    Query::not_equal("userInternalId", sequence.clone()),
                ],
            )
            .await?;
        if !taken.is_empty() {
            return Err(Error::new(ErrorType::UserAlreadyExists));
        }
        if !provider_email.is_empty() {
            let identity = db
                .find_one(
                    "identities",
                    vec![
                        Query::equal("providerEmail", [provider_email.as_str()]),
                        Query::not_equal("userInternalId", sequence),
                    ],
                )
                .await?;
            if !identity.is_empty() {
                return Err(Error::new(ErrorType::UserAlreadyExists));
            }
            let others = db
                .find(
                    "users",
                    vec![Query::equal("email", [provider_email.as_str()]), Query::not_equal("$id", u.id.as_str())],
                    utopia_database::Database::PERMISSION_READ,
                )
                .await?;
            if !others.is_empty() {
                return Err(Error::new(ErrorType::UserAlreadyExists));
            }
        }
        upgrade = true;
    }
    let current =
        user.as_ref().and_then(|u| appwrite_core::auth::session_verify(&u.sessions, &ctx.store.secret).cloned());
    if user.is_none() {
        let session = db
            .find_one(
                "sessions",
                vec![Query::equal("provider", [provider.as_str()]), Query::equal("providerUid", [sub.as_str()])],
            )
            .await?;
        if !session.is_empty() {
            user = documents::load_user(&db, &session.string_or_empty("userId")).await?;
        }
    }

    let mut new_user: Option<String> = None;
    let mut new_target: Option<String> = None;
    if user.is_none() {
        let identity = db
            .find_one(
                "identities",
                vec![Query::equal("provider", [provider.as_str()]), Query::equal("providerUid", [sub.as_str()])],
            )
            .await?;
        if !identity.is_empty() {
            user = documents::load_user(&db, &identity.string_or_empty("userId")).await?;
        }
        let mut email = provider_email.clone();
        let mut emails = vec![email.clone()];
        let mut meta = Metadata::default();
        let mut canonicalize = false;
        if user.is_none() && !provider_email.is_empty() {
            (email, emails, meta, canonicalize) = id_token_email(ctx, &provider_email, is_verified)?;
        }
        if user.is_none() && !email.is_empty() {
            let found = db
                .find_one("users", vec![Query::equal("email", emails.iter().map(String::as_str).collect::<Vec<_>>())])
                .await?;
            if !found.is_empty() {
                if !is_verified {
                    return Err(Error::new(ErrorType::GeneralBadRequest));
                }
                let mut found = User::from(&found);
                let domain = |e: &str| utopia_emails::Email::new(e).map(|p| p.domain().to_owned()).unwrap_or_default();
                if domain(found.email.as_deref().unwrap_or("")) == domain(&provider_email) {
                    found.email_verification = Some(true);
                }
                user = Some(found);
            }
        }
        if user.is_none() && !provider_email.is_empty() {
            let identity =
                db.find_one("identities", vec![Query::equal("providerEmail", [provider_email.as_str()])]).await?;
            if !identity.is_empty() {
                if !is_verified {
                    return Err(Error::new(ErrorType::GeneralBadRequest));
                }
                user = documents::load_user(&db, &identity.string_or_empty("userId")).await?;
            }
        }
        if user.is_none() {
            let limit = ctx.project.auth_int("limit", 0);
            if limit != 0 && db.count("users", Vec::new(), Some(10_000)).await? >= limit {
                return Err(Error::new(ErrorType::UserCountExceeded));
            }
            id_token_email_policy(ctx, &meta, &email, canonicalize)?;
            let user_id = appwrite_core::database::unique_id();
            let mut document = base::new_user(&user_id);
            document.set("email", if email.is_empty() { Value::Null } else { Value::from(email.as_str()) });
            document.set("emailVerification", Value::Bool(!email.is_empty() && is_verified));
            base::set_default_hash(&mut document);
            document.set("name", Value::from(name.as_str()));
            document.set("mfa", Value::Bool(false));
            document.set("authenticators", Value::Null);
            let search: Vec<&str> = [user_id.as_str(), email.as_str(), name.as_str()]
                .into_iter()
                .filter(|p| !p.is_empty() && *p != "0")
                .collect();
            document.set("search", Value::from(search.join(" ").as_str()));
            meta.apply(&mut document);
            let created = base::create_user(ctx, document).await?;
            new_user = Some(created.id.clone());
            if !email.is_empty() {
                let target = db.create_document("targets", base::new_target(&created, "email", &email)).await;
                match target {
                    Ok(t) => new_target = Some(t.id().to_owned()),
                    Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                        return Err(Error::new(ErrorType::UserAlreadyExists));
                    }
                    Err(e) => return Err(e.into()),
                }
            }
            user = Some(created);
        }
    }
    let Some(mut user) = user else {
        return Err(Error::new(ErrorType::UserNotFound));
    };
    base::act_as(ctx, &user);
    ctx.authorization.add_role(&format!("user:{}", user.id));
    ctx.authorization.add_role("users");
    if user.status == Some(false) {
        return Err(Error::new(ErrorType::UserBlocked));
    }
    if user.email.as_deref().is_none_or(|e| e.is_empty() || e == "0") && !provider_email.is_empty() {
        let (email, emails, meta, canonicalize) = id_token_email(ctx, &provider_email, is_verified)?;
        let others = db
            .find(
                "users",
                vec![
                    Query::equal("email", emails.iter().map(String::as_str).collect::<Vec<_>>()),
                    Query::not_equal("$id", user.id.as_str()),
                ],
                utopia_database::Database::PERMISSION_READ,
            )
            .await?;
        if !others.is_empty() {
            return Err(Error::new(ErrorType::UserAlreadyExists));
        }
        id_token_email_policy(ctx, &meta, &email, canonicalize)?;
        user.email = Some(email.clone());
        user.email_verification = Some(user.email_verification == Some(true) || is_verified);
        user.email_canonical = meta.canonical.clone();
        user.email_is_canonical = meta.is_canonical;
        user.email_is_corporate = meta.is_corporate;
        user.email_is_disposable = meta.is_disposable;
        user.email_is_free = meta.is_free;
        match db.create_document("targets", base::new_target(&user, "email", &email)).await {
            Ok(_) => {}
            Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                let existing = db
                    .authorization()
                    .skip(|| db.find_one("targets", vec![Query::equal("identifier", [email.as_str()])]))
                    .await?;
                if existing.is_empty() || existing.string_or_empty("userInternalId") != user.sequence.to_string() {
                    return Err(Error::new(ErrorType::UserAlreadyExists));
                }
            }
            Err(e) => return Err(e.into()),
        }
    }

    // The identity: created, or its tokens and photo refreshed.
    let expires_at = (!access_token.is_empty() && expiry > 0).then(|| datetime::add_seconds(datetime::now(), expiry));
    let expiry_value = expires_at.as_ref().map(datetime::value).unwrap_or(Value::Null);
    let identity = db
        .find_one(
            "identities",
            vec![
                Query::equal("userInternalId", [documents::internal_id(user.sequence)]),
                Query::equal("provider", [provider.as_str()]),
                Query::equal("providerUid", [sub.as_str()]),
            ],
        )
        .await?;
    if identity.is_empty() {
        if !provider_email.is_empty() {
            let others = db
                .find(
                    "identities",
                    vec![
                        Query::equal("providerEmail", [provider_email.as_str()]),
                        Query::not_equal("userInternalId", documents::internal_id(user.sequence)),
                    ],
                    utopia_database::Database::PERMISSION_READ,
                )
                .await?;
            if !others.is_empty() {
                return Err(Error::new(ErrorType::GeneralBadRequest));
            }
        }
        let mut document = Document::default();
        document.set("$id", Value::from(appwrite_core::database::unique_id().as_str()));
        document.set("$permissions", base::user_permissions(&user.id));
        document.set("userInternalId", documents::internal_id(user.sequence));
        document.set("userId", Value::from(user.id.as_str()));
        document.set("provider", Value::from(provider.as_str()));
        document.set("providerUid", Value::from(sub.as_str()));
        document.set("providerEmail", Value::from(provider_email.as_str()));
        document.set("providerAccessToken", Value::from(access_token.as_str()));
        document.set("providerRefreshToken", Value::Null);
        document.set("providerAccessTokenExpiry", expiry_value.clone());
        document.set("providerIdToken", Value::from(id_token.as_str()));
        document.set("photo", if photo.is_empty() { Value::Null } else { Value::from(photo.as_str()) });
        match db.create_document("identities", document).await {
            Ok(_) => {}
            Err(e) if e.is_a(utopia_database::Kind::Duplicate) => {
                if let Some(new_user) = &new_user {
                    let _ = db
                        .authorization()
                        .skip(|| async {
                            if let Some(target) = &new_target {
                                db.delete_document("targets", target).await?;
                            }
                            db.delete_document("users", new_user).await
                        })
                        .await;
                }
                return Err(Error::new(ErrorType::UserAlreadyExists));
            }
            Err(e) => return Err(e.into()),
        }
    } else {
        let mut changes = Document::default();
        changes.set("providerIdToken", Value::from(id_token.as_str()));
        if !access_token.is_empty() {
            changes.set("providerAccessToken", Value::from(access_token.as_str()));
            changes.set("providerAccessTokenExpiry", expiry_value.clone());
        }
        if !photo.is_empty() {
            changes.set("photo", Value::from(photo.as_str()));
        }
        db.update_document("identities", identity.id(), changes).await?;
    }

    let mut updates = Document::default();
    if user.name.as_deref().is_none_or(|n| n.is_empty() || n == "0") {
        user.name = Some(name.clone());
        updates.set("name", Value::from(name.as_str()));
    }
    updates.set("status", Value::Bool(true));
    updates.set("email", documents::text(user.email.as_deref()));
    updates.set("emailVerification", Value::Bool(user.email_verification == Some(true)));
    let meta = Metadata {
        canonical: user.email_canonical.clone(),
        is_canonical: user.email_is_canonical,
        is_corporate: user.email_is_corporate,
        is_disposable: user.email_is_disposable,
        is_free: user.email_is_free,
    };
    meta.apply(&mut updates);
    db.update_document("users", &user.id, updates).await?;

    let duration = base::duration(ctx);
    let secret = base::token_secret(256)?;
    let expire = datetime::add_seconds(datetime::now(), duration);
    let mut session = base::new_session(ctx, &user, &provider, &secret, &["email", "oauth2"], &expire).await?;
    session.set("providerUid", Value::from(sub.as_str()));
    session.set("providerAccessToken", Value::from(access_token.as_str()));
    session.set("providerRefreshToken", Value::Null);
    session.set("providerAccessTokenExpiry", expiry_value);
    let created = db.create_document("sessions", session).await?;
    let session = Session::from(&created);
    if upgrade {
        for target in user.targets.iter().filter(|t| t.provider_type == "push") {
            let mut updates = Document::default();
            updates.set("sessionId", Value::from(session.id.as_str()));
            updates.set("sessionInternalId", created.sequence().cloned().unwrap_or(Value::Null));
            db.update_document("targets", &target.id, updates).await?;
        }
    }
    let encoded = base::encode_store(&user.id, &secret)?;
    let cookie_expire = datetime::add_seconds(datetime::now(), duration);
    let country = base::country_name(ctx, session.country_code.as_deref().unwrap_or(""));
    let model = SessionModel {
        session: &session,
        country_name: &country,
        current: true,
        secret: Some(&encoded),
        render: ctx.render(),
    };
    let mut response = ctx.created(&model);
    base::add_session_cookies(ctx, &mut response, &encoded, &cookie_expire);
    ctx.event_param("userId", user.id.clone());
    ctx.event_param("sessionId", session.id.clone());
    ctx.event_payload(&model);
    if let Err(e) = base::session_alert(ctx, &user, &session).await {
        tracing::warn!(error = %e, "session alert failed");
    }
    // The replaced session goes once the caller holds the new one.
    if let Some(current) = current {
        let document = db.get_document("sessions", &current.id, Vec::new(), false).await?;
        if !document.is_empty() {
            db.delete_document("sessions", document.id()).await?;
        }
    }
    db.purge_cached_document("users", Some(&user.id)).await?;
    Ok(response)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Every provider's authorization URL is PHP's: `tests/fixtures/login-urls.json`
    /// holds `getLoginURL()` of each provider without PKCE (made by
    /// `login-urls.php` in the API container), for a plain and a JSON secret,
    /// with and without extra scopes.
    #[test]
    fn login_urls_match_php() {
        let fixtures: Vec<Json> =
            serde_json::from_str(include_str!("../tests/fixtures/login-urls.json")).expect("fixtures");
        let state: Map<String, Json> = serde_json::from_value(json!({
            "success": "http://localhost/s?a=1",
            "failure": "http://localhost/f",
            "token": false,
            "nonce": "0123456789abcdef0123456789abcdef",
            "state": "app",
        }))
        .expect("state");
        let mut failures = Vec::new();
        for fixture in &fixtures {
            let provider = fixture["provider"].as_str().unwrap_or_default();
            let secret = fixture["secret"].as_str().unwrap_or_default();
            let scopes: Vec<String> =
                fixture["scopes"].as_array().map(|a| a.iter().map(json_text).collect()).unwrap_or_default();
            let callback = format!("http://localhost:9501/v1/account/sessions/oauth2/callback/{provider}/p1");
            let mut adapter = Provider::new(provider, "appid", secret, &callback, state.clone(), &scopes);
            let got = adapter.login_url().unwrap_or_else(|_| "ERROR".to_owned());
            if got != fixture["url"].as_str().unwrap_or_default() {
                failures.push(format!("{provider} {secret} {scopes:?}:\n  rust {got}\n  php  {}", fixture["url"]));
            }
        }
        assert!(failures.is_empty(), "{} of {} differ:\n{}", failures.len(), fixtures.len(), failures.join("\n"));
    }

    /// The PKCE verifier survives the state: encrypted out, decrypted back.
    #[test]
    fn pkce_state_round_trips() {
        if utopia_system::env("_APP_OPENSSL_KEY_V1").is_none() {
            return;
        }
        let mut adapter = Provider::new("kick", "id", "secret", "http://cb", Map::new(), &[]);
        let url = adapter.login_url().expect("url");
        let verifier = adapter.pkce_verifier();
        let query = url.split_once('?').map(|(_, q)| q).unwrap_or_default();
        let state = base::parse_query(query).get("state").map(json_text).unwrap_or_default();
        let mut back = Provider::new("kick", "id", "secret", "http://cb", Map::new(), &[]);
        back.parse_state(&state).expect("state");
        assert_eq!(back.pkce_verifier(), verifier);
    }
}
