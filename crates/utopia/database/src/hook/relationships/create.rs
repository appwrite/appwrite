//! `afterDocumentCreate()`: relating the related documents of a new
//! document, one by one or prepared.

use std::sync::{Arc, Mutex};

use super::{
    Prepared, PreparedCreate, Relationships, attributes, coerce_to_document, coerced, junction, lock, permissions_value,
};
use crate::attribute::Attribute;
use crate::capability::Capability;
use crate::collection::Collection;
use crate::database::{BoxFuture, Database, RELATION_MAX_DEPTH};
use crate::document::{self, Document};
use crate::error::{Error, Kind, Result};
use crate::permission::{Permission, PermissionType};
use crate::relationship::{RelationshipSide, RelationshipType};
use crate::role::Role;
use crate::value::{self, Ext, Value};

/// The ids of the related documents a prepared create would create, by
/// related collection id, each as (lower-cased id, id), in the order found.
pub(super) type Created = Vec<(String, Vec<(String, String)>)>;

/// What relating a document reads of one relationship attribute.
pub(super) struct Relation<'r> {
    /// The attribute key.
    pub(super) key: &'r str,
    /// The relationship type.
    pub(super) kind: RelationshipType,
    /// Whether it is two-way.
    pub(super) two_way: bool,
    /// The key on the other side (`''` when none).
    pub(super) two_way_key: String,
    /// The side of the attribute.
    pub(super) side: RelationshipSide,
}

impl<'r> Relation<'r> {
    /// The relation of a relationship attribute; `None` for another one.
    pub(super) fn of(attribute: &'r Attribute) -> Option<Self> {
        let (Some(relationship), Some(side)) = (&attribute.relationship, attribute.side) else { return None };
        Some(Relation {
            key: &attribute.key,
            kind: relationship.kind,
            two_way: relationship.two_way,
            two_way_key: relationship.two_way_key.clone().unwrap_or_default(),
            side,
        })
    }
}

/// Adds `id` to the ids `created` holds for `collection`; `false` when an
/// id equal to it but for case is there already.
fn add_created(created: &mut Created, collection: &str, id: &str) -> bool {
    let lowered = id.to_ascii_lowercase();
    let ids = match created.iter_mut().position(|(c, _)| c == collection) {
        Some(index) => &mut created[index].1,
        None => {
            created.push((collection.to_owned(), Vec::new()));
            &mut created.last_mut().expect("just pushed").1
        }
    };
    if ids.iter().any(|(l, _)| *l == lowered) {
        return false;
    }
    ids.push((lowered, id.to_owned()));
    true
}

/// The document of a junction collection linking `related_id` (under
/// `key`) and `document_id` (under `two_way_key`) (`junctionDocument()`).
pub(super) fn junction_document(key: &str, related_id: &str, two_way_key: &str, document_id: &str) -> Result<Document> {
    let any = Role::any();
    Document::from_pairs([
        (key, value::string(related_id)),
        (two_way_key, value::string(document_id)),
        (
            document::PERMISSIONS,
            value::list([
                value::string(Permission::read(&any)),
                value::string(Permission::update(&any)),
                value::string(Permission::delete(&any)),
            ]),
        ),
    ])
}

/// `$a->getAttributes() != $b->getAttributes()`.
pub(super) fn attributes_differ(a: &Document, b: &Document) -> bool {
    !Value::Array(a.attributes()).loose_eq(&Value::Array(b.attributes()))
}

/// Whether relating a document by id writes anything; otherwise it only
/// reads the related document (`writesById()`).
fn writes_by_id(kind: RelationshipType, two_way: bool, side: RelationshipSide) -> bool {
    match kind {
        RelationshipType::OneToOne => two_way,
        RelationshipType::OneToMany => side == RelationshipSide::Parent,
        RelationshipType::ManyToOne => side == RelationshipSide::Child,
        RelationshipType::ManyToMany => true,
    }
}

