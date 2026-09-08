use crate::client::Client;
use crate::error::Result;
use crate::query::Query;
use crate::resolver::Resolver;
use crate::Message;

/// Forwards queries to another DNS server. PHP `Utopia\DNS\Resolver\Proxy`.
#[derive(Debug)]
pub struct Proxy {
    client: Client,
}

impl Proxy {
    pub fn new(server: impl Into<String>, port: u16) -> Result<Self> {
        Ok(Self {
            client: Client::new(server, port, 5, false)?,
        })
    }
}

impl Resolver for Proxy {
    fn resolve(&self, query: &Query) -> Result<Message> {
        self.client.query(&query.message)
    }
}
