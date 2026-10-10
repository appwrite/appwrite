//! [`OpenSrs`]: the OpenSRS XML API (XCP envelopes over HTTPS).

use std::sync::Arc;

use super::xml::{Document, Node};
use super::{
    Adapter, Availability, Config, Contacts, Domain, NameserverUpdate, Price, Purchase, RegType, Renewal,
    SuggestOptions, Suggestion, SuggestionKind, Suggestions, TransferState, TransferStatus, UpdateDetails, bare_tld,
    datetime, md5,
};
use crate::Error;
use crate::php::int;
use crate::registrar::namecom::https;
use crate::transport::{HttpTransport, Request, Transport, normalize_url};

/// A value of an XCP message.
#[derive(Debug, Clone)]
enum Item {
    Str(String),
    Int(i64),
    Float(f64),
    /// An array: a list (keys `0..n`) or keyed entries.
    Array(Vec<(String, Item)>),
}

impl Item {
    fn list<S: Into<String>>(items: impl IntoIterator<Item = S>) -> Item {
        Item::Array(items.into_iter().enumerate().map(|(i, s)| (i.to_string(), Item::Str(s.into()))).collect())
    }

    /// `array_keys($a) === range(0, count($a) - 1)`.
    fn is_list(entries: &[(String, Item)]) -> bool {
        !entries.is_empty() && entries.iter().enumerate().all(|(i, (k, _))| *k == i.to_string())
    }
}

/// An XCP message: object, action, optional domain and attributes.
struct Message {
    object: &'static str,
    action: &'static str,
    domain: Option<String>,
    attributes: Vec<(String, Item)>,
}

fn attr(key: &str, item: Item) -> (String, Item) {
    (key.to_owned(), item)
}

fn s(value: impl Into<String>) -> Item {
    Item::Str(value.into())
}

/// `createEnvelopItem()`: a scalar as `<item key='k'>v</item>`, an array as a `dt_array`.
fn envelop_item(key: &str, value: &Item) -> String {
    match value {
        Item::Str(v) => format!("<item key='{key}'>{v}</item>"),
        Item::Int(v) => format!("<item key='{key}'>{v}</item>"),
        // A float reaches a `string|int` parameter: PHP truncates it to an int.
        Item::Float(v) if v.is_finite() && v.abs() < 9.2e18 => format!("<item key='{key}'>{}</item>", v.trunc() as i64),
        Item::Float(v) => format!("<item key='{key}'>{}</item>", php_std::number::to_string(*v)),
        Item::Array(entries) => create_array(key, entries),
    }
}

fn create_array(key: &str, entries: &[(String, Item)]) -> String {
    let mut out = vec![format!("<item key=\"{key}\">"), "<dt_array>".to_owned()];
    out.extend(entries.iter().map(|(k, v)| envelop_item(k, v)));
    out.push("</dt_array>".into());
    out.push("</item>".into());
    out.join("\n")
}

fn create_assoc(key: &str, entries: &[(String, Item)]) -> String {
    let mut out = vec![format!("<item key=\"{key}\">"), "<dt_assoc>".to_owned()];
    for (k, v) in entries {
        out.push(match v {
            Item::Array(inner) if Item::is_list(inner) => create_array(k, inner),
            Item::Array(inner) => create_assoc(k, inner),
            other => envelop_item(k, other),
        });
    }
    out.push("</dt_assoc>".into());
    out.push("</item>".into());
    out.join("\n")
}

fn wrap_assoc(key: &str, parts: impl IntoIterator<Item = String>) -> String {
    let mut out = vec![format!("<item key=\"{key}\">"), "<dt_assoc>".to_owned()];
    out.extend(parts);
    out.push("</dt_assoc>".into());
    out.push("</item>".into());
    out.join("\n")
}

fn wrap_array(key: &str, parts: impl IntoIterator<Item = String>) -> String {
    let mut out = vec![format!("<item key=\"{key}\">"), "<dt_array>".to_owned()];
    out.extend(parts);
    out.push("</dt_array>".into());
    out.push("</item>".into());
    out.join("\n")
}

