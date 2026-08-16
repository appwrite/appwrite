use std::collections::HashMap;

use bytes::Bytes;
use http::{Method, Request};
use serde_json::{json, Value};
use time::format_description::well_known::Rfc3339;
use time::{OffsetDateTime, PrimitiveDateTime};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};

use crate::cache::Cache;
use crate::error::DomainsError;
use crate::registrar::adapter::{Adapter, AdapterState};
use crate::registrar::{
    Contact, Contacts, NameserverUpdate, Price, Registrar, RegistrarDomain, Renewal, SuggestItem,
    SuggestQuery, TransferStatus, TransferStatusEnum, UpdateDetails,
};

/// Name.com registrar adapter (PHP `Adapter\NameCom`).
#[derive(Debug)]
pub struct NameCom {
    username: String,
    token: String,
    endpoint: String,
    state: AdapterState,
}

impl NameCom {
    /// PHP `ERROR_NOT_FOUND`.
    pub const ERROR_NOT_FOUND: &'static str = "Not Found";
    /// PHP `ERROR_DOMAIN_TAKEN`.
    pub const ERROR_DOMAIN_TAKEN: &'static str = "Domain is not available";
    /// PHP `ERROR_INVALID_AUTH_CODE`.
    pub const ERROR_INVALID_AUTH_CODE: &'static str = "we were unable to get authoritative domain information from the registry. this usually means that the domain name or auth code provided was not correct.";
    /// PHP `ERROR_INVALID_CONTACT`.
    pub const ERROR_INVALID_CONTACT: &'static str = "invalid value for";
    /// PHP `ERROR_INVALID_DOMAIN`.
    pub const ERROR_INVALID_DOMAIN: &'static str = "Invalid Domain Name";
    /// PHP `ERROR_INVALID_DOMAINS`.
    pub const ERROR_INVALID_DOMAINS: &'static str = "None of the submitted domains are valid";
    /// PHP `ERROR_INVALID_YEARS`.
    pub const ERROR_INVALID_YEARS: &'static str = "Invalid value for years";
    /// PHP `ERROR_UNSUPPORTED_TLD`.
    pub const ERROR_UNSUPPORTED_TLD: &'static str = "unsupported tld";
    /// PHP `ERROR_TLD_NOT_SUPPORTED`.
    pub const ERROR_TLD_NOT_SUPPORTED: &'static str = "TLD not supported";
    /// PHP `ERROR_UNSUPPORTED_TRANSFER`.
    pub const ERROR_UNSUPPORTED_TRANSFER: &'static str = "do not support transfers for";
    /// PHP `ERROR_UNAUTHORIZED`.
    pub const ERROR_UNAUTHORIZED: &'static str = "Unauthorized";
    /// PHP `ERROR_RATE_LIMIT_EXCEEDED`.
    pub const ERROR_RATE_LIMIT_EXCEEDED: &'static str = "Rate Limit Exceeded";

    /// PHP `CONTACT_TYPE_*`.
    pub const CONTACT_TYPE_REGISTRANT: &'static str = "registrant";
    /// Admin contact type.
    pub const CONTACT_TYPE_ADMIN: &'static str = "admin";
    /// Tech contact type.
    pub const CONTACT_TYPE_TECH: &'static str = "tech";
    /// Billing contact type.
    pub const CONTACT_TYPE_BILLING: &'static str = "billing";
    /// Owner contact type.
    pub const CONTACT_TYPE_OWNER: &'static str = "owner";

    /// PHP constructor. `http://` is rewritten to `https://` except loopback,
    /// which is kept so wiremock tests can speak plain HTTP.
    pub fn new(
        username: impl Into<String>,
        token: impl Into<String>,
        endpoint: impl Into<String>,
    ) -> Self {
        Self {
            username: username.into(),
            token: token.into(),
            endpoint: normalize_endpoint(&endpoint.into()),
            state: AdapterState::default(),
        }
    }

