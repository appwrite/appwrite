//! [`RetryStrategy`]: which failed S3 requests are worth sending again.

use std::sync::Arc;

use super::client::{Method, Request, Response, TransportError};
use super::xml;

const REJECTED_ERROR_CODES: &[&str] = &["SlowDown", "ServiceUnavailable", "Throttling", "RequestThrottled"];
const UNKNOWN_ERROR_CODES: &[&str] = &["InternalError"];
const REJECTED_STATUS_CODES: &[u16] = &[429, 503];
const UNKNOWN_STATUS_CODES: &[u16] = &[500, 502, 504];

/// `CURLE_COULDNT_CONNECT`.
const CURLE_COULDNT_CONNECT: i64 = 7;
/// `SOCKET_ECONNREFUSED` on Linux.
const ECONNREFUSED: i64 = 111;

#[derive(PartialEq)]
enum Outcome {
    /// The service refused the request: it was not applied.
    Rejected,
    /// The request may have been applied.
    Unknown,
}

/// Retries transient S3 failures.
///
/// A rejected request (throttled, or never sent) is always retried. One that
/// may have been applied (an internal error, a dropped or timed-out
/// connection) is only retried when replaying it is harmless: not a `POST`
/// other than CompleteMultipartUpload, and not a conditional write, whose
/// condition the first attempt may already have changed. The XML error code
/// wins over the HTTP status. Waits use exponential backoff with full
/// jitter, or a numeric `Retry-After`.
///
/// ```
/// use utopia_storage::s3::{Method, Request, Response, RetryStrategy};
///
/// let strategy = RetryStrategy::new(3, 0.5, 20.0).with_randomizer(|| 1.0);
/// let request = Request { method: Method::Put, url: "https://s3.example.com/a".into(), headers: vec![], body: Default::default() };
/// assert_eq!(strategy.delay(&request, 1, Some(&Response::new(503)), None), Some(0.5));
/// assert_eq!(strategy.delay(&request, 1, Some(&Response::new(404)), None), None);
/// ```
///
/// ```php
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Response;
/// use Utopia\Psr7\Uri;
/// use Utopia\Storage\Device\S3\RetryStrategy;
///
/// $strategy = new RetryStrategy(3, 0.5, 20.0, fn (): float => 1.0);
/// $request = new Request('PUT', Uri::parse('https://s3.example.com/a'));
/// var_dump($strategy->delay($request, 1, new Response(503), null)); // float(0.5)
/// var_dump($strategy->delay($request, 1, new Response(404), null)); // NULL
/// ```
///
/// PHP: `Utopia\Storage\Device\S3\RetryStrategy`.
#[derive(Clone)]
pub struct RetryStrategy {
    retries: i64,
    delay: f64,
    max_delay: f64,
    randomizer: Arc<dyn Fn() -> f64 + Send + Sync>,
}

impl Default for RetryStrategy {
    /// Three retries, from a 0.5 s window up to 20 s.
    fn default() -> Self {
        Self::new(3, 0.5, 20.0)
    }
}

impl std::fmt::Debug for RetryStrategy {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RetryStrategy")
            .field("retries", &self.retries)
            .field("delay", &self.delay)
            .field("max_delay", &self.max_delay)
            .finish_non_exhaustive()
    }
}

impl RetryStrategy {
    /// `retries` retries after the first attempt; the wait before retry `n`
    /// is drawn from `[0, min(max_delay, delay * 2^(n-1)))` seconds.
    ///
    /// PHP: `new RetryStrategy($retries, $delay, $maxDelay)`.
    pub fn new(retries: i64, delay: f64, max_delay: f64) -> Self {
        RetryStrategy { retries, delay, max_delay, randomizer: Arc::new(rand::random::<f64>) }
    }

    /// The strategy drawing its jitter from `randomizer` (a value in
    /// `[0, 1)`), for deterministic waits.
    ///
    /// PHP: the `$randomizer` argument.
    pub fn with_randomizer(mut self, randomizer: impl Fn() -> f64 + Send + Sync + 'static) -> Self {
        self.randomizer = Arc::new(randomizer);
        self
    }

    /// How many seconds to wait before sending `request` again after
    /// attempt `attempt` got `response` or failed with `error`; `None` to
    /// give up.
    ///
    /// PHP: `delay()`.
    pub fn delay(
        &self,
        request: &Request,
        attempt: i64,
        response: Option<&Response>,
        error: Option<&TransportError>,
    ) -> Option<f64> {
        if attempt > self.retries {
            return None;
        }
        let outcome = match response {
            Some(response) => classify_response(response),
            None => classify_error(error),
        }?;
        if outcome == Outcome::Unknown && !replayable(request) {
            return None;
        }
        if let Some(after) = response.and_then(|r| self.retry_after(r)) {
            return Some(after);
        }
        let window = self.max_delay.min(self.delay * 2f64.powf((attempt - 1) as f64));
        Some((self.randomizer)() * window)
    }

    fn retry_after(&self, response: &Response) -> Option<f64> {
        let value = response.header_line("retry-after");
        let number = php_std::value::numeric_str(&value)?;
        Some(self.max_delay.min(0f64.max(number.as_f64())))
    }
}

fn classify_response(response: &Response) -> Option<Outcome> {
    let trimmed = php_std::string::ltrim(&response.body, b" \t\n\r\0\x0B");
    if trimmed.starts_with(b"<?xml") || trimmed.starts_with(b"<Error") {
        let code = xml::parse(&response.body).and_then(|root| root.child_text("Code")).unwrap_or_default();
        if !code.is_empty() {
            return if REJECTED_ERROR_CODES.contains(&code.as_str()) {
                Some(Outcome::Rejected)
            } else if UNKNOWN_ERROR_CODES.contains(&code.as_str()) {
                Some(Outcome::Unknown)
            } else {
                None
            };
        }
    }
    if REJECTED_STATUS_CODES.contains(&response.status) {
        Some(Outcome::Rejected)
    } else if UNKNOWN_STATUS_CODES.contains(&response.status) {
        Some(Outcome::Unknown)
    } else {
        None
    }
}

fn classify_error(error: Option<&TransportError>) -> Option<Outcome> {
    match error? {
        TransportError::Dns { .. } => Some(Outcome::Rejected),
        // A TLS failure is a connection failure in PHP's class hierarchy.
        TransportError::Connection { code, .. } | TransportError::Tls { code, .. }
            if *code == CURLE_COULDNT_CONNECT || *code == ECONNREFUSED =>
        {
            Some(Outcome::Rejected)
        }
        TransportError::Tls { .. } => None,
        TransportError::Connection { .. } | TransportError::Timeout { .. } => Some(Outcome::Unknown),
        TransportError::Network { .. } => None,
    }
}

fn replayable(request: &Request) -> bool {
    match request.method {
        Method::Put => request.header("if-match").is_none() && request.header("if-none-match").is_none(),
        Method::Post => {
            // Of the POSTs, only CompleteMultipartUpload is safe: a landed
            // completion leaves the object in place.
            php_std::encoding::parse_str(request.query().as_bytes()).get_str("uploadId").is_some()
        }
        _ => true,
    }
}
