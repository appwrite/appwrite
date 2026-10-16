use thiserror::Error;

/// Condition parse / encode error (`Utopia\WAF\Exception\Condition`).
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum ConditionError {
    /// Unknown operator name.
    #[error("Unsupported condition method: {0}")]
    UnsupportedMethod(String),

    /// JSON decode failed.
    #[error("Invalid condition payload: {0}")]
    InvalidPayload(String),

    /// Decoded JSON was not an array/object definition.
    #[error("Invalid condition payload. Expecting array definition.")]
    ExpectingArray,

    /// `method` was present but not a string.
    #[error("Invalid condition method definition.")]
    InvalidMethodDefinition,

    /// `attribute` was present but not a string.
    #[error("Invalid condition attribute definition.")]
    InvalidAttributeDefinition,

    /// `values` was present but not an array.
    #[error("Invalid condition values definition.")]
    InvalidValuesDefinition,

    /// Logical operator child was not an array definition.
    #[error("Invalid nested condition definition.")]
    InvalidNested,

    /// Logical constructor received a non-condition nested value.
    #[error("Logical conditions require nested condition definitions.")]
    LogicalRequiresNested,

    /// JSON encode failed.
    #[error("Unable to encode condition: {0}")]
    Encode(String),
}

/// Invalid constructor arguments (`InvalidArgumentException` in PHP).
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum InvalidArgumentError {
    /// [`crate::Challenge`] type is not captcha/custom/compute.
    #[error("Invalid challenge type: {0}")]
    InvalidChallengeType(String),

    /// [`crate::RateLimit`] `limit` or `interval` is less than 1.
    #[error("Limit and interval must be at least 1")]
    InvalidRateLimit,
}