    fn send(
        &self,
        method: Method,
        path: &str,
        data: Option<&Value>,
    ) -> Result<Value, DomainsError> {
        let url = format!("{}{path}", self.endpoint);
        let client = Client::new(curl::Client::new())
            .with_timeout(self.state.timeout as f64)
            .and_then(|client| client.with_connect_timeout(self.state.connect_timeout as f64))
            .map_err(|e| {
                DomainsError::generic(format!("Failed to send request to Name.com: {e}"), 0)
            })?
            .with_basic_auth(&self.username, &self.token);

        let payload = if matches!(method, Method::POST | Method::PUT | Method::PATCH) {
            if let Some(data) = data {
                serde_json::to_vec(data).map_err(|e| {
                    DomainsError::generic(format!("Failed to encode request data to JSON: {e}"), 0)
                })?
            } else {
                Vec::new()
            }
        } else {
            Vec::new()
        };
        let request = Request::builder()
            .method(method)
            .uri(&url)
            .header("Content-Type", "application/json")
            .body(Bytes::from(payload))
            .map_err(|e| {
                DomainsError::generic(format!("Failed to send request to Name.com: {e}"), 0)
            })?;
        let response = client.send_request(request).map_err(|e| {
            DomainsError::generic(format!("Failed to send request to Name.com: {e}"), 0)
        })?;
        let http_code = i64::from(response.status().as_u16());
        let text = String::from_utf8_lossy(response.body()).into_owned();

        let parsed: Option<Value> = if text.is_empty() || text == "null" {
            Some(Value::Null)
        } else {
            serde_json::from_str(&text).ok()
        };
        if parsed.is_none() && text != "null" && !text.is_empty() {
            return Err(DomainsError::generic(
                "Failed to parse response from Name.com: Invalid JSON",
                0,
            ));
        }
        let response_json = parsed.unwrap_or(Value::Null);

        if http_code >= 400 {
            let mut message = json_string(response_json.get("message").unwrap_or(&Value::Null));
            if message.is_empty() {
                message = "Unknown error".into();
            }
            if let Some(details) = response_json.get("details") {
                let details = json_string(details);
                if !details.is_empty() && details != "null" {
                    message = format!("{message}({details})");
                }
            }
            if http_code == 429
                || message
                    .to_ascii_lowercase()
                    .contains(&Self::ERROR_RATE_LIMIT_EXCEEDED.to_ascii_lowercase())
            {
                return Err(DomainsError::rate_limit(
                    format!("Rate limit exceeded: {message}"),
                    429,
                ));
            }
            return Err(DomainsError::generic(message, http_code));
        }

        if response_json.is_null() {
            Ok(json!({}))
        } else {
            Ok(response_json)
        }
    }

    fn match_error(err: &DomainsError) -> Option<&'static str> {
        let error_lower = err.message().to_ascii_lowercase();
        let code = err.code();
        for (message, expected) in ERROR_MAP {
            if let Some(expected) = expected {
                if code != *expected {
                    continue;
                }
            }
            if error_lower.contains(&message.to_ascii_lowercase()) {
                return Some(message);
            }
        }
        None
    }

    fn sanitize_contacts(contacts: &Contacts) -> Result<Value, DomainsError> {
        if contacts.len() == 0 {
            return Err(DomainsError::invalid_contact(
                "Contacts must be a non-empty array",
                400,
            ));
        }
        let default = contacts.first().cloned().ok_or_else(|| {
            DomainsError::invalid_contact("Contacts must be a non-empty array", 400)
        })?;

        let registrant = contacts
            .get(Self::CONTACT_TYPE_REGISTRANT)
            .or_else(|| contacts.get(Self::CONTACT_TYPE_OWNER))
            .or_else(|| contacts.get("0"))
            .cloned()
            .unwrap_or_else(|| default.clone());
        let admin = contacts
            .get(Self::CONTACT_TYPE_ADMIN)
            .or_else(|| contacts.get("1"))
            .cloned()
            .unwrap_or_else(|| default.clone());
        let tech = contacts
            .get(Self::CONTACT_TYPE_TECH)
            .or_else(|| contacts.get("2"))
            .cloned()
            .unwrap_or_else(|| default.clone());
        let billing = contacts
            .get(Self::CONTACT_TYPE_BILLING)
            .or_else(|| contacts.get("3"))
            .cloned()
            .unwrap_or(default);

        Ok(json!({
            Self::CONTACT_TYPE_REGISTRANT: format_contact(&registrant),
            Self::CONTACT_TYPE_ADMIN: format_contact(&admin),
            Self::CONTACT_TYPE_TECH: format_contact(&tech),
            Self::CONTACT_TYPE_BILLING: format_contact(&billing),
        }))
    }

    fn map_transfer_status(status: &str) -> TransferStatusEnum {
        match status.to_ascii_lowercase().as_str() {
            "completed" => TransferStatusEnum::Completed,
            "canceled" | "canceled_pending_refund" | "rejected" => TransferStatusEnum::Cancelled,
            "pending" | "pending_transfer" | "submitting_transfer" => {
                TransferStatusEnum::PendingRegistry
            }
            "pending_insert" => TransferStatusEnum::PendingAdmin,
            "pending_new_auth_code" | "pending_unlock" => TransferStatusEnum::PendingOwner,
            _ => TransferStatusEnum::NotTransferrable,
        }
    }
}

