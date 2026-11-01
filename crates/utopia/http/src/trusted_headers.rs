//! Which forwarded headers the server believes for the client's address,
//! scheme, host and port, when it runs behind a proxy.
//!
//! PHP: `Utopia\Http\TrustedHeaders`.

/// Headers naming the client address, the scheme it used, the host it asked
/// for and the port it connected to, read in order; the first usable value
/// wins. Names are lowercased and trimmed, empty ones dropped.
///
/// The default trusts no address, host or port header and
/// `x-forwarded-proto` for the scheme.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TrustedHeaders {
    ip: Vec<String>,
    proto: Vec<String>,
    host: Vec<String>,
    port: Vec<String>,
}

impl Default for TrustedHeaders {
    /// No address, host or port header, and `x-forwarded-proto` for the scheme.
    ///
    /// PHP: `new TrustedHeaders()`.
    fn default() -> Self {
        Self { ip: Vec::new(), proto: vec!["x-forwarded-proto".to_owned()], host: Vec::new(), port: Vec::new() }
    }
}

impl TrustedHeaders {
    /// The default, shared: a request that keeps it allocates nothing.
    pub(crate) fn shared_default() -> std::sync::Arc<Self> {
        static DEFAULT: std::sync::LazyLock<std::sync::Arc<TrustedHeaders>> =
            std::sync::LazyLock::new(|| std::sync::Arc::new(TrustedHeaders::default()));
        DEFAULT.clone()
    }

    /// Trusts the headers `ip` for the client address, `proto` for the
    /// scheme, `host` for the host asked for and `port` for the port
    /// connected to. Names are lowercased and trimmed; empty names and `0`
    /// are dropped.
    ///
    /// PHP: `new TrustedHeaders($ip, $proto, $host, $port)`.
    pub fn new<I, P, H, O>(ip: I, proto: P, host: H, port: O) -> Self
    where
        I: IntoIterator,
        I::Item: AsRef<str>,
        P: IntoIterator,
        P::Item: AsRef<str>,
        H: IntoIterator,
        H::Item: AsRef<str>,
        O: IntoIterator,
        O::Item: AsRef<str>,
    {
        Self { ip: normalize(ip), proto: normalize(proto), host: normalize(host), port: normalize(port) }
    }

    /// Headers naming the client address.
    pub fn ip(&self) -> &[String] {
        &self.ip
    }

    /// Headers naming the scheme the client used.
    pub fn proto(&self) -> &[String] {
        &self.proto
    }

    /// Headers naming the host the client asked for.
    pub fn host(&self) -> &[String] {
        &self.host
    }

    /// Headers naming the port the client connected to.
    pub fn port(&self) -> &[String] {
        &self.port
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
