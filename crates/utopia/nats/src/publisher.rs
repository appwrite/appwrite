//! Publishing from callbacks.

use std::sync::{Arc, Mutex};

use bytes::Bytes;

use crate::headers::Headers;

/// A publish waiting for the connection.
#[derive(Debug, Clone)]
pub(crate) struct Queued {
    pub subject: String,
    pub data: Bytes,
    pub reply_to: Option<String>,
    pub headers: Option<Headers>,
}

/// Publishes through a [`crate::Connection`] from code that cannot hold
/// it: subscription callbacks, service handlers, push consumers. Cheap to
/// clone.
///
/// A publish is queued and the connection sends it, with every check
/// [`crate::Connection::publish`] makes, as soon as the callback returns
/// (or, from anywhere else, at the connection's next read, `flush` or
/// publish). Errors surface from the connection call that sends it.
///
/// ```
/// # utopia_async::run(async {
/// # let Ok(mut connection) = utopia_nats::Connection::connect(utopia_nats::ConnectionOptions {
/// #     connect_timeout: std::time::Duration::from_millis(200),
/// #     ..utopia_nats::ConnectionOptions::new([std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into())])
/// # }).await else { return Ok(()) };
/// let replies = connection.publisher();
/// connection
///     .subscribe("math.double", Some(Box::new(move |msg: utopia_nats::Message| {
///         let value: i64 = std::str::from_utf8(&msg.data)?.parse()?;
///         replies.publish(msg.reply_to.unwrap_or_default(), (value * 2).to_string(), None, None);
///         Ok(())
///     })), None)
///     .await?;
/// let answer = connection.request("math.double", "21", None, None).await?;
/// assert_eq!(answer.data, "42");
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\NATS\Connection;
/// use Utopia\NATS\ConnectionOptions;
/// use Utopia\NATS\Exception\ConnectionException;
///
/// try {
///     $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
/// } catch (ConnectionException) {
///     return;
/// }
/// $connection->subscribe('math.double', function ($msg) use ($connection) {
///     $connection->publish($msg->replyTo, (string) ((int) $msg->data * 2));
/// });
/// $connection->request('math.double', '21')->data; // '42'
/// ```
///
/// PHP: `Connection::publish()` called from inside a callback (PHP
/// callbacks hold the connection itself).
#[derive(Debug, Clone, Default)]
pub struct Publisher {
    queue: Arc<Mutex<Vec<Queued>>>,
}

impl Publisher {
    /// Queues a publish of `data` to `subject`.
    pub fn publish(
        &self,
        subject: impl Into<String>,
        data: impl Into<Bytes>,
        reply_to: Option<String>,
        headers: Option<Headers>,
    ) {
        self.lock().push(Queued { subject: subject.into(), data: data.into(), reply_to, headers });
    }

    /// Whether publishes wait to be sent.
    pub fn is_empty(&self) -> bool {
        self.lock().is_empty()
    }

    pub(crate) fn take(&self) -> Vec<Queued> {
        std::mem::take(&mut *self.lock())
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Vec<Queued>> {
        self.queue.lock().unwrap_or_else(std::sync::PoisonError::into_inner)
    }
}
