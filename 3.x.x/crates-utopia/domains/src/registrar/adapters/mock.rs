use std::collections::HashMap;
use std::time::{SystemTime, UNIX_EPOCH};

use md5::{Digest, Md5};
use parking_lot::Mutex;
use serde_json::{json, Value};
use time::{Duration, OffsetDateTime};

use crate::cache::Cache;
use crate::error::DomainsError;
use crate::registrar::adapter::{is_valid_email, php_empty, Adapter, AdapterState};
use crate::registrar::{
    Contact, Contacts, NameserverUpdate, Price, Registrar, RegistrarDomain, Renewal, SuggestItem,
    SuggestQuery, TransferStatus, TransferStatusEnum, UpdateDetails,
};

const RESPONSE_CODE_BAD_REQUEST: i64 = 400;
const RESPONSE_CODE_NOT_FOUND: i64 = 404;
const RESPONSE_CODE_INVALID_CONTACT: i64 = 465;
const RESPONSE_CODE_DOMAIN_TAKEN: i64 = 485;

/// In-memory registrar used by PHP `MockTest` (PHP `Adapter\Mock`).
#[derive(Debug)]
pub struct Mock {
    taken_domains: Mutex<Vec<String>>,
    purchased_domains: Mutex<Vec<String>>,
    transferred_domains: Mutex<Vec<String>>,
    supported_tlds: Mutex<Vec<String>>,
    default_price: Mutex<f64>,
    premium_domains: Mutex<HashMap<String, f64>>,
    state: AdapterState,
}

impl Mock {
    /// PHP constructor.
    pub fn new(
        taken_domains: Vec<String>,
        supported_tlds: Vec<String>,
        default_price: f64,
    ) -> Self {
        let mut taken = vec![
            "google.com".into(),
            "facebook.com".into(),
            "amazon.com".into(),
        ];
        taken.extend(taken_domains);
        let tlds = if supported_tlds.is_empty() {
            vec![
                "com".into(),
                "net".into(),
                "org".into(),
                "io".into(),
                "dev".into(),
                "app".into(),
            ]
        } else {
            supported_tlds
        };
        Self {
            taken_domains: Mutex::new(taken),
            purchased_domains: Mutex::new(Vec::new()),
            transferred_domains: Mutex::new(Vec::new()),
            supported_tlds: Mutex::new(tlds),
            default_price: Mutex::new(default_price),
            premium_domains: Mutex::new(HashMap::from([
                ("premium.com".into(), 5000.0),
                ("business.com".into(), 10_000.0),
                ("shop.net".into(), 2500.0),
            ])),
            state: AdapterState::default(),
        }
    }

    /// PHP `new Mock()` with all defaults.
    pub fn default_mock() -> Self {
        Self::new(Vec::new(), Vec::new(), 12.99)
    }

    /// Purchased domains in this mock session.
    pub fn get_purchased_domains(&self) -> Vec<String> {
        self.purchased_domains.lock().clone()
    }

    /// Transferred domains in this mock session.
    pub fn get_transferred_domains(&self) -> Vec<String> {
        self.transferred_domains.lock().clone()
    }

    /// Reset purchased / transferred lists.
    pub fn reset(&self) {
        self.purchased_domains.lock().clear();
        self.transferred_domains.lock().clear();
    }

    /// Mark a domain as taken.
    pub fn add_taken_domain(&self, domain: impl Into<String>) {
        let domain = domain.into();
        let mut taken = self.taken_domains.lock();
        if !taken.iter().any(|d| d == &domain) {
            taken.push(domain);
        }
    }

    /// Add a premium domain and price.
    pub fn add_premium_domain(&self, domain: impl Into<String>, price: f64) {
        self.premium_domains.lock().insert(domain.into(), price);
    }

    fn is_taken_or_purchased(&self, domain: &str) -> bool {
        self.taken_domains.lock().iter().any(|d| d == domain)
            || self.purchased_domains.lock().iter().any(|d| d == domain)
    }

    fn validate_contacts(&self, contacts: &Contacts) -> Result<(), DomainsError> {
        let list: Vec<Contact> = match contacts {
            Contacts::Single(c) => vec![c.clone()],
            Contacts::List(list) => list.clone(),
            Contacts::Typed(map) => map.values().cloned().collect(),
        };
        for contact in list {
            if !matches!(
                contacts,
                Contacts::Single(_) | Contacts::List(_) | Contacts::Typed(_)
            ) {
                return Err(DomainsError::invalid_contact(
                    "Invalid contact: contact must be an instance of Contact",
                    RESPONSE_CODE_INVALID_CONTACT,
                ));
            }
            let data = contact.to_array();
            for field in [
                "firstname",
                "lastname",
                "email",
                "phone",
                "address1",
                "city",
                "state",
                "postalcode",
                "country",
            ] {
                let value = data.get(field).map_or("", String::as_str);
                if php_empty(value) {
                    return Err(DomainsError::invalid_contact(
                        format!("Invalid contact: missing required field '{field}'"),
                        RESPONSE_CODE_INVALID_CONTACT,
                    ));
                }
            }
            if !is_valid_email(data.get("email").map_or("", String::as_str)) {
                return Err(DomainsError::invalid_contact(
                    "Invalid contact: invalid email format",
                    RESPONSE_CODE_INVALID_CONTACT,
                ));
            }
        }
        Ok(())
    }
}