/// `validateContact()` + `createContact()`.
fn create_contact(kind: &str, contact: &[(String, Item)]) -> String {
    const REQUIRED: [(&str, &str); 11] = [
        ("firstname", "first_name"),
        ("lastname", "last_name"),
        ("email", "email"),
        ("phone", "phone"),
        ("address1", "address1"),
        ("city", "city"),
        ("state", "state"),
        ("postalcode", "postal_code"),
        ("country", "country"),
        ("owner", "owner"),
        ("org", "org_name"),
    ];
    let mut out = vec![format!("<item key='{kind}'>"), "<dt_assoc>".to_owned()];
    for (field, name) in REQUIRED {
        if let Some((_, value)) = contact.iter().find(|(k, _)| k == field) {
            out.push(envelop_item(name, value));
        }
    }
    out.push("</dt_assoc>".into());
    out.push("</item>".into());
    out.join("\n")
}

fn build_envelope(message: &Message) -> String {
    let mut out: Vec<String> = [
        "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>",
        "<!DOCTYPE OPS_envelope SYSTEM 'ops.dtd'>",
        "<OPS_envelope>",
        "<header>",
        "<version>0.9</version>",
        "</header>",
        "<body>",
        "<data_block>",
        "<dt_assoc>",
    ]
    .map(String::from)
    .to_vec();
    out.push(envelop_item("protocol", &s("XCP")));
    out.push(envelop_item("object", &s(message.object)));
    out.push(envelop_item("action", &s(message.action)));
    out.push(message.domain.as_ref().map_or(String::new(), |d| envelop_item("domain", &s(d.as_str()))));
    out.push("<item key=\"attributes\">".into());
    out.push("<dt_assoc>".into());
    for (key, value) in &message.attributes {
        let entries = match value {
            Item::Array(entries) => Some(entries.as_slice()),
            _ => None,
        };
        out.push(match (key.as_str(), entries) {
            ("contact_set", Some(contacts)) => wrap_assoc(
                "contact_set",
                contacts.iter().map(|(kind, c)| match c {
                    Item::Array(fields) => create_contact(kind, fields),
                    _ => create_contact(kind, &[]),
                }),
            ),
            ("nameserver_list", Some(list)) => wrap_array(
                "nameserver_list",
                list.iter().enumerate().map(|(i, (_, name))| {
                    [
                        "<dt_assoc>".to_owned(),
                        envelop_item("name", name),
                        envelop_item("sortorder", &Item::Int(i as i64)),
                        "</dt_assoc>".to_owned(),
                    ]
                    .join("\n")
                }),
            ),
            ("assign_ns" | "add_ns" | "remove_ns", Some(list)) => {
                wrap_array("add_ns", list.iter().enumerate().map(|(i, (_, name))| envelop_item(&i.to_string(), name)))
            }
            ("service_override", Some(services)) => wrap_assoc(
                "service_override",
                services.iter().map(|(name, config)| match config {
                    Item::Array(entries) => create_assoc(name, entries),
                    other => create_assoc(name, &[(String::new(), other.clone())]),
                }),
            ),
            (key, _) => envelop_item(key, value),
        });
    }
    out.extend(
        ["</dt_assoc>", "</item>", "</dt_assoc>", "</data_block>", "</body>", "</OPS_envelope>"].map(String::from),
    );
    out.join("\n")
}

/// The OpenSRS registrar (`Registrar\Adapter\OpenSRS`): XCP messages
/// signed with the reseller API key.
///
/// ```
/// use std::sync::Arc;
/// use utopia_domains::registrar::{OpenSrs, Registrar};
/// use utopia_domains::transport::{Request, Response, Transport, TransportError};
/// use utopia_domains::BoxFuture;
///
/// /// Answers every lookup with "available".
/// struct Recorded;
///
/// impl Transport for Recorded {
///     fn send<'a>(&'a self, request: Request) -> BoxFuture<'a, Result<Response, TransportError>> {
///         assert!(request.header("X-Signature").is_some());
///         let body = "<OPS_envelope><body><data_block><dt_assoc>\
///             <item key=\"response_code\">210</item></dt_assoc></data_block></body></OPS_envelope>";
///         Box::pin(async move { Ok(Response { status: 200, headers: vec![], body: body.into() }) })
///     }
/// }
///
/// let adapter = OpenSrs::new("key", "reseller", "secret", "horizon.opensrs.net:55443", Some(Arc::new(Recorded)));
/// let registrar = Registrar::new(adapter);
/// let availability = tokio::runtime::Runtime::new()?.block_on(registrar.available(&["my-app.net".to_owned()]))?;
/// assert_eq!(availability["my-app.net"], true);
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Psr\Http\Client\ClientInterface;
/// use Psr\Http\Message\RequestInterface;
/// use Psr\Http\Message\ResponseInterface;
/// use Utopia\Domains\Registrar;
/// use Utopia\Domains\Registrar\Adapter\OpenSRS;
/// use Utopia\Psr7\Response\Factory;
///
/// $recorded = new class implements ClientInterface {
///     public function sendRequest(RequestInterface $request): ResponseInterface
///     {
///         $body = '<OPS_envelope><body><data_block><dt_assoc>'
///             . '<item key="response_code">210</item></dt_assoc></data_block></body></OPS_envelope>';
///         return (new Factory())->createResponse(200)
///             ->withBody((new \Utopia\Psr7\Stream\Factory())->createStream($body));
///     }
/// };
/// $registrar = new Registrar(new OpenSRS('key', 'reseller', 'secret', 'horizon.opensrs.net:55443', $recorded));
/// var_dump($registrar->available(['my-app.net'])['my-app.net']); // bool(true)
/// ```
#[derive(Clone)]
pub struct OpenSrs {
    api_key: String,
    username: String,
    password: String,
    endpoint: String,
    transport: Arc<dyn Transport>,
}

