//! Canonical forms of addresses per mail provider.
//!
//! PHP: `Utopia\Emails\Canonicals\Provider`, `Providers\*`.

use std::fmt;

use php_std::string;

use crate::Error;

/// The canonical form of an address: its local part and domain with a
/// provider's aliases removed.
///
/// PHP: the array `['local' => ..., 'domain' => ...]`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Canonical {
    /// The part before the `@`.
    pub local: String,
    /// The part after the `@`.
    pub domain: String,
}

impl fmt::Display for Canonical {
    /// Writes the address, `local@domain`.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}@{}", self.local, self.domain)
    }
}

/// A mail provider's aliasing rules. Implement it to add a provider;
/// [`crate::Email::canonical_with`] takes any set of them.
///
/// PHP: `Utopia\Emails\Canonicals\Provider`.
pub trait Provider: Send + Sync {
    /// Whether the domain belongs to this provider.
    ///
    /// PHP: `supports($domain)`.
    fn supports(&self, domain: &str) -> bool {
        self.supported_domains().contains(&domain)
    }

    /// The address with the provider's aliases removed.
    ///
    /// PHP: `getCanonical($local, $domain)`.
    fn canonical(&self, local: &str, domain: &str) -> Result<Canonical, Error>;

    /// The domain canonical addresses of this provider use (`gmail.com`).
    ///
    /// PHP: `getCanonicalDomain()`.
    fn canonical_domain(&self) -> &str;

    /// The domains that belong to this provider.
    ///
    /// PHP: `getSupportedDomains()`.
    fn supported_domains(&self) -> &[&str];
}

/// The providers [`crate::Email`] consults, in order.
///
/// PHP: the same list, in the same order, as PHP consults.
pub static PROVIDERS: &[&dyn Provider] = &[&Gmail, &Outlook, &Yahoo, &Icloud, &Protonmail, &Fastmail, &Walla];

/// Lower-cases ASCII letters only.
///
/// PHP: `toLowerCase()`, PHP `strtolower`.
fn lower(local: &str) -> String {
    let lowered = string::strtolower(local.as_bytes());
    String::from_utf8(lowered.into_owned()).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

/// Drops everything from the first `+`, unless the local part starts with it.
///
/// PHP: `removePlusAddressing()`.
fn without_plus(mut local: String) -> String {
    if let Some(p) = local.find('+')
        && p > 0
    {
        local.truncate(p);
    }
    local
}

/// Drops every dot.
///
/// PHP: `removeDots()`.
fn without_dots(local: String) -> String {
    if local.contains('.') { local.replace('.', "") } else { local }
}

/// Whether a string is empty or `"0"`.
///
/// PHP: `empty()` of a string.
fn empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

fn not_empty(local: String) -> Result<String, Error> {
    if empty(&local) {
        return Err(Error::InvalidArgument("Email local part cannot be empty after normalization".into()));
    }
    Ok(local)
}

fn canonical(local: String, domain: &str) -> Result<Canonical, Error> {
    Ok(Canonical { local, domain: domain.to_owned() })
}

/// Gmail: drops `+tags` and all dots; `googlemail.com` is `gmail.com`.
///
/// PHP: `Providers\Gmail`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Gmail;

impl Provider for Gmail {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(not_empty(without_dots(without_plus(lower(local))))?, self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "gmail.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["gmail.com", "googlemail.com"]
    }
}

/// Outlook, Hotmail, Live, MSN and Passport: drops `+tags`, keeps dots, and
/// uses `outlook.com`.
///
/// PHP: `Providers\Outlook`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Outlook;

impl Provider for Outlook {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(not_empty(without_plus(lower(local)))?, self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "outlook.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &[
            "outlook.com",
            "outlook.at",
            "outlook.be",
            "outlook.cl",
            "outlook.co.il",
            "outlook.co.nz",
            "outlook.co.th",
            "outlook.co.uk",
            "outlook.com.ar",
            "outlook.com.au",
            "outlook.com.br",
            "outlook.com.gr",
            "outlook.com.pe",
            "outlook.com.tr",
            "outlook.com.vn",
            "outlook.cz",
            "outlook.de",
            "outlook.dk",
            "outlook.es",
            "outlook.fr",
            "outlook.hu",
            "outlook.id",
            "outlook.ie",
            "outlook.in",
            "outlook.it",
            "outlook.jp",
            "outlook.kr",
            "outlook.lv",
            "outlook.my",
            "outlook.ph",
            "outlook.pt",
            "outlook.sa",
            "outlook.sg",
            "outlook.sk",
            "hotmail.com",
            "hotmail.at",
            "hotmail.be",
            "hotmail.ca",
            "hotmail.cl",
            "hotmail.co.il",
            "hotmail.co.nz",
            "hotmail.co.th",
            "hotmail.co.uk",
            "hotmail.com.ar",
            "hotmail.com.au",
            "hotmail.com.br",
            "hotmail.com.gr",
            "hotmail.com.mx",
            "hotmail.com.pe",
            "hotmail.com.tr",
            "hotmail.com.vn",
            "hotmail.cz",
            "hotmail.de",
            "hotmail.dk",
            "hotmail.es",
            "hotmail.fr",
            "hotmail.hu",
            "hotmail.id",
            "hotmail.ie",
            "hotmail.in",
            "hotmail.it",
            "hotmail.jp",
            "hotmail.kr",
            "hotmail.lv",
            "hotmail.my",
            "hotmail.ph",
            "hotmail.pt",
            "hotmail.sa",
            "hotmail.sg",
            "hotmail.sk",
            "live.com",
            "live.be",
            "live.co.uk",
            "live.com.ar",
            "live.com.mx",
            "live.de",
            "live.es",
            "live.eu",
            "live.fr",
            "live.it",
            "live.nl",
            "msn.com",
            "passport.com",
        ]
    }
}

