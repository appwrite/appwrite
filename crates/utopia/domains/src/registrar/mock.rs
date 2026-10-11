//! An in-memory registrar for tests.

use std::sync::{Mutex, MutexGuard};

use indexmap::IndexMap;
use php_std::filter::{self, FILTER_VALIDATE_EMAIL, Options};
use php_std::value::loose_str_eq;
use serde_json::json;

use super::{
    Adapter, Availability, Config, Domain, NameserverUpdate, Price, Purchase, RegType, Renewal, SuggestOptions,
    Suggestion, SuggestionKind, Suggestions, TransferState, TransferStatus, UpdateDetails, bare_tld, datetime, md5,
};
use crate::Error;

const BAD_REQUEST: i64 = 400;
const NOT_FOUND: i64 = 404;
const INVALID_CONTACT: i64 = 465;
const DOMAIN_TAKEN: i64 = 485;

#[derive(Debug)]
struct State {
    taken: Vec<String>,
    purchased: Vec<String>,
    transferred: Vec<String>,
    tlds: Vec<String>,
    premium: IndexMap<String, f64>,
}

/// A registrar that keeps its registry in memory: `google.com`, `facebook.com`
/// and `amazon.com` are taken, `com`, `net`, `org`, `io`, `dev` and `app` are
/// sold at the default price, a few domains are premium, and purchases and
/// transfers are remembered.
///
/// PHP: `Registrar\Adapter\Mock`.
///
/// ```
/// use utopia_domains::registrar::{Contact, Mock, Purchase, Registrar};
///
/// let contact = Contact {
///     firstname: "Ada".into(), lastname: "Lovelace".into(), phone: "+18031234567".into(),
///     email: "ada@example.com".into(), address1: "1 Main St".into(), city: "London".into(),
///     state: "LDN".into(), country: "GB".into(), postalcode: "N1".into(), ..Contact::default()
/// };
/// let registrar = Registrar::new(Mock::new());
/// tokio::runtime::Runtime::new()?.block_on(async {
///     let order = registrar.purchase(&Purchase::new("my-app.com", contact)).await?;
///     assert!(order.starts_with("mock_"));
///     assert_eq!(registrar.adapter().purchased(), ["my-app.com"]);
///     Ok::<_, utopia_domains::Error>(())
/// })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Domains\Registrar;
/// use Utopia\Domains\Registrar\Adapter\Mock;
/// use Utopia\Domains\Registrar\Contact;
///
/// $contact = new Contact('Ada', 'Lovelace', '+18031234567', 'ada@example.com', '1 Main St', '', '', 'London', 'LDN', 'GB', 'N1', '');
/// $mock = new Mock();
/// $order = (new Registrar($mock))->purchase('my-app.com', $contact);
/// var_dump(str_starts_with($order, 'mock_')); // bool(true)
/// print_r($mock->getPurchasedDomains());       // Array ( [0] => my-app.com )
/// ```
#[derive(Debug)]
pub struct Mock {
    state: Mutex<State>,
    default_price: f64,
}

impl Default for Mock {
    /// The default registry ([`Mock::new`]).
    fn default() -> Self {
        Self::new()
    }
}

fn contains(list: &[String], value: &str) -> bool {
    list.iter().any(|v| loose_str_eq(v.as_bytes(), value.as_bytes()))
}

fn not_found(domain: &str) -> Error {
    Error::domains(format!("Domain {domain} not found in mock registry"), NOT_FOUND)
}

impl Mock {
    /// The default registry, at 12.99 per year.
    pub fn new() -> Self {
        Self::with(Vec::new(), Vec::new(), 12.99)
    }

