//! Registrar facade, models, and HTTP adapters.

mod adapter;
mod contact;
mod domain;
mod price;
mod renewal;
mod transfer_status;
mod update_details;

pub mod adapters;

use crate::cache::Cache;
use crate::error::DomainsError;

pub use adapter::Adapter;
pub use contact::{Contact, Contacts};
pub use domain::RegistrarDomain;
pub use price::Price;
pub use renewal::Renewal;
pub use transfer_status::{TransferStatus, TransferStatusEnum};
pub use update_details::UpdateDetails;

pub use adapters::{Mock, NameCom, OpenSrs};

/// Suggested domain metadata (PHP associative array).
#[derive(Debug, Clone, PartialEq)]
pub struct SuggestItem {
    pub available: bool,
    pub price: Option<f64>,
    pub kind: String,
    pub renewal_price: Option<f64>,
    pub purchase_type: Option<String>,
}

/// Query for [`Registrar::suggest`] (`array|string` in PHP).
#[derive(Debug, Clone)]
pub enum SuggestQuery {
    Text(String),
    Terms(Vec<String>),
}

impl From<&str> for SuggestQuery {
    fn from(value: &str) -> Self {
        Self::Text(value.to_string())
    }
}

impl From<String> for SuggestQuery {
    fn from(value: String) -> Self {
        Self::Text(value)
    }
}

impl From<Vec<String>> for SuggestQuery {
    fn from(value: Vec<String>) -> Self {
        Self::Terms(value)
    }
}

impl From<Vec<&str>> for SuggestQuery {
    fn from(value: Vec<&str>) -> Self {
        Self::Terms(value.into_iter().map(str::to_string).collect())
    }
}

impl SuggestQuery {
    pub(crate) fn as_terms(&self) -> Vec<String> {
        match self {
            Self::Text(text) => vec![text.clone()],
            Self::Terms(terms) => terms.clone(),
        }
    }

    pub(crate) fn joined(&self, sep: &str) -> String {
        match self {
            Self::Text(text) => text.clone(),
            Self::Terms(terms) => terms.join(sep),
        }
    }
}

/// Nameserver update result (PHP associative array).
#[derive(Debug, Clone, PartialEq)]
pub struct NameserverUpdate {
    pub successful: bool,
    pub nameservers: Vec<String>,
    pub code: Option<String>,
    pub text: Option<String>,
    pub error: Option<String>,
}

/// Registrar facade (PHP `Utopia\Domains\Registrar`).
#[derive(Debug)]
pub struct Registrar {
    adapter: Box<dyn Adapter>,
}

impl Registrar {
    /// Registration type: new.
    pub const REG_TYPE_NEW: &'static str = "new";
    /// Registration type: transfer.
    pub const REG_TYPE_TRANSFER: &'static str = "transfer";
    /// Registration type: renewal.
    pub const REG_TYPE_RENEWAL: &'static str = "renewal";
    /// Registration type: trade.
    pub const REG_TYPE_TRADE: &'static str = "trade";

    /// Construct with PHP defaults (`$defaultNameservers = []`, no cache,
    /// `$connectTimeout = 5`, `$timeout = 10`).
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Self::new_with(adapter, Vec::new(), None, 5, 10)
    }

    /// Full PHP constructor.
    pub fn new_with(
        adapter: impl Adapter + 'static,
        default_nameservers: Vec<String>,
        cache: Option<Cache>,
        connect_timeout: u64,
        timeout: u64,
    ) -> Self {
        let mut adapter: Box<dyn Adapter> = Box::new(adapter);
        if !default_nameservers.is_empty() {
            adapter.set_default_nameservers(default_nameservers);
        }
        if cache.is_some() {
            adapter.set_cache(cache);
        }
        adapter.set_connect_timeout(connect_timeout);
        adapter.set_timeout(timeout);
        Self { adapter }
    }

    /// Adapter name (`getName()`).
    pub fn get_name(&self) -> String {
        self.adapter.get_name()
    }

    /// Whether `domain` is available for registration.
    pub fn available(&self, domain: &str) -> Result<bool, DomainsError> {
        self.adapter.available(domain)
    }

    /// Purchase a domain. Returns an order id.
    #[allow(clippy::too_many_arguments)]
    pub fn purchase(
        &self,
        domain: &str,
        contacts: impl Into<Contacts>,
        period_years: i64,
        nameservers: Vec<String>,
        autorenew_enabled: bool,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        self.adapter.purchase(
            domain,
            contacts.into(),
            period_years,
            nameservers,
            autorenew_enabled,
            purchase_price,
        )
    }

    /// Suggest domain names.
    #[allow(clippy::too_many_arguments)]
    pub fn suggest(
        &self,
        query: impl Into<SuggestQuery>,
        tlds: Vec<String>,
        limit: Option<i64>,
        filter_type: Option<&str>,
        price_max: Option<i64>,
        price_min: Option<i64>,
    ) -> Result<std::collections::HashMap<String, SuggestItem>, DomainsError> {
        self.adapter
            .suggest(query.into(), tlds, limit, filter_type, price_max, price_min)
    }

    /// TLDs supported by the adapter.
    pub fn tlds(&self) -> Result<Vec<String>, DomainsError> {
        self.adapter.tlds()
    }

    /// Domain details (`getDomain()`).
    pub fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError> {
        self.adapter.get_domain(domain)
    }

    /// Update domain details such as auto-renew.
    pub fn update_domain(
        &self,
        domain: &str,
        details: &UpdateDetails,
    ) -> Result<bool, DomainsError> {
        self.adapter.update_domain(domain, details)
    }

    /// Replace nameservers.
    pub fn update_nameservers(
        &self,
        domain: &str,
        nameservers: Vec<String>,
    ) -> Result<NameserverUpdate, DomainsError> {
        self.adapter.update_nameservers(domain, nameservers)
    }

    /// Registration / renewal / transfer price.
    pub fn get_price(
        &self,
        domain: &str,
        period_years: i64,
        reg_type: &str,
        ttl: u64,
    ) -> Result<Price, DomainsError> {
        self.adapter.get_price(domain, period_years, reg_type, ttl)
    }

    /// Renew a domain.
    pub fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError> {
        self.adapter.renew(domain, period_years)
    }

    /// Transfer a domain. Returns an order id.
    pub fn transfer(
        &self,
        domain: &str,
        auth_code: &str,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        self.adapter.transfer(domain, auth_code, purchase_price)
    }

    /// EPP authorization code.
    pub fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError> {
        self.adapter.get_auth_code(domain)
    }

    /// Cancel pending purchase orders.
    pub fn cancel_purchase(&self) -> Result<bool, DomainsError> {
        self.adapter.cancel_purchase()
    }

    /// Transfer status for a domain.
    pub fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError> {
        self.adapter.check_transfer_status(domain)
    }
}
