//! Relationship attributes: their storage, junction collections and
//! definitions on both sides.
//!
//! PHP: `Trait\Relationships` (`skipRelationships()` and
//! `skipRelationshipsExistCheck()` are in `scopes.rs`).

use super::attributes::{write_attributes, write_indexes};
use super::retry::Rollback;
use super::{Database, METADATA};
use crate::adapter::{self, Feature, Relationships as RelationshipStorage};
use crate::attribute::{self, Attribute};
use crate::capability::Capability;
use crate::collection::{self, Collection};
use crate::document::{Document, SetType};
use crate::error::{Error, Kind, Result};
use crate::event::{Domain, Event};
use crate::index::Index;
use crate::relationship::{Relationship, RelationshipSide, RelationshipType, Update};
use crate::value::{Array, Value};

/// A step that puts back part of a relationship change, run best effort
/// (PHP's rollback closures).
enum Restore {
    /// `replaceAttribute($collection, $key, $attribute)`.
    ReplaceAttribute { collection: String, key: String, attribute: Attribute },
    /// `renameStoredAttribute($collection, $key, $new)`.
    RenameStoredAttribute { collection: String, key: String, new: String },
    /// `renameRelationshipIndex($collection, $key, $new)`.
    RenameRelationshipIndex { collection: String, key: String, new: String },
    /// `$adapter->updateRelationship(...)`.
    UpdateRelationship { collection: String, relationship: Relationship, side: RelationshipSide, update: Update },
    /// `$adapter->createRelationship(...)`.
    CreateRelationship { collection: String, relationship: Relationship },
    /// `silent(createIndex($collection, $index))`.
    CreateIndex { collection: String, index: Index },
    /// `silent(createDocument(METADATA, $junction))`.
    CreateMetadata { document: Document },
}

/// PHP's `$value ?: $fallback` of a string: empty and `"0"` are falsy.
fn or_else(value: Option<&str>, fallback: impl FnOnce() -> String) -> String {
    match value {
        Some(v) if !v.is_empty() && v != "0" => v.to_owned(),
        _ => fallback(),
    }
}

/// `getSequence()` as a string (`null` as empty).
fn sequence(document: &Document) -> String {
    document.sequence().map(crate::value::to_text).unwrap_or_default()
}

impl Database {
    /// The adapter's relationship storage (`adapterHasFeature(Feature\Relationships::class)`).
    ///
    /// Fails ([`crate::Kind::Database`]) with `Adapter does not support
    /// relationships`.
    fn relationship_storage(&self) -> Result<&dyn RelationshipStorage> {
        let adapter = &self.inner.adapter;
        match adapter.relationships() {
            Some(storage) if adapter.has_feature(Feature::Relationships) => Ok(storage),
            _ => Err(Error::database("Adapter does not support relationships")),
        }
    }

