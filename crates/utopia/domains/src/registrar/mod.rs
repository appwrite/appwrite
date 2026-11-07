//! Domain registrars: search, price, buy, renew and transfer domains through
//! an [`Adapter`] ([`Mock`], [`NameCom`], [`OpenSrs`]), driven by a
//! [`Registrar`].

mod mock;
mod namecom;
mod opensrs;
mod xml;

use std::borrow::Cow;
use std::future::Future;
use std::time::Duration;

use indexmap::IndexMap;
use php_std::datetime::DateTime;

pub use mock::Mock;
pub use namecom::NameCom;
pub use opensrs::OpenSrs;

use crate::{Cache, Error};

/// Availability of each domain asked about, in the order asked.
pub type Availability = IndexMap<String, bool>;

/// Suggested domains, in the order the registrar ranked them.
pub type Suggestions = IndexMap<String, Suggestion>;

/// The kind of registration a price is for.
///
/// PHP: `Registrar::REG_TYPE_*`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub enum RegType {
    /// `new`: a first registration.
    #[default]
    New,
    /// `transfer`: a transfer from another registrar.
    Transfer,
    /// `renewal`: a renewal of a registration.
    Renewal,
    /// `trade`: a change of owner.
    Trade,
}

impl RegType {
    /// The registrar's name for it (`new`, `transfer`, `renewal`, `trade`).
    pub const fn as_str(self) -> &'static str {
        match self {
            RegType::New => "new",
            RegType::Transfer => "transfer",
            RegType::Renewal => "renewal",
            RegType::Trade => "trade",
        }
    }

    /// The type a name stands for.
    pub fn parse(name: &str) -> Option<Self> {
        Some(match name {
            "new" => RegType::New,
            "transfer" => RegType::Transfer,
            "renewal" => RegType::Renewal,
            "trade" => RegType::Trade,
            _ => return None,
        })
    }
}

/// A contact of a domain registration (owner, admin, tech or billing).
///
/// ```
/// use utopia_domains::registrar::Contact;
///
/// let contact = Contact { firstname: "Ada".into(), lastname: "Lovelace".into(), ..Contact::default() };
/// assert_eq!(contact.owner(), "Ada Lovelace");
/// ```
///
/// ```php
/// use Utopia\Domains\Registrar\Contact;
///
/// $contact = new Contact('Ada', 'Lovelace', '', '', '', '', '', '', '', '', '', '');
/// echo $contact->toArray()['owner'], "\n"; // Ada Lovelace
/// ```
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Contact {
    /// Given name.
    pub firstname: String,
    /// Family name.
    pub lastname: String,
    /// Phone number, international format (`+18031234567`).
    pub phone: String,
    /// Email address.
    pub email: String,
    /// First address line.
    pub address1: String,
    /// Second address line.
    pub address2: String,
    /// Third address line.
    pub address3: String,
    /// City.
    pub city: String,
    /// State or province.
    pub state: String,
    /// Country code (`US`).
    pub country: String,
    /// Postal code.
    pub postalcode: String,
    /// Organization.
    pub org: String,
    /// The registrant's name; `firstname lastname` when not set.
    pub owner: Option<String>,
}

impl Contact {
    /// The registrant's name: `owner`, else `firstname lastname`.
    pub fn owner(&self) -> Cow<'_, str> {
        match &self.owner {
            Some(owner) => Cow::Borrowed(owner),
            None => Cow::Owned(format!("{} {}", self.firstname, self.lastname)),
        }
    }

    /// Every field by name, in order, `owner` resolved.
    ///
    /// PHP: `toArray()`.
    pub fn fields(&self) -> [(&'static str, Cow<'_, str>); 13] {
        [
            ("firstname", Cow::Borrowed(&self.firstname)),
            ("lastname", Cow::Borrowed(&self.lastname)),
            ("phone", Cow::Borrowed(&self.phone)),
            ("email", Cow::Borrowed(&self.email)),
            ("address1", Cow::Borrowed(&self.address1)),
            ("address2", Cow::Borrowed(&self.address2)),
            ("address3", Cow::Borrowed(&self.address3)),
            ("city", Cow::Borrowed(&self.city)),
            ("state", Cow::Borrowed(&self.state)),
            ("country", Cow::Borrowed(&self.country)),
            ("postalcode", Cow::Borrowed(&self.postalcode)),
            ("org", Cow::Borrowed(&self.org)),
            ("owner", self.owner()),
        ]
    }
}