/// Yahoo, Ymail and Rocketmail: keeps the local part (a `-keyword` is a
/// separate disposable address, not an alias) and uses `yahoo.com`.
///
/// PHP: `Providers\Yahoo`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Yahoo;

impl Provider for Yahoo {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(lower(local), self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "yahoo.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &[
            "yahoo.com",
            "yahoo.co.uk",
            "yahoo.ca",
            "yahoo.de",
            "yahoo.fr",
            "yahoo.in",
            "yahoo.it",
            "ymail.com",
            "rocketmail.com",
        ]
    }
}

/// iCloud, me.com and mac.com: drops `+tags`, keeps dots, and uses
/// `icloud.com`.
///
/// PHP: `Providers\Icloud`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Icloud;

impl Provider for Icloud {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(not_empty(without_plus(lower(local)))?, self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "icloud.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["icloud.com", "me.com", "mac.com"]
    }
}

/// Proton Mail: drops `+tags`, keeps dots and the domain (each Proton domain
/// is a separate address).
///
/// PHP: `Providers\Protonmail`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Protonmail;

impl Provider for Protonmail {
    fn canonical(&self, local: &str, domain: &str) -> Result<Canonical, Error> {
        let domain = if self.supports(domain) { domain } else { self.canonical_domain() };
        canonical(without_plus(lower(local)), domain)
    }

    fn canonical_domain(&self) -> &str {
        "protonmail.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["protonmail.com", "proton.me", "pm.me", "protonmail.ch"]
    }
}

/// Fastmail: keeps the local part and uses `fastmail.com`.
///
/// PHP: `Providers\Fastmail`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Fastmail;

impl Provider for Fastmail {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(lower(local), self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "fastmail.com"
    }

    fn supported_domains(&self) -> &[&str] {
        &["fastmail.com", "fastmail.fm"]
    }
}

/// Yandex: keeps the local part and uses `yandex.ru`. Not in [`PROVIDERS`].
///
/// PHP: `Providers\Yandex`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Yandex;

impl Provider for Yandex {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(lower(local), self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "yandex.ru"
    }

    fn supported_domains(&self) -> &[&str] {
        &["yandex.ru", "yandex.ua", "yandex.kz", "yandex.com", "yandex.by", "ya.ru"]
    }
}

/// Walla: keeps the local part; `walla.com` is `walla.co.il`.
///
/// PHP: `Providers\Walla`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Walla;

impl Provider for Walla {
    fn canonical(&self, local: &str, _domain: &str) -> Result<Canonical, Error> {
        canonical(lower(local), self.canonical_domain())
    }

    fn canonical_domain(&self) -> &str {
        "walla.co.il"
    }

    fn supported_domains(&self) -> &[&str] {
        &["walla.co.il", "walla.com"]
    }
}

/// The fallback for any domain: only lower-cases the local part. Its
/// canonical domain is empty and it lists no domains.
///
/// PHP: `Providers\Generic`.
#[derive(Debug, Clone, Copy, Default)]
pub struct Generic;

impl Provider for Generic {
    fn supports(&self, _domain: &str) -> bool {
        true
    }

    fn canonical(&self, local: &str, domain: &str) -> Result<Canonical, Error> {
        canonical(lower(local), domain)
    }

    fn canonical_domain(&self) -> &str {
        ""
    }

    fn supported_domains(&self) -> &[&str] {
        &[]
    }
}
