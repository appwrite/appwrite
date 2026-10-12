//! Relationships: relationship attributes, populating related documents,
//! writing and deleting through relationships, and queries on related
//! attributes.
//!
//! Area: relationships.
//!
//! PHP `Document`s are shared objects: populating or relating a document
//! mutates every reference to it. Here documents are values, so a helper
//! that mutates documents takes them by `&mut` or returns them.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the relationships area is implemented.
#![allow(unused_variables, dead_code)]

use super::{Database, Selects};
use crate::document::Document;
use crate::error::Result;
use crate::operator::Operator;
use crate::query::Query;
use crate::value::{Value, to_text};

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
        todo!("relationships")
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
        todo!("relationships")
    }

    /// Deletes relationship `id` of `collection` (both sides, and the
    /// junction collection).
    ///
    /// PHP: `Database::deleteRelationship()`.
    pub async fn delete_relationship(&self, collection: &str, id: &str) -> Result<bool> {
        todo!("relationships")
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
        todo!("relationships")
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
        todo!("relationships")
    }

    /// Populates one relationship of `documents` by its kind; returns the
    /// related documents fetched (for the next level).
    ///
    /// PHP: `Database::populateSingleRelationshipBatch()` (private).
    pub(crate) async fn populate_single_relationship_batch(
        &self,
        documents: &mut [Document],
        relationship: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// One-to-one population.
    ///
    /// PHP: `Database::populateOneToOneRelationshipsBatch()` (private).
    pub(crate) async fn populate_one_to_one_relationships_batch(
        &self,
        documents: &mut [Document],
        relationship: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// One-to-many population.
    ///
    /// PHP: `Database::populateOneToManyRelationshipsBatch()` (private).
    pub(crate) async fn populate_one_to_many_relationships_batch(
        &self,
        documents: &mut [Document],
        relationship: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// Many-to-one population.
    ///
    /// PHP: `Database::populateManyToOneRelationshipsBatch()` (private).
    pub(crate) async fn populate_many_to_one_relationships_batch(
        &self,
        documents: &mut [Document],
        relationship: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// Many-to-many population (through the junction collection).
    ///
    /// PHP: `Database::populateManyToManyRelationshipsBatch()` (private).
    pub(crate) async fn populate_many_to_many_relationships_batch(
        &self,
        documents: &mut [Document],
        relationship: &Document,
        queries: Vec<Query>,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    // ----- Writing -----

    /// Creates or links the related documents `document` carries in its
    /// relationship attributes, and returns `document` with those
    /// attributes reduced to what its own table stores.
    ///
    /// PHP: `Database::createDocumentRelationships()` (private). Called by
    /// `create_document(s)`, `upsert_documents*`.
    pub(crate) async fn create_document_relationships(
        &self,
        collection: &Document,
        document: Document,
    ) -> Result<Document> {
        todo!("relationships")
    }

    /// Creates or updates `relation` and links it to `document` through
    /// relationship `key`; returns the related document's id.
    ///
    /// PHP: `Database::relateDocuments()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn relate_documents(
        &self,
        collection: &Document,
        related_collection: &Document,
        key: &str,
        document: &Document,
        relation: Document,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<String> {
        todo!("relationships")
    }

    /// Links document `document_id` to existing document `relation_id`
    /// through relationship `key`.
    ///
    /// PHP: `Database::relateDocumentsById()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn relate_documents_by_id(
        &self,
        collection: &Document,
        related_collection: &Document,
        key: &str,
        document_id: &str,
        relation_id: &str,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<()> {
        todo!("relationships")
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
        document: Document,
    ) -> Result<Document> {
        todo!("relationships")
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
        existing_ids: &[String],
    ) -> Result<Vec<Value>> {
        todo!("relationships")
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
        todo!("relationships")
    }

    /// The `restrict` rule: fails when related documents exist.
    ///
    /// PHP: `Database::deleteRestrict()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn delete_restrict(
        &self,
        related_collection: &Document,
        document: &Document,
        value: &Value,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<()> {
        todo!("relationships")
    }

    /// The documents of `related_collection` whose `two_way_key` points at
    /// `document`.
    ///
    /// PHP: `Database::findReferencingDocuments()` (private).
    pub(crate) async fn find_referencing_documents(
        &self,
        related_collection: &Document,
        document: &Document,
        two_way_key: &str,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// The `setNull` rule: unlinks related documents; returns the documents
    /// written.
    ///
    /// PHP: `Database::deleteSetNull()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn delete_set_null(
        &self,
        collection: &Document,
        related_collection: &Document,
        document: &Document,
        relation_type: &str,
        two_way: bool,
        two_way_key: &str,
        side: &str,
    ) -> Result<Vec<Document>> {
        todo!("relationships")
    }

    /// The `cascade` rule: deletes related documents.
    ///
    /// PHP: `Database::deleteCascade()` (private).
    #[allow(clippy::too_many_arguments)]
    pub(crate) async fn delete_cascade(
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
        todo!("relationships")
    }

    // ----- Relationship queries -----

    /// The nested selections of `select` queries on relationship paths
    /// (`author.name`), by relationship key.
    ///
    /// PHP: `Database::processRelationshipQueries()` (private). Called by
    /// `get_document`, `find`.
    pub(crate) fn process_relationship_queries(
        &self,
        relationships: &[Document],
        queries: &[Query],
    ) -> Result<Selects> {
        todo!("relationships")
    }

    /// The ids of `start_collection`'s documents matching queries on
    /// multi-level relationship paths (`project.employee.company.name`),
    /// resolved from the deepest level up; `None` when nothing matches.
    ///
    /// PHP: `Database::processNestedRelationshipPath()` (private).
    pub(crate) async fn process_nested_relationship_path(
        &self,
        start_collection: &str,
        queries: &[Query],
    ) -> Result<Option<Vec<String>>> {
        todo!("relationships")
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
        todo!("relationships")
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
    ) -> Result<Option<(String, Vec<String>)>> {
        todo!("relationships")
    }
}
