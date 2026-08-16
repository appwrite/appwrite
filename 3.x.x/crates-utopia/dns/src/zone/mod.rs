pub mod file;
pub mod resolver;

pub use file::File;
pub use resolver::Resolver;

use crate::error::{Error, Result};
use crate::message::Record;

/// An administrative unit containing DNS records. PHP `Utopia\DNS\Zone`.
#[derive(Debug, Clone)]
pub struct Zone {
    pub name: String,
    pub records: Vec<Record>,
    pub soa: Record,
}

impl Zone {
    /// PHP `Zone::__construct`.
    pub fn new(name: impl AsRef<str>, records: Vec<Record>, soa: Record) -> Result<Self> {
        if soa.type_code != Record::TYPE_SOA {
            return Err(Error::invalid(
                "SOA parameter must be a Record with TYPE_SOA",
            ));
        }
        let name = name.as_ref().to_ascii_lowercase();
        if soa.name != name {
            return Err(Error::invalid(format!(
                "SOA record name must match zone name: expected '{name}', got '{}'",
                soa.name
            )));
        }
        let zone_suffix = if name == "." {
            ".".to_string()
        } else {
            format!(".{name}")
        };
        for record in &records {
            if record.type_code == Record::TYPE_SOA {
                return Err(Error::invalid(
                    "SOA records should be passed as the $soa parameter, not in $records",
                ));
            }
            if name != "." && record.name != name && !record.name.ends_with(&zone_suffix) {
                return Err(Error::invalid(format!(
                    "Record name '{}' does not belong to zone '{name}'",
                    record.name
                )));
            }
        }
        Ok(Self { name, records, soa })
    }

    /// PHP `Zone::isAuthoritative`.
    #[must_use]
    pub fn is_authoritative(&self, name: &str) -> bool {
        if name == self.name {
            return true;
        }
        self.records
            .iter()
            .all(|record| record.name != name || record.type_code != Record::TYPE_NS)
    }
}
