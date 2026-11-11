//! Perplexity.

use super::chat::{Chat, Dialect};
use super::chat_adapter;

/// The chat completions endpoint.
///
/// PHP: `Perplexity::ENDPOINT`.
pub const ENDPOINT: &str = "https://api.perplexity.ai/chat/completions";

/// Sonar: lightweight search (the default).
pub const MODEL_SONAR: &str = "sonar";
/// Sonar Pro.
pub const MODEL_SONAR_PRO: &str = "sonar-pro";
/// Sonar Deep Research.
pub const MODEL_SONAR_DEEP_RESEARCH: &str = "sonar-deep-research";
/// Sonar Reasoning.
pub const MODEL_SONAR_REASONING: &str = "sonar-reasoning";
/// Sonar Reasoning Pro.
pub const MODEL_SONAR_REASONING_PRO: &str = "sonar-reasoning-pro";

/// Every model, in PHP's order.
pub const MODELS: &[&str] =
    &[MODEL_SONAR, MODEL_SONAR_PRO, MODEL_SONAR_DEEP_RESEARCH, MODEL_SONAR_REASONING, MODEL_SONAR_REASONING_PRO];

/// Perplexity's OpenAI-compatible chat completions. An HTML error page in
/// the stream (an authorization failure) becomes `(http_<status>)
/// <reason>` from its title or first heading.
///
/// PHP: `Utopia\Agents\Adapters\Perplexity`.
///
/// ```
/// use utopia_agents::{Adapter, Perplexity};
///
/// let adapter = Perplexity::new("test-api-key");
/// assert_eq!((adapter.name(), adapter.model()), ("perplexity", "sonar"));
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Perplexity;
///
/// $adapter = new Perplexity('test-api-key');
/// echo $adapter->getName(), ' ', $adapter->getModel(), "\n"; // perplexity sonar
/// ```
#[derive(Debug, Clone)]
pub struct Perplexity(Chat);

impl Perplexity {
    /// An adapter with the API key, model [`MODEL_SONAR`] and [`ENDPOINT`].
    ///
    /// PHP: `new Perplexity($apiKey)`.
    pub fn new(api_key: &str) -> Perplexity {
        Perplexity(Chat::new(api_key, MODEL_SONAR, ENDPOINT, Dialect::Perplexity))
    }
}

chat_adapter!(Perplexity, models: MODELS, schema: true);
