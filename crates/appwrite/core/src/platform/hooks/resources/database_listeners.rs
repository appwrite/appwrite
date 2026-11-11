//! The project database's listeners (PHP: `app/init/resources/request.php`,
//! the `dbForProject` resource's `->on(...)` hooks; hooks.json
//! `resource-database-listeners`): users created, usage metrics and the
//! function events cache. The shutdown hooks read what they recorded.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_database::Database;
use utopia_database::database::Hook;
use utopia_database::event::{Domain, Event};
use utopia_database::hook::{Lifecycle, Selective};

use crate::Result;

/// What the project database's listeners saw during a request (PHP's
/// `dbForProject` listeners).
#[derive(Debug, Default)]
pub struct Listened {
    /// Users created (`create-trigger-events`): their
    /// `users.[userId].create` events.
    pub users: Vec<utopia_database::Document>,
    /// Usage metrics (`calculate-usage`, and [`Context::metric`]).
    pub metrics: Vec<UsageMetric>,
    /// A function was created, updated or deleted
    /// (`purge-function-events-cache`).
    pub functions: bool,
}

/// A usage metric a request records (`Appwrite\Usage\Context`'s
/// `addMetric()` and `addResourceMetric()`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct UsageMetric {
    pub key: String,
    pub value: i64,
    /// `addResourceMetric()`'s resource type and internal ID, over the
    /// request's (the project).
    pub resource: Option<(String, String)>,
}

impl UsageMetric {
    /// `addMetric()`: counted against the request's resource.
    pub fn new(key: &str, value: i64) -> Self {
        UsageMetric { key: key.to_owned(), value, resource: None }
    }
}

/// Registers PHP's hooks on a project database (the `dbForProject`
/// resource): `Usage`, `UserEvents` and `FunctionCache`, in that order.
pub(in crate::platform) fn listen(db: &Database, listened: &Arc<Mutex<Listened>>) -> Result<()> {
    db.add_hook(Hook::lifecycle(Arc::new(Usage(listened.clone()))))?;
    db.add_hook(Hook::lifecycle(Arc::new(UserEvents(listened.clone()))))?;
    db.add_hook(Hook::lifecycle(Arc::new(FunctionCache(listened.clone()))))?;
    Ok(())
}

fn lock(listened: &Mutex<Listened>) -> std::sync::MutexGuard<'_, Listened> {
    listened.lock().unwrap_or_else(|e| e.into_inner())
}

/// `Appwrite\Utopia\Database\Hook\Usage`: sessions and deployments counted
/// as they are created and deleted.
struct Usage(Arc<Mutex<Listened>>);

impl Usage {
    fn track_document(&self, collection: &str, document: &utopia_database::Document, value: i64) {
        let mut listened = lock(&self.0);
        match collection {
            "sessions" => listened.metrics.push(UsageMetric::new("sessions", value)),
            "deployments" => {
                let kind = document.get("resourceType").map(utopia_database::value::to_text).unwrap_or_default();
                let size = document.get("size").map(utopia_database::value::to_long).unwrap_or(0);
                // `addResourceMetric()`: counted against the deployment's
                // function or site (`rtrim($resourceType, 's')`).
                let owner = kind.trim_end_matches('s').to_owned();
                let owner_id =
                    document.get("resourceInternalId").map(utopia_database::value::to_text).unwrap_or_default();
                let resource = Some((owner, owner_id));
                listened.metrics.push(UsageMetric {
                    key: format!("{kind}.deployments"),
                    value,
                    resource: resource.clone(),
                });
                listened.metrics.push(UsageMetric {
                    key: format!("{kind}.deployments.storage"),
                    value: size * value,
                    resource,
                });
            }
            _ => {}
        }
    }

    fn track_batch(&self, collection: &str, value: i64) {
        if collection == "sessions" {
            lock(&self.0).metrics.push(UsageMetric::new("sessions", value));
        }
    }
}