/// Where a contact sits in [`Contacts`]: a position or a role
/// (`owner`, `admin`, `tech`, `billing`, `registrant`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum ContactKey {
    /// A position (`0` for the first contact of a list).
    Index(i64),
    /// A role name.
    Role(String),
}

impl ContactKey {
    /// The key as a string (positions in decimal).
    pub fn as_string(&self) -> Cow<'_, str> {
        match self {
            ContactKey::Index(i) => Cow::Owned(i.to_string()),
            ContactKey::Role(r) => Cow::Borrowed(r),
        }
    }
}

/// The contacts of a registration: one contact for every role, a list, or
/// contacts by role.
///
/// ```
/// use utopia_domains::registrar::{Contact, Contacts};
///
/// let contact = Contact { firstname: "Ada".into(), ..Contact::default() };
/// let one = Contacts::from(contact.clone());
/// let by_role = Contacts::from_iter([("owner", contact.clone()), ("admin", contact)]);
/// assert_eq!((one.len(), by_role.len()), (1, 2));
/// ```
///
/// ```php
/// use Utopia\Domains\Registrar\Contact;
///
/// $contact = new Contact('Ada', '', '', '', '', '', '', '', '', '', '', '');
/// $byRole = ['owner' => $contact, 'admin' => $contact];
/// echo count($byRole), "\n"; // 2
/// ```
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Contacts(pub Vec<(ContactKey, Contact)>);

impl Contacts {
    /// The number of contacts.
    pub fn len(&self) -> usize {
        self.0.len()
    }

    /// Whether there is none.
    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
    }

    /// The contact under a key.
    ///
    /// PHP: `$contacts[$key]`.
    pub fn get(&self, key: &ContactKey) -> Option<&Contact> {
        self.0.iter().find(|(k, _)| k == key).map(|(_, c)| c)
    }

    /// The contact under a role.
    pub fn role(&self, role: &str) -> Option<&Contact> {
        self.0.iter().find(|(k, _)| matches!(k, ContactKey::Role(r) if r == role)).map(|(_, c)| c)
    }

    /// The contact at a position key.
    pub fn index(&self, index: i64) -> Option<&Contact> {
        self.get(&ContactKey::Index(index))
    }

    /// The contacts in order.
    pub fn iter(&self) -> impl Iterator<Item = &(ContactKey, Contact)> {
        self.0.iter()
    }
}

impl From<Contact> for Contacts {
    /// One contact for every role, at position `0`.
    fn from(contact: Contact) -> Self {
        Contacts(vec![(ContactKey::Index(0), contact)])
    }
}

impl From<Vec<Contact>> for Contacts {
    /// A list of contacts, keyed by position.
    fn from(contacts: Vec<Contact>) -> Self {
        Contacts(contacts.into_iter().enumerate().map(|(i, c)| (ContactKey::Index(i as i64), c)).collect())
    }
}

impl<S: Into<String>> FromIterator<(S, Contact)> for Contacts {
    /// Contacts by role (`owner`, `admin`, `tech`, `billing`).
    fn from_iter<I: IntoIterator<Item = (S, Contact)>>(iter: I) -> Self {
        Contacts(iter.into_iter().map(|(role, c)| (ContactKey::Role(role.into()), c)).collect())
    }
}

