//! OpenRouter.

use super::chat::{Chat, Dialect};
use super::chat_adapter;

/// The chat completions endpoint.
///
/// PHP: `OpenRouter::ENDPOINT`.
pub const ENDPOINT: &str = "https://openrouter.ai/api/v1/chat/completions";

/// OpenRouter's model catalog: a named constant per model of the curated
/// providers (Anthropic, OpenAI, Google, Meta, Deepseek, Mistral, xAI) and
/// [`MODELS`](models::MODELS), every model id, generated from
/// `data/openrouter-constants.txt` and `data/openrouter-models.txt`, which
/// the `agents-sync-openrouter-models` task refreshes.
///
/// PHP: `Utopia\Agents\Adapters\OpenRouter\Models`.
pub mod models {
    include!(concat!(env!("OUT_DIR"), "/openrouter_models.rs"));
}

/// OpenRouter's OpenAI-compatible chat completions, routed to any
/// provider's model (`meta-llama/llama-3.3-70b-instruct`). Structured
/// output is not offered: not every routed model supports it. The routed
/// model's provider prefix and `:` suffix are ignored when choosing the
/// token limit parameter and temperature (`openai/gpt-5-nano:free` is
/// treated as `gpt-5-nano`). An app URL and title, when given, go in the
/// `HTTP-Referer` and `X-Title` headers.
///
/// PHP: `Utopia\Agents\Adapters\OpenRouter`.
///
/// ```
/// use utopia_agents::{Adapter, OpenRouter, adapters::openrouter::models};
///
/// let adapter = OpenRouter::new("test-api-key").with_app(Some("https://example.com"), Some("Example"));
/// assert_eq!(adapter.model(), models::MODEL_OPENAI_GPT_4O);
/// assert!(adapter.models().contains(&"openai/gpt-4o"));
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\OpenRouter;
/// use Utopia\Agents\Adapters\OpenRouter\Models;
///
/// $adapter = new OpenRouter('test-api-key', httpReferer: 'https://example.com', xTitle: 'Example');
/// echo $adapter->getModel() === Models::MODEL_OPENAI_GPT_4O ? "yes\n" : "no\n"; // yes
/// echo in_array('openai/gpt-4o', $adapter->getModels(), true) ? "yes\n" : "no\n"; // yes
/// ```
#[derive(Debug, Clone)]
pub struct OpenRouter(Chat);

impl OpenRouter {
    /// An adapter with the API key, model
    /// [`MODEL_OPENAI_GPT_4O`](models::MODEL_OPENAI_GPT_4O) and [`ENDPOINT`].
    ///
    /// PHP: `new OpenRouter($apiKey)`.
    pub fn new(api_key: &str) -> OpenRouter {
        OpenRouter(Chat::new(
            api_key,
            models::MODEL_OPENAI_GPT_4O,
            ENDPOINT,
            Dialect::OpenRouter { http_referer: None, x_title: None },
        ))
    }

    /// With the app's URL and title, sent as `HTTP-Referer` and `X-Title`
    /// (left out when empty or `"0"`).
    ///
    /// PHP: the constructor's `$httpReferer` and `$xTitle`.
    pub fn with_app(mut self, http_referer: Option<&str>, x_title: Option<&str>) -> OpenRouter {
        self.0.dialect =
            Dialect::OpenRouter { http_referer: http_referer.map(str::to_owned), x_title: x_title.map(str::to_owned) };
        self
    }
}

chat_adapter!(OpenRouter, models: models::MODELS, schema: false);
