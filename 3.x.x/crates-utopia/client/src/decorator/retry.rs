//! PHP `Utopia\Client\Decorator\Retry` and `Retry\Strategy` / `Retry\Backoff`.

use std::sync::Arc;
use std::time::Duration;

use bytes::Bytes;
use http::{header, Request, Response};
use rand::Rng;
use utopia_pools::Recover;

use crate::{Adapter, Error, StreamingClient, Tls};

/// PHP `Utopia\Client\Decorator\Retry\Strategy`.
pub trait Strategy: Send + Sync {
    fn delay(
        &self,
        request: &Request<Bytes>,
        attempt: i32,
        response: Option<&Response<Bytes>>,
        error: Option<&Error>,
    ) -> Option<f64>;
}

/// PHP `Utopia\Client\Decorator\Retry\Backoff`.
#[derive(Clone)]
pub struct Backoff {
    max_attempts: i32,
    base_delay: f64,
    max_delay: f64,
    multiplier: f64,
    randomizer: Arc<dyn Fn() -> f64 + Send + Sync>,
}

impl std::fmt::Debug for Backoff {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Backoff")
            .field("max_attempts", &self.max_attempts)
            .field("base_delay", &self.base_delay)
            .field("max_delay", &self.max_delay)
            .field("multiplier", &self.multiplier)
            .finish_non_exhaustive()
    }
}

impl Default for Backoff {
    fn default() -> Self {
        Self::new()
    }
}

impl Backoff {
    /// PHP `new Backoff($maxAttempts = 3, $baseDelay = 0.1, $maxDelay = 10.0, $multiplier = 2.0)`.
    #[must_use]
    pub fn new() -> Self {
        Self {
            max_attempts: 3,
            base_delay: 0.1,
            max_delay: 10.0,
            multiplier: 2.0,
            randomizer: Arc::new(|| rand::thread_rng().gen::<f64>()),
        }
    }

    #[must_use]
    pub fn max_attempts(mut self, max_attempts: i32) -> Self {
        self.max_attempts = max_attempts;
        self
    }

    #[must_use]
    pub fn base_delay(mut self, base_delay: f64) -> Self {
        self.base_delay = base_delay;
        self
    }

    #[must_use]
    pub fn max_delay(mut self, max_delay: f64) -> Self {
        self.max_delay = max_delay;
        self
    }

    #[must_use]
    pub fn multiplier(mut self, multiplier: f64) -> Self {
        self.multiplier = multiplier;
        self
    }

    #[must_use]
    pub fn with_randomizer<F>(mut self, randomizer: F) -> Self
    where
        F: Fn() -> f64 + Send + Sync + 'static,
    {
        self.randomizer = Arc::new(randomizer);
        self
    }

    fn is_retryable(response: Option<&Response<Bytes>>, error: Option<&Error>) -> bool {
        if let Some(error) = error {
            return error.is_network();
        }
        response.is_some_and(|response| matches!(response.status().as_u16(), 429 | 502 | 503 | 504))
    }

    fn retry_after(&self, response: Option<&Response<Bytes>>) -> Option<f64> {
        let response = response?;
        let value = response.headers().get(header::RETRY_AFTER)?.to_str().ok()?;
        let parsed: f64 = value.parse().ok()?;
        if !parsed.is_finite() {
            return None;
        }
        Some(self.max_delay.min(0.0_f64.max(parsed)))
    }

    fn backoff(&self, attempt: i32) -> f64 {
        let ceiling = self
            .max_delay
            .min(self.base_delay * self.multiplier.powi(attempt - 1));
        (self.randomizer)() * ceiling
    }
}

const IDEMPOTENT: &[&str] = &["GET", "HEAD", "PUT", "DELETE", "OPTIONS", "TRACE"];

impl Strategy for Backoff {
    fn delay(
        &self,
        request: &Request<Bytes>,
        attempt: i32,
        response: Option<&Response<Bytes>>,
        error: Option<&Error>,
    ) -> Option<f64> {
        if attempt >= self.max_attempts {
            return None;
        }
        if !IDEMPOTENT.contains(&request.method().as_str()) {
            return None;
        }
        if !Self::is_retryable(response, error) {
            return None;
        }
        Some(
            self.retry_after(response)
                .unwrap_or_else(|| self.backoff(attempt)),
        )
    }
}

/// PHP `Utopia\Client\Decorator\Retry`.
#[derive(Clone)]
pub struct Retry<A: Adapter, S: Strategy = Backoff> {
    adapter: A,
    strategy: S,
    sleep: Arc<dyn Fn(f64) + Send + Sync>,
}