/// A registered domain.
///
/// PHP: `Registrar\Domain`.
#[derive(Debug, Clone)]
pub struct Domain {
    /// The domain name.
    pub domain: String,
    /// When it was registered.
    pub created_at: Option<DateTime>,
    /// When the registration expires.
    pub expires_at: Option<DateTime>,
    /// Whether it renews automatically.
    pub auto_renew: Option<bool>,
    /// The nameservers it delegates to.
    pub nameservers: Option<Vec<String>>,
}

/// A price quote.
///
/// PHP: `Registrar\Price`.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Price {
    /// The price, in the account currency.
    pub price: f64,
    /// Whether the domain is sold at a premium (registry premium or an
    /// aftermarket listing).
    pub premium: bool,
}

/// The outcome of a renewal.
///
/// PHP: `Registrar\Renewal`.
#[derive(Debug, Clone)]
pub struct Renewal {
    /// The registrar's order id.
    pub order_id: Option<String>,
    /// When the registration expires.
    pub expires_at: Option<DateTime>,
}

/// Where a transfer stands.
///
/// PHP: `TransferStatusEnum`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum TransferState {
    /// The domain can be transferred.
    Transferrable,
    /// The domain cannot be transferred.
    NotTransferrable,
    /// Waiting for the owner's approval.
    PendingOwner,
    /// Waiting for the admin contact's approval.
    PendingAdmin,
    /// Waiting for the registry.
    PendingRegistry,
    /// The transfer is done.
    Completed,
    /// The transfer was cancelled or rejected.
    Cancelled,
    /// The registrar cannot transfer this domain.
    ServiceUnavailable,
}

impl TransferState {
    /// The state's name (`transferrable`, `pending_owner`, ...).
    pub const fn as_str(self) -> &'static str {
        match self {
            TransferState::Transferrable => "transferrable",
            TransferState::NotTransferrable => "not_transferrable",
            TransferState::PendingOwner => "pending_owner",
            TransferState::PendingAdmin => "pending_admin",
            TransferState::PendingRegistry => "pending_registry",
            TransferState::Completed => "completed",
            TransferState::Cancelled => "cancelled",
            TransferState::ServiceUnavailable => "service_unavailable",
        }
    }
}

/// The status of a transfer.
///
/// PHP: `Registrar\TransferStatus`.
#[derive(Debug, Clone)]
pub struct TransferStatus {
    /// Where the transfer stands.
    pub status: TransferState,
    /// The registrar's explanation.
    pub reason: Option<String>,
    /// When the transfer was requested.
    pub timestamp: Option<DateTime>,
}

/// What to change on a domain. Adapters refuse details without `auto_renew`.
///
/// PHP: `Registrar\UpdateDetails`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct UpdateDetails {
    /// Whether it renews automatically.
    pub auto_renew: Option<bool>,
}

/// What kind of domain a suggestion is.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SuggestionKind {
    /// `suggestion`: a regular registration.
    Suggestion,
    /// `premium`: a premium or aftermarket domain.
    Premium,
}

impl SuggestionKind {
    /// The kind's name (`suggestion`, `premium`).
    pub const fn as_str(self) -> &'static str {
        match self {
            SuggestionKind::Suggestion => "suggestion",
            SuggestionKind::Premium => "premium",
        }
    }
}

/// One suggested domain.
#[derive(Debug, Clone, PartialEq)]
pub struct Suggestion {
    /// Whether it can be registered.
    pub available: bool,
    /// The registration price, when quoted.
    pub price: Option<f64>,
    /// Regular or premium.
    pub kind: SuggestionKind,
    /// The renewal price, for registrars that quote one (Name.com).
    pub renewal_price: Option<f64>,
    /// How the domain is sold (`registration`, or an aftermarket type), for
    /// registrars that say (Name.com).
    pub purchase_type: Option<String>,
}

/// What [`Adapter::suggest`] looks for besides the query.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct SuggestOptions {
    /// TLDs to search (`com` or `.com`); the registrar's choice when empty.
    pub tlds: Vec<String>,
    /// At most this many suggestions (`0` and `None` leave it to the adapter).
    pub limit: Option<i64>,
    /// Only regular or only premium suggestions; both when `None`.
    pub filter: Option<SuggestionKind>,
    /// Highest premium price.
    pub price_max: Option<i64>,
    /// Lowest premium price.
    pub price_min: Option<i64>,
}

