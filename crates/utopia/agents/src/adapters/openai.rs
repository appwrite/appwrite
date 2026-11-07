//! OpenAI.

use super::chat::{Chat, Dialect};
use super::chat_adapter;

/// GPT-5 Nano: a small GPT-5 for low latency and cost-sensitive work.
pub const MODEL_GPT_5_NANO: &str = "gpt-5-nano";
/// GPT-4.5 Preview.
pub const MODEL_GPT_4_5_PREVIEW: &str = "gpt-4.5-preview";
/// GPT-4.1.
pub const MODEL_GPT_4_1: &str = "gpt-4.1";
/// GPT-4o: multimodal, text and images.
pub const MODEL_GPT_4O: &str = "gpt-4o";
/// o4-mini.
pub const MODEL_O4_MINI: &str = "o4-mini";
/// o3.
pub const MODEL_O3: &str = "o3";
/// o3-mini (the default).
pub const MODEL_O3_MINI: &str = "o3-mini";

/// The chat completions endpoint.
///
/// PHP: `OpenAI::ENDPOINT`.
pub const ENDPOINT: &str = "https://api.openai.com/v1/chat/completions";

/// Every model, in PHP's order.
///
/// PHP: `getModels()`.
pub const MODELS: &[&str] =
    &[MODEL_GPT_5_NANO, MODEL_GPT_4_5_PREVIEW, MODEL_GPT_4_1, MODEL_GPT_4O, MODEL_O4_MINI, MODEL_O3, MODEL_O3_MINI];

/// OpenAI's chat completions: answers streamed token by token, or, with a
/// schema, a JSON answer in strict `json_schema` mode. Images attach as
/// data URLs. `gpt-5-nano` only takes temperature 1 (another is replaced,
/// with a warning on standard error), and the o-series and `gpt-5-nano`
/// take `max_completion_tokens` instead of `max_tokens`.
///
/// PHP: `Utopia\Agents\Adapters\OpenAI`: `new OpenAI($apiKey, $model,
/// $maxTokens, $temperature, $endpoint, $timeout, $client)` is
/// `OpenAi::new(api_key)` and the `with_*` methods.
///
/// ```
/// use utopia_agents::{Adapter, OpenAi, adapters::openai};
///
/// let adapter = OpenAi::new("test-api-key").with_model(openai::MODEL_GPT_4O).with_max_tokens(2048);
/// assert_eq!(adapter.name(), "openai");
/// assert_eq!(adapter.model(), "gpt-4o");
/// assert_eq!(adapter.endpoint(), "https://api.openai.com/v1/chat/completions");
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\OpenAI;
///
/// $adapter = new OpenAI('test-api-key', OpenAI::MODEL_GPT_4O, 2048);
/// echo $adapter->getName(), ' ', $adapter->getModel(), ' ', $adapter->getEndpoint(), "\n";
/// // openai gpt-4o https://api.openai.com/v1/chat/completions
/// ```
#[derive(Debug, Clone)]
pub struct OpenAi(Chat);

impl OpenAi {
    /// An adapter with the API key, model [`MODEL_O3_MINI`], 1024 tokens,
    /// temperature 1.0 and [`ENDPOINT`].
    ///
    /// PHP: `new OpenAI($apiKey)`.
    pub fn new(api_key: &str) -> OpenAi {
        OpenAi(Chat::new(api_key, MODEL_O3_MINI, ENDPOINT, Dialect::OpenAi))
    }
}

chat_adapter!(OpenAi, models: MODELS, schema: true);
