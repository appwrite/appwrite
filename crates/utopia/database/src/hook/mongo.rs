//! The MongoDB adapter's read hooks: they narrow the filter of a read, an
//! update or a delete before the adapter runs it. Filters are PHP arrays
//! ([`Array`]), as the adapter builds them.
//!
//! PHP: `Utopia\Database\Hook\Mongo\{Read, Permission, Tenant}`.

use std::sync::Arc;

use crate::permission::PermissionType;
use crate::storage;
use crate::validator::authorization::Authorization;
use crate::value::{Array, ArrayKey, Str, Value};

/// Narrows a MongoDB filter before the adapter runs it.
///
/// The database authorizes every write before it reaches the adapter, so
/// write paths apply the tenant hook alone and never a permission filter.
///
/// PHP: `Utopia\Database\Hook\Mongo\Read`.
pub trait Read: Send + Sync {
    /// The filter narrowed for a read of `collection` under `permission`
    /// (`applyFilters()`).
    fn apply_filters(&self, filters: Array, collection: &str, permission: PermissionType) -> Array;
}

/// Narrows a read to the documents the current roles may access: an
/// exact-match `$in` of `type("role")` strings against the embedded
/// `_permissions` array, added to what the filter already holds for it.
/// Nothing while authorization is disabled.
///
/// PHP: `Utopia\Database\Hook\Mongo\Permission`.
#[derive(Debug, Clone)]
pub struct Permission {
    authorization: Arc<Authorization>,
}

impl Permission {
    /// The hook over `authorization`'s roles.
    pub fn new(authorization: Arc<Authorization>) -> Self {
        Permission { authorization }
    }

    /// `<type>("<role>")` for each role of `authorization`: the strings
    /// documents' `_permissions` hold.
    pub fn strings(authorization: &Authorization, permission: PermissionType) -> Vec<Str> {
        authorization
            .roles()
            .iter()
            .map(|role| {
                let role = role.to_php_string().unwrap_or(Str::EMPTY);
                let mut s = format!("{}(\"", permission.as_str()).into_bytes();
                s.extend_from_slice(role.as_bytes());
                s.extend_from_slice(b"\")");
                Str::from(s)
            })
            .collect()
    }
}

impl Read for Permission {
    fn apply_filters(&self, mut filters: Array, _collection: &str, permission: PermissionType) -> Array {
        if !self.authorization.status() {
            return filters;
        }
        let permissions: Array = Self::strings(&self.authorization, permission).into_iter().map(Value::Str).collect();
        let key = ArrayKey::Str(Str::from_static(storage::PERMISSIONS));
        let mut filter = match filters.get(&key) {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        };
        filter.set(ArrayKey::Str(Str::from_static("$in")), Value::Array(permissions));
        filters.set(key, Value::Array(filter));
        filters
    }
}

/// Scopes every read, update and delete filter to the current tenant when
/// tables are shared: `_tenant` set to what the tenant filter answers for
/// the collection.
///
/// PHP: `Utopia\Database\Hook\Mongo\Tenant`.
pub struct Tenant<'a> {
    shared_tables: bool,
    tenant_filters: Box<dyn Fn(&str) -> Value + Send + Sync + 'a>,
}

impl std::fmt::Debug for Tenant<'_> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Tenant").field("shared_tables", &self.shared_tables).finish()
    }
}

impl<'a> Tenant<'a> {
    /// The hook: `tenant_filters` gives the `_tenant` filter of a collection.
    pub fn new(shared_tables: bool, tenant_filters: impl Fn(&str) -> Value + Send + Sync + 'a) -> Self {
        Tenant { shared_tables, tenant_filters: Box::new(tenant_filters) }
    }
}

impl Read for Tenant<'_> {
    fn apply_filters(&self, mut filters: Array, collection: &str, _permission: PermissionType) -> Array {
        if !self.shared_tables {
            return filters;
        }
        filters.set(ArrayKey::Str(Str::from_static(storage::TENANT)), (self.tenant_filters)(collection));
        filters
    }
}
