//! The web origins allowed to run passkey ceremonies for a relying party.

use crate::oauth2::parse_url;

/// The web origins allowed to run ceremonies for a relying party: HTTPS on
/// the RP ID or a subdomain, with no path, query, fragment or credentials;
/// HTTP only for `localhost`.
///
/// PHP: `Utopia\Auth\Passkeys\Origin`.
///
/// ```
/// use utopia_auth::passkeys::Origin;
///
/// let origin = Origin::new("example.com");
/// assert_eq!(origin.normalize(b"https://app.example.com:443"), Some("https://app.example.com".to_owned()));
/// assert_eq!(origin.normalize(b"http://app.example.com"), None);
/// ```
///
/// ```php
/// use Utopia\Auth\Passkeys\Origin;
///
/// $origin = new Origin('example.com');
/// echo $origin->normalize('https://app.example.com:443'), "\n"; // https://app.example.com
/// var_dump($origin->normalize('http://app.example.com'));       // NULL
/// ```
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Origin {
    rp_id: String,
}

/// The only host allowed over plain HTTP, when it is also the RP ID.
///
/// PHP: `Origin::LOCALHOST`.
pub const LOCALHOST: &str = "localhost";

impl Origin {
    /// The origin rules for the relying party ID `rp_id`.
    ///
    /// PHP: `new Origin($rpId)`.
    pub fn new(rp_id: impl Into<String>) -> Self {
        Self { rp_id: rp_id.into() }
    }

    /// A sentence describing a valid origin, for a validation error message.
    ///
    /// PHP: `getDescription()`.
    pub fn description(&self) -> String {
        format!(
            "Origin must be an HTTPS URL without a path, such as \"https://{0}\", on \"{0}\" or one of its subdomains. HTTP is only allowed when the relying party ID is \"localhost\".",
            self.rp_id
        )
    }

    /// The origin as `scheme://host[:port]` without a default port, or `None`
    /// when it is not allowed.
    ///
    /// PHP: `normalize($origin)`.
    pub fn normalize(&self, origin: &[u8]) -> Option<String> {
        let parts = parse_url(origin)?;
        let (scheme, host) = (parts.scheme?, parts.host?);
        if parts.user.is_some() || parts.pass.is_some() || parts.query.is_some() || parts.fragment.is_some() {
            return None;
        }
        if !matches!(parts.path.as_deref(), None | Some(b"") | Some(b"/"))
            || origin.ends_with(b"?")
            || origin.ends_with(b"#")
        {
            return None;
        }
        if scheme != scheme.to_ascii_lowercase() || host != host.to_ascii_lowercase() {
            return None;
        }
        let rp = self.rp_id.as_bytes();
        let secure = scheme == b"https";
        let local = scheme == b"http" && host == LOCALHOST.as_bytes() && rp == LOCALHOST.as_bytes();
        if !secure && !local {
            return None;
        }
        let mut suffix = b".".to_vec();
        suffix.extend_from_slice(rp);
        if host != rp && !host.ends_with(&suffix) {
            return None;
        }
        let scheme = String::from_utf8_lossy(&scheme);
        let host = String::from_utf8_lossy(&host);
        let default = if scheme == "https" { 443 } else { 80 };
        Some(match parts.port {
            None => format!("{scheme}://{host}"),
            Some(p) if p == default => format!("{scheme}://{host}"),
            Some(p) => format!("{scheme}://{host}:{p}"),
        })
    }
}
