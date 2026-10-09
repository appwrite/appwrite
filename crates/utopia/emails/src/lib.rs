//! Email addresses: validation (PHP `FILTER_VALIDATE_EMAIL`), canonical form
//! per provider, and free / disposable / corporate classification.
//!
//! Domain lists are the data files shipped with `packages/emails/data`
//! (`free-domains.php`, `disposable-domains.php`). They are loaded once at
//! startup with [`Domains::load`]; lookups are hash-set membership tests.

use std::collections::HashSet;
use std::path::Path;
use std::sync::OnceLock;

use fancy_regex::Regex;
use serde_json::Value;
use utopia_validators::Validator;

/// Free and disposable domain lists.
#[derive(Debug, Default)]
pub struct Domains {
    free: HashSet<String>,
    disposable: HashSet<String>,
}

static DOMAINS: OnceLock<Domains> = OnceLock::new();

impl Domains {
    /// Parses a PHP `return [ 'a', 'b', ... ];` list file.
    pub fn parse_php_list(content: &str) -> HashSet<String> {
        content
            .lines()
            .filter_map(|line| {
                let l = line.strip_prefix("    '")?.strip_suffix("',")?;
                if l.contains('\'') { None } else { Some(l.to_owned()) }
            })
            .collect()
    }

    /// Loads the lists from `dir` (the `packages/emails/data` directory).
    pub fn load(dir: &Path) -> Self {
        let read = |name: &str| match std::fs::read_to_string(dir.join(name)) {
            Ok(c) => Self::parse_php_list(&c),
            Err(e) => {
                tracing::warn!(file = name, error = %e, "email domain list unavailable");
                HashSet::new()
            }
        };
        Self { free: read("free-domains.php"), disposable: read("disposable-domains.php") }
    }

    pub fn from_sets(free: HashSet<String>, disposable: HashSet<String>) -> Self {
        Self { free, disposable }
    }

    /// Installs the process-wide lists (first call wins).
    pub fn install(self) {
        let _ = DOMAINS.set(self);
    }

    fn global() -> &'static Domains {
        DOMAINS.get_or_init(Domains::default)
    }
}

/// Parse error (`Utopia\Emails\Email` constructor exceptions).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct EmailError(pub String);

/// A parsed email address.
#[derive(Debug, Clone)]
pub struct Email {
    email: String,
    local: String,
    domain: String,
}

fn php_empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

fn php_trim(s: &str) -> &str {
    s.trim_matches(|c| matches!(c, ' ' | '\t' | '\n' | '\r' | '\0' | '\x0B'))
}

impl Email {
    pub fn new(input: &str) -> Result<Self, EmailError> {
        let email = php_trim(input).to_lowercase();
        if php_empty(&email) {
            return Err(EmailError("Email address cannot be empty".into()));
        }
        let parts: Vec<&str> = email.split('@').collect();
        if parts.len() != 2 {
            return Err(EmailError(format!("'{input}' must be a valid email address")));
        }
        let (local, domain) = (parts[0].to_owned(), parts[1].to_owned());
        if php_empty(&local) || php_empty(&domain) {
            return Err(EmailError(format!("'{input}' must be a valid email address")));
        }
        if domain.starts_with("http://") || domain.starts_with("https://") {
            return Err(EmailError(format!("'{domain}' must be a valid domain or hostname")));
        }
        Ok(Self { email, local, domain })
    }

    pub fn get(&self) -> &str {
        &self.email
    }

    pub fn local(&self) -> &str {
        &self.local
    }

    pub fn domain(&self) -> &str {
        &self.domain
    }

    /// `FILTER_VALIDATE_EMAIL` on the normalised address.
    pub fn is_valid(&self) -> bool {
        filter_validate_email(&self.email)
    }

    pub fn is_disposable(&self) -> bool {
        Domains::global().disposable.contains(&self.domain)
    }

    pub fn is_free(&self) -> bool {
        Domains::global().free.contains(&self.domain) && !self.is_disposable()
    }

    pub fn is_corporate(&self) -> bool {
        !self.is_free() && !self.is_disposable()
    }

    /// Canonical address (provider-specific normalisation).
    pub fn canonical(&self) -> Result<String, EmailError> {
        let (local, domain) = canonicalize(&self.local, &self.domain)?;
        Ok(format!("{local}@{domain}"))
    }
}

fn strip_plus(local: &str) -> &str {
    match local.find('+') {
        Some(p) if p > 0 => &local[..p],
        _ => local,
    }
}

const GMAIL: [&str; 2] = ["gmail.com", "googlemail.com"];
const OUTLOOK: [&str; 81] = [
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
];
const OUTLOOK_EXTRA: [&str; 2] = ["msn.com", "passport.com"];
const YAHOO: [&str; 9] = [
    "yahoo.com",
    "yahoo.co.uk",
    "yahoo.ca",
    "yahoo.de",
    "yahoo.fr",
    "yahoo.in",
    "yahoo.it",
    "ymail.com",
    "rocketmail.com",
];
const ICLOUD: [&str; 3] = ["icloud.com", "me.com", "mac.com"];
const PROTON: [&str; 4] = ["protonmail.com", "proton.me", "pm.me", "protonmail.ch"];
const FASTMAIL: [&str; 2] = ["fastmail.com", "fastmail.fm"];
const WALLA: [&str; 2] = ["walla.co.il", "walla.com"];