const ERROR_MAP: &[(&str, Option<i64>)] = &[
    (NameCom::ERROR_NOT_FOUND, Some(404)),
    (NameCom::ERROR_DOMAIN_TAKEN, None),
    (NameCom::ERROR_INVALID_AUTH_CODE, None),
    (NameCom::ERROR_INVALID_YEARS, Some(400)),
    (NameCom::ERROR_INVALID_CONTACT, None),
    (NameCom::ERROR_INVALID_DOMAIN, None),
    (NameCom::ERROR_INVALID_DOMAINS, None),
    (NameCom::ERROR_UNSUPPORTED_TLD, Some(422)),
    (NameCom::ERROR_TLD_NOT_SUPPORTED, None),
    (NameCom::ERROR_UNSUPPORTED_TRANSFER, Some(400)),
    (NameCom::ERROR_UNAUTHORIZED, Some(401)),
    (NameCom::ERROR_RATE_LIMIT_EXCEEDED, Some(429)),
];

fn format_contact(contact: &Contact) -> Value {
    let data = contact.to_array();
    json!({
        "firstName": data.get("firstname").cloned().unwrap_or_default(),
        "lastName": data.get("lastname").cloned().unwrap_or_default(),
        "companyName": data.get("org").cloned().unwrap_or_default(),
        "email": data.get("email").cloned().unwrap_or_default(),
        "phone": data.get("phone").cloned().unwrap_or_default(),
        "address1": data.get("address1").cloned().unwrap_or_default(),
        "address2": data.get("address2").cloned().unwrap_or_default(),
        "city": data.get("city").cloned().unwrap_or_default(),
        "state": data.get("state").cloned().unwrap_or_default(),
        "zip": data.get("postalcode").cloned().unwrap_or_default(),
        "country": data.get("country").cloned().unwrap_or_default(),
    })
}

pub(crate) fn json_string(value: &Value) -> String {
    match value {
        Value::String(s) => s.clone(),
        Value::Number(n) => n.to_string(),
        Value::Bool(b) => b.to_string(),
        Value::Null => String::new(),
        other => other.to_string(),
    }
}

pub(crate) fn json_f64(value: &Value) -> Option<f64> {
    match value {
        Value::Number(n) => n.as_f64(),
        Value::String(s) => s.parse().ok(),
        _ => None,
    }
}

pub(crate) fn parse_datetime(raw: &str) -> Option<OffsetDateTime> {
    if let Ok(dt) = OffsetDateTime::parse(raw, &Rfc3339) {
        return Some(dt);
    }
    let formats = [
        time::macros::format_description!("[year]-[month]-[day]T[hour]:[minute]:[second]"),
        time::macros::format_description!("[year]-[month]-[day] [hour]:[minute]:[second]"),
        time::macros::format_description!("[year]-[month]-[day]"),
    ];
    for format in formats {
        if let Ok(dt) = PrimitiveDateTime::parse(raw, &format) {
            return Some(dt.assume_utc());
        }
        if let Ok(dt) = OffsetDateTime::parse(raw, &format) {
            return Some(dt);
        }
    }
    None
}

