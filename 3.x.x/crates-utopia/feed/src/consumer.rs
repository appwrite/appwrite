use std::sync::Arc;

use utopia_client::Adapter;
use utopia_cloudevents::CloudEvent;

use crate::{Cursor, FeedError, Id, Readable, Remote, MAX_BATCH, MAX_TIMEOUT, TIP};

/// PHP handler return: only exact `false` is unprocessed.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ConsumeDecision {
    /// Anything other than PHP `false` - including `null`, `0`, `''`.
    Processed,
    /// PHP `false`.
    Unprocessed,
}

/// Convert a handler return value the way PHP reads `=== false`.
pub trait IntoConsumeResult {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError>;
}

impl IntoConsumeResult for () {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl IntoConsumeResult for bool {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(if self {
            ConsumeDecision::Processed
        } else {
            ConsumeDecision::Unprocessed
        })
    }
}

impl IntoConsumeResult for ConsumeDecision {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(self)
    }
}

impl IntoConsumeResult for i32 {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl IntoConsumeResult for i64 {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl IntoConsumeResult for usize {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl IntoConsumeResult for &str {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl IntoConsumeResult for String {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        Ok(ConsumeDecision::Processed)
    }
}

impl<T: IntoConsumeResult> IntoConsumeResult for Result<T, FeedError> {
    fn into_consume_result(self) -> Result<ConsumeDecision, FeedError> {
        self.and_then(IntoConsumeResult::into_consume_result)
    }
}

/// PHP `Utopia\Feed\Consumer`.
pub struct Consumer {
    feed: Arc<dyn Readable>,
    cursor: Arc<dyn Cursor>,
    name: String,
    batch: i64,
    timeout: i64,
    start: String,
    position: parking_lot::Mutex<Option<String>>,
    restored: parking_lot::Mutex<bool>,
    moved: parking_lot::Mutex<u64>,
}

impl Consumer {
    /// PHP `Consumer::BATCH`.
    pub const BATCH: i64 = 100;
    /// PHP `Consumer::START_OLDEST`.
    pub const START_OLDEST: &'static str = "oldest";
    /// PHP `Consumer::START_TIP`.
    pub const START_TIP: &'static str = "tip";

    pub fn new(
        feed: Arc<dyn Readable>,
        cursor: Arc<dyn Cursor>,
        name: impl Into<String>,
    ) -> Result<Self, FeedError> {
        Self::with_options(feed, cursor, name, "", Self::BATCH, 0, Self::START_OLDEST)
    }

    #[allow(clippy::too_many_arguments)]
    pub fn with_options(
        feed: Arc<dyn Readable>,
        cursor: Arc<dyn Cursor>,
        name: impl Into<String>,
        feed_name: &str,
        batch: i64,
        timeout: i64,
        start: &str,
    ) -> Result<Self, FeedError> {
        let name = name.into();
        if name.is_empty() {
            return Err(FeedError::invalid("Feed consumer requires a name"));
        }
        if start != Self::START_OLDEST && start != Self::START_TIP {
            return Err(FeedError::invalid(format!(
                "Feed consumer start must be Consumer::START_OLDEST or Consumer::START_TIP, got {start}"
            )));
        }
        if !feed_name.is_empty() && feed_name != feed.get_name() {
            return Err(FeedError::invalid(format!(
                "The source already names its feed {}, which {feed_name} contradicts",
                feed.get_name()
            )));
        }
        Ok(Self {
            feed,
            cursor,
            name,
            batch,
            timeout,
            start: start.to_owned(),
            position: parking_lot::Mutex::new(None),
            restored: parking_lot::Mutex::new(false),
            moved: parking_lot::Mutex::new(0),
        })
    }

    /// PHP `new Consumer(Adapter $source, ..., string $feed)`.
    #[allow(clippy::too_many_arguments)]
    pub fn from_client<A: Adapter + 'static>(
        client: A,
        cursor: Arc<dyn Cursor>,
        name: impl Into<String>,
        feed: impl Into<String>,
        batch: i64,
        timeout: i64,
        start: &str,
    ) -> Result<Self, FeedError> {
        let remote = Remote::new(client, feed)?;
        Self::with_options(Arc::new(remote), cursor, name, "", batch, timeout, start)
    }

    #[must_use]
    pub fn get_name(&self) -> &str {
        &self.name
    }

