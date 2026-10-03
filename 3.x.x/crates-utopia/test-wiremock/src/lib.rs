//! WireMock container harness for Utopia tests.
//!
//! Talks to the compose/CI `wiremock` service (`WIREMOCK_URL`, default
//! `http://127.0.0.1:8089`). Start it before running HTTP mock tests:
//!
//! ```bash
//! docker compose -f docker-compose.test.yml up -d wiremock
//! ```

#![allow(clippy::missing_errors_doc)]
#![allow(clippy::missing_panics_doc)]

mod matchers;
mod respond;
mod server;
mod stub;

pub use matchers::{body_string_contains, header, method, path, path_regex, query_param, Matcher};
pub use respond::{RecordedRequest, Respond};
pub use server::MockServer;
pub use stub::{Mock, ResponseTemplate};

/// WireMock release aligned with `docker-compose.test.yml`.
pub const WIREMOCK_VERSION: &str = "3.12.1";
