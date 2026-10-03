use crate::error::Result;
use crate::query::Query;
use crate::resolver::Resolver;
use crate::zone::{self, Zone};
use crate::Message;

/// In-memory authoritative resolver. PHP `Utopia\DNS\Resolver\Memory`.
#[derive(Debug, Clone)]
pub struct Memory {
    zone: Zone,
}

impl Memory {
    #[must_use]
    pub fn new(zone: Zone) -> Self {
        Self { zone }
    }
}

impl Resolver for Memory {
    fn resolve(&self, query: &Query) -> Result<Message> {
        zone::Resolver::lookup(&query.message, &self.zone)
    }
}
