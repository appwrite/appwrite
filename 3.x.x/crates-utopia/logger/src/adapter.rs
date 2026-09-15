//! Adapter trait (PHP `Utopia\Logger\Adapter`).

use crate::error::LoggerError;
use crate::log::Log;

/// External log provider.
///
/// PHP methods: `getName`, `push`, `getSupportedTypes`, `getSupportedEnvironments`,
/// `getSupportedBreadcrumbTypes`, `validate`.
pub trait Adapter {
    /// Unique adapter name (PHP `getName()`).
    fn get_name(&self) -> &'static str;

    /// Push a log to the external provider. Returns the HTTP status code.
    fn push(&self, log: &Log) -> Result<u16, LoggerError>;

    /// Log types supported by this adapter.
    fn get_supported_types(&self) -> &'static [&'static str];

    /// Environments supported by this adapter.
    fn get_supported_environments(&self) -> &'static [&'static str];

    /// Breadcrumb types supported by this adapter.
    fn get_supported_breadcrumb_types(&self) -> &'static [&'static str];

    /// Validate a log for compatibility with this adapter.
    ///
    /// Returns `Ok(true)` when valid. Unknown types/environments/breadcrumbs
    /// raise [`LoggerError`] (PHP throws `Exception`). Returning `Ok(false)`
    /// makes [`crate::Logger::add_log`] return `500` without pushing.
    fn validate(&self, log: &Log) -> Result<bool, LoggerError> {
        let supported_log_types = self.get_supported_types();
        let supported_environments = self.get_supported_environments();
        let supported_breadcrumb_types = self.get_supported_breadcrumb_types();

        if !supported_log_types.contains(&log.get_type()) {
            return Err(LoggerError::UnsupportedAdapterLogType(
                supported_log_types.join(", "),
            ));
        }
        if !supported_environments.contains(&log.get_environment()) {
            return Err(LoggerError::UnsupportedAdapterEnvironment(
                supported_environments.join(", "),
            ));
        }

        for breadcrumb in log.get_breadcrumbs() {
            if !supported_breadcrumb_types.contains(&breadcrumb.get_type()) {
                return Err(LoggerError::UnsupportedAdapterBreadcrumbType(
                    supported_breadcrumb_types.join(", "),
                ));
            }
        }

        Ok(true)
    }
}