/// Whether a new document has a related document given as a document
/// (`hasRelatedDocuments()`).
fn has_related_documents(collection: &Collection, document: &Document) -> Result<bool> {
    for attribute in collection.attributes()? {
        if attribute.relationship.is_none() {
            continue;
        }
        match document.get(&attribute.key) {
            Some(Value::Ext(Ext::Document(_))) => return Ok(true),
            Some(Value::Array(items)) if items.values().any(|v| value::as_document(v).is_some()) => return Ok(true),
            _ => {}
        }
    }
    Ok(false)
}

/// Whether a new document's related documents are given a reference back
/// to it that relating them writes through, which reads it first
/// (`isReferencedBack()`).
fn is_referenced_back(collection: &Collection, document: &Document) -> Result<bool> {
    for attribute in collection.attributes()? {
        let Some(relationship) = &attribute.relationship else { continue };
        if relationship.kind != RelationshipType::OneToOne || !relationship.two_way {
            continue;
        }
        if matches!(document.get(&attribute.key), Some(Value::Ext(Ext::Document(_)) | Value::Array(_))) {
            return Ok(true);
        }
    }
    Ok(false)
}

impl Relationships {
    /// Relates the related documents of a new document, replacing its
    /// relationship values with what is stored for them
    /// (`afterDocumentCreate()`). The caller retrying a transaction gives it
    /// the document it was given again (PHP's `$copies` and `restore()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when a related document exists,
    /// ([`crate::Kind::Relationship`]) for an invalid relationship value.
    pub(crate) fn after_document_create<'a>(
        &'a self,
        collection: &'a Collection,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let db = self.database()?;
            if !self.can_prepare(&db) || !has_related_documents(collection, &document)? {
                return self.relate(collection, document, None).await;
            }

            let prepared: Prepared =
                Arc::new(Mutex::new(PreparedCreate::new(db.adapter().supports(Capability::TransactionNested))));
            let mut created = Created::new();
            let stack = self.current_write_stack();
            let relate = |p: Option<Prepared>| self.relate(collection, document.clone(), p);
            if !self.collect_created(&db, &prepared, collection, &document, &stack, &mut created).await? {
                return self.relate_one_by_one(&relate).await;
            }

            if is_referenced_back(collection, &document)? {
                add_created(&mut created, collection.id(), document.id());
                let mut p = lock(&prepared);
                p.start_preparing(collection.id(), document.id());
                p.remember(collection.clone());
            }