fn md5_hex(input: &str) -> String {
    let mut hasher = Md5::new();
    hasher.update(input.as_bytes());
    format!("{:x}", hasher.finalize())
}

fn unix_now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

impl Default for Mock {
    fn default() -> Self {
        Self::default_mock()
    }
}

impl Adapter for Mock {
    fn get_name(&self) -> String {
        "mock".into()
    }

    fn available(&self, domain: &str) -> Result<bool, DomainsError> {
        Ok(!self.is_taken_or_purchased(domain))
    }

    fn purchase(
        &self,
        domain: &str,
        contacts: Contacts,
        _period_years: i64,
        _nameservers: Vec<String>,
        _autorenew_enabled: bool,
        _purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        if !self.available(domain)? {
            return Err(DomainsError::domain_taken(
                format!("Domain {domain} is not available for registration"),
                RESPONSE_CODE_DOMAIN_TAKEN,
            ));
        }
        self.validate_contacts(&contacts)?;
        self.purchased_domains.lock().push(domain.to_string());
        Ok(format!(
            "mock_{}",
            md5_hex(&format!("{domain}{}", unix_now()))
        ))
    }

    fn suggest(
        &self,
        query: SuggestQuery,
        tlds: Vec<String>,
        limit: Option<i64>,
        filter_type: Option<&str>,
        price_max: Option<i64>,
        price_min: Option<i64>,
    ) -> Result<HashMap<String, SuggestItem>, DomainsError> {
        let query = query.joined("-");
        let tlds = if tlds.is_empty() {
            self.supported_tlds.lock().clone()
        } else {
            tlds
        };
        let limit = limit.unwrap_or(10);
        let mut suggestions = HashMap::new();
        let mut count = 0i64;

        if filter_type.is_none() || filter_type == Some("suggestion") {
            for tld in &tlds {
                if count >= limit {
                    break;
                }
                let tld = tld.trim_start_matches('.');
                let domain = format!("{query}.{tld}");
                suggestions.insert(
                    domain.clone(),
                    SuggestItem {
                        available: self.available(&domain)?,
                        price: None,
                        kind: "suggestion".into(),
                        renewal_price: None,
                        purchase_type: None,
                    },
                );
                count += 1;
            }
        }

        if (filter_type.is_none() || filter_type == Some("premium")) && count < limit {
            let premium = self.premium_domains.lock().clone();
            for (domain, price) in premium {
                if count >= limit {
                    break;
                }
                if price_min.is_some_and(|min| price < min as f64) {
                    continue;
                }
                if price_max.is_some_and(|max| price > max as f64) {
                    continue;
                }
                suggestions.insert(
                    domain.clone(),
                    SuggestItem {
                        available: self.available(&domain)?,
                        price: Some(price),
                        kind: "premium".into(),
                        renewal_price: None,
                        purchase_type: None,
                    },
                );
                count += 1;
            }
        }

        Ok(suggestions)
    }

    fn tlds(&self) -> Result<Vec<String>, DomainsError> {
        Ok(self.supported_tlds.lock().clone())
    }

    fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError> {
        if !self.purchased_domains.lock().iter().any(|d| d == domain) {
            return Err(DomainsError::generic(
                format!("Domain {domain} not found in mock registry"),
                RESPONSE_CODE_NOT_FOUND,
            ));
        }
        let created = OffsetDateTime::now_utc();
        let expires = created.saturating_add(Duration::days(365));
        Ok(RegistrarDomain::new(
            domain,
            Some(created),
            Some(expires),
            Some(false),
            Some(vec!["ns1.example.com".into(), "ns2.example.com".into()]),
        ))
    }

    fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, DomainsError> {
        if !self.purchased_domains.lock().iter().any(|d| d == domain) {
            return Err(DomainsError::generic(
                format!("Domain {domain} not found in mock registry"),
                RESPONSE_CODE_NOT_FOUND,
            ));
        }
        if details.auto_renew.is_none() {
            return Err(DomainsError::generic("Details must include autoRenew", 400));
        }
        Ok(true)
    }

    fn update_nameservers(
        &self,
        _domain: &str,
        nameservers: Vec<String>,
    ) -> Result<NameserverUpdate, DomainsError> {
        Ok(NameserverUpdate {
            successful: true,
            nameservers,
            code: None,
            text: None,
            error: None,
        })
    }