    /// Creates a relationship from `collection` (`createRelationship()`):
    /// the attribute on both sides, a junction collection for many-to-many,
    /// the storage, then the indexes. A key left unset is derived from the
    /// id of the collection on the other side. Returns the relationship
    /// from the parent side, both keys resolved.
    ///
    /// Fails ([`crate::Kind::Database`]) for an adapter without
    /// relationships or a failed metadata write, ([`crate::Kind::NotFound`])
    /// for a missing collection, ([`crate::Kind::Duplicate`]) for a key
    /// taken on either side, ([`crate::Kind::Limit`]) past either
    /// collection's limits, and ([`crate::Kind::Refused`]) when the adapter
    /// does not create the relationship.
    pub async fn create_relationship(&self, collection: &str, relationship: Relationship) -> Result<Relationship> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.create_relationship(collection, relationship)).await;
        }
        self.relationship_storage()?;
        let adapter = &self.inner.adapter;

        let Some(mut collection) = self.silent(self.find_collection(collection)).await? else {
            return Err(Error::not_found("Collection not found"));
        };
        let Some(mut related) = self.silent(self.find_collection(&relationship.related_collection)).await? else {
            return Err(Error::not_found("Related collection not found"));
        };

        let collection_id = collection.id().to_owned();
        let related_id = related.id().to_owned();
        let key = or_else(relationship.key.as_deref(), || adapter::filter(&related_id));
        let two_way_key = or_else(relationship.two_way_key.as_deref(), || adapter::filter(&collection_id));
        let relationship = relationship.apply(&Update {
            key: Some(key.clone()),
            two_way_key: Some(two_way_key.clone()),
            ..Default::default()
        });
        let kind = relationship.kind;

        for attribute in collection.attributes()? {
            if attribute.key.eq_ignore_ascii_case(&key) {
                return Err(Error::duplicate("Attribute already exists"));
            }
            if let Some(existing) = &attribute.relationship
                && existing.two_way_key.as_deref().unwrap_or("").eq_ignore_ascii_case(&two_way_key)
                && existing.related_collection == related_id
            {
                return Err(Error::duplicate("Related attribute already exists"));
            }
        }

        let parent = Attribute::relationship(key.clone(), relationship.clone(), RelationshipSide::Parent)?;
        let child = Attribute::relationship(
            two_way_key.clone(),
            relationship.inverse(collection_id.clone()),
            RelationshipSide::Child,
        )?;

        self.check_attribute(&collection_id, &parent).await?;
        self.check_attribute(&related_id, &child).await?;

        let mut junction = None;
        if kind == RelationshipType::ManyToMany {
            let name = format!("_{}_{}", sequence(&collection), sequence(&related));
            let junction_attributes = vec![
                Attribute::string(key.clone(), super::LENGTH_KEY).required(true),
                Attribute::string(two_way_key.clone(), super::LENGTH_KEY).required(true),
            ];
            let junction_indexes = vec![
                Index::key(format!("_index_{key}"), [key.clone()], Vec::new(), Vec::new())?,
                Index::key(format!("_index_{two_way_key}"), [two_way_key.clone()], Vec::new(), Vec::new())?,
            ];
            let definition = Collection::create(
                name.clone(),
                "",
                junction_attributes.clone(),
                junction_indexes.clone(),
                None,
                true,
                Array::new(),
            )?;
            match self.silent(self.create_collection(definition)).await {
                Ok(_) => {}
                Err(error) if error.is_a(Kind::Duplicate) => {
                    match adapter.create_collection(&name, &junction_attributes, &junction_indexes).await {
                        // The junction's metadata and schema both survive a
                        // prior partial failure.
                        Ok(_) => {}
                        Err(error) if error.is_a(Kind::Duplicate) => {}
                        Err(error) => return Err(error),
                    }
                }
                Err(error) => return Err(error),
            }
            junction = Some(name);
        }

        let created = self.create_relationship_in_schema(&collection_id, &relationship, junction.as_deref()).await?;

        collection.set_with(collection::ATTRIBUTES, Value::from(parent.to_document()?), SetType::Append)?;
        related.set_with(collection::ATTRIBUTES, Value::from(child.to_document()?), SetType::Append)?;

        self.silent(async {
            let mut committed_failure = None;
            let written = self
                .with_retries(
                    || async {
                        self.with_transaction(|| async {
                            self.update_document(METADATA, &collection_id, collection.document().clone()).await?;
                            self.update_document(METADATA, &related_id, related.document().clone()).await?;
                            Ok(())
                        })
                        .await
                    },
                    3,
                    100,
                )
                .await;
            if let Err(error) = written {
                if !self.may_have_committed(&error) {
                    forget_attribute(&mut collection, &key)?;
                    forget_attribute(&mut related, &two_way_key)?;

                    if created {
                        self.cleanup_created_relationship(&collection_id, &relationship, junction.as_deref()).await;
                    }

                    return Err(Error::database(format!("Failed to create relationship: {}", error.message()))
                        .with_previous(error));
                }
                committed_failure = Some(error);
            }

            let index_key = format!("_index_{key}");
            let two_way_index_key = format!("_index_{two_way_key}");
            let unique = |k: &str, a: &str| Index::unique(k, [a], Vec::new(), Vec::new());
            let keyed = |k: &str, a: &str| Index::key(k, [a], Vec::new(), Vec::new());
            let indexes: Vec<(String, Index)> = match relationship.kind {
                RelationshipType::OneToOne if relationship.two_way => vec![
                    (collection_id.clone(), unique(&index_key, &key)?),
                    (related_id.clone(), unique(&two_way_index_key, &two_way_key)?),
                ],
                RelationshipType::OneToOne => vec![(collection_id.clone(), unique(&index_key, &key)?)],
                RelationshipType::OneToMany => vec![(related_id.clone(), keyed(&two_way_index_key, &two_way_key)?)],
                RelationshipType::ManyToOne => vec![(collection_id.clone(), keyed(&index_key, &key)?)],
                RelationshipType::ManyToMany => Vec::new(),
            };

            let mut indexes_created: Vec<(String, String)> = Vec::new();
            let mut failure = None;
            for (index_collection, index) in &indexes {
                if let Err(error) = self.create_index(index_collection, index.clone()).await {
                    if !self.may_have_committed(&error) {
                        failure = Some(error);
                        break;
                    }
                    committed_failure.get_or_insert(error);
                }
                indexes_created.push((index_collection.clone(), index.key.clone()));
            }

            if let Some(error) = failure {
                for (created_collection, created_key) in &indexes_created {
                    if let Err(cleanup) = self.delete_index(created_collection, created_key).await {
                        super::console_error(format!("Failed to cleanup index '{created_key}': {}", cleanup.message()));
                    }
                }

                forget_attribute(&mut collection, &key)?;
                forget_attribute(&mut related, &two_way_key)?;
                let removed = self
                    .with_transaction(|| async {
                        self.update_document(METADATA, &collection_id, collection.document().clone()).await?;
                        self.update_document(METADATA, &related_id, related.document().clone()).await?;
                        Ok(())
                    })
                    .await;
                let definitions_removed = match removed {
                    Ok(()) => true,
                    Err(cleanup) => {
                        super::console_error(format!(
                            "Failed to cleanup metadata for relationship '{key}': {}",
                            cleanup.message()
                        ));
                        self.failed_after_commit(&cleanup)
                    }
                };

                if definitions_removed {
                    self.cleanup_created_relationship(&collection_id, &relationship, junction.as_deref()).await;
                }

                return Err(Error::database(format!("Failed to create relationship indexes: {}", error.message()))
                    .with_previous(error));
            }

            match committed_failure {
                Some(error) => Err(error),
                None => Ok(()),
            }
        })
        .await?;

        self.trigger(Event::AttributeCreate, || Domain::AttributeCreated {
            collection: collection_id.clone(),
            attribute: parent.clone(),
        })
        .await?;

        Ok(relationship)
    }

    /// Updates the relationship stored under `key` on `collection`, from
    /// either of its sides (`updateRelationship()`): the update is read from
    /// that side, its `key` renaming `key` and its `two_way_key` the key on
    /// the related collection. Returns the relationship from the side of
    /// `collection`.
    ///
    /// Fails ([`crate::Kind::Database`]) for an adapter without
    /// relationships or a failed rename, ([`crate::Kind::Duplicate`]) for a
    /// key taken, ([`crate::Kind::NotFound`]) for a missing relationship,
    /// and ([`crate::Kind::Refused`]) when the adapter does not rename the
    /// relationship's columns.
    pub async fn update_relationship(&self, collection: &str, key: &str, update: Update) -> Result<Relationship> {
        if let Some(parts) = self.mirror_parts() {
            return Box::pin(parts.update_relationship(collection, key, update)).await;
        }
        let storage = self.relationship_storage()?;

        let collection = self.get_collection(collection).await?;
        let attributes = collection.attributes()?.to_vec();

        if let Some(new_key) = &update.key
            && attributes.iter().any(|a| a.key == *new_key)
        {
            return Err(Error::duplicate("Relationship already exists"));
        }

        let attribute = relationship_attribute(&attributes, key);
        let (Some(attribute), Some(current), Some(side)) =
            (attribute.cloned(), attribute.and_then(|a| a.relationship.clone()), attribute.and_then(|a| a.side))
        else {
            return Err(Error::not_found("Relationship not found"));
        };

        if update.key.is_none()
            && update.two_way_key.is_none()
            && update.two_way.is_none()
            && update.on_delete.is_none()
        {
            return Ok(current);
        }

        let collection_id = collection.id().to_owned();
        let related = self.get_collection(&current.related_collection).await?;
        let related_id = related.id().to_owned();
        let related_attributes = related.attributes()?.to_vec();
        let old_two_way_key = current.two_way_key.clone().unwrap_or_default();

        if let Some(new_two_way_key) = &update.two_way_key
            && related_attributes.iter().any(|a| a.key == *new_two_way_key)
        {
            return Err(Error::duplicate("Related attribute already exists"));
        }

        let Some(inverse) = relationship_attribute(&related_attributes, &old_two_way_key).cloned() else {
            return Err(Error::not_found("Attribute not found"));
        };
        let Some(inverse_side) = inverse.side else {
            return Err(Error::not_found("Attribute not found"));
        };

        let updated = current.apply(&update);
        let new_key = update.key.clone().unwrap_or_else(|| key.to_owned());
        let new_two_way_key = update.two_way_key.clone().unwrap_or_else(|| old_two_way_key.clone());
        let altering = new_key != key || new_two_way_key != old_two_way_key;
        let renamed = updated.apply(&Update {
            key: Some(new_key.clone()),
            two_way_key: Some(new_two_way_key.clone()),
            ..Default::default()
        });
        let junction =
            (current.kind == RelationshipType::ManyToMany).then(|| junction_collection(&collection, &related, side));

        let rename = Update {
            key: Some(new_key.clone()),
            two_way_key: Some(new_two_way_key.clone()),
            two_way: Some(updated.two_way),
            on_delete: None,
        };
        let renames = relationship_index_renames(
            current.kind,
            side,
            updated.two_way,
            &collection_id,
            &related_id,
            junction.as_deref(),
            key,
            &new_key,
            &old_two_way_key,
            &new_two_way_key,
        );

        let mut adapter_updated = false;
        if altering {
            match storage.update_relationship(&collection_id, &current, side, &rename).await {
                Ok(done) => adapter_updated = done,
                Err(error) => {
                    let remaining = self.relationship_columns_left(&renames).await?;
                    let retried: Result<bool> = async {
                        let remaining = match remaining {
                            Some(r) if r.len() != renames.len() => r,
                            _ => return Err(error),
                        };
                        if remaining.is_empty() {
                            return Ok(true);
                        }
                        let own = junction.as_deref().unwrap_or(&collection_id);
                        let other = junction.as_deref().unwrap_or(&related_id);
                        let partial = current.apply(&Update {
                            key: Some(if rename_left(&remaining, own, key, &new_key) {
                                key.to_owned()
                            } else {
                                new_key.clone()
                            }),
                            two_way_key: Some(if rename_left(&remaining, other, &old_two_way_key, &new_two_way_key) {
                                old_two_way_key.clone()
                            } else {
                                new_two_way_key.clone()
                            }),
                            ..Default::default()
                        });
                        storage.update_relationship(&collection_id, &partial, side, &rename).await
                    }
                    .await;
                    match retried {
                        Ok(done) => adapter_updated = done,
                        Err(failure) if failure.is_a(Kind::Duplicate) || failure.is_a(Kind::NotFound) => {
                            return Err(failure);
                        }
                        Err(failure) => {
                            return Err(Error::database(format!(
                                "Failed to update relationship '{key}': {}",
                                failure.message()
                            ))
                            .with_previous(failure));
                        }
                    }
                }
            }

            if !adapter_updated {
                return Err(Error::refused(format!("Failed to update relationship '{key}'")));
            }
        }

        let parent_after = Attribute::relationship(new_key.clone(), renamed.clone(), side)?;
        let inverse_after =
            Attribute::relationship(new_two_way_key.clone(), renamed.inverse(collection_id.clone()), inverse_side)?;

        let mut updated_attributes: Vec<(String, Attribute)> = Vec::new();
        let mut restores: Vec<Restore> = Vec::new();

        let replaced: Result<()> = async {
            self.replace_attribute(&collection_id, key, parent_after.clone()).await?;
            updated_attributes.push((collection_id.clone(), parent_after.clone()));
            restores.push(Restore::ReplaceAttribute {
                collection: collection_id.clone(),
                key: new_key.clone(),
                attribute: attribute.clone(),
            });

            self.replace_attribute(&related_id, &old_two_way_key, inverse_after.clone()).await?;
            updated_attributes.push((related_id.clone(), inverse_after.clone()));
            restores.push(Restore::ReplaceAttribute {
                collection: related_id.clone(),
                key: new_two_way_key.clone(),
                attribute: inverse.clone(),
            });

            if let Some(junction) = &junction {
                updated_attributes
                    .push((junction.clone(), self.rename_stored_attribute(junction, key, &new_key).await?));
                restores.push(Restore::RenameStoredAttribute {
                    collection: junction.clone(),
                    key: new_key.clone(),
                    new: key.to_owned(),
                });
                updated_attributes.push((
                    junction.clone(),
                    self.rename_stored_attribute(junction, &old_two_way_key, &new_two_way_key).await?,
                ));
                restores.push(Restore::RenameStoredAttribute {
                    collection: junction.clone(),
                    key: new_two_way_key.clone(),
                    new: old_two_way_key.clone(),
                });

                self.with_retries(|| self.purge_cached_collection(junction), 3, 100).await?;
            }
            Ok(())
        }
        .await;

        if let Err(error) = replaced {
            self.best_effort(restores).await;

            if adapter_updated {
                self.best_effort(vec![Restore::UpdateRelationship {
                    collection: collection_id.clone(),
                    relationship: renamed.clone(),
                    side,
                    update: Update {
                        key: Some(key.to_owned()),
                        two_way_key: Some(old_two_way_key.clone()),
                        two_way: Some(updated.two_way),
                        on_delete: None,
                    },
                }])
                .await;
            }

            return Err(error);
        }

        let mut renames_completed: Vec<(String, String, String)> = Vec::new();
        let mut failure = None;
        for (indexed_collection, from, to) in &renames {
            if let Err(error) = self.rename_relationship_index(indexed_collection, from, to).await {
                failure = Some(error);
                break;
            }
            renames_completed.push((indexed_collection.clone(), to.clone(), from.clone()));
        }

        if let Some(error) = failure {
            if adapter_updated {
                self.best_effort(vec![Restore::UpdateRelationship {
                    collection: collection_id.clone(),
                    relationship: renamed.clone(),
                    side,
                    update: Update {
                        key: Some(key.to_owned()),
                        two_way_key: Some(old_two_way_key.clone()),
                        two_way: Some(current.two_way),
                        on_delete: None,
                    },
                }])
                .await;
            }

            self.best_effort(
                renames_completed
                    .into_iter()
                    .rev()
                    .map(|(collection, key, new)| Restore::RenameRelationshipIndex { collection, key, new })
                    .collect(),
            )
            .await;
            self.best_effort(restores).await;

            return Err(Error::database(format!(
                "Failed to update relationship indexes for '{key}': {}",
                error.message()
            ))
            .with_previous(error));
        }

        self.with_retries(|| self.purge_cached_collection(&collection_id), 3, 100).await?;
        self.with_retries(|| self.purge_cached_collection(&related_id), 3, 100).await?;

        let listeners = self.listens(Event::AttributeUpdate);
        if !listeners.is_empty() {
            for (updated_collection, updated_attribute) in updated_attributes {
                self.dispatch(
                    &Domain::AttributeUpdated { collection: updated_collection, attribute: updated_attribute },
                    &listeners,
                )
                .await?;
            }
        }

        Ok(renamed)
    }

    /// Deletes the relationship stored under `key` on `collection`, from
    /// either of its sides, and its inverse (`deleteRelationship()`).
    ///
    /// Fails ([`crate::Kind::Database`]) for an adapter without
    /// relationships or a failed metadata write, ([`crate::Kind::NotFound`])
    /// for a missing relationship, and ([`crate::Kind::Refused`]) when the
    /// adapter does not drop the relationship.
    pub async fn delete_relationship(&self, collection: &str, key: &str) -> Result<()> {
        if let Some(parts) = self.mirror_parts() {
            return crate::mirror::in_order!(&*parts, "deleteRelationship", |db| db
                .delete_relationship(collection, key));
        }
        self.relationship_storage()?;

        let collection = self.silent(self.get_collection(collection)).await?;
        let found = relationship_attribute(collection.attributes()?, key);
        let (Some(attribute), Some(relationship), Some(side)) =
            (found.cloned(), found.and_then(|a| a.relationship.clone()), found.and_then(|a| a.side))
        else {
            return Err(Error::not_found("Relationship not found"));
        };

        let related = self.silent(self.get_collection(&relationship.related_collection)).await?;
        let two_way_key = relationship.two_way_key.clone().unwrap_or_default();
        let collection_id = collection.id().to_owned();
        let related_id = related.id().to_owned();

        let collection_attributes = attribute_documents(collection.attributes()?, key)?;
        let related_attributes = attribute_documents(related.attributes()?, &two_way_key)?;

        let mut deleted_indexes: Vec<(String, Index)> = Vec::new();
        let mut deleted_junction: Option<Document> = None;

        self.silent(async {
            if relationship.kind == RelationshipType::ManyToMany {
                let junction = junction_collection(&collection, &related, side);

                deleted_junction = Some(self.silent(self.get_document(METADATA, &junction, Vec::new(), false)).await?);
                self.delete_document(METADATA, &junction).await?;

                return Ok(());
            }

            for (index_collection, index) in relationship_indexes(
                relationship.kind,
                side,
                relationship.two_way,
                &collection_id,
                &related_id,
                key,
                &two_way_key,
            )? {
                self.delete_index(&index_collection, &index.key).await?;
                deleted_indexes.push((index_collection, index));
            }
            Ok::<(), Error>(())
        })
        .await?;

        let mut collection = self.silent(self.get_collection(&collection_id)).await?;
        let mut related = self.silent(self.get_collection(&related_id)).await?;
        collection.set(collection::ATTRIBUTES, Value::Array(Array::from_list(collection_attributes)))?;
        related.set(collection::ATTRIBUTES, Value::Array(Array::from_list(related_attributes)))?;

        let should_rollback = match self.delete_relationship_from_schema(&collection_id, &relationship, side).await {
            Ok(dropped) => dropped,
            Err(error) => {
                self.best_effort(relationship_definition_restores(&deleted_indexes, deleted_junction.as_ref())).await;
                return Err(error);
            }
        };

        let written = self
            .with_retries(
                || async {
                    self.silent(self.with_transaction(|| async {
                        self.update_document(METADATA, &collection_id, collection.document().clone()).await?;
                        self.update_document(METADATA, &related_id, related.document().clone()).await?;
                        Ok(())
                    }))
                    .await
                },
                3,
                100,
            )
            .await;

        if let Err(error) = written {
            let mut steps = Vec::new();
            if should_rollback {
                steps.push(if side == RelationshipSide::Parent {
                    Restore::CreateRelationship {
                        collection: collection_id.clone(),
                        relationship: relationship.clone(),
                    }
                } else {
                    Restore::CreateRelationship {
                        collection: related_id.clone(),
                        relationship: relationship.inverse(collection_id.clone()),
                    }
                });
            }
            steps.extend(relationship_definition_restores(&deleted_indexes, deleted_junction.as_ref()));
            self.best_effort(steps).await;

            return Err(Error::database(format!(
                "Failed to persist metadata after retries for relationship deletion '{key}': {}",
                error.message()
            ))
            .with_previous(error));
        }

        self.with_retries(|| self.purge_cached_collection(&collection_id), 3, 100).await?;
        self.with_retries(|| self.purge_cached_collection(&related_id), 3, 100).await?;

        self.trigger(Event::AttributeDelete, || Domain::AttributeDeleted {
            collection: collection_id.clone(),
            attribute: attribute.clone(),
        })
        .await?;

        Ok(())
    }

    /// Runs each step of a rollback, carrying on past a step that fails
    /// (`bestEffort()`).
    async fn best_effort(&self, steps: Vec<Restore>) {
        for step in steps {
            // A rollback step that fails leaves the rest to run.
            let _ = self.restore(step).await;
        }
    }

    /// Runs one rollback step.
    async fn restore(&self, step: Restore) -> Result<()> {
        match step {
            Restore::ReplaceAttribute { collection, key, attribute } => {
                self.replace_attribute(&collection, &key, attribute).await.map(|_| ())
            }
            Restore::RenameStoredAttribute { collection, key, new } => {
                self.rename_stored_attribute(&collection, &key, &new).await.map(|_| ())
            }
            Restore::RenameRelationshipIndex { collection, key, new } => {
                self.rename_relationship_index(&collection, &key, &new).await
            }
            Restore::UpdateRelationship { collection, relationship, side, update } => self
                .relationship_storage()?
                .update_relationship(&collection, &relationship, side, &update)
                .await
                .map(|_| ()),
            Restore::CreateRelationship { collection, relationship } => {
                self.relationship_storage()?.create_relationship(&collection, &relationship).await.map(|_| ())
            }
            Restore::CreateIndex { collection, index } => {
                Box::pin(self.silent(self.create_index(&collection, index))).await.map(|_| ())
            }
            Restore::CreateMetadata { document } => {
                self.silent(self.create_document(METADATA, document)).await.map(|_| ())
            }
        }
    }

    /// Drops the storage of a relationship created by
    /// [`Database::create_relationship`] whose metadata could not be
    /// written, with retries (`cleanupRelationship()`).
    ///
    /// Fails as the last attempt does.
    async fn cleanup_relationship(&self, collection: &str, relationship: &Relationship, attempts: u32) -> Result<()> {
        let storage = self.relationship_storage()?;
        self.cleanup(
            || async {
                storage.delete_relationship(collection, relationship, RelationshipSide::Parent).await.map(|_| ())
            },
            "relationship",
            relationship.key.as_deref().unwrap_or(""),
            attempts,
        )
        .await
    }

    /// Creates a relationship's storage (`createRelationshipInSchema()`):
    /// whether this call created it, `false` when the schema already held
    /// it.
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not create it;
    /// its junction collection is dropped.
    async fn create_relationship_in_schema(
        &self,
        collection: &str,
        relationship: &Relationship,
        junction: Option<&str>,
    ) -> Result<bool> {
        let storage = self.relationship_storage()?;
        match storage.create_relationship(collection, relationship).await {
            Ok(true) => return Ok(true),
            Ok(false) => {}
            // The metadata checks found no such relationship, so the schema
            // holds an orphan of a prior partial failure: keep it and write
            // the metadata.
            Err(error) if error.is_a(Kind::Duplicate) => return Ok(false),
            Err(error) => return Err(error),
        }

        if let Some(junction) = junction
            && let Err(error) = self.silent(self.cleanup_collection(junction, 3)).await
        {
            super::console_error(format!("Failed to cleanup junction collection '{junction}': {}", error.message()));
        }

        Err(Error::refused("Failed to create relationship"))
    }

    /// Drops a relationship's storage (`deleteRelationshipFromSchema()`):
    /// whether this call dropped it, `false` when the schema no longer held
    /// it.
    ///
    /// Fails ([`crate::Kind::Refused`]) when the adapter does not drop it.
    async fn delete_relationship_from_schema(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        let storage = self.relationship_storage()?;
        match storage.delete_relationship(collection, relationship, side).await {
            Ok(true) => Ok(true),
            Ok(false) => Err(Error::refused("Failed to delete relationship")),
            // The relationship is already absent from the schema.
            Err(error) if error.is_a(Kind::NotFound) => Ok(false),
            Err(error) => Err(error),
        }
    }

    /// Drops what [`Database::create_relationship`] created for a
    /// relationship whose metadata it then had to remove, logging failures
    /// (`cleanupCreatedRelationship()`).
    async fn cleanup_created_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        junction: Option<&str>,
    ) {
        if let Err(error) = self.cleanup_relationship(collection, relationship, 3).await {
            super::console_error(format!(
                "Failed to cleanup relationship '{}': {}",
                relationship.key.as_deref().unwrap_or(""),
                error.message()
            ));
        }

        let Some(junction) = junction else { return };

        if let Err(error) = self.cleanup_collection(junction, 3).await {
            super::console_error(format!("Failed to cleanup junction collection '{junction}': {}", error.message()));
        }
    }

    /// Points the relationship index on `key` at `new_key` and renames it
    /// after it (`renameRelationshipIndex()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing index.
    async fn rename_relationship_index(&self, collection: &str, key: &str, new_key: &str) -> Result<()> {
        let mut definition = self.silent(self.get_collection(collection)).await?;
        let index_key = format!("_index_{key}");

        let mut indexes = definition.indexes()?.to_vec();
        let Some(position) = indexes.iter().position(|i| i.key == index_key) else {
            return Err(Error::not_found("Index not found"));
        };
        indexes[position] = super::attributes::with_indexed_attributes(&indexes[position], &[new_key.to_owned()])?;

        write_indexes(&mut definition, &indexes)?;
        self.update_metadata(&definition, Rollback::None, false, &format!("index metadata update '{index_key}'"))
            .await?;
        self.with_retries(|| self.purge_cached_collection(collection), 3, 100).await?;

        Box::pin(self.silent(self.rename_index(collection, &index_key, &format!("_index_{new_key}")))).await
    }

    /// Stores `attribute` in place of the attribute stored under `key` on
    /// `collection` (`replaceAttribute()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing attribute.
    async fn replace_attribute(&self, collection: &str, key: &str, attribute: Attribute) -> Result<Attribute> {
        let mut definition = self.silent(self.get_collection(collection)).await?;

        let mut attributes = definition.attributes()?.to_vec();
        let Some(position) = attributes.iter().position(|a| a.key == key) else {
            return Err(Error::not_found("Attribute not found"));
        };
        attributes[position] = attribute.clone();

        write_attributes(&mut definition, &attributes)?;
        self.update_metadata(&definition, Rollback::None, false, &format!("attribute metadata update '{key}'")).await?;
        self.with_retries(|| self.purge_cached_collection(collection), 3, 100).await?;

        Ok(attribute)
    }

    /// Renames the attribute stored under `key` on `collection`, a junction
    /// collection, to `new_key` (`renameStoredAttribute()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing attribute.
    async fn rename_stored_attribute(&self, collection: &str, key: &str, new_key: &str) -> Result<Attribute> {
        let definition = self.silent(self.get_collection(collection)).await?;
        let stored = definition.attributes()?.iter().find(|a| a.key == key).cloned();
        match stored {
            Some(stored) => {
                let renamed =
                    stored.apply(&attribute::Update { key: Some(new_key.to_owned()), ..Default::default() })?;
                self.replace_attribute(collection, key, renamed).await
            }
            None => Err(Error::not_found("Attribute not found")),
        }
    }

    /// The relationship columns an earlier attempt left under their old
    /// names (`relationshipColumnsLeft()`), when each other column is in the
    /// engine's schema under its new name and no longer under its old one,
    /// so a retry renames only what is left; `None` when a column is under
    /// both names or neither, or the schema cannot be read.
    async fn relationship_columns_left(
        &self,
        renames: &[(String, String, String)],
    ) -> Result<Option<Vec<(String, String, String)>>> {
        if renames.is_empty() || !self.inner.adapter.supports(Capability::SchemaIntrospection) {
            return Ok(None);
        }

        let mut left = Vec::new();
        for rename in renames {
            let (renamed_collection, from, to) = rename;
            let moved = self.has_schema_column(renamed_collection, to).await?;
            if moved == self.has_schema_column(renamed_collection, from).await? {
                return Ok(None);
            }
            if !moved {
                left.push(rename.clone());
            }
        }

        Ok(Some(left))
    }

    /// Whether the engine's schema of `collection` has a column for `key`
    /// (`hasSchemaColumn()`).
    async fn has_schema_column(&self, collection: &str, key: &str) -> Result<bool> {
        let filtered = adapter::filter(key).to_ascii_lowercase();
        Ok(self.schema_attributes(collection).await?.iter().any(|c| c.name.to_ascii_lowercase() == filtered))
    }
}

