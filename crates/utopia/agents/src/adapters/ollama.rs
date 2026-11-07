//! Ollama (embeddings).

use std::sync::Arc;

use php_std::{Array, Value};

use crate::adapter::{BoxFuture, Embedding, Embeddings, Listener};
use crate::php::{self, map};
use crate::{Adapter, Base, Error, Message, Profile, Transport};

/// EmbeddingGemma (the default and only model).
pub const MODEL_EMBEDDING_GEMMA: &str = "embeddinggemma";

/// Every model.
///
/// PHP: `Ollama::MODELS`.
pub const MODELS: &[&str] = &[MODEL_EMBEDDING_GEMMA];

/// The embed endpoint of the local Ollama service.
pub const ENDPOINT: &str = "http://ollama:11434/api/embed";

/// The size of a model's embeddings.
///
/// PHP: `Ollama::DIMENSIONS`.
pub fn dimension(model: &str) -> Option<i64> {
    (model == MODEL_EMBEDDING_GEMMA).then_some(768)
}

/// Embeddings from a local Ollama service (no API key). It does not chat:
/// [`Adapter::send`] fails.
///
/// PHP: `Utopia\Agents\Adapters\Ollama`: `new Ollama($model, $timeout,
/// $client)` is [`Ollama::with_model`] and the `with_*` methods.
///
/// ```
/// use utopia_agents::{Adapter, Ollama};
///
/// let adapter = Ollama::new();
/// assert_eq!((adapter.name(), adapter.model()), ("ollama", "embeddinggemma"));
/// assert_eq!(adapter.embedding_dimension()?, 768);
/// assert!(Ollama::with_model("unknown").is_err());
/// # Ok::<(), utopia_agents::Error>(())
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Ollama;
///
/// $adapter = new Ollama();
/// echo $adapter->getName(), ' ', $adapter->getModel(), ' ', $adapter->getEmbeddingDimension(), "\n";
/// // ollama embeddinggemma 768
/// // new Ollama('unknown') throws InvalidArgumentException
/// ```
#[derive(Debug, Clone)]
pub struct Ollama {
    base: Base,
    model: String,
    endpoint: String,
}

impl Default for Ollama {
    fn default() -> Ollama {
        Ollama::new()
    }
}

/// `Invalid model: <model>. Supported models: <models>`.
pub(crate) fn invalid_model(model: &str, models: &[&str]) -> Error {
    Error::InvalidArgument(format!("Invalid model: {model}. Supported models: {}", models.join(", ")))
}

impl Ollama {
    /// An adapter on [`MODEL_EMBEDDING_GEMMA`] at [`ENDPOINT`].
    ///
    /// PHP: `new Ollama()`.
    pub fn new() -> Ollama {
        Ollama { base: Base::default(), model: MODEL_EMBEDDING_GEMMA.to_owned(), endpoint: ENDPOINT.to_owned() }
    }

    /// An adapter on `model`; [`Error::InvalidArgument`] for a model not in
    /// [`MODELS`].
    ///
    /// PHP: `new Ollama($model)`.
    pub fn with_model(model: &str) -> Result<Ollama, Error> {
        if !MODELS.contains(&model) {
            return Err(invalid_model(model, MODELS));
        }
        Ok(Ollama { model: model.to_owned(), ..Ollama::new() })
    }

    /// With this timeout, in milliseconds.
    pub fn with_timeout(mut self, timeout: i64) -> Ollama {
        self.base.set_timeout(timeout);
        self
    }

    /// Sending through this client.
    pub fn with_client(mut self, client: Arc<dyn Transport>) -> Ollama {
        self.base.set_client(client);
        self
    }

    /// The endpoint.
    ///
    /// PHP: `getEndpoint()`.
    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    /// Changes the endpoint.
    ///
    /// PHP: `setEndpoint($endpoint)`.
    pub fn set_endpoint(&mut self, endpoint: &str) -> &mut Ollama {
        self.endpoint = endpoint.to_owned();
        self
    }

    async fn embedding(&mut self, text: &str) -> Result<Embedding, Error> {
        let payload = map([("model", Value::from(self.model.as_str())), ("input", Value::from(text))]);
        let request = Base::request(&self.endpoint, &payload, &[])?;
        let response = self.base.client()?.send(request).await?;
        let json = php::decode(response.bytes());
        if !matches!(json, Value::Array(_)) {
            return Err(Error::exception("Invalid response format received from the API"));
        }
        if let Some(error) = php::get(&json, "error") {
            let message = match error {
                Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                _ => "Unknown error".to_owned(),
            };
            return Err(Error::Exception { message, code: i64::from(response.status()) });
        }
        let embedding = match php::array(&json, "embeddings").and_then(|e| php::at(e, 0)) {
            Some(Value::Array(first)) => first.clone(),
            _ => Array::new(),
        };
        Ok(Embedding {
            embedding,
            tokens_processed: php::int(&json, "prompt_eval_count"),
            total_duration: php::int(&json, "total_duration"),
            model_loading_duration: Some(php::int(&json, "load_duration")),
        })
    }

    async fn embeddings(&mut self, texts: &[String]) -> Result<Embeddings, Error> {
        if texts.is_empty() {
            return Err(Error::InvalidArgument("bulkEmbed requires at least one text".into()));
        }
        let mut out =
            Embeddings { embeddings: Vec::with_capacity(texts.len()), tokens_processed: None, total_duration: None };
        for text in texts {
            let result = self.embedding(text).await?;
            out.embeddings.push(result.embedding);
            if let Some(tokens) = result.tokens_processed {
                out.tokens_processed = Some(out.tokens_processed.unwrap_or(0).wrapping_add(tokens));
            }
            if let Some(duration) = result.total_duration {
                out.total_duration = Some(out.total_duration.unwrap_or(0).wrapping_add(duration));
            }
        }
        Ok(out)
    }
}

impl Adapter for Ollama {
    fn name(&self) -> &str {
        "ollama"
    }

    fn send<'a>(
        &'a mut self,
        _profile: &'a Profile,
        _messages: &'a [Message],
        _listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        Box::pin(async {
            Err(Error::exception("OllamaAdapter does not support chat or messages. Use embed() instead."))
        })
    }

    fn models(&self) -> &'static [&'static str] {
        MODELS
    }

    fn model(&self) -> &str {
        &self.model
    }

    fn set_model(&mut self, model: &str) -> Result<(), Error> {
        if !MODELS.contains(&model) {
            return Err(invalid_model(model, MODELS));
        }
        self.model = model.to_owned();
        Ok(())
    }

    fn is_schema_supported(&self) -> bool {
        false
    }

    fn supports_embeddings(&self) -> bool {
        true
    }

    fn embed<'a>(&'a mut self, text: &'a str) -> BoxFuture<'a, Result<Embedding, Error>> {
        Box::pin(self.embedding(text))
    }

    fn bulk_embed<'a>(&'a mut self, texts: &'a [String]) -> BoxFuture<'a, Result<Embeddings, Error>> {
        Box::pin(self.embeddings(texts))
    }

    fn embedding_dimension(&self) -> Result<i64, Error> {
        Ok(dimension(&self.model).unwrap_or(0))
    }

    fn base(&self) -> &Base {
        &self.base
    }

    fn base_mut(&mut self) -> &mut Base {
        &mut self.base
    }
}
