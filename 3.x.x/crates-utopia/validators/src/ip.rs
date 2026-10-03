use crate::{Validator, ValueType};
use serde_json::Value;
use std::net::IpAddr;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum IpVersion {
    Any,
    V4,
    V6,
}

#[derive(Debug, Clone)]
pub struct Ip {
    version: IpVersion,
}

impl Ip {
    pub fn new() -> Self {
        Self {
            version: IpVersion::Any,
        }
    }

    pub fn v4() -> Self {
        Self {
            version: IpVersion::V4,
        }
    }

    pub fn v6() -> Self {
        Self {
            version: IpVersion::V6,
        }
    }
}

impl Default for Ip {
    fn default() -> Self {
        Self::new()
    }
}

impl Validator for Ip {
    fn description(&self) -> String {
        match self.version {
            IpVersion::Any => "Value must be a valid IP address".into(),
            IpVersion::V4 => "Value must be a valid IPv4 address".into(),
            IpVersion::V6 => "Value must be a valid IPv6 address".into(),
        }
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(s) = value.as_str() else {
            return false;
        };
        matches!(
            (s.parse::<IpAddr>(), self.version),
            (Ok(IpAddr::V4(_)), IpVersion::Any | IpVersion::V4)
                | (Ok(IpAddr::V6(_)), IpVersion::Any | IpVersion::V6)
        )
    }
}
