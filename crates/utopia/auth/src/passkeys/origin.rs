use crate::oauth2::parse_url;

/// The web origins allowed to run ceremonies for a relying party
/// (`Utopia\Auth\Passkeys\Origin`): HTTPS on the RP ID or a subdomain, with
/// no path, query, fragment or credentials; HTTP only for `localhost`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Origin {
    rp_id: String,
}

/// `Origin::LOCALHOST`.
pub const LOCALHOST: &str = "localhost";

impl Origin {
    pub fn new(rp_id: impl Into<String>) -> Self {
        Self { rp_id: rp_id.into() }
    }

    /// `getDescription()`.
    pub fn description(&self) -> String {
        format!(
            "Origin must be an HTTPS URL without a path, such as \"https://{0}\", on \"{0}\" or one of its subdomains. HTTP is only allowed when the relying party ID is \"localhost\".",
            self.rp_id
        )
    }

    /// `normalize($origin)`: `scheme://host[:port]` without default ports, or `None` when not allowed.
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
