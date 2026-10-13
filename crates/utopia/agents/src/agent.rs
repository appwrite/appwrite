//! Agents: an adapter with a description, instructions and a schema.

use crate::adapter::{Embedding, Embeddings, Listener};
use crate::{Adapter, Error, Message, Schema};

/// The content of one instruction: one text, or lines joined by `\n` when
/// sent.
///
/// PHP: `string|list<string>`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Instruction {
    /// One text.
    Text(String),
    /// Lines.
    Lines(Vec<String>),
}

impl Instruction {
    /// The text sent: the lines joined by `\n`.
    pub fn text(&self) -> String {
        match self {
            Instruction::Text(t) => t.clone(),
            Instruction::Lines(lines) => lines.join("\n"),
        }
    }
}

impl From<&str> for Instruction {
    fn from(text: &str) -> Instruction {
        Instruction::Text(text.to_owned())
    }
}

impl From<String> for Instruction {
    fn from(text: String) -> Instruction {
        Instruction::Text(text)
    }
}

/// What an agent tells its provider besides the conversation: a
/// description, named instructions and, for structured output, a schema.
/// Adapters read it in [`Adapter::send`].
///
/// PHP: the description, instructions and schema of `Utopia\Agents\Agent`,
/// which adapters read through `getAgent()`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Profile {
    description: String,
    instructions: Vec<(String, Instruction)>,
    schema: Option<Schema>,
}

impl Profile {
    /// The description (`""` when none).
    pub fn description(&self) -> &str {
        &self.description
    }

    /// The instructions, name and content, in order.
    pub fn instructions(&self) -> &[(String, Instruction)] {
        &self.instructions
    }

    /// The schema answers must follow, if any.
    pub fn schema(&self) -> Option<&Schema> {
        self.schema.as_ref()
    }

    /// Each instruction as sent: `# <name>\n\n<text>`.
    pub(crate) fn instruction_texts(&self) -> impl Iterator<Item = String> + '_ {
        self.instructions.iter().map(|(name, content)| format!("# {name}\n\n{}", content.text()))
    }
}

/// An AI agent: a provider adapter, with a description, instructions and
/// optionally a schema its answers must follow.
///
/// PHP: `Utopia\Agents\Agent`. PHP's agent and adapter refer to each other
/// (`new Agent($adapter)` calls `$adapter->setAgent($this)`); here the
/// agent owns its adapter ([`Agent::adapter`], [`Agent::into_adapter`]).
///
/// ```
/// use utopia_agents::{Agent, OpenAi};
///
/// let mut agent = Agent::new(OpenAi::new("test-api-key"));
/// agent
///     .set_description("You answer questions about the weather.")
///     .add_instruction("Tone", "Be brief.");
///
/// assert_eq!(agent.description(), "You answer questions about the weather.");
/// assert_eq!(agent.instructions().len(), 1);
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\OpenAI;
/// use Utopia\Agents\Agent;
///
/// $agent = new Agent(new OpenAI('test-api-key'));
/// $agent
///     ->setDescription('You answer questions about the weather.')
///     ->addInstruction('Tone', 'Be brief.');
///
/// echo $agent->getDescription(), "\n"; // You answer questions about the weather.
/// echo count($agent->getInstructions()), "\n"; // 1
/// ```
#[derive(Debug, Clone)]
pub struct Agent<A> {
    adapter: A,
    profile: Profile,
}

impl<A: Adapter> Agent<A> {
    /// An agent on `adapter`, without description, instructions or schema.
    ///
    /// PHP: `new Agent($adapter)`.
    pub fn new(adapter: A) -> Agent<A> {
        Agent { adapter, profile: Profile::default() }
    }

    /// An agent on `adapter` with a profile.
    pub(crate) fn from_parts(adapter: A, profile: Profile) -> Agent<A> {
        Agent { adapter, profile }
    }