fn canonicalize(local: &str, domain: &str) -> Result<(String, String), EmailError> {
    let empty = || EmailError("Email local part cannot be empty after normalization".into());
    let d = domain;
    if GMAIL.contains(&d) {
        let l = strip_plus(local).replace('.', "");
        if l.is_empty() {
            return Err(empty());
        }
        return Ok((l, "gmail.com".into()));
    }
    if OUTLOOK.contains(&d) || OUTLOOK_EXTRA.contains(&d) {
        let l = strip_plus(local).to_owned();
        if l.is_empty() {
            return Err(empty());
        }
        return Ok((l, "outlook.com".into()));
    }
    if YAHOO.contains(&d) {
        return Ok((local.to_owned(), "yahoo.com".into()));
    }
    if ICLOUD.contains(&d) {
        let l = strip_plus(local).to_owned();
        if l.is_empty() {
            return Err(empty());
        }
        return Ok((l, "icloud.com".into()));
    }
    if PROTON.contains(&d) {
        return Ok((strip_plus(local).to_owned(), d.to_owned()));
    }
    if FASTMAIL.contains(&d) {
        return Ok((local.to_owned(), "fastmail.com".into()));
    }
    if WALLA.contains(&d) {
        return Ok((local.to_owned(), "walla.co.il".into()));
    }
    Ok((local.to_owned(), d.to_owned()))
}

/// PHP's `FILTER_VALIDATE_EMAIL` (php-src `logical_filters.c`).
pub fn filter_validate_email(email: &str) -> bool {
    static RE: OnceLock<Regex> = OnceLock::new();
    if email.len() > 320 {
        return false;
    }
    let re = RE.get_or_init(|| {
        Regex::new(concat!(
            r"(?i)^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})",
            r"(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)",
            r"(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))",
            r"(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*",
            r"@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)",
            r"|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))",
            r"|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?",
            r"(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))\z"
        ))
        .expect("valid email regex")
    });
    re.is_match(email).unwrap_or(false)
}

/// `Utopia\Emails\Validator\Email`.
#[derive(Debug, Clone, Copy, Default)]
pub struct EmailValidator {
    pub allow_empty: bool,
}

impl Validator for EmailValidator {
    fn description(&self) -> String {
        "Value must be a valid email address".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Value::String(s) = value else {
            return false;
        };
        if self.allow_empty && s.is_empty() {
            return true;
        }
        Email::new(s).map(|e| e.is_valid()).unwrap_or(false)
    }
}

/// Email metadata stored on users (`emailCanonical`, `emailIs*`).
#[derive(Debug, Clone, Default, PartialEq)]
pub struct Metadata {
    pub canonical: Option<String>,
    pub is_canonical: Option<bool>,
    pub is_corporate: Option<bool>,
    pub is_disposable: Option<bool>,
    pub is_free: Option<bool>,
}

impl Metadata {
    /// Computes metadata the way `Users\Base::createUser` does (all `None` on any error).
    pub fn of(email: Option<&str>) -> Self {
        let Ok(parsed) = Email::new(email.unwrap_or("")) else {
            return Self::default();
        };
        let Ok(canonical) = parsed.canonical() else {
            return Self::default();
        };
        Self {
            is_canonical: Some(parsed.get() == canonical),
            canonical: Some(canonical),
            is_corporate: Some(parsed.is_corporate()),
            is_disposable: Some(parsed.is_disposable()),
            is_free: Some(parsed.is_free()),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validation() {
        for ok in
            ["cristiano.ronaldo@manchester-united.co.uk", "a+b@gmail.com", "users.service@updated.com", "x@[127.0.0.1]"]
        {
            assert!(filter_validate_email(ok), "{ok}");
        }
        for bad in ["a@localhost", "plainaddress", "a@b..com", "a b@c.com", "@x.com"] {
            assert!(!filter_validate_email(bad), "{bad}");
        }
        let v = EmailValidator::default();
        assert!(v.is_valid(&Value::String("  John@X.COM ".into())));
        assert!(!v.is_valid(&Value::String(String::new())));
        assert!(EmailValidator { allow_empty: true }.is_valid(&Value::String(String::new())));
    }

    #[test]
    fn canonical() {
        let e = Email::new("John.Doe+tag@GMail.com").unwrap();
        assert_eq!(e.canonical().unwrap(), "johndoe@gmail.com");
        let m = Metadata::of(Some("cristiano.ronaldo@manchester-united.co.uk"));
        assert_eq!(m.canonical.as_deref(), Some("cristiano.ronaldo@manchester-united.co.uk"));
        assert_eq!(m.is_canonical, Some(true));
        assert_eq!(m.is_corporate, Some(true));
        assert_eq!(Metadata::of(None), Metadata::default());
        assert_eq!(Metadata::of(Some(".@gmail.com")), Metadata::default());
    }

    #[test]
    fn lists() {
        let set = Domains::parse_php_list("<?php\nreturn [\n    'gmail.com',\n    'yahoo.com',\n];\n");
        assert!(set.contains("gmail.com"));
        assert_eq!(set.len(), 2);
    }
}
