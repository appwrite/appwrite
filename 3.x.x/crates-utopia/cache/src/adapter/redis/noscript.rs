use std::error::Error;
use std::fmt;

/// PHP `Utopia\Cache\Adapter\Redis\NoScript`.
#[derive(Debug)]
pub struct NoScript {
    message: String,
    source: Option<Box<dyn Error + Send + Sync>>,
}

impl NoScript {
    const CODE: &'static str = "NOSCRIPT";

    #[must_use]
    pub fn new(message: impl Into<String>) -> Self {
        Self {
            message: message.into(),
            source: None,
        }
    }

    /// PHP `NoScript::matches($error)` - leading token is `NOSCRIPT`.
    #[must_use]
    pub fn matches(error: &str) -> bool {
        error.split_once(' ').map_or(error, |(code, _)| code) == Self::CODE
    }

    /// PHP `NoScript::from($reason)`.
    #[must_use]
    pub fn from_message(message: impl Into<String>) -> Self {
        Self::new(message)
    }

    #[must_use]
    pub fn from_error(err: impl Error + Send + Sync + 'static) -> Self {
        let message = err.to_string();
        Self {
            message,
            source: Some(Box::new(err)),
        }
    }

    #[must_use]
    pub fn message(&self) -> &str {
        &self.message
    }

    #[must_use]
    pub fn previous(&self) -> Option<&(dyn Error + Send + Sync)> {
        self.source.as_deref()
    }
}

impl fmt::Display for NoScript {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl Error for NoScript {
    fn source(&self) -> Option<&(dyn Error + 'static)> {
        self.source.as_ref().map(|err| {
            let err: &(dyn Error + 'static) = err.as_ref();
            err
        })
    }
}
