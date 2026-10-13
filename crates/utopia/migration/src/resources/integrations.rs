//! Platforms and API keys.

use php_std::{Array, Value};

use super::{Input, param, to_array, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

/// An API key (never its secret).
///
/// PHP: `Utopia\Migration\Resources\Integrations\ApiKey` (`getApiKeyName()` is the name).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ApiKey {
    /// The name.
    pub name: String,
    /// The scopes.
    pub scopes: Array,
    /// When it expires.
    pub expire: String,
    /// When it was last used.
    pub accessed_at: String,
    /// The SDKs that used it.
    pub sdks: Array,
}

impl ApiKey {
    /// A resource of this key.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::ApiKey(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `ApiKey::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Integrations\\ApiKey::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let key = ApiKey {
            name: to_string(&i.raw("name"), &p(2, "name"))?,
            scopes: to_array(&i.or("scopes", Value::Array(Array::new())), &p(3, "scopes"))?,
            expire: to_string(&i.or("expire", Value::from("")), &p(4, "expire"))?,
            accessed_at: to_string(&i.or("accessedAt", Value::from("")), &p(5, "accessedAt"))?,
            sdks: to_array(&i.or("sdks", Value::Array(Array::new())), &p(6, "sdks"))?,
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(7, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(8, "updatedAt"))?;
        Ok(key.resource(id, created, updated))
    }
}

/// A client platform (web, Android, Apple, ...).
///
/// PHP: `Utopia\Migration\Resources\Integrations\Platform` (`getPlatformName()` is the name).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Platform {
    /// The platform type (`getType()`).
    pub platform_type: String,
    /// The name.
    pub name: String,
    /// The package or bundle identifier.
    pub key: String,
    /// The store id.
    pub store: String,
    /// The web hostname.
    pub hostname: String,
}

impl Platform {
    /// A resource of this platform.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::Platform(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `Platform::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Integrations\\Platform::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let platform = Platform {
            platform_type: to_string(&i.raw("type"), &p(2, "type"))?,
            name: to_string(&i.raw("name"), &p(3, "name"))?,
            key: to_string(&i.or("key", Value::from("")), &p(4, "key"))?,
            store: to_string(&i.or("store", Value::from("")), &p(5, "store"))?,
            hostname: to_string(&i.or("hostname", Value::from("")), &p(6, "hostname"))?,
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(7, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(8, "updatedAt"))?;
        Ok(platform.resource(id, created, updated))
    }
}
