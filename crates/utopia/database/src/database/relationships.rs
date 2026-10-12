//! Relationships: relationship attributes, populating related documents,
//! writing and deleting through relationships, and queries on related
//! attributes.
//!
//! Area: relationships.
//!
//! PHP `Document`s are shared objects: populating or relating a document
//! mutates every reference to it. Here documents are values, so:
//!
//! - population keeps every document it reads in a [`Population`] arena and
//!   records which document holds which related documents (a related
//!   document can be held by several parents); the levels below fill the
//!   arena in place, and the links are resolved once, deepest first, as
//!   PHP's references would have shown them;
//! - helpers that change a document take it by value and return it.

use std::collections::HashMap;

use super::documents::relationship_attributes;
use super::metadata::{documents_at, set_documents};
use super::{Database, Finally, Selects};
use crate::document::{Document, SetType};
use crate::error::{Error, Kind, Result};
use crate::helpers::permission::Permission;
use crate::helpers::role::Role;
use crate::operator::{Operator, OperatorMethod};
use crate::query::{Method, Query};
use crate::validator::{array_key, index};
use crate::value::{Array, ArrayKey, Ext, Value, as_document, list, string, to_text};

/// The options of a relationship attribute, as PHP's methods read them
/// (`$relationship['options'][...]`).
struct Relation {
    /// `$relationship['key']`.
    key: String,
    /// `relatedCollection`.
    related_collection: String,
    /// `relationType`.
    kind: String,
    /// `twoWay`.
    two_way: bool,
    /// `twoWayKey`.
    two_way_key: String,
    /// `onDelete`.
    on_delete: String,
    /// `side`.
    side: String,
}

impl Relation {
    /// The options of `relationship`.
    fn of(relationship: &Document) -> Self {
        let options = relationship.raw("options").cloned().unwrap_or(Value::Null);
        let text = |key: &str| to_text(index(&options, key));
        Self {
            key: to_text(relationship.raw("key").unwrap_or(&Value::Null)),
            related_collection: text("relatedCollection"),
            kind: text("relationType"),
            two_way: index(&options, "twoWay").truthy(),
            two_way_key: text("twoWayKey"),
            on_delete: text("onDelete"),
            side: text("side"),
        }
    }

    /// Whether this side stores many related documents through the other
    /// side's key (one-to-many parent, many-to-one child).
    fn reads_back_reference(&self) -> bool {
        (self.kind == Database::RELATION_ONE_TO_MANY && self.side == Database::RELATION_SIDE_PARENT)
            || (self.kind == Database::RELATION_MANY_TO_ONE && self.side == Database::RELATION_SIDE_CHILD)
    }

    /// Whether this side stores the related document's id in its own column
    /// (one-to-many child, many-to-one parent).
    fn holds_key(&self) -> bool {
        (self.kind == Database::RELATION_ONE_TO_MANY && self.side == Database::RELATION_SIDE_CHILD)
            || (self.kind == Database::RELATION_MANY_TO_ONE && self.side == Database::RELATION_SIDE_PARENT)
    }
}

/// The documents a population reads, and which of them holds which.
struct Population {
    /// Every document: the populated ones first, then each level's related
    /// documents, in the order they were found.
    docs: Vec<Document>,
    /// `(holder, key, held)`, in the order PHP set them.
    links: Vec<(usize, String, Link)>,
}

/// What a document's relationship attribute holds.
enum Link {
    /// One related document.
    One(usize),
    /// A list of related documents.
    Many(Vec<usize>),
}

impl Population {
    /// Sets `key` of document `holder` to `link` (resolved by
    /// [`Population::resolve`]); the attribute keeps or takes its position
    /// now, as PHP's `setAttribute` does.
    fn link(&mut self, holder: usize, key: &str, link: Link) {
        self.docs[holder].set(key, Value::Null);
        self.links.push((holder, key.to_owned(), link));
    }

    /// Adds `documents`; returns their positions.
    fn add(&mut self, documents: Vec<Document>) -> Vec<usize> {
        let start = self.docs.len();
        self.docs.extend(documents);
        (start..self.docs.len()).collect()
    }

    /// The first `count` documents with every link resolved: the deepest
    /// links first, so each held document is complete when copied.
    fn resolve(mut self, count: usize) -> Vec<Document> {
        while let Some((holder, key, link)) = self.links.pop() {
            let value = match link {
                Link::One(held) => Value::from(self.docs[held].clone()),
                Link::Many(held) => list(held.iter().map(|&h| Value::from(self.docs[h].clone()))),
            };
            self.docs[holder].set(key, value);
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
    collection: Document,
    /// The nested selections by relationship key.
    selects: Selects,
    /// The back-reference that led here, skipped.
    skip_key: Option<String>,
    /// Whether the population was asked for explicit selections.
    explicit: bool,
}

/// `new Document($pairs)`.
fn new_document(pairs: Vec<(&str, Value)>) -> Result<Document> {
    Document::new(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect())
}

/// `Error`: a method called on a value that is not an object.
fn not_an_object(method: &str, value: &Value) -> Error {
    Error::new(Kind::Error, format!("Call to a member function {method}() on {}", value.debug_type()))
}

/// `$value->getId()` on a value expected to be a document.
fn id_of(value: &Value) -> Result<Value> {
    match value {
        Value::Ext(Ext::Document(d)) => Ok(string(d.id())),
        other => Err(not_an_object("getId", other)),
    }
}

/// `$value?->getId()`.
fn nullsafe_id(value: &Value) -> Result<Option<String>> {
    match value {
        Value::Null => Ok(None),
        other => id_of(other).map(|id| Some(to_text(&id))),
    }
}

/// `$item instanceof Document ? $item->getId() : $item`.
fn id_or_value(value: &Value) -> Value {
    match value {
        Value::Ext(Ext::Document(d)) => string(d.id()),
        other => other.clone(),
    }
}

/// `$item instanceof Document ? $item->getId() : (\is_string($item) ? $item : null)`.
fn operator_id(value: &Value) -> Option<Value> {
    match value {
        Value::Ext(Ext::Document(d)) => Some(string(d.id())),
        Value::Str(_) => Some(value.clone()),
        _ => None,
    }
}

/// `array_filter(array_map(operator_id, $values))`: the ids, falsy ones
/// dropped.
fn operator_ids<'a>(values: impl IntoIterator<Item = &'a Value>) -> Vec<Value> {
    values.into_iter().filter_map(operator_id).filter(Value::truthy).collect()
}

/// `\array_unique($values)`: the first of the values equal as strings.
fn unique(values: Vec<Value>) -> Vec<Value> {
    let mut seen: Vec<String> = Vec::with_capacity(values.len());
    let mut out = Vec::with_capacity(values.len());
    for value in values {
        let text = to_text(&value);
        if !seen.contains(&text) {
            seen.push(text);
            out.push(value);
        }
    }
    out
}

/// `\array_diff($values, $remove)`: the values not equal, as strings, to
/// any of `remove`.
fn diff(values: &[Value], remove: &[Value]) -> Vec<Value> {
    let remove: Vec<String> = remove.iter().map(to_text).collect();
    values.iter().filter(|v| !remove.contains(&to_text(v))).cloned().collect()
}

/// `\array_intersect($values, $keep)`.
fn intersect(values: &[Value], keep: &[Value]) -> Vec<Value> {
    let keep: Vec<String> = keep.iter().map(to_text).collect();
    values.iter().filter(|v| keep.contains(&to_text(v))).cloned().collect()
}

/// `\in_array($needle, $haystack)` (loose).
fn in_array(needle: &Value, haystack: &[Value]) -> bool {
    haystack.iter().any(|v| v.loose_eq(needle))
}

/// `empty($text)` for a string.
fn empty_text(text: &str) -> bool {
    text.is_empty() || text == "0"
}

/// Whether an error is an `\Exception` (not an `\Error`).
fn is_exception(e: &Error) -> bool {
    !matches!(e.kind(), Kind::TypeError | Kind::ValueError | Kind::DivisionByZero | Kind::Error)
}

/// The permissions of a junction document.
fn junction_permissions() -> Value {
    let any = Role::any();
    list([string(Permission::read(&any)), string(Permission::update(&any)), string(Permission::delete(&any))])
}

/// `$relation->getAttributes() != $other->getAttributes()`.
fn attributes_differ(a: &Document, b: &Document) -> bool {
    !Value::Array(a.attributes()).loose_eq(&Value::Array(b.attributes()))
}

/// The ids of the documents of a list value (`array_map(fn ($d) => $d->getId(), $value)`).
fn ids_of_list(value: &Value) -> Result<Vec<Value>> {
    match value {
        Value::Array(a) => a.values().map(id_of).collect(),
        other => Err(Error::new(
            Kind::TypeError,
            format!("array_map(): Argument #2 ($array) must be of type array, {} given", other.debug_type()),
        )),
    }
}

/// The ids of a relationship update's list (`string` or `Document` items).
fn new_ids(value: &Array) -> Result<Vec<Value>> {
    value
        .values()
        .map(|item| match item {
            Value::Str(_) => Ok(item.clone()),
            Value::Ext(Ext::Document(d)) => Ok(string(d.id())),
            _ => Err(Error::relationship("Invalid relationship value. Must be either a document or document ID.")),
        })
        .collect()
}

impl Database {
    // ----- Relationship attributes -----

    /// Creates a relationship attribute `id` (the related collection's id
    /// by default) on `collection` towards `related_collection`, of `kind`
    /// (one-to-one, one-to-many, many-to-one, many-to-many), optionally
    /// two-way with `two_way_key` (the collection's id by default), and its
    /// on-delete rule (`restrict`, `cascade`, `setNull`). Many-to-many
    /// relationships get a junction collection.
    ///
    /// PHP: `Database::createRelationship($collection, $relatedCollection,
    /// $type, $twoWay = false, $id = null, $twoWayKey = null, $onDelete =
    /// Database::RELATION_MUTATE_RESTRICT)`.
    #[allow(clippy::too_many_arguments)]
    pub async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: Option<&str>,
        two_way_key: Option<&str>,
        on_delete: &str,
    ) -> Result<bool> {
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        if collection.is_empty() {
            return Err(Error::not_found("Collection not found"));
        }
        let mut related = self.silent(|| self.get_collection(related_collection), None).await?;
        if related.is_empty() {
            return Err(Error::not_found("Related collection not found"));
        }
        let id = id.unwrap_or(related.id()).to_owned();
        let two_way_key = two_way_key.unwrap_or(collection.id()).to_owned();

        for attribute in documents_at(&collection, "attributes") {
            if attribute.id().eq_ignore_ascii_case(&id) {
                return Err(Error::duplicate("Attribute already exists"));
            }
            let is_relationship = matches!(attribute.get("type"), Some(Value::Str(t)) if t.as_bytes() == Self::VAR_RELATIONSHIP.as_bytes());
            let options = attribute.get("options").cloned().unwrap_or(Value::Null);
            if is_relationship
                && to_text(index(&options, "twoWayKey")).eq_ignore_ascii_case(&two_way_key)
                && index(&options, "relatedCollection").strict_eq(&string(related.id()))
            {
                return Err(Error::duplicate("Related attribute already exists"));
            }
        }

        let options = |related: &str, two_way_key: &str, side: &str| -> Value {
            Value::Array(
                [
                    ("relatedCollection", string(related)),
                    ("relationType", string(kind)),
                    ("twoWay", Value::Bool(two_way)),
                    ("twoWayKey", string(two_way_key)),
                    ("onDelete", string(on_delete)),
                    ("side", string(side)),
                ]
                .into_iter()
                .map(|(k, v)| (ArrayKey::from(k), v))
                .collect(),
            )
        };
        let relationship = new_document(vec![
            ("$id", string(id.as_str())),
            ("key", string(id.as_str())),
            ("type", string(Self::VAR_RELATIONSHIP)),
            ("required", Value::Bool(false)),
            ("default", Value::Null),
            ("options", options(related.id(), &two_way_key, Self::RELATION_SIDE_PARENT)),
        ])?;
        let two_way_relationship = new_document(vec![
            ("$id", string(two_way_key.as_str())),
            ("key", string(two_way_key.as_str())),
            ("type", string(Self::VAR_RELATIONSHIP)),
            ("required", Value::Bool(false)),
            ("default", Value::Null),
            ("options", options(collection.id(), &id, Self::RELATION_SIDE_CHILD)),
        ])?;

        self.check_attribute(&collection, &relationship)?;
        self.check_attribute(&related, &two_way_relationship)?;

        let adapter = self.adapter().clone();
        let mut junction = None;
        if kind == Self::RELATION_MANY_TO_MANY {
            let name = self.junction_collection(&collection, &related, Self::RELATION_SIDE_PARENT);
            let column = |key: &str| {
                new_document(vec![
                    ("$id", string(key)),
                    ("key", string(key)),
                    ("type", string(Self::VAR_STRING)),
                    ("size", Value::Int(Self::LENGTH_KEY)),
                    ("required", Value::Bool(true)),
                    ("signed", Value::Bool(true)),
                    ("array", Value::Bool(false)),
                    ("filters", Value::Array(Array::new())),
                ])
            };
            let attributes = vec![column(&id)?, column(&two_way_key)?];
            let indexes = vec![
                new_document(vec![
                    ("$id", string(format!("_index_{id}"))),
                    ("key", string(format!("index_{id}"))),
                    ("type", string(Self::INDEX_KEY)),
                    ("attributes", list([string(id.as_str())])),
                ])?,
                new_document(vec![
                    ("$id", string(format!("_index_{two_way_key}"))),
                    ("key", string(format!("_index_{two_way_key}"))),
                    ("type", string(Self::INDEX_KEY)),
                    ("attributes", list([string(two_way_key.as_str())])),
                ])?,
            ];
            let created = self
                .silent(|| self.create_collection(&name, attributes.clone(), indexes.clone(), None, true), None)
                .await;
            match created {
                Ok(_) => {}
                // Junction metadata already exists from a prior partial
                // failure: make sure the table exists too.
                Err(e) if e.is_a(Kind::Duplicate) => {
                    match adapter.create_collection(&name, &attributes, &indexes).await {
                        Err(e) if !e.is_a(Kind::Duplicate) => return Err(e),
                        _ => {}
                    }
                }
                Err(e) => return Err(e),
            }
            junction = Some(name);
        }

        let mut created = false;
        match adapter.create_relationship(collection.id(), related.id(), kind, two_way, &id, &two_way_key).await {
            Ok(true) => created = true,
            Ok(false) => {
                if let Some(junction) = &junction
                    && let Err(e) = self.silent(|| self.cleanup_collection(junction, 3), None).await
                {
                    tracing::error!("Failed to cleanup junction collection '{junction}': {}", e.message());
                }
                return Err(Error::database("Failed to create relationship"));
            }
            // The relationship exists only in the physical schema, an orphan
            // of a prior partial failure: go on to the metadata.
            Err(e) if e.is_a(Kind::Duplicate) => {}
            Err(e) => return Err(e),
        }

        collection.set_with("attributes", Value::from(relationship.clone()), SetType::Append);
        related.set_with("attributes", Value::from(two_way_relationship), SetType::Append);

        self.silent(
            || self.persist_relationship(collection, related, kind, two_way, &id, &two_way_key, junction, created),
            None,
        )
        .await?;

        let _ = self.trigger(Self::EVENT_ATTRIBUTE_CREATE, &Value::from(relationship));
        Ok(true)
    }

