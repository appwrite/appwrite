//! `populateDocuments()`: the related documents of read documents, level
//! after level.
//!
//! PHP sets each related document object on every document holding it and
//! keeps changing that object at the levels below. Here every document the
//! population reads goes into an arena ([`Tree`]) that records which
//! document holds which; the levels below change the arena in place, and
//! the holdings are resolved once, deepest first, as PHP's shared objects
//! would have shown them.

use std::ops::Range;

use utopia_query::Method;

use super::{Relationships, attributes, junction, relationships, unique};
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, RELATION_MAX_DEPTH, Selects};
use crate::document::{self, Document};
use crate::error::Result;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{Relationship, RelationshipSide, RelationshipType};
use crate::value::{self, Ext, Value};

/// What a relationship attribute of a document in the [`Tree`] holds.
#[derive(Debug, Clone, PartialEq)]
pub(super) enum Link {
    /// One related document.
    One(usize),
    /// A list of related documents.
    Many(Vec<usize>),
}

/// The documents a population reads, and which of them holds which.
#[derive(Debug, Default)]
pub(super) struct Tree {
    /// Every document: the populated ones first, then each batch of related
    /// documents in the order they were read.
    pub(super) docs: Vec<Document>,
    /// `(holder, key, held)`, in the order they were set.
    pub(super) links: Vec<(usize, String, Link)>,
}

impl Tree {
    /// A tree of the documents to populate.
    pub(super) fn new(documents: Vec<Document>) -> Self {
        Tree { docs: documents, links: Vec::new() }
    }

    /// Adds documents; returns their range.
    pub(super) fn add(&mut self, documents: Vec<Document>) -> Range<usize> {
        let start = self.docs.len();
        self.docs.extend(documents);
        start..self.docs.len()
    }

    /// `$document->removeAttribute($key)`.
    pub(super) fn remove(&mut self, node: usize, key: &str) {
        self.links.retain(|(holder, k, _)| !(*holder == node && k == key));
        self.docs[node].remove(key);
    }

    /// `$document->setAttribute($key, $value)` with a value that holds no
    /// document of the tree.
    pub(super) fn set(&mut self, node: usize, key: &str, value: Value) {
        self.links.retain(|(holder, k, _)| !(*holder == node && k == key));
        self.docs[node].put(key, value);
    }

    /// `$document->setAttribute($key, $related)`: the attribute keeps or
    /// takes its position now, and holds the related documents once
    /// resolved.
    pub(super) fn link(&mut self, node: usize, key: &str, link: Link) {
        self.set(node, key, Value::Null);
        self.links.push((node, key.to_owned(), link));
    }

    /// The first `count` documents with every holding resolved, the latest
    /// set first: a held document is complete when it is copied into its
    /// holder, as the levels below set their holdings after it was set.
    pub(super) fn resolve(mut self, count: usize) -> Vec<Document> {
        while let Some((holder, key, link)) = self.links.pop() {
            let value = match link {
                Link::One(held) => Value::from(self.docs[held].clone()),
                Link::Many(held) => value::list(held.iter().map(|&h| Value::from(self.docs[h].clone()))),
            };
            self.docs[holder].put(key, value);
        }
        self.docs.truncate(count);
        self.docs
    }
}

/// One entry of the population's breadth-first queue.
struct Level {
    /// The documents to populate.
    docs: Vec<usize>,
    /// Their collection.
    collection: Collection,
    /// The nested selections by relationship key.
    selects: Selects,
    /// The back-reference that led here, skipped (`skipKey`).
    skip_key: Option<String>,
    /// Whether the population was asked for explicit selections
    /// (`hasExplicitSelects`).
    explicit: bool,
}

/// The select queries of `queries`, and the others.
fn split_selects(queries: &[Query]) -> (Vec<Query>, Vec<Query>) {
    queries.iter().cloned().partition(|q| q.method() == Method::Select)
}

/// The id a stored reference holds (`$value instanceof Document ?
/// $value->getId() : (is_string($value) ? $value : null)`).
fn reference(value: Option<&Value>) -> Option<String> {
    match value? {
        Value::Ext(Ext::Document(d)) => Some(d.id().to_owned()),
        v @ Value::Str(_) => Some(value::to_text(v)),
        _ => None,
    }
}

