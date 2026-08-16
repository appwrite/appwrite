//! PHP `Utopia\Cdn\Domain`.

use crate::CdnError;

/// Hostname and cache-path checks used by every purge and certificate call.
#[derive(Debug, Clone, Copy)]
pub struct Domain;

impl Domain {
    /// PHP `Domain::validate`.
    ///
    /// Lowercase hostname without a scheme, port, path, or trailing slash.
    /// Matches `filter_var(..., FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)`.
    pub fn validate(domain: &str) -> Result<String, CdnError> {
        if domain.is_empty() || domain != domain.to_lowercase() || !is_hostname(domain) {
            return Err(CdnError::invalid(
                "Domain must be a lowercase hostname without a scheme, port, path, or trailing slash.",
            ));
        }
        Ok(domain.to_owned())
    }

    /// PHP `Domain::validatePaths`.
    pub fn validate_paths(paths: &[String]) -> Result<Vec<String>, CdnError> {
        for path in paths {
            if !path.starts_with('/') {
                return Err(CdnError::invalid(
                    "Every cache path must be a string beginning with \"/\".",
                ));
            }
        }
        Ok(paths.to_vec())
    }
}

fn is_hostname(domain: &str) -> bool {
    // PHP FILTER_VALIDATE_DOMAIN allows one trailing dot (FQDN); FILTER_FLAG_HOSTNAME
    // still requires each label to be a hostname token. A trailing slash/scheme/port
    // is rejected by the label rules (`/`, `:`) or empty labels.
    let host = domain.strip_suffix('.').unwrap_or(domain);
    if host.is_empty() || host.len() > 253 {
        return false;
    }
    if host.contains('/') || host.contains(':') || host.contains(' ') {
        return false;
    }
    host.split('.').all(is_label)
}

fn is_label(label: &str) -> bool {
    if label.is_empty() || label.len() > 63 {
        return false;
    }
    let bytes = label.as_bytes();
    if bytes[0] == b'-' || bytes[bytes.len() - 1] == b'-' {
        return false;
    }
    bytes
        .iter()
        .all(|b| b.is_ascii_alphanumeric() || *b == b'-')
}
