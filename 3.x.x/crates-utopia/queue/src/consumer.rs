use crate::error::QueueError;
use crate::message::Message;
use crate::publisher::Publisher;
use crate::queue::Queue;

/// Claim, ack, reject, and close a consumer.
///
/// PHP `Utopia\Queue\Consumer`.
pub trait Consumer: Send + Sync {
    /// Block up to `timeout` seconds for the next message and claim it, or `None` on timeout.
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError>;

    /// Acknowledge a processed message.
    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError>;

    /// Mark a message as failed.
    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError>;

    /// Close the consumer and free resources.
    fn close(&self);

    /// PHP `instanceof Publisher` check used by queue-depth telemetry.
    fn as_publisher(&self) -> Option<&dyn Publisher> {
        None
    }
}

impl Consumer for Box<dyn Consumer> {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        (**self).receive(queue, timeout)
    }

    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        (**self).commit(queue, message)
    }

    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        (**self).reject(queue, message)
    }

    fn close(&self) {
        (**self).close();
    }

    fn as_publisher(&self) -> Option<&dyn Publisher> {
        (**self).as_publisher()
    }
}

impl Consumer for std::sync::Arc<dyn Consumer> {
    fn receive(&self, queue: &Queue, timeout: i64) -> Result<Option<Message>, QueueError> {
        (**self).receive(queue, timeout)
    }

    fn commit(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        (**self).commit(queue, message)
    }

    fn reject(&self, queue: &Queue, message: &Message) -> Result<(), QueueError> {
        (**self).reject(queue, message)
    }

    fn close(&self) {
        (**self).close();
    }

    fn as_publisher(&self) -> Option<&dyn Publisher> {
        (**self).as_publisher()
    }
}