/// The steps that put back what [`Database::delete_relationship`] removed
/// before dropping the relationship: its indexes, or the definition of its
/// junction collection (`relationshipDefinitionRestores()`).
fn relationship_definition_restores(
    deleted_indexes: &[(String, Index)],
    deleted_junction: Option<&Document>,
) -> Vec<Restore> {
    let mut restores: Vec<Restore> = deleted_indexes
        .iter()
        .map(|(collection, index)| Restore::CreateIndex { collection: collection.clone(), index: index.clone() })
        .collect();
    if let Some(junction) = deleted_junction
        && !junction.is_empty()
    {
        restores.push(Restore::CreateMetadata { document: junction.clone() });
    }
    restores
}

/// The junction collection of a many-to-many relationship seen from `side`
/// (`getJunctionCollection()`).
fn junction_collection(collection: &Document, related: &Document, side: RelationshipSide) -> String {
    if side == RelationshipSide::Parent {
        format!("_{}_{}", sequence(collection), sequence(related))
    } else {
        format!("_{}_{}", sequence(related), sequence(collection))
    }
}

/// The indexes [`Database::create_relationship`] creates for a
/// relationship, by the collection each is on, seen from `side`
/// (`relationshipIndexes()`).
fn relationship_indexes(
    kind: RelationshipType,
    side: RelationshipSide,
    two_way: bool,
    collection: &str,
    related_collection: &str,
    key: &str,
    two_way_key: &str,
) -> Result<Vec<(String, Index)>> {
    let parent = side == RelationshipSide::Parent;
    let own = (collection, format!("_index_{key}"), key);
    let other = (related_collection, format!("_index_{two_way_key}"), two_way_key);

    let (unique, keyed) = match kind {
        RelationshipType::OneToOne => (
            if two_way {
                if parent { vec![own, other] } else { vec![other, own] }
            } else {
                vec![if parent { own } else { other }]
            },
            Vec::new(),
        ),
        RelationshipType::OneToMany => (Vec::new(), vec![if parent { other } else { own }]),
        RelationshipType::ManyToOne => (Vec::new(), vec![if parent { own } else { other }]),
        RelationshipType::ManyToMany => (Vec::new(), Vec::new()),
    };

    let mut indexes = Vec::new();
    for (index_collection, index_key, indexed) in unique {
        indexes.push((index_collection.to_owned(), Index::unique(index_key, [indexed], Vec::new(), Vec::new())?));
    }
    for (index_collection, index_key, indexed) in keyed {
        indexes.push((index_collection.to_owned(), Index::key(index_key, [indexed], Vec::new(), Vec::new())?));
    }
    Ok(indexes)
}