impl std::fmt::Debug for OpenSrs {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("OpenSrs").field("username", &self.username).field("endpoint", &self.endpoint).finish()
    }
}

/// The text of the first element a path finds, `""` when none.
fn first<'d>(doc: &'d Document, context: Node, path: &str) -> &'d str {
    doc.xpath(context, path).first().map_or("", |&n| doc.text(n))
}

const RESPONSE_CODE: &str = "//body/data_block/dt_assoc/item[@key=\"response_code\"]";
const RESPONSE_TEXT: &str = "//body/data_block/dt_assoc/item[@key=\"response_text\"]";
const IS_SUCCESS: &str = "//body/data_block/dt_assoc/item[@key=\"is_success\"]";
const ATTRIBUTE_ITEMS: &str = "//body/data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item";

impl OpenSrs {
    /// OpenSRS code: the domain is available.
    pub const RESPONSE_CODE_DOMAIN_AVAILABLE: i64 = 210;
    /// OpenSRS code: the change was already in place.
    pub const RESPONSE_CODE_NOTHING_TO_DO: i64 = 220;
    /// OpenSRS code: no price for the domain.
    pub const RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND: i64 = 400;
    /// OpenSRS code: invalid contact data or registrant password.
    pub const RESPONSE_CODE_INVALID_CONTACT: i64 = 465;
    /// OpenSRS code: the domain is taken.
    pub const RESPONSE_CODE_DOMAIN_TAKEN: i64 = 485;
    /// OpenSRS code: the domain cannot be transferred.
    pub const RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE: i64 = 487;

    /// An adapter for a reseller (`api_key`, `username`) registering
    /// domains under a registrant password, at an endpoint
    /// (`rr-n1-tor.opensrs.net:55443` in production,
    /// `horizon.opensrs.net:55443` for tests; `https://` is enforced).
    /// Without a transport, requests go over [`HttpTransport`].
    pub fn new(
        api_key: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
        endpoint: &str,
        transport: Option<Arc<dyn Transport>>,
    ) -> Self {
        Self {
            api_key: api_key.into(),
            username: username.into(),
            password: password.into(),
            endpoint: https(endpoint),
            transport: transport.unwrap_or_else(|| Arc::new(HttpTransport::new())),
        }
    }

    /// The endpoint requests go to.
    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    async fn send(&self, config: &Config, message: &Message) -> Result<Vec<u8>, Error> {
        let xml = build_envelope(message);
        let signature = md5(&format!("{}{}", md5(&format!("{xml}{}", self.api_key)), self.api_key));
        let request = Request {
            method: "POST".into(),
            url: normalize_url(&self.endpoint).ok_or_else(|| Error::InvalidArgument("Invalid URI.".into()))?,
            headers: vec![
                ("Content-Type".into(), "text/xml".into()),
                ("X-Username".into(), self.username.clone()),
                ("X-Signature".into(), signature),
            ],
            body: xml.into_bytes(),
            connect_timeout: config.connect_timeout,
            timeout: config.timeout,
        };
        let response = self
            .transport
            .send(request)
            .await
            .map_err(|e| Error::exception(format!("Failed to send request to OpenSRS: {e}"), 0))?;
        Ok(response.body)
    }

