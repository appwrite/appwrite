//! Web Application Firewall rules management for Utopia.
//!
//! Rust port of [`utopia-php/waf`](https://github.com/utopia-php/waf).

mod attribute;
pub mod attributes;
mod condition;
mod error;
mod firewall;
mod rule;
pub mod rules;
pub mod validator;

use serde_json::{Map, Value};
use std::collections::HashMap;
use std::sync::Arc;

pub use attribute::Attribute;
pub use attributes::Ip;
pub use condition::Condition;
pub use error::{ConditionError, InvalidArgumentError};
pub use firewall::Firewall;
pub use rule::{
    Rule, ACTION_BYPASS, ACTION_CHALLENGE, ACTION_DENY, ACTION_RATE_LIMIT, ACTION_REDIRECT,
};
pub use rules::{Bypass, Challenge, Deny, RateLimit, Redirect};

/// PHP `Utopia\WAF\Exception` namespace.
pub mod exception {
    pub use crate::error::ConditionError as Condition;
}

/// Request attributes keyed by name (PHP `array<string, mixed>`).
pub type Attributes = Map<String, Value>;

/// Typed matching semantics, keyed by normalized attribute name.
pub type AttributeTypes = HashMap<String, Arc<dyn Attribute>>;

pub mod prelude {
    pub use crate::{
        attributes, exception, validator, Attribute, AttributeTypes, Attributes, Bypass, Challenge,
        Condition, Deny, Firewall, Ip, RateLimit, Redirect, Rule, ACTION_BYPASS, ACTION_CHALLENGE,
        ACTION_DENY, ACTION_RATE_LIMIT, ACTION_REDIRECT,
    };
}