    /// A registry with more taken domains, other TLDs (when not empty) and
    /// another default price.
    pub fn with(taken: Vec<String>, tlds: Vec<String>, default_price: f64) -> Self {
        let mut state = State {
            taken: ["google.com", "facebook.com", "amazon.com"].map(String::from).to_vec(),
            purchased: Vec::new(),
            transferred: Vec::new(),
            tlds: ["com", "net", "org", "io", "dev", "app"].map(String::from).to_vec(),
            premium: IndexMap::from([
                ("premium.com".to_owned(), 5000.00),
                ("business.com".to_owned(), 10000.00),
                ("shop.net".to_owned(), 2500.00),
            ]),
        };
        state.taken.extend(taken);
        if !tlds.is_empty() {
            state.tlds = tlds;
        }
        Self { state: Mutex::new(state), default_price }
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Domains bought or transferred in.
    ///
    /// PHP: `getPurchasedDomains()`.
    pub fn purchased(&self) -> Vec<String> {
        self.state().purchased.clone()
    }

    /// Domains transferred in.
    ///
    /// PHP: `getTransferredDomains()`.
    pub fn transferred(&self) -> Vec<String> {
        self.state().transferred.clone()
    }

    /// Forgets purchases and transfers.
    ///
    /// PHP: `reset()`.
    pub fn reset(&self) {
        let mut state = self.state();
        state.purchased.clear();
        state.transferred.clear();
    }

    /// Marks a domain as taken, so it is not available.
    ///
    /// PHP: `addTakenDomain()`.
    pub fn add_taken(&self, domain: &str) {
        let mut state = self.state();
        if !contains(&state.taken, domain) {
            state.taken.push(domain.to_owned());
        }
    }

    /// Sells a domain at a premium `price`.
    ///
    /// PHP: `addPremiumDomain()`.
    pub fn add_premium(&self, domain: &str, price: f64) {
        self.state().premium.insert(domain.to_owned(), price);
    }

    fn is_available(state: &State, domain: &str) -> bool {
        !contains(&state.taken, domain) && !contains(&state.purchased, domain)
    }

    fn validate_contacts(purchase: &Purchase) -> Result<(), Error> {
        for (_, contact) in purchase.contacts.iter() {
            let fields = contact.fields();
            let value = |name: &str| fields.iter().find(|(n, _)| *n == name).map(|(_, v)| v.as_ref()).unwrap_or("");
            for field in
                ["firstname", "lastname", "email", "phone", "address1", "city", "state", "postalcode", "country"]
            {
                let v = value(field);
                if v.is_empty() || v == "0" {
                    return Err(Error::InvalidContact {
                        message: format!("Invalid contact: missing required field '{field}'"),
                        code: INVALID_CONTACT,
                    });
                }
            }
            let email = filter::filter_var(
                &filter::Value::Str(value("email").as_bytes().to_vec()),
                FILTER_VALIDATE_EMAIL,
                &Options::Flags(0),
            );
            let valid = matches!(&email, Ok(f) if php_std::value::truthy(&filtered_json(&f.value)));
            if !valid {
                return Err(Error::InvalidContact {
                    message: "Invalid contact: invalid email format".into(),
                    code: INVALID_CONTACT,
                });
            }
        }
        Ok(())
    }

    fn order_id(prefix: &str, domain: &str) -> String {
        format!("{prefix}{}", md5(&format!("{domain}{}", php_std::datetime::time())))
    }
}

/// Whether a filter result counts as true.
///
/// PHP: truthiness of a `filter_var()` result.
fn filtered_json(value: &filter::Value) -> serde_json::Value {
    match value {
        filter::Value::Str(s) => serde_json::Value::String(String::from_utf8_lossy(s).into_owned()),
        filter::Value::Bool(b) => serde_json::Value::Bool(*b),
        _ => serde_json::Value::Null,
    }
}

impl Adapter for Mock {
    fn name(&self) -> &str {
        "mock"
    }

    async fn available(&self, _config: &Config, domains: &[String]) -> Result<Availability, Error> {
        let state = self.state();
        let mut availability = Availability::new();
        for domain in domains {
            if !availability.contains_key(domain) {
                availability.insert(domain.clone(), Self::is_available(&state, domain));
            }
        }
        Ok(availability)
    }

    async fn purchase(&self, _config: &Config, purchase: &Purchase) -> Result<String, Error> {
        let domain = &purchase.domain;
        if !Self::is_available(&self.state(), domain) {
            return Err(Error::DomainTaken {
                message: format!("Domain {domain} is not available for registration"),
                code: DOMAIN_TAKEN,
            });
        }
        Self::validate_contacts(purchase)?;
        self.state().purchased.push(domain.clone());
        Ok(Self::order_id("mock_", domain))
    }

    async fn suggest(
        &self,
        _config: &Config,
        query: &[String],
        options: &SuggestOptions,
    ) -> Result<Suggestions, Error> {
        let state = self.state();
        let query = query.join("-");
        let tlds = if options.tlds.is_empty() { &state.tlds } else { &options.tlds };
        let limit = options.limit.unwrap_or(10);
        let mut suggestions = Suggestions::new();
        let mut count = 0;
        if options.filter != Some(SuggestionKind::Premium) {
            for tld in tlds {
                if count >= limit {
                    break;
                }
                let domain = format!("{query}.{}", bare_tld(tld));
                let available = Self::is_available(&state, &domain);
                suggestions.insert(
                    domain,
                    Suggestion {
                        available,
                        price: None,
                        kind: SuggestionKind::Suggestion,
                        renewal_price: None,
                        purchase_type: None,
                    },
                );
                count += 1;
            }
        }
        if options.filter != Some(SuggestionKind::Suggestion) && count < limit {
            for (domain, &price) in &state.premium {
                if count >= limit {
                    break;
                }
                if options.price_min.is_some_and(|min| price < min as f64) {
                    continue;
                }
                if options.price_max.is_some_and(|max| price > max as f64) {
                    continue;
                }
                let available = Self::is_available(&state, domain);
                suggestions.insert(
                    domain.clone(),
                    Suggestion {
                        available,
                        price: Some(price),
                        kind: SuggestionKind::Premium,
                        renewal_price: None,
                        purchase_type: None,
                    },
                );
                count += 1;
            }
        }
        Ok(suggestions)
    }

    async fn tlds(&self, _config: &Config) -> Result<Vec<String>, Error> {
        Ok(self.state().tlds.clone())
    }

