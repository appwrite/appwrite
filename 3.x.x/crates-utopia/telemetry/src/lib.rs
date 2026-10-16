//! Metrics telemetry adapters for Utopia.
//!
//! Rust port of [`utopia-php/telemetry`](https://github.com/utopia-php/telemetry).

mod adapter;
pub mod adapters;
mod error;
mod instrument;
pub mod otel;
mod php_url;

pub use adapter::Adapter;
pub use error::TelemetryError;
pub use instrument::{
    attrs, lazy_counter, lazy_gauge, lazy_histogram, lazy_up_down_counter, Counter, Gauge,
    Histogram, ObservableGauge, ObserveCallback, Observer, UpDownCounter,
};
pub use otel::{
    HttpTransport, OpenTelemetry, Transport, CONTENT_TYPE_JSON, CONTENT_TYPE_NDJSON,
    CONTENT_TYPE_PROTOBUF,
};

pub use adapters::{NoneAdapter, TestAdapter};

/// Attribute key/value pairs attached to a measurement.
pub type Attributes = std::collections::HashMap<String, String>;

/// Optional instrument configuration hints (e.g. histogram bucket boundaries).
pub type Advisory = std::collections::HashMap<String, String>;

/// Prelude for common telemetry types.
pub mod prelude {
    pub use crate::{
        adapters::{NoneAdapter, TestAdapter},
        Adapter, Attributes, Counter, Gauge, Histogram, HttpTransport, ObservableGauge,
        OpenTelemetry, TelemetryError, UpDownCounter,
    };
}
