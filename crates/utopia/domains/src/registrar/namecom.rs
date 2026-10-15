//! The Name.com registrar, over its Core API.

use std::sync::Arc;

use php_std::json;
use serde_json::{Map, Value, json};

use super::{
    Adapter, Availability, Config, Contact, ContactKey, Contacts, Domain, NameserverUpdate, Price, Purchase, RegType,
    Renewal, SuggestOptions, Suggestion, SuggestionKind, Suggestions, TransferState, TransferStatus, UpdateDetails,
    bare_tld, datetime,
};
use crate::Error;
use crate::php::{at, empty, float, path, string, truthy};
use crate::transport::{HttpTransport, Request, Transport, normalize_url};

const AVAILABILITY_BATCH_SIZE: usize = 50;
const AVAILABILITY_CACHE_TTL: i64 = 60;
const TLD_PAGE_SIZE: i64 = 1000;

/// Name.com's error messages, matched in this order against an error's
/// lower-cased message, each only with the code it comes with (`None`: any
/// code).
///
/// PHP: `NameCom::ERROR_*`.
const ERROR_MAP: [(&str, Option<i64>); 13] = [
    (NameCom::ERROR_NOT_FOUND, Some(404)),
    (NameCom::ERROR_DOMAIN_TAKEN, None),
    (NameCom::ERROR_DOMAIN_DOES_NOT_EXIST, Some(404)),
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

/// The Name.com registrar, over its Core API with HTTP basic authentication.
///
/// PHP: `Registrar\Adapter\NameCom`.
///
/// Availability is asked 50 domains per request; each answer carries the
/// prices too, so with a cache a following [`Adapter::price`] for the same
/// domain needs no request.
///
/// ```
/// use std::sync::Arc;
/// use utopia_domains::registrar::{NameCom, Registrar};
/// use utopia_domains::transport::{Request, Response, Transport, TransportError};
/// use utopia_domains::BoxFuture;
///
/// /// Answers every request with the same recorded response.
/// struct Recorded(&'static str);
///
/// impl Transport for Recorded {
///     fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
///         assert_eq!(request.url, "https://api.name.com/core/v1/domains/example.com:getAuthCode");
///         Box::pin(async move { Ok(Response { status: 200, headers: vec![], body: self.0.into() }) })
///     }
/// }
///
/// let adapter = NameCom::new("user", "token", "api.name.com", Some(Arc::new(Recorded(r#"{"authCode":"x1y2"}"#))));
/// let registrar = Registrar::new(adapter);
/// let code = utopia_async::Runtime::new()?.block_on(registrar.auth_code("example.com"))?;
/// assert_eq!(code, "x1y2");
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Psr\Http\Client\ClientInterface;
/// use Psr\Http\Message\RequestInterface;
/// use Psr\Http\Message\ResponseInterface;
/// use Utopia\Domains\Registrar;
/// use Utopia\Domains\Registrar\Adapter\NameCom;
/// use Utopia\Psr7\Response\Factory;
///
/// $recorded = new class implements ClientInterface {
///     public function sendRequest(RequestInterface $request): ResponseInterface
///     {
///         return (new Factory())->createResponse(200)
///             ->withBody((new \Utopia\Psr7\Stream\Factory())->createStream('{"authCode":"x1y2"}'));
///     }
/// };
/// $registrar = new Registrar(new NameCom('user', 'token', 'api.name.com', $recorded));
/// echo $registrar->getAuthCode('example.com'), "\n"; // x1y2
/// ```
#[derive(Clone)]
pub struct NameCom {
    username: String,
    token: String,
    endpoint: String,
    transport: Arc<dyn Transport>,
}

impl std::fmt::Debug for NameCom {
    /// Writes the username and endpoint, leaving the token out.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("NameCom").field("username", &self.username).field("endpoint", &self.endpoint).finish()
    }
}

/// `https://` in front of an endpoint, replacing `http://`.
pub(crate) fn https(endpoint: &str) -> String {
    if let Some(rest) = endpoint.strip_prefix("http://") {
        format!("https://{rest}")
    } else if endpoint.starts_with("https://") {
        endpoint.to_owned()
    } else {
        format!("https://{endpoint}")
    }
}

