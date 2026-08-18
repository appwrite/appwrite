//! Reproduction step attached to a log (PHP `Utopia\Logger\Log\Breadcrumb`).

use crate::error::LoggerError;
use crate::log::Log;

/// A single breadcrumb / reproduction step.
#[derive(Debug, Clone, PartialEq)]
pub struct Breadcrumb {
    type_: String,
    category: String,
    message: String,
    timestamp: f64,
}

impl Breadcrumb {
    /// Create a breadcrumb. `type_` must be one of the [`Log`] type constants.
    pub fn new(
        type_: impl Into<String>,
        category: impl Into<String>,
        message: impl Into<String>,
        timestamp: f64,
    ) -> Result<Self, LoggerError> {
        let type_ = type_.into();
        match type_.as_str() {
            Log::TYPE_DEBUG
            | Log::TYPE_ERROR
            | Log::TYPE_INFO
            | Log::TYPE_WARNING
            | Log::TYPE_VERBOSE => {}
            _ => return Err(LoggerError::InvalidBreadcrumbType),
        }
        Ok(Self {
            type_,
            category: category.into(),
            message: message.into(),
            timestamp,
        })
    }

    /// Breadcrumb type (PHP `getType()`).
    pub fn get_type(&self) -> &str {
        &self.type_
    }

    /// Breadcrumb category (PHP `getCategory()`).
    pub fn get_category(&self) -> &str {
        &self.category
    }

    /// Breadcrumb message (PHP `getMessage()`).
    pub fn get_message(&self) -> &str {
        &self.message
    }

    /// Breadcrumb timestamp in seconds (PHP `getTimestamp()`).
    pub fn get_timestamp(&self) -> f64 {
        self.timestamp
    }
}