#[async_trait]
impl Lifecycle for Usage {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        match event {
            Domain::DocumentCreated { collection, document } => self.track_document(collection, document, 1),
            Domain::DocumentDeleted { collection, document } => self.track_document(collection, document, -1),
            Domain::DocumentBatchCreated { collection, count } => self.track_batch(collection, *count),
            Domain::DocumentBatchDeleted { collection, count } => self.track_batch(collection, -count),
            Domain::DocumentBatchUpserted { collection, created, .. } => self.track_batch(collection, *created),
            _ => {}
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for Usage {
    fn handles(&self, event: Event) -> bool {
        matches!(
            event,
            Event::DocumentCreate
                | Event::DocumentDelete
                | Event::DocumentsCreate
                | Event::DocumentsDelete
                | Event::DocumentsUpsert
        )
    }
}

/// `Appwrite\Utopia\Database\Hook\UserEvents`: each user created, for the
/// `users.[userId].create` events the shutdown triggers.
struct UserEvents(Arc<Mutex<Listened>>);

#[async_trait]
impl Lifecycle for UserEvents {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        if let Domain::DocumentCreated { collection, document } = event
            && collection == "users"
        {
            lock(&self.0).users.push(document.clone());
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for UserEvents {
    fn handles(&self, event: Event) -> bool {
        event == Event::DocumentCreate
    }
}

/// `Appwrite\Utopia\Database\Hook\FunctionCache`: a function created,
/// updated or deleted, for the shutdown to purge the function events cache.
struct FunctionCache(Arc<Mutex<Listened>>);

#[async_trait]
impl Lifecycle for FunctionCache {
    async fn handle(&self, event: &Domain) -> utopia_database::Result<()> {
        if let Domain::DocumentCreated { collection, .. }
        | Domain::DocumentUpdated { collection, .. }
        | Domain::DocumentDeleted { collection, .. } = event
            && collection == "functions"
        {
            lock(&self.0).functions = true;
        }
        Ok(())
    }

    fn selective(&self) -> Option<&dyn Selective> {
        Some(self)
    }
}

impl Selective for FunctionCache {
    fn handles(&self, event: Event) -> bool {
        matches!(event, Event::DocumentCreate | Event::DocumentUpdate | Event::DocumentDelete)
    }
}

#[cfg(test)]
mod usage_tests {
    use utopia_database::{Document, Value};

    use super::*;

    fn document(fields: &[(&str, Value)]) -> Document {
        let mut document = Document::default();
        for (key, value) in fields {
            document.put(*key, value.clone());
        }
        document
    }

    /// The metrics PHP's `Usage` hook records for the same events (printed
    /// by `Appwrite\Usage\Context::getMetrics()` on the oracle): a
    /// deployment counts against its function or site, a session against
    /// the request's resource.
    #[utopia_async::test]
    async fn deployments_count_against_their_owner() {
        let listened = Arc::new(Mutex::new(Listened::default()));
        let usage = Usage(listened.clone());
        let created = document(&[
            ("resourceType", Value::from("functions")),
            ("resourceInternalId", Value::Int(12)),
            ("size", Value::from("2048")),
        ]);
        let deleted = document(&[
            ("resourceType", Value::from("sites")),
            ("resourceInternalId", Value::from("3")),
            ("size", Value::Int(10)),
        ]);
        let events = [
            Domain::DocumentCreated { collection: "deployments".into(), document: created },
            Domain::DocumentDeleted { collection: "deployments".into(), document: deleted },
            Domain::DocumentCreated { collection: "sessions".into(), document: Document::default() },
        ];
        for event in &events {
            usage.handle(event).await.unwrap();
        }
        let owner = |kind: &str, id: &str| Some((kind.to_owned(), id.to_owned()));
        let expected = vec![
            UsageMetric { key: "functions.deployments".into(), value: 1, resource: owner("function", "12") },
            UsageMetric { key: "functions.deployments.storage".into(), value: 2048, resource: owner("function", "12") },
            UsageMetric { key: "sites.deployments".into(), value: -1, resource: owner("site", "3") },
            UsageMetric { key: "sites.deployments.storage".into(), value: -10, resource: owner("site", "3") },
            UsageMetric::new("sessions", 1),
        ];
        assert_eq!(lock(&listened).metrics, expected);
    }
}