/// `(key, nodes)` pairs grouped in first-seen order.
fn group(groups: &mut Vec<(String, Vec<usize>)>, key: String, node: usize) {
    match groups.iter_mut().find(|(k, _)| *k == key) {
        Some((_, nodes)) => nodes.push(node),
        None => groups.push((key, vec![node])),
    }
}

impl Relationships {
    /// The documents with their related documents populated, from
    /// `fetch_depth` down to [`RELATION_MAX_DEPTH`], as `selects` select
    /// them (`populateDocuments()`).
    pub(crate) fn populate_documents<'a>(
        &'a self,
        documents: Vec<Document>,
        collection: &'a Collection,
        fetch_depth: i64,
        selects: Selects,
    ) -> BoxFuture<'a, Result<Vec<Document>>> {
        Box::pin(self.in_batch_population.with(true, async move {
            let db = self.database()?;
            let count = documents.len();
            let mut tree = Tree::new(documents);
            let explicit = !selects.is_empty();
            let mut queue = vec![Level {
                docs: (0..count).collect(),
                collection: collection.clone(),
                selects,
                skip_key: None,
                explicit,
            }];
            let mut current = fetch_depth;

            while !queue.is_empty() && current < RELATION_MAX_DEPTH {
                let mut next = Vec::new();

                for level in queue {
                    if level.docs.is_empty() {
                        continue;
                    }

                    for attribute in attributes(&level.collection)? {
                        let (Some(relationship), Some(side)) = (&attribute.relationship, attribute.side) else {
                            continue;
                        };
                        let key = attribute.key.as_str();
                        let selected = level.selects.iter().find(|(k, _)| k == key).map(|(_, q)| q);
                        if level.skip_key.as_deref() == Some(key) || (level.explicit && selected.is_none()) {
                            continue;
                        }

                        let queries = selected.cloned().unwrap_or_default();

                        if current + 1 >= RELATION_MAX_DEPTH {
                            for &node in &level.docs {
                                tree.remove(node, key);
                            }
                            continue;
                        }

                        let related = self
                            .populate_single_relationship_batch(
                                &db,
                                &mut tree,
                                &level.docs,
                                &level.collection,
                                key,
                                relationship,
                                side,
                                &queries,
                            )
                            .await?;

                        let two_way = relationship.two_way;
                        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
                        let nested = selected.is_some();

                        if !related.is_empty() && (nested || !level.explicit) {
                            let related_collection =
                                db.silent(db.find_collection(&relationship.related_collection)).await?;
                            if let Some(related_collection) = related_collection {
                                let mut relationship_queries = if nested { queries.clone() } else { Vec::new() };
                                let next_selects = self
                                    .process_queries(&relationships(&related_collection)?, &mut relationship_queries)?;
                                next.push(Level {
                                    docs: related.clone(),
                                    collection: related_collection,
                                    selects: next_selects,
                                    skip_key: two_way.then(|| two_way_key.clone()),
                                    explicit: level.explicit,
                                });
                            }
                        }

                        if two_way {
                            for &node in &related {
                                tree.remove(node, &two_way_key);
                            }
                        }
                    }
                }

                queue = next;
                current += 1;
            }

            Ok(tree.resolve(count))
        }))
    }

    /// The attributes a related document keeps for the selects passed down
    /// to it, as 7.x read them (`selectsOfRelated()`): the selects as
    /// [`Relationships::process_queries`] leaves them.
    fn selects_of_related(&self, related_collection: &Collection, select_queries: &[Query]) -> Result<Vec<Query>> {
        let mut selects = select_queries.to_vec();
        self.process_queries(&relationships(related_collection)?, &mut selects)?;
        Ok(selects)
    }

    /// Populates one relationship of `docs` by its type; returns the related
    /// documents read, for the next level (`populateSingleRelationshipBatch()`).
    #[allow(clippy::too_many_arguments)]
    async fn populate_single_relationship_batch(
        &self,
        db: &Database,
        tree: &mut Tree,
        docs: &[usize],
        collection: &Collection,
        key: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        match relationship.kind {
            RelationshipType::OneToOne => self.populate_one_to_one(db, tree, docs, key, relationship, queries).await,
            RelationshipType::OneToMany => {
                if side == RelationshipSide::Child {
                    if !relationship.two_way {
                        for &node in docs {
                            tree.remove(node, key);
                        }
                        return Ok(Vec::new());
                    }
                    return self.populate_one_to_one(db, tree, docs, key, relationship, queries).await;
                }
                self.populate_back_referenced(db, tree, docs, key, relationship, queries).await
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Parent {
                    return self.populate_one_to_one(db, tree, docs, key, relationship, queries).await;
                }
                if !relationship.two_way {
                    for &node in docs {
                        tree.remove(node, key);
                    }
                    return Ok(Vec::new());
                }
                self.populate_back_referenced(db, tree, docs, key, relationship, queries).await
            }
            RelationshipType::ManyToMany => {
                self.populate_many_to_many(db, tree, docs, collection, key, relationship, side, queries).await
            }
        }
    }

    /// The related documents of `ids` (`$attribute` among them), read chunk
    /// by chunk with `queries` appended.
    async fn read_related(
        &self,
        db: &Database,
        collection: &str,
        attribute: &str,
        ids: &[String],
        queries: &[Query],
    ) -> Result<Vec<Document>> {
        let reads: Vec<BoxFuture<'_, Result<Vec<Document>>>> = ids
            .chunks(self.relation_query_chunk_size(db))
            .map(|chunk| {
                let mut all = vec![Query::equal(attribute, chunk.to_vec()), Query::limit(i64::MAX)];
                all.extend(queries.iter().cloned());
                db.find(collection, all, PermissionType::Read)
            })
            .collect();
        self.read_chunks(db, reads).await
    }

    /// One-to-one population, and the single side of one-to-many and
    /// many-to-one (`populateOneToOneRelationshipsBatch()`).
    async fn populate_one_to_one(
        &self,
        db: &Database,
        tree: &mut Tree,
        docs: &[usize],
        key: &str,
        relationship: &Relationship,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let related_collection = db.get_collection(&relationship.related_collection).await?;

        let mut related_ids = Vec::new();
        let mut by_related: Vec<(String, Vec<usize>)> = Vec::new();
        for &node in docs {
            match tree.docs[node].get(key) {
                None | Some(Value::Ext(Ext::Document(_))) => continue,
                Some(v) => {
                    let id = value::to_text(v);
                    related_ids.push(id.clone());
                    group(&mut by_related, id, node);
                }
            }
        }

        if related_ids.is_empty() {
            return Ok(Vec::new());
        }

        let (select_queries, other_queries) = split_selects(queries);
        let found =
            self.read_related(db, related_collection.id(), document::ID, &unique(related_ids), &other_queries).await?;
        let range = tree.add(found);

        let mut by_id: Vec<(String, usize)> = Vec::new();
        for node in range.clone() {
            let id = tree.docs[node].id().to_owned();
            match by_id.iter_mut().find(|(i, _)| *i == id) {
                Some((_, n)) => *n = node,
                None => by_id.push((id, node)),
            }
        }

        let selects = self.selects_of_related(&related_collection, &select_queries)?;
        db.apply_select_filters_to_documents(&mut tree.docs[range.clone()], &selects);

        for (related_id, holders) in by_related {
            match by_id.iter().find(|(i, _)| *i == related_id) {
                Some(&(_, held)) => {
                    for node in holders {
                        tree.link(node, key, Link::One(held));
                    }
                }
                None => {
                    for node in holders {
                        tree.set(node, key, Value::from(Document::empty()));
                    }
                }
            }
        }

        Ok(range.collect())
    }

    /// The many side of one-to-many (parent) and many-to-one (child): the
    /// related documents whose two-way key holds each document's id
    /// (`populateOneToManyRelationshipsBatch()`,
    /// `populateManyToOneRelationshipsBatch()`).
    async fn populate_back_referenced(
        &self,
        db: &Database,
        tree: &mut Tree,
        docs: &[usize],
        key: &str,
        relationship: &Relationship,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
        let related_collection = db.get_collection(&relationship.related_collection).await?;

        let ids = unique(docs.iter().map(|&node| tree.docs[node].id().to_owned()).collect());
        if ids.is_empty() {
            return Ok(Vec::new());
        }

        let (select_queries, other_queries) = split_selects(queries);
        let found = self.read_related(db, related_collection.id(), &two_way_key, &ids, &other_queries).await?;
        let range = tree.add(found);

        let mut by_holder: Vec<(String, Vec<usize>)> = Vec::new();
        for node in range.clone() {
            if let Some(holder) = reference(tree.docs[node].get(&two_way_key)) {
                group(&mut by_holder, holder, node);
            }
        }

        let selects = self.selects_of_related(&related_collection, &select_queries)?;
        db.apply_select_filters_to_documents(&mut tree.docs[range.clone()], &selects);

        for &node in docs {
            let id = tree.docs[node].id().to_owned();
            let held = by_holder.iter().find(|(k, _)| *k == id).map(|(_, h)| h.clone()).unwrap_or_default();
            tree.link(node, key, Link::Many(held));
        }

        Ok(range.collect())
    }

    /// Many-to-many population, through the junction collection
    /// (`populateManyToManyRelationshipsBatch()`).
    #[allow(clippy::too_many_arguments)]
    async fn populate_many_to_many(
        &self,
        db: &Database,
        tree: &mut Tree,
        docs: &[usize],
        collection: &Collection,
        key: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
        let related_collection = db.get_collection(&relationship.related_collection).await?;

        if !relationship.two_way && side == RelationshipSide::Child {
            return Ok(Vec::new());
        }

        let document_ids = unique(docs.iter().map(|&node| tree.docs[node].id().to_owned()).collect());
        if document_ids.is_empty() {
            return Ok(Vec::new());
        }

        let junction = junction(collection, &related_collection, side);
        let reads: Vec<BoxFuture<'_, Result<Vec<Document>>>> = document_ids
            .chunks(self.relation_query_chunk_size(db))
            .map(|chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
                let queries = vec![Query::equal(two_way_key.as_str(), chunk.to_vec()), Query::limit(i64::MAX)];
                Box::pin(db.skip_relationships(db.find(&junction, queries, PermissionType::Read)))
            })
            .collect();
        let junctions = self.read_chunks(db, reads).await?;

        let mut related_ids = Vec::new();
        let mut by_document: Vec<(String, Vec<String>)> = Vec::new();
        for junction in &junctions {
            let (Some(document_id), Some(related_id)) = (junction.get(&two_way_key), junction.get(key)) else {
                continue;
            };
            let (Some(document_id), Some(related_id)) = (reference(Some(document_id)), reference(Some(related_id)))
            else {
                continue;
            };
            match by_document.iter_mut().find(|(d, _)| *d == document_id) {
                Some((_, ids)) => ids.push(related_id.clone()),
                None => by_document.push((document_id, vec![related_id.clone()])),
            }
            related_ids.push(related_id);
        }

        let (select_queries, other_queries) = split_selects(queries);

        let mut related: Vec<(String, Vec<usize>)> = Vec::new();
        let mut range = 0..0;
        if !related_ids.is_empty() {
            let found = self
                .read_related(db, related_collection.id(), document::ID, &unique(related_ids), &other_queries)
                .await?;
            range = tree.add(found);

            let mut by_id: Vec<(String, usize)> = Vec::new();
            for node in range.clone() {
                let id = tree.docs[node].id().to_owned();
                match by_id.iter_mut().find(|(i, _)| *i == id) {
                    Some((_, n)) => *n = node,
                    None => by_id.push((id, node)),
                }
            }

            let selects = self.selects_of_related(&related_collection, &select_queries)?;
            db.apply_select_filters_to_documents(&mut tree.docs[range.clone()], &selects);

            for (document_id, ids) in by_document {
                let held =
                    ids.iter().filter_map(|id| by_id.iter().find(|(i, _)| i == id).map(|&(_, node)| node)).collect();
                match related.iter_mut().find(|(d, _)| *d == document_id) {
                    Some((_, h)) => *h = held,
                    None => related.push((document_id, held)),
                }
            }
        }

        for &node in docs {
            let id = tree.docs[node].id().to_owned();
            let held = related.iter().find(|(d, _)| *d == id).map(|(_, h)| h.clone()).unwrap_or_default();
            tree.link(node, key, Link::Many(held));
        }

        Ok(range.collect())
    }
}