fn premium_listing(result: Option<&Value>) -> bool {
    let premium = result.and_then(|r| at(r, "premium"));
    let purchase_type = result.and_then(|r| at(r, "purchaseType"));
    premium == Some(&Value::Bool(true))
        || match purchase_type {
            None => false,
            Some(Value::String(t)) => !t.is_empty() && t != "registration",
            Some(_) => true,
        }
}

impl NameCom {
    /// Name.com error: the resource was not found (with code 404).
    pub const ERROR_NOT_FOUND: &'static str = "Not Found";
    /// Name.com error: the domain is registered already.
    pub const ERROR_DOMAIN_TAKEN: &'static str = "Domain is not available";
    /// Name.com error: the domain to transfer does not exist (with code 404).
    pub const ERROR_DOMAIN_DOES_NOT_EXIST: &'static str = "The requested domain does not exist.";
    /// Name.com error: the transfer auth code was refused (lower-case, as matched).
    pub const ERROR_INVALID_AUTH_CODE: &'static str = "we were unable to get authoritative domain information from the registry. this usually means that the domain name or auth code provided was not correct.";
    /// Name.com error: a contact field was refused.
    pub const ERROR_INVALID_CONTACT: &'static str = "invalid value for";
    /// Name.com error: the domain name is not valid.
    pub const ERROR_INVALID_DOMAIN: &'static str = "Invalid Domain Name";
    /// Name.com error: none of the domains of an availability check is valid.
    pub const ERROR_INVALID_DOMAINS: &'static str = "None of the submitted domains are valid";
    /// Name.com error: the period is not allowed for the TLD (with code 400).
    pub const ERROR_INVALID_YEARS: &'static str = "Invalid value for years";
    /// Name.com error: the TLD is not sold (with code 422).
    pub const ERROR_UNSUPPORTED_TLD: &'static str = "unsupported tld";
    /// Name.com error: the TLD is not supported.
    pub const ERROR_TLD_NOT_SUPPORTED: &'static str = "TLD not supported";
    /// Name.com error: transfers of the TLD are not supported (with code 400).
    pub const ERROR_UNSUPPORTED_TRANSFER: &'static str = "do not support transfers for";
    /// Name.com error: the credentials were refused (with code 401).
    pub const ERROR_UNAUTHORIZED: &'static str = "Unauthorized";
    /// Name.com error: the account is throttled (with code 429).
    pub const ERROR_RATE_LIMIT_EXCEEDED: &'static str = "Rate Limit Exceeded";

    /// An adapter for an account (`username` and API `token`) at an endpoint
    /// (`https://api.name.com` in production, `https://api.dev.name.com` for
    /// tests; `https://` is enforced). Without a transport, requests go over
    /// [`HttpTransport`].
    pub fn new(
        username: impl Into<String>,
        token: impl Into<String>,
        endpoint: &str,
        transport: Option<Arc<dyn Transport>>,
    ) -> Self {
        Self {
            username: username.into(),
            token: token.into(),
            endpoint: https(endpoint),
            transport: transport.unwrap_or_else(|| Arc::new(HttpTransport::new())),
        }
    }