/// A domain to buy, with the details of the registration.
///
/// PHP: the arguments of `purchase()`.
#[derive(Debug, Clone, PartialEq)]
pub struct Purchase {
    /// The domain name.
    pub domain: String,
    /// Owner, admin, tech and billing contacts.
    pub contacts: Contacts,
    /// Years to register for.
    pub period_years: i64,
    /// Nameservers to delegate to; the registrar's defaults when empty.
    pub nameservers: Vec<String>,
    /// Whether to renew automatically.
    pub autorenew: bool,
    /// The quoted price, required for a premium domain.
    pub price: Option<f64>,
}

impl Purchase {
    /// One year, default nameservers, no autorenew, no premium price.
    pub fn new(domain: impl Into<String>, contacts: impl Into<Contacts>) -> Self {
        Self {
            domain: domain.into(),
            contacts: contacts.into(),
            period_years: 1,
            nameservers: Vec::new(),
            autorenew: false,
            price: None,
        }
    }
}

/// The outcome of a nameserver change. Each registrar reports a different
/// subset of the fields.
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct NameserverUpdate {
    /// OpenSRS's response code.
    pub code: Option<String>,
    /// OpenSRS's response text.
    pub text: Option<String>,
    /// Whether the change was made.
    pub successful: bool,
    /// The nameservers now set (absent when the change failed).
    pub nameservers: Option<Vec<String>>,
    /// Why the change failed (Name.com).
    pub error: Option<String>,
}

/// The settings a [`Registrar`] passes its adapter on every call.
///
/// PHP: the settings of `setDefaultNameservers()`, `setCache()`,
/// `setConnectTimeout()` and `setTimeout()`.
#[derive(Debug, Clone)]
pub struct Config {
    /// Nameservers a purchase delegates to when it names none.
    pub default_nameservers: Vec<String>,
    /// Where prices and availability are cached, if anywhere.
    pub cache: Option<Cache>,
    /// The default transport's connection timeout.
    pub connect_timeout: Duration,
    /// The default transport's request timeout.
    pub timeout: Duration,
}

impl Default for Config {
    /// No default nameservers, no cache, 5 s to connect, 10 s per request.
    fn default() -> Self {
        Self {
            default_nameservers: Vec::new(),
            cache: None,
            connect_timeout: Duration::from_secs(5),
            timeout: Duration::from_secs(10),
        }
    }
}

/// A registrar API. Implement it to add a registrar.
///
/// PHP: `Registrar\Adapter`.
pub trait Adapter: Send + Sync {
    /// The adapter's id (`mock`, `namecom`, `opensrs`).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// Whether each domain can be registered.
    fn available(
        &self,
        config: &Config,
        domains: &[String],
    ) -> impl Future<Output = Result<Availability, Error>> + Send;

    /// Registers a domain; returns the order id.
    fn purchase(&self, config: &Config, purchase: &Purchase) -> impl Future<Output = Result<String, Error>> + Send;

    /// Domains to register for a query (its words are joined).
    fn suggest(
        &self,
        config: &Config,
        query: &[String],
        options: &SuggestOptions,
    ) -> impl Future<Output = Result<Suggestions, Error>> + Send;

    /// The TLDs the registrar sells.
    fn tlds(&self, config: &Config) -> impl Future<Output = Result<Vec<String>, Error>> + Send;

    /// A domain of the account.
    fn domain(&self, config: &Config, domain: &str) -> impl Future<Output = Result<Domain, Error>> + Send;

    /// Changes a domain of the account.
    fn update_domain(
        &self,
        config: &Config,
        domain: &str,
        details: &UpdateDetails,
    ) -> impl Future<Output = Result<bool, Error>> + Send;

