//! Auth resources.

use serde_json::{json, Map, Value};

use crate::resource::{
    Resource, ResourceBase, TYPE_AUTH_METHODS, TYPE_HASH, TYPE_MEMBERSHIP, TYPE_OAUTH2_PROVIDER,
    TYPE_POLICIES, TYPE_TEAM, TYPE_USER,
};
use crate::transfer::GROUP_AUTH;

type ProviderField = (&'static str, &'static str, Option<&'static str>);
type ProviderDefinition = (&'static str, &'static [ProviderField]);

macro_rules! simple_resource {
    ($ty:ident, $name:expr, $group:expr) => {
        #[derive(Debug, Clone)]
        pub struct $ty {
            base: ResourceBase,
            #[allow(dead_code)]
            extra: Map<String, Value>,
        }
        impl $ty {
            pub fn new(id: impl Into<String>) -> Self {
                Self {
                    base: ResourceBase::new(id),
                    extra: Map::new(),
                }
            }
        }
        impl Resource for $ty {
            fn get_name(&self) -> &'static str {
                $name
            }
            fn get_group(&self) -> &'static str {
                $group
            }
            fn base(&self) -> &ResourceBase {
                &self.base
            }
            fn base_mut(&mut self) -> &mut ResourceBase {
                &mut self.base
            }
        }
    };
}

#[derive(Debug, Clone)]
pub struct User {
    base: ResourceBase,
    email: String,
    password: String,
    name: String,
}

impl User {
    pub fn new(id: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            email: String::new(),
            password: String::new(),
            name: String::new(),
        }
    }
    #[must_use]
    pub fn get_email(&self) -> &str {
        &self.email
    }
    pub fn set_email(&mut self, email: impl Into<String>) {
        self.email = email.into();
    }
    #[must_use]
    pub fn get_password(&self) -> &str {
        &self.password
    }
    pub fn set_password(&mut self, password: impl Into<String>) {
        self.password = password.into();
    }
    #[must_use]
    pub fn get_username(&self) -> &str {
        &self.name
    }
    pub fn set_username(&mut self, name: impl Into<String>) {
        self.name = name.into();
    }
}

impl Resource for User {
    fn get_name(&self) -> &'static str {
        TYPE_USER
    }
    fn get_group(&self) -> &'static str {
        GROUP_AUTH
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}

#[derive(Debug, Clone)]
pub struct Team {
    base: ResourceBase,
    name: String,
}

impl Team {
    pub fn new(id: impl Into<String>, name: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            name: name.into(),
        }
    }
    #[must_use]
    pub fn get_team_name(&self) -> &str {
        &self.name
    }
}

impl Resource for Team {
    fn get_name(&self) -> &'static str {
        TYPE_TEAM
    }
    fn get_group(&self) -> &'static str {
        GROUP_AUTH
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}

simple_resource!(Membership, TYPE_MEMBERSHIP, GROUP_AUTH);
simple_resource!(Hash, TYPE_HASH, GROUP_AUTH);
simple_resource!(AuthMethods, TYPE_AUTH_METHODS, GROUP_AUTH);
simple_resource!(Policies, TYPE_POLICIES, GROUP_AUTH);

/// PHP `Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider`.
#[derive(Debug, Clone)]
pub struct OAuth2Provider {
    base: ResourceBase,
    provider_key: String,
    enabled: bool,
    settings: Map<String, Value>,
}

impl OAuth2Provider {
    pub const TARGET_APP_ID: &'static str = "appId";
    pub const TARGET_SECRET: &'static str = "secret";

