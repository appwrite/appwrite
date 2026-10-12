//! Subscriptions: the handle callers hold and the state the connection
//! keeps for it.

use std::collections::VecDeque;
use std::fmt;

use crate::error::{CallbackError, Error, Result};
use crate::message::Message;
use crate::options::SlowConsumerHook;

/// A callback receiving the messages of a subscription.
pub type MessageCallback = Box<dyn FnMut(Message) -> Result<(), CallbackError> + Send>;

/// A subscription of a [`crate::Connection`]: what [`crate::Connection::subscribe`]
/// returns and the other subscription calls take. Cheap to clone.
///
/// PHP: the `Utopia\NATS\Subscription` object callers hold (its `sid`,
/// `subject` and `queue` properties). Its methods that read the
/// connection are [`crate::Connection`] methods here
/// ([`crate::Connection::next_message`], [`crate::Connection::unsubscribe`],
/// [`crate::Connection::subscription_state`]).
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Subscription {
    /// The subscription id.
    pub sid: String,
    /// The subject (or wildcard) subscribed to.
    pub subject: String,
    /// The queue group, if any.
    pub queue: Option<String>,
}

/// The state of a subscription: its callback or its queue of pending
/// messages, its counters and limits.
///
/// A subscription with a callback delivers each message to it at once. One
/// without keeps messages until they are taken, up to its pending limits;
/// past them it drops the message and calls the slow-consumer hook once per
/// overflow (again after a message is taken).
///
/// PHP: the state of `Utopia\NATS\Subscription` (it can be built on its
/// own, as the PHP tests do).
pub struct SubscriptionState {
    sid: String,
    subject: String,
    queue: Option<String>,
    callback: Option<MessageCallback>,
    pending_msgs_limit: i64,
    pending_bytes_limit: i64,
    on_slow_consumer: Option<SlowConsumerHook>,
    pending: VecDeque<Message>,
    active: bool,
    max_messages: Option<i64>,
    received: i64,
    pending_bytes: i64,
    slow_consumer_signaled: bool,
}

impl SubscriptionState {
    /// A subscription's state, active, with nothing received.
    ///
    /// PHP: `new Subscription($sid, $subject, $queue, $callback,
    /// $pendingMsgsLimit, $pendingBytesLimit, $onSlowConsumer)`.
    pub fn new(
        sid: impl Into<String>,
        subject: impl Into<String>,
        queue: Option<String>,
        callback: Option<MessageCallback>,
        pending_msgs_limit: i64,
        pending_bytes_limit: i64,
        on_slow_consumer: Option<SlowConsumerHook>,
    ) -> Self {
        SubscriptionState {
            sid: sid.into(),
            subject: subject.into(),
            queue,
            callback,
            pending_msgs_limit,
            pending_bytes_limit,
            on_slow_consumer,
            pending: VecDeque::new(),
            active: true,
            max_messages: None,
            received: 0,
            pending_bytes: 0,
            slow_consumer_signaled: false,
        }
    }

    /// The subscription id.
    pub fn sid(&self) -> &str {
        &self.sid
    }

    /// The subject.
    pub fn subject(&self) -> &str {
        &self.subject
    }

    /// The queue group.
    pub fn queue(&self) -> Option<&str> {
        self.queue.as_deref()
    }

    /// The handle for this subscription.
    pub fn handle(&self) -> Subscription {
        Subscription { sid: self.sid.clone(), subject: self.subject.clone(), queue: self.queue.clone() }
    }

    /// Takes the oldest pending message, resetting the slow-consumer latch.
    ///
    /// PHP: `Subscription::nextMessage()` on a subscription without a
    /// connection (with one it also reads the connection: see
    /// [`crate::Connection::next_message`]).
    pub fn next_message(&mut self) -> Option<Message> {
        let message = self.pending.pop_front()?;
        self.pending_bytes = (self.pending_bytes - message.data.len() as i64).max(0);
        self.slow_consumer_signaled = false;
        Some(message)
    }

    /// Whether it still receives messages.
    ///
    /// PHP: `Subscription::isActive()`.
    pub fn is_active(&self) -> bool {
        self.active
    }

    /// Hands a message to the callback, or queues it (dropping it and
    /// signalling a slow consumer over the limits); deactivates the
    /// subscription once it reached its maximum.
    ///
    /// # Errors
    ///
    /// [`Error::Callback`] with what the callback or the slow-consumer hook
    /// raised.
    ///
    /// PHP: `Subscription::deliver()`.
    pub fn deliver(&mut self, message: Message) -> Result<()> {
        if let Some(callback) = self.callback.as_mut() {
            self.received += 1;
            callback(message).map_err(|e| Error::Callback(e.into()))?;
        } else {
            let bytes = message.data.len() as i64;
            if self.pending.len() as i64 >= self.pending_msgs_limit
                || self.pending_bytes + bytes > self.pending_bytes_limit
            {
                return self.signal_slow_consumer();
            }
            self.received += 1;
            self.pending_bytes += bytes;
            self.pending.push_back(message);
        }
        if self.max_messages.is_some_and(|max| self.received >= max) {
            self.active = false;
        }
        Ok(())
    }

    /// How many messages wait to be taken.
    ///
    /// PHP: `Subscription::getPendingCount()`.
    pub fn pending_count(&self) -> usize {
        self.pending.len()
    }

    /// How many payload bytes wait to be taken.
    ///
    /// PHP: `Subscription::getPendingBytes()`.
    pub fn pending_bytes(&self) -> i64 {
        self.pending_bytes
    }

    fn signal_slow_consumer(&mut self) -> Result<()> {
        if self.slow_consumer_signaled {
            return Ok(());
        }
        self.slow_consumer_signaled = true;
        if let Some(hook) = self.on_slow_consumer.clone() {
            hook(self).map_err(|e| Error::Callback(e.into()))?;
        }
        Ok(())
    }

    /// Deactivates the subscription once `max` messages were received
    /// (at once if they already were).
    ///
    /// PHP: `Subscription::setMaxMessages()`.
    pub fn set_max_messages(&mut self, max: i64) {
        self.max_messages = Some(max);
        if self.received >= max {
            self.active = false;
        }
    }

    /// Deactivates the subscription.
    ///
    /// PHP: `Subscription::setInactive()`.
    pub fn set_inactive(&mut self) {
        self.active = false;
    }

    /// How many messages it accepted (delivered or queued).
    ///
    /// PHP: `Subscription::getReceived()`.
    pub fn received(&self) -> i64 {
        self.received
    }

    /// Whether messages go to a callback rather than a queue.
    ///
    /// PHP: `Subscription::hasCallback()`.
    pub fn has_callback(&self) -> bool {
        self.callback.is_some()
    }
}

impl fmt::Debug for SubscriptionState {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("SubscriptionState")
            .field("sid", &self.sid)
            .field("subject", &self.subject)
            .field("queue", &self.queue)
            .field("active", &self.active)
            .field("received", &self.received)
            .field("pending", &self.pending.len())
            .finish_non_exhaustive()
    }
}
