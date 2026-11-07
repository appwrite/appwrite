use std::sync::Arc;

use crate::telemetry::{DURATION_BUCKETS, Histogram, Telemetry, UpDownCounter};

/// The instruments of one application.
#[derive(Clone)]
pub(crate) struct Instruments {
    pub request_duration: Arc<dyn Histogram>,
    pub active_requests: Arc<dyn UpDownCounter>,
    pub request_body_size: Arc<dyn Histogram>,
    pub response_body_size: Arc<dyn Histogram>,
}

impl Instruments {
    /// Creates the server's instruments from `telemetry`.
    pub fn new(telemetry: &dyn Telemetry) -> Self {
        Self {
            request_duration: telemetry.histogram("http.server.request.duration", "s", Some(&DURATION_BUCKETS)),
            active_requests: telemetry.up_down_counter("http.server.active_requests", "{request}"),
            request_body_size: telemetry.histogram("http.server.request.body.size", "By", None),
            response_body_size: telemetry.histogram("http.server.response.body.size", "By", None),
        }
    }
}
