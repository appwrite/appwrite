//! AI agents on LLM providers: an [`Agent`] (a provider [`Adapter`] with a
//! description, instructions and an optional [`Schema`] for structured
//! output) holds [`Conversation`]s of [`Message`]s, which may carry image
//! attachments. Answers stream token by token to a listener, and tokens are
//! counted by kind ([`Usage`]). Embedding adapters turn texts into vectors.
//! [`DiffCheck`] asks an agent about the difference between two versions of
//! a repository.
//!
//! Adapters: [`OpenAi`], [`Anthropic`], [`Gemini`], [`Deepseek`],
//! [`OpenRouter`] (with its generated model catalog), [`Perplexity`],
//! [`Xai`], and the embedding adapters [`Ollama`] and [`Appwrite`]. They
//! send JSON over `utopia-client` (a keep-alive client built on first use,
//! or the [`Transport`] given), and parse server-sent events split anywhere
//! across chunks.
//!
//! ```no_run
//! use utopia_agents::{Agent, Conversation, Message, OpenAi, User};
//!
//! # async fn run() -> Result<(), utopia_agents::Error> {
//! let mut agent = Agent::new(OpenAi::new("sk-..."));
//! agent.set_description("You are a helpful assistant.");
//!
//! let mut conversation = Conversation::new(agent);
//! conversation.message(&User::new("user-1", ""), Message::new("What is Appwrite?"), Vec::new())?;
//! let answer = conversation.send().await?;
//! println!("{}", answer.text());
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Agents\Adapters\OpenAI;
//! use Utopia\Agents\Agent;
//! use Utopia\Agents\Conversation;
//! use Utopia\Agents\Message;
//! use Utopia\Agents\Roles\User;
//!
//! $agent = new Agent(new OpenAI('sk-...'));
//! $agent->setDescription('You are a helpful assistant.');
//!
//! $conversation = new Conversation($agent);
//! $conversation->message(new User('user-1'), new Message('What is Appwrite?'));
//! // echo $conversation->send()->getContent(), "\n";
//! ```
//!
//! PHP: `utopia-php/agents`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Agent` | [`Agent`] (its description, instructions and schema: [`Profile`]) |
//! | `Adapter` | [`Adapter`], with the shared state [`Base`]; token counters: [`Usage`]; the injectable client: [`Transport`] |
//! | `Conversation` | [`Conversation`] |
//! | `Message` | [`Message`] |
//! | `Role`, `Roles\User`, `Roles\Assistant` | [`Role`], [`User`], [`Assistant`], [`ROLE_USER`], [`ROLE_ASSISTANT`] |
//! | `Schema` | [`Schema`] |
//! | `Schema\SchemaObject` | [`SchemaObject`], [`schema::types`], [`schema::VALID_TYPES`] |
//! | `Adapters\*` | [`adapters`] |
//! | `DiffCheck\DiffCheck`, `DiffCheck\Options`, `DiffCheck\Repository` | [`DiffCheck`], [`diff_check::Options`], [`diff_check::Repository`] |
//! | the arrays `embed()` and `bulkEmbed()` return | [`Embedding`], [`Embeddings`] |
//! | `\Exception`, `\InvalidArgumentException`, `\RuntimeException` | [`Error`] |
//! | `scripts/sync-openrouter-models.php` | the `agents-sync-openrouter-models` binary |
#![warn(missing_docs)]

pub mod adapter;
pub mod adapters;
mod agent;
mod conversation;
pub mod diff_check;
mod error;
mod message;
mod mime;
mod php;
mod role;
pub mod schema;

pub use adapter::{Adapter, Base, BoxFuture, Embedding, Embeddings, Listener, Transport, Usage};
pub use adapters::{Anthropic, Appwrite, Deepseek, Gemini, Ollama, OpenAi, OpenRouter, Perplexity, Xai};
pub use agent::{Agent, Instruction, Profile};
pub use conversation::Conversation;
pub use diff_check::DiffCheck;
pub use error::Error;
pub use message::Message;
pub use role::{Assistant, ROLE_ASSISTANT, ROLE_USER, Role, User};
pub use schema::{Schema, SchemaObject, property};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