    /// `sanitizeResponse()`: the document, or the error it reports (codes above 299).
    fn sanitize(body: &[u8]) -> Result<Document, Error> {
        let doc =
            Document::parse(body).ok_or_else(|| Error::exception("OpenSRS returned an invalid XML response", 0))?;
        let code = int(first(&doc, doc.root(), RESPONSE_CODE));
        if code > 299 {
            return Err(Error::exception(first(&doc, doc.root(), RESPONSE_TEXT), code));
        }
        Ok(doc)
    }

    async fn request(&self, config: &Config, message: &Message) -> Result<Document, Error> {
        Self::sanitize(&self.send(config, message).await?)
    }

    fn sanitize_contacts(contacts: &Contacts) -> Result<Item, Error> {
        let fields = |c: &super::Contact| {
            Item::Array(c.fields().iter().map(|(k, v)| ((*k).to_owned(), Item::Str(v.to_string()))).collect())
        };
        if contacts.len() == 1 {
            let Some(contact) = contacts.index(0) else {
                return Err(Error::Engine("Call to a member function toArray() on null".into()));
            };
            let one = fields(contact);
            return Ok(Item::Array(
                ["owner", "admin", "tech", "billing"].map(|role| (role.to_owned(), one.clone())).to_vec(),
            ));
        }
        Ok(Item::Array(contacts.iter().map(|(key, c)| (key.as_string().into_owned(), fields(c))).collect()))
    }

    /// `register()`: an `SW_REGISTER` order; returns its id.
    async fn register(&self, config: &Config, order: Registration<'_>) -> Result<String, Error> {
        let mut attributes = vec![
            attr("domain", s(order.domain)),
            attr("periodYears", Item::Int(order.period_years)),
            attr("contact_set", order.contacts),
            attr("custom_tech_contact", Item::Int(0)),
            attr("custom_nameservers", Item::Int(i64::from(!order.nameservers.is_empty()))),
            attr("reg_username", s(self.username.as_str())),
            attr("reg_password", s(self.password.as_str())),
            attr("reg_type", s(order.reg_type.as_str())),
            attr("handle", s("process")),
            attr("f_whois_privacy", Item::Int(1)),
            attr("auto_renew", Item::Int(i64::from(order.autorenew))),
        ];
        if let Some(code) = order.auth_code.filter(|c| !c.is_empty() && *c != "0") {
            attributes.push(attr("auth_info", s(code)));
        }
        if !order.nameservers.is_empty() {
            attributes.push(attr("nameserver_list", Item::list(order.nameservers.iter().cloned())));
        }
        if let Some(price) = order.price {
            attributes.push(attr("premium_price_to_display", Item::Float(price)));
        }
        let message = Message { object: "DOMAIN", action: "SW_REGISTER", domain: None, attributes };
        let doc = self.request(config, &message).await?;
        let id = doc.xpath(doc.root(), "//data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item[@key=\"id\"]");
        Ok(id.first().map_or("", |&n| doc.text(n)).to_owned())
    }
}

struct Registration<'a> {
    domain: &'a str,
    reg_type: RegType,
    contacts: Item,
    nameservers: &'a [String],
    period_years: i64,
    auth_code: Option<&'a str>,
    price: Option<f64>,
    autorenew: bool,
}

impl Adapter for OpenSrs {
    fn name(&self) -> &str {
        "opensrs"
    }

    async fn available(&self, config: &Config, domains: &[String]) -> Result<Availability, Error> {
        let mut availability = Availability::new();
        for domain in domains {
            if availability.contains_key(domain) {
                continue;
            }
            let message = Message {
                object: "DOMAIN",
                action: "LOOKUP",
                domain: None,
                attributes: vec![attr("domain", s(domain.as_str()))],
            };
            let doc = self.request(config, &message).await?;
            let code = int(first(&doc, doc.root(), RESPONSE_CODE));
            availability.insert(domain.clone(), code == Self::RESPONSE_CODE_DOMAIN_AVAILABLE);
        }
        Ok(availability)
    }