    /// Changes a domain's nameservers. Adapters that cannot fail with
    /// `Method not implemented`.
    fn update_nameservers(
        &self,
        config: &Config,
        domain: &str,
        nameservers: &[String],
    ) -> impl Future<Output = Result<NameserverUpdate, Error>> + Send {
        let _ = (config, domain, nameservers);
        async { Err(Error::exception("Method not implemented", 0)) }
    }

    /// The price of a registration, renewal or transfer; cached answers
    /// younger than `ttl` seconds are reused.
    fn price(
        &self,
        config: &Config,
        domain: &str,
        period_years: i64,
        reg_type: RegType,
        ttl: i64,
    ) -> impl Future<Output = Result<Price, Error>> + Send;

    /// Renews a domain of the account.
    fn renew(
        &self,
        config: &Config,
        domain: &str,
        period_years: i64,
    ) -> impl Future<Output = Result<Renewal, Error>> + Send;

    /// Transfers a domain in; returns the order id.
    fn transfer(
        &self,
        config: &Config,
        domain: &str,
        auth_code: &str,
        price: Option<f64>,
    ) -> impl Future<Output = Result<String, Error>> + Send;

    /// The EPP authorization code of a domain of the account.
    fn auth_code(&self, config: &Config, domain: &str) -> impl Future<Output = Result<String, Error>> + Send;

    /// Where a transfer stands.
    fn transfer_status(
        &self,
        config: &Config,
        domain: &str,
    ) -> impl Future<Output = Result<TransferStatus, Error>> + Send;

    /// Cancels pending purchase orders.
    fn cancel_purchase(&self, config: &Config) -> impl Future<Output = Result<bool, Error>> + Send;
}

/// A registrar: an [`Adapter`] with its settings.
///
/// PHP: `Utopia\Domains\Registrar`.
///
/// ```
/// use utopia_domains::registrar::{Contact, Mock, Purchase, RegType, Registrar};
///
/// let registrar = Registrar::new(Mock::new());
/// utopia_async::Runtime::new()?.block_on(async {
///     let availability = registrar.available(&["my-app.com".to_owned(), "google.com".to_owned()]).await?;
///     assert_eq!(availability["my-app.com"], true);
///     assert_eq!(availability["google.com"], false);
///
///     let price = registrar.price("my-app.com", 1, RegType::New, 3600).await?;
///     assert_eq!(price.price, 12.99);
///     Ok::<_, utopia_domains::Error>(())
/// })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Domains\Registrar;
/// use Utopia\Domains\Registrar\Adapter\Mock;
///
/// $registrar = new Registrar(new Mock());
/// $availability = $registrar->available(['my-app.com', 'google.com']);
/// var_dump($availability['my-app.com']); // bool(true)
/// var_dump($availability['google.com']); // bool(false)
/// echo $registrar->getPrice('my-app.com')->price, "\n"; // 12.99
/// ```
#[derive(Debug)]
pub struct Registrar<A> {
    adapter: A,
    config: Config,
}

impl<A: Adapter> Registrar<A> {
    /// A registrar with the default settings: no default nameservers, no
    /// cache, 5 s to connect, 10 s per request.
    pub fn new(adapter: A) -> Self {
        Self::with_config(adapter, Config::default())
    }

    /// A registrar with these settings.
    pub fn with_config(adapter: A, config: Config) -> Self {
        Self { adapter, config }
    }

    /// The adapter.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// The settings.
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// The adapter's id (`mock`, `namecom`, `opensrs`).
    ///
    /// PHP: `getName()`.
    pub fn name(&self) -> &str {
        self.adapter.name()
    }

    /// Whether each domain can be registered, in the order asked.
    ///
    /// PHP: `available()`.
    pub async fn available(&self, domains: &[String]) -> Result<Availability, Error> {
        self.adapter.available(&self.config, domains).await
    }

    /// Registers a domain; returns the order id.
    ///
    /// PHP: `purchase()`.
    pub async fn purchase(&self, purchase: &Purchase) -> Result<String, Error> {
        self.adapter.purchase(&self.config, purchase).await
    }

