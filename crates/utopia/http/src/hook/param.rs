mod check;
mod default;

use crate::resources::Resource;

pub use check::{Check, ValidatorFactory};
pub use default::{Default, DefaultFactory};

/// A param a hook or route declares: its default, validator and metadata.
///
/// PHP: the arguments of `Hook::param(...)`.
#[derive(Clone)]
pub struct Param {
    /// The value used when the request does not send the param.
    pub default: Default,
    /// The validator a sent value must pass.
    pub validator: Check,
    /// A human description.
    pub description: String,
    /// Whether the request may omit the param.
    pub optional: bool,
    /// Resources passed to a default or validator factory.
    pub injections: Vec<String>,
    /// Whether the value is passed through without validation.
    pub skip_validation: bool,
    /// Whether the param is deprecated (metadata for documentation).
    pub deprecated: bool,
    /// An example value (metadata for documentation).
    pub example: String,
    /// The name of a model the param refers to (metadata for documentation).
    pub model: Option<String>,
    /// Other names the value may be sent under.
    pub aliases: Vec<String>,
    /// Enum metadata for documentation generators (any value).
    ///
    /// PHP: the `$enum` object of `param()`.
    pub enumeration: Option<Resource>,
}

impl Param {
    /// A required param with a default, a validator and a description.
    pub fn new(default: impl Into<Default>, validator: impl Into<Check>, description: &str) -> Self {
        Self {
            default: default.into(),
            validator: validator.into(),
            description: description.to_owned(),
            optional: false,
            injections: Vec::new(),
            skip_validation: false,
            deprecated: false,
            example: String::new(),
            model: None,
            aliases: Vec::new(),
            enumeration: None,
        }
    }

    /// Sets whether the request may omit the param.
    pub fn optional(mut self, optional: bool) -> Self {
        self.optional = optional;
        self
    }

    /// Sets the resources passed to a default or validator factory.
    pub fn injections(mut self, injections: &[&str]) -> Self {
        self.injections = injections.iter().map(|s| (*s).to_owned()).collect();
        self
    }

    /// Sets whether the value is passed through without validation.
    pub fn skip_validation(mut self, skip: bool) -> Self {
        self.skip_validation = skip;
        self
    }

    /// Sets other names the value may be sent under.
    pub fn aliases(mut self, aliases: &[&str]) -> Self {
        self.aliases = aliases.iter().map(|s| (*s).to_owned()).collect();
        self
    }
}
