//! Which forwarded headers the server believes for the client's address and
//! scheme, when it runs behind a proxy.
//!
//! PHP: `Utopia\Http\TrustedHeaders`.

/// Headers naming the client address and the scheme it used, read in order;
/// the first usable value wins. Names are lowercased and trimmed, empty ones
/// dropped.
///
/// The default trusts no address header and `x-forwarded-proto` for the
/// scheme.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TrustedHeaders {
    ip: Vec<String>,
    proto: Vec<String>,
}

impl Default for TrustedHeaders {
    /// No address header, and `x-forwarded-proto` for the scheme.
    ///
    /// PHP: `new TrustedHeaders()`.
    fn default() -> Self {
        Self { ip: Vec::new(), proto: vec!["x-forwarded-proto".to_owned()] }
    }
}

impl TrustedHeaders {
    /// Trusts the headers `ip` for the client address and `proto` for the
    /// scheme. Names are lowercased and trimmed; empty names and `0` are
    /// dropped.
    ///
    /// PHP: `new TrustedHeaders($ip, $proto)`.
    pub fn new<I, P>(ip: I, proto: P) -> Self
    where
        I: IntoIterator,
        I::Item: AsRef<str>,
        P: IntoIterator,
        P::Item: AsRef<str>,
    {
        Self { ip: normalize(ip), proto: normalize(proto) }
    }

    /// Headers naming the client address.
    pub fn ip(&self) -> &[String] {
        &self.ip
    }

    /// Headers naming the scheme the client used.
    pub fn proto(&self) -> &[String] {
        &self.proto
    }
}

fn normalize<I>(headers: I) -> Vec<String>
where
    I: IntoIterator,
    I::Item: AsRef<str>,
{
    headers
        .into_iter()
        .map(|h| {
            let lowered = h.as_ref().to_ascii_lowercase();
            String::from_utf8_lossy(php_std::string::trim(lowered.as_bytes(), b" \t\n\r\0\x0B")).into_owned()
        })
        // array_filter drops '' and '0'.
        .filter(|h| !h.is_empty() && h != "0")
        .collect()
}
