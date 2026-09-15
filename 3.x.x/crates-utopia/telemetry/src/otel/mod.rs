//! OpenTelemetry adapter and OTLP HTTP transport.
//!
//! Metrics aggregation and OTLP protobuf encoding come from the official
//! `opentelemetry` / `opentelemetry_sdk` / `opentelemetry-otlp` crates. The
//! Utopia [`Transport`] (PHP `TransportInterface`) is the HTTP layer, matching
//! PHP `MetricExporter($transport)`.

mod adapter;
mod transport;

pub use adapter::OpenTelemetry;
pub use transport::{
    HttpTransport, Transport, CONTENT_TYPE_JSON, CONTENT_TYPE_NDJSON, CONTENT_TYPE_PROTOBUF,
};
