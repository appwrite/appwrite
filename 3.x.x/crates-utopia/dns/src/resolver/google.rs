use crate::error::Result;
use crate::resolver::proxy::Proxy;

/// Google public DNS (8.8.8.8 / 8.8.4.4). PHP `Utopia\DNS\Resolver\Google`.
///
/// Matches PHP: this is a UDP proxy, not DNS-over-HTTPS.
#[derive(Debug)]
pub struct Google(Proxy);

impl Google {
    pub fn new(use_backup: bool) -> Result<Self> {
        let ip = if use_backup { "8.8.4.4" } else { "8.8.8.8" };
        Ok(Self(Proxy::new(ip, 53)?))
    }

    /// Forward through a custom nameserver (tests / private resolvers).
    /// PHP always uses `8.8.8.8:53` / `8.8.4.4:53`.
    pub fn with_nameserver(host: impl Into<String>, port: u16) -> Result<Self> {
        Ok(Self(Proxy::new(host, port)?))
    }
}

impl crate::resolver::Resolver for Google {
    fn resolve(&self, query: &crate::query::Query) -> Result<crate::Message> {
        self.0.resolve(query)
    }
}
