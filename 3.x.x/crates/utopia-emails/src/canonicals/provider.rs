use crate::EmailError;

/// Canonical local + domain pair (PHP `getCanonical()` array with `local` / `domain` keys).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Canonical {
    /// Normalized local part.
    pub local: String,
    /// Normalized domain part.
    pub domain: String,
}

/// Email provider normalization (PHP `Utopia\Emails\Canonicals\Provider`).
pub trait Provider: Send + Sync + std::fmt::Debug {
    /// Whether this provider handles `domain`.
    fn supports(&self, domain: &str) -> bool;

    /// Canonical local + domain according to provider rules.
    fn get_canonical(&self, local: &str, domain: &str) -> Result<Canonical, EmailError>;

    /// Canonical domain for this provider (`''` for [`super::Generic`]).
    fn get_canonical_domain(&self) -> &'static str;

    /// Supported domains (empty for [`super::Generic`]).
    fn get_supported_domains(&self) -> &'static [&'static str];
}

/// PHP `Provider::removePlusAddressing()`.
pub fn remove_plus_addressing(local: &str) -> String {
    match local.find('+') {
        Some(pos) if pos > 0 => local[..pos].to_string(),
        _ => local.to_string(),
    }
}

/// PHP `Provider::removeDots()`.
pub fn remove_dots(local: &str) -> String {
    local.replace('.', "")
}

/// PHP `Provider::removeHyphens()`.
#[allow(dead_code)]
pub fn remove_hyphens(local: &str) -> String {
    local.replace('-', "")
}

/// PHP `Provider::removeHyphenSubaddress()` - everything after the last hyphen.
pub fn remove_hyphen_subaddress(local: &str) -> String {
    let components: Vec<&str> = local.split('-').collect();
    if components.len() > 1 {
        components[..components.len() - 1].join("-")
    } else {
        components[0].to_string()
    }
}

/// PHP `Provider::toLowerCase()` (`strtolower`).
pub fn to_lower_case(local: &str) -> String {
    local.to_ascii_lowercase()
}

pub(crate) fn require_non_empty_local(local: String) -> Result<String, EmailError> {
    // PHP `empty($normalizedLocal)` is true for `""` and `"0"`.
    if local.is_empty() || local == "0" {
        Err(EmailError::EmptyLocalAfterNormalization)
    } else {
        Ok(local)
    }
}