    /// The endpoint requests go to.
    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    /// One API call: the decoded answer, or the error Name.com reported.
    async fn send(&self, config: &Config, method: &str, path: &str, data: Option<&Value>) -> Result<Value, Error> {
        let mut body = Vec::new();
        if let Some(data) = data
            && matches!(method, "POST" | "PUT" | "PATCH")
        {
            let value = php_std::Value::<php_std::Never>::from_json(data);
            body = json::encode(&value, json::Flags::NONE, json::DEFAULT_DEPTH)
                .map_err(|e| Error::exception(format!("Failed to encode request data to JSON: {}", e.message()), 0))?
                .into_bytes();
        }
        let credentials = php_std::encoding::base64_encode(format!("{}:{}", self.username, self.token).as_bytes());
        let request = Request {
            method: method.to_owned(),
            url: normalize_url(&format!("{}{path}", self.endpoint))
                .ok_or_else(|| Error::InvalidArgument("Invalid URI.".into()))?,
            headers: vec![
                ("Content-Type".into(), "application/json".into()),
                ("Authorization".into(), format!("Basic {credentials}")),
            ],
            body,
            connect_timeout: config.connect_timeout,
            timeout: config.timeout,
        };
        let response = self
            .transport
            .send(request)
            .await
            .map_err(|e| Error::exception(format!("Failed to send request to Name.com: {e}"), 0))?;
        let decoded = if response.body.is_empty() {
            None
        } else {
            match json::decode_value(&response.body, json::DEFAULT_DEPTH, json::Flags::NONE) {
                Ok(Value::Null) if response.body == b"null" => None,
                Ok(v) if !v.is_null() => Some(v),
                _ => return Err(Error::exception("Failed to parse response from Name.com: Invalid JSON", 0)),
            }
        };
        let status = i64::from(response.status);
        if status >= 400 {
            let mut message =
                decoded.as_ref().and_then(|r| at(r, "message")).map_or("Unknown error".to_owned(), string);
            let details = decoded.as_ref().and_then(|r| at(r, "details"));
            if truthy(details) {
                message.push_str(&format!("({})", details.map(string).unwrap_or_default()));
            }
            if status == 429
                || message.to_ascii_lowercase().contains(&Self::ERROR_RATE_LIMIT_EXCEEDED.to_ascii_lowercase())
            {
                return Err(Error::RateLimit { message: format!("Rate limit exceeded: {message}"), code: 429 });
            }
            return Err(Error::exception(message, status));
        }
        Ok(decoded.unwrap_or_else(|| Value::Array(Vec::new())))
    }

