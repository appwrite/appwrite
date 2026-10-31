//! Finding the addresses a hostname points to.

use std::future::Future;
use std::time::Duration;

use php_std::filter::{FILTER_VALIDATE_IP, Options, Value};

use crate::Error;
use crate::client::Client;
use crate::message::{Message, Question, RCODE_NOERROR, RCODE_NXDOMAIN, Record};

/// Finds the addresses a hostname points to.
///
/// PHP: `Utopia\DNS\Lookup`.
pub trait Lookup: Send + Sync {
    /// Every `A` and `AAAA` address of `hostname`; none when it cannot be resolved.
    ///
    /// PHP: `addresses()`.
    fn addresses(&self, hostname: &str) -> impl Future<Output = Vec<String>> + Send;
}

/// Asks the given recursive resolvers in order, over TCP (no 512-byte
/// truncation, and IPv6 servers work), instead of the system resolver, so
/// names only the local network knows (`/etc/hosts`, search domains,
/// cluster services) never resolve.
///
/// A server that fails, cannot be reached, or answers anything but
/// `NOERROR` or `NXDOMAIN` for either family is skipped; when none answers
/// for both, the hostname resolves to nothing rather than half its
/// addresses.
///
/// PHP: `Utopia\DNS\Lookup\Recursive`.
#[derive(Debug, Clone)]
pub struct Recursive {
    servers: Vec<(String, u16)>,
}

impl Recursive {
    /// Resolvers given as `ip`, `ip:port`, `[ipv6]` or `[ipv6]:port` (port 53 by default).
    ///
    /// Fails with [`Error::InvalidArgument`] when none is given, or one is
    /// not an IP address with an optional valid port.
    ///
    /// PHP: `new Recursive($servers)`.
    pub fn new<S: AsRef<str>>(servers: &[S]) -> Result<Recursive, Error> {
        if servers.is_empty() {
            return Err(Error::invalid("At least one DNS server is required."));
        }
        let servers = servers.iter().map(|s| parse(s.as_ref())).collect::<Result<_, _>>()?;
        Ok(Recursive { servers })
    }

    /// The servers, as host and port.
    pub fn servers(&self) -> &[(String, u16)] {
        &self.servers
    }

    async fn ask(&self, host: &str, port: u16, hostname: &str) -> Option<Vec<String>> {
        let client = Client::new(host, port, Duration::from_secs(5), true).ok()?;
        let mut addresses: Vec<String> = Vec::new();
        for ty in [Record::TYPE_A, Record::TYPE_AAAA] {
            let query = Message::query(Question::new(hostname, ty), None, true);
            let response = client.query(&query).await.ok()?;
            let code = response.header().response_code;
            if code != RCODE_NOERROR && code != RCODE_NXDOMAIN {
                return None;
            }
            for record in response.answers() {
                if record.ty() == ty {
                    let address = String::from_utf8_lossy(record.rdata()).into_owned();
                    if !addresses.contains(&address) {
                        addresses.push(address);
                    }
                }
            }
        }
        Some(addresses)
    }
}

impl Lookup for Recursive {
    async fn addresses(&self, hostname: &str) -> Vec<String> {
        for (host, port) in &self.servers {
            if let Some(addresses) = self.ask(host, *port, hostname).await {
                return addresses;
            }
        }
        Vec::new()
    }
}

/// `ip`, `ip:port`, `[ipv6]` or `[ipv6]:port`.
fn parse(server: &str) -> Result<(String, u16), Error> {
    let mut host = server;
    let mut digits = "53";
    if let Some(inner) = server.strip_prefix('[') {
        match inner.find(']') {
            None => host = "",
            Some(close) => {
                host = &inner[..close];
                let rest = &inner[close + 1..];
                if !rest.is_empty() {
                    digits = rest.strip_prefix(':').unwrap_or("");
                }
            }
        }
    } else if server.bytes().filter(|&b| b == b':').count() == 1 {
        let (h, d) = server.split_once(':').unwrap_or((server, ""));
        host = h;
        digits = d;
    }
    let valid_ip =
        php_std::filter::filter_var(&Value::Str(host.as_bytes().to_vec()), FILTER_VALIDATE_IP, &Options::Flags(0))
            .is_ok_and(|f| matches!(f.value, Value::Str(_)));
    let port = php_std::format::str_to_int(digits.as_bytes());
    if !valid_ip || digits.is_empty() || !digits.bytes().all(|b| b.is_ascii_digit()) || port > 65535 {
        return Err(Error::invalid(format!("DNS server must be an IP address with an optional port: '{server}'.")));
    }
    Ok((host.to_owned(), port as u16))
}