    /// Sets the description.
    ///
    /// PHP: `setDescription($description)`.
    pub fn set_description(&mut self, description: &str) -> &mut Agent<A> {
        self.profile.description = description.to_owned();
        self
    }

    /// Replaces the instructions. A name given twice keeps its first place
    /// and its last content, as keys of a PHP array do.
    ///
    /// PHP: `setInstructions($instructions)`.
    pub fn set_instructions(&mut self, instructions: Vec<(String, Instruction)>) -> &mut Agent<A> {
        self.profile.instructions = Vec::with_capacity(instructions.len());
        for (name, content) in instructions {
            self.put(name, content);
        }
        self
    }

    /// `$instructions[$name] = $content`.
    fn put(&mut self, name: String, content: Instruction) {
        match self.profile.instructions.iter_mut().find(|(n, _)| *n == name) {
            Some((_, existing)) => *existing = content,
            None => self.profile.instructions.push((name, content)),
        }
    }

    /// Adds an instruction, or replaces the content of the one of that name
    /// in place.
    ///
    /// PHP: `addInstruction($name, $content)`.
    pub fn add_instruction(&mut self, name: &str, content: &str) -> &mut Agent<A> {
        self.put(name.to_owned(), Instruction::Text(content.to_owned()));
        self
    }

    /// The description.
    ///
    /// PHP: `getDescription()`.
    pub fn description(&self) -> &str {
        &self.profile.description
    }

    /// The instructions, in order.
    ///
    /// PHP: `getInstructions()`.
    pub fn instructions(&self) -> &[(String, Instruction)] {
        &self.profile.instructions
    }

    /// The adapter.
    ///
    /// PHP: `getAdapter()`.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// The adapter, to change it.
    pub fn adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }

    /// The adapter, taken back.
    pub fn into_adapter(self) -> A {
        self.adapter
    }

    /// The description, instructions and schema adapters read.
    pub fn profile(&self) -> &Profile {
        &self.profile
    }

    /// The schema, if any.
    ///
    /// PHP: `getSchema()`.
    pub fn schema(&self) -> Option<&Schema> {
        self.profile.schema.as_ref()
    }

    /// Makes answers follow a schema.
    ///
    /// Fails with [`Error::Exception`] (`Schema is not supported for this
    /// model`) when the adapter has no structured output.
    ///
    /// PHP: `setSchema($schema)`.
    pub fn set_schema(&mut self, schema: Schema) -> Result<&mut Agent<A>, Error> {
        if !self.adapter.is_schema_supported() {
            return Err(Error::exception("Schema is not supported for this model"));
        }
        self.profile.schema = Some(schema);
        Ok(self)
    }

    /// Sends messages to the adapter with this agent's profile: what PHP's
    /// `$adapter->send($messages, $listener)` does for an adapter whose
    /// agent this is.
    pub async fn send<'a>(&'a mut self, messages: &'a [Message], listener: Option<Listener<'a>>) -> Result<Message, Error> {
        self.adapter.send(&self.profile, messages, listener).await
    }

    /// Embeds a text with the adapter.
    ///
    /// Fails with [`Error::Exception`] (`This adapter does not support
    /// embedding/embedding API.`) when it does not embed.
    ///
    /// PHP: `embed($text)`.
    pub async fn embed(&mut self, text: &str) -> Result<Embedding, Error> {
        if !self.adapter.supports_embeddings() {
            return Err(Error::exception("This adapter does not support embedding/embedding API."));
        }
        self.adapter.embed(text).await
    }

    /// Embeds several texts with the adapter.
    ///
    /// PHP: `bulkEmbed($texts)`.
    pub async fn bulk_embed(&mut self, texts: &[String]) -> Result<Embeddings, Error> {
        if !self.adapter.supports_embeddings() {
            return Err(Error::exception("This adapter does not support embedding/embedding API."));
        }
        self.adapter.bulk_embed(texts).await
    }
}