    /// The `ERROR_MAP` entry an error matches.
    fn match_error(error: &Error) -> Option<&'static str> {
        let lower = error.message().to_ascii_lowercase();
        let code = error.code();
        ERROR_MAP
            .iter()
            .find(|(message, expected)| {
                expected.is_none_or(|c| c == code) && lower.contains(&message.to_ascii_lowercase())
            })
            .map(|(message, _)| *message)
    }

    fn sanitize_contacts(contacts: &Contacts) -> Result<Value, Error> {
        let Some((_, first)) = contacts.iter().next() else {
            return Err(Error::InvalidContact { message: "Contacts must be a non-empty array".into(), code: 400 });
        };
        let pick = |roles: &[&str], index: i64| -> &Contact {
            roles
                .iter()
                .find_map(|r| contacts.role(r))
                .or_else(|| contacts.get(&ContactKey::Index(index)))
                .unwrap_or(first)
        };
        let mut result = Map::new();
        result.insert("registrant".into(), Self::format_contact(pick(&["registrant", "owner"], 0)));
        result.insert("admin".into(), Self::format_contact(pick(&["admin"], 1)));
        result.insert("tech".into(), Self::format_contact(pick(&["tech"], 2)));
        result.insert("billing".into(), Self::format_contact(pick(&["billing"], 3)));
        Ok(Value::Object(result))
    }

    fn format_contact(contact: &Contact) -> Value {
        json!({
            "firstName": contact.firstname,
            "lastName": contact.lastname,
            "companyName": contact.org,
            "email": contact.email,
            "phone": contact.phone,
            "address1": contact.address1,
            "address2": contact.address2,
            "city": contact.city,
            "state": contact.state,
            "zip": contact.postalcode,
            "country": contact.country,
        })
    }

    fn map_transfer_status(status: &str) -> TransferState {
        match status.to_ascii_lowercase().as_str() {
            "completed" => TransferState::Completed,
            "canceled" | "canceled_pending_refund" | "rejected" => TransferState::Cancelled,
            "pending" | "pending_transfer" | "submitting_transfer" => TransferState::PendingRegistry,
            "pending_insert" => TransferState::PendingAdmin,
            "pending_new_auth_code" | "pending_unlock" => TransferState::PendingOwner,
            _ => TransferState::NotTransferrable,
        }
    }

    async fn try_purchase(&self, config: &Config, purchase: &Purchase) -> Result<String, Error> {
        let nameservers =
            if purchase.nameservers.is_empty() { &config.default_nameservers } else { &purchase.nameservers };
        let contacts = Self::sanitize_contacts(&purchase.contacts)?;
        let mut data = json!({
            "domain": {
                "domainName": purchase.domain,
                "nameservers": nameservers,
                "contacts": contacts,
                "autorenewEnabled": purchase.autorenew,
            },
            "years": purchase.period_years,
        });
        if let Some(price) = purchase.price {
            data["purchasePrice"] = price_value(price);
        }
        let result = self.send(config, "POST", "/core/v1/domains", Some(&data)).await?;
        Ok(at(&result, "order").map(string).unwrap_or_default())
    }

    async fn try_transfer(
        &self,
        config: &Config,
        domain: &str,
        auth_code: &str,
        price: Option<f64>,
    ) -> Result<String, Error> {
        let mut data = json!({ "domainName": domain, "authCode": auth_code });
        if let Some(price) = price {
            data["purchasePrice"] = price_value(price);
        }
        let result = self.send(config, "POST", "/core/v1/transfers", Some(&data)).await?;
        Ok(at(&result, "order").map(string).unwrap_or_default())
    }

    async fn try_price(
        &self,
        config: &Config,
        domain: &str,
        period_years: i64,
        reg_type: RegType,
    ) -> Result<Price, Error> {
        let result = self
            .send(config, "GET", &format!("/core/v1/domains/{domain}:getPricing?years={period_years}"), None)
            .await?;
        let mut premium = !empty(at(&result, "premium"));
        let mut prices: [(RegType, Option<Value>); 3] = [
            (RegType::New, at(&result, "purchasePrice").cloned()),
            (RegType::Renewal, at(&result, "renewalPrice").cloned()),
            (RegType::Transfer, at(&result, "transferPrice").cloned()),
        ];

        let mut availability = None;
        let mut availability_failed = false;
        if let Some(cache) = &config.cache
            && let Some(cached) = cache.load(&format!("{domain}_availability"), AVAILABILITY_CACHE_TTL).await
            && matches!(cached, Value::Array(_) | Value::Object(_))
        {
            availability = Some(cached);
        }
        if availability.is_none() {
            let data = json!({ "domainNames": [domain] });
            match self.send(config, "POST", "/core/v1/domains:checkAvailability", Some(&data)).await {
                Ok(answer) => availability = path(&answer, &["results", "0"]).cloned(),
                Err(e @ Error::RateLimit { .. }) => return Err(e),
                Err(_) => availability_failed = true,
            }
        }
        let availability = availability.as_ref();
        if !empty(availability.and_then(|a| at(a, "purchasable"))) && premium_listing(availability) {
            premium = true;
            if let Some(price) = availability.and_then(|a| at(a, "purchasePrice")) {
                prices[0].1 = Some(price_value(float(price)));
            }
            let renewal = availability.and_then(|a| at(a, "renewalPrice"));
            if !empty(renewal) {
                prices[1].1 = renewal.map(|r| price_value(float(r)));
            }
        }

        if prices.iter().all(|(_, p)| p.is_none()) {
            return Err(price_not_found(domain));
        }
        if let Some(cache) = &config.cache
            && !availability_failed
        {
            let mut data = Map::new();
            for (kind, price) in &prices {
                let price = price.as_ref().map_or(Value::Null, |p| price_value(float(p)));
                data.insert(kind.as_str().to_owned(), json!({ "price": price, "premium": premium }));
            }
            cache.save(&format!("{domain}_{period_years}"), &Value::Object(data)).await;
        }
        let price = prices.iter().find(|(kind, _)| *kind == reg_type).and_then(|(_, p)| p.as_ref());
        match price {
            Some(price) => Ok(Price { price: float(price), premium }),
            None => Err(price_not_found(domain)),
        }
    }
}

/// A float as JSON (non-finite ones as `null`, which PHP cannot encode either).
fn price_value(price: f64) -> Value {
    serde_json::Number::from_f64(price).map_or(Value::Null, Value::Number)
}

fn price_not_found(domain: &str) -> Error {
    Error::PriceNotFound { message: format!("Price not found for domain: {domain}"), code: 400 }
}

/// Re-raises a rate limit as is; wraps anything else.
fn wrap(error: Error, wrap: impl FnOnce(String, i64) -> Error) -> Error {
    match error {
        e @ Error::RateLimit { .. } => e,
        e => wrap(e.message().to_owned(), e.code()),
    }
}

impl Adapter for NameCom {
    fn name(&self) -> &str {
        "namecom"
    }

