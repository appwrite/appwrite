//! Resolvers: how a server answers a query.

use std::future::Future;
use std::sync::Mutex;
use std::time::Duration;

use php_std::random::Mt19937;

use crate::Error;
use crate::client::Client;
use crate::message::Message;
use crate::query::Query;
use crate::zone::{self, Zone};

/// Answers queries. Implement it to serve records from a database, an API
/// or anything else; wrap another resolver to add tracing, rate limiting
/// or caching around it.
///
/// An error makes the server answer `SERVFAIL` and report the error to its
/// error handlers.
///
/// PHP: `Utopia\DNS\Resolver`.
pub trait Resolver: Send + Sync + 'static {
    /// The response to `query`.
    ///
    /// PHP: `resolve()`.
    fn resolve(&self, query: &Query) -> impl Future<Output = Result<Message, Error>> + Send;
}

/// An authoritative resolver over one zone held in memory.
///
/// Multi-record answers are shuffled with its own generator, seeded from
/// the operating system unless given ([`Memory::with_seed`]).
///
/// PHP: `Utopia\DNS\Resolver\Memory`.
#[derive(Debug)]
pub struct Memory {
    zone: Zone,
    rng: Mutex<Mt19937>,
}

impl Memory {
    /// A resolver for `zone`.
    ///
    /// PHP: `new Memory($zone)`.
    pub fn new(zone: Zone) -> Memory {
        Memory { zone, rng: Mutex::new(Mt19937::from_entropy()) }
    }

    /// A resolver for `zone` shuffling answers as PHP does after `mt_srand(seed)`.
    pub fn with_seed(zone: Zone, seed: i64) -> Memory {
        Memory { zone, rng: Mutex::new(Mt19937::new(seed)) }
    }

    /// The zone.
    pub fn zone(&self) -> &Zone {
        &self.zone
    }
}

impl Resolver for Memory {
    async fn resolve(&self, query: &Query) -> Result<Message, Error> {
        let mut rng = self.rng.lock().unwrap_or_else(|e| e.into_inner());
        zone::lookup_with(&query.message, &self.zone, &mut rng)
    }
}

/// Forwards queries to another DNS server over UDP.
///
/// PHP: `Utopia\DNS\Resolver\Proxy`, and its `Cloudflare` and `Google` presets.
#[derive(Debug, Clone)]
pub struct Proxy {
    client: Client,
}

impl Proxy {
    /// A resolver forwarding to `server` (an IP address) on `port`, with
    /// the client's 5-second timeout.
    ///
    /// Fails with [`Error::Exception`] when `server` is not an IP address.
    ///
    /// PHP: `new Proxy($server, $port)`.
    pub fn new(server: &str, port: u16) -> Result<Proxy, Error> {
        Ok(Proxy { client: Client::new(server, port, Duration::from_secs(5), false)? })
    }

    /// Cloudflare's public resolver: `1.1.1.1`, or `1.0.0.1` as the backup.
    ///
    /// PHP: `new Cloudflare($useBackup)`.
    pub fn cloudflare(backup: bool) -> Proxy {
        Proxy::preset(if backup { "1.0.0.1" } else { "1.1.1.1" })
    }

    /// Google's public resolver: `8.8.8.8`, or `8.8.4.4` as the backup.
    ///
    /// PHP: `new Google($useBackup)`.
    pub fn google(backup: bool) -> Proxy {
        Proxy::preset(if backup { "8.8.4.4" } else { "8.8.8.8" })
    }

    fn preset(server: &str) -> Proxy {
        Proxy::new(server, 53).unwrap_or_else(|_| unreachable!("preset servers are IP addresses"))
    }

    /// The client queries are forwarded with.
    pub fn client(&self) -> &Client {
        &self.client
    }
}

impl Resolver for Proxy {
    async fn resolve(&self, query: &Query) -> Result<Message, Error> {
        self.client.query(&query.message).await
    }
}