    /// The metadata and indexes of a new relationship, undoing the schema
    /// change when they fail (`createRelationship`'s silent part).
    #[allow(clippy::too_many_arguments)]
    async fn persist_relationship(
        &self,
        mut collection: Document,
        mut related: Document,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
        junction: Option<String>,
        created: bool,
    ) -> Result<()> {
        let persisted = self
            .with_retries(
                || {
                    self.with_transaction(|| {
                        let (collection, related) = (collection.clone(), related.clone());
                        async move {
                            self.update_document(Self::METADATA, collection.id(), collection.clone()).await?;
                            self.update_document(Self::METADATA, related.id(), related.clone()).await?;
                            Ok(())
                        }
                    })
                },
                3,
                100,
                2.0,
            )
            .await;
        if let Err(mut e) = persisted {
            self.rollback_attribute_metadata(&mut collection, &[id.to_owned()]);
            self.rollback_attribute_metadata(&mut related, &[two_way_key.to_owned()]);
            if created {
                if let Err(cleanup) = self
                    .cleanup_relationship(
                        collection.id(),
                        related.id(),
                        kind,
                        two_way,
                        id,
                        two_way_key,
                        Self::RELATION_SIDE_PARENT,
                        3,
                    )
                    .await
                {
                    tracing::error!("Failed to cleanup relationship '{id}': {}", cleanup.message());
                    e = cleanup;
                }
                if let Some(junction) = &junction
                    && let Err(cleanup) = self.cleanup_collection(junction, 3).await
                {
                    tracing::error!("Failed to cleanup junction collection '{junction}': {}", cleanup.message());
                    e = cleanup;
                }
            }
            return Err(Error::database(format!("Failed to create relationship: {}", e.message())));
        }

        let index_key = format!("_index_{id}");
        let two_way_index_key = format!("_index_{two_way_key}");
        let mut indexes_created: Vec<(String, String)> = Vec::new();
        let indexed: Result<()> = async {
            match kind {
                Self::RELATION_ONE_TO_ONE => {
                    self.create_index(
                        collection.id(),
                        &index_key,
                        Self::INDEX_UNIQUE,
                        vec![id.to_owned()],
                        vec![],
                        vec![],
                        1,
                    )
                    .await?;
                    indexes_created.push((collection.id().to_owned(), index_key.clone()));
                    if two_way {
                        self.create_index(
                            related.id(),
                            &two_way_index_key,
                            Self::INDEX_UNIQUE,
                            vec![two_way_key.to_owned()],
                            vec![],
                            vec![],
                            1,
                        )
                        .await?;
                        indexes_created.push((related.id().to_owned(), two_way_index_key.clone()));
                    }
                }
                Self::RELATION_ONE_TO_MANY => {
                    self.create_index(
                        related.id(),
                        &two_way_index_key,
                        Self::INDEX_KEY,
                        vec![two_way_key.to_owned()],
                        vec![],
                        vec![],
                        1,
                    )
                    .await?;
                    indexes_created.push((related.id().to_owned(), two_way_index_key.clone()));
                }
                Self::RELATION_MANY_TO_ONE => {
                    self.create_index(
                        collection.id(),
                        &index_key,
                        Self::INDEX_KEY,
                        vec![id.to_owned()],
                        vec![],
                        vec![],
                        1,
                    )
                    .await?;
                    indexes_created.push((collection.id().to_owned(), index_key.clone()));
                }
                // The junction collection was created with its indexes.
                Self::RELATION_MANY_TO_MANY => {}
                _ => return Err(Error::relationship("Invalid relationship type.")),
            }
            Ok(())
        }
        .await;
        let Err(e) = indexed else {
            return Ok(());
        };

        for (collection_id, index) in &indexes_created {
            if let Err(cleanup) = self.delete_index(collection_id, index).await {
                tracing::error!("Failed to cleanup index '{index}': {}", cleanup.message());
            }
        }
        let restored = self
            .with_transaction(|| {
                let (mut collection, mut related) = (collection.clone(), related.clone());
                async move {
                    without_attribute(&mut collection, id);
                    self.update_document(Self::METADATA, collection.id(), collection.clone()).await?;
                    without_attribute(&mut related, two_way_key);
                    self.update_document(Self::METADATA, related.id(), related.clone()).await?;
                    Ok(())
                }
            })
            .await;
        if let Err(cleanup) = restored {
            tracing::error!("Failed to cleanup metadata for relationship '{id}': {}", cleanup.message());
        }
        if let Err(cleanup) = self
            .cleanup_relationship(
                collection.id(),
                related.id(),
                kind,
                two_way,
                id,
                two_way_key,
                Self::RELATION_SIDE_PARENT,
                3,
            )
            .await
        {
            tracing::error!("Failed to cleanup relationship '{id}': {}", cleanup.message());
        }
        if let Some(junction) = &junction
            && let Err(cleanup) = self.cleanup_collection(junction, 3).await
        {
            tracing::error!("Failed to cleanup junction collection '{junction}': {}", cleanup.message());
        }
        Err(Error::database(format!("Failed to create relationship indexes: {}", e.message())))
    }