pub(crate) fn normalize_endpoint(endpoint: &str) -> String {
    if endpoint.starts_with("http://127.0.0.1")
        || endpoint.starts_with("http://localhost")
        || endpoint.starts_with("http://[::1]")
    {
        return endpoint.trim_end_matches('/').to_string();
    }
    if let Some(rest) = endpoint.strip_prefix("http://") {
        format!("https://{}", rest.trim_end_matches('/'))
    } else if let Some(rest) = endpoint.strip_prefix("https://") {
        format!("https://{}", rest.trim_end_matches('/'))
    } else {
        format!("https://{}", endpoint.trim_end_matches('/'))
    }
}

impl Adapter for NameCom {
    fn get_name(&self) -> String {
        "namecom".into()
    }

    fn available(&self, domain: &str) -> Result<bool, DomainsError> {
        let result = match self.send(
            Method::POST,
            "/core/v1/domains:checkAvailability",
            Some(&json!({ "domainNames": [domain] })),
        ) {
            Ok(v) => v,
            Err(e) => {
                if Self::match_error(&e) == Some(Self::ERROR_INVALID_DOMAINS) {
                    return Ok(false);
                }
                return Err(e);
            }
        };
        Ok(result
            .get("results")
            .and_then(Value::as_array)
            .and_then(|a| a.first())
            .and_then(|r| r.get("purchasable"))
            .and_then(Value::as_bool)
            .unwrap_or(false))
    }

    fn update_nameservers(
        &self,
        domain: &str,
        nameservers: Vec<String>,
    ) -> Result<NameserverUpdate, DomainsError> {
        match self.send(
            Method::POST,
            &format!("/core/v1/domains/{domain}:setNameservers"),
            Some(&json!({ "nameservers": nameservers })),
        ) {
            Ok(result) => {
                let returned = result
                    .get("nameservers")
                    .and_then(Value::as_array)
                    .map_or_else(
                        || nameservers.clone(),
                        |arr| {
                            arr.iter()
                                .filter_map(|v| v.as_str().map(str::to_string))
                                .collect()
                        },
                    );
                Ok(NameserverUpdate {
                    successful: true,
                    nameservers: returned,
                    code: None,
                    text: None,
                    error: None,
                })
            }
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => Ok(NameserverUpdate {
                successful: false,
                nameservers,
                code: None,
                text: None,
                error: Some(e.message()),
            }),
        }
    }