    async fn domain(&self, _config: &Config, domain: &str) -> Result<Domain, Error> {
        if !contains(&self.state().purchased, domain) {
            return Err(not_found(domain));
        }
        Ok(Domain {
            domain: domain.to_owned(),
            created_at: Some(datetime("now")?),
            expires_at: Some(datetime("+1 year")?),
            auto_renew: Some(false),
            nameservers: Some(vec!["ns1.example.com".into(), "ns2.example.com".into()]),
        })
    }

    async fn update_domain(&self, _config: &Config, domain: &str, details: &UpdateDetails) -> Result<bool, Error> {
        if !contains(&self.state().purchased, domain) {
            return Err(not_found(domain));
        }
        if details.auto_renew.is_none() {
            return Err(Error::domains("Details must include autoRenew", BAD_REQUEST));
        }
        Ok(true)
    }

    async fn update_nameservers(
        &self,
        _config: &Config,
        _domain: &str,
        nameservers: &[String],
    ) -> Result<NameserverUpdate, Error> {
        Ok(NameserverUpdate {
            successful: true,
            nameservers: Some(nameservers.to_vec()),
            ..NameserverUpdate::default()
        })
    }

    async fn price(
        &self,
        config: &Config,
        domain: &str,
        period_years: i64,
        reg_type: RegType,
        ttl: i64,
    ) -> Result<Price, Error> {
        if let Some(cache) = &config.cache
            && let Some(cached) = cache.load(domain, ttl).await
            && let Some(price) = crate::php::at(&cached, "price")
        {
            let premium = crate::php::truthy(crate::php::at(&cached, "premium"));
            return Ok(Price { price: crate::php::float(price), premium });
        }
        let premium = self.state().premium.get(domain).copied();
        let result = if let Some(price) = premium {
            Price { price: price * period_years as f64, premium: true }
        } else {
            let parts: Vec<&str> = domain.split('.').collect();
            if parts.len() < 2 {
                return Err(Error::PriceNotFound {
                    message: format!("Invalid domain format: {domain}"),
                    code: BAD_REQUEST,
                });
            }
            let tld = parts[parts.len() - 1];
            if !contains(&self.state().tlds, tld) {
                return Err(Error::PriceNotFound {
                    message: format!("TLD .{tld} is not supported"),
                    code: BAD_REQUEST,
                });
            }
            let multiplier = match reg_type {
                RegType::Renewal => 1.1,
                RegType::Trade => 1.2,
                RegType::New | RegType::Transfer => 1.0,
            };
            Price { price: self.default_price * period_years as f64 * multiplier, premium: false }
        };
        if let Some(cache) = &config.cache {
            cache.save(domain, &json!({"price": result.price, "premium": result.premium})).await;
        }
        Ok(result)
    }

    async fn renew(&self, config: &Config, domain: &str, period_years: i64) -> Result<Renewal, Error> {
        if !contains(&self.state().purchased, domain) {
            return Err(not_found(domain));
        }
        let info = self.domain(config, domain).await?;
        let modifier = format!("+{period_years} years");
        let expires_at = match info.expires_at {
            Some(expiry) => {
                expiry.modified(modifier.as_bytes()).map_err(|e| Error::DateMalformed(e.message().into_owned()))?
            }
            None => datetime(&modifier)?,
        };
        Ok(Renewal { order_id: Some(Self::order_id("mock_order_", domain)), expires_at: Some(expires_at) })
    }

    async fn transfer(
        &self,
        _config: &Config,
        domain: &str,
        _auth_code: &str,
        _price: Option<f64>,
    ) -> Result<String, Error> {
        let mut state = self.state();
        if contains(&state.purchased, domain) {
            return Err(Error::DomainTaken {
                message: format!("Domain {domain} is already in this account"),
                code: DOMAIN_TAKEN,
            });
        }
        state.transferred.push(domain.to_owned());
        state.purchased.push(domain.to_owned());
        Ok(Self::order_id("mock_transfer_", domain))
    }

    async fn auth_code(&self, _config: &Config, domain: &str) -> Result<String, Error> {
        if !contains(&self.state().purchased, domain) {
            return Err(not_found(domain));
        }
        Ok(format!("mock_{}", &md5(domain)[..8]))
    }

    async fn transfer_status(&self, _config: &Config, domain: &str) -> Result<TransferStatus, Error> {
        let (transferred, purchased) = {
            let state = self.state();
            (contains(&state.transferred, domain), contains(&state.purchased, domain))
        };
        Ok(if transferred {
            TransferStatus {
                status: TransferState::PendingRegistry,
                reason: Some("Transfer in progress".into()),
                timestamp: Some(datetime("now")?),
            }
        } else if purchased {
            TransferStatus {
                status: TransferState::Completed,
                reason: Some("Domain already exists in mock account".into()),
                timestamp: Some(datetime("now")?),
            }
        } else {
            TransferStatus { status: TransferState::Transferrable, reason: None, timestamp: None }
        })
    }

    async fn cancel_purchase(&self, _config: &Config) -> Result<bool, Error> {
        Ok(true)
    }
}
