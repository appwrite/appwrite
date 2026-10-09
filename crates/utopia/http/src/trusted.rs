//! `Utopia\Http\TrustedHeaders`: which forwarded headers this server believes.

/// Headers naming the client address and the scheme it used, read in order;
/// the first usable value wins. Names are lowercased and trimmed, empty ones
/// dropped.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TrustedHeaders {
    ip: Vec<String>,
    proto: Vec<String>,
}

impl Default for TrustedHeaders {
    /// `new TrustedHeaders()`: no IP header, `x-forwarded-proto` for the scheme.
    fn default() -> Self {
        Self { ip: Vec::new(), proto: vec!["x-forwarded-proto".to_owned()] }
    }
}

impl TrustedHeaders {
    /// `new TrustedHeaders($ip, $proto)`.
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
