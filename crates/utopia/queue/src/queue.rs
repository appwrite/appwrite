//! Queue identity.

use crate::error::{Error, Result};

/// The namespace every queue uses unless told otherwise.
pub const DEFAULT_NAMESPACE: &str = "utopia-queue";

/// A named queue in a namespace: the identity a broker stores messages under.
///
/// `job_ttl` (seconds, `0` for none) is how long a broker keeps a failed
/// message's payload, and on NATS the work stream's message age.
///
/// ```
/// use utopia_queue::Queue;
///
/// let queue = Queue::new("emails")?;
/// assert_eq!(queue.name(), "emails");
/// assert_eq!(queue.namespace(), "utopia-queue");
/// assert!(Queue::new("").is_err());
/// # Ok::<(), utopia_queue::Error>(())
/// ```
///
/// ```php
/// use Utopia\Queue\Queue;
///
/// $queue = new Queue('emails');
/// echo $queue->name, ' ', $queue->namespace, "\n"; // emails utopia-queue
/// ```
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Queue {
    name: String,
    namespace: String,
    job_ttl: i64,
}

impl Queue {
    /// A queue in the default namespace. Fails on an empty name (or `"0"`,
    /// which PHP treats as empty).
    pub fn new(name: impl Into<String>) -> Result<Self> {
        Self::with(name, DEFAULT_NAMESPACE, 0)
    }

    /// A queue with every field: name, namespace and payload TTL in seconds.
    pub fn with(name: impl Into<String>, namespace: impl Into<String>, job_ttl: i64) -> Result<Self> {
        let name = name.into();
        if name.is_empty() || name == "0" {
            return Err(Error::InvalidArgument("Cannot create queue with empty name.".into()));
        }
        Ok(Self { name, namespace: namespace.into(), job_ttl })
    }

    /// The queue's name.
    pub fn name(&self) -> &str {
        &self.name
    }

    /// The key prefix shared by every queue of one application.
    pub fn namespace(&self) -> &str {
        &self.namespace
    }

    /// Seconds a failed message's payload is kept, `0` for no limit.
    pub fn job_ttl(&self) -> i64 {
        self.job_ttl
    }

    /// `<namespace>.<kind>.<name>`: the Redis key of one of the queue's lists or counters.
    pub fn key(&self, kind: &str) -> String {
        let mut key = String::with_capacity(self.namespace.len() + kind.len() + self.name.len() + 2);
        key.push_str(&self.namespace);
        key.push('.');
        key.push_str(kind);
        key.push('.');
        key.push_str(&self.name);
        key
    }
}
