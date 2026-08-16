use std::sync::OnceLock;

use utopia_domains::Domain;

use crate::canonicals::{
    Fastmail, Generic, Gmail, Icloud, Outlook, Protonmail, Provider, Walla, Yahoo,
};
use crate::filter::filter_validate_email;
use crate::lists::{disposable_domains, free_domains};
use crate::EmailError;

/// Parsed email address (PHP `Utopia\Emails\Email`).
#[derive(Debug, Clone)]
pub struct Email {
    email: String,
    local: String,
    domain: String,
    domain_instance: Domain,
}

impl Email {
    /// Maximum length for the local part (before `@`).
    pub const LOCAL_MAX_LENGTH: usize = 64;

    /// Maximum length for the domain part (after `@`).
    pub const DOMAIN_MAX_LENGTH: usize = 253;

    /// Full email address (`getFormatted` default).
    pub const FORMAT_FULL: &'static str = "full";

    /// Local part only.
    pub const FORMAT_LOCAL: &'static str = "local";

    /// Domain part only.
    pub const FORMAT_DOMAIN: &'static str = "domain";

    /// Registerable provider domain.
    pub const FORMAT_PROVIDER: &'static str = "provider";

    /// Subdomain labels only.
    pub const FORMAT_SUBDOMAIN: &'static str = "subdomain";

    /// PHP `new Email($email)` - trim + `mb_strtolower`, then split on `@`.
    pub fn new(email: impl AsRef<str>) -> Result<Self, EmailError> {
        let original = email.as_ref();
        let email = php_mb_strtolower(php_trim(original));
        // PHP `empty($this->email)` - true for `""` and `"0"`.
        if php_empty_string(&email) {
            return Err(EmailError::Empty);
        }
        let parts: Vec<&str> = email.split('@').collect();
        if parts.len() != 2 || php_empty_string(parts[0]) || php_empty_string(parts[1]) {
            return Err(EmailError::Invalid {
                email: original.to_string(),
            });
        }
        let local = parts[0].to_string();
        let domain = parts[1].to_string();
        let domain_instance = Domain::new(&domain)?;
        Ok(Self {
            email,
            local,
            domain,
            domain_instance,
        })
    }

    /// Full email address (`get()`).
    pub fn get(&self) -> &str {
        &self.email
    }

    /// Local part before `@` (`getLocal()`).
    pub fn get_local(&self) -> &str {
        &self.local
    }

    /// Domain part after `@` (`getDomain()`).
    pub fn get_domain(&self) -> &str {
        &self.domain
    }

    /// PHP `filter_var($this->email, FILTER_VALIDATE_EMAIL)`.
    pub fn is_valid(&self) -> bool {
        filter_validate_email(&self.email)
    }

