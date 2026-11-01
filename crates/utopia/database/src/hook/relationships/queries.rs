//! `processQueries()` and `convertQueries()`: selects of relationship paths,
//! and filters on related attributes turned into filters on ids.

use utopia_query::{Array as QArray, Method, Value as QValue};

use super::{Relationships, attributes, empty, intersect, junction, unique};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, Selects};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{Relationship, RelationshipSide, RelationshipType};
use crate::value::{self, Ext, Value};

/// The relationship attribute named `key`, the last one when several are
/// (`$relationshipsByKey[$key] ?? null`).
fn by_key<'r>(relationships: &'r [Attribute], key: &str) -> Option<&'r Attribute> {
    relationships.iter().rev().find(|a| a.key == key)
}

/// `explode('.', $attribute)`: the first part, and the rest joined back.
fn split_path(attribute: &str) -> (&str, &str) {
    match attribute.split_once('.') {
        Some((first, rest)) => (first, rest),
        None => (attribute, ""),
    }
}

/// Whether the relationship is read from the other side's key
/// (`$needsParentResolution` / `$needsReverseLookup`).
fn reversed(kind: RelationshipType, side: RelationshipSide) -> bool {
    (kind == RelationshipType::OneToMany && side == RelationshipSide::Parent)
        || (kind == RelationshipType::ManyToOne && side == RelationshipSide::Child)
        || kind == RelationshipType::ManyToMany
}

/// Adds the truthy ids a stored reference holds (a document, an id, or a
/// list of them) to `ids`, each once.
fn add_references(ids: &mut Vec<String>, value: Option<&Value>, lists: bool) {
    let mut add = |v: &Value| {
        let id = match v {
            Value::Ext(Ext::Document(d)) => d.id().to_owned(),
            Value::Str(_) => value::to_text(v),
            _ => return,
        };
        if !empty(&id) && !ids.contains(&id) {
            ids.push(id);
        }
    };
    match value {
        Some(Value::Array(items)) if lists => items.values().for_each(&mut add),
        Some(v) => add(v),
        None => {}
    }
}

/// Adds a query to the queries of the relationship `key`, kept in the
/// order the relationships are first named (`$nestedSelections[$key][] =`).
fn push_select(nested: &mut Selects, key: &str, query: Query) {
    match nested.iter_mut().find(|(k, _)| k == key) {
        Some((_, queries)) => queries.push(query),
        None => nested.push((key.to_owned(), vec![query])),
    }
}

/// A filter on a related attribute passed down to the related documents,
/// so they are filtered as the documents holding them are:
/// `comments.approved` keeps only the approved comments of each document
/// read (`constrainRelated()`). Pagination, orders and `containsAll`
/// (which matches across different related documents, so no single one
/// has to match it) are not passed down.
fn constrain_related(relationships: &[Attribute], query: &Query, constraints: &mut Selects) {
    let method = query.method();
    let attribute = query.attribute();
    if !attribute.contains('.')
        || matches!(
            method,
            Method::Limit
                | Method::Offset
                | Method::CursorAfter
                | Method::CursorBefore
                | Method::OrderAsc
                | Method::OrderDesc
                | Method::OrderRandom
                | Method::ContainsAll
        )
    {
        return;
    }
    let (key, rest) = split_path(attribute);
    if by_key(relationships, key).is_none() {
        return;
    }
    push_select(constraints, key, Query::new(method, rest, query.values().clone(), ""));
}

