//! Host of a URI, matching PHP `parse_url($uri, PHP_URL_HOST)`.
//!
//! IPv6 is returned with brackets and **without** compressing the spelling, so
//! `[0:0:0:0:0:0:0:1]` stays distinct from `[::1]` the way PHP `parse_url`
//! (and `Platform::LOOPBACK_ALIASES`) do.

/// Extract the host from `uri`. `None` when there is no `://` authority or
/// the host is empty.
#[must_use]
pub fn url_host(uri: &str) -> Option<String> {
    let uri = uri.trim();
    let after_scheme = uri.split_once("://")?.1;
    let authority = after_scheme.split(['/', '?', '#']).next().unwrap_or("");
    let authority = match authority.rsplit_once('@') {
        Some((_, hostport)) => hostport,
        None => authority,
    };
    if authority.is_empty() {
        return None;
    }
    if let Some(rest) = authority.strip_prefix('[') {
        let end = rest.find(']')?;
        let after = &rest[end + 1..];
        if !(after.is_empty() || after.starts_with(':')) {
            return None;
        }
        return Some(format!("[{}]", &rest[..end]));
    }
    let host = authority.split(':').next().unwrap_or(authority);
    if host.is_empty() {
        None
    } else {
        Some(host.to_string())
    }
}

#[cfg(test)]
mod tests {
    use super::url_host;

    #[test]
    fn extracts_ipv4_and_domain() {
        assert_eq!(
            url_host("https://example.com:443/path"),
            Some("example.com".into())
        );
        assert_eq!(url_host("http://127.0.0.1:5173"), Some("127.0.0.1".into()));
    }

    #[test]
    fn keeps_ipv6_spelling() {
        assert_eq!(url_host("http://[::1]:3000"), Some("[::1]".into()));
        assert_eq!(
            url_host("http://[0:0:0:0:0:0:0:1]"),
            Some("[0:0:0:0:0:0:0:1]".into())
        );
    }

    #[test]
    fn rejects_non_urls() {
        assert_eq!(url_host(""), None);
        assert_eq!(url_host("%%%not-a-url%%%"), None);
        assert_eq!(url_host("exp://"), None);
        assert_eq!(url_host("http://[::1]evil.com"), None);
        assert_eq!(url_host("http://[::1].evil.com"), None);
    }
}
