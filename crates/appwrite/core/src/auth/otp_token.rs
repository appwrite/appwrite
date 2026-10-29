//! A one-time passcode bound to the origin that asked for it, delivered as
//! the `OTP-Token` email header so mail clients and autofill can hand the
//! code to that origin only (`Appwrite\Auth\OTPToken`).
//!
//! See <https://datatracker.ietf.org/doc/draft-goto-otp-token/>.

use serde_json::{Map, Value};

/// The header name (`OTPToken::HEADER`).
pub const HEADER: &str = "OTP-Token";

/// A code bound to a canonical origin.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct OtpToken {
    pub code: String,
    pub origin: String,
}

impl OtpToken {
    /// `OTPToken::from`: binds `code` to `origin`, or `None` when the origin
    /// cannot carry one: the draft accepts only potentially trustworthy
    /// origins in canonical form.
    pub fn from(code: &str, origin: &str) -> Option<Self> {
        if !printable(code) {
            return None;
        }
        Some(Self { code: code.to_owned(), origin: canonicalize(origin)? })
    }

    /// `OTPToken::from(...)?->toHeaders() ?? []`: the mail message's
    /// headers, empty when the origin cannot carry a code.
    pub fn headers(code: &str, origin: &str) -> Map<String, Value> {
        let mut headers = Map::new();
        if let Some(token) = Self::from(code, origin) {
            headers.insert(HEADER.to_owned(), Value::String(token.value()));
        }
        headers
    }

    /// An RFC 9651 Structured Field item: a String with an `origin`
    /// parameter.
    pub fn value(&self) -> String {
        format!("{}; origin={}", string(&self.code), string(&self.origin))
    }
}

/// RFC 6454 ASCII serialization: lowercase, default port omitted, nothing
/// past the authority.
fn canonicalize(origin: &str) -> Option<String> {
    if !printable(origin) {
        return None;
    }
    let (scheme, rest) = origin.split_once("://")?;
    // A user, password, path, query or fragment.
    if rest.contains(['@', '/', '?', '#']) {
        return None;
    }
    let (host, port) = match rest.strip_prefix('[') {
        Some(inner) => {
            let (address, after) = inner.split_once(']')?;
            (format!("[{address}]"), after.strip_prefix(':'))
        }
        None => match rest.split_once(':') {
            Some((host, port)) => (host.to_owned(), Some(port)),
            None => (rest.to_owned(), None),
        },
    };
    let port = match port.filter(|p| !p.is_empty()) {
        None => None,
        Some(p) if p.bytes().all(|b| b.is_ascii_digit()) => Some(p.parse::<u16>().ok()?),
        Some(_) => return None,
    };
    if scheme.is_empty() || host.is_empty() {
        return None;
    }
    let scheme = scheme.to_ascii_lowercase();
    let host = host.to_ascii_lowercase();
    let default = match scheme.as_str() {
        "https" => 443,
        "http" => 80,
        _ => return None,
    };
    if scheme == "http" && !loopback(&host) {
        return None;
    }
    let mut serialized = format!("{scheme}://{host}");
    if let Some(port) = port.filter(|p| *p != default) {
        serialized.push_str(&format!(":{port}"));
    }
    Some(serialized)
}

/// Secure Contexts: plain http is only trustworthy on the local machine.
fn loopback(host: &str) -> bool {
    if host == "localhost" || host.ends_with(".localhost") || host == "[::1]" {
        return true;
    }
    host.starts_with("127.") && host.parse::<std::net::Ipv4Addr>().is_ok()
}

/// `ctype_print` on a non-empty string.
fn printable(value: &str) -> bool {
    !value.is_empty() && value.bytes().all(|b| (0x20..=0x7e).contains(&b))
}

/// A Structured Field String: quoted, with `"` and `\` escaped.
fn string(value: &str) -> String {
    let mut out = String::with_capacity(value.len() + 2);
    out.push('"');
    for c in value.chars() {
        if c == '"' || c == '\\' {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('"');
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn value(code: &str, origin: &str) -> Option<String> {
        OtpToken::from(code, origin).map(|t| t.value())
    }

    #[test]
    fn binds_a_code_to_a_trustworthy_origin() {
        assert_eq!(
            value("123456", "https://example.com").as_deref(),
            Some(r#""123456"; origin="https://example.com""#)
        );
        assert_eq!(value("123456", "http://localhost").as_deref(), Some(r#""123456"; origin="http://localhost""#));
        assert_eq!(
            value("1", "http://app.localhost:3000").as_deref(),
            Some(r#""1"; origin="http://app.localhost:3000""#)
        );
        assert_eq!(value("1", "http://127.0.0.1").as_deref(), Some(r#""1"; origin="http://127.0.0.1""#));
        assert_eq!(value("1", "http://[::1]:8080").as_deref(), Some(r#""1"; origin="http://[::1]:8080""#));
    }

    #[test]
    fn canonicalizes_the_origin() {
        assert_eq!(value("1", "HTTPS://Example.COM:443").as_deref(), Some(r#""1"; origin="https://example.com""#));
        assert_eq!(
            value("1", "https://example.com:8443").as_deref(),
            Some(r#""1"; origin="https://example.com:8443""#)
        );
        assert_eq!(value("1", "http://localhost:80").as_deref(), Some(r#""1"; origin="http://localhost""#));
    }

    #[test]
    fn escapes_the_code() {
        assert_eq!(value(r#"a"b\c"#, "https://e.com").as_deref(), Some(r#""a\"b\\c"; origin="https://e.com""#));
    }

    #[test]
    fn refuses_origins_that_cannot_carry_a_code() {
        for origin in [
            "",
            "example.com",
            "http://example.com",
            "http://128.0.0.1",
            "ftp://example.com",
            "https://example.com/",
            "https://example.com/path",
            "https://example.com?q",
            "https://example.com#f",
            "https://user@example.com",
            "https://example.com:99999",
            "https://example.com:abc",
            "https://exa mple.com\n",
        ] {
            assert!(OtpToken::from("123456", origin).is_none(), "{origin}");
        }
        assert!(OtpToken::from("", "https://example.com").is_none());
        assert!(OtpToken::from("12\n34", "https://example.com").is_none());
    }

    #[test]
    fn headers_are_empty_without_a_token() {
        assert!(OtpToken::headers("123456", "").is_empty());
        let headers = OtpToken::headers("123456", "http://localhost");
        assert_eq!(headers.get(HEADER), Some(&Value::String(r#""123456"; origin="http://localhost""#.to_owned())));
    }
}
