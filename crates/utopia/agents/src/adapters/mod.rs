//! The provider adapters.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Adapters\OpenAI` | [`OpenAi`] |
//! | `Adapters\Anthropic` | [`Anthropic`] |
//! | `Adapters\Gemini` | [`Gemini`] |
//! | `Adapters\Deepseek` | [`Deepseek`] |
//! | `Adapters\OpenRouter` | [`OpenRouter`] |
//! | `Adapters\OpenRouter\Models` | [`openrouter::models`] |
//! | `Adapters\Perplexity` | [`Perplexity`] |
//! | `Adapters\XAI` | [`Xai`] |
//! | `Adapters\Ollama` | [`Ollama`] |
//! | `Adapters\Appwrite` | [`Appwrite`] |

pub mod anthropic;
pub mod appwrite;
pub(crate) mod chat;
pub mod deepseek;
pub mod gemini;
pub mod ollama;
pub mod openai;
pub mod openrouter;
pub mod perplexity;
pub mod xai;

pub use anthropic::Anthropic;
pub use appwrite::Appwrite;
pub use deepseek::Deepseek;
pub use gemini::Gemini;
pub use ollama::Ollama;
pub use openai::OpenAi;
pub use openrouter::OpenRouter;
pub use perplexity::Perplexity;
pub use xai::Xai;

/// The builder methods, setters and [`crate::Adapter`] implementation of an
/// OpenAI-compatible adapter (a newtype around `chat::Chat`).
macro_rules! chat_adapter {
    ($ty:ident, models: $models:expr, schema: $schema:expr) => {
        impl $ty {
            /// With this model.
            ///
            /// PHP: the constructor's `$model`.
            pub fn with_model(mut self, model: &str) -> $ty {
                self.0.model = model.to_owned();
                self
            }

            /// With at most this many tokens per answer (default 1024).
            ///
            /// PHP: the constructor's `$maxTokens`.
            pub fn with_max_tokens(mut self, max_tokens: i64) -> $ty {
                self.0.max_tokens = max_tokens;
                self
            }

            /// With this sampling temperature (default 1.0).
            ///
            /// PHP: the constructor's `$temperature`.
            pub fn with_temperature(mut self, temperature: f64) -> $ty {
                self.0.temperature = temperature;
                self
            }

            /// Sending to this endpoint instead of the provider's.
            ///
            /// PHP: the constructor's `$endpoint`.
            pub fn with_endpoint(mut self, endpoint: &str) -> $ty {
                self.0.endpoint = endpoint.to_owned();
                self
            }

            /// With this request timeout, in milliseconds (default 90000).
            ///
            /// PHP: the constructor's `$timeout`.
            pub fn with_timeout(mut self, timeout: i64) -> $ty {
                self.0.base.assign_timeout(timeout);
                self
            }

            /// Sending through this client instead of one built on first
            /// use.
            ///
            /// PHP: the constructor's `$client`.
            pub fn with_client(mut self, client: std::sync::Arc<dyn $crate::Transport>) -> $ty {
                self.0.base.set_client(client);
                self
            }

            /// Changes the most tokens per answer.
            ///
            /// PHP: `setMaxTokens($maxTokens)`.
            pub fn set_max_tokens(&mut self, max_tokens: i64) -> &mut $ty {
                self.0.max_tokens = max_tokens;
                self
            }

            /// Changes the temperature.
            ///
            /// PHP: `setTemperature($temperature)`.
            pub fn set_temperature(&mut self, temperature: f64) -> &mut $ty {
                self.0.temperature = temperature;
                self
            }

            /// The endpoint requests go to.
            ///
            /// PHP: `getEndpoint()`.
            pub fn endpoint(&self) -> &str {
                &self.0.endpoint
            }

            /// Changes the endpoint.
            ///
            /// PHP: `setEndpoint($endpoint)`.
            pub fn set_endpoint(&mut self, endpoint: &str) -> &mut $ty {
                self.0.endpoint = endpoint.to_owned();
                self
            }
        }

        impl $crate::Adapter for $ty {
            fn name(&self) -> &str {
                self.0.name()
            }

            fn send<'a>(
                &'a mut self,
                profile: &'a $crate::Profile,
                messages: &'a [$crate::Message],
                listener: Option<$crate::adapter::Listener<'a>>,
            ) -> $crate::adapter::BoxFuture<'a, Result<$crate::Message, $crate::Error>> {
                Box::pin(self.0.send(profile, messages, listener))
            }

            fn models(&self) -> &'static [&'static str] {
                $models
            }

            fn model(&self) -> &str {
                &self.0.model
            }

            fn set_model(&mut self, model: &str) -> Result<(), $crate::Error> {
                self.0.model = model.to_owned();
                Ok(())
            }

            fn is_schema_supported(&self) -> bool {
                $schema
            }

            fn supports_embeddings(&self) -> bool {
                false
            }

            fn embed<'a>(
                &'a mut self,
                _text: &'a str,
            ) -> $crate::adapter::BoxFuture<'a, Result<$crate::adapter::Embedding, $crate::Error>> {
                Box::pin(async { Err($crate::adapter::no_embeddings()) })
            }

            fn bulk_embed<'a>(
                &'a mut self,
                _texts: &'a [String],
            ) -> $crate::adapter::BoxFuture<'a, Result<$crate::adapter::Embeddings, $crate::Error>> {
                Box::pin(async { Err($crate::adapter::no_embeddings()) })
            }

            fn embedding_dimension(&self) -> Result<i64, $crate::Error> {
                Err($crate::adapter::no_embeddings())
            }

            fn base(&self) -> &$crate::Base {
                &self.0.base
            }

            fn base_mut(&mut self) -> &mut $crate::Base {
                &mut self.0.base
            }

            fn supports_attachments(&self) -> bool {
                true
            }

            fn supports_attachment(&self, attachment: &$crate::Message) -> bool {
                $crate::adapter::is_image(attachment)
            }

            fn max_attachments_per_message(&self) -> Option<i64> {
                Some($crate::adapters::chat::MAX_ATTACHMENTS_PER_MESSAGE)
            }

            fn max_attachment_bytes(&self) -> Option<i64> {
                Some($crate::adapters::chat::MAX_ATTACHMENT_BYTES)
            }

            fn max_total_attachment_bytes(&self) -> Option<i64> {
                Some($crate::adapters::chat::MAX_TOTAL_ATTACHMENT_BYTES)
            }

            fn allowed_attachment_mime_types(&self) -> Option<std::borrow::Cow<'_, [&str]>> {
                Some(std::borrow::Cow::Borrowed($crate::adapters::chat::IMAGE_TYPES))
            }
        }
    };
}

pub(crate) use chat_adapter;
