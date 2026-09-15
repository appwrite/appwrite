//! Logger (PHP `Utopia\Logger\Logger`).

use std::fmt;

use rand::Rng;

use crate::adapter::Adapter;
use crate::error::LoggerError;
use crate::log::Log;

/// Instant-push logger wrapping a single [`Adapter`].
pub struct Logger {
    adapter: Box<dyn Adapter>,
    sample_percent: Option<f64>,
}

impl Logger {
    /// Library version advertised to providers (PHP `LIBRARY_VERSION`).
    pub const LIBRARY_VERSION: &'static str = crate::LIBRARY_VERSION;

    /// Registered provider names (PHP `PROVIDERS`).
    pub const PROVIDERS: &'static [&'static str] = crate::PROVIDERS;

    /// Construct a logger around an adapter (PHP `__construct`).
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Self {
            adapter: Box::new(adapter),
            sample_percent: None,
        }
    }

    /// Store a new log. Currently pushed immediately to the adapter.
    ///
    /// Returns the HTTP status from the adapter, `0` when sampled out, or
    /// `500` when [`Adapter::validate`] returns `false`.
    pub fn add_log(&self, log: &Log) -> Result<u16, LoggerError> {
        if !log.is_ready() {
            return Err(LoggerError::NotReady);
        }

        if let Some(sample_percent) = self.sample_percent {
            let rand = rand::thread_rng().gen_range(1..=100);
            if f64::from(rand) >= sample_percent {
                return Ok(0);
            }
        }

        if self.adapter.validate(log)? {
            self.adapter.push(log)
        } else {
            Ok(500)
        }
    }

    /// List of available providers (PHP `getProviders()`).
    pub fn get_providers() -> &'static [&'static str] {
        Self::PROVIDERS
    }

    /// Whether `provider_name` is a registered provider (PHP `hasProvider()`).
    pub fn has_provider(provider_name: &str) -> bool {
        Self::PROVIDERS
            .iter()
            .any(|registered| *registered == provider_name)
    }

    /// Keep only a sample of logs. `sample` is a fraction with `1.0` = 100%.
    /// Stored internally as a percent (PHP `$sample * 100`).
    pub fn set_sample(&mut self, sample: f64) -> &mut Self {
        self.sample_percent = Some(sample * 100.0);
        self
    }

    /// Current sample value as a percentage (PHP `getSample()`).
    pub fn get_sample(&self) -> Option<f64> {
        self.sample_percent
    }
}

impl fmt::Debug for Logger {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Logger")
            .field("sample_percent", &self.sample_percent)
            .finish_non_exhaustive()
    }
}
