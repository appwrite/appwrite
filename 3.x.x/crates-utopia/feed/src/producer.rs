use serde_json::json;
use utopia_cloudevents::CloudEvent;

use crate::{Appendable, FeedError, Readable};

/// PHP `Utopia\Feed\Producer`.
pub struct Producer<S> {
    store: S,
    source: String,
}

impl<S> std::fmt::Debug for Producer<S> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Producer")
            .field("source", &self.source)
            .finish_non_exhaustive()
    }
}

impl<S: Readable + Appendable> Producer<S> {
    pub fn new(store: S, source: impl Into<String>) -> Result<Self, FeedError> {
        let source = source.into();
        if source.is_empty() {
            return Err(FeedError::invalid("Feed producer requires a source"));
        }
        Ok(Self { store, source })
    }

    #[must_use]
    pub fn get_name(&self) -> &str {
        self.store.get_name()
    }

    #[must_use]
    pub fn store(&self) -> &S {
        &self.store
    }

    /// PHP `produce(string $type, mixed $data = [], string $subject = '')`.
    pub fn produce(
        &self,
        type_name: &str,
        data: serde_json::Value,
        subject: &str,
    ) -> Result<String, FeedError> {
        let mut event = CloudEvent::create(type_name, self.source.clone(), "");
        event.data = data;
        event.subject = if subject.is_empty() {
            None
        } else {
            Some(subject.to_owned())
        };
        self.publish(event)
    }

    /// PHP `produce($type)` with default `data = []`.
    pub fn produce_type(&self, type_name: &str) -> Result<String, FeedError> {
        self.produce(type_name, json!([]), "")
    }

    /// PHP `publish(CloudEvent $event)`.
    pub fn publish(&self, event: CloudEvent) -> Result<String, FeedError> {
        if event.r#type.is_empty() {
            return Err(FeedError::invalid("Feed event type is required"));
        }
        let time = match event.time.as_deref() {
            None | Some("") => Some(CloudEvent::now()),
            Some(_) => event.time.clone(),
        };
        let rebuilt = CloudEvent {
            r#type: event.r#type,
            source: self.source.clone(),
            id: event.id,
            specversion: event.specversion,
            subject: event.subject,
            time,
            datacontenttype: event.datacontenttype,
            data: event.data,
            dataschema: event.dataschema,
            extensions: event.extensions,
            data_binary: event.data_binary,
        };
        self.store.append(rebuilt)
    }
}
