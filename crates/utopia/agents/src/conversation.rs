//! Conversations: the messages exchanged with an agent.

use crate::adapter::Usage;
use crate::{Adapter, Agent, Assistant, Error, Message, Role};

/// A listener of streamed tokens a conversation keeps.
type Tokens = dyn FnMut(&str) + Send;

/// A conversation with an agent: messages added in order, sent together,
/// the answer added as the assistant's, and the tokens every send used.
///
/// PHP: `Utopia\Agents\Conversation`.
///
/// ```no_run
/// use utopia_agents::{Agent, Conversation, Message, OpenAi, User};
///
/// # async fn run() -> Result<(), utopia_agents::Error> {
/// let mut agent = Agent::new(OpenAi::new("sk-..."));
/// agent.set_description("You are a helpful assistant.");
///
/// let mut conversation = Conversation::new(agent);
/// conversation.listen(|token: &str| print!("{token}"));
/// conversation.message(&User::new("user-1", "Ada"), Message::new("Hello!"), Vec::new())?;
/// let answer = conversation.send().await?;
/// println!("\n{} tokens", conversation.usage().total());
/// # let _ = answer;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Agents\Adapters\OpenAI;
/// use Utopia\Agents\Agent;
/// use Utopia\Agents\Conversation;
/// use Utopia\Agents\Message;
/// use Utopia\Agents\Roles\User;
///
/// $agent = new Agent(new OpenAI('sk-...'));
/// $agent->setDescription('You are a helpful assistant.');
///
/// $conversation = new Conversation($agent);
/// $conversation->listen(fn (string $token) => print($token));
/// $conversation->message(new User('user-1', 'Ada'), new Message('Hello!'));
/// // $answer = $conversation->send(); echo "\n", $conversation->getTotalTokens(), " tokens\n";
/// ```
pub struct Conversation<A> {
    agent: Agent<A>,
    messages: Vec<Message>,
    usage: Usage,
    listener: Option<Box<Tokens>>,
}

impl<A> std::fmt::Debug for Conversation<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Conversation")
            .field("messages", &self.messages)
            .field("usage", &self.usage)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Conversation<A> {
    /// A conversation with `agent`, without messages.
    ///
    /// PHP: `new Conversation($agent)`.
    pub fn new(agent: Agent<A>) -> Conversation<A> {
        Conversation { agent, messages: Vec::new(), usage: Usage::default(), listener: None }
    }

    /// Passes each token of streamed answers to `listener`.
    ///
    /// PHP: `listen($listener)`.
    pub fn listen(&mut self, listener: impl FnMut(&str) + Send + 'static) -> &mut Conversation<A> {
        self.listener = Some(Box::new(listener));
        self
    }

    /// Whether a listener was set.
    ///
    /// PHP: `getListener()` (a no-op closure until `listen()`).
    pub fn has_listener(&self) -> bool {
        self.listener.is_some()
    }

    /// Adds a message from `from`, with attachments: the message and its
    /// attachments (those it has and those given) take `from`'s role.
    ///
    /// Fails with [`Error::InvalidArgument`], adding nothing, when the
    /// attachments break the adapter's limits: too many, an empty one, one
    /// too large, one of an undetectable or disallowed MIME type, one the
    /// adapter does not support, or too many bytes in total.
    ///
    /// PHP: `message($from, $message, $attachments)`.
    pub fn message(
        &mut self,
        from: &dyn Role,
        message: Message,
        attachments: Vec<Message>,
    ) -> Result<&mut Conversation<A>, Error> {
        let role = from.identifier();
        let mut entry = message.with_role(role);
        let existing = entry.attachments().iter().map(|a| a.with_role(role)).collect();
        entry.set_attachments(existing);

        self.validate(&entry, &attachments)?;

        for attachment in attachments {
            entry.add_attachment(attachment.with_role(role));
        }
        self.messages.push(entry);
        Ok(self)
    }

    /// Sends the conversation, counts the tokens the adapter reports, and
    /// adds the answer as the assistant's message.
    ///
    /// The adapter's counters are cumulative, and each send adds them as
    /// they stand: a second send counts the first one's tokens again, as
    /// PHP does.
    ///
    /// PHP: `send()`.
    pub async fn send(&mut self) -> Result<Message, Error> {
        let mut listener = self.listener.take();
        let answer = {
            let listener = listener.as_mut().map(|l| &mut **l as &mut (dyn FnMut(&str) + Send));
            self.agent.send(&self.messages, listener).await
        };
        self.listener = listener;
        let answer = answer?;

        let usage = *self.agent.adapter().usage();
        self.usage
            .count_input(usage.input())
            .count_output(usage.output())
            .count_cache_creation_input(usage.cache_creation_input())
            .count_cache_read_input(usage.cache_read_input());

        let from = Assistant::new(self.agent.adapter().model(), "Assistant");
        self.message(&from, answer.clone(), Vec::new())?;
        Ok(answer)
    }

    /// The messages, in order.
    ///
    /// PHP: `getMessages()`.
    pub fn messages(&self) -> &[Message] {
        &self.messages
    }

    /// The agent.
    ///
    /// PHP: `getAgent()`.
    pub fn agent(&self) -> &Agent<A> {
        &self.agent
    }

    /// The agent, to change it.
    pub fn agent_mut(&mut self) -> &mut Agent<A> {
        &mut self.agent
    }

    /// The tokens counted so far.
    ///
    /// PHP: `getInputTokens()`, `getOutputTokens()`,
    /// `getCacheCreationInputTokens()`, `getCacheReadInputTokens()`,
    /// `getTotalTokens()`.
    pub fn usage(&self) -> &Usage {
        &self.usage
    }

    /// The token counters, to add to them.
    ///
    /// PHP: `countInputTokens()`, `countOutputTokens()`,
    /// `countCacheCreationInputTokens()`, `countCacheReadInputTokens()`.
    pub fn usage_mut(&mut self) -> &mut Usage {
        &mut self.usage
    }

    /// PHP: `validateAttachments($message, $attachments)`.
    fn validate(&self, message: &Message, attachments: &[Message]) -> Result<(), Error> {
        let adapter = self.agent.adapter();
        let all = message.attachments().iter().chain(attachments);
        let count = message.attachments().len() + attachments.len();

        if let Some(max) = adapter.max_attachments_per_message()
            && count as i64 > max
        {
            return Err(Error::InvalidArgument("Too many attachments in this message".into()));
        }

        let max_bytes = adapter.max_attachment_bytes();
        let max_total = adapter.max_total_attachment_bytes();
        let allowed = adapter.allowed_attachment_mime_types();

        let mut total: i64 = 0;
        for attachment in all {
            let bytes = attachment.content().len() as i64;
            if bytes == 0 {
                return Err(Error::InvalidArgument("Attachment payload cannot be empty".into()));
            }
            if max_bytes.is_some_and(|max| bytes > max) {
                return Err(Error::InvalidArgument("Attachment exceeds per-file size limit".into()));
            }
            let Some(mime) = attachment.mime_type() else {
                return Err(Error::InvalidArgument("Attachment MIME type cannot be detected".into()));
            };
            if allowed.as_ref().is_some_and(|allowed| !allowed.contains(&mime)) {
                return Err(Error::InvalidArgument("Attachment MIME type is not allowed".into()));
            }
            if !adapter.supports_attachment(attachment) {
                return Err(Error::InvalidArgument("Attachment type is not supported by this adapter".into()));
            }
            total += bytes;
        }

        if max_total.is_some_and(|max| total > max) {
            return Err(Error::InvalidArgument("Attachments exceed total payload size limit".into()));
        }
        Ok(())
    }
}