            self.relate_prepared(&db, prepared, &created, relate).await
        })
    }

    /// Relates new related documents through `relate` without reading each
    /// before creating it and reading it back after, when none of them is
    /// stored yet (`relatePrepared()`). Where the adapter has savepoints,
    /// they are prepared, written together, and any failure rolls the
    /// attempt back and relates them one by one instead; elsewhere each is
    /// written where it would have been written on its own. Every attempt
    /// starts from the documents `relate` was given.
    pub(super) async fn relate_prepared<'f, T, F>(
        &self,
        db: &Database,
        prepared: Prepared,
        created: &Created,
        relate: F,
    ) -> Result<T>
    where
        F: Fn(Option<Prepared>) -> BoxFuture<'f, Result<T>> + Send + Sync,
        T: Send,
    {
        if self.any_stored(db, &prepared, created).await {
            return self.relate_one_by_one(&relate).await;
        }

        let deferred = lock(&prepared).deferred;
        let attempt = || async {
            self.preparing
                .with(true, async {
                    let result = relate(Some(prepared.clone())).await?;
                    self.write_prepared(db, &prepared).await?;
                    Ok(result)
                })
                .await
        };

        if !deferred {
            return attempt().await;
        }

        db.with_savepoint(attempt, |_| self.relate_one_by_one(&relate)).await
    }

    /// Relates an updated document's new related documents through
    /// `relate`, prepared when none of them is stored (`relateUpdated()`).
    pub(super) async fn relate_updated<'f, F>(
        &self,
        db: &Database,
        related_collection: &Collection,
        documents: &[Document],
        relate: F,
    ) -> Result<()>
    where
        F: Fn(Option<Prepared>) -> BoxFuture<'f, Result<()>> + Send + Sync,
    {
        if documents.is_empty() || !self.can_prepare(db) {
            return relate(None).await;
        }

        let prepared: Prepared =
            Arc::new(Mutex::new(PreparedCreate::new(db.adapter().supports(Capability::TransactionNested))));
        lock(&prepared).remember(related_collection.clone());
        let mut created = Created::new();
        let stack = self.current_write_stack();
        if !self.collect_related(db, &prepared, related_collection, documents, &stack, &mut created).await? {
            return self.relate_one_by_one(&relate).await;
        }

        self.relate_prepared(db, prepared, &created, relate).await
    }

    /// Relates through `relate` one document at a time
    /// (`relateOneByOne()`): no write inside it prepares.
    async fn relate_one_by_one<'f, T, F>(&self, relate: &F) -> Result<T>
    where
        F: Fn(Option<Prepared>) -> BoxFuture<'f, Result<T>> + Send + Sync,
        T: Send,
    {
        self.replays.with(self.replays.get() + 1, relate(None)).await
    }

    /// Whether the current write may prepare its new related documents
    /// (`canPrepare()`): none of its writes is preparing or relating one by
    /// one already, and it runs in a transaction.
    fn can_prepare(&self, db: &Database) -> bool {
        if !self.prepare || self.preparing.get() || self.replays.get() > 0 {
            return false;
        }
        let adapter = db.adapter();
        adapter.in_transaction() && !adapter.tenant_per_document()
    }

    /// Collects the ids of the related documents a new document's
    /// relationships would create, by related collection, as deep as
    /// relating goes from `write_stack` (`collectCreated()`); `false` when a
    /// related document appears twice, as its second appearance may find
    /// the first one written, or a relationship value is invalid.
    ///
    /// PHP also refuses the same document object met twice; documents are
    /// values here, so only a repeated id is.
    fn collect_created<'a>(
        &'a self,
        db: &'a Database,
        prepared: &'a Prepared,
        collection: &'a Collection,
        document: &'a Document,
        write_stack: &'a [String],
        created: &'a mut Created,
    ) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let depth = write_stack.len();
            for attribute in attributes(collection)? {
                let Some(relationship) = &attribute.relationship else { continue };
                let value = match coerced(document.get(&attribute.key)) {
                    Ok(value) => value,
                    Err(e) if e.is_a(Kind::Relationship) => return Ok(false),
                    Err(e) => return Err(e),
                };
                let related: Vec<Document> = match &value {
                    Some(Value::Ext(Ext::Document(d))) => vec![(**d).clone()],
                    Some(Value::Array(items)) => items.values().filter_map(value::as_document).cloned().collect(),
                    _ => Vec::new(),
                };
                if related.is_empty() {
                    continue;
                }

                let Some(related_collection) =
                    self.related_collection(db, &relationship.related_collection, Some(prepared)).await?
                else {
                    continue;
                };
                if depth as i64 >= RELATION_MAX_DEPTH - 1 && write_stack[depth - 1] != related_collection.id() {
                    continue;
                }

                let mut stack = write_stack.to_vec();
                stack.push(collection.id().to_owned());
                if !self.collect_related(db, prepared, &related_collection, &related, &stack, created).await? {
                    return Ok(false);
                }
            }
            Ok(true)
        })
    }

    /// Collects the ids of related documents, and of the related documents
    /// they would create in turn (`collectRelated()`).
    async fn collect_related(
        &self,
        db: &Database,
        prepared: &Prepared,
        related_collection: &Collection,
        documents: &[Document],
        write_stack: &[String],
        created: &mut Created,
    ) -> Result<bool> {
        for document in documents {
            let id = document.id();
            if !id.is_empty() && !add_created(created, related_collection.id(), id) {
                return Ok(false);
            }
            if !self.collect_created(db, prepared, related_collection, document, write_stack, created).await? {
                return Ok(false);
            }
        }
        Ok(true)
    }

    /// Whether any of the documents is already stored, readable by the
    /// caller or not, or cannot be looked up (`anyStored()`).
    async fn any_stored(&self, db: &Database, prepared: &Prepared, ids: &Created) -> bool {
        let adapter = db.adapter();
        let tenant = if adapter.shared_tables() { adapter.tenant() } else { Value::Null };
        for (collection, collection_ids) in ids {
            let Ok(definition) = self.collection(db, prepared, collection).await else { return true };
            let ids: Vec<&str> = collection_ids.iter().map(|(_, id)| id.as_str()).collect();
            for chunk in ids.chunks(self.relation_query_chunk_size(db)) {
                let mut documents = Vec::with_capacity(chunk.len());
                for id in chunk {
                    match Document::from_pairs([(document::ID, value::string(*id)), (document::TENANT, tenant.clone())])
                    {
                        Ok(d) => documents.push(d),
                        Err(_) => return true,
                    }
                }
                match adapter.get_sequences(&definition, documents).await {
                    Ok(found) => {
                        if found.iter().any(|d| d.sequence().is_some()) {
                            return true;
                        }
                    }
                    Err(_) => return true,
                }
            }
        }
        false
    }

    /// A collection read once for the whole prepared create, as it cannot
    /// change while the create's transaction is open (`collection()`).
    /// The collection a relationship relates to, or `None` when it was
    /// deleted while the relationship was left behind, as 7.x could leave
    /// it: such a relationship is not related, populated or cascaded, and
    /// keeps its stored value (`relatedCollection()`, 8.3.6).
    pub(super) async fn related_collection(
        &self,
        db: &Database,
        id: &str,
        prepared: Option<&Prepared>,
    ) -> Result<Option<Collection>> {
        if let Some(prepared) = prepared
            && let Some(collection) = lock(prepared).collection(id)
        {
            return Ok(Some(collection.clone()));
        }
        let collection = db.find_collection(id).await?;
        if let (Some(prepared), Some(collection)) = (prepared, &collection) {
            let mut p = lock(prepared);
            if p.collection(id).is_none() {
                p.collections.push((id.to_owned(), collection.clone()));
            }
        }
        Ok(collection)
    }

    pub(super) async fn collection(&self, db: &Database, prepared: &Prepared, id: &str) -> Result<Collection> {
        if let Some(collection) = lock(prepared).collection(id) {
            return Ok(collection.clone());
        }
        let collection = db.get_collection(id).await?;
        let mut p = lock(prepared);
        if let Some(collection) = p.collection(id) {
            return Ok(collection.clone());
        }
        p.collections.push((id.to_owned(), collection.clone()));
        Ok(collection)
    }

    /// Prepares a new related document through the checks
    /// `createDocument()` applies, relates its own related documents, and
    /// writes or queues it after them, where `createDocument()` would have
    /// written it (`prepare()`); returns its id.
    pub(super) fn prepare<'a>(
        &'a self,
        db: &'a Database,
        prepared: &'a Prepared,
        collection: &'a Collection,
        document: Document,
    ) -> BoxFuture<'a, Result<String>> {
        Box::pin(async move {
            let document = db.prepare_create(collection, document).await?;
            let id = document.id().to_owned();

            lock(prepared).start_preparing(collection.id(), &id);
            let document = self.relate(collection, document, Some(prepared.clone())).await?;
            let deferred = {
                let mut p = lock(prepared);
                p.stop_preparing(collection.id(), &id);
                p.documents.push((collection.clone(), document));
                p.deferred
            };
            if !deferred {
                self.write_prepared(db, prepared).await?;
            }

            Ok(id)
        })
    }

    /// Writes the prepared documents queued so far, so whatever comes next
    /// sees the database as it would be had each been written on its own
    /// (`writePrepared()`).
    pub(super) async fn write_prepared(&self, db: &Database, prepared: &Prepared) -> Result<()> {
        let documents = std::mem::take(&mut lock(prepared).documents);
        if documents.is_empty() {
            return Ok(());
        }
        db.create_prepared(documents).await
    }

    /// Relates the related documents of `document`, a new document of
    /// `collection` (`relate()`), marking it as being related meanwhile.
    pub(super) fn relate<'a>(
        &'a self,
        collection: &'a Collection,
        document: Document,
        prepared: Option<Prepared>,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let entry = (collection.id().to_owned(), document.id().to_owned());
            let relating = self.relating.get();
            if relating.contains(&entry) {
                return self.relate_attributes(collection, document, prepared).await;
            }
            let mut relating = relating.as_ref().clone();
            relating.push(entry);
            self.relating.with(Arc::new(relating), self.relate_attributes(collection, document, prepared)).await
        })
    }

    /// Whether `id` is a new document of `collection` the current write is
    /// relating before writing it (`isRelating()`).
    pub(super) fn is_relating(&self, collection: &str, id: &str) -> bool {
        self.relating.get().iter().any(|(c, i)| c == collection && i == id)
    }

    /// `relateAttributes()`.
    async fn relate_attributes(
        &self,
        collection: &Collection,
        mut document: Document,
        prepared: Option<Prepared>,
    ) -> Result<Document> {
        let db = self.database()?;
        let write_stack = self.current_write_stack();
        let stack_count = write_stack.len();

        for attribute in attributes(collection)? {
            let Some(relation) = Relation::of(&attribute) else { continue };
            let related_name = &attribute.relationship.as_ref().expect("a relationship").related_collection;
            let Some(related_collection) = self.related_collection(&db, related_name, prepared.as_ref()).await? else {
                detach(&relation, &mut document);
                continue;
            };

            if stack_count as i64 >= RELATION_MAX_DEPTH - 1 && write_stack[stack_count - 1] != related_collection.id() {
                document.remove(relation.key);
                continue;
            }

            self.in_write(
                collection.id(),
                self.relate_value(&db, collection, &related_collection, &relation, &mut document, prepared.as_ref()),
            )
            .await?;
        }

        Ok(document)
    }

    /// Relates the value of one relationship attribute of a new document
    /// (the body of `relateAttributes()`'s loop).
    async fn relate_value(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        relation: &Relation<'_>,
        document: &mut Document,
        prepared: Option<&Prepared>,
    ) -> Result<()> {
        use RelationshipSide::{Child, Parent};
        use RelationshipType::{ManyToMany, ManyToOne, OneToMany, OneToOne};

        let (kind, side, two_way) = (relation.kind, relation.side, relation.two_way);
        let key = relation.key;
        let child_one_way = "Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.";
        let invalid = "Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.";

        match coerce_to_document(document, key)? {
            Some(Value::Array(items)) => {
                if kind == OneToOne && !two_way && side == Child {
                    return Err(Error::relationship(child_one_way));
                }
                if (kind == ManyToOne && side == Parent) || (kind == OneToMany && side == Child) || kind == OneToOne {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either a document ID or a document, array given.",
                    ));
                }
                for related in items.values() {
                    match related {
                        Value::Ext(Ext::Document(d)) => {
                            self.relate_documents(
                                db,
                                collection,
                                related_collection,
                                relation,
                                document,
                                (**d).clone(),
                                prepared,
                                false,
                            )
                            .await?;
                        }
                        Value::Str(_) => {
                            let id = document.id().to_owned();
                            self.relate_documents_by_id(
                                db,
                                collection,
                                related_collection,
                                relation,
                                &id,
                                &value::to_text(related),
                                prepared,
                            )
                            .await?;
                        }
                        _ => return Err(Error::relationship(invalid)),
                    }
                }
                document.remove(key);
            }
            Some(Value::Ext(Ext::Document(related))) => {
                if kind == OneToOne && !two_way && side == Child {
                    return Err(Error::relationship(child_one_way));
                }
                if (kind == OneToMany && side == Parent) || (kind == ManyToOne && side == Child) || kind == ManyToMany {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either an array of documents or document IDs, document given.",
                    ));
                }
                let related_id = self
                    .relate_documents(db, collection, related_collection, relation, document, *related, prepared, false)
                    .await?;
                document.put(key, value::string(related_id));
            }
            Some(Value::Str(related)) => {
                if kind == OneToOne && !two_way && side == Child {
                    return Err(Error::relationship(child_one_way));
                }
                if (kind == OneToMany && side == Parent) || (kind == ManyToOne && side == Child) || kind == ManyToMany {
                    return Err(Error::relationship(
                        "Invalid relationship value. Must be either an array of documents or document IDs, document ID given.",
                    ));
                }
                let id = document.id().to_owned();
                let related = value::to_text(&Value::Str(related));
                self.relate_documents_by_id(db, collection, related_collection, relation, &id, &related, prepared)
                    .await?;
            }
            None => {
                let kept = (kind == OneToMany && side == Child)
                    || (kind == ManyToOne && side == Parent)
                    || (kind == OneToOne && side == Parent)
                    || (kind == OneToOne && two_way);
                if !kept {
                    document.remove(key);
                }
            }
            Some(_) => return Err(Error::relationship(invalid)),
        }
        Ok(())
    }

    /// Relates `relation`, a related document given as a document, to
    /// `document`: created when it is not stored, updated when it changes,
    /// with its junction document for a many-to-many relationship
    /// (`relateDocuments()`); returns its id. `linked` documents are linked
    /// already and not checked for update permission.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn relate_documents(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        relation_of: &Relation<'_>,
        document: &Document,
        mut relation: Document,
        prepared: Option<&Prepared>,
        linked: bool,
    ) -> Result<String> {
        let two_way_key = relation_of.two_way_key.as_str();
        let back = match relation_of.kind {
            RelationshipType::OneToOne => relation_of.two_way,
            RelationshipType::OneToMany => relation_of.side == RelationshipSide::Parent,
            RelationshipType::ManyToOne => relation_of.side == RelationshipSide::Child,
            RelationshipType::ManyToMany => false,
        };
        if back {
            relation.put(two_way_key, value::string(document.id()));
        }

        if let Some(prepared) = prepared {
            return self
                .prepare_related(db, prepared, collection, related_collection, relation_of, document, relation)
                .await;
        }

        let related =
            db.skip_query_hooks(db.get_document(related_collection.id(), relation.id(), Vec::new(), false)).await?;

        if related.is_empty() {
            self.refuse_unreadable(db, related_collection, relation.id(), PermissionType::Update).await?;
        } else if !linked {
            self.authorize_link(db, related_collection, &related)?;
        }

        let related = if related.is_empty() {
            if !relation.is_set(document::PERMISSIONS) {
                relation.set(document::PERMISSIONS, permissions_value(document)?)?;
            }
            db.create_document(related_collection.id(), relation).await?
        } else if attributes_differ(&related, &relation) {
            let mut related = related;
            for (key, value) in relation.attributes() {
                related.as_array_mut().set(key, value);
            }
            let id = related.id().to_owned();
            db.update_document(related_collection.id(), &id, related).await?
        } else {
            related
        };

        if relation_of.kind == RelationshipType::ManyToMany {
            let junction = junction(collection, related_collection, relation_of.side);
            db.create_document(
                &junction,
                junction_document(relation_of.key, related.id(), two_way_key, document.id())?,
            )
            .await?;
        }

        Ok(related.id().to_owned())
    }

    /// Prepares a related document as new, with its junction document
    /// (`prepareRelated()`): `relatePrepared()` found none of the related
    /// documents stored.
    #[allow(clippy::too_many_arguments)]
    async fn prepare_related(
        &self,
        db: &Database,
        prepared: &Prepared,
        collection: &Collection,
        related_collection: &Collection,
        relation_of: &Relation<'_>,
        document: &Document,
        mut relation: Document,
    ) -> Result<String> {
        if !relation.is_set(document::PERMISSIONS) {
            relation.set(document::PERMISSIONS, permissions_value(document)?)?;
        }

        let related_id = self.prepare(db, prepared, related_collection, relation).await?;

        if relation_of.kind == RelationshipType::ManyToMany {
            let junction =
                self.collection(db, prepared, &junction(collection, related_collection, relation_of.side)).await?;
            let link = junction_document(relation_of.key, &related_id, &relation_of.two_way_key, document.id())?;
            self.prepare(db, prepared, &junction, link).await?;
        }

        Ok(related_id)
    }

    /// Relates the related document `relation_id`, given by id, to the
    /// document `document_id` (`relateDocumentsById()`).
    #[allow(clippy::too_many_arguments)]
    async fn relate_documents_by_id(
        &self,
        db: &Database,
        collection: &Collection,
        related_collection: &Collection,
        relation_of: &Relation<'_>,
        document_id: &str,
        relation_id: &str,
        prepared: Option<&Prepared>,
    ) -> Result<()> {
        let (kind, two_way, side) = (relation_of.kind, relation_of.two_way, relation_of.side);
        let two_way_key = relation_of.two_way_key.as_str();

        // A new document still being related or prepared is written after
        // this read and was not stored before: linking to it links to a
        // document this write creates.
        let creating = prepared.is_some_and(|p| lock(p).is_preparing(related_collection.id(), relation_id))
            || self.is_relating(related_collection.id(), relation_id);
        if creating && (self.check_exist.get() || !writes_by_id(kind, two_way, side)) {
            return Ok(());
        }

        if let Some(prepared) = prepared {
            self.write_prepared(db, prepared).await?;
        }

        let mut related = db
            .skip_relationships(db.skip_query_hooks(db.get_document(
                related_collection.id(),
                relation_id,
                Vec::new(),
                false,
            )))
            .await?;

        if related.is_empty() {
            self.refuse_unreadable(db, related_collection, relation_id, PermissionType::Read).await?;
            if self.check_exist.get() {
                return Ok(());
            }
        } else {
            self.authorize_link(db, related_collection, &related)?;
        }

        let back = match kind {
            RelationshipType::OneToOne => two_way,
            RelationshipType::OneToMany => side == RelationshipSide::Parent,
            RelationshipType::ManyToOne => side == RelationshipSide::Child,
            RelationshipType::ManyToMany => {
                db.purge_cached_document(related_collection.id(), relation_id).await?;
                let junction = junction(collection, related_collection, side);
                db.skip_relationships(db.create_document(
                    &junction,
                    junction_document(relation_of.key, relation_id, two_way_key, document_id)?,
                ))
                .await?;
                false
            }
        };
        if back {
            related.put(two_way_key, value::string(document_id));
            db.skip_relationships(db.update_document(related_collection.id(), relation_id, related)).await?;
        }
        Ok(())
    }
}

/// Keeps the value written to a relationship whose related collection was
/// deleted as the side holding its key stores it, by ID, and drops it from
/// a side that stores nothing (`detach()`, 8.3.6).
pub(super) fn detach(relation: &Relation<'_>, document: &mut Document) {
    let parent = relation.side == RelationshipSide::Parent;
    let stores_key = match relation.kind {
        RelationshipType::OneToOne => parent || relation.two_way,
        RelationshipType::OneToMany => !parent,
        RelationshipType::ManyToOne => parent,
        RelationshipType::ManyToMany => false,
    };
    if !stores_key {
        document.remove(relation.key);
        return;
    }
    if let Some(Value::Ext(Ext::Document(related))) = document.get(relation.key) {
        let id = related.id().to_owned();
        document.put(relation.key, Value::Str(id.as_str().into()));
    }
}
