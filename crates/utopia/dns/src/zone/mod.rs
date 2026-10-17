//! Zones: the records a server is authoritative for, looking names up in
//! them, and zone files.

mod file;
mod resolver;

pub use file::{export, export_at, import};
pub use resolver::{lookup, lookup_with};

use php_std::Str;

use crate::Error;
use crate::message::Record;

/// An administrative unit: the records of a domain and its subdomains, and
/// the `SOA` record that heads it.
///
/// PHP: `Utopia\DNS\Zone`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Zone {
    name: Str,
    records: Vec<Record>,
    soa: Record,
}

impl Zone {
    /// A zone named `name` (lower-cased; `.` is the root).
    ///
    /// Fails with [`Error::InvalidArgument`] when `soa` is not an `SOA`
    /// record named like the zone, or a record is an `SOA` or lies outside
    /// the zone.
    ///
    /// PHP: `new Zone($name, $records, $soa)`.
    pub fn new(name: impl AsRef<[u8]>, records: Vec<Record>, soa: Record) -> Result<Zone, Error> {
        if soa.ty() != Record::TYPE_SOA {
            return Err(Error::invalid("SOA parameter must be a Record with TYPE_SOA"));
        }
        let name = Str::from(name.as_ref().to_ascii_lowercase());
        if soa.name() != &*name {
            let mut message = b"SOA record name must match zone name: expected '".to_vec();
            message.extend_from_slice(&name);
            message.extend_from_slice(b"', got '");
            message.extend_from_slice(soa.name());
            message.push(b'\'');
            return Err(Error::InvalidArgument(message));
        }
        let root = &*name == b".";
        for record in &records {
            if record.ty() == Record::TYPE_SOA {
                return Err(Error::invalid("SOA records should be passed as the $soa parameter, not in $records"));
            }
            if !root && !in_zone(record.name(), &name) {
                let mut message = b"Record name '".to_vec();
                message.extend_from_slice(record.name());
                message.extend_from_slice(b"' does not belong to zone '");
                message.extend_from_slice(&name);
                message.push(b'\'');
                return Err(Error::InvalidArgument(message));
            }
        }
        Ok(Zone { name, records, soa })
    }

    /// The zone name, lower case.
    pub fn name(&self) -> &[u8] {
        &self.name
    }

    /// The records (without the `SOA`).
    pub fn records(&self) -> &[Record] {
        &self.records
    }

    /// The `SOA` record.
    pub fn soa(&self) -> &Record {
        &self.soa
    }

    /// Whether the zone answers for `name` itself rather than delegating
    /// it: the apex always, any other name unless it has `NS` records.
    ///
    /// PHP: `isAuthoritative()`.
    pub fn is_authoritative(&self, name: &[u8]) -> bool {
        name == &*self.name || !self.records.iter().any(|r| r.name() == name && r.ty() == Record::TYPE_NS)
    }
}

/// `name` is the zone or ends with `.zone`.
fn in_zone(name: &[u8], zone: &[u8]) -> bool {
    name == zone || (name.len() > zone.len() && name.ends_with(zone) && name[name.len() - zone.len() - 1] == b'.')
}