    /// Domains to register for a query (its words are joined), in the order
    /// the registrar ranks them.
    ///
    /// PHP: `suggest()`.
    pub async fn suggest(&self, query: &[String], options: &SuggestOptions) -> Result<Suggestions, Error> {
        self.adapter.suggest(&self.config, query, options).await
    }

    /// The TLDs the registrar sells.
    ///
    /// PHP: `tlds()`.
    pub async fn tlds(&self) -> Result<Vec<String>, Error> {
        self.adapter.tlds(&self.config).await
    }

    /// A domain of the account.
    ///
    /// PHP: `getDomain()`.
    pub async fn domain(&self, domain: &str) -> Result<Domain, Error> {
        self.adapter.domain(&self.config, domain).await
    }

    /// Changes a domain of the account; returns whether it changed.
    ///
    /// PHP: `updateDomain()`.
    pub async fn update_domain(&self, domain: &str, details: &UpdateDetails) -> Result<bool, Error> {
        self.adapter.update_domain(&self.config, domain, details).await
    }

    /// Changes a domain's nameservers.
    ///
    /// PHP: `updateNameservers()`.
    pub async fn update_nameservers(&self, domain: &str, nameservers: &[String]) -> Result<NameserverUpdate, Error> {
        self.adapter.update_nameservers(&self.config, domain, nameservers).await
    }

    /// The price of a registration, renewal or transfer for `period_years`;
    /// cached answers younger than `ttl` seconds are reused.
    ///
    /// PHP: `getPrice()`.
    pub async fn price(&self, domain: &str, period_years: i64, reg_type: RegType, ttl: i64) -> Result<Price, Error> {
        self.adapter.price(&self.config, domain, period_years, reg_type, ttl).await
    }

    /// Renews a domain of the account for `period_years`.
    ///
    /// PHP: `renew()`.
    pub async fn renew(&self, domain: &str, period_years: i64) -> Result<Renewal, Error> {
        self.adapter.renew(&self.config, domain, period_years).await
    }

    /// Transfers a domain in with its authorization code; returns the order
    /// id. `price` is required for a premium domain.
    ///
    /// PHP: `transfer()`.
    pub async fn transfer(&self, domain: &str, auth_code: &str, price: Option<f64>) -> Result<String, Error> {
        self.adapter.transfer(&self.config, domain, auth_code, price).await
    }

    /// The EPP authorization code of a domain of the account.
    ///
    /// PHP: `getAuthCode()`.
    pub async fn auth_code(&self, domain: &str) -> Result<String, Error> {
        self.adapter.auth_code(&self.config, domain).await
    }

    /// Where a transfer of the domain stands.
    ///
    /// PHP: `checkTransferStatus()`.
    pub async fn transfer_status(&self, domain: &str) -> Result<TransferStatus, Error> {
        self.adapter.transfer_status(&self.config, domain).await
    }

    /// Cancels pending purchase orders; returns whether that succeeded.
    ///
    /// PHP: `cancelPurchase()`.
    pub async fn cancel_purchase(&self) -> Result<bool, Error> {
        self.adapter.cancel_purchase(&self.config).await
    }
}

/// A TLD without its leading dots.
///
/// PHP: `ltrim($tld, '.')`.
pub(crate) fn bare_tld(tld: &str) -> &str {
    tld.trim_start_matches('.')
}

/// Parses a date the registrar sent, in UTC.
///
/// PHP: `new DateTime($value)` in UTC.
pub(crate) fn datetime(value: &str) -> Result<DateTime, Error> {
    DateTime::new(value.as_bytes(), None, &php_std::datetime::TzInfo::utc())
        .map_err(|e| Error::DateMalformed(e.message().into_owned()))
}

/// The MD5 digest of `data`, in hex.
///
/// PHP: `md5($data)`.
pub(crate) fn md5(data: &str) -> String {
    php_std::string::hash(b"md5", data.as_bytes()).unwrap_or_default()
}