/// The relationship indexes a rename of its keys renames, as
/// `(collection, from key, to key)` (`relationshipIndexRenames()`).
#[allow(clippy::too_many_arguments)]
fn relationship_index_renames(
    kind: RelationshipType,
    side: RelationshipSide,
    two_way: bool,
    collection: &str,
    related_collection: &str,
    junction: Option<&str>,
    key: &str,
    new_key: &str,
    two_way_key: &str,
    new_two_way_key: &str,
) -> Vec<(String, String, String)> {
    let triple = |c: &str, from: &str, to: &str| (c.to_owned(), from.to_owned(), to.to_owned());
    let own: Vec<_> = if key != new_key { vec![triple(collection, key, new_key)] } else { Vec::new() };
    let other: Vec<_> = if two_way_key != new_two_way_key {
        vec![triple(related_collection, two_way_key, new_two_way_key)]
    } else {
        Vec::new()
    };
    let parent = side == RelationshipSide::Parent;

    match kind {
        RelationshipType::OneToOne if two_way => own.into_iter().chain(other).collect(),
        RelationshipType::OneToOne if parent => own,
        RelationshipType::OneToOne => other,
        RelationshipType::OneToMany => {
            if parent {
                other
            } else {
                own
            }
        }
        RelationshipType::ManyToOne => {
            if parent {
                own
            } else {
                other
            }
        }
        RelationshipType::ManyToMany => match junction {
            None => Vec::new(),
            Some(junction) => {
                let mut renames = Vec::new();
                if key != new_key {
                    renames.push(triple(junction, key, new_key));
                }
                if two_way_key != new_two_way_key {
                    renames.push(triple(junction, two_way_key, new_two_way_key));
                }
                renames
            }
        },
    }
}