    async fn update_nameservers(
        &self,
        config: &Config,
        domain: &str,
        nameservers: &[String],
    ) -> Result<NameserverUpdate, Error> {
        let message = Message {
            object: "DOMAIN",
            action: "ADVANCED_UPDATE_NAMESERVERS",
            domain: Some(domain.to_owned()),
            attributes: vec![attr("add_ns", Item::list(nameservers.iter().cloned())), attr("op_type", s("add_remove"))],
        };
        let doc = self.request(config, &message).await?;
        Ok(NameserverUpdate {
            code: Some(first(&doc, doc.root(), RESPONSE_CODE).to_owned()),
            text: Some(first(&doc, doc.root(), RESPONSE_TEXT).to_owned()),
            successful: first(&doc, doc.root(), IS_SUCCESS) == "1",
            nameservers: Some(nameservers.to_vec()),
            error: None,
        })
    }

    async fn purchase(&self, config: &Config, purchase: &Purchase) -> Result<String, Error> {
        let attempt = async {
            let nameservers =
                if purchase.nameservers.is_empty() { &config.default_nameservers } else { &purchase.nameservers };
            let contacts = Self::sanitize_contacts(&purchase.contacts)?;
            self.register(
                config,
                Registration {
                    domain: &purchase.domain,
                    reg_type: RegType::New,
                    contacts,
                    nameservers,
                    period_years: purchase.period_years,
                    auth_code: None,
                    price: purchase.price,
                    autorenew: purchase.autorenew,
                },
            )
            .await
        };
        attempt.await.map_err(|e| {
            if matches!(e, Error::Engine(_)) {
                return e;
            }
            let code = e.code();
            let message = format!("Failed to purchase domain: {}", e.message());
            if code == Self::RESPONSE_CODE_DOMAIN_TAKEN {
                Error::DomainTaken { message, code }
            } else if code == Self::RESPONSE_CODE_INVALID_CONTACT && e.message().contains("Invalid data") {
                Error::InvalidContact { message, code }
            } else if code == Self::RESPONSE_CODE_INVALID_CONTACT && e.message().contains("password") {
                Error::Auth { message, code }
            } else {
                Error::Domains { message, code }
            }
        })
    }

    async fn transfer(
        &self,
        config: &Config,
        domain: &str,
        auth_code: &str,
        price: Option<f64>,
    ) -> Result<String, Error> {
        let registration = Registration {
            domain,
            reg_type: RegType::Transfer,
            contacts: Item::Array(Vec::new()),
            nameservers: &[],
            period_years: 1,
            auth_code: Some(auth_code),
            price,
            autorenew: false,
        };
        self.register(config, registration).await.map_err(|e| {
            let code = e.code();
            if code == Self::RESPONSE_CODE_DOMAIN_NOT_TRANSFERABLE {
                let parts: Vec<&str> = e.message().split('\n').collect();
                let reason = parts.get(1).unwrap_or(&parts[0]);
                Error::DomainNotTransferable { message: format!("Domain is not transferable: {reason}"), code }
            } else if code == Self::RESPONSE_CODE_DOMAIN_TAKEN {
                Error::DomainTaken { message: "Domain is already in this account".into(), code }
            } else {
                Error::Domains { message: format!("Failed to transfer domain: {}", e.message()), code }
            }
        })
    }

    async fn cancel_purchase(&self, config: &Config) -> Result<bool, Error> {
        let message = Message {
            object: "ORDER",
            action: "CANCEL_PENDING_ORDERS",
            domain: None,
            attributes: vec![
                attr("to_date", Item::Int(php_std::datetime::time())),
                attr("status", Item::list(["declined", "pending"])),
            ],
        };
        let doc = self.request(config, &message).await?;
        Ok(first(&doc, doc.root(), IS_SUCCESS) == "1")
    }

