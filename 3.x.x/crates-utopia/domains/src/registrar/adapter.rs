use crate::cache::Cache;
use crate::error::DomainsError;

use super::{
    Contacts, NameserverUpdate, Price, RegistrarDomain, Renewal, SuggestItem, SuggestQuery,
    TransferStatus, UpdateDetails,
};

/// Registrar adapter (PHP `Utopia\Domains\Registrar\Adapter`).
pub trait Adapter: Send + Sync + std::fmt::Debug {
    /// Adapter identifier (`getName()`).
    fn get_name(&self) -> String;

    /// Check availability.
    fn available(&self, domain: &str) -> Result<bool, DomainsError>;

    /// Purchase a domain.
    #[allow(clippy::too_many_arguments)]
    fn purchase(
        &self,
        domain: &str,
        contacts: Contacts,
        period_years: i64,
        nameservers: Vec<String>,
        autorenew_enabled: bool,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError>;

    /// Suggest domains.
    #[allow(clippy::too_many_arguments)]
    fn suggest(
        &self,
        query: SuggestQuery,
        tlds: Vec<String>,
        limit: Option<i64>,
        filter_type: Option<&str>,
        price_max: Option<i64>,
        price_min: Option<i64>,
    ) -> Result<std::collections::HashMap<String, SuggestItem>, DomainsError>;

    /// Supported TLDs.
    fn tlds(&self) -> Result<Vec<String>, DomainsError>;

    /// Fetch domain details.
    fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError>;

    /// Update domain details.
    fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, DomainsError>;

    /// Update nameservers. Default matches PHP (`Method not implemented`).
    fn update_nameservers(
        &self,
        _domain: &str,
        _nameservers: Vec<String>,
    ) -> Result<NameserverUpdate, DomainsError> {
        Err(DomainsError::generic("Method not implemented", 0))
    }

    /// Fetch a price.
    fn get_price(
        &self,
        domain: &str,
        period_years: i64,
        reg_type: &str,
        ttl: u64,
    ) -> Result<Price, DomainsError>;

    /// Renew a domain.
    fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError>;

    /// Transfer a domain.
    fn transfer(
        &self,
        domain: &str,
        auth_code: &str,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError>;

    /// Fetch an EPP auth code.
    fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError>;

    /// Transfer status.
    fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError>;

    /// Cancel pending purchases.
    fn cancel_purchase(&self) -> Result<bool, DomainsError>;

    /// Default nameservers used when purchase is called with an empty list.
    fn set_default_nameservers(&mut self, nameservers: Vec<String>);

    /// Attach a cache (used by `getPrice`).
    fn set_cache(&mut self, cache: Option<Cache>);

    /// TCP connect timeout in seconds.
    fn set_connect_timeout(&mut self, connect_timeout: u64);

    /// Request timeout in seconds.
    fn set_timeout(&mut self, timeout: u64);
}

/// Shared HTTP adapter state (timeouts, cache, nameservers).
#[derive(Debug)]
pub(crate) struct AdapterState {
    pub default_nameservers: Vec<String>,
    pub cache: Option<Cache>,
    pub connect_timeout: u64,
    pub timeout: u64,
}

impl Default for AdapterState {
    fn default() -> Self {
        Self {
            default_nameservers: Vec::new(),
            cache: None,
            connect_timeout: 5,
            timeout: 10,
        }
    }
}

impl AdapterState {
    pub(crate) fn nameservers_or_default(&self, nameservers: Vec<String>) -> Vec<String> {
        if nameservers.is_empty() {
            self.default_nameservers.clone()
        } else {
            nameservers
        }
    }
}

/// PHP `empty($value)` for contact strings.
pub(crate) fn php_empty(value: &str) -> bool {
    value.is_empty() || value == "0"
}

/// Approximate PHP `FILTER_VALIDATE_EMAIL`.
pub(crate) fn is_valid_email(email: &str) -> bool {
    let Some((local, domain)) = email.rsplit_once('@') else {
        return false;
    };
    if local.is_empty() || domain.is_empty() || local.len() > 64 {
        return false;
    }
    if email.contains(' ') || email.contains('\n') || email.contains('\r') {
        return false;
    }
    domain.contains('.')
}
