//! `afterDocumentUpdate()`: relating the related documents an update
//! changes.

use super::create::{Relation, attributes_differ, junction_document};
use super::{
    Prepared, Relationships, attributes, coerce_to_document, diff, id_of, intersect, junction, not_referencing, unique,
};
use crate::collection::Collection;
use crate::database::{BATCH_SIZE, BoxFuture, Database, RELATION_MAX_DEPTH};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::operator::{Operator, OperatorType};
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::value::{self, Ext, Value};

/// `gettype()`.
pub(super) fn gettype(value: Option<&Value>) -> &'static str {
    match value {
        None | Some(Value::Null) => "NULL",
        Some(Value::Bool(_)) => "boolean",
        Some(Value::Int(_)) => "integer",
        Some(Value::Float(_)) => "double",
        Some(Value::Str(_)) => "string",
        Some(Value::Array(_)) => "array",
        Some(Value::Object(_) | Value::Ext(_)) => "object",
    }
}

/// `in_array($needle, $haystack)` over strings (loose: numeric strings
/// compare as numbers).
fn in_array(needle: &str, haystack: &[String]) -> bool {
    let needle = value::string(needle);
    haystack.iter().any(|s| value::string(s.as_str()).loose_eq(&needle))
}

/// `array_map(fn (Document $document) => $document->getId(), $value)` over
/// a stored relationship value (`[]` unless it is an array).
///
/// Fails ([`crate::Kind::TypeError`]) for an element that is not a
/// document, as PHP's typed closure does.
fn stored_ids(value: Option<&Value>) -> Result<Vec<String>> {
    let Some(Value::Array(items)) = value else { return Ok(Vec::new()) };
    items
        .values()
        .map(|item| match item {
            Value::Ext(Ext::Document(d)) => Ok(d.id().to_owned()),
            other => Err(Error::new(
                Kind::TypeError,
                format!(
                    "{{closure}}(): Argument #1 ($document) must be of type Utopia\\Database\\Document, {} given",
                    other.debug_type()
                ),
            )),
        })
        .collect()
}

/// The ids of a relationship update's list (`string` or `Document` items).
///
/// Fails ([`crate::Kind::Relationship`]) for any other item.
fn new_ids(items: &value::Array) -> Result<Vec<String>> {
    items
        .values()
        .map(|item| match item {
            Value::Str(_) => Ok(value::to_text(item)),
            Value::Ext(Ext::Document(d)) => Ok(d.id().to_owned()),
            _ => Err(Error::relationship("Invalid relationship value. Must be either a document or document ID.")),
        })
        .collect()
}

/// `$document->getAttribute(Document::PERMISSIONS)`.
fn permissions_of(document: &Document) -> Value {
    document.get(document::PERMISSIONS).cloned().unwrap_or(Value::Null)
}

/// `array_splice($ids, $offset, 0, [$id])`.
fn splice(ids: &mut Vec<String>, offset: i64, id: String) {
    let len = ids.len() as i64;
    let at = if offset < 0 { (len + offset).max(0) } else { offset.min(len) };
    ids.insert(at as usize, id);
}