/// The ids of documents.
fn ids_of(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

impl Relationships {
    /// The queries for each relationship's related documents
    /// (`processQueries()`): the selects that reach into it, which it
    /// rewrites to select the relationship keys the populate needs, the
    /// queries of a relationship query on it, and the filters on its
    /// attributes when the queries populate it without them, so a filter
    /// never populates a relationship the queries do not select.
    ///
    /// Fails ([`crate::Kind::Query`]) for a select value that is not a string.
    pub(crate) fn process_queries(&self, relationships: &[Attribute], queries: &mut [Query]) -> Result<Selects> {
        let mut nested: Selects = Vec::new();

        // Fast exit: collections without relationships short-circuit before
        // walking the query list.
        if relationships.is_empty() {
            return Ok(nested);
        }

        let mut selects = false;
        let mut constraints: Selects = Vec::new();

        for query in queries.iter_mut() {
            let method = query.method();

            if method == Method::Relationship {
                let key = query.attribute();
                if by_key(relationships, key).is_some() {
                    for inner in query.values().values() {
                        if let QValue::Ext(utopia_query::Object::Query(inner)) = inner {
                            push_select(&mut nested, key, Query::from((**inner).clone()));
                        }
                    }
                }
                continue;
            }

            if method != Method::Select {
                constrain_related(relationships, query, &mut constraints);
                continue;
            }

            selects = true;

            let mut values: Vec<Option<QValue>> = query.values().values().cloned().map(Some).collect();
            for slot in values.iter_mut() {
                let Some(QValue::Str(text)) = slot.as_ref() else {
                    return Err(Error::query("Select queries must contain only string attributes."));
                };
                let text = String::from_utf8_lossy(text.as_bytes()).into_owned();
                if !text.contains('.') {
                    continue;
                }

                let (selected, path) = split_path(&text);
                let Some(attribute) = by_key(relationships, selected) else { continue };
                let Some(relationship) = &attribute.relationship else { continue };

                let select = if empty(path) { Query::select(["*"]) } else { Query::select([path]) };
                push_select(&mut nested, selected, select);

                let keep = match relationship.kind {
                    RelationshipType::ManyToMany => false,
                    RelationshipType::OneToMany => attribute.side != Some(RelationshipSide::Parent),
                    RelationshipType::ManyToOne => attribute.side == Some(RelationshipSide::Parent),
                    RelationshipType::OneToOne => true,
                };
                *slot = keep.then(|| QValue::Str(selected.into()));
            }

            let mut finals: Vec<QValue> = values.into_iter().flatten().collect();
            if finals.is_empty() {
                finals.push(QValue::Str("*".into()));
            }
            query.set_values(QArray::from_list(finals));
        }

        for (key, filters) in constraints {
            let held = nested.iter().any(|(k, _)| *k == key);
            if !selects || held {
                for filter in filters {
                    push_select(&mut nested, &key, filter);
                }
            }
        }

        Ok(nested)
    }

    /// Queries on relationship attributes turned into queries on the ids
    /// they match (`convertQueries()`); `None` when no document can match.
    ///
    /// Fails ([`crate::Kind::Query`]) for two equal queries on the same
    /// related attribute, and when a related query is invalid.
    pub(crate) fn convert_queries<'a>(
        &'a self,
        relationships: &'a [Attribute],
        queries: Vec<Query>,
        collection: Option<&'a Collection>,
    ) -> BoxFuture<'a, Result<Option<Vec<Query>>>> {
        Box::pin(async move {
            // Fast exit: nothing to convert when the collection has no
            // relationship attributes.
            if relationships.is_empty() {
                return Ok(Some(queries));
            }

            let has_relationship_query =
                queries.iter().any(|q| q.attribute().contains('.') || q.method() == Method::ContainsAll);
            if !has_relationship_query {
                return Ok(Some(queries));
            }

            let mut additional = Vec::new();
            let mut grouped: Vec<(String, Attribute, Vec<Query>, Vec<usize>)> = Vec::new();
            let mut remove = Vec::new();

            for (index, query) in queries.iter().enumerate() {
                if query.method() != Method::ContainsAll || !query.attribute().contains('.') {
                    continue;
                }
                let (relationship_key, nested_attribute) = split_path(query.attribute());
                let Some(relationship) = by_key(relationships, relationship_key) else { continue };

                let mut parent_id_sets = Vec::new();
                let mut resolved = document::ID.to_owned();
                for value in query.values().values() {
                    let related = Query::equal(nested_attribute, [value.clone()]);
                    let Some((attribute, ids)) =
                        self.resolve_relationship_group_to_ids(relationship, vec![related], collection).await?
                    else {
                        return Ok(None);
                    };
                    resolved = attribute;
                    parent_id_sets.push(ids);
                }

                let ids = match parent_id_sets.split_first() {
                    Some((first, rest)) if !rest.is_empty() => {
                        rest.iter().fold(first.clone(), |acc, set| intersect(&acc, set))
                    }
                    Some((first, _)) => first.clone(),
                    None => Vec::new(),
                };
                if ids.is_empty() {
                    return Ok(None);
                }

                additional.push(Query::equal(resolved, ids));
                remove.push(index);
            }

            for (index, query) in queries.iter().enumerate() {
                if matches!(query.method(), Method::Select | Method::ContainsAll) || !query.attribute().contains('.') {
                    continue;
                }
                let (relationship_key, nested_attribute) = split_path(query.attribute());
                let Some(relationship) = by_key(relationships, relationship_key) else { continue };

                let related = Query::new(query.method(), nested_attribute, query.values().clone(), "");
                match grouped.iter_mut().find(|(k, ..)| k == relationship_key) {
                    Some((_, _, queries, indices)) => {
                        queries.push(related);
                        indices.push(index);
                    }
                    None => {
                        grouped.push((relationship_key.to_owned(), relationship.clone(), vec![related], vec![index]))
                    }
                }
            }

            for (relationship_key, relationship, related_queries, indices) in grouped {
                let mut equal_attributes: Vec<&str> = Vec::new();
                for query in &related_queries {
                    if query.method() == Method::Equal {
                        let attribute = query.attribute();
                        if equal_attributes.contains(&attribute) {
                            return Err(Error::query(format!(
                                "Multiple equal queries on '{relationship_key}.{attribute}' will never match a single document. Use Query::containsAll() to match across different related documents."
                            )));
                        }
                        equal_attributes.push(attribute);
                    }
                }

                match self.resolve_relationship_group_to_ids(&relationship, related_queries.clone(), collection).await {
                    Ok(None) => return Ok(None),
                    Ok(Some((attribute, ids))) => {
                        additional.push(Query::equal(attribute, ids));
                        remove.extend(indices);
                    }
                    Err(e) if e.is_a(Kind::Query) => return Err(e),
                    Err(e) if !e.is_engine_error() => return Ok(None),
                    Err(e) => return Err(e),
                }
            }

            let mut converted: Vec<Query> =
                queries.into_iter().enumerate().filter(|(i, _)| !remove.contains(i)).map(|(_, q)| q).collect();
            converted.extend(additional);
            Ok(Some(converted))
        })
    }

    /// The ids of the documents of `start` that nested paths (`a.b.attr`)
    /// of `queries` lead to (`processNestedRelationshipPath()`); `None` when
    /// a path is not a relationship path or matches nothing.
    async fn process_nested_relationship_path(
        &self,
        db: &Database,
        start: &str,
        queries: &[Query],
    ) -> Result<Option<Vec<String>>> {
        let mut path_groups: Vec<(String, Vec<Query>)> = Vec::new();
        for query in queries {
            let attribute = query.attribute();
            let Some((path, leaf)) = attribute.rsplit_once('.') else { continue };
            let leaf = Query::new(query.method(), leaf, query.values().clone(), "");
            match path_groups.iter_mut().find(|(p, _)| p == path) {
                Some((_, group)) => group.push(leaf),
                None => path_groups.push((path.to_owned(), vec![leaf])),
            }
        }

        let mut all_matching = Vec::new();
        for (path, leaf_queries) in path_groups {
            let mut current = start.to_owned();
            // Each link's key, the collection it starts from, its
            // relationship and side.
            let mut chain: Vec<(String, String, Relationship, RelationshipSide)> = Vec::new();

            for relationship_key in path.split('.') {
                let Some(definition) = db.silent(db.find_collection(&current)).await? else {
                    return Ok(None);
                };
                let link = attributes(&definition)?.into_iter().find_map(|a| match (&a.relationship, a.side) {
                    (Some(r), Some(side)) if a.key == relationship_key => {
                        Some((relationship_key.to_owned(), current.clone(), r.clone(), side))
                    }
                    _ => None,
                });
                let Some(link) = link else { return Ok(None) };
                current = link.2.related_collection.clone();
                chain.push(link);
            }

            let mut leaf = leaf_queries;
            leaf.push(Query::select([document::ID]));
            leaf.push(Query::limit(i64::MAX));
            let matching_docs = db.silent(db.skip_relationships(db.find(&current, leaf, PermissionType::Read))).await?;
            let mut matching_ids = ids_of(&matching_docs);
            if matching_ids.is_empty() {
                return Ok(None);
            }

            for (link_key, link_from, link_relationship, side) in chain.iter().rev() {
                let link_to = link_relationship.related_collection.as_str();
                let link_two_way_key = link_relationship.two_way_key.clone().unwrap_or_default();

                if reversed(link_relationship.kind, *side) {
                    let mut parent_ids = Vec::new();
                    if link_relationship.kind == RelationshipType::ManyToMany {
                        let from = db.silent(db.get_collection(link_from)).await?;
                        let to = db.silent(db.get_collection(link_to)).await?;
                        let junction = junction(&from, &to, *side);
                        let junction_docs = self
                            .read_by_ids(db, &matching_ids, |chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
                                let queries = vec![Query::equal(link_key.as_str(), chunk), Query::limit(i64::MAX)];
                                let junction = junction.clone();
                                Box::pin(async move {
                                    db.silent(db.skip_relationships(db.find(&junction, queries, PermissionType::Read)))
                                        .await
                                })
                            })
                            .await?;
                        for junction_doc in &junction_docs {
                            add_references(&mut parent_ids, junction_doc.get(&link_two_way_key), false);
                        }
                    } else {
                        let child_docs = self
                            .read_by_ids(db, &matching_ids, |chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
                                let queries = vec![Query::equal(document::ID, chunk), Query::limit(i64::MAX)];
                                Box::pin(db.silent(db.skip_relationships(db.find(
                                    link_to,
                                    queries,
                                    PermissionType::Read,
                                ))))
                            })
                            .await?;
                        for doc in &child_docs {
                            add_references(&mut parent_ids, doc.get(&link_two_way_key), true);
                        }
                    }
                    matching_ids = parent_ids;
                } else {
                    let parent_docs = self
                        .read_by_ids(db, &matching_ids, |chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
                            let queries = vec![
                                Query::equal(link_key.as_str(), chunk),
                                Query::select([document::ID]),
                                Query::limit(i64::MAX),
                            ];
                            Box::pin(db.silent(db.skip_relationships(db.find(
                                link_from,
                                queries,
                                PermissionType::Read,
                            ))))
                        })
                        .await?;
                    matching_ids = ids_of(&parent_docs);
                }

                if matching_ids.is_empty() {
                    return Ok(None);
                }
            }

            all_matching.extend(matching_ids);
        }

        Ok(Some(unique(all_matching)))
    }

    /// The attribute and ids the documents matching `related_queries` on
    /// the other side of `attribute` give (`resolveRelationshipGroupToIds()`);
    /// `None` when none match.
    async fn resolve_relationship_group_to_ids(
        &self,
        attribute: &Attribute,
        related_queries: Vec<Query>,
        collection: Option<&Collection>,
    ) -> Result<Option<(String, Vec<String>)>> {
        let (Some(relationship), Some(side)) = (&attribute.relationship, attribute.side) else {
            return Ok(None);
        };
        let db = self.database()?;
        let related_collection = relationship.related_collection.as_str();
        let kind = relationship.kind;
        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
        let relationship_key = attribute.key.as_str();

        let mut related_queries = related_queries;
        let mut path_ids = None;
        if related_queries.iter().any(|q| q.attribute().contains('.')) {
            match self.process_nested_relationship_path(&db, related_collection, &related_queries).await? {
                Some(ids) if !ids.is_empty() => path_ids = Some(ids),
                _ => return Ok(None),
            }
            related_queries.retain(|q| !q.attribute().contains('.'));
        }

        let needs_parent_resolution = reversed(kind, side);

        if kind == RelationshipType::ManyToMany
            && needs_parent_resolution
            && let Some(collection) = collection
        {
            let matching_docs = self
                .find_related(
                    &db,
                    related_collection,
                    &related_queries,
                    path_ids.as_deref(),
                    vec![Query::select([document::ID]), Query::limit(i64::MAX)],
                )
                .await?;
            let matching_ids = ids_of(&matching_docs);
            if matching_ids.is_empty() {
                return Ok(None);
            }

            let related_definition = db.silent(db.get_collection(related_collection)).await?;
            let junction = junction(collection, &related_definition, side);
            let junction_docs = self
                .read_by_ids(&db, &matching_ids, |chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
                    let queries = vec![Query::equal(relationship_key, chunk), Query::limit(i64::MAX)];
                    let junction = junction.clone();
                    let db = &db;
                    Box::pin(async move {
                        db.silent(db.skip_relationships(db.find(&junction, queries, PermissionType::Read))).await
                    })
                })
                .await?;

            let mut parent_ids = Vec::new();
            for junction_doc in &junction_docs {
                add_references(&mut parent_ids, junction_doc.get(&two_way_key), false);
            }
            return Ok((!parent_ids.is_empty()).then(|| (document::ID.to_owned(), parent_ids)));
        }

        if needs_parent_resolution {
            let matching_docs = self
                .find_related(
                    &db,
                    related_collection,
                    &related_queries,
                    path_ids.as_deref(),
                    vec![Query::limit(i64::MAX)],
                )
                .await?;
            let mut parent_ids = Vec::new();
            for doc in &matching_docs {
                add_references(&mut parent_ids, doc.get(&two_way_key), true);
            }
            return Ok((!parent_ids.is_empty()).then(|| (document::ID.to_owned(), parent_ids)));
        }

        let matching_docs = self
            .find_related(
                &db,
                related_collection,
                &related_queries,
                path_ids.as_deref(),
                vec![Query::select([document::ID]), Query::limit(i64::MAX)],
            )
            .await?;
        let matching_ids = ids_of(&matching_docs);
        Ok((!matching_ids.is_empty()).then(|| (relationship_key.to_owned(), matching_ids)))
    }

    /// The related documents matching `related_queries`, limited to
    /// `path_ids` when a nested path resolved them, without populating their
    /// relationships (`findRelated()`).
    async fn find_related(
        &self,
        db: &Database,
        related_collection: &str,
        related_queries: &[Query],
        path_ids: Option<&[String]>,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        let Some(path_ids) = path_ids else {
            let mut all = related_queries.to_vec();
            all.extend(queries);
            return db.silent(db.skip_relationships(db.find(related_collection, all, PermissionType::Read))).await;
        };

        self.read_by_ids(db, path_ids, |chunk| -> BoxFuture<'_, Result<Vec<Document>>> {
            let mut all = related_queries.to_vec();
            all.push(Query::equal(document::ID, chunk));
            all.extend(queries.iter().cloned());
            Box::pin(db.silent(db.skip_relationships(db.find(related_collection, all, PermissionType::Read))))
        })
        .await
    }
}