    /// Updates relationship `id` of `collection`: renames its key and
    /// two-way key, changes its two-way-ness or on-delete rule (`None`
    /// keeps each).
    ///
    /// PHP: `Database::updateRelationship($collection, $id, $newKey = null,
    /// $newTwoWayKey = null, $twoWay = null, $onDelete = null)`.
    pub async fn update_relationship(
        &self,
        collection: &str,
        id: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
        two_way: Option<bool>,
        on_delete: Option<&str>,
    ) -> Result<bool> {
        if new_key.is_none() && new_two_way_key.is_none() && two_way.is_none() && on_delete.is_none() {
            return Ok(true);
        }
        let collection = self.get_collection(collection).await?;
        let attributes = documents_at(&collection, "attributes");
        let keys = |attributes: &[Document]| -> Vec<Value> {
            attributes.iter().map(|a| a.raw("key").cloned().unwrap_or(Value::Null)).collect()
        };
        if let Some(new_key) = new_key
            && in_array(&string(new_key), &keys(&attributes))
        {
            return Err(Error::duplicate("Relationship already exists"));
        }
        let Some(position) = attributes.iter().position(|a| a.raw("$id").unwrap_or(&Value::Null).loose_eq(&string(id)))
        else {
            return Err(Error::not_found("Relationship not found"));
        };
        let old_attribute = attributes[position].clone();
        let old_options = old_attribute.raw("options").cloned().unwrap_or(Value::Null);
        let relation = Relation::of(&old_attribute);
        let kind = index(&old_options, "relationType").clone();
        let side = index(&old_options, "side").clone();
        let kind_text = relation.kind.clone();
        let side_text = relation.side.clone();
        let related = self.get_collection(&relation.related_collection).await?;

        let old_two_way_key = relation.two_way_key.clone();
        let altering = new_key.is_some_and(|k| k != id) || new_two_way_key.is_some_and(|k| k != old_two_way_key);

        if let Some(new_two_way_key) = new_two_way_key
            && in_array(&string(new_two_way_key), &keys(&documents_at(&related, "attributes")))
        {
            return Err(Error::duplicate("Related attribute already exists"));
        }

        let actual_new_key = new_key.unwrap_or(id).to_owned();
        let actual_new_two_way_key = new_two_way_key.map(str::to_owned).unwrap_or_else(|| old_two_way_key.clone());
        let actual_two_way = match two_way {
            Some(t) => Value::Bool(t),
            None => index(&old_options, "twoWay").clone(),
        };
        let actual_on_delete = match on_delete {
            Some(o) => string(o),
            None => index(&old_options, "onDelete").clone(),
        };
        let adapter = self.adapter().clone();

        let mut adapter_updated = false;
        if altering {
            let updated = adapter
                .update_relationship(
                    collection.id(),
                    related.id(),
                    &kind_text,
                    actual_two_way.truthy(),
                    id,
                    &old_two_way_key,
                    &side_text,
                    Some(&actual_new_key),
                    Some(&actual_new_two_way_key),
                )
                .await
                .and_then(
                    |updated| {
                        if updated { Ok(()) } else { Err(Error::database("Failed to update relationship")) }
                    },
                );
            match updated {
                Ok(()) => adapter_updated = true,
                Err(e) => {
                    // The rename may have happened already (an orphan of a
                    // prior partial failure): the new column exists.
                    let mut exists = false;
                    if adapter.support_for_schema_attributes() {
                        let filtered = adapter.filter(&actual_new_key)?.to_ascii_lowercase();
                        exists = self
                            .schema_attributes(collection.id())
                            .await?
                            .iter()
                            .any(|a| a.id().to_ascii_lowercase() == filtered);
                    }
                    if !exists {
                        return Err(Error::database(format!("Failed to update relationship '{id}': {}", e.message()))
                            .with_previous(e));
                    }
                    adapter_updated = true;
                }
            }
        }

        let metadata: Result<()> = async {
            let options: Value = Value::Array(
                [
                    ("relatedCollection", string(related.id())),
                    ("relationType", kind.clone()),
                    ("twoWay", actual_two_way.clone()),
                    ("twoWayKey", string(actual_new_two_way_key.as_str())),
                    ("onDelete", actual_on_delete.clone()),
                    ("side", side.clone()),
                ]
                .into_iter()
                .map(|(k, v)| (ArrayKey::from(k), v))
                .collect(),
            );
            let new_key = actual_new_key.clone();
            self.update_attribute_meta(collection.id(), id, move |attribute, _, _| {
                attribute.set("$id", string(new_key.as_str()));
                attribute.set("key", string(new_key.as_str()));
                attribute.set("options", options);
                Ok(())
            })
            .await?;
            let (new_key, new_two_way_key) = (actual_new_key.clone(), actual_new_two_way_key.clone());
            let (two_way, on_delete) = (actual_two_way.clone(), actual_on_delete.clone());
            self.update_attribute_meta(related.id(), &old_two_way_key, move |attribute, _, _| {
                let mut options = match attribute.get("options") {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                options.set(ArrayKey::from("twoWayKey"), string(new_key.as_str()));
                options.set(ArrayKey::from("twoWay"), two_way);
                options.set(ArrayKey::from("onDelete"), on_delete);
                attribute.set("$id", string(new_two_way_key.as_str()));
                attribute.set("key", string(new_two_way_key.as_str()));
                attribute.set("options", Value::Array(options));
                Ok(())
            })
            .await?;
            if kind_text == Self::RELATION_MANY_TO_MANY {
                let junction = self.junction_collection(&collection, &related, &side_text);
                self.rename_junction_attribute(&junction, id, &actual_new_key).await?;
                self.rename_junction_attribute(&junction, &old_two_way_key, &actual_new_two_way_key).await?;
                self.with_retries(|| async { self.purge_cached_collection(&junction).await.map(|_| ()) }, 3, 100, 2.0)
                    .await?;
            }
            Ok(())
        }
        .await;
        if let Err(e) = metadata {
            if adapter_updated {
                let _ = adapter
                    .update_relationship(
                        collection.id(),
                        related.id(),
                        &kind_text,
                        actual_two_way.truthy(),
                        &actual_new_key,
                        &actual_new_two_way_key,
                        &side_text,
                        Some(id),
                        Some(&old_two_way_key),
                    )
                    .await;
            }
            return Err(e);
        }

        // (collection, renamed from, renamed to), to reverse on failure.
        let mut renames: Vec<(String, String, String)> = Vec::new();
        let renamed: Result<()> = async {
            let mut planned = Vec::new();
            match kind_text.as_str() {
                Self::RELATION_ONE_TO_ONE => {
                    if id != actual_new_key {
                        planned.push((collection.id().to_owned(), id.to_owned(), actual_new_key.clone()));
                    }
                    if actual_two_way.truthy() && old_two_way_key != actual_new_two_way_key {
                        planned.push((
                            related.id().to_owned(),
                            old_two_way_key.clone(),
                            actual_new_two_way_key.clone(),
                        ));
                    }
                }
                Self::RELATION_ONE_TO_MANY | Self::RELATION_MANY_TO_ONE => {
                    let on_related =
                        (kind_text == Self::RELATION_ONE_TO_MANY) == (side_text == Self::RELATION_SIDE_PARENT);
                    if on_related {
                        if old_two_way_key != actual_new_two_way_key {
                            planned.push((
                                related.id().to_owned(),
                                old_two_way_key.clone(),
                                actual_new_two_way_key.clone(),
                            ));
                        }
                    } else if id != actual_new_key {
                        planned.push((collection.id().to_owned(), id.to_owned(), actual_new_key.clone()));
                    }
                }
                Self::RELATION_MANY_TO_MANY => {
                    let junction = self.junction_collection(&collection, &related, &side_text);
                    if id != actual_new_key {
                        planned.push((junction.clone(), id.to_owned(), actual_new_key.clone()));
                    }
                    if old_two_way_key != actual_new_two_way_key {
                        planned.push((junction, old_two_way_key.clone(), actual_new_two_way_key.clone()));
                    }
                }
                _ => return Err(Error::relationship("Invalid relationship type.")),
            }
            for (collection, from, to) in planned {
                self.rename_relationship_index(&collection, &from, &to).await?;
                renames.push((collection, to, from));
            }
            Ok(())
        }
        .await;

        if let Err(e) = renamed {
            for (collection, from, to) in renames.iter().rev() {
                let _ = self.rename_relationship_index(collection, from, to).await;
            }
            let restored_options = old_options.clone();
            let _ = self
                .update_attribute_meta(collection.id(), &actual_new_key, move |attribute, _, _| {
                    attribute.set("$id", string(id));
                    attribute.set("key", string(id));
                    attribute.set("options", restored_options);
                    Ok(())
                })
                .await;
            let (old_key, old_two_way, old_on_delete) = (
                old_two_way_key.clone(),
                index(&old_options, "twoWay").clone(),
                index(&old_options, "onDelete").clone(),
            );
            let _ = self
                .update_attribute_meta(related.id(), &actual_new_two_way_key, move |attribute, _, _| {
                    let mut options = match attribute.get("options") {
                        Some(Value::Array(a)) => a.clone(),
                        _ => Array::new(),
                    };
                    options.set(ArrayKey::from("twoWayKey"), string(id));
                    options.set(ArrayKey::from("twoWay"), old_two_way);
                    options.set(ArrayKey::from("onDelete"), old_on_delete);
                    attribute.set("$id", string(old_key.as_str()));
                    attribute.set("key", string(old_key.as_str()));
                    attribute.set("options", Value::Array(options));
                    Ok(())
                })
                .await;
            if kind_text == Self::RELATION_MANY_TO_MANY {
                let junction = self.junction_collection(&collection, &related, &side_text);
                let _ = self.rename_junction_attribute(&junction, &actual_new_key, id).await;
                let _ = self.rename_junction_attribute(&junction, &actual_new_two_way_key, &old_two_way_key).await;
            }
            if adapter_updated {
                let _ = adapter
                    .update_relationship(
                        collection.id(),
                        related.id(),
                        &kind_text,
                        index(&old_options, "twoWay").truthy(),
                        &actual_new_key,
                        &actual_new_two_way_key,
                        &side_text,
                        Some(id),
                        Some(&old_two_way_key),
                    )
                    .await;
            }
            return Err(Error::database(format!("Failed to update relationship indexes for '{id}': {}", e.message()))
                .with_previous(e));
        }

        self.with_retries(|| async { self.purge_cached_collection(collection.id()).await.map(|_| ()) }, 3, 100, 2.0)
            .await?;
        self.with_retries(|| async { self.purge_cached_collection(related.id()).await.map(|_| ()) }, 3, 100, 2.0)
            .await?;
        Ok(true)
    }

    /// Renames attribute `from` of a junction collection to `to`
    /// (metadata only).
    async fn rename_junction_attribute(&self, junction: &str, from: &str, to: &str) -> Result<()> {
        let to = to.to_owned();
        self.update_attribute_meta(junction, from, move |attribute, _, _| {
            attribute.set("$id", string(to.as_str()));
            attribute.set("key", string(to.as_str()));
            Ok(())
        })
        .await
        .map(|_| ())
    }

    /// Renames the index `_index_{from}` of `collection` to `_index_{to}`,
    /// now on attribute `to` (`updateRelationship`'s `$renameIndex`).
    async fn rename_relationship_index(&self, collection: &str, from: &str, to: &str) -> Result<()> {
        let attribute = to.to_owned();
        self.update_index_meta(collection, &format!("_index_{from}"), move |index, _, _| {
            index.set("attributes", list([string(attribute.as_str())]));
            Ok(())
        })
        .await?;
        let (old, new) = (format!("_index_{from}"), format!("_index_{to}"));
        self.silent(|| self.rename_index(collection, &old, &new), None).await?;
        Ok(())
    }

    /// Deletes relationship `id` of `collection` (both sides, and the
    /// junction collection).
    ///
    /// PHP: `Database::deleteRelationship()`.
    pub async fn delete_relationship(&self, collection: &str, id: &str) -> Result<bool> {
        let mut collection = self.silent(|| self.get_collection(collection), None).await?;
        let mut attributes = documents_at(&collection, "attributes");
        let Some(position) = attributes.iter().position(|a| a.raw("$id").is_some_and(|v| v.strict_eq(&string(id))))
        else {
            return Err(Error::not_found("Relationship not found"));
        };
        let relationship = attributes.remove(position);
        set_documents(&mut collection, "attributes", attributes);

        let relation = Relation::of(&relationship);
        let (kind, two_way, two_way_key, side) =
            (relation.kind.as_str(), relation.two_way, relation.two_way_key.as_str(), relation.side.as_str());

        let mut related = self.silent(|| self.get_collection(&relation.related_collection), None).await?;
        let mut related_attributes = documents_at(&related, "attributes");
        if let Some(position) =
            related_attributes.iter().position(|a| a.raw("$id").is_some_and(|v| v.strict_eq(&string(two_way_key))))
        {
            related_attributes.remove(position);
        }
        set_documents(&mut related, "attributes", related_attributes);

        let collection_attributes = collection.get("attributes").cloned().unwrap_or(Value::Null);
        let related_collection_attributes = related.get("attributes").cloned().unwrap_or(Value::Null);

        // Indexes go before the columns they reference.
        let (deleted_indexes, deleted_junction) =
            self.silent(|| self.delete_relationship_indexes(&collection, &related, &relation, id), None).await?;

        let mut collection = self.silent(|| self.get_collection(collection.id()), None).await?;
        let mut related = self.silent(|| self.get_collection(related.id()), None).await?;
        collection.set("attributes", collection_attributes);
        related.set("attributes", related_collection_attributes);

        let adapter = self.adapter().clone();
        let mut should_rollback = false;
        match adapter.delete_relationship(collection.id(), related.id(), kind, two_way, id, two_way_key, side).await {
            Ok(true) => should_rollback = true,
            Ok(false) => return Err(Error::database("Failed to delete relationship")),
            // Already absent from the schema.
            Err(e) if e.is_a(Kind::NotFound) => {}
            Err(e) => return Err(e),
        }

        let persisted = self
            .with_retries(
                || {
                    self.silent(
                        || {
                            self.with_transaction(|| {
                                let (collection, related) = (collection.clone(), related.clone());
                                async move {
                                    self.update_document(Self::METADATA, collection.id(), collection.clone()).await?;
                                    self.update_document(Self::METADATA, related.id(), related.clone()).await?;
                                    Ok(())
                                }
                            })
                        },
                        None,
                    )
                },
                3,
                100,
                2.0,
            )
            .await;
        if let Err(e) = persisted {
            if should_rollback {
                let _ =
                    adapter.create_relationship(collection.id(), related.id(), kind, two_way, id, two_way_key).await;
            }
            for (collection_id, key, kind, attribute) in deleted_indexes {
                let _ = self.create_index(&collection_id, &key, kind, vec![attribute], vec![], vec![], 1).await;
            }
            if let Some(junction) = deleted_junction.filter(|j| !j.is_empty()) {
                let _ = self.silent(|| self.create_document(Self::METADATA, junction), None).await;
            }
            return Err(Error::database(format!(
                "Failed to persist metadata after retries for relationship deletion '{id}': {}",
                e.message()
            ))
            .with_previous(e));
        }

        self.with_retries(|| async { self.purge_cached_collection(collection.id()).await.map(|_| ()) }, 3, 100, 2.0)
            .await?;
        self.with_retries(|| async { self.purge_cached_collection(related.id()).await.map(|_| ()) }, 3, 100, 2.0)
            .await?;

        let _ = self.trigger(Self::EVENT_ATTRIBUTE_DELETE, &Value::from(relationship));
        Ok(true)
    }

    /// Deletes the indexes of a relationship (or, for many-to-many, its
    /// junction collection's metadata); returns the indexes deleted
    /// (collection, key, type, attribute) and the junction's metadata, to
    /// restore them (`deleteRelationship`'s silent part).
    async fn delete_relationship_indexes(
        &self,
        collection: &Document,
        related: &Document,
        relation: &Relation,
        id: &str,
    ) -> Result<(Vec<(String, String, &'static str, String)>, Option<Document>)> {
        let (two_way, two_way_key, side) = (relation.two_way, relation.two_way_key.as_str(), relation.side.as_str());
        let parent = side == Self::RELATION_SIDE_PARENT;
        let own = (collection.id().to_owned(), format!("_index_{id}"), id.to_owned());
        let other = (related.id().to_owned(), format!("_index_{two_way_key}"), two_way_key.to_owned());
        let mut deletions: Vec<((String, String, String), &'static str)> = Vec::new();
        let mut deleted_junction = None;
        match relation.kind.as_str() {
            Self::RELATION_ONE_TO_ONE => {
                if parent {
                    deletions.push((own.clone(), Self::INDEX_UNIQUE));
                    if two_way {
                        deletions.push((other.clone(), Self::INDEX_UNIQUE));
                    }
                }
                if side == Self::RELATION_SIDE_CHILD {
                    deletions.push((other, Self::INDEX_UNIQUE));
                    if two_way {
                        deletions.push((own, Self::INDEX_UNIQUE));
                    }
                }
            }
            Self::RELATION_ONE_TO_MANY => deletions.push((if parent { other } else { own }, Self::INDEX_KEY)),
            Self::RELATION_MANY_TO_ONE => deletions.push((if parent { own } else { other }, Self::INDEX_KEY)),
            Self::RELATION_MANY_TO_MANY => {
                let junction = self.junction_collection(collection, related, side);
                deleted_junction =
                    Some(self.silent(|| self.get_document(Self::METADATA, &junction, vec![], false), None).await?);
                self.delete_document(Self::METADATA, &junction).await?;
            }
            _ => return Err(Error::relationship("Invalid relationship type.")),
        }
        let mut deleted = Vec::new();
        for ((collection_id, key, attribute), kind) in deletions {
            self.delete_index(&collection_id, &key).await?;
            deleted.push((collection_id, key, kind, attribute));
        }
        Ok((deleted, deleted_junction))
    }

    /// Drops a relationship's columns (the rollback of a failed
    /// `create_relationship`), with retries.
    ///
    /// PHP: `Database::cleanupRelationship()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn cleanup_relationship(
        &self,
        collection_id: &str,
        related_collection_id: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        max_attempts: u32,
    ) -> Result<()> {
        let adapter = self.adapter();
        self.cleanup(
            || async move {
                adapter
                    .delete_relationship(collection_id, related_collection_id, kind, two_way, key, two_way_key, side)
                    .await
                    .map(|_| ())
            },
            "relationship",
            key,
            max_attempts,
        )
        .await
    }

    // ----- Populating (reads) -----

    /// `documents` (of `collection`) with their relationship attributes
    /// populated breadth first, down to [`Database::RELATION_MAX_DEPTH`]
    /// from `depth`, honouring nested `selects`.
    ///
    /// PHP: `Database::populateDocumentsRelationships($documents,
    /// $collection, $relationshipFetchDepth = 0, $selects = [])` (private).
    /// Called by `get_document`, `find`, `create_document(s)`,
    /// `update_document`, `upsert_documents*`.
    pub(crate) async fn populate_documents_relationships(
        &self,
        documents: Vec<Document>,
        collection: &Document,
        depth: i64,
        selects: &Selects,
    ) -> Result<Vec<Document>> {
        // No nested population while related documents are fetched.
        self.state().in_batch_relationship_population = true;
        let _reset = Finally::new(|| self.state().in_batch_relationship_population = false);

        let count = documents.len();
        let mut tree = Population { docs: documents, links: Vec::new() };
        let mut queue = vec![Level {
            docs: (0..count).collect(),
            collection: collection.clone(),
            selects: selects.clone(),
            skip_key: None,
            explicit: !selects.is_empty(),
        }];
        let mut current = depth;

        while !queue.is_empty() && current < Self::RELATION_MAX_DEPTH {
            let mut next = Vec::new();
            for level in queue {
                if level.docs.is_empty() {
                    continue;
                }
                let selects = level.selects;
                let relationships: Vec<Document> = relationship_attributes(&level.collection)
                    .into_iter()
                    .filter(|r| {
                        let key = r.raw("key").cloned().unwrap_or(Value::Null);
                        if level.skip_key.as_ref().is_some_and(|skip| key.strict_eq(&string(skip.as_str()))) {
                            return false;
                        }
                        !level.explicit || selects.iter().any(|(k, _)| *k == to_text(&key))
                    })
                    .collect();

                for mut relationship in relationships {
                    let relation = Relation::of(&relationship);
                    let key = relation.key.clone();
                    relationship.set("collection", string(level.collection.id()));

                    if current + 1 >= Self::RELATION_MAX_DEPTH {
                        for &doc in &level.docs {
                            tree.docs[doc].remove(&key);
                        }
                        continue;
                    }

                    let position = selects.iter().position(|(k, _)| *k == key);
                    let queries = position.map(|p| selects[p].1.clone()).unwrap_or_default();
                    let related = self
                        .populate_single_relationship_batch(&mut tree, &level.docs, &relationship, &queries)
                        .await?;

                    let nested = position.is_some();
                    if !related.is_empty() && (nested || !level.explicit) {
                        let related_collection =
                            self.silent(|| self.get_collection(&relation.related_collection), None).await?;
                        if !related_collection.is_empty() {
                            let mut relationship_queries = if nested { queries } else { Vec::new() };
                            let next_selects = self.process_relationship_queries(
                                &relationship_attributes(&related_collection),
                                &mut relationship_queries,
                            )?;
                            next.push(Level {
                                docs: related.clone(),
                                collection: related_collection,
                                selects: next_selects,
                                skip_key: relation.two_way.then(|| relation.two_way_key.clone()),
                                explicit: level.explicit,
                            });
                        }
                    }

                    // Back-references are always removed: no cycles.
                    if relation.two_way {
                        for &doc in &related {
                            tree.docs[doc].remove(&relation.two_way_key);
                        }
                    }
                }
            }
            queue = next;
            current += 1;
        }

        Ok(tree.resolve(count))
    }

    /// Populates one relationship of `docs` by its kind; returns the
    /// related documents fetched (for the next level).
    ///
    /// PHP: `Database::populateSingleRelationshipBatch()` (private).
    async fn populate_single_relationship_batch(
        &self,
        tree: &mut Population,
        docs: &[usize],
        relationship: &Document,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let relation = Relation::of(relationship);
        match relation.kind.as_str() {
            Self::RELATION_ONE_TO_ONE => {
                self.populate_one_to_one_relationships_batch(tree, docs, &relation, queries).await
            }
            Self::RELATION_ONE_TO_MANY => {
                if relation.side == Self::RELATION_SIDE_CHILD {
                    if !relation.two_way {
                        for &doc in docs {
                            tree.docs[doc].remove(&relation.key);
                        }
                        return Ok(Vec::new());
                    }
                    return self.populate_one_to_one_relationships_batch(tree, docs, &relation, queries).await;
                }
                self.populate_back_referenced_batch(tree, docs, &relation, queries).await
            }
            Self::RELATION_MANY_TO_ONE => {
                if relation.side == Self::RELATION_SIDE_PARENT {
                    return self.populate_one_to_one_relationships_batch(tree, docs, &relation, queries).await;
                }
                if !relation.two_way {
                    for &doc in docs {
                        tree.docs[doc].remove(&relation.key);
                    }
                    return Ok(Vec::new());
                }
                self.populate_back_referenced_batch(tree, docs, &relation, queries).await
            }
            Self::RELATION_MANY_TO_MANY => {
                let collection = to_text(relationship.get("collection").unwrap_or(&Value::Null));
                self.populate_many_to_many_relationships_batch(tree, docs, &relation, &collection, queries).await
            }
            _ => Ok(Vec::new()),
        }
    }

    /// `find()` on a related collection with `queries` appended, chunk by
    /// chunk of `ids` (`Query::equal($attribute, $chunk)`).
    async fn find_related(
        &self,
        related: &Document,
        attribute: &str,
        ids: &[Value],
        queries: &[Query],
    ) -> Result<Vec<Document>> {
        let mut found = Vec::new();
        let chunk_size = self.max_query_values().max(1) as usize;
        for chunk in ids.chunks(chunk_size) {
            let mut all = vec![Query::equal(attribute, chunk.to_vec()), Query::limit(i64::MAX)];
            all.extend(queries.iter().cloned());
            found.extend(self.find(related.id(), all, Self::PERMISSION_READ).await?);
        }
        Ok(found)
    }

    /// One-to-one population (also the single side of one-to-many and
    /// many-to-one).
    ///
    /// PHP: `Database::populateOneToOneRelationshipsBatch()` (private).
    async fn populate_one_to_one_relationships_batch(
        &self,
        tree: &mut Population,
        docs: &[usize],
        relation: &Relation,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let key = relation.key.as_str();
        let related = self.get_collection(&relation.related_collection).await?;

        let mut related_ids = Vec::new();
        let mut by_related: Vec<(ArrayKey, Vec<usize>)> = Vec::new();
        for &doc in docs {
            let Some(value) = tree.docs[doc].get(key) else { continue };
            // Already populated.
            if as_document(value).is_some() {
                continue;
            }
            let value = value.clone();
            let k = array_key(&value);
            related_ids.push(value);
            match by_related.iter_mut().find(|(id, _)| *id == k) {
                Some((_, holders)) => holders.push(doc),
                None => by_related.push((k, vec![doc])),
            }
        }
        if related_ids.is_empty() {
            return Ok(Vec::new());
        }

        let found = self.find_related(&related, "$id", &unique(related_ids), queries).await?;
        let slots = tree.add(found);
        let by_id: HashMap<ArrayKey, usize> =
            slots.iter().map(|&slot| (ArrayKey::normalize(tree.docs[slot].id().into()), slot)).collect();

        for (id, holders) in by_related {
            match by_id.get(&id) {
                Some(&slot) => {
                    for doc in holders {
                        tree.link(doc, key, Link::One(slot));
                    }
                }
                None => {
                    for doc in holders {
                        tree.docs[doc].set(key, Value::from(Document::empty()));
                    }
                }
            }
        }
        Ok(slots)
    }

    /// The many side of one-to-many (parent) and many-to-one (child): the
    /// related documents whose `twoWayKey` holds each document's id.
    ///
    /// PHP: `Database::populateOneToManyRelationshipsBatch()` and
    /// `populateManyToOneRelationshipsBatch()` (private).
    async fn populate_back_referenced_batch(
        &self,
        tree: &mut Population,
        docs: &[usize],
        relation: &Relation,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let related = self.get_collection(&relation.related_collection).await?;
        let ids = unique(docs.iter().map(|&d| string(tree.docs[d].id())).collect());
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        // Fetch every attribute (to group by the back-reference), then
        // apply the selections.
        let (selects, others): (Vec<Query>, Vec<Query>) =
            queries.iter().cloned().partition(|q| *q.method() == Method::Select);
        let found = self.find_related(&related, &relation.two_way_key, &ids, &others).await?;
        let slots = tree.add(found);

        let mut by_holder: Vec<(ArrayKey, Vec<usize>)> = Vec::new();
        for &slot in &slots {
            let Some(holder) = tree.docs[slot].get(&relation.two_way_key) else { continue };
            let k = array_key(&id_or_value(holder));
            match by_holder.iter_mut().find(|(id, _)| *id == k) {
                Some((_, held)) => held.push(slot),
                None => by_holder.push((k, vec![slot])),
            }
        }

        if let (Some(&first), Some(&last)) = (slots.first(), slots.last()) {
            self.apply_select_filters_to_documents(&mut tree.docs[first..=last], &selects);
        }

        for &doc in docs {
            let k = ArrayKey::normalize(tree.docs[doc].id().into());
            let held = by_holder.iter().find(|(id, _)| *id == k).map(|(_, held)| held.clone()).unwrap_or_default();
            tree.link(doc, &relation.key, Link::Many(held));
        }
        Ok(slots)
    }

    /// Many-to-many population (through the junction collection).
    ///
    /// PHP: `Database::populateManyToManyRelationshipsBatch()` (private).
    async fn populate_many_to_many_relationships_batch(
        &self,
        tree: &mut Population,
        docs: &[usize],
        relation: &Relation,
        collection: &str,
        queries: &[Query],
    ) -> Result<Vec<usize>> {
        let related = self.get_collection(&relation.related_collection).await?;
        let collection = self.get_collection(collection).await?;
        if !relation.two_way && relation.side == Self::RELATION_SIDE_CHILD {
            return Ok(Vec::new());
        }
        let ids = unique(docs.iter().map(|&d| string(tree.docs[d].id())).collect());
        if ids.is_empty() {
            return Ok(Vec::new());
        }
        let junction = self.junction_collection(&collection, &related, &relation.side);

        let mut junctions = Vec::new();
        for chunk in ids.chunks(self.max_query_values().max(1) as usize) {
            let queries = vec![Query::equal(&relation.two_way_key, chunk.to_vec()), Query::limit(i64::MAX)];
            junctions.extend(self.skip_relationships(|| self.find(&junction, queries, Self::PERMISSION_READ)).await?);
        }

        let mut related_ids = Vec::new();
        let mut by_document: Vec<(ArrayKey, Vec<Value>)> = Vec::new();
        for junction in &junctions {
            let (Some(document_id), Some(related_id)) =
                (junction.get(&relation.two_way_key), junction.get(&relation.key))
            else {
                continue;
            };
            let k = array_key(document_id);
            match by_document.iter_mut().find(|(id, _)| *id == k) {
                Some((_, held)) => held.push(related_id.clone()),
                None => by_document.push((k, vec![related_id.clone()])),
            }
            related_ids.push(related_id.clone());
        }

        let mut slots = Vec::new();
        let mut held_by: HashMap<ArrayKey, Vec<usize>> = HashMap::new();
        if !related_ids.is_empty() {
            let found = self.find_related(&related, "$id", &unique(related_ids), queries).await?;
            slots = tree.add(found);
            let by_id: HashMap<ArrayKey, usize> =
                slots.iter().map(|&slot| (ArrayKey::normalize(tree.docs[slot].id().into()), slot)).collect();
            for (document_id, related_ids) in by_document {
                let held = related_ids.iter().filter_map(|id| by_id.get(&array_key(id)).copied()).collect();
                held_by.insert(document_id, held);
            }
        }

        for &doc in docs {
            let k = ArrayKey::normalize(tree.docs[doc].id().into());
            tree.link(doc, &relation.key, Link::Many(held_by.get(&k).cloned().unwrap_or_default()));
        }
        Ok(slots)
    }

    // ----- Writing -----

    /// Pushes `collection_id` on the relationship write stack until the
    /// returned guard drops (PHP's `try { ... } finally { array_pop(...) }`).
    fn write_frame(&self, collection_id: &str) -> Finally<impl FnOnce() + '_> {
        self.state().relationship_write_stack.push(collection_id.to_owned());
        Finally::new(move || {
            self.state().relationship_write_stack.pop();
        })
    }

    /// Whether the write stack is deep enough that a relationship towards
    /// `related_id` is no longer written.
    fn write_too_deep(&self, related_id: &str) -> bool {
        let state = self.state();
        let count = state.relationship_write_stack.len() as i64;
        count >= Self::RELATION_MAX_DEPTH - 1
            && state.relationship_write_stack.last().is_some_and(|last| last != related_id)
    }

    /// Creates or links the related documents `document` carries in its
    /// relationship attributes, and returns `document` with those
    /// attributes reduced to what its own table stores.
    ///
    /// PHP: `Database::createDocumentRelationships()` (private). Called by
    /// `create_document(s)`, `upsert_documents*`.
    pub(crate) async fn create_document_relationships(
        &self,
        collection: &Document,
        mut document: Document,
    ) -> Result<Document> {
        // The stack depth is read once, before any relationship is written.
        let deep = self.state().relationship_write_stack.len() as i64 >= Self::RELATION_MAX_DEPTH - 1;
        for relationship in relationship_attributes(collection) {
            let relation = Relation::of(&relationship);
            let key = relation.key.as_str();
            let value = document.get(key).cloned().unwrap_or(Value::Null);
            let related = self.get_collection(&relation.related_collection).await?;
            let (kind, two_way, side) = (relation.kind.as_str(), relation.two_way, relation.side.as_str());

            if deep && self.write_too_deep(related.id()) {
                document.remove(key);
                continue;
            }

            let _frame = self.write_frame(collection.id());
            let invalid = || {
                Error::relationship(
                    "Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.",
                )
            };
            let child_one_to_one = || {
                Error::relationship(
                    "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.",
                )
            };
            let many = (kind == Self::RELATION_ONE_TO_MANY && side == Self::RELATION_SIDE_PARENT)
                || (kind == Self::RELATION_MANY_TO_ONE && side == Self::RELATION_SIDE_CHILD)
                || kind == Self::RELATION_MANY_TO_MANY;
            match &value {
                Value::Array(items) => {
                    if kind == Self::RELATION_ONE_TO_ONE && !two_way && side == Self::RELATION_SIDE_CHILD {
                        return Err(child_one_to_one());
                    }
                    if !many {
                        return Err(Error::relationship(
                            "Invalid relationship value. Must be either a document ID or a document, array given.",
                        ));
                    }
                    for item in items.values() {
                        match item {
                            Value::Ext(Ext::Document(d)) => {
                                self.relate_documents(collection, &related, &relation, &document, (**d).clone())
                                    .await?;
                            }
                            Value::Str(_) => {
                                self.relate_documents_by_id(
                                    collection,
                                    &related,
                                    &relation,
                                    document.id(),
                                    &to_text(item),
                                )
                                .await?;
                            }
                            _ => return Err(invalid()),
                        }
                    }
                    document.remove(key);
                }
                Value::Ext(_) | Value::Object(_) => {
                    let Some(d) = as_document(&value) else {
                        return Err(invalid());
                    };
                    if kind == Self::RELATION_ONE_TO_ONE && !two_way && side == Self::RELATION_SIDE_CHILD {
                        return Err(child_one_to_one());
                    }
                    if many {
                        return Err(Error::relationship(
                            "Invalid relationship value. Must be either an array of documents or document IDs, document given.",
                        ));
                    }
                    let related_id =
                        self.relate_documents(collection, &related, &relation, &document, d.clone()).await?;
                    document.set(key, string(related_id));
                }
                Value::Str(_) => {
                    if kind == Self::RELATION_ONE_TO_ONE && !two_way && side == Self::RELATION_SIDE_CHILD {
                        return Err(child_one_to_one());
                    }
                    if many {
                        return Err(Error::relationship(
                            "Invalid relationship value. Must be either an array of documents or document IDs, document ID given.",
                        ));
                    }
                    self.relate_documents_by_id(collection, &related, &relation, document.id(), &to_text(&value))
                        .await?;
                }
                Value::Null => {
                    let kept = relation.holds_key()
                        || (kind == Self::RELATION_ONE_TO_ONE && side == Self::RELATION_SIDE_PARENT)
                        || (kind == Self::RELATION_ONE_TO_ONE && side == Self::RELATION_SIDE_CHILD && two_way);
                    if !kept {
                        document.remove(key);
                    }
                }
                _ => return Err(invalid()),
            }
        }
        Ok(document)
    }

    /// Creates or updates `relation` and links it to `document` through
    /// the relationship; returns the related document's id.
    ///
    /// PHP: `Database::relateDocuments()` (private).
    async fn relate_documents(
        &self,
        collection: &Document,
        related_collection: &Document,
        relationship: &Relation,
        document: &Document,
        mut relation: Document,
    ) -> Result<String> {
        let Relation { key, kind, two_way, two_way_key, side, .. } = relationship;
        let sets_back_reference = match kind.as_str() {
            Self::RELATION_ONE_TO_ONE => *two_way,
            Self::RELATION_ONE_TO_MANY => side == Self::RELATION_SIDE_PARENT,
            Self::RELATION_MANY_TO_ONE => side == Self::RELATION_SIDE_CHILD,
            _ => false,
        };
        if sets_back_reference {
            relation.set(two_way_key.as_str(), string(document.id()));
        }

        let mut related = self.get_document(related_collection.id(), relation.id(), vec![], false).await?;
        if related.is_empty() {
            // Created, inheriting the document's permissions when it has none.
            if relation.get("$permissions").is_none() {
                relation.set("$permissions", list(document.permissions().into_iter().map(string)));
            }
            related = self.create_document(related_collection.id(), relation).await?;
        } else if attributes_differ(&related, &relation) {
            for (attribute, value) in relation.attributes() {
                related.as_array_mut().set(attribute, value);
            }
            let id = related.id().to_owned();
            related = self.update_document(related_collection.id(), &id, related).await?;
        }

        if kind == Self::RELATION_MANY_TO_MANY {
            let junction = self.junction_collection(collection, related_collection, side);
            let link = new_document(vec![
                (key.as_str(), string(related.id())),
                (two_way_key.as_str(), string(document.id())),
                ("$permissions", junction_permissions()),
            ])?;
            self.create_document(&junction, link).await?;
        }
        Ok(related.id().to_owned())
    }

    /// Links document `document_id` to existing document `relation_id`
    /// through the relationship.
    ///
    /// PHP: `Database::relateDocumentsById()` (private).
    async fn relate_documents_by_id(
        &self,
        collection: &Document,
        related_collection: &Document,
        relationship: &Relation,
        document_id: &str,
        relation_id: &str,
    ) -> Result<()> {
        let Relation { key, kind, two_way, two_way_key, side, .. } = relationship;
        // Empty when not readable.
        let mut related =
            self.skip_relationships(|| self.get_document(related_collection.id(), relation_id, vec![], false)).await?;
        if related.is_empty() && self.state().check_relationships_exist {
            return Ok(());
        }
        let sets_back_reference = match kind.as_str() {
            Self::RELATION_ONE_TO_ONE => *two_way,
            Self::RELATION_ONE_TO_MANY => side == Self::RELATION_SIDE_PARENT,
            Self::RELATION_MANY_TO_ONE => side == Self::RELATION_SIDE_CHILD,
            _ => false,
        };
        if sets_back_reference {
            related.set(two_way_key.as_str(), string(document_id));
            self.skip_relationships(|| self.update_document(related_collection.id(), relation_id, related)).await?;
        } else if kind == Self::RELATION_MANY_TO_MANY {
            self.purge_cached_document(related_collection.id(), Some(relation_id)).await?;
            let junction = self.junction_collection(collection, related_collection, side);
            let link = new_document(vec![
                (key.as_str(), string(relation_id)),
                (two_way_key.as_str(), string(document_id)),
                ("$permissions", junction_permissions()),
            ])?;
            self.skip_relationships(|| self.create_document(&junction, link)).await?;
        }
        Ok(())
    }

    /// Applies the changes of `document`'s relationship attributes against
    /// `old` (creating, linking, unlinking related documents, applying
    /// relationship operators), and returns `document` with those
    /// attributes reduced to what its own table stores.
    ///
    /// PHP: `Database::updateDocumentRelationships()` (private). Called by
    /// `update_document`, `upsert_documents*`.
    pub(crate) async fn update_document_relationships(
        &self,
        collection: &Document,
        old: &Document,
        mut document: Document,
    ) -> Result<Document> {
        let deep = self.state().relationship_write_stack.len() as i64 >= Self::RELATION_MAX_DEPTH - 1;
        for relationship in relationship_attributes(collection) {
            let relation = Relation::of(&relationship);
            let key = relation.key.as_str();
            let mut value = document.get(key).cloned().unwrap_or(Value::Null);
            let old_value = old.get(key).cloned().unwrap_or(Value::Null);
            let related = self.get_collection(&relation.related_collection).await?;
            let (kind, two_way, two_way_key, side) =
                (relation.kind.as_str(), relation.two_way, relation.two_way_key.as_str(), relation.side.as_str());

            if let Value::Ext(Ext::Operator(operator)) = &value
                && operator.is_array_operation()
            {
                let existing: Vec<Value> = match &old_value {
                    Value::Array(a) => a.values().map(id_or_value).collect(),
                    _ => Vec::new(),
                };
                value = list(self.apply_relationship_operator(operator, &existing)?);
                document.set(key, value.clone());
            }

            if old_value.loose_eq(&value) {
                let single = kind == Self::RELATION_ONE_TO_ONE
                    || (kind == Self::RELATION_MANY_TO_ONE && side == Self::RELATION_SIDE_PARENT);
                if single && let Some(d) = as_document(&value) {
                    document.set(key, string(d.id()));
                    continue;
                }
                document.remove(key);
                continue;
            }

            if deep && self.write_too_deep(related.id()) {
                document.remove(key);
                continue;
            }

            let _frame = self.write_frame(collection.id());
            match kind {
                Self::RELATION_ONE_TO_ONE => {
                    if !two_way {
                        if side == Self::RELATION_SIDE_CHILD {
                            return Err(Error::relationship(
                                "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.",
                            ));
                        }
                        match &value {
                            Value::Str(_) => {
                                let value_id = to_text(&value);
                                let found = self
                                    .skip_relationships(|| {
                                        self.get_document(related.id(), &value_id, vec![Query::select(["$id"])], false)
                                    })
                                    .await?;
                                // No such related document: the key is unset.
                                if found.is_empty() {
                                    document.set(key, Value::Null);
                                }
                            }
                            Value::Ext(Ext::Document(d)) => {
                                let relation = relation_without_two_way(&relation);
                                let id = self
                                    .relate_documents(collection, &related, &relation, &document, (**d).clone())
                                    .await?;
                                document.set(key, string(id));
                            }
                            Value::Array(_) => {
                                return Err(Error::relationship(
                                    "Invalid relationship value. Must be either a document, document ID or null. Array given.",
                                ));
                            }
                            _ => {}
                        }
                        continue;
                    }

                    match &value {
                        Value::Str(_) => {
                            let value_id = to_text(&value);
                            let found = self
                                .skip_relationships(|| {
                                    self.get_document(related.id(), &value_id, vec![Query::select(["$id"])], false)
                                })
                                .await?;
                            if found.is_empty() {
                                document.set(key, Value::Null);
                                continue;
                            }
                            if nullsafe_id(&old_value)?.as_deref() != Some(value_id.as_str())
                                && !self.related_exists(related.id(), two_way_key, &value_id).await?.is_empty()
                            {
                                // Before the relations change, as the unique
                                // index would only fail afterwards.
                                return Err(Error::duplicate("Document already has a related document"));
                            }
                            let mut found = found;
                            found.set(two_way_key, string(document.id()));
                            let found_id = found.id().to_owned();
                            self.skip_relationships(|| self.update_document(related.id(), &found_id, found)).await?;
                        }
                        Value::Ext(Ext::Document(d)) => {
                            let mut value = (**d).clone();
                            let existing = self
                                .skip_relationships(|| self.get_document(related.id(), value.id(), vec![], false))
                                .await?;
                            if nullsafe_id(&old_value)?.as_deref() != Some(value.id())
                                && !self.related_exists(related.id(), two_way_key, value.id()).await?.is_empty()
                            {
                                return Err(Error::duplicate("Document already has a related document"));
                            }
                            // Not popped when the write fails, as in PHP.
                            self.state().relationship_write_stack.push(related.id().to_owned());
                            value.set(two_way_key, string(document.id()));
                            let written = if existing.is_empty() {
                                if value.get("$permissions").is_none() {
                                    value.set(
                                        "$permissions",
                                        document.get("$permissions").cloned().unwrap_or(Value::Null),
                                    );
                                }
                                self.create_document(related.id(), value).await?
                            } else {
                                self.update_document(related.id(), existing.id(), value).await?
                            };
                            self.state().relationship_write_stack.pop();
                            document.set(key, string(written.id()));
                        }
                        Value::Null | Value::Ext(_) | Value::Object(_) => {
                            if let Some(old_id) = nullsafe_id(&old_value)? {
                                let old_related = self
                                    .skip_relationships(|| self.get_document(related.id(), &old_id, vec![], false))
                                    .await?;
                                let unset = document_of(two_way_key, Value::Null)?;
                                self.skip_relationships(|| self.update_document(related.id(), old_related.id(), unset))
                                    .await?;
                            }
                        }
                        _ => {
                            return Err(Error::relationship(
                                "Invalid relationship value. Must be either a document, document ID or null.",
                            ));
                        }
                    }
                }
                Self::RELATION_ONE_TO_MANY | Self::RELATION_MANY_TO_ONE => {
                    if relation.reads_back_reference() {
                        let items = match &value {
                            Value::Array(a) if a.is_list() => a.clone(),
                            other => {
                                return Err(Error::relationship(format!(
                                    "Invalid relationship value. Must be either an array of documents or document IDs, {} given.",
                                    other.type_name()
                                )));
                            }
                        };
                        let old_ids = ids_of_list(&old_value)?;
                        let new_ids = new_ids(&items)?;
                        for removed in diff(&old_ids, &new_ids) {
                            let unset = document_of(two_way_key, Value::Null)?;
                            let removed = to_text(&removed);
                            self.authorization()
                                .skip(|| {
                                    self.skip_relationships(|| self.update_document(related.id(), &removed, unset))
                                })
                                .await?;
                        }
                        for item in items.values() {
                            match item {
                                Value::Str(_) => {
                                    let item_id = to_text(item);
                                    let found = self
                                        .skip_relationships(|| {
                                            self.get_document(
                                                related.id(),
                                                &item_id,
                                                vec![Query::select(["$id"])],
                                                false,
                                            )
                                        })
                                        .await?;
                                    if found.is_empty() {
                                        continue;
                                    }
                                    let mut found = found;
                                    found.set(two_way_key, string(document.id()));
                                    let found_id = found.id().to_owned();
                                    self.skip_relationships(|| self.update_document(related.id(), &found_id, found))
                                        .await?;
                                }
                                Value::Ext(Ext::Document(d)) => {
                                    let mut item = (**d).clone();
                                    let found = self
                                        .skip_relationships(|| {
                                            self.get_document(
                                                related.id(),
                                                item.id(),
                                                vec![Query::select(["$id"])],
                                                false,
                                            )
                                        })
                                        .await?;
                                    if found.is_empty() {
                                        if item.get("$permissions").is_none() {
                                            item.set(
                                                "$permissions",
                                                document.get("$permissions").cloned().unwrap_or(Value::Null),
                                            );
                                        }
                                        item.set(two_way_key, string(document.id()));
                                        self.create_document(related.id(), item).await?;
                                    } else {
                                        item.set(two_way_key, string(document.id()));
                                        self.update_document(related.id(), found.id(), item).await?;
                                    }
                                }
                                _ => return Err(Error::relationship("Invalid relationship value.")),
                            }
                        }
                        document.remove(key);
                        continue;
                    }

                    match &value {
                        Value::Str(_) => {
                            let value_id = to_text(&value);
                            let found = self
                                .skip_relationships(|| {
                                    self.get_document(related.id(), &value_id, vec![Query::select(["$id"])], false)
                                })
                                .await?;
                            if found.is_empty() {
                                document.set(key, Value::Null);
                            }
                            self.purge_cached_document(related.id(), Some(&value_id)).await?;
                        }
                        Value::Ext(Ext::Document(d)) => {
                            let mut item = (**d).clone();
                            let found = self
                                .skip_relationships(|| {
                                    self.get_document(related.id(), item.id(), vec![Query::select(["$id"])], false)
                                })
                                .await?;
                            // PHP reads the id back from the document it created.
                            let mut item_id = item.id().to_owned();
                            if found.is_empty() {
                                if item.get("$permissions").is_none() {
                                    item.set(
                                        "$permissions",
                                        document.get("$permissions").cloned().unwrap_or(Value::Null),
                                    );
                                }
                                item_id = self.create_document(related.id(), item).await?.id().to_owned();
                            } else if attributes_differ(&found, &item) {
                                self.update_document(related.id(), found.id(), item).await?;
                                self.purge_cached_document(related.id(), Some(found.id())).await?;
                            }
                            document.set(key, string(item_id));
                        }
                        Value::Null => {}
                        Value::Array(_) => {
                            return Err(Error::relationship(
                                "Invalid relationship value. Must be either a document ID or a document, array given.",
                            ));
                        }
                        other if !other.truthy() => {
                            return Err(Error::relationship(
                                "Invalid relationship value. Must be either a document ID or a document.",
                            ));
                        }
                        _ => return Err(Error::relationship("Invalid relationship value.")),
                    }
                }
                Self::RELATION_MANY_TO_MANY => {
                    let items = match &value {
                        Value::Null => continue,
                        Value::Array(a) => a.clone(),
                        _ => {
                            return Err(Error::relationship(
                                "Invalid relationship value. Must be an array of documents or document IDs.",
                            ));
                        }
                    };
                    let old_ids = ids_of_list(&old_value)?;
                    let new_ids = new_ids(&items)?;
                    let junction = self.junction_collection(collection, &related, side);
                    for removed in diff(&old_ids, &new_ids) {
                        let junctions = self
                            .find(
                                &junction,
                                vec![
                                    Query::equal(key, [removed]),
                                    Query::equal(two_way_key, [document.id()]),
                                    Query::limit(i64::MAX),
                                ],
                                Self::PERMISSION_READ,
                            )
                            .await?;
                        for link in junctions {
                            self.authorization().skip(|| self.delete_document(link.collection(), link.id())).await?;
                        }
                    }
                    for item in items.values() {
                        let relation_id = match item {
                            Value::Str(_) => {
                                if in_array(item, &old_ids)
                                    || self
                                        .get_document(related.id(), &to_text(item), vec![Query::select(["$id"])], false)
                                        .await?
                                        .is_empty()
                                {
                                    continue;
                                }
                                to_text(item)
                            }
                            Value::Ext(Ext::Document(d)) => {
                                let mut item = (**d).clone();
                                let mut found = self
                                    .get_document(related.id(), item.id(), vec![Query::select(["$id"])], false)
                                    .await?;
                                let item_id = item.id().to_owned();
                                if found.is_empty() {
                                    // PHP checks the list (`$value`), not the item.
                                    if !items.get_str("$permissions").is_some_and(|p| !matches!(p, Value::Null)) {
                                        item.set(
                                            "$permissions",
                                            document.get("$permissions").cloned().unwrap_or(Value::Null),
                                        );
                                    }
                                    found = self.create_document(related.id(), item).await?;
                                } else if attributes_differ(&found, &item) {
                                    let found_id = found.id().to_owned();
                                    found = self.update_document(related.id(), &found_id, item).await?;
                                }
                                if in_array(&string(item_id), &old_ids) {
                                    continue;
                                }
                                found.id().to_owned()
                            }
                            _ => {
                                return Err(Error::relationship(
                                    "Invalid relationship value. Must be either a document or document ID.",
                                ));
                            }
                        };
                        let link = new_document(vec![
                            (key, string(relation_id)),
                            (two_way_key, string(document.id())),
                            ("$permissions", junction_permissions()),
                        ])?;
                        self.skip_relationships(|| self.create_document(&junction, link)).await?;
                    }
                    document.remove(key);
                }
                _ => {}
            }
        }
        Ok(document)
    }

    /// The document of `related` whose `two_way_key` is `id` (only its
    /// `$id`), without relationships.
    async fn related_exists(&self, related: &str, two_way_key: &str, id: &str) -> Result<Document> {
        self.skip_relationships(|| {
            self.find_one(related, vec![Query::select(["$id"]), Query::equal(two_way_key, [id])])
        })
        .await
    }

    /// The junction collection of a many-to-many relationship:
    /// `_{parentSequence}_{childSequence}`.
    ///
    /// PHP: `Database::getJunctionCollection()` (private).
    pub(crate) fn junction_collection(
        &self,
        collection: &Document,
        related_collection: &Document,
        side: &str,
    ) -> String {
        let sequence = |d: &Document| d.sequence().map(to_text).unwrap_or_default();
        if side == Self::RELATION_SIDE_PARENT {
            format!("_{}_{}", sequence(collection), sequence(related_collection))
        } else {
            format!("_{}_{}", sequence(related_collection), sequence(collection))
        }
    }

    /// The related ids (or documents) after applying a relationship
    /// operator (`arrayAppend`, `arrayRemove`, ...) to `existing_ids`.
    ///
    /// PHP: `Database::applyRelationshipOperator()` (private).
    pub(crate) fn apply_relationship_operator(
        &self,
        operator: &Operator,
        existing_ids: &[Value],
    ) -> Result<Vec<Value>> {
        let values = operator.values();
        let value_ids = || operator_ids(values);
        let mut existing = existing_ids.to_vec();
        Ok(match operator.method() {
            OperatorMethod::ArrayAppend => {
                existing.extend(value_ids());
                existing
            }
            OperatorMethod::ArrayPrepend => {
                let mut out = value_ids();
                out.extend(existing);
                out
            }
            OperatorMethod::ArrayInsert => {
                let position = values.first().map(crate::adapter::postgres::pdo::to_long).unwrap_or(0);
                if let Some(id) = values.get(1).and_then(operator_id) {
                    let len = existing.len() as i64;
                    let at = if position < 0 { (len + position).max(0) } else { position.min(len) };
                    existing.insert(at as usize, id);
                }
                existing
            }
            OperatorMethod::ArrayRemove => match values.first() {
                Some(Value::Array(remove)) => diff(&existing, &operator_ids(remove.values())),
                Some(remove) => match operator_id(remove) {
                    Some(id) => diff(&existing, &[id]),
                    None => existing,
                },
                None => existing,
            },
            OperatorMethod::ArrayUnique => unique(existing),
            OperatorMethod::ArrayIntersect => intersect(&existing, &value_ids()),
            OperatorMethod::ArrayDiff => diff(&existing, &value_ids()),
            _ => existing,
        })
    }

    // ----- Deleting -----

    /// Applies the on-delete rule of each relationship of `document` before
    /// it is deleted; with `report`, returns the two-way related documents
    /// left changed (keyed by their tenant key).
    ///
    /// PHP: `Database::deleteDocumentRelationships()` (private). Called by
    /// `delete_document`, `delete_documents`.
    pub(crate) async fn delete_document_relationships(
        &self,
        collection: &Document,
        document: &Document,
        report: bool,
    ) -> Result<Vec<(String, Document)>> {
        let mut related_documents: Vec<(String, Document)> = Vec::new();
        let mut cascaded = false;

        for mut relationship in relationship_attributes(collection) {
            let relation = Relation::of(&relationship);
            let key = relation.key.as_str();
            let value = document.get(key).cloned().unwrap_or(Value::Null);
            let related = self.get_collection(&relation.related_collection).await?;
            let (kind, two_way, two_way_key, side) =
                (relation.kind.as_str(), relation.two_way, relation.two_way_key.as_str(), relation.side.as_str());

            relationship.set("collection", string(collection.id()));
            relationship.set("document", string(document.id()));

            // Whether the other side survives this delete without being written to.
            let mut unwritten = false;

            match relation.on_delete.as_str() {
                Self::RELATION_MUTATE_RESTRICT => {
                    self.delete_restrict(&related, document, &value, kind, two_way, two_way_key, side).await?;
                    unwritten = true;
                }
                Self::RELATION_MUTATE_SET_NULL => {
                    let updated =
                        self.delete_set_null(collection, &related, document, kind, two_way, two_way_key, side).await?;
                    if two_way {
                        for relation in updated {
                            remember(&mut related_documents, format!("{}:{}", related.id(), relation.id()), relation);
                        }
                    }
                    unwritten = relation.holds_key() || kind == Self::RELATION_MANY_TO_MANY;
                }
                Self::RELATION_MUTATE_CASCADE => {
                    unwritten = relation.holds_key()
                        || (kind == Self::RELATION_MANY_TO_MANY && side == Self::RELATION_SIDE_CHILD);
                    let stack = self.state().relationship_delete_stack.clone();
                    let skip = stack.iter().any(|processed| {
                        let existing = Relation::of(processed);
                        let existing_collection = to_text(processed.raw("collection").unwrap_or(&Value::Null));
                        let (existing_key, existing_related, existing_two_way_key, existing_side) = (
                            existing.key.as_str(),
                            existing.related_collection.as_str(),
                            existing.two_way_key.as_str(),
                            existing.side.as_str(),
                        );
                        // Already fetched for this document.
                        let reflexive = Value::from(processed.clone()).loose_eq(&Value::from(relationship.clone()));
                        // The same relationship, from the other side.
                        let symmetric = existing_key == two_way_key
                            && existing_two_way_key == key
                            && existing_related == collection.id()
                            && existing_collection == related.id()
                            && existing_side != side;
                        // Related across several collections.
                        let transitive = (existing_key == two_way_key
                            && existing_collection == related.id()
                            && existing_side != side)
                            || (existing_two_way_key == key
                                && existing_related == collection.id()
                                && existing_side != side)
                            || (existing_key == key
                                && existing_two_way_key != two_way_key
                                && existing_related == related.id()
                                && existing_side != side)
                            || (existing_key != key
                                && existing_two_way_key == two_way_key
                                && existing_related == related.id()
                                && existing_side != side);
                        reflexive || symmetric || transitive
                    });
                    if !skip {
                        self.delete_cascade(
                            collection,
                            &related,
                            document,
                            key,
                            &value,
                            kind,
                            two_way_key,
                            side,
                            &relationship,
                        )
                        .await?;
                    }
                }
                _ => {}
            }

            let values: Vec<Value> = match &value {
                Value::Array(a) => a.values().cloned().collect(),
                other => vec![other.clone()],
            };
            for item in values {
                let Some(relation_document) = as_document(&item).filter(|d| !d.is_empty()) else {
                    continue;
                };
                if relation.on_delete == Self::RELATION_MUTATE_CASCADE && !unwritten {
                    cascaded = true;
                } else if two_way && unwritten {
                    remember(
                        &mut related_documents,
                        format!("{}:{}", related.id(), relation_document.id()),
                        relation_document.clone(),
                    );
                }
            }
        }

        if !report {
            return Ok(Vec::new());
        }

        // A document related to itself is deleted, not changed.
        let own = format!("{}:{}", collection.id(), document.id());
        related_documents.retain(|(k, _)| *k != own);

        if !cascaded || related_documents.is_empty() {
            return Ok(related_documents);
        }

        // A cascade can remove a related document anywhere down its chain:
        // keep only the ones still there.
        let mut ids_by_collection: Vec<(String, Vec<String>)> = Vec::new();
        for (_, relation) in &related_documents {
            let collection_id = relation.collection().to_owned();
            match ids_by_collection.iter_mut().find(|(c, _)| *c == collection_id) {
                Some((_, ids)) => ids.push(relation.id().to_owned()),
                None => ids_by_collection.push((collection_id, vec![relation.id().to_owned()])),
            }
        }
        let mut existing: Vec<(String, String)> = Vec::new();
        for (collection_id, ids) in ids_by_collection {
            for chunk in ids.chunks(self.max_query_values().max(1) as usize) {
                let queries = vec![
                    Query::equal("$id", chunk.iter().map(String::as_str)),
                    Query::select(["$id"]),
                    Query::limit(chunk.len() as i64),
                ];
                let found =
                    self.authorization().skip(|| self.find(&collection_id, queries, Self::PERMISSION_READ)).await?;
                for doc in found {
                    existing.push((collection_id.clone(), doc.id().to_owned()));
                }
            }
        }
        related_documents
            .retain(|(_, relation)| existing.iter().any(|(c, id)| c == relation.collection() && id == relation.id()));
        Ok(related_documents)
    }

    /// The `restrict` rule: fails when related documents exist.
    ///
    /// PHP: `Database::deleteRestrict()` (private).
    #[allow(clippy::too_many_arguments)]
    async fn delete_restrict(
        &self,
        related_collection: &Document,
        document: &Document,
        value: &Value,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<()> {
        let value = match value {
            Value::Ext(Ext::Document(d)) if d.is_empty() => &Value::Null,
            other => other,
        };
        if value.truthy() && relation_type != Self::RELATION_MANY_TO_ONE && side == Self::RELATION_SIDE_PARENT {
            return Err(Error::restricted("Cannot delete document because it has at least one related document."));
        }

        if relation_type == Self::RELATION_ONE_TO_ONE && side == Self::RELATION_SIDE_CHILD && !two_way {
            self.authorization()
                .skip(|| async {
                    let related = self.find_one(related_collection.id(), referencing(two_way_key, document)).await?;
                    if related.is_empty() {
                        return Ok(());
                    }
                    let unset = document_of(two_way_key, Value::Null)?;
                    self.skip_relationships(|| self.update_document(related_collection.id(), related.id(), unset))
                        .await?;
                    Ok::<(), Error>(())
                })
                .await?;
        }

        if relation_type == Self::RELATION_MANY_TO_ONE && side == Self::RELATION_SIDE_CHILD {
            let related = self
                .authorization()
                .skip(|| self.find_one(related_collection.id(), referencing(two_way_key, document)))
                .await?;
            if !related.is_empty() {
                return Err(Error::restricted("Cannot delete document because it has at least one related document."));
            }
        }
        Ok(())
    }

    /// The documents of `related_collection` whose `two_way_key` points at
    /// `document` (only their `$id`), whatever the permissions: deletes can
    /// start from a document read without its relationships.
    ///
    /// PHP: `Database::findReferencingDocuments()` (private).
    async fn find_referencing_documents(
        &self,
        related_collection: &Document,
        document: &Document,
        two_way_key: &str,
    ) -> Result<Vec<Document>> {
        let mut queries = referencing(two_way_key, document);
        queries.push(Query::limit(i64::MAX));
        self.authorization().skip(|| self.find(related_collection.id(), queries, Self::PERMISSION_READ)).await
    }

    /// The `setNull` rule: unlinks related documents; returns the documents
    /// written.
    ///
    /// PHP: `Database::deleteSetNull()` (private).
    #[allow(clippy::too_many_arguments)]
    async fn delete_set_null(
        &self,
        collection: &Document,
        related_collection: &Document,
        document: &Document,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<Vec<Document>> {
        let mut updated = Vec::new();
        let related_id = related_collection.id();
        let unset_one = |id: String| async move {
            let unset = document_of(two_way_key, Value::Null)?;
            self.authorization().skip(|| self.skip_relationships(|| self.update_document(related_id, &id, unset))).await
        };
        match relation_type {
            Self::RELATION_ONE_TO_ONE => {
                if !two_way && side == Self::RELATION_SIDE_PARENT {
                    return Ok(updated);
                }
                // No read or update permission needed to delete.
                let result = self
                    .authorization()
                    .skip(|| async {
                        let related = self.find_one(related_id, referencing(two_way_key, document)).await?;
                        if related.is_empty() {
                            return Ok(None);
                        }
                        let unset = document_of(two_way_key, Value::Null)?;
                        let written =
                            self.skip_relationships(|| self.update_document(related_id, related.id(), unset)).await?;
                        Ok::<_, Error>(Some(written))
                    })
                    .await?;
                if let Some(result) = result.filter(|r| !r.is_empty()) {
                    updated.push(result);
                }
            }
            Self::RELATION_ONE_TO_MANY | Self::RELATION_MANY_TO_ONE => {
                let skipped = if relation_type == Self::RELATION_ONE_TO_MANY {
                    side == Self::RELATION_SIDE_CHILD
                } else {
                    side == Self::RELATION_SIDE_PARENT
                };
                if skipped {
                    return Ok(updated);
                }
                for relation in self.find_referencing_documents(related_collection, document, two_way_key).await? {
                    let result = unset_one(relation.id().to_owned()).await?;
                    if !result.is_empty() {
                        updated.push(result);
                    }
                }
            }
            Self::RELATION_MANY_TO_MANY => {
                let junction = self.junction_collection(collection, related_collection, side);
                let mut queries = referencing(two_way_key, document);
                queries.push(Query::limit(i64::MAX));
                let links = self.find(&junction, queries, Self::PERMISSION_READ).await?;
                for link in links {
                    self.skip_relationships(|| self.delete_document(&junction, link.id())).await?;
                }
            }
            _ => {}
        }
        Ok(updated)
    }

    /// The `cascade` rule: deletes related documents.
    ///
    /// PHP: `Database::deleteCascade()` (private).
    #[allow(clippy::too_many_arguments)]
    async fn delete_cascade(
        &self,
        collection: &Document,
        related_collection: &Document,
        document: &Document,
        key: &str,
        value: &Value,
        relation_type: &str,
        two_way_key: &str,
        side: &str,
        relationship: &Document,
    ) -> Result<()> {
        let related_id = related_collection.id();
        // Pushed for the deletes below; like PHP, not popped when one fails.
        let push = || self.state().relationship_delete_stack.push(relationship.clone());
        let pop = || {
            self.state().relationship_delete_stack.pop();
        };
        match relation_type {
            Self::RELATION_ONE_TO_ONE => {
                if !matches!(value, Value::Null) {
                    push();
                    let id = match value {
                        Value::Ext(Ext::Document(d)) => d.id().to_owned(),
                        other => to_text(other),
                    };
                    self.delete_document(related_id, &id).await?;
                    pop();
                }
            }
            Self::RELATION_ONE_TO_MANY => {
                if side == Self::RELATION_SIDE_CHILD {
                    return Ok(());
                }
                push();
                if let Value::Array(relations) = value {
                    for relation in relations.values() {
                        let id = to_text(&id_of(relation)?);
                        self.delete_document(related_id, &id).await?;
                    }
                }
                pop();
            }
            Self::RELATION_MANY_TO_ONE => {
                if side == Self::RELATION_SIDE_PARENT {
                    return Ok(());
                }
                let mut queries = referencing(two_way_key, document);
                queries.push(Query::limit(i64::MAX));
                let relations = self.find(related_id, queries, Self::PERMISSION_READ).await?;
                push();
                for relation in relations {
                    self.delete_document(related_id, relation.id()).await?;
                }
                pop();
            }
            Self::RELATION_MANY_TO_MANY => {
                let junction = self.junction_collection(collection, related_collection, side);
                let queries = vec![
                    Query::select(["$id", key]),
                    Query::equal(two_way_key, [document.id()]),
                    Query::limit(i64::MAX),
                ];
                let links = self.skip_relationships(|| self.find(&junction, queries, Self::PERMISSION_READ)).await?;
                push();
                for link in links {
                    if side == Self::RELATION_SIDE_PARENT {
                        let id = to_text(link.get(key).unwrap_or(&Value::Null));
                        self.delete_document(related_id, &id).await?;
                    }
                    self.delete_document(&junction, link.id()).await?;
                }
                pop();
            }
            _ => {}
        }
        Ok(())
    }

    // ----- Relationship queries -----

    /// The nested selections of `select` queries on relationship paths
    /// (`author.name`), by relationship key; rewrites those queries to
    /// select the relationship attribute itself (or nothing, for a side that
    /// stores nothing), `*` when nothing is left.
    ///
    /// PHP: `Database::processRelationshipQueries()` (private), which
    /// rewrites the (shared) query objects it receives. Called by
    /// `get_document`, `find`.
    pub(crate) fn process_relationship_queries(
        &self,
        relationships: &[Document],
        queries: &mut [Query],
    ) -> Result<Selects> {
        let mut nested: Selects = Vec::new();
        for query in queries.iter_mut() {
            if *query.method() != Method::Select {
                continue;
            }
            let mut values: Vec<Option<Value>> = query.values().iter().cloned().map(Some).collect();
            for slot in values.iter_mut() {
                let Some(Value::Str(value)) = slot else { continue };
                let value = String::from_utf8_lossy(value.as_bytes()).into_owned();
                if !value.contains('.') {
                    continue;
                }
                let (selected_key, path) = value.split_once('.').unwrap_or((value.as_str(), ""));
                let Some(relationship) =
                    relationships.iter().find(|r| r.get("key").is_some_and(|k| k.strict_eq(&string(selected_key))))
                else {
                    continue;
                };

                // 'foo.bar.baz' passes 'bar.baz' down the chain; a bare
                // relationship selects all of it.
                let selection = if empty_text(path) { Query::select(["*"]) } else { Query::select([path]) };
                match nested.iter_mut().find(|(k, _)| k == selected_key) {
                    Some((_, list)) => list.push(selection),
                    None => nested.push((selected_key.to_owned(), vec![selection])),
                }

                let relation = Relation::of(relationship);
                let keep = match relation.kind.as_str() {
                    Self::RELATION_MANY_TO_MANY => false,
                    Self::RELATION_ONE_TO_MANY => relation.side != Self::RELATION_SIDE_PARENT,
                    Self::RELATION_MANY_TO_ONE => relation.side == Self::RELATION_SIDE_PARENT,
                    Self::RELATION_ONE_TO_ONE => true,
                    _ => continue,
                };
                *slot = keep.then(|| string(selected_key));
            }
            let mut values: Vec<Value> = values.into_iter().flatten().collect();
            if values.is_empty() {
                values = vec![string("*")];
            }
            query.set_values(values);
        }
        Ok(nested)
    }

    /// The ids of `start_collection`'s documents matching queries on
    /// multi-level relationship paths (`project.employee.company.name`),
    /// resolved from the deepest level up; `None` when nothing matches.
    ///
    /// PHP: `Database::processNestedRelationshipPath()` (private).
    async fn process_nested_relationship_path(
        &self,
        start_collection: &str,
        queries: &[Query],
    ) -> Result<Option<Vec<Value>>> {
        // Path (all but the last part) => the queries on its last part.
        let mut groups: Vec<(String, Vec<Query>)> = Vec::new();
        for query in queries {
            let attribute = query.attribute();
            if !attribute.contains('.') {
                continue;
            }
            let (path, last) = attribute.rsplit_once('.').unwrap_or(("", attribute));
            let leaf = Query::new(query.method().clone(), last, query.values().to_vec());
            match groups.iter_mut().find(|(p, _)| p == path) {
                Some((_, list)) => list.push(leaf),
                None => groups.push((path.to_owned(), vec![leaf])),
            }
        }

        let mut all = Vec::new();
        for (path, leaves) in groups {
            let mut current = start_collection.to_owned();
            // (key, from, to, relation)
            let mut chain: Vec<(String, String, String, Relation)> = Vec::new();
            for key in path.split('.') {
                let collection = self.silent(|| self.get_collection(&current), None).await?;
                let Some(relationship) = relationship_attributes(&collection)
                    .into_iter()
                    .find(|r| r.raw("key").is_some_and(|k| k.strict_eq(&string(key))))
                else {
                    return Ok(None);
                };
                let relation = Relation::of(&relationship);
                let to = relation.related_collection.clone();
                chain.push((key.to_owned(), current.clone(), to.clone(), relation));
                current = to;
            }

            let mut leaves = leaves;
            leaves.push(Query::select(["$id"]));
            leaves.push(Query::limit(i64::MAX));
            let found = self
                .silent(|| self.skip_relationships(|| self.find(&current, leaves, Self::PERMISSION_READ)), None)
                .await?;
            let mut matching: Vec<Value> = found.iter().map(|d| string(d.id())).collect();
            if matching.is_empty() {
                return Ok(None);
            }

            for (key, from, to, relation) in chain.iter().rev() {
                if relation.reads_back_reference() || relation.kind == Self::RELATION_MANY_TO_MANY {
                    let mut parents: Vec<Value> = Vec::new();
                    let mut add = |id: Value| {
                        if id.truthy() && !in_array(&id, &parents) {
                            parents.push(id);
                        }
                    };
                    if relation.kind == Self::RELATION_MANY_TO_MANY {
                        // The junction directly, not the populated children.
                        let from_collection = self.silent(|| self.get_collection(from), None).await?;
                        let to_collection = self.silent(|| self.get_collection(to), None).await?;
                        let junction = self.junction_collection(&from_collection, &to_collection, &relation.side);
                        let queries = vec![Query::equal(key, matching.clone()), Query::limit(i64::MAX)];
                        let links = self
                            .silent(
                                || self.skip_relationships(|| self.find(&junction, queries, Self::PERMISSION_READ)),
                                None,
                            )
                            .await?;
                        for link in links {
                            add(link.get(&relation.two_way_key).cloned().unwrap_or(Value::Null));
                        }
                    } else {
                        let queries = vec![
                            Query::equal("$id", matching.clone()),
                            Query::select(["$id", relation.two_way_key.as_str()]),
                            Query::limit(i64::MAX),
                        ];
                        let children = self
                            .silent(|| self.skip_relationships(|| self.find(to, queries, Self::PERMISSION_READ)), None)
                            .await?;
                        for child in children {
                            match child.get(&relation.two_way_key).cloned().unwrap_or(Value::Null) {
                                Value::Array(parents) => {
                                    for parent in parents.values() {
                                        add(id_or_value(parent));
                                    }
                                }
                                parent => add(id_or_value(&parent)),
                            }
                        }
                    }
                    matching = parents;
                } else {
                    let queries =
                        vec![Query::equal(key, matching.clone()), Query::select(["$id"]), Query::limit(i64::MAX)];
                    let parents = self
                        .silent(|| self.skip_relationships(|| self.find(from, queries, Self::PERMISSION_READ)), None)
                        .await?;
                    matching = parents.iter().map(|d| string(d.id())).collect();
                }
                if matching.is_empty() {
                    return Ok(None);
                }
            }
            all.extend(matching);
        }
        Ok(Some(unique(all)))
    }

    /// `queries` with filters on relationship paths replaced by id filters
    /// on the collection's own attributes; `None` when the relationship
    /// filters cannot match any document.
    ///
    /// PHP: `Database::convertRelationshipQueries()` (private). Called by
    /// `find`, `count`, `sum`.
    pub(crate) async fn convert_relationship_queries(
        &self,
        relationships: &[Document],
        queries: Vec<Query>,
        collection: Option<&Document>,
    ) -> Result<Option<Vec<Query>>> {
        let has_relationship_query =
            queries.iter().any(|q| q.attribute().contains('.') || *q.method() == Method::ContainsAll);
        if !has_relationship_query {
            return Ok(Some(queries));
        }

        let by_key = |key: &str| -> Option<&Document> {
            // The last relationship of a key wins, as array keys do.
            relationships.iter().rev().find(|r| to_text(r.get("key").unwrap_or(&Value::Null)) == key)
        };

        let mut additional = Vec::new();
        let mut removed: Vec<usize> = Vec::new();

        for (position, query) in queries.iter().enumerate() {
            if *query.method() != Method::ContainsAll {
                continue;
            }
            let attribute = query.attribute();
            let Some((relationship_key, nested)) = attribute.split_once('.') else {
                // Non-relationship containsAll: the adapter's.
                continue;
            };
            let Some(relationship) = by_key(relationship_key) else {
                continue;
            };
            // Each value resolved on its own, then the parents intersected.
            let mut sets: Vec<Vec<Value>> = Vec::new();
            let mut resolved_attribute = "$id".to_owned();
            for value in query.values() {
                let related = vec![Query::equal(nested, [value.clone()])];
                let Some((attribute, ids)) =
                    self.resolve_relationship_group_to_ids(relationship, related, collection).await?
                else {
                    return Ok(None);
                };
                resolved_attribute = attribute;
                sets.push(ids);
            }
            let ids = match sets.len() {
                0 => Vec::new(),
                1 => sets.remove(0),
                _ => sets[1..].iter().fold(sets[0].clone(), |acc, set| intersect(&acc, set)),
            };
            if ids.is_empty() {
                return Ok(None);
            }
            additional.push(Query::equal(&resolved_attribute, ids));
            removed.push(position);
        }

        // Dot-path queries by relationship key: (key, queries, positions).
        let mut groups: Vec<(String, Vec<Query>, Vec<usize>)> = Vec::new();
        for (position, query) in queries.iter().enumerate() {
            if matches!(query.method(), Method::Select | Method::ContainsAll) {
                continue;
            }
            let Some((relationship_key, nested)) = query.attribute().split_once('.') else {
                continue;
            };
            if by_key(relationship_key).is_none() {
                continue;
            }
            let related = Query::new(query.method().clone(), nested, query.values().to_vec());
            match groups.iter_mut().find(|(k, _, _)| k == relationship_key) {
                Some((_, list, positions)) => {
                    list.push(related);
                    positions.push(position);
                }
                None => groups.push((relationship_key.to_owned(), vec![related], vec![position])),
            }
        }

        for (relationship_key, related, positions) in groups {
            let Some(relationship) = by_key(&relationship_key) else { continue };
            // Several equals on one attribute never match a single document.
            let mut equal_attributes: Vec<&str> = Vec::new();
            for query in &related {
                if *query.method() == Method::Equal {
                    let attribute = query.attribute();
                    if equal_attributes.contains(&attribute) {
                        return Err(Error::query(format!(
                            "Multiple equal queries on '{relationship_key}.{attribute}' will never match a single document. Use Query::containsAll() to match across different related documents."
                        )));
                    }
                    equal_attributes.push(attribute);
                }
            }
            match self.resolve_relationship_group_to_ids(relationship, related.clone(), collection).await {
                Ok(None) => return Ok(None),
                Ok(Some((attribute, ids))) => {
                    additional.push(Query::equal(&attribute, ids));
                    removed.extend(positions);
                }
                Err(e) if e.is_a(Kind::Query) => return Err(e),
                Err(e) if is_exception(&e) => return Ok(None),
                Err(e) => return Err(e),
            }
        }

        let mut out: Vec<Query> =
            queries.into_iter().enumerate().filter(|(i, _)| !removed.contains(i)).map(|(_, q)| q).collect();
        out.extend(additional);
        Ok(Some(out))
    }

    /// The attribute to filter on and the ids matching `related_queries` on
    /// the related collection of `relationship`; `None` when nothing
    /// matches.
    ///
    /// PHP: `Database::resolveRelationshipGroupToIds()` (private), which
    /// returns `['attribute' => ..., 'ids' => [...]]`.
    pub(crate) async fn resolve_relationship_group_to_ids(
        &self,
        relationship: &Document,
        related_queries: Vec<Query>,
        collection: Option<&Document>,
    ) -> Result<Option<(String, Vec<Value>)>> {
        let relation = Relation::of(relationship);
        let related = relation.related_collection.as_str();
        let relationship_key = to_text(relationship.get("key").unwrap_or(&Value::Null));

        let mut related_queries = related_queries;
        if related_queries.iter().any(|q| q.attribute().contains('.')) {
            let Some(matching) = self.process_nested_relationship_path(related, &related_queries).await? else {
                return Ok(None);
            };
            if matching.is_empty() {
                return Ok(None);
            }
            related_queries.retain(|q| !q.attribute().contains('.'));
            related_queries.push(Query::equal("$id", matching));
        }

        let parents_from = |parents: &mut Vec<Value>, value: Value| {
            if value.truthy() && !in_array(&value, parents) {
                parents.push(value);
            }
        };

        if relation.kind == Self::RELATION_MANY_TO_MANY
            && let Some(collection) = collection
        {
            // The junction directly: population is off when the outer find
            // skips relationships.
            let mut queries = related_queries;
            queries.push(Query::select(["$id"]));
            queries.push(Query::limit(i64::MAX));
            let found = self
                .silent(|| self.skip_relationships(|| self.find(related, queries, Self::PERMISSION_READ)), None)
                .await?;
            let matching: Vec<Value> = found.iter().map(|d| string(d.id())).collect();
            if matching.is_empty() {
                return Ok(None);
            }
            let related_collection = self.silent(|| self.get_collection(related), None).await?;
            let junction = self.junction_collection(collection, &related_collection, &relation.side);
            let queries = vec![Query::equal(&relationship_key, matching), Query::limit(i64::MAX)];
            let links = self
                .silent(|| self.skip_relationships(|| self.find(&junction, queries, Self::PERMISSION_READ)), None)
                .await?;
            let mut parents = Vec::new();
            for link in links {
                parents_from(&mut parents, link.get(&relation.two_way_key).cloned().unwrap_or(Value::Null));
            }
            return Ok((!parents.is_empty()).then(|| ("$id".to_owned(), parents)));
        }

        if relation.reads_back_reference() || relation.kind == Self::RELATION_MANY_TO_MANY {
            // Population reads the back-reference of the related documents.
            let mut queries = related_queries;
            queries.push(Query::limit(i64::MAX));
            let found = self.silent(|| self.find(related, queries, Self::PERMISSION_READ), None).await?;
            let mut parents = Vec::new();
            for doc in found {
                match doc.get(&relation.two_way_key).cloned().unwrap_or(Value::Null) {
                    Value::Array(ids) => {
                        for id in ids.values() {
                            parents_from(&mut parents, id_or_value(id));
                        }
                    }
                    parent => parents_from(&mut parents, id_or_value(&parent)),
                }
            }
            return Ok((!parents.is_empty()).then(|| ("$id".to_owned(), parents)));
        }

        let mut queries = related_queries;
        queries.push(Query::select(["$id"]));
        queries.push(Query::limit(i64::MAX));
        let found = self
            .silent(|| self.skip_relationships(|| self.find(related, queries, Self::PERMISSION_READ)), None)
            .await?;
        let matching: Vec<Value> = found.iter().map(|d| string(d.id())).collect();
        Ok((!matching.is_empty()).then_some((relationship_key, matching)))
    }
}

/// `$collection` without attribute `id` in its `attributes`, keys kept
/// (`array_filter`).
fn without_attribute(collection: &mut Document, id: &str) {
    if let Some(Value::Array(attributes)) = collection.get_mut("attributes") {
        attributes.retain(|_, a| match a {
            Value::Ext(Ext::Document(d)) => d.id() != id,
            other => to_text(index(other, "$id")) != id,
        });
    }
}

/// `$related[$key] = $relation`: replaced in place, or appended.
fn remember(related: &mut Vec<(String, Document)>, key: String, relation: Document) {
    match related.iter_mut().find(|(k, _)| *k == key) {
        Some(entry) => entry.1 = relation,
        None => related.push((key, relation)),
    }
}

/// `[Query::select(['$id']), Query::equal($twoWayKey, [$document->getId()])]`.
fn referencing(two_way_key: &str, document: &Document) -> Vec<Query> {
    vec![Query::select(["$id"]), Query::equal(two_way_key, [document.id()])]
}

/// `new Document([$key => $value])`.
fn document_of(key: &str, value: Value) -> Result<Document> {
    new_document(vec![(key, value)])
}

/// A copy of a relationship's options (to call `relateDocuments` with
/// other flags).
fn relation_without_two_way(relation: &Relation) -> Relation {
    Relation {
        key: relation.key.clone(),
        related_collection: relation.related_collection.clone(),
        kind: relation.kind.clone(),
        two_way: false,
        two_way_key: relation.two_way_key.clone(),
        on_delete: relation.on_delete.clone(),
        side: relation.side.clone(),
    }
}
