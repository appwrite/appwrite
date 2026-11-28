//! `Appwrite\Deletes\Targets`: deleting messaging targets with their
//! subscribers, keeping their topics' totals.

use async_trait::async_trait;
use utopia_database::database::Callback;
use utopia_database::{Database, Document, Kind, Query, Value};

use crate::Result;
use crate::database::documents::{self, Attributes};
use crate::database::filters;

/// `Deletes\Targets::delete()`: the targets `queries` match, each with its
/// subscribers (and their topics' totals).
pub async fn delete(db: &Database, mut queries: Vec<Query>) -> Result<()> {
    queries.push(Query::order_asc("", None));
    db.delete_documents("targets", queries, utopia_database::database::BATCH_SIZE, Some(&mut DeleteSubscribers { db }))
        .await?;
    Ok(())
}

/// `Deletes\Targets::deleteSubscribers`, for each deleted target.
struct DeleteSubscribers<'a> {
    db: &'a Database,
}

#[async_trait]
impl Callback<Document> for DeleteSubscribers<'_> {
    async fn call(&mut self, target: Document) -> utopia_database::Result<()> {
        let mut topics = DecreaseTopic { db: self.db, provider_type: target.string_or_empty("providerType") };
        self.db
            .delete_documents(
                "subscribers",
                vec![
                    Query::equal("targetInternalId", [documents::query_value(target.sequence())]),
                    Query::order_asc("", None),
                ],
                utopia_database::database::BATCH_SIZE,
                Some(&mut topics),
            )
            .await?;
        Ok(())
    }
}

/// Decrements the subscriber total of a deleted subscriber's topic.
struct DecreaseTopic<'a> {
    db: &'a Database,
    provider_type: String,
}

#[async_trait]
impl Callback<Document> for DecreaseTopic<'_> {
    async fn call(&mut self, subscriber: Document) -> utopia_database::Result<()> {
        let db = self.db;
        let topic_id = subscriber.string_or_empty("topicId");
        let topic_internal_id = documents::query_value(subscriber.get("topicInternalId"));
        let skipped = filters::names(filters::TOPICS_SUBQUERIES);
        let topic = db
            .skip_filters(
                Some(skipped.as_slice()),
                db.find_one(
                    "topics",
                    vec![Query::select(["$id", "$sequence"]), Query::equal("$sequence", [topic_internal_id])],
                ),
            )
            .await?;
        if topic.is_empty() {
            return Ok(());
        }
        let total = match self.provider_type.as_str() {
            "email" => "emailTotal",
            "sms" => "smsTotal",
            "push" => "pushTotal",
            _ => return Err(utopia_database::Error::new(Kind::Exception, "Invalid target provider type")),
        };
        let decreased = db
            .skip_filters(
                Some(skipped.as_slice()),
                db.decrease_document_attribute("topics", &topic_id, total, Value::Int(1), Some(Value::Int(0))),
            )
            .await;
        match decreased {
            Err(e) if e.is_a(Kind::Limit) => {
                tracing::error!(topic = topic_id, error = %e, "Delete subscribers decreaseDocumentAttribute");
                Ok(())
            }
            other => other.map(|_| ()),
        }
    }
}