    async fn suggest(&self, config: &Config, query: &[String], options: &SuggestOptions) -> Result<Suggestions, Error> {
        let (min, max) = (options.price_min, options.price_max);
        let show = |v: Option<i64>| v.map_or(String::new(), |v| v.to_string());
        if let (Some(lo), Some(hi)) = (min, max)
            && lo > hi
        {
            return Err(Error::exception(
                format!("Invalid price range: priceMin ({lo}) must be less than priceMax ({hi})."),
                0,
            ));
        }
        let filter = options.filter;
        if filter == Some(SuggestionKind::Suggestion) && (min.is_some() || max.is_some()) {
            return Err(Error::exception(
                format!(
                    "Invalid price range: priceMin ({}) and priceMax ({}) cannot be set when filterType is 'suggestion'.",
                    show(min),
                    show(max)
                ),
                0,
            ));
        }
        let premium = filter != Some(SuggestionKind::Suggestion);
        let regular = filter != Some(SuggestionKind::Premium);
        let tlds: Vec<String> = options.tlds.iter().map(|t| format!(".{}", bare_tld(t))).collect();
        let mut attributes = vec![
            attr("services", Item::list(["suggestion", "premium", "lookup"])),
            attr("searchstring", s(query.join(" "))),
            attr("skip_registry_lookup", Item::Int(1)),
        ];
        let mut overrides: Vec<(String, Vec<(String, Item)>)> = Vec::new();
        let mut set = |service: &str, key: &str, value: Item| {
            let index = match overrides.iter().position(|(s, _)| s == service) {
                Some(i) => i,
                None => {
                    overrides.push((service.to_owned(), Vec::new()));
                    overrides.len() - 1
                }
            };
            overrides[index].1.push((key.to_owned(), value));
        };
        if !tlds.is_empty() {
            attributes.push(attr("tlds", Item::list(tlds.iter().cloned())));
            if premium {
                set("premium", "tlds", Item::list(tlds.iter().cloned()));
            }
            if regular {
                set("suggestion", "tlds", Item::list(tlds.iter().cloned()));
            }
            set("lookup", "tlds", Item::list(tlds.iter().cloned()));
        }
        let limit = options.limit.filter(|&l| l != 0);
        if let Some(limit) = limit {
            if premium {
                set("premium", "maximum", Item::Int(limit));
            }
            if regular {
                set("suggestion", "maximum", Item::Int(limit));
            }
        }
        if let Some(min) = min {
            set("premium", "price_min", Item::Int(min));
        }
        if let Some(max) = max {
            set("premium", "price_max", Item::Int(max));
        }
        if !overrides.is_empty() {
            attributes.push(attr(
                "service_override",
                Item::Array(overrides.into_iter().map(|(k, v)| (k, Item::Array(v))).collect()),
            ));
        }
        let message = Message { object: "DOMAIN", action: "NAME_SUGGEST", domain: None, attributes };
        let doc = self.request(config, &message).await?;
        let mut items = Suggestions::new();
        let items_path = |service: &str| {
            format!(
                "//body/data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item[@key=\"{service}\"]/dt_assoc/item[@key=\"items\"]/dt_array/item"
            )
        };
        if regular {
            let mut processed = 0;
            for element in doc.xpath(doc.root(), &items_path("suggestion")) {
                if options.limit.is_some_and(|l| processed >= l) {
                    break;
                }
                let domain =
                    doc.xpath(element, "dt_assoc/item[@key=\"domain\"]").first().map(|&n| doc.text(n).to_owned());
                let status = doc
                    .xpath(element, "dt_assoc/item[@key=\"status\"] | dt_assoc/item[@key=\"availability\"]")
                    .first()
                    .map_or(String::new(), |&n| doc.text(n).to_ascii_lowercase());
                if let Some(domain) = domain.filter(|d| !d.is_empty() && d != "0") {
                    items.insert(
                        domain,
                        Suggestion {
                            available: matches!(status.as_str(), "available" | "true" | "1"),
                            price: None,
                            kind: SuggestionKind::Suggestion,
                            renewal_price: None,
                            purchase_type: None,
                        },
                    );
                    processed += 1;
                }
            }
            if filter == Some(SuggestionKind::Suggestion) {
                return Ok(items);
            }
            if let Some(limit) = limit
                && items.len() as i64 >= limit
            {
                items.truncate(usize::try_from(limit).unwrap_or(0));
                return Ok(items);
            }
        }
        if limit.is_none_or(|l| (items.len() as i64) < l) {
            let remaining = limit.map(|l| l - items.len() as i64);
            let mut processed = 0;
            for element in doc.xpath(doc.root(), &items_path("premium")) {
                if remaining.is_some_and(|r| processed >= r) {
                    break;
                }
                let mut domain = None;
                let mut available = false;
                let mut price = None;
                for field in doc.xpath(element, "dt_assoc/item") {
                    let value = doc.text(field);
                    match doc.attribute(field, "key") {
                        "domain" => domain = Some(value.to_owned()),
                        "status" => available = value == "available",
                        "price" => {
                            price = php_std::value::numeric_str(value).map(|n| match n {
                                php_std::value::Number::Int(i) => i as f64,
                                php_std::value::Number::Float(f) => f,
                            })
                        }
                        _ => {}
                    }
                }
                if let Some(domain) = domain.filter(|d| !d.is_empty() && d != "0") {
                    items.insert(
                        domain,
                        Suggestion {
                            available,
                            price,
                            kind: SuggestionKind::Premium,
                            renewal_price: None,
                            purchase_type: None,
                        },
                    );
                    processed += 1;
                }
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
            && let Some(cached) = cache.load(domain, ttl).await
            && let Some(price) = crate::php::at(&cached, "price")
        {
            let premium = crate::php::truthy(crate::php::at(&cached, "premium"));
            return Ok(Price { price: crate::php::float(price), premium });
        }
        let attempt = async {
            let message = Message {
                object: "DOMAIN",
                action: "GET_PRICE",
                domain: None,
                attributes: vec![
                    attr("domain", s(domain)),
                    attr("periodYears", Item::Int(period_years)),
                    attr("reg_type", s(reg_type.as_str())),
                ],
            };
            let doc = self.request(config, &message).await?;
            let found = doc.xpath(
                doc.root(),
                "//body/data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item[@key=\"price\"]",
            );
            let Some(&node) = found.first() else {
                return Err(Error::PriceNotFound {
                    message: format!("Price not found for domain: {domain}"),
                    code: Self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND,
                });
            };
            let price = Price { price: php_std::format::str_to_float(doc.text(node).as_bytes()), premium: false };
            if let Some(cache) = &config.cache {
                cache.save(domain, &serde_json::json!({"price": price.price, "premium": price.premium})).await;
            }
            Ok(price)
        };
        attempt.await.map_err(|e| {
            let code = e.code();
            let message = format!("Failed to get price for domain: {}", e.message());
            if code == Self::RESPONSE_CODE_DOMAIN_PRICE_NOT_FOUND {
                Error::PriceNotFound { message, code }
            } else {
                Error::Domains { message, code }
            }
        })
    }

    async fn tlds(&self, _config: &Config) -> Result<Vec<String>, Error> {
        Ok(Vec::new())
    }

    async fn domain(&self, config: &Config, domain: &str) -> Result<Domain, Error> {
        let message = Message {
            object: "DOMAIN",
            action: "GET",
            domain: Some(domain.to_owned()),
            attributes: vec![attr("type", s("all_info")), attr("clean_ca_subset", Item::Int(1))],
        };
        let doc = self.request(config, &message).await?;
        let mut result = Domain {
            domain: domain.to_owned(),
            created_at: None,
            expires_at: None,
            auto_renew: None,
            nameservers: None,
        };
        for element in doc.xpath(doc.root(), ATTRIBUTE_ITEMS) {
            let value = doc.text(element);
            match doc.attribute(element, "key") {
                "registry_createdate" => result.created_at = Some(datetime(value)?),
                "registry_expiredate" => result.expires_at = Some(datetime(value)?),
                "auto_renew" => result.auto_renew = Some(value == "1"),
                "nameserver_list" => {
                    let mut list = Vec::new();
                    for nameserver in doc.xpath(element, "dt_array/item/dt_assoc") {
                        if let Some(&name) = doc.xpath(nameserver, "item[@key=\"name\"]").first() {
                            let name = doc.text(name).trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
                            if !name.is_empty() && name != "0" {
                                list.push(name.to_owned());
                            }
                        }
                    }
                    result.nameservers = Some(list);
                }
                _ => {}
            }
        }
        Ok(result)
    }

    async fn update_domain(&self, config: &Config, domain: &str, details: &UpdateDetails) -> Result<bool, Error> {
        let Some(auto_renew) = details.auto_renew else {
            return Err(Error::domains("Details must include autoRenew", 400));
        };
        let message = Message {
            object: "DOMAIN",
            action: "MODIFY",
            domain: Some(domain.to_owned()),
            attributes: vec![
                attr("data", s("expire_action")),
                attr("affect_domains", Item::Int(0)),
                attr("auto_renew", Item::Int(i64::from(auto_renew))),
                attr("let_expire", Item::Int(i64::from(!auto_renew))),
            ],
        };
        let doc = self.request(config, &message).await?;
        let code = doc.xpath(doc.root(), RESPONSE_CODE);
        if let Some(&code) = code.first()
            && int(doc.text(code)) == Self::RESPONSE_CODE_NOTHING_TO_DO
        {
            return Ok(true);
        }
        let details = format!(
            "//body/data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item[@key=\"details\"]/dt_assoc/item[@key=\"{domain}\"]/dt_assoc/item[@key=\"is_success\"]"
        );
        let mut elements = doc.xpath(doc.root(), &details);
        if elements.is_empty() {
            elements = doc.xpath(doc.root(), IS_SUCCESS);
        }
        match elements.first() {
            Some(&node) => Ok(doc.text(node) == "1"),
            None => Err(Error::domains("Failed to update domain: invalid response from OpenSRS", 500)),
        }
    }

    async fn renew(&self, config: &Config, domain: &str, period_years: i64) -> Result<Renewal, Error> {
        let message = Message {
            object: "DOMAIN",
            action: "RENEW",
            domain: None,
            attributes: vec![
                attr("domain", s(domain)),
                attr("auto_renew", Item::Int(0)),
                attr("currentexpirationyear", s("2022")),
                attr("periodYears", Item::Int(period_years)),
                attr("handle", s("process")),
            ],
        };
        let body = self.send(config, &message).await?;
        let doc =
            Document::parse(&body).ok_or_else(|| Error::Engine("Call to a member function xpath() on false".into()))?;
        let mut renewal = Renewal { order_id: None, expires_at: None };
        for item in doc.xpath(doc.root(), ATTRIBUTE_ITEMS) {
            match doc.attribute(item, "key") {
                "registration expiration date" => renewal.expires_at = Some(datetime(doc.text(item))?),
                "order_id" => renewal.order_id = Some(doc.text(item).to_owned()),
                _ => {}
            }
        }
        Ok(renewal)
    }

    async fn auth_code(&self, config: &Config, domain: &str) -> Result<String, Error> {
        let attempt = async {
            let message = Message {
                object: "DOMAIN",
                action: "GET",
                domain: Some(domain.to_owned()),
                attributes: vec![attr("type", s("domain_auth_info"))],
            };
            let doc = self.request(config, &message).await?;
            let found = doc.xpath(
                doc.root(),
                "//body/data_block/dt_assoc/item[@key=\"attributes\"]/dt_assoc/item[@key=\"domain_auth_info\"]",
            );
            match found.first() {
                Some(&node) => Ok(doc.text(node).to_owned()),
                None => Err(Error::domains("Auth code not found in response", 404)),
            }
        };
        attempt.await.map_err(|e| Error::Domains {
            message: format!("Failed to get auth code: {}", e.message()),
            code: e.code(),
        })
    }

    async fn transfer_status(&self, config: &Config, domain: &str) -> Result<TransferStatus, Error> {
        let attempt = async {
            let message = Message {
                object: "DOMAIN",
                action: "CHECK_TRANSFER",
                domain: None,
                attributes: vec![
                    attr("domain", s(domain)),
                    attr("check_status", Item::Int(1)),
                    attr("get_request_address", Item::Int(0)),
                ],
            };
            let doc = self.request(config, &message).await?;
            let (mut transferrable, mut noservice) = (0, 0);
            let (mut reason, mut status, mut timestamp) = (None, None, None);
            for element in doc.xpath(doc.root(), ATTRIBUTE_ITEMS) {
                let value = doc.text(element);
                match doc.attribute(element, "key") {
                    "transferrable" => transferrable = int(value),
                    "noservice" => noservice = int(value),
                    "reason" => reason = Some(value.to_owned()),
                    "status" => status = Some(value.to_owned()),
                    "timestamp" => timestamp = Some(datetime(value)?),
                    _ => {}
                }
            }
            let state = if noservice == 1 {
                TransferState::ServiceUnavailable
            } else if transferrable == 1 {
                TransferState::Transferrable
            } else {
                match status.as_deref() {
                    Some("pending_owner") => TransferState::PendingOwner,
                    Some("pending_admin") => TransferState::PendingAdmin,
                    Some("pending_registry") => TransferState::PendingRegistry,
                    Some("completed") => TransferState::Completed,
                    Some("cancelled") => TransferState::Cancelled,
                    _ => TransferState::NotTransferrable,
                }
            };
            Ok::<_, Error>(TransferStatus { status: state, reason, timestamp })
        };
        attempt.await.map_err(|e| Error::Domains {
            message: format!("Failed to check transfer status: {}", e.message()),
            code: e.code(),
        })
    }
}