/// Whether `left` still holds the rename (`renameLeft()`).
fn rename_left(left: &[(String, String, String)], collection: &str, from: &str, to: &str) -> bool {
    left.iter().any(|(c, f, t)| c == collection && f == from && t == to)
}

/// Removes the attribute stored under `key` from the collection's
/// metadata, without writing it (`forgetAttribute()`).
fn forget_attribute(collection: &mut Collection, key: &str) -> Result<()> {
    let documents = attribute_documents(collection.attributes()?, key)?;
    collection.set(collection::ATTRIBUTES, Value::Array(Array::from_list(documents)))?;
    Ok(())
}

/// The stored form of `attributes`, the first one under `without` left out
/// (`attributeDocuments()`).
fn attribute_documents(attributes: &[Attribute], without: &str) -> Result<Vec<Value>> {
    let mut documents = Vec::with_capacity(attributes.len());
    let mut removed = false;
    for attribute in attributes {
        if !removed && attribute.key == without {
            removed = true;
            continue;
        }
        documents.push(Value::from(attribute.to_document()?));
    }
    Ok(documents)
}

/// The relationship attribute stored under `key`; `None` when the
/// attribute under `key` is not a relationship (`relationshipAttribute()`).
fn relationship_attribute<'a>(attributes: &'a [Attribute], key: &str) -> Option<&'a Attribute> {
    let attribute = attributes.iter().find(|a| a.key == key)?;
    attribute.relationship.as_ref().map(|_| attribute)
}