    /// PHP `OAuth2Provider::PROVIDERS`.
    pub const PROVIDERS: &'static [ProviderDefinition] = &[
        ("amazon", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "apple",
            &[
                ("serviceId", Self::TARGET_APP_ID, None),
                ("keyId", Self::TARGET_SECRET, Some("keyID")),
                ("teamId", Self::TARGET_SECRET, Some("teamID")),
            ],
        ),
        ("appwrite", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "auth0",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("endpoint", Self::TARGET_SECRET, None),
            ],
        ),
        (
            "authentik",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("endpoint", Self::TARGET_SECRET, None),
            ],
        ),
        ("autodesk", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("bitbucket", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("bitly", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("box", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("dailymotion", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("discord", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("disqus", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("dropbox", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("etsy", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("facebook", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("figma", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "fusionauth",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("endpoint", Self::TARGET_SECRET, None),
            ],
        ),
        ("github", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "gitlab",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("endpoint", Self::TARGET_SECRET, None),
            ],
        ),
        (
            "google",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("prompt", Self::TARGET_SECRET, None),
            ],
        ),
        (
            "keycloak",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("endpoint", Self::TARGET_SECRET, Some("keycloakDomain")),
                ("realmName", Self::TARGET_SECRET, Some("keycloakRealm")),
            ],
        ),
        ("kick", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("linkedin", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "microsoft",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("tenant", Self::TARGET_SECRET, None),
            ],
        ),
        ("notion", &[("clientId", Self::TARGET_APP_ID, None)]),
        (
            "oidc",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                (
                    "wellKnownURL",
                    Self::TARGET_SECRET,
                    Some("wellKnownEndpoint"),
                ),
                (
                    "authorizationURL",
                    Self::TARGET_SECRET,
                    Some("authorizationEndpoint"),
                ),
                ("tokenURL", Self::TARGET_SECRET, Some("tokenEndpoint")),
                ("userInfoURL", Self::TARGET_SECRET, Some("userInfoEndpoint")),
            ],
        ),
        (
            "okta",
            &[
                ("clientId", Self::TARGET_APP_ID, None),
                ("domain", Self::TARGET_SECRET, Some("oktaDomain")),
                ("authorizationServerId", Self::TARGET_SECRET, None),
            ],
        ),
        ("paypal", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("paypalSandbox", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("podio", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("salesforce", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("slack", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("spotify", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("stripe", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("tradeshift", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("tradeshiftBox", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("twitch", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("wordpress", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("x", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("yahoo", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("yandex", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("zoho", &[("clientId", Self::TARGET_APP_ID, None)]),
        ("zoom", &[("clientId", Self::TARGET_APP_ID, None)]),
    ];

    pub fn provider_keys() -> impl Iterator<Item = &'static str> {
        Self::PROVIDERS.iter().map(|(k, _)| *k)
    }

    fn descriptor(provider_key: &str) -> Option<&'static [ProviderField]> {
        Self::PROVIDERS
            .iter()
            .find(|(k, _)| *k == provider_key)
            .map(|(_, fields)| *fields)
    }

    pub fn new(
        id: impl Into<String>,
        provider_key: impl Into<String>,
        enabled: bool,
        settings: Map<String, Value>,
    ) -> Self {
        Self {
            base: ResourceBase::new(id),
            provider_key: provider_key.into(),
            enabled,
            settings,
        }
    }

    /// PHP `OAuth2Provider::fromArray`. Unknown providers return `None`. Secrets are never copied.
    #[must_use]
    pub fn from_array(provider_key: &str, array: &Map<String, Value>) -> Option<Self> {
        let allowed = Self::descriptor(provider_key)?;
        let mut settings = Map::new();
        for (field, _, _) in allowed {
            if let Some(value) = array.get(*field) {
                settings.insert((*field).to_owned(), value.clone());
            }
        }
        let mut provider = Self::new(
            array
                .get("id")
                .and_then(Value::as_str)
                .unwrap_or(provider_key),
            provider_key,
            array
                .get("enabled")
                .and_then(Value::as_bool)
                .unwrap_or(false),
            settings,
        );
        array
            .get("createdAt")
            .and_then(Value::as_str)
            .unwrap_or("")
            .clone_into(&mut provider.base.created_at);
        array
            .get("updatedAt")
            .and_then(Value::as_str)
            .unwrap_or("")
            .clone_into(&mut provider.base.updated_at);
        Some(provider)
    }

    #[must_use]
    pub fn get_provider_key(&self) -> &str {
        &self.provider_key
    }
    #[must_use]
    pub fn get_enabled(&self) -> bool {
        self.enabled
    }
    #[must_use]
    pub fn get_settings(&self) -> &Map<String, Value> {
        &self.settings
    }
    #[must_use]
    pub fn get_destination_app_id(&self) -> Option<String> {
        let desc = Self::descriptor(&self.provider_key)?;
        for (field, target, _) in desc {
            if *target != Self::TARGET_APP_ID {
                continue;
            }
            let value = self.settings.get(*field)?;
            if is_empty_value(value) {
                return None;
            }
            return Some(match value {
                Value::String(s) => s.clone(),
                other => other.to_string(),
            });
        }
        None
    }
    #[must_use]
    pub fn get_destination_secret_fields(&self) -> Map<String, Value> {
        let mut fields = Map::new();
        let Some(desc) = Self::descriptor(&self.provider_key) else {
            return fields;
        };
        for (field, target, key) in desc {
            if *target != Self::TARGET_SECRET || !self.settings.contains_key(*field) {
                continue;
            }
            let value = &self.settings[*field];
            if is_empty_value(value) {
                continue;
            }
            fields.insert(key.unwrap_or(field).to_owned(), value.clone());
        }
        fields
    }
    #[must_use]
    pub fn is_configured(&self) -> bool {
        self.enabled || self.get_destination_app_id().is_some()
    }
}

fn is_empty_value(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::String(s) if s.is_empty() => true,
        Value::Array(a) if a.is_empty() => true,
        Value::Object(o) if o.is_empty() => true,
        _ => false,
    }
}

impl Resource for OAuth2Provider {
    fn get_name(&self) -> &'static str {
        TYPE_OAUTH2_PROVIDER
    }
    fn get_group(&self) -> &'static str {
        GROUP_AUTH
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "id": self.get_id(),
            "providerKey": self.provider_key,
            "enabled": self.enabled,
            "settings": self.settings,
            "createdAt": self.get_created_at(),
            "updatedAt": self.get_updated_at(),
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}