    fn purchase(
        &self,
        domain: &str,
        contacts: Contacts,
        period_years: i64,
        nameservers: Vec<String>,
        autorenew_enabled: bool,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        let nameservers = self.state.nameservers_or_default(nameservers);
        let contact_data = Self::sanitize_contacts(&contacts)?;
        let mut data = json!({
            "domain": {
                "domainName": domain,
                "nameservers": nameservers,
                "contacts": contact_data,
                "autorenewEnabled": autorenew_enabled,
            },
            "years": period_years,
        });
        if let Some(price) = purchase_price {
            data["purchasePrice"] = json!(price);
        }
        match self.send(Method::POST, "/core/v1/domains", Some(&data)) {
            Ok(result) => Ok(json_string(result.get("order").unwrap_or(&Value::Null))),
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => {
                let message = format!("Failed to purchase domain: {}", e.message());
                let code = e.code();
                match Self::match_error(&e) {
                    Some(Self::ERROR_UNAUTHORIZED) => Err(DomainsError::auth(message, code)),
                    Some(Self::ERROR_DOMAIN_TAKEN) => {
                        Err(DomainsError::domain_taken(message, code))
                    }
                    Some(Self::ERROR_INVALID_CONTACT) => {
                        Err(DomainsError::invalid_contact(message, code))
                    }
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_UNSUPPORTED_TRANSFER) => {
                        Err(DomainsError::unsupported_tld(message, code))
                    }
                    _ => Err(DomainsError::generic(message, code)),
                }
            }
        }
    }

    fn transfer(
        &self,
        domain: &str,
        auth_code: &str,
        purchase_price: Option<f64>,
    ) -> Result<String, DomainsError> {
        let mut data = json!({
            "domainName": domain,
            "authCode": auth_code,
        });
        if let Some(price) = purchase_price {
            data["purchasePrice"] = json!(price);
        }
        match self.send(Method::POST, "/core/v1/transfers", Some(&data)) {
            Ok(result) => Ok(json_string(result.get("order").unwrap_or(&Value::Null))),
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => {
                let message = format!("Failed to transfer domain: {}", e.message());
                let code = e.code();
                match Self::match_error(&e) {
                    Some(Self::ERROR_UNAUTHORIZED) => Err(DomainsError::auth(message, code)),
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_UNSUPPORTED_TRANSFER) => {
                        Err(DomainsError::unsupported_tld(message, code))
                    }
                    Some(Self::ERROR_INVALID_AUTH_CODE) => {
                        Err(DomainsError::invalid_auth_code(message, code))
                    }
                    Some(Self::ERROR_DOMAIN_TAKEN) => {
                        Err(DomainsError::domain_taken(message, code))
                    }
                    _ => Err(DomainsError::generic(message, code)),
                }
            }
        }
    }

    fn cancel_purchase(&self) -> Result<bool, DomainsError> {
        Ok(true)
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
        let query = query.joined(" ");
        let mut data = json!({ "keyword": query });
        if !tlds.is_empty() {
            data["tldFilter"] = json!(tlds
                .iter()
                .map(|t| t.trim_start_matches('.'))
                .collect::<Vec<_>>());
        }
        if let Some(limit) = limit {
            data["limit"] = json!(limit);
        }
        let result = self.send(Method::POST, "/core/v1/domains:search", Some(&data))?;
        let mut items = HashMap::new();
        if let Some(results) = result.get("results").and_then(Value::as_array) {
            for domain_result in results {
                let Some(domain) = domain_result.get("domainName").and_then(Value::as_str) else {
                    continue;
                };
                let purchasable = domain_result
                    .get("purchasable")
                    .and_then(Value::as_bool)
                    .unwrap_or(false);
                let price = domain_result.get("purchasePrice").and_then(json_f64);
                let renewal_price = domain_result.get("renewalPrice").and_then(json_f64);
                let purchase_type = domain_result
                    .get("purchaseType")
                    .and_then(Value::as_str)
                    .unwrap_or("registration")
                    .to_string();
                let is_premium = domain_result
                    .get("premium")
                    .and_then(Value::as_bool)
                    .unwrap_or(false)
                    || (!purchase_type.is_empty() && purchase_type != "registration");
                if let Some(price) = price {
                    if price_min.is_some_and(|min| price < min as f64) {
                        continue;
                    }
                    if price_max.is_some_and(|max| price > max as f64) {
                        continue;
                    }
                }
                if filter_type == Some("premium") && !is_premium {
                    continue;
                }
                if filter_type == Some("suggestion") && is_premium {
                    continue;
                }
                items.insert(
                    domain.to_string(),
                    SuggestItem {
                        available: purchasable,
                        price,
                        kind: if is_premium {
                            "premium".into()
                        } else {
                            "suggestion".into()
                        },
                        renewal_price,
                        purchase_type: Some(purchase_type),
                    },
                );
                if limit.is_some_and(|l| items.len() as i64 >= l) {
                    break;
                }
            }
        }
        Ok(items)
    }

    fn get_price(
        &self,
        domain: &str,
        period_years: i64,
        reg_type: &str,
        ttl: u64,
    ) -> Result<Price, DomainsError> {
        let cache_key = format!("{domain}_{period_years}");
        if let Some(cache) = &self.state.cache {
            if let Some(Value::Object(cached)) = cache.load(&cache_key, ttl) {
                if let Some(Value::Object(entry)) = cached.get(reg_type) {
                    if matches!(entry.get("price"), None | Some(Value::Null)) {
                        return Err(DomainsError::price_not_found(
                            format!("Price not found for domain: {domain}"),
                            400,
                        ));
                    }
                    let price = entry.get("price").and_then(json_f64).unwrap_or(0.0);
                    let premium = entry
                        .get("premium")
                        .and_then(Value::as_bool)
                        .unwrap_or(false);
                    return Ok(Price::new(price, premium));
                }
            }
        }

        let result = match self.send(
            Method::GET,
            &format!("/core/v1/domains/{domain}:getPricing?years={period_years}"),
            None,
        ) {
            Ok(v) => v,
            Err(e)
                if matches!(
                    e,
                    DomainsError::PriceNotFound { .. } | DomainsError::RateLimit { .. }
                ) =>
            {
                return Err(e);
            }
            Err(e) => {
                let message = format!("Failed to get price for domain: {domain} - {}", e.message());
                let code = e.code();
                return match Self::match_error(&e) {
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_TLD_NOT_SUPPORTED) => {
                        Err(DomainsError::unsupported_tld(message, code))
                    }
                    Some(Self::ERROR_NOT_FOUND | Self::ERROR_INVALID_DOMAIN) => {
                        Err(DomainsError::price_not_found(message, code))
                    }
                    Some(Self::ERROR_INVALID_YEARS) => {
                        Err(DomainsError::invalid_period(message, code))
                    }
                    _ => Err(DomainsError::generic(message, code)),
                };
            }
        };

        let mut is_premium = result.get("premium").is_some_and(|v| match v {
            Value::Bool(b) => *b,
            Value::Number(n) => n.as_f64().unwrap_or(0.0) != 0.0,
            Value::String(s) => !s.is_empty() && s != "0",
            _ => false,
        });

        let mut price_map: HashMap<&str, Option<f64>> = HashMap::from([
            (
                Registrar::REG_TYPE_NEW,
                result.get("purchasePrice").and_then(json_f64),
            ),
            (
                Registrar::REG_TYPE_RENEWAL,
                result.get("renewalPrice").and_then(json_f64),
            ),
            (
                Registrar::REG_TYPE_TRANSFER,
                result.get("transferPrice").and_then(json_f64),
            ),
        ]);

        let mut availability_failed = false;
        let availability = match self.send(
            Method::POST,
            "/core/v1/domains:checkAvailability",
            Some(&json!({ "domainNames": [domain] })),
        ) {
            Ok(v) => v
                .get("results")
                .and_then(Value::as_array)
                .and_then(|a| a.first())
                .cloned(),
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => return Err(e),
            Err(_) => {
                availability_failed = true;
                None
            }
        };

        if let Some(availability) = &availability {
            let purchase_type = availability
                .get("purchaseType")
                .and_then(Value::as_str)
                .unwrap_or("registration");
            let purchasable = availability
                .get("purchasable")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            let premium_flag = availability
                .get("premium")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            if purchasable
                && (premium_flag || (!purchase_type.is_empty() && purchase_type != "registration"))
            {
                is_premium = true;
                if let Some(p) = availability.get("purchasePrice").and_then(json_f64) {
                    price_map.insert(Registrar::REG_TYPE_NEW, Some(p));
                }
                if let Some(p) = availability.get("renewalPrice").and_then(json_f64) {
                    if p != 0.0 {
                        price_map.insert(Registrar::REG_TYPE_RENEWAL, Some(p));
                    }
                }
            }
        }

        if price_map.values().all(Option::is_none) {
            return Err(DomainsError::price_not_found(
                format!("Price not found for domain: {domain}"),
                400,
            ));
        }

        if let Some(cache) = &self.state.cache {
            if !availability_failed {
                let mut cache_data = serde_json::Map::new();
                for (kind, price) in &price_map {
                    cache_data.insert(
                        (*kind).to_string(),
                        json!({ "price": price, "premium": is_premium }),
                    );
                }
                cache.save(&cache_key, Value::Object(cache_data));
            }
        }

        let price = price_map.get(reg_type).copied().flatten();
        let Some(price) = price else {
            return Err(DomainsError::price_not_found(
                format!("Price not found for domain: {domain}"),
                400,
            ));
        };
        Ok(Price::new(price, is_premium))
    }

    fn tlds(&self) -> Result<Vec<String>, DomainsError> {
        Ok(Vec::new())
    }

    fn get_domain(&self, domain: &str) -> Result<RegistrarDomain, DomainsError> {
        match self.send(Method::GET, &format!("/core/v1/domains/{domain}"), None) {
            Ok(result) => {
                let created_at = result
                    .get("createDate")
                    .and_then(Value::as_str)
                    .and_then(parse_datetime);
                let expires_at = result
                    .get("expireDate")
                    .and_then(Value::as_str)
                    .and_then(parse_datetime);
                let auto_renew = result
                    .get("autorenewEnabled")
                    .and_then(Value::as_bool)
                    .unwrap_or(false);
                let nameservers = result
                    .get("nameservers")
                    .and_then(Value::as_array)
                    .map(|arr| {
                        arr.iter()
                            .filter_map(|v| v.as_str().map(str::to_string))
                            .collect()
                    });
                Ok(RegistrarDomain::new(
                    domain,
                    created_at,
                    expires_at,
                    Some(auto_renew),
                    nameservers,
                ))
            }
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => Err(DomainsError::generic(
                format!("Failed to get domain information: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, DomainsError> {
        let Some(auto_renew) = details.auto_renew else {
            return Err(DomainsError::generic("Details must include autoRenew", 400));
        };
        match self.send(
            Method::PATCH,
            &format!("/core/v1/domains/{domain}"),
            Some(&json!({ "autorenewEnabled": auto_renew })),
        ) {
            Ok(_) => Ok(true),
            Err(e)
                if matches!(
                    e,
                    DomainsError::RateLimit { .. } | DomainsError::Generic { .. }
                ) =>
            {
                Err(e)
            }
            Err(e) => Err(DomainsError::generic(
                format!("Failed to update domain: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, DomainsError> {
        match self.send(
            Method::POST,
            &format!("/core/v1/domains/{domain}:renew"),
            Some(&json!({ "years": period_years })),
        ) {
            Ok(result) => {
                let order_id = json_string(result.get("order").unwrap_or(&Value::Null));
                let expires_at = result
                    .pointer("/domain/expireDate")
                    .and_then(Value::as_str)
                    .and_then(parse_datetime);
                Ok(Renewal::new(
                    if order_id.is_empty() {
                        Some(String::new())
                    } else {
                        Some(order_id)
                    },
                    expires_at,
                ))
            }
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => Err(DomainsError::generic(
                format!("Failed to renew domain: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn get_auth_code(&self, domain: &str) -> Result<String, DomainsError> {
        match self.send(
            Method::GET,
            &format!("/core/v1/domains/{domain}:getAuthCode"),
            None,
        ) {
            Ok(result) => result
                .get("authCode")
                .and_then(Value::as_str)
                .map(str::to_string)
                .ok_or_else(|| DomainsError::generic("Auth code not found in response", 404)),
            Err(e)
                if matches!(
                    e,
                    DomainsError::RateLimit { .. } | DomainsError::Generic { .. }
                ) =>
            {
                if matches!(e, DomainsError::Generic { .. })
                    && e.message() == "Auth code not found in response"
                {
                    return Err(e);
                }
                if matches!(e, DomainsError::RateLimit { .. }) {
                    return Err(e);
                }
                Err(DomainsError::generic(
                    format!("Failed to get auth code: {}", e.message()),
                    e.code(),
                ))
            }
            Err(e) => Err(DomainsError::generic(
                format!("Failed to get auth code: {}", e.message()),
                e.code(),
            )),
        }
    }

    fn check_transfer_status(&self, domain: &str) -> Result<TransferStatus, DomainsError> {
        match self.send(Method::GET, &format!("/core/v1/transfers/{domain}"), None) {
            Ok(result) => {
                let status = Self::map_transfer_status(
                    result
                        .get("status")
                        .and_then(Value::as_str)
                        .unwrap_or("unknown"),
                );
                let reason = result
                    .get("statusDetails")
                    .and_then(Value::as_str)
                    .map(str::to_string);
                let timestamp = result
                    .get("created")
                    .and_then(Value::as_str)
                    .and_then(parse_datetime);
                Ok(TransferStatus::new(status, reason, timestamp))
            }
            Err(e) if matches!(e, DomainsError::RateLimit { .. }) => Err(e),
            Err(e) => {
                if e.code() == 404 {
                    Err(DomainsError::domain_not_found(
                        format!("Domain not found: {domain}"),
                        e.code(),
                    ))
                } else {
                    Err(DomainsError::generic(
                        format!("Failed to check transfer status: {}", e.message()),
                        e.code(),
                    ))
                }
            }
        }
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