    async fn available(&self, config: &Config, domains: &[String]) -> Result<Availability, Error> {
        let mut unique: Vec<&String> = Vec::with_capacity(domains.len());
        for d in domains {
            if !unique.contains(&d) {
                unique.push(d);
            }
        }
        let mut availability: Availability = unique.iter().map(|d| ((*d).clone(), false)).collect();
        for chunk in unique.chunks(AVAILABILITY_BATCH_SIZE) {
            let data = json!({ "domainNames": chunk });
            let result = match self.send(config, "POST", "/core/v1/domains:checkAvailability", Some(&data)).await {
                Ok(result) => result,
                Err(e) if Self::match_error(&e) == Some(Self::ERROR_INVALID_DOMAINS) => continue,
                Err(e) => return Err(e),
            };
            let Some(results) = at(&result, "results") else { continue };
            for domain in values(results) {
                let Some(name) = at(domain, "domainName").map(string) else { continue };
                let Some(slot) = availability.get_mut(&name) else { continue };
                *slot = truthy(at(domain, "purchasable"));
                let Some(cache) = &config.cache else { continue };
                cache.save(&format!("{name}_availability"), domain).await;
                if empty(at(domain, "purchasable")) {
                    continue;
                }
                let Some(price) = at(domain, "purchasePrice") else { continue };
                let premium = premium_listing(Some(domain));
                let mut data = Map::new();
                data.insert(
                    RegType::New.as_str().into(),
                    json!({ "price": price_value(float(price)), "premium": premium }),
                );
                let renewal = at(domain, "renewalPrice");
                if !empty(renewal) {
                    let renewal = renewal.map_or(0.0, float);
                    data.insert(
                        RegType::Renewal.as_str().into(),
                        json!({ "price": price_value(renewal), "premium": premium }),
                    );
                }
                let tld = name.rsplit('.').next().unwrap_or("");
                let period_years = if tld == "ai" { 2 } else { 1 };
                cache.save(&format!("{name}_{period_years}"), &Value::Object(data)).await;
            }
        }
        Ok(availability)
    }

    async fn update_nameservers(
        &self,
        config: &Config,
        domain: &str,
        nameservers: &[String],
    ) -> Result<NameserverUpdate, Error> {
        let data = json!({ "nameservers": nameservers });
        match self.send(config, "POST", &format!("/core/v1/domains/{domain}:setNameservers"), Some(&data)).await {
            Ok(result) => Ok(NameserverUpdate {
                successful: true,
                nameservers: Some(match at(&result, "nameservers") {
                    Some(list) => values(list).map(string).collect(),
                    None => nameservers.to_vec(),
                }),
                ..NameserverUpdate::default()
            }),
            Err(e @ Error::RateLimit { .. }) => Err(e),
            Err(e) => Ok(NameserverUpdate {
                successful: false,
                error: Some(e.message().to_owned()),
                ..NameserverUpdate::default()
            }),
        }
    }

