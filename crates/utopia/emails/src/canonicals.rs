//! Canonical forms of addresses per mail provider
//! (`Utopia\Emails\Canonicals\Provider` and its `Providers\*`).

use std::fmt;

use php_std::string;

use crate::Error;

/// The canonical form of an address (`['local' => ..., 'domain' => ...]`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Canonical {
    pub local: String,
    pub domain: String,
}

impl fmt::Display for Canonical {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}@{}", self.local, self.domain)
    }
}

/// A mail provider's aliasing rules (`Utopia\Emails\Canonicals\Provider`).
/// Implement it to add a provider; [`crate::Email::canonical_with`] takes
/// any set of them.
pub trait Provider: Send + Sync {
    /// `supports($domain)`: whether the domain belongs to this provider.
    fn supports(&self, domain: &str) -> bool {
        self.supported_domains().contains(&domain)
    }

    /// `getCanonical($local, $domain)`: the address with the provider's
    /// aliases removed.
    fn canonical(&self, local: &str, domain: &str) -> Result<Canonical, Error>;

    /// `getCanonicalDomain()`.
    fn canonical_domain(&self) -> &str;

    /// `getSupportedDomains()`.
    fn supported_domains(&self) -> &[&str];
}

/// The providers [`crate::Email`] consults, in order. `Yandex` is not
/// among them, as in PHP.
pub static PROVIDERS: &[&dyn Provider] = &[&Gmail, &Outlook, &Yahoo, &Icloud, &Protonmail, &Fastmail, &Walla];

/// `toLowerCase()`: PHP `strtolower` (ASCII only).
fn lower(local: &str) -> String {
    let lowered = string::strtolower(local.as_bytes());
    String::from_utf8(lowered.into_owned()).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

/// `removePlusAddressing()`: drops everything from the first `+`, unless
/// the local part starts with it.
fn without_plus(mut local: String) -> String {
    if let Some(p) = local.find('+')
        && p > 0
    {
        local.truncate(p);
    }
    local
}

/// `removeDots()`.
fn without_dots(local: String) -> String {
    if local.contains('.') { local.replace('.', "") } else { local }
}

/// PHP `empty()` of a string.
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

/// `Providers\Gmail`: drops `+tags` and all dots; `googlemail.com` is `gmail.com`.
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

/// `Providers\Outlook`: Outlook, Hotmail, Live, MSN and Passport; drops
/// `+tags`, keeps dots.
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

/// `Providers\Yahoo`: keeps the local part (a `-keyword` is a separate
/// disposable address, not an alias).
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

/// `Providers\Icloud`: iCloud, me.com, mac.com; drops `+tags`, keeps dots.
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

/// `Providers\Protonmail`: drops `+tags`, keeps dots and the domain (each
/// Proton domain is a separate address).
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

/// `Providers\Fastmail`: keeps the local part.
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

/// `Providers\Yandex`: keeps the local part.
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

/// `Providers\Walla`: keeps the local part; `walla.com` is `walla.co.il`.
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

/// `Providers\Generic`: any domain; only lower-cases the local part.
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
