//! Errors for telemetry adapters (PHP `Utopia\Telemetry\Exception`).

use thiserror::Error;

/// Telemetry adapter error. Messages match PHP `Utopia\Telemetry\Exception`
/// and `InvalidArgumentException` thrown by the Swoole OTLP transport.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum TelemetryError {
    /// PHP `InvalidArgumentException("Invalid endpoint URL: {$endpoint}")`.
    #[error("Invalid endpoint URL: {0}")]
    InvalidEndpoint(String),

    /// PHP `Exception('Transport has been shut down')`.
    #[error("Transport has been shut down")]
    TransportShutdown,

    /// PHP `Exception("OTLP connection failed: {$errMsg} (code: {$errCode})")`.
    #[error("OTLP connection failed: {message} (code: {code})")]
    ConnectionFailed { message: String, code: i32 },

    /// PHP `Exception("OTLP export failed with status {$status}: {$body}")`.
    #[error("OTLP export failed with status {status}: {body}")]
    ExportFailed { status: String, body: String },
}