    async fn purchase(&self, config: &Config, purchase: &Purchase) -> Result<String, Error> {
        self.try_purchase(config, purchase).await.map_err(|e| {
            let matched = Self::match_error(&e);
            wrap(e, |message, code| {
                let message = format!("Failed to purchase domain: {message}");
                match matched {
                    Some(Self::ERROR_UNAUTHORIZED) => Error::Auth { message, code },
                    Some(Self::ERROR_DOMAIN_TAKEN) => Error::DomainTaken { message, code },
                    Some(Self::ERROR_INVALID_CONTACT) => Error::InvalidContact { message, code },
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_UNSUPPORTED_TRANSFER) => {
                        Error::UnsupportedTld { message, code }
                    }
                    _ => Error::Domains { message, code },
                }
            })
        })
    }

    async fn transfer(
        &self,
        config: &Config,
        domain: &str,
        auth_code: &str,
        price: Option<f64>,
    ) -> Result<String, Error> {
        self.try_transfer(config, domain, auth_code, price).await.map_err(|e| {
            let matched = Self::match_error(&e);
            wrap(e, |message, code| {
                let message = format!("Failed to transfer domain: {message}");
                match matched {
                    Some(Self::ERROR_UNAUTHORIZED) => Error::Auth { message, code },
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_UNSUPPORTED_TRANSFER) => {
                        Error::UnsupportedTld { message, code }
                    }
                    Some(Self::ERROR_INVALID_AUTH_CODE) => Error::InvalidAuthCode { message, code },
                    Some(Self::ERROR_DOMAIN_DOES_NOT_EXIST) => Error::DomainNotTransferable { message, code },
                    Some(Self::ERROR_DOMAIN_TAKEN) => Error::DomainTaken { message, code },
                    _ => Error::Domains { message, code },
                }
            })
        })
    }

    async fn cancel_purchase(&self, _config: &Config) -> Result<bool, Error> {
        Ok(true)
    }

    async fn suggest(&self, config: &Config, query: &[String], options: &SuggestOptions) -> Result<Suggestions, Error> {
        let mut data = Map::new();
        data.insert("keyword".into(), Value::String(query.join(" ")));
        if !options.tlds.is_empty() {
            data.insert("tldFilter".into(), options.tlds.iter().map(|t| bare_tld(t)).collect());
        }
        let limit = options.limit.filter(|&l| l != 0);
        if let Some(limit) = limit {
            data.insert("limit".into(), limit.into());
        }
        let result = self.send(config, "POST", "/core/v1/domains:search", Some(&Value::Object(data))).await?;
        let mut items = Suggestions::new();
        let Some(results @ (Value::Array(_) | Value::Object(_))) = at(&result, "results") else {
            return Ok(items);
        };
        for entry in values(results) {
            let Some(domain) = at(entry, "domainName").filter(|d| truthy(Some(d))).map(string) else { continue };
            let price = at(entry, "purchasePrice").map(float);
            let renewal_price = at(entry, "renewalPrice").map(float);
            let purchase_type = at(entry, "purchaseType").map_or_else(|| "registration".to_owned(), string);
            let premium = premium_listing(Some(entry));
            if let Some(price) = price {
                if options.price_min.is_some_and(|min| price < min as f64) {
                    continue;
                }
                if options.price_max.is_some_and(|max| price > max as f64) {
                    continue;
                }
            }
            match options.filter {
                Some(SuggestionKind::Premium) if !premium => continue,
                Some(SuggestionKind::Suggestion) if premium => continue,
                _ => {}
            }
            items.insert(
                domain,
                Suggestion {
                    available: truthy(at(entry, "purchasable")),
                    price,
                    kind: if premium { SuggestionKind::Premium } else { SuggestionKind::Suggestion },
                    renewal_price,
                    purchase_type: Some(purchase_type),
                },
            );
            if limit.is_some_and(|l| items.len() as i64 >= l) {
                break;
            }
        }
        Ok(items)
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
            && let Some(cached) = cache.load(&format!("{domain}_{period_years}"), ttl).await
            && let Some(entry @ (Value::Array(_) | Value::Object(_))) = at(&cached, reg_type.as_str())
        {
            return match at(entry, "price") {
                None => Err(price_not_found(domain)),
                Some(price) => Ok(Price { price: float(price), premium: truthy(at(entry, "premium")) }),
            };
        }
        self.try_price(config, domain, period_years, reg_type).await.map_err(|e| match e {
            e @ (Error::PriceNotFound { .. } | Error::RateLimit { .. }) => e,
            e => {
                let matched = Self::match_error(&e);
                let message = format!("Failed to get price for domain: {domain} - {}", e.message());
                let code = e.code();
                match matched {
                    Some(Self::ERROR_UNSUPPORTED_TLD | Self::ERROR_TLD_NOT_SUPPORTED) => {
                        Error::UnsupportedTld { message, code }
                    }
                    Some(Self::ERROR_NOT_FOUND | Self::ERROR_INVALID_DOMAIN) => Error::PriceNotFound { message, code },
                    Some(Self::ERROR_INVALID_YEARS) => Error::InvalidPeriod { message, code },
                    _ => Error::Domains { message, code },
                }
            }
        })
    }

    async fn tlds(&self, config: &Config) -> Result<Vec<String>, Error> {
        let mut tlds = Vec::new();
        let mut page = Value::from(1);
        loop {
            let path = format!("/core/v1/tldpricing?perPage={TLD_PAGE_SIZE}&page={}", string(&page));
            let result = self.send(config, "GET", &path, None).await?;
            if let Some(pricing) = at(&result, "pricing") {
                tlds.extend(values(pricing).filter_map(|p| at(p, "tld")).map(string));
            }
            match at(&result, "nextPage") {
                Some(next) => page = next.clone(),
                None => return Ok(tlds),
            }
        }
    }

    async fn domain(&self, config: &Config, domain: &str) -> Result<Domain, Error> {
        let fetch = async {
            let result = self.send(config, "GET", &format!("/core/v1/domains/{domain}"), None).await?;
            let date = |key: &str| at(&result, key).map(|v| datetime(&string(v))).transpose();
            Ok::<_, Error>(Domain {
                domain: domain.to_owned(),
                created_at: date("createDate")?,
                expires_at: date("expireDate")?,
                auto_renew: Some(truthy(at(&result, "autorenewEnabled"))),
                nameservers: Some(
                    at(&result, "nameservers").map(|n| values(n).map(string).collect()).unwrap_or_default(),
                ),
            })
        };
        fetch.await.map_err(|e| {
            wrap(e, |m, code| Error::Domains { message: format!("Failed to get domain information: {m}"), code })
        })
    }

    async fn update_domain(&self, config: &Config, domain: &str, details: &UpdateDetails) -> Result<bool, Error> {
        let Some(auto_renew) = details.auto_renew else {
            return Err(Error::domains("Details must include autoRenew", 400));
        };
        let data = json!({ "autorenewEnabled": auto_renew });
        self.send(config, "PATCH", &format!("/core/v1/domains/{domain}"), Some(&data))
            .await
            .map(|_| true)
            .map_err(|e| wrap(e, |m, code| Error::Domains { message: format!("Failed to update domain: {m}"), code }))
    }

    async fn renew(&self, config: &Config, domain: &str, period_years: i64) -> Result<Renewal, Error> {
        let fetch = async {
            let data = json!({ "years": period_years });
            let result = self.send(config, "POST", &format!("/core/v1/domains/{domain}:renew"), Some(&data)).await?;
            let expires_at = path(&result, &["domain", "expireDate"]).map(|v| datetime(&string(v))).transpose()?;
            Ok::<_, Error>(Renewal { order_id: Some(at(&result, "order").map(string).unwrap_or_default()), expires_at })
        };
        fetch
            .await
            .map_err(|e| wrap(e, |m, code| Error::Domains { message: format!("Failed to renew domain: {m}"), code }))
    }

    async fn auth_code(&self, config: &Config, domain: &str) -> Result<String, Error> {
        let result = self.send(config, "GET", &format!("/core/v1/domains/{domain}:getAuthCode"), None).await;
        match result {
            Ok(result) => match at(&result, "authCode") {
                // The return type is `string`: scalars convert, arrays are a TypeError.
                Some(Value::Array(_) | Value::Object(_)) => Err(Error::Type(
                    "Utopia\\Domains\\Registrar\\Adapter\\NameCom::getAuthCode(): Return value must be of type string, array returned"
                        .into(),
                )),
                Some(code) => Ok(string(code)),
                None => Err(Error::domains("Auth code not found in response", 404)),
            },
            Err(e) => Err(wrap(e, |m, code| Error::Domains { message: format!("Failed to get auth code: {m}"), code })),
        }
    }

    async fn transfer_status(&self, config: &Config, domain: &str) -> Result<TransferStatus, Error> {
        let fetch = async {
            let result = self.send(config, "GET", &format!("/core/v1/transfers/{domain}"), None).await?;
            let status = at(&result, "status").map_or_else(|| "unknown".to_owned(), string);
            Ok::<_, Error>(TransferStatus {
                status: Self::map_transfer_status(&status),
                reason: at(&result, "statusDetails").map(string),
                timestamp: at(&result, "created").map(|v| datetime(&string(v))).transpose()?,
            })
        };
        fetch.await.map_err(|e| match e {
            e @ Error::RateLimit { .. } => e,
            e if e.code() == 404 => Error::DomainNotFound { message: format!("Domain not found: {domain}"), code: 404 },
            e => {
                Error::Domains { message: format!("Failed to check transfer status: {}", e.message()), code: e.code() }
            }
        })
    }
}

/// The values of a decoded array (list or map), in order.
pub(crate) fn values(value: &Value) -> Box<dyn Iterator<Item = &Value> + Send + '_> {
    match value {
        Value::Array(list) => Box::new(list.iter()),
        Value::Object(map) => Box::new(map.values()),
        _ => Box::new(std::iter::empty()),
    }
}