impl<A: Adapter, S: Strategy> std::fmt::Debug for Retry<A, S> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter.debug_struct("Retry").finish_non_exhaustive()
    }
}

impl<A: Adapter> Retry<A, Backoff> {
    /// PHP `new Retry($adapter, $strategy = new Backoff(), $sleep = null)`.
    pub fn new(adapter: A) -> Self {
        Self::with_strategy(adapter, Backoff::new())
    }
}

impl<A: Adapter, S: Strategy> Retry<A, S> {
    pub fn with_strategy(adapter: A, strategy: S) -> Self {
        Self {
            adapter,
            strategy,
            sleep: Arc::new(|seconds| {
                if seconds > 0.0 && seconds.is_finite() {
                    std::thread::sleep(Duration::from_secs_f64(seconds));
                }
            }),
        }
    }

    pub fn with_sleep<F>(mut self, sleep: F) -> Self
    where
        F: Fn(f64) + Send + Sync + 'static,
    {
        self.sleep = Arc::new(sleep);
        self
    }
}

impl<A: Adapter, S: Strategy + Clone> Retry<A, S> {
    fn wrap(&self, adapter: A) -> Self {
        Self {
            adapter,
            strategy: self.strategy.clone(),
            sleep: Arc::clone(&self.sleep),
        }
    }
}

impl<A: Adapter, S: Strategy> StreamingClient for Retry<A, S> {
    fn send_request(&self, request: Request<Bytes>) -> Result<Response<Bytes>, Error> {
        let mut attempt = 1;
        loop {
            match self.adapter.send_request(request.clone()) {
                Ok(response) => {
                    if let Some(delay) =
                        self.strategy
                            .delay(&request, attempt, Some(&response), None)
                    {
                        (self.sleep)(delay);
                        attempt += 1;
                        continue;
                    }
                    return Ok(response);
                }
                Err(error) => {
                    if let Some(delay) = self.strategy.delay(&request, attempt, None, Some(&error))
                    {
                        (self.sleep)(delay);
                        attempt += 1;
                        continue;
                    }
                    return Err(error);
                }
            }
        }
    }

    fn stream(
        &self,
        request: Request<Bytes>,
        sink: &mut dyn FnMut(&[u8]),
    ) -> Result<Response<Bytes>, Error> {
        let mut attempt = 1;
        loop {
            let mut delivered = 0usize;
            let mut counting = |chunk: &[u8]| {
                delivered += chunk.len();
                sink(chunk);
            };
            match self.adapter.stream(request.clone(), &mut counting) {
                Ok(response) => {
                    if delivered > 0 {
                        return Ok(response);
                    }
                    if let Some(delay) =
                        self.strategy
                            .delay(&request, attempt, Some(&response), None)
                    {
                        (self.sleep)(delay);
                        attempt += 1;
                        continue;
                    }
                    return Ok(response);
                }
                Err(error) => {
                    if delivered > 0 {
                        return Err(error);
                    }
                    if let Some(delay) = self.strategy.delay(&request, attempt, None, Some(&error))
                    {
                        (self.sleep)(delay);
                        attempt += 1;
                        continue;
                    }
                    return Err(error);
                }
            }
        }
    }
}

impl<A: Adapter, S: Strategy + Clone> Adapter for Retry<A, S> {
    fn with_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Ok(self.wrap(self.adapter.with_timeout(seconds)?))
    }

    fn with_connect_timeout(&self, seconds: f64) -> Result<Self, Error> {
        Ok(self.wrap(self.adapter.with_connect_timeout(seconds)?))
    }

    fn with_ssl_verification(&self, enabled: bool) -> Self {
        self.wrap(self.adapter.with_ssl_verification(enabled))
    }

    fn with_custom_ca(&self, path: impl Into<String>) -> Self {
        self.wrap(self.adapter.with_custom_ca(path))
    }

    fn with_certificate(
        &self,
        cert_path: impl Into<String>,
        key_path: impl Into<String>,
        passphrase: Option<String>,
    ) -> Self {
        self.wrap(
            self.adapter
                .with_certificate(cert_path, key_path, passphrase),
        )
    }

    fn with_min_tls_version(&self, version: Tls) -> Self {
        self.wrap(self.adapter.with_min_tls_version(version))
    }

    fn with_connection_reuse(&self, enabled: bool) -> Self {
        self.wrap(self.adapter.with_connection_reuse(enabled))
    }
}

impl<A: Adapter + Recover, S: Strategy> Recover for Retry<A, S> {}
