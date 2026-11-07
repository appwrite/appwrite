//! Appwrite's embedding service.

use std::sync::Arc;

use php_std::Value;

use super::ollama::invalid_model;
use crate::adapter::{BoxFuture, Embedding, Embeddings, Listener};
use crate::php::{self, map};
use crate::{Adapter, Base, Error, Message, Profile, Transport};

/// Nomic Embed Text v1.5: general purpose (the default).
pub const MODEL_NOMIC_EMBED_TEXT: &str = "nomic-embed-text";
/// EmbeddingGemma 300M.
pub const MODEL_EMBEDDING_GEMMA: &str = "embedding-gemma";
/// all-MiniLM-L6-v2: small and fast.
pub const MODEL_ALL_MINILM: &str = "all-minilm";
/// BGE Small EN v1.5: small, English.
pub const MODEL_BGE_SMALL: &str = "bge-small";

/// Every model.
///
/// PHP: `Appwrite::MODELS`.
pub const MODELS: &[&str] = &[MODEL_NOMIC_EMBED_TEXT, MODEL_EMBEDDING_GEMMA, MODEL_ALL_MINILM, MODEL_BGE_SMALL];

/// The embed endpoint of the embedding service.
pub const ENDPOINT: &str = "http://appwrite-embedding:11434/embed";

/// The size of a model's embeddings.
///
/// PHP: `Appwrite::DIMENSIONS`.
pub fn dimension(model: &str) -> Option<i64> {
    match model {
        MODEL_NOMIC_EMBED_TEXT | MODEL_EMBEDDING_GEMMA => Some(768),
        MODEL_ALL_MINILM | MODEL_BGE_SMALL => Some(384),
        _ => None,
    }
}

/// Embeddings from Appwrite's embedding service (no API key), several texts
/// per request. It does not chat: [`Adapter::send`] fails.
///
/// PHP: `Utopia\Agents\Adapters\Appwrite`.
///
/// ```
/// use utopia_agents::{Adapter, Appwrite, adapters::appwrite};
///
/// let adapter = Appwrite::with_model(appwrite::MODEL_ALL_MINILM)?;
/// assert_eq!(adapter.name(), "appwrite-embedding");
/// assert_eq!(adapter.embedding_dimension()?, 384);
/// # Ok::<(), utopia_agents::Error>(())
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\Appwrite;
///
/// $adapter = new Appwrite(Appwrite::MODEL_ALL_MINILM);
/// echo $adapter->getName(), ' ', $adapter->getEmbeddingDimension(), "\n"; // appwrite-embedding 384
/// ```
#[derive(Debug, Clone)]
pub struct Appwrite {
    base: Base,
    model: String,
    endpoint: String,
}

impl Default for Appwrite {
    fn default() -> Appwrite {
        Appwrite::new()
    }
}

impl Appwrite {
    /// An adapter on [`MODEL_NOMIC_EMBED_TEXT`] at [`ENDPOINT`].
    ///
    /// PHP: `new Appwrite()`.
    pub fn new() -> Appwrite {
        Appwrite { base: Base::default(), model: MODEL_NOMIC_EMBED_TEXT.to_owned(), endpoint: ENDPOINT.to_owned() }
    }

    /// An adapter on `model`; [`Error::InvalidArgument`] for a model not in
    /// [`MODELS`].
    ///
    /// PHP: `new Appwrite($model)`.
    pub fn with_model(model: &str) -> Result<Appwrite, Error> {
        if !MODELS.contains(&model) {
            return Err(invalid_model(model, MODELS));
        }
        Ok(Appwrite { model: model.to_owned(), ..Appwrite::new() })
    }

    /// With this timeout, in milliseconds.
    pub fn with_timeout(mut self, timeout: i64) -> Appwrite {
        self.base.set_timeout(timeout);
        self
    }

    /// Sending through this client.
    pub fn with_client(mut self, client: Arc<dyn Transport>) -> Appwrite {
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
    pub fn set_endpoint(&mut self, endpoint: &str) -> &mut Appwrite {
        self.endpoint = endpoint.to_owned();
        self
    }

    async fn embeddings(&mut self, texts: &[String]) -> Result<Embeddings, Error> {
        if texts.is_empty() {
            return Err(Error::InvalidArgument("bulkEmbed requires at least one text".into()));
        }
        let payload = map([
            ("model", Value::from(self.model.as_str())),
            ("texts", php::list(texts.iter().map(|t| Value::from(t.as_str())).collect())),
        ]);
        let request = Base::request(&self.endpoint, &payload, &[])?;
        let response = self.base.client()?.send(request).await?;
        let status = i64::from(response.status());
        let json = php::decode(response.bytes());
        if !matches!(json, Value::Array(_)) {
            return Err(Error::exception("Invalid response format received from the API"));
        }
        if let Some(error) = php::get(&json, "error") {
            let message = match error {
                Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                _ => "Unknown error".to_owned(),
            };
            return Err(Error::Exception { message, code: status });
        }
        let rows = match php::array(&json, "embeddings") {
            Some(Value::Array(rows)) if rows.len() == texts.len() => rows,
            _ => {
                return Err(Error::Exception {
                    message: "Embedding response missing or count mismatch".into(),
                    code: status,
                });
            }
        };
        let mut embeddings = Vec::with_capacity(rows.len());
        for (i, row) in rows.iter() {
            match row {
                Value::Array(vector) if !vector.is_empty() => embeddings.push(vector.clone()),
                _ => {
                    return Err(Error::Exception {
                        message: format!("Embedding row {} missing or empty", php::key_string(i)),
                        code: status,
                    });
                }
            }
        }
        Ok(Embeddings {
            embeddings,
            tokens_processed: php::int(&json, "tokens"),
            total_duration: php::int(&json, "total_duration"),
        })
    }

    async fn embedding(&mut self, text: &str) -> Result<Embedding, Error> {
        let mut result = self.embeddings(std::slice::from_ref(&text.to_owned())).await?;
        Ok(Embedding {
            embedding: result.embeddings.swap_remove(0),
            tokens_processed: result.tokens_processed,
            total_duration: result.total_duration,
            model_loading_duration: None,
        })
    }
}

impl Adapter for Appwrite {
    fn name(&self) -> &str {
        "appwrite-embedding"
    }

    fn send<'a>(
        &'a mut self,
        _profile: &'a Profile,
        _messages: &'a [Message],
        _listener: Option<Listener<'a>>,
    ) -> BoxFuture<'a, Result<Message, Error>> {
        Box::pin(async { Err(Error::exception("Appwrite does not support chat or messages. Use embed() instead.")) })
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