    /// PHP `consume(callable $handler)` when the handler returns nothing.
    pub fn consume_any<F>(&self, mut handler: F) -> Result<i64, FeedError>
    where
        F: FnMut(&CloudEvent),
    {
        self.consume(|event| {
            handler(event);
        })
    }

    /// PHP `consume(callable $handler)`. Exact `false` stops; other values process.
    /// A handler `Err` is raised after committing events handled earlier in the run.
    pub fn consume<F, R>(&self, mut handler: F) -> Result<i64, FeedError>
    where
        F: FnMut(&CloudEvent) -> R,
        R: IntoConsumeResult,
    {
        let moved = *self.moved.lock();
        let events = self.poll()?;
        let mut handled = 0i64;
        let mut processed: Option<String> = None;
        let mut failure = None;
        for event in &events {
            match handler(event).into_consume_result() {
                Ok(ConsumeDecision::Processed) => {
                    processed = Some(event.id.clone());
                    handled += 1;
                }
                Ok(ConsumeDecision::Unprocessed) => break,
                Err(err) => {
                    failure = Some(err);
                    break;
                }
            }
        }
        self.advance(processed.as_deref(), moved)?;
        if let Some(err) = failure {
            return Err(err);
        }
        Ok(handled)
    }

    /// PHP `consumeChunk(callable $handler)`.
    pub fn consume_chunk<F, R>(&self, handler: F) -> Result<i64, FeedError>
    where
        F: FnOnce(&[CloudEvent]) -> R,
        R: IntoConsumeResult,
    {
        let moved = *self.moved.lock();
        let events = self.poll()?;
        if events.is_empty() {
            return Ok(0);
        }
        match handler(&events).into_consume_result()? {
            ConsumeDecision::Unprocessed => Ok(0),
            ConsumeDecision::Processed => {
                let last = events.last().map(|e| e.id.clone());
                self.advance(last.as_deref(), moved)?;
                Ok(events.len() as i64)
            }
        }
    }

    fn poll(&self) -> Result<Vec<CloudEvent>, FeedError> {
        let limit = self.batch.clamp(1, MAX_BATCH);
        let timeout = self.timeout.clamp(0, MAX_TIMEOUT);
        self.feed.poll(
            self.position()?.as_deref().or(self.origin()),
            limit,
            timeout,
        )
    }

    fn origin(&self) -> Option<&str> {
        if self.start == Self::START_TIP {
            Some(TIP)
        } else {
            None
        }
    }

    fn advance(&self, processed: Option<&str>, moved: u64) -> Result<(), FeedError> {
        let Some(processed) = processed else {
            return Ok(());
        };
        if *self.moved.lock() != moved {
            return Ok(());
        }
        let expected = self.position.lock().clone();
        *self.position.lock() = Some(processed.to_owned());
        if !self.cursor.advance(
            self.feed.get_name(),
            &self.name,
            processed,
            expected.as_deref(),
        )? {
            *self.position.lock() = None;
            *self.restored.lock() = false;
        }
        Ok(())
    }

    pub fn position(&self) -> Result<Option<String>, FeedError> {
        if !*self.restored.lock() {
            let loaded = self.cursor.load(self.feed.get_name(), &self.name)?;
            *self.position.lock() = loaded;
            *self.restored.lock() = true;
        }
        Ok(self.position.lock().clone())
    }

    pub fn seek(&self, event_id: &str) -> Result<(), FeedError> {
        let usable = if self.feed.is_store() {
            Id::is_valid(event_id)
        } else {
            !event_id.is_empty() && event_id != TIP
        };
        if !usable {
            return Err(FeedError::invalid(format!(
                "Invalid feed event id: {event_id}"
            )));
        }
        self.cursor
            .save(self.feed.get_name(), &self.name, event_id)?;
        *self.position.lock() = Some(event_id.to_owned());
        *self.restored.lock() = true;
        *self.moved.lock() += 1;
        Ok(())
    }

    pub fn reset(&self) -> Result<(), FeedError> {
        self.cursor.reset(self.feed.get_name(), &self.name)?;
        *self.position.lock() = None;
        *self.restored.lock() = true;
        *self.moved.lock() += 1;
        Ok(())
    }
}

impl std::fmt::Debug for Consumer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Consumer")
            .field("name", &self.name)
            .field("batch", &self.batch)
            .field("timeout", &self.timeout)
            .field("start", &self.start)
            .finish_non_exhaustive()
    }
}