    /// Local-part charset / length / dot rules (`hasValidLocal()`).
    pub fn has_valid_local(&self) -> bool {
        if self.local.chars().count() > Self::LOCAL_MAX_LENGTH {
            return false;
        }
        if !self
            .local
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '+' | '-'))
        {
            return false;
        }
        if self.local.contains("..") {
            return false;
        }
        if self.local.starts_with('.') || self.local.ends_with('.') {
            return false;
        }
        true
    }

    /// Domain length, `filter_var('test@'.$domain)`, and known-or-test PSL (`hasValidDomain()`).
    pub fn has_valid_domain(&self) -> bool {
        if self.domain.chars().count() > Self::DOMAIN_MAX_LENGTH {
            return false;
        }
        let probe = format!("test@{}", self.domain);
        if !filter_validate_email(&probe) {
            return false;
        }
        if !self.domain_instance.is_known() && !self.domain_instance.is_test() {
            return false;
        }
        true
    }

    /// Whether the domain is on the disposable list (`isDisposable()`).
    pub fn is_disposable(&self) -> bool {
        disposable_domains().contains(&self.domain)
    }

    /// Whether the domain is free and not disposable (`isFree()`).
    ///
    /// When a domain is on both lists, disposable wins (`is_free` is false).
    pub fn is_free(&self) -> bool {
        if free_domains().contains(&self.domain) && self.is_disposable() {
            return false;
        }
        free_domains().contains(&self.domain)
    }

    /// Neither free nor disposable (`isCorporate()`).
    pub fn is_corporate(&self) -> bool {
        if self.is_free() && self.is_disposable() {
            return false;
        }
        !self.is_free() && !self.is_disposable()
    }

    /// Registerable domain, or the full domain when unknown (`getProvider()`).
    pub fn get_provider(&self) -> String {
        let registerable = self.domain_instance.get_registerable();
        if registerable.is_empty() {
            self.domain.clone()
        } else {
            registerable
        }
    }

    /// Subdomain labels (`getSubdomain()`).
    pub fn get_subdomain(&self) -> String {
        self.domain_instance.get_sub()
    }

    /// Whether [`Self::get_subdomain`] is non-empty (`hasSubdomain()`).
    pub fn has_subdomain(&self) -> bool {
        !self.domain_instance.get_sub().is_empty()
    }

    /// Canonical address after provider-specific alias stripping (`getCanonical()`).
    pub fn get_canonical(&self) -> Result<String, EmailError> {
        let provider = provider_for_domain(&self.domain);
        let canonical = provider.get_canonical(&self.local, &self.domain)?;
        Ok(format!("{}@{}", canonical.local, canonical.domain))
    }

    /// Whether a specific (non-generic) provider supports this domain (`isCanonicalSupported()`).
    pub fn is_canonical_supported(&self) -> bool {
        is_domain_supported(&self.domain)
    }

    /// Canonical domain, or `None` for the generic provider (`getCanonicalDomain()`).
    pub fn get_canonical_domain(&self) -> Option<&'static str> {
        let provider = provider_for_domain(&self.domain);
        let domain = provider.get_canonical_domain();
        if domain.is_empty() {
            None
        } else {
            Some(domain)
        }
    }

    /// Format selector (`getFormatted($format)`). Unknown formats return the full address.
    pub fn get_formatted(&self, format: &str) -> String {
        match format {
            Self::FORMAT_LOCAL => self.local.clone(),
            Self::FORMAT_DOMAIN => self.domain.clone(),
            Self::FORMAT_PROVIDER => self.get_provider(),
            Self::FORMAT_SUBDOMAIN => self.get_subdomain(),
            _ => self.email.clone(),
        }
    }
}

fn providers() -> &'static [&'static dyn Provider] {
    static GMAIL: Gmail = Gmail;
    static OUTLOOK: Outlook = Outlook;
    static YAHOO: Yahoo = Yahoo;
    static ICLOUD: Icloud = Icloud;
    static PROTONMAIL: Protonmail = Protonmail;
    static FASTMAIL: Fastmail = Fastmail;
    static WALLA: Walla = Walla;
    static PROVIDERS: OnceLock<Vec<&'static dyn Provider>> = OnceLock::new();
    PROVIDERS.get_or_init(|| {
        vec![
            &GMAIL,
            &OUTLOOK,
            &YAHOO,
            &ICLOUD,
            &PROTONMAIL,
            &FASTMAIL,
            &WALLA,
        ]
    })
}

fn provider_for_domain(domain: &str) -> &'static dyn Provider {
    static GENERIC: Generic = Generic;
    for provider in providers() {
        if provider.supports(domain) {
            return *provider;
        }
    }
    &GENERIC
}

fn is_domain_supported(domain: &str) -> bool {
    providers().iter().any(|provider| provider.supports(domain))
}

/// PHP `empty($s)` for strings.
fn php_empty_string(s: &str) -> bool {
    s.is_empty() || s == "0"
}

/// PHP `trim()` default charset: space, NL, CR, tab, NUL, vertical tab.
fn php_trim(s: &str) -> &str {
    s.trim_matches(|c: char| matches!(c, ' ' | '\n' | '\r' | '\t' | '\0' | '\u{0B}'))
}

/// PHP `mb_strtolower`.
fn php_mb_strtolower(s: &str) -> String {
    s.to_lowercase()
}
