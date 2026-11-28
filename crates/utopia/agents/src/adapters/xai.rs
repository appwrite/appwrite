//! xAI.

use super::chat::{Chat, Dialect};
use super::chat_adapter;

/// The chat completions endpoint.
///
/// PHP: `XAI::ENDPOINT`.
pub const ENDPOINT: &str = "https://api.x.ai/v1/chat/completions";

/// Grok 3.
pub const MODEL_GROK_3: &str = "grok-3";
/// Grok 3 Mini (the default).
pub const MODEL_GROK_3_MINI: &str = "grok-3-mini";
/// Grok 2 Image.
pub const MODEL_GROK_2_IMAGE: &str = "grok-2-image-1212";

/// Every model, in PHP's order.
pub const MODELS: &[&str] = &[MODEL_GROK_3, MODEL_GROK_3_MINI, MODEL_GROK_2_IMAGE];

/// xAI's OpenAI-compatible chat completions, without structured output.
/// Errors read `{"code": ..., "error": "..."}`.
///
/// PHP: `Utopia\Agents\Adapters\XAI`.
///
/// ```
/// use utopia_agents::{Adapter, Xai};
///
/// let adapter = Xai::new("test-api-key");
/// assert_eq!((adapter.name(), adapter.model()), ("xai", "grok-3-mini"));
/// assert!(!adapter.is_schema_supported());
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\XAI;
///
/// $adapter = new XAI('test-api-key');
/// echo $adapter->getName(), ' ', $adapter->getModel(), "\n"; // xai grok-3-mini
/// var_dump($adapter->isSchemaSupported()); // bool(false)
/// ```
#[derive(Debug, Clone)]
pub struct Xai(Chat);

impl Xai {
    /// An adapter with the API key, model [`MODEL_GROK_3_MINI`] and
    /// [`ENDPOINT`].
    ///
    /// PHP: `new XAI($apiKey)`.
    pub fn new(api_key: &str) -> Xai {
        Xai(Chat::new(api_key, MODEL_GROK_3_MINI, ENDPOINT, Dialect::Xai))
    }
}

chat_adapter!(Xai, models: MODELS, schema: false);