/// The ids a relationship value holds after an array operator applies to
/// the ids it held (`applyRelationshipOperator()`).
pub(super) fn apply_relationship_operator(operator: &Operator, existing: &[String]) -> Vec<String> {
    let values = operator.values();
    let falsy = |id: &String| !super::empty(id);
    let value_ids: Vec<String> = values.values().filter_map(id_of).filter(falsy).collect();

    match operator.method() {
        OperatorType::ArrayAppend => existing.iter().cloned().chain(value_ids).collect(),
        OperatorType::ArrayPrepend => value_ids.into_iter().chain(existing.iter().cloned()).collect(),
        OperatorType::ArrayInsert => {
            let index = values.get(&value::ArrayKey::Int(0)).map(value::to_long).unwrap_or(0);
            let mut ids = existing.to_vec();
            if let Some(id) = values.get(&value::ArrayKey::Int(1)).and_then(id_of) {
                splice(&mut ids, index, id);
            }
            ids
        }
        OperatorType::ArrayRemove => match values.get(&value::ArrayKey::Int(0)) {
            Some(Value::Array(remove)) => {
                let remove: Vec<String> = remove.values().filter_map(id_of).filter(falsy).collect();
                diff(existing, &remove)
            }
            Some(remove) => match id_of(remove) {
                Some(id) => diff(existing, &[id]),
                None => existing.to_vec(),
            },
            None => existing.to_vec(),
        },
        OperatorType::ArrayUnique => unique(existing.to_vec()),
        OperatorType::ArrayIntersect => intersect(existing, &value_ids),
        OperatorType::ArrayDiff => diff(existing, &value_ids),
        _ => existing.to_vec(),
    }
}

impl Relationships {
    /// Relates the related documents an update changes (`afterDocumentUpdate()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when a one-to-one related document
    /// is linked elsewhere, ([`crate::Kind::Relationship`]) for an invalid
    /// relationship value, ([`crate::Kind::Authorization`]) for a related
    /// document the caller may not link.
    pub(crate) fn after_document_update<'a>(
        &'a self,
        collection: &'a Collection,
        old: &'a Document,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let db = self.database()?;
            let mut document = document;
            let write_stack = self.current_write_stack();
            let stack_count = write_stack.len();

            for attribute in attributes(collection)? {
                let Some(relation) = Relation::of(&attribute) else { continue };
                let key = relation.key;
                let mut value = coerce_to_document(&mut document, key)?;
                let old_value = old.get(key).cloned();
                let old_id = match &old_value {
                    Some(Value::Ext(Ext::Document(d))) => Some(d.id().to_owned()),
                    Some(v @ Value::Str(_)) => Some(value::to_text(v)),
                    _ => None,
                };
                let related_name = &attribute.relationship.as_ref().expect("a relationship").related_collection;

                if let Some(Value::Ext(Ext::Operator(operator))) = &value
                    && operator.is_array_operation()
                {
                    let existing: Vec<String> = match &old_value {
                        Some(Value::Array(items)) => items
                            .values()
                            .map(|item| match item {
                                Value::Ext(Ext::Document(d)) => d.id().to_owned(),
                                other => value::to_text(other),
                            })
                            .collect(),
                        _ => Vec::new(),
                    };
                    let ids = apply_relationship_operator(operator, &existing);
                    let list = value::list(ids.into_iter().map(value::string));
                    document.put(key, list.clone());
                    value = Some(list);
                }

                let null = Value::Null;
                if old_value.as_ref().unwrap_or(&null).loose_eq(value.as_ref().unwrap_or(&null)) {
                    let keeps_id = relation.kind == RelationshipType::OneToOne
                        || (relation.kind == RelationshipType::ManyToOne && relation.side == RelationshipSide::Parent);
                    match &value {
                        Some(Value::Ext(Ext::Document(d))) if keeps_id => {
                            let id = d.id().to_owned();
                            document.put(key, value::string(id));
                        }
                        _ => {
                            document.remove(key);
                        }
                    }
                    continue;
                }

                let Some(related_collection) = self.related_collection(&db, related_name, None).await? else {
                    super::create::detach(&relation, &mut document);
                    continue;
                };

                if stack_count as i64 >= RELATION_MAX_DEPTH - 1
                    && write_stack[stack_count - 1] != related_collection.id()
                {
                    document.remove(key);
                    continue;
                }

                self.in_write(
                    collection.id(),
                    self.update_value(
                        &db,
                        collection,
                        &related_collection,
                        &relation,
                        &mut document,
                        value,
                        old_value,
                        old_id,
                    ),
                )
                .await?;
            }

