//! `beforeDocumentDelete()`: the on-delete rule of each relationship.

use std::future::Future;
use std::sync::Arc;

use super::create::Relation;
use super::{Cascade, Relationships, attributes, junction, unique};
use crate::collection::Collection;
use crate::database::{BATCH_SIZE, BoxFuture, Database};
use crate::document::{self, Document};
use crate::error::{Error, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{RelationshipDeleteAction, RelationshipSide, RelationshipType};
use crate::value::{self, Ext, Value};

/// Documents by collection id, then by id, each in the order first set
/// (PHP's `array<string, array<string, Document>>`).
type Changed = Vec<(String, Vec<(String, Document)>)>;

/// `$changed[$collection][$id] = $document`.
fn set_changed(changed: &mut Changed, collection: &str, document: Document) {
    let id = document.id().to_owned();
    let documents = match changed.iter_mut().position(|(c, _)| c == collection) {
        Some(index) => &mut changed[index].1,
        None => {
            changed.push((collection.to_owned(), Vec::new()));
            &mut changed.last_mut().expect("just pushed").1
        }
    };
    match documents.iter_mut().find(|(i, _)| *i == id) {
        Some((_, existing)) => *existing = document,
        None => documents.push((id, document)),
    }
}

/// The restriction every restricting relationship refuses a delete with.
const RESTRICTED: &str = "Cannot delete document because it has at least one related document.";

/// `[Query::select(['$id']), Query::equal($twoWayKey, [$document->getId()])]`.
fn referencing(two_way_key: &str, document: &Document) -> Vec<Query> {
    vec![Query::select([document::ID]), Query::equal(two_way_key, [document.id()])]
}

impl Relationships {
    /// Applies the on-delete rule of each relationship before `document` is
    /// deleted (`beforeDocumentDelete()`); with `report`, returns the
    /// documents on the other side of a two-way relationship the delete
    /// leaves changed: the ones it wrote (as the write left them), and the
    /// ones it left holding a reference to the deleted document without
    /// writing them (as read off the deleted document). One-way peers are
    /// left out, and so is every peer a cascade removed.
    ///
    /// Fails ([`crate::Kind::Restricted`]) when a restricting relationship
    /// holds the document.
    pub(crate) fn before_document_delete<'a>(
        &'a self,
        collection: &'a Collection,
        document: &'a Document,
        report: bool,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(async move {
            let db = self.database()?;
            let mut changed = Changed::new();
            let mut cascaded = false;

            for attribute in attributes(collection)? {
                let Some(relation) = Relation::of(&attribute) else { continue };
                let relationship = attribute.relationship.as_ref().expect("a relationship");
                let key = relation.key;
                let value = document.get(key).cloned();
                let related_collection = db.get_collection(&relationship.related_collection).await?;
                let (kind, side, two_way) = (relation.kind, relation.side, relation.two_way);
                let two_way_key = relation.two_way_key.as_str();
                let on_delete = relationship.on_delete;

                let holds_key = (kind == RelationshipType::OneToMany && side == RelationshipSide::Child)
                    || (kind == RelationshipType::ManyToOne && side == RelationshipSide::Parent);
                let unwritten;

                match on_delete {
                    RelationshipDeleteAction::Restrict => {
                        self.delete_restrict(&db, collection, &related_collection, document, &relation).await?;
                        unwritten = true;
                    }
                    RelationshipDeleteAction::SetNull => {
                        let written = self
                            .delete_set_null(
                                &db,
                                collection,
                                &related_collection,
                                document,
                                &relation,
                                report && two_way,
                            )
                            .await?;
                        for related in written {
                            set_changed(&mut changed, related_collection.id(), related);
                        }
                        unwritten = holds_key || kind == RelationshipType::ManyToMany;
                    }
                    RelationshipDeleteAction::Cascade => {
                        unwritten =
                            holds_key || (kind == RelationshipType::ManyToMany && side == RelationshipSide::Child);
                        let cascade = Cascade {
                            collection: collection.id().to_owned(),
                            document: document.id().to_owned(),
                            attribute: attribute.clone(),
                        };

                        let followed = self.delete_stack.get().iter().any(|processed| {
                            let existing_key = processed.attribute.key.as_str();
                            let existing_collection = processed.collection.as_str();
                            let existing_related =
                                processed.attribute.relationship.as_ref().map(|r| r.related_collection.as_str());
                            let existing_two_way_key = processed
                                .attribute
                                .relationship
                                .as_ref()
                                .and_then(|r| r.two_way_key.as_deref())
                                .unwrap_or("");
                            let other_side = processed.attribute.side != Some(side);

                            let reflexive = *processed == cascade;
                            let symmetric = existing_key == two_way_key
                                && existing_two_way_key == key
                                && existing_related == Some(collection.id())
                                && existing_collection == related_collection.id()
                                && other_side;
                            let transitive = (existing_key == two_way_key
                                && existing_collection == related_collection.id()
                                && other_side)
                                || (existing_two_way_key == key
                                    && existing_related == Some(collection.id())
                                    && other_side)
                                || (existing_key == key
                                    && existing_two_way_key != two_way_key
                                    && existing_related == Some(related_collection.id())
                                    && other_side)
                                || (existing_key != key
                                    && existing_two_way_key == two_way_key
                                    && existing_related == Some(related_collection.id())
                                    && other_side);
                            reflexive || symmetric || transitive
                        });
                        if !followed {
                            self.delete_cascade(&db, collection, &related_collection, document, &relation, cascade)
                                .await?;
                        }
                    }
                }

                let values: Vec<Value> = match value {
                    Some(Value::Array(items)) => items.values().cloned().collect(),
                    other => vec![other.unwrap_or(Value::Null)],
                };
                for related in values {
                    let Value::Ext(Ext::Document(related)) = related else { continue };
                    if related.is_empty() {
                        continue;
                    }
                    if on_delete == RelationshipDeleteAction::Cascade && !unwritten {
                        cascaded = true;
                    } else if two_way && unwritten {
                        set_changed(&mut changed, related_collection.id(), *related);
                    }
                }
            }

            if !report {
                return Ok(Vec::new());
            }

            if let Some((_, documents)) = changed.iter_mut().find(|(c, _)| c == collection.id()) {
                documents.retain(|(id, _)| id != document.id());
            }

            if cascaded {
                changed = self.without_removed(&db, changed).await?;
            }

            Ok(changed.into_iter().flat_map(|(_, documents)| documents.into_iter().map(|(_, d)| d)).collect())
        })
    }

    /// The documents that still exist: a cascade can remove one anywhere
    /// down its chain (`withoutRemoved()`).
    async fn without_removed(&self, db: &Database, documents: Changed) -> Result<Changed> {
        let mut remaining = Changed::new();
        for (collection, by_id) in documents {
            let ids: Vec<String> = by_id.iter().map(|(_, d)| d.id().to_owned()).collect();
            for chunk in ids.chunks(self.relation_query_chunk_size(db)) {
                let found = db
                    .authorization()
                    .skip(db.skip_query_hooks(db.find(
                        &collection,
                        vec![
                            Query::equal(document::ID, chunk.to_vec()),
                            Query::select([document::ID]),
                            Query::limit(chunk.len() as i64),
                        ],
                        PermissionType::Read,
                    )))
                    .await?;
                for existing in found {
                    if let Some((_, document)) = by_id.iter().find(|(id, _)| id == existing.id()) {
                        set_changed(&mut remaining, &collection, document.clone());
                    }
                }
            }
        }
        Ok(remaining)
    }

    /// The `restrict` rule (`deleteRestrict()`).
    async fn delete_restrict(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
    ) -> Result<()> {
        let (kind, side, two_way) = (relation.kind, relation.side, relation.two_way);
        let two_way_key = relation.two_way_key.as_str();

        if kind != RelationshipType::ManyToOne
            && side == RelationshipSide::Parent
            && self.has_related_document(db, collection, related_collection, document, relation).await?
        {
            return Err(Error::restricted(RESTRICTED));
        }

        if kind == RelationshipType::OneToOne && side == RelationshipSide::Child && !two_way {
            db.authorization()
                .skip(async {
                    let related = db
                        .skip_query_hooks(db.find_one(related_collection.id(), referencing(two_way_key, document)))
                        .await?;
                    if related.is_empty() {
                        return Ok(());
                    }
                    let id = related.id().to_owned();
                    db.skip_relationships(db.update_document(
                        related_collection.id(),
                        &id,
                        Document::from_pairs([(two_way_key, Value::Null)])?,
                    ))
                    .await?;
                    Ok::<(), Error>(())
                })
                .await?;
        }

        if kind == RelationshipType::ManyToOne && side == RelationshipSide::Child {
            let related = db
                .authorization()
                .skip(db.skip_query_hooks(db.find_one(related_collection.id(), referencing(two_way_key, document))))
                .await?;
            if !related.is_empty() {
                return Err(Error::restricted(RESTRICTED));
            }
        }
        Ok(())
    }

    /// Whether a document on the other side of the relationship holds
    /// `document` (`hasRelatedDocument()`).
    async fn has_related_document(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
    ) -> Result<bool> {
        let authorization = db.authorization();
        if relation.kind == RelationshipType::OneToMany {
            let found = authorization
                .skip(db.skip_query_hooks(
                    db.find_one(related_collection.id(), referencing(&relation.two_way_key, document)),
                ))
                .await?;
            return Ok(!found.is_empty());
        }

        let related_ids = self.find_related_ids(db, collection, related_collection, document, relation).await?;
        for chunk in related_ids.chunks(self.relation_query_chunk_size(db)) {
            let related = authorization
                .skip(db.skip_query_hooks(db.find_one(
                    related_collection.id(),
                    vec![Query::select([document::ID]), Query::equal(document::ID, chunk.to_vec())],
                )))
                .await?;
            if !related.is_empty() {
                return Ok(true);
            }
        }
        Ok(false)
    }

    /// The ids of the documents on the other side of the relationship a
    /// delete of `document` reaches, read from storage with permissions and
    /// relationships skipped (`findRelatedIds()`): the relationship value on
    /// `document` holds only what the caller could read.
    async fn find_related_ids(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
    ) -> Result<Vec<String>> {
        let side = relation.side;
        match relation.kind {
            RelationshipType::OneToOne if side == RelationshipSide::Parent || relation.two_way => {
                self.find_stored_related_ids(db, collection, document, relation.key).await
            }
            RelationshipType::OneToMany if side == RelationshipSide::Parent => {
                self.find_referencing_ids(db, related_collection, document, &relation.two_way_key).await
            }
            RelationshipType::ManyToOne if side == RelationshipSide::Child => {
                self.find_referencing_ids(db, related_collection, document, &relation.two_way_key).await
            }
            RelationshipType::ManyToMany => {
                self.find_junction_related_ids(db, collection, related_collection, document, relation).await
            }
            _ => Ok(Vec::new()),
        }
    }

    /// `findStoredRelatedIds()`.
    async fn find_stored_related_ids(
        &self,
        db: &Database,
        collection: &Collection,
        document: &Document,
        key: &str,
    ) -> Result<Vec<String>> {
        let stored = db
            .authorization()
            .skip(db.skip_relationships(db.skip_query_hooks(db.get_document(
                collection.id(),
                document.id(),
                Vec::new(),
                true,
            ))))
            .await?;
        Ok(match stored.get(key) {
            Some(Value::Str(id)) if !id.is_empty() => vec![value::to_text(&Value::Str(id.clone()))],
            _ => Vec::new(),
        })
    }

    /// `findReferencingIds()`.
    async fn find_referencing_ids(
        &self,
        db: &Database,
        related_collection: &Collection,
        document: &Document,
        two_way_key: &str,
    ) -> Result<Vec<String>> {
        Ok(self
            .find_referencing_documents(db, related_collection, document, two_way_key)
            .await?
            .iter()
            .map(|d| d.id().to_owned())
            .collect())
    }

    /// `findJunctionRelatedIds()`.
    async fn find_junction_related_ids(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
    ) -> Result<Vec<String>> {
        let junctions = db
            .authorization()
            .skip(db.skip_relationships(db.skip_query_hooks(db.find(
                &junction(collection, related_collection, relation.side),
                vec![
                    Query::select([relation.key]),
                    Query::equal(relation.two_way_key.as_str(), [document.id()]),
                    Query::limit(i64::MAX),
                ],
                PermissionType::Read,
            ))))
            .await?;

        let mut related_ids = Vec::new();
        for junction in &junctions {
            if let Some(v @ Value::Str(id)) = junction.get(relation.key)
                && !id.is_empty()
            {
                related_ids.push(value::to_text(v));
            }
        }
        Ok(unique(related_ids))
    }

    /// Every document of `related_collection` whose `two_way_key` points at
    /// `document`, permissions skipped: a referencing document the caller
    /// cannot read still has to have its key cleared
    /// (`findReferencingDocuments()`).
    async fn find_referencing_documents(
        &self,
        db: &Database,
        related_collection: &Collection,
        document: &Document,
        two_way_key: &str,
    ) -> Result<Vec<Document>> {
        let mut queries = referencing(two_way_key, document);
        queries.push(Query::limit(i64::MAX));
        db.authorization()
            .skip(db.skip_query_hooks(db.find(related_collection.id(), queries, PermissionType::Read)))
            .await
    }

    /// Clears the key on every document referencing `document`
    /// (`clearReferences()`); returns the documents as the write left them,
    /// or none unless `report`.
    async fn clear_references(
        &self,
        db: &Database,
        related_collection: &Collection,
        document: &Document,
        two_way_key: &str,
        report: bool,
    ) -> Result<Vec<Document>> {
        let relations = self.find_referencing_documents(db, related_collection, document, two_way_key).await?;
        if relations.is_empty() {
            return Ok(Vec::new());
        }

        let relation_ids: Vec<String> = relations.iter().map(|d| d.id().to_owned()).collect();
        let mut cleared = Vec::new();
        for chunk in relation_ids.chunks(self.relation_query_chunk_size(db)) {
            let mut collect = |(updated, _): (Document, Option<Document>)| -> Result<()> {
                cleared.push(updated);
                Ok(())
            };
            let on_next: Option<&mut dyn crate::database::Callback<(Document, Option<Document>)>> =
                if report { Some(&mut collect) } else { None };
            db.authorization()
                .skip(db.skip_relationships(db.update_documents(
                    related_collection.id(),
                    Document::from_pairs([(two_way_key, Value::Null)])?,
                    vec![Query::equal(document::ID, chunk.to_vec())],
                    BATCH_SIZE,
                    on_next,
                )))
                .await?;
        }
        Ok(cleared)
    }

    /// The `setNull` rule (`deleteSetNull()`); returns the documents the
    /// delete wrote, as the write left them, or none unless `report`.
    async fn delete_set_null(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
        report: bool,
    ) -> Result<Vec<Document>> {
        let (side, two_way) = (relation.side, relation.two_way);
        let two_way_key = relation.two_way_key.as_str();
        match relation.kind {
            RelationshipType::OneToOne => {
                if !two_way && side == RelationshipSide::Parent {
                    return Ok(Vec::new());
                }
                let written = db
                    .authorization()
                    .skip(async {
                        let related = db
                            .skip_query_hooks(db.find_one(related_collection.id(), referencing(two_way_key, document)))
                            .await?;
                        if related.is_empty() {
                            return Ok(None);
                        }
                        let id = related.id().to_owned();
                        let written = db
                            .skip_relationships(db.update_document(
                                related_collection.id(),
                                &id,
                                Document::from_pairs([(two_way_key, Value::Null)])?,
                            ))
                            .await?;
                        Ok::<Option<Document>, Error>(Some(written))
                    })
                    .await?;
                Ok(match written {
                    Some(written) if report && !written.is_empty() => vec![written],
                    _ => Vec::new(),
                })
            }
            RelationshipType::OneToMany => {
                if side == RelationshipSide::Child {
                    return Ok(Vec::new());
                }
                self.clear_references(db, related_collection, document, two_way_key, report).await
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Parent {
                    return Ok(Vec::new());
                }
                self.clear_references(db, related_collection, document, two_way_key, report).await
            }
            RelationshipType::ManyToMany => {
                let junction = junction(collection, related_collection, side);
                let mut queries = referencing(two_way_key, document);
                queries.push(Query::limit(i64::MAX));
                let junctions = db.skip_query_hooks(db.find(&junction, queries, PermissionType::Read)).await?;
                let junction_ids: Vec<String> = junctions.iter().map(|d| d.id().to_owned()).collect();
                db.skip_relationships(self.delete_related_documents(db, &junction, junction_ids)).await?;
                Ok(Vec::new())
            }
        }
    }

    /// The `cascade` rule (`deleteCascade()`).
    async fn delete_cascade(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        document: &Document,
        relation: &Relation<'_>,
        cascade: Cascade,
    ) -> Result<()> {
        match relation.kind {
            RelationshipType::OneToOne | RelationshipType::OneToMany | RelationshipType::ManyToOne => {
                let related_ids = self.find_related_ids(db, collection, related_collection, document, relation).await?;
                if !related_ids.is_empty() {
                    self.cascade(cascade, self.delete_related_documents(db, related_collection.id(), related_ids))
                        .await?;
                }
            }
            RelationshipType::ManyToMany => {
                let junction = junction(collection, related_collection, relation.side);
                let junctions = db
                    .skip_relationships(db.skip_query_hooks(db.find(
                        &junction,
                        vec![
                            Query::select([document::ID, relation.key]),
                            Query::equal(relation.two_way_key.as_str(), [document.id()]),
                            Query::limit(i64::MAX),
                        ],
                        PermissionType::Read,
                    )))
                    .await?;

                let mut junction_ids = Vec::new();
                let mut related_ids = Vec::new();
                for junction_document in &junctions {
                    junction_ids.push(junction_document.id().to_owned());
                    if relation.side == RelationshipSide::Parent
                        && let Some(id) = junction_document.get(relation.key).and_then(super::id_of)
                    {
                        related_ids.push(id);
                    }
                }

                self.cascade(cascade, async {
                    self.delete_related_documents(db, related_collection.id(), related_ids).await?;
                    self.delete_related_documents(db, &junction, junction_ids).await
                })
                .await?;
            }
        }
        Ok(())
    }

    /// Runs `future` with `cascade` on the delete stack (`cascade()`).
    async fn cascade<F: Future>(&self, cascade: Cascade, future: F) -> F::Output {
        let mut stack = self.delete_stack.get().as_ref().clone();
        stack.push(cascade);
        self.delete_stack.with(Arc::new(stack), future).await
    }

    /// Deletes the related documents `ids` one at a time through
    /// `deleteDocument()` (`deleteRelatedDocuments()`), which checks the
    /// caller's delete permission on each and cascades below it.
    async fn delete_related_documents(&self, db: &Database, collection: &str, ids: Vec<String>) -> Result<()> {
        for id in unique(ids) {
            db.delete_document(collection, &id).await?;
        }
        Ok(())
    }
}