    fn get_price(
        &self,
        domain: &str,
        period_years: i64,
        reg_type: &str,
        ttl: u64,
    ) -> Result<Price, DomainsError> {
        if let Some(cache) = &self.state.cache {
            if let Some(Value::Object(cached)) = cache.load(domain, ttl) {
                if let Some(price) = cached.get("price").and_then(Value::as_f64) {
                    let premium = cached
                        .get("premium")
                        .and_then(Value::as_bool)
                        .unwrap_or(false);
                    return Ok(Price::new(price, premium));
                }
            }
        }

        if let Some(price) = self.premium_domains.lock().get(domain).copied() {
            let result = Price::new(price * period_years as f64, true);
            if let Some(cache) = &self.state.cache {
                cache.save(
                    domain,
                    json!({ "price": result.price, "premium": result.premium }),
                );
            }
            return Ok(result);
        }

        let parts: Vec<&str> = domain.split('.').collect();
        if parts.len() < 2 {
            return Err(DomainsError::price_not_found(
                format!("Invalid domain format: {domain}"),
                RESPONSE_CODE_BAD_REQUEST,
            ));
        }
        let tld = parts[parts.len() - 1];
        if !self.supported_tlds.lock().iter().any(|t| t == tld) {
            return Err(DomainsError::price_not_found(
                format!("TLD .{tld} is not supported"),
                RESPONSE_CODE_BAD_REQUEST,
            ));
        }

        let multiplier = match reg_type {
            Registrar::REG_TYPE_RENEWAL => 1.1,
            Registrar::REG_TYPE_TRADE => 1.2,
            _ => 1.0,
        };
        let price = *self.default_price.lock() * period_years as f64 * multiplier;
        let result = Price::new(price, false);
        if let Some(cache) = &self.state.cache {
            cache.save(
                domain,
                json!({ "price": result.price, "premium": result.premium }),
            );
        }
        Ok(result)
    }

    fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError> {
        if !self.purchased_domains.lock().iter().any(|d| d == domain) {
            return Err(DomainsError::generic(
                format!("Domain {domain} not found in mock registry"),
                RESPONSE_CODE_NOT_FOUND,
            ));
        }
        let info = self.get_domain(domain)?;
        let new_expiry = match info.expires_at {
            Some(current) => current.saturating_add(Duration::days(365 * period_years.max(0))),
            None => {
                OffsetDateTime::now_utc().saturating_add(Duration::days(365 * period_years.max(0)))
            }
        };
        Ok(Renewal::new(
            Some(format!(
                "mock_order_{}",
                md5_hex(&format!("{domain}{}", unix_now()))
            )),
            Some(new_expiry),
        ))
    }

    fn transfer(
        &self,
        domain: &str,
        _auth_code: &str,
        _purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        if self.purchased_domains.lock().iter().any(|d| d == domain) {
            return Err(DomainsError::domain_taken(
                format!("Domain {domain} is already in this account"),
                RESPONSE_CODE_DOMAIN_TAKEN,
            ));
        }
        self.transferred_domains.lock().push(domain.to_string());
        self.purchased_domains.lock().push(domain.to_string());
        Ok(format!(
            "mock_transfer_{}",
            md5_hex(&format!("{domain}{}", unix_now()))
        ))
    }

    fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError> {
        if !self.purchased_domains.lock().iter().any(|d| d == domain) {
            return Err(DomainsError::generic(
                format!("Domain {domain} not found in mock registry"),
                RESPONSE_CODE_NOT_FOUND,
            ));
        }
        Ok(format!("mock_{}", &md5_hex(domain)[..8]))
    }

    fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError> {
        if self.transferred_domains.lock().iter().any(|d| d == domain) {
            Ok(TransferStatus::new(
                TransferStatusEnum::PendingRegistry,
                Some("Transfer in progress".into()),
                Some(OffsetDateTime::now_utc()),
            ))
        } else if self.purchased_domains.lock().iter().any(|d| d == domain) {
            Ok(TransferStatus::new(
                TransferStatusEnum::Completed,
                Some("Domain already exists in mock account".into()),
                Some(OffsetDateTime::now_utc()),
            ))
        } else {
            Ok(TransferStatus::new(
                TransferStatusEnum::Transferrable,
                None,
                None,
            ))
        }
    }

    fn cancel_purchase(&self) -> Result<bool, DomainsError> {
        Ok(true)
    }

    fn set_default_nameservers(&mut self, nameservers: Vec<String>) {
        self.state.default_nameservers = nameservers;
    }

    fn set_cache(&mut self, cache: Option<Cache>) {
        self.state.cache = cache;
    }

    fn set_connect_timeout(&mut self, connect_timeout: u64) {
        self.state.connect_timeout = connect_timeout;
    }

    fn set_timeout(&mut self, timeout: u64) {
        self.state.timeout = timeout;
    }
}