            Ok(document)
        })
    }

    /// Relates the value of one relationship attribute an update changes
    /// (the body of `afterDocumentUpdate()`'s loop).
    #[allow(clippy::too_many_arguments)]
    async fn update_value(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        relation: &Relation<'_>,
        document: &mut Document,
        value: Option<Value>,
        old_value: Option<Value>,
        old_id: Option<String>,
    ) -> Result<()> {
        use RelationshipSide::{Child, Parent};
        use RelationshipType::{ManyToMany, ManyToOne, OneToMany, OneToOne};

        let key = relation.key;
        let two_way_key = relation.two_way_key.as_str();
        let rc = related_collection.id();
        let select_id = || vec![Query::select([document::ID])];
        let old_document_id = match &old_value {
            Some(Value::Ext(Ext::Document(d))) => Some(d.id().to_owned()),
            _ => None,
        };

        match relation.kind {
            OneToOne if !relation.two_way => {
                if relation.side == Child {
                    return Err(Error::relationship(
                        "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.",
                    ));
                }
                match value {
                    Some(v @ Value::Str(_)) => {
                        let id = value::to_text(&v);
                        let related = db
                            .skip_relationships(db.skip_query_hooks(db.get_document(rc, &id, select_id(), false)))
                            .await?;
                        if related.is_empty() {
                            self.refuse_unreadable(db, related_collection, &id, PermissionType::Read).await?;
                            document.put(key, Value::Null);
                        } else if Some(&id) != old_id.as_ref() {
                            self.authorize_link(db, related_collection, &related)?;
                        }
                    }
                    Some(Value::Ext(Ext::Document(related))) => {
                        let linked = Some(related.id()) == old_id.as_deref();
                        let one_way = Relation {
                            key,
                            kind: relation.kind,
                            two_way: false,
                            two_way_key: relation.two_way_key.clone(),
                            side: relation.side,
                        };
                        let id = self
                            .relate_documents(
                                db,
                                collection,
                                related_collection,
                                &one_way,
                                document,
                                *related,
                                None,
                                linked,
                            )
                            .await?;
                        document.put(key, value::string(id));
                    }
                    Some(Value::Array(_)) => {
                        return Err(Error::relationship(
                            "Invalid relationship value. Must be either a document, document ID or null. Array given.",
                        ));
                    }
                    _ => {}
                }
            }
            OneToOne => match value {
                Some(v @ Value::Str(_)) => {
                    let id = value::to_text(&v);
                    if self.is_relating(rc, &id) {
                        return Ok(());
                    }
                    let related = db
                        .skip_relationships(db.skip_query_hooks(db.get_document(rc, &id, select_id(), false)))
                        .await?;
                    if related.is_empty() {
                        self.refuse_unreadable(db, related_collection, &id, PermissionType::Read).await?;
                        document.put(key, Value::Null);
                    } else {
                        if old_document_id.as_deref() != Some(id.as_str())
                            && self.is_linked_elsewhere(db, collection, key, &id, document).await?
                        {
                            return Err(Error::duplicate("Document already has a related document"));
                        }
                        let mut related = related;
                        related.put(two_way_key, value::string(document.id()));
                        let related_id = related.id().to_owned();
                        db.skip_relationships(db.update_document(rc, &related_id, related)).await?;
                    }
                }
                Some(Value::Ext(Ext::Document(value))) => {
                    let mut value = *value;
                    let related = db
                        .skip_relationships(db.skip_query_hooks(db.get_document(rc, value.id(), Vec::new(), false)))
                        .await?;
                    if old_document_id.as_deref() != Some(value.id())
                        && self.is_linked_elsewhere(db, collection, key, value.id(), document).await?
                    {
                        return Err(Error::duplicate("Document already has a related document"));
                    }
                    let related = if related.is_empty() {
                        self.refuse_unreadable(db, related_collection, value.id(), PermissionType::Update).await?;
                        if !value.is_set(document::PERMISSIONS) {
                            value.set(document::PERMISSIONS, permissions_of(document))?;
                        }
                        value.put(two_way_key, value::string(document.id()));
                        db.create_document(rc, value).await?
                    } else {
                        value.put(two_way_key, value::string(document.id()));
                        let related_id = related.id().to_owned();
                        db.update_document(rc, &related_id, value).await?
                    };
                    document.put(key, value::string(related.id()));
                }
                None => {
                    if let Some(old_related_id) = &old_document_id {
                        let old_related = db
                            .skip_relationships(db.skip_query_hooks(db.get_document(
                                rc,
                                old_related_id,
                                Vec::new(),
                                false,
                            )))
                            .await?;
                        let id = old_related.id().to_owned();
                        db.skip_relationships(db.update_document(
                            rc,
                            &id,
                            Document::from_pairs([(two_way_key, Value::Null)])?,
                        ))
                        .await?;
                    }
                }
                Some(_) => {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either a document, document ID or null.",
                    ));
                }
            },
            OneToMany | ManyToOne
                if (relation.kind == OneToMany && relation.side == Parent)
                    || (relation.kind == ManyToOne && relation.side == Child) =>
            {
                let items = match &value {
                    Some(Value::Array(items)) if items.is_list() => items.clone(),
                    other => {
                        return Err(Error::relationship(format!(
                            "Invalid relationship value. Must be either an array of documents or document IDs, {} given.",
                            gettype(other.as_ref())
                        )));
                    }
                };
                let old_ids = stored_ids(old_value.as_ref())?;
                let new_ids = new_ids(&items)?;
                let removed = diff(&old_ids, &new_ids);
                let chunk_size = self.relation_query_chunk_size(db);

                if !removed.is_empty() {
                    for chunk in removed.chunks(chunk_size) {
                        db.authorization()
                            .skip(db.skip_relationships(db.update_documents(
                                rc,
                                Document::from_pairs([(two_way_key, Value::Null)])?,
                                vec![Query::equal(document::ID, chunk.to_vec())],
                                BATCH_SIZE,
                                None,
                            )))
                            .await?;
                    }
                }

                let mut string_relations = Vec::new();
                let mut document_relations = Vec::new();
                for relation in items.values() {
                    match relation {
                        Value::Str(_) => string_relations.push(value::to_text(relation)),
                        Value::Ext(Ext::Document(d)) => document_relations.push((**d).clone()),
                        _ => return Err(Error::relationship("Invalid relationship value.")),
                    }
                }

                if !string_relations.is_empty() {
                    let mut unlinked_ids = Vec::new();
                    for chunk in string_relations.chunks(chunk_size) {
                        let unlinked = db
                            .skip_relationships(db.skip_query_hooks(db.find(
                                rc,
                                vec![
                                    Query::select([document::ID]),
                                    Query::equal(document::ID, chunk.to_vec()),
                                    not_referencing(two_way_key, document.id()),
                                    Query::limit(chunk.len() as i64),
                                ],
                                PermissionType::Read,
                            )))
                            .await?;
                        unlinked_ids.extend(unlinked.iter().map(|d| d.id().to_owned()));
                    }

                    for relation in diff(&diff(&string_relations, &unlinked_ids), &old_ids) {
                        self.refuse_unreadable(db, related_collection, &relation, PermissionType::Read).await?;
                    }

                    self.link_related_documents(db, related_collection, two_way_key, document.id(), unlinked_ids)
                        .await?;
                }

                let document_id = document.id().to_owned();
                let permissions = permissions_of(document);
                let relate = |prepared: Option<Prepared>| -> BoxFuture<'_, Result<()>> {
                    let relations = document_relations.clone();
                    let document_id = document_id.clone();
                    let permissions = permissions.clone();
                    Box::pin(async move {
                        for mut relation in relations {
                            if let Some(prepared) = &prepared {
                                if !relation.is_set(document::PERMISSIONS) {
                                    relation.set(document::PERMISSIONS, permissions.clone())?;
                                }
                                relation.put(two_way_key, value::string(document_id.as_str()));
                                self.prepare(db, prepared, related_collection, relation).await?;
                                continue;
                            }

                            let related = db
                                .skip_relationships(db.skip_query_hooks(db.get_document(
                                    rc,
                                    relation.id(),
                                    select_id(),
                                    false,
                                )))
                                .await?;

                            if related.is_empty() {
                                self.refuse_unreadable(db, related_collection, relation.id(), PermissionType::Update)
                                    .await?;
                                if !relation.is_set(document::PERMISSIONS) {
                                    relation.set(document::PERMISSIONS, permissions.clone())?;
                                }
                                relation.put(two_way_key, value::string(document_id.as_str()));
                                db.create_document(rc, relation).await?;
                            } else {
                                relation.put(two_way_key, value::string(document_id.as_str()));
                                let related_id = related.id().to_owned();
                                db.update_document(rc, &related_id, relation).await?;
                            }
                        }
                        Ok(())
                    })
                };
                self.relate_updated(db, related_collection, &document_relations, relate).await?;

                document.remove(key);
            }
            OneToMany | ManyToOne => match value {
                Some(v @ Value::Str(_)) => {
                    let id = value::to_text(&v);
                    if self.is_relating(rc, &id) {
                        return Ok(());
                    }
                    let related = db
                        .skip_relationships(db.skip_query_hooks(db.get_document(rc, &id, select_id(), false)))
                        .await?;
                    if related.is_empty() {
                        self.refuse_unreadable(db, related_collection, &id, PermissionType::Read).await?;
                        document.put(key, Value::Null);
                    } else if Some(&id) != old_id.as_ref() {
                        self.authorize_link(db, related_collection, &related)?;
                    }
                    db.purge_cached_document(rc, &id).await?;
                }
                Some(Value::Ext(Ext::Document(value))) => {
                    let mut value = *value;
                    if value.id().is_empty() {
                        return Err(Error::relationship(format!(
                            "Invalid relationship value. Document must have a valid {}.",
                            document::ID
                        )));
                    }
                    let related = db
                        .skip_relationships(db.skip_query_hooks(db.get_document(rc, value.id(), select_id(), false)))
                        .await?;
                    if related.is_empty() {
                        self.refuse_unreadable(db, related_collection, value.id(), PermissionType::Update).await?;
                        if !value.is_set(document::PERMISSIONS) {
                            value.set(document::PERMISSIONS, permissions_of(document))?;
                        }
                        db.create_document(rc, value.clone()).await?;
                    } else if Some(value.id()) != old_id.as_deref() {
                        self.authorize_link(db, related_collection, &related)?;
                    }

                    if !related.is_empty() && attributes_differ(&related, &value) {
                        let related_id = related.id().to_owned();
                        db.update_document(rc, &related_id, value.clone()).await?;
                        db.purge_cached_document(rc, &related_id).await?;
                    }

                    document.put(key, value::string(value.id()));
                }
                None => {}
                Some(Value::Array(_)) => {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either a document ID or a document, array given.",
                    ));
                }
                Some(other) if !other.truthy() => {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either a document ID or a document.",
                    ));
                }
                Some(_) => return Err(Error::relationship("Invalid relationship value.")),
            },
            ManyToMany => {
                let items = match &value {
                    None => return Ok(()),
                    Some(Value::Array(items)) => items.clone(),
                    Some(_) => {
                        return Err(Error::relationship(
                            "Invalid relationship value. Must be an array of documents or document IDs.",
                        ));
                    }
                };
                let old_ids = stored_ids(old_value.as_ref())?;
                let new_ids = new_ids(&items)?;
                let removed = diff(&old_ids, &new_ids);
                let chunk_size = self.relation_query_chunk_size(db);
                let junction_name = junction(collection, related_collection, relation.side);

                if !removed.is_empty() {
                    // Chunk both the lookup and the delete so a many-to-many
                    // diff with thousands of removed peers stays within the
                    // validator's maxQueryValues ceiling.
                    let mut junction_ids = Vec::new();
                    for chunk in removed.chunks(chunk_size) {
                        let junctions = db
                            .skip_query_hooks(db.find(
                                &junction_name,
                                vec![
                                    Query::select([document::ID]),
                                    Query::equal(key, chunk.to_vec()),
                                    Query::equal(two_way_key, [document.id()]),
                                    Query::limit(i64::MAX),
                                ],
                                PermissionType::Read,
                            ))
                            .await?;
                        junction_ids.extend(junctions.iter().map(|d| d.id().to_owned()));
                    }

                    for chunk in junction_ids.chunks(chunk_size) {
                        db.authorization()
                            .skip(db.delete_documents(
                                &junction_name,
                                vec![Query::equal(document::ID, chunk.to_vec())],
                                BATCH_SIZE,
                                None,
                            ))
                            .await?;
                    }
                }

                let related_documents: Vec<Document> = items.values().filter_map(value::as_document).cloned().collect();
                let document_id = document.id().to_owned();
                let permissions = permissions_of(document);
                let junction_name = &junction_name;
                let old_ids = &old_ids;
                let relate = |prepared: Option<Prepared>| -> BoxFuture<'_, Result<()>> {
                    let items = items.clone();
                    let document_id = document_id.clone();
                    let permissions = permissions.clone();
                    Box::pin(async move {
                        for relation in items.values() {
                            if let Some(prepared) = &prepared {
                                if let Value::Ext(Ext::Document(d)) = relation {
                                    let mut relation = (**d).clone();
                                    if !relation.is_set(document::PERMISSIONS) {
                                        relation.set(document::PERMISSIONS, permissions.clone())?;
                                    }
                                    let related_id = self.prepare(db, prepared, related_collection, relation).await?;
                                    let junction = self.collection(db, prepared, junction_name).await?;
                                    let link = junction_document(key, &related_id, two_way_key, &document_id)?;
                                    self.prepare(db, prepared, &junction, link).await?;
                                    continue;
                                }
                                self.write_prepared(db, prepared).await?;
                            }

                            let relation_id = match relation {
                                Value::Str(_) => {
                                    let id = value::to_text(relation);
                                    if in_array(&id, old_ids) {
                                        continue;
                                    }
                                    let related =
                                        db.skip_query_hooks(db.get_document(rc, &id, select_id(), false)).await?;
                                    if related.is_empty() {
                                        self.refuse_unreadable(db, related_collection, &id, PermissionType::Read)
                                            .await?;
                                        continue;
                                    }
                                    self.authorize_link(db, related_collection, &related)?;
                                    id
                                }
                                Value::Ext(Ext::Document(d)) => {
                                    let mut relation = (**d).clone();
                                    let related = db
                                        .skip_query_hooks(db.get_document(rc, relation.id(), select_id(), false))
                                        .await?;
                                    if !related.is_empty() && !in_array(relation.id(), old_ids) {
                                        self.authorize_link(db, related_collection, &related)?;
                                    }
                                    let related = if related.is_empty() {
                                        self.refuse_unreadable(
                                            db,
                                            related_collection,
                                            relation.id(),
                                            PermissionType::Update,
                                        )
                                        .await?;
                                        if !relation.is_set(document::PERMISSIONS) {
                                            relation.set(document::PERMISSIONS, permissions.clone())?;
                                        }
                                        db.create_document(rc, relation.clone()).await?
                                    } else if attributes_differ(&related, &relation) {
                                        let related_id = related.id().to_owned();
                                        db.update_document(rc, &related_id, relation.clone()).await?
                                    } else {
                                        related
                                    };
                                    if in_array(relation.id(), old_ids) {
                                        continue;
                                    }
                                    related.id().to_owned()
                                }
                                _ => {
                                    return Err(Error::relationship(
                                        "Invalid relationship value. Must be either a document or document ID.",
                                    ));
                                }
                            };

                            db.skip_relationships(db.create_document(
                                junction_name,
                                junction_document(key, &relation_id, two_way_key, &document_id)?,
                            ))
                            .await?;
                        }
                        Ok(())
                    })
                };
                self.relate_updated(db, related_collection, &related_documents, relate).await?;

                document.remove(key);
            }
        }
        Ok(())
    }

    /// Whether another document of `collection` than `document` links
    /// `related_id` under `key` (`isLinkedElsewhere()`).
    async fn is_linked_elsewhere(
        &self,
        db: &Database,
        collection: &Collection,
        key: &str,
        related_id: &str,
        document: &Document,
    ) -> Result<bool> {
        let found = db
            .authorization()
            .skip(db.skip_relationships(db.skip_query_hooks(db.find_one(
                collection.id(),
                vec![
                    Query::select([document::ID]),
                    Query::equal(key, [related_id]),
                    Query::not_equal(document::ID, document.id()),
                ],
            ))))
            .await?;
        Ok(!found.is_empty())
    }

    /// Points `two_way_key` on the related documents `ids` at `document_id`
    /// (`linkRelatedDocuments()`). `updateDocuments()` leaves out every
    /// document the caller may not update, so a chunk that comes back short
    /// is finished one document at a time. With dates preserved, each is
    /// linked on its own (7.x kept each one's stored `$updatedAt`).
    async fn link_related_documents(
        &self,
        db: &Database,
        collection: &Collection,
        two_way_key: &str,
        document_id: &str,
        ids: Vec<String>,
    ) -> Result<()> {
        let ids = unique(ids);
        if db.is_preserving_dates() {
            for id in &ids {
                self.link_related_document(db, collection, id, two_way_key, document_id).await?;
            }
            return Ok(());
        }

        for chunk in ids.chunks(self.relation_query_chunk_size(db)) {
            let linked = db
                .skip_relationships(db.update_documents(
                    collection.id(),
                    Document::from_pairs([(two_way_key, value::string(document_id))])?,
                    vec![Query::equal(document::ID, chunk.to_vec())],
                    BATCH_SIZE,
                    None,
                ))
                .await?;
            if linked == chunk.len() as i64 {
                continue;
            }

            let unlinked = db
                .authorization()
                .skip(db.skip_relationships(db.skip_query_hooks(db.find(
                    collection.id(),
                    vec![
                        Query::select([document::ID]),
                        Query::equal(document::ID, chunk.to_vec()),
                        not_referencing(two_way_key, document_id),
                        Query::limit(chunk.len() as i64),
                    ],
                    PermissionType::Read,
                ))))
                .await?;
            for related in unlinked {
                self.link_related_document(db, collection, related.id(), two_way_key, document_id).await?;
            }
        }
        Ok(())
    }

    /// Links one related document `updateDocuments()` left out; one that is
    /// gone or linked already is skipped (`linkRelatedDocument()`).
    ///
    /// Fails ([`crate::Kind::Authorization`]) without update permission on it.
    async fn link_related_document(
        &self,
        db: &Database,
        collection: &Collection,
        id: &str,
        two_way_key: &str,
        document_id: &str,
    ) -> Result<()> {
        let related = db
            .authorization()
            .skip(db.skip_relationships(db.skip_query_hooks(db.get_document(collection.id(), id, Vec::new(), true))))
            .await?;

        if related.is_empty() || related.get(two_way_key).is_some_and(|v| v.strict_eq(&value::string(document_id))) {
            return Ok(());
        }

        self.authorize_link(db, collection, &related)?;

        let mut link = Document::from_pairs([(two_way_key, value::string(document_id))])?;
        if db.is_preserving_dates() {
            link.put(document::UPDATED_AT, related.updated_at().map(value::string).unwrap_or(Value::Null));
        }

        db.skip_relationships(db.update_document(collection.id(), id, link)).await?;
        Ok(())
    }
}
