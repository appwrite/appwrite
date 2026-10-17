//! `authorize()`: checking, without writing, that the caller may relate the
//! related documents a write carries.

use super::update::apply_relationship_operator;
use super::{Relationships, permission_input, relationships, to_document};
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, RELATION_MAX_DEPTH, Resolve};
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::validator::authorization::Input;
use crate::value::{self, Ext, Value};

/// A related document or id a relationship value holds.
enum Related {
    /// A related document.
    Document(Document),
    /// A related document's id.
    Id(String),
}

/// The ids of the related documents a relationship value holds
/// (`relatedIds()`).
fn related_ids(value: Option<&Value>) -> Vec<String> {
    let items: Vec<&Value> = match value {
        Some(Value::Array(a)) if a.is_list() => a.values().collect(),
        Some(v) => vec![v],
        None => Vec::new(),
    };
    items
        .into_iter()
        .filter_map(|v| match v {
            Value::Ext(Ext::Document(d)) => Some(d.id().to_owned()),
            Value::Str(_) => Some(value::to_text(v)),
            _ => None,
        })
        .collect()
}

/// The related documents and ids a relationship value holds, a document
/// given as an array read as a document (`relatedValues()`).
///
/// Fails ([`crate::Kind::Relationship`]) for anything else.
fn related_values(value: &Value) -> Result<Vec<Related>> {
    let items: Vec<&Value> = match value {
        Value::Null => return Ok(Vec::new()),
        Value::Array(a) if a.is_list() => a.values().collect(),
        v => vec![v],
    };
    items
        .into_iter()
        .map(|v| match v {
            Value::Array(a) => Ok(Related::Document(to_document(a.clone())?)),
            Value::Ext(Ext::Document(d)) => Ok(Related::Document((**d).clone())),
            Value::Str(_) => Ok(Related::Id(value::to_text(v))),
            _ => Err(Error::relationship(
                "Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.",
            )),
        })
        .collect()
}

impl Relationships {
    /// Checks, without writing, that the caller may relate the related
    /// documents `document` carries the way writing it relates them
    /// (`authorize()`): create on the related collection for a new document,
    /// update to link a stored one or to change one sent as a document, and
    /// never a stored document the caller cannot read. With `old`, the
    /// stored document an update changes, a related document it links
    /// already is not linked again. With `resolve`, every related document is
    /// judged as a later write would find it.
    ///
    /// Fails ([`crate::Kind::Authorization`]) when writing `document` would
    /// be refused, ([`crate::Kind::Relationship`]) when a relationship value
    /// is not a document, an id or a list of them.
    pub(crate) fn authorize<'a>(
        &'a self,
        collection: &'a Collection,
        document: &'a Document,
        old: Option<&'a Document>,
        resolve: Option<Resolve>,
    ) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let db = self.database()?;
            self.authorize_related(&db, collection, document, old, &[], resolve.as_ref()).await
        })
    }

    /// `authorizeRelated()`.
    fn authorize_related<'a>(
        &'a self,
        db: &'a Database,
        collection: &'a Collection,
        document: &'a Document,
        old: Option<&'a Document>,
        write_stack: &'a [String],
        resolve: Option<&'a Resolve>,
    ) -> BoxFuture<'a, Result<()>> {
        Box::pin(async move {
            let depth = write_stack.len();

            for attribute in relationships(collection)? {
                let Some(relationship) = &attribute.relationship else { continue };
                let key = attribute.key.as_str();
                if !document.has(key) {
                    continue;
                }

                // A relationship whose related collection was deleted is
                // left as it is (`relatedCollection()`, 8.3.6).
                let Some(related_collection) = db.find_collection(&relationship.related_collection).await? else {
                    continue;
                };
                if depth as i64 >= RELATION_MAX_DEPTH - 1 && write_stack[depth - 1] != related_collection.id() {
                    continue;
                }

                let linked = related_ids(old.and_then(|o| o.get(key)));
                let mut value = document.get(key).cloned().unwrap_or(Value::Null);
                if let Value::Ext(Ext::Operator(operator)) = &value {
                    value = if operator.is_array_operation() {
                        value::list(apply_relationship_operator(operator, &linked).into_iter().map(value::string))
                    } else {
                        value::list([])
                    };
                }

                for related in related_values(&value)? {
                    match related {
                        Related::Id(id) => {
                            self.authorize_link_by_id(db, &related_collection, &id, &linked, resolve).await?;
                        }
                        Related::Document(related) => {
                            self.authorize_related_document(
                                db,
                                collection,
                                &related_collection,
                                &related,
                                &linked,
                                write_stack,
                                resolve,
                            )
                            .await?;
                        }
                    }
                }
            }
            Ok(())
        })
    }

    /// `authorizeLinkById()`.
    async fn authorize_link_by_id(
        &self,
        db: &Database,
        related_collection: &Collection,
        id: &str,
        linked: &[String],
        resolve: Option<&Resolve>,
    ) -> Result<()> {
        if linked.iter().any(|l| l == id) {
            return Ok(());
        }
        let stored = self
            .view_related(
                db,
                related_collection,
                id,
                PermissionType::Read,
                resolve,
                vec![Query::select([document::ID])],
            )
            .await?;
        if stored.is_empty() {
            return Ok(());
        }
        self.authorize_link(db, related_collection, &stored)
    }

    /// `authorizeRelatedDocument()`.
    #[allow(clippy::too_many_arguments)]
    async fn authorize_related_document(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        related: &Document,
        linked: &[String],
        write_stack: &[String],
        resolve: Option<&Resolve>,
    ) -> Result<()> {
        let id = related.id();
        let stored = if id.is_empty() {
            Document::empty()
        } else {
            self.view_related(db, related_collection, id, PermissionType::Update, resolve, Vec::new()).await?
        };

        let mut stack = write_stack.to_vec();
        stack.push(collection.id().to_owned());

        if stored.is_empty() {
            let authorization = db.authorization();
            let input =
                Input::new(PermissionType::Create, related_collection.permissions_by_type(PermissionType::Create)?);
            if !authorization.is_valid(&input) {
                return Err(Error::authorization(authorization.description()));
            }
            return self.authorize_related(db, related_collection, related, None, &stack, resolve).await;
        }

        let changes = related.keys().any(|k| !matches!(k, value::KeyRef::Str(s) if s == document::ID.as_bytes()));
        if !linked.iter().any(|l| l == id) || changes {
            self.authorize_link(db, related_collection, &stored)?;
        }

        self.authorize_related(db, related_collection, related, Some(&stored), &stack, resolve).await
    }

    /// The related document `id` as a write would find it: the document
    /// `resolve` gives for it, else the stored one, either empty when there
    /// is none (`viewRelated()`). One the caller cannot read is refused for
    /// the reason of `check`, or of read when the caller passes `check`.
    async fn view_related(
        &self,
        db: &Database,
        collection: &Collection,
        id: &str,
        check: PermissionType,
        resolve: Option<&Resolve>,
        queries: Vec<Query>,
    ) -> Result<Document> {
        let staged = resolve.and_then(|resolve| resolve(collection.id(), id));
        let Some(staged) = staged else {
            let stored = db
                .skip_relationships(db.skip_query_hooks(db.get_document(collection.id(), id, queries, false)))
                .await?;
            if stored.is_empty() {
                self.refuse_unreadable(db, collection, id, check).await?;
            }
            return Ok(stored);
        };

        if !staged.is_empty()
            && !db.authorization().is_valid(&permission_input(collection, &staged, PermissionType::Read)?)
        {
            self.refuse_hidden(db, collection, &staged, check)?;
        }
        Ok(staged)
    }
}
