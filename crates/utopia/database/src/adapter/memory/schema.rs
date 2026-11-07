//! Databases, collections, attributes, relationship fields and indexes.
//!
//! PHP: `Memory::create`, `update`, `exists`, `collectionExists`, `list`,
//! `delete`, `createCollection`, `deleteCollection`, `createAttribute(s)`,
//! `updateAttribute`, `deleteAttribute`, `renameAttribute`,
//! `createRelationship`, `updateRelationship`, `deleteRelationship` (with
//! `registerRelationshipField`, `renameDocumentField`, `dropDocumentField`,
//! `resolveJunctionCollection`), `renameIndex`, `createIndex`,
//! `deleteIndex`, `getSizeOfCollection`, `attributeEntry`, `indexEntry`.

use utopia_query::schema::{ColumnType, IndexType};

use super::store::{self, k, key_of, key_value, s};
use super::{Ctx, Store, filter};
use crate::attribute::Attribute;
use crate::database::METADATA;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::index::Index;
use crate::relationship::{Relationship, RelationshipSide, RelationshipType, Update};
use crate::value::{Array, ArrayKey, Str, Value};

/// The five tables of the store a collection has an entry in, by position
/// (data, permissions, by document, by permission, unique hashes).
const TABLES: usize = 5;

impl Store {
    /// `key($collection)`: `<database>.<namespace>_<collection>`.
    pub(crate) fn key(ctx: &Ctx, collection: &str) -> ArrayKey {
        k(&format!("{}.{}_{}", ctx.database, ctx.namespace, filter(collection)))
    }

    /// Whether the collection exists (`isset($this->data[$key])`).
    pub(crate) fn has(&self, key: &ArrayKey) -> bool {
        store::isset(&self.data, std::slice::from_ref(key))
    }

    fn table(&mut self, i: usize) -> &mut Array {
        match i {
            0 => &mut self.data,
            1 => &mut self.permissions,
            2 => &mut self.by_document,
            3 => &mut self.by_permission,
            _ => &mut self.hashes,
        }
    }

    fn table_ref(&self, i: usize) -> &Array {
        match i {
            0 => &self.data,
            1 => &self.permissions,
            2 => &self.by_document,
            3 => &self.by_permission,
            _ => &self.hashes,
        }
    }

    /// `create($name)`.
    pub(crate) fn create(&mut self, ctx: &Ctx, name: &str) {
        let name = k(name);
        if !store::isset(&self.databases, std::slice::from_ref(&name)) {
            self.databases.set(name.clone(), Value::Array(Array::new()));
            self.journal(ctx, move |s: &mut Store| {
                s.databases.remove(&name);
            });
        }
    }

    /// `moveCollection($from, $to)`: every table's entry moved.
    fn move_collection(&mut self, from: &Value, to: &Value) {
        if from.strict_eq(to) {
            return;
        }
        let (from, to) = (key_of(from), key_of(to));
        for i in 0..TABLES {
            let table = self.table(i);
            if let Some(v) = table.get(&from).cloned() {
                table.set(to.clone(), v);
                table.remove(&from);
            }
        }
    }

    /// `update($name, $new)`: the database renamed, its collections' storage
    /// keys with it.
    pub(crate) fn update(&mut self, ctx: &Ctx, name: &str, new: &str) -> Result<bool> {
        if ctx.shared {
            return Err(Error::database("Cannot rename a database while shared tables are enabled"));
        }
        let (name_key, new_key) = (k(name), k(new));
        if !store::isset(&self.databases, std::slice::from_ref(&name_key)) {
            return Err(Error::not_found("Database not found"));
        }
        if store::isset(&self.databases, std::slice::from_ref(&new_key)) {
            return Err(Error::duplicate("Database already exists"));
        }
        let previous = match self.databases.get(&name_key) {
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        };
        let prefix = format!("{name}.");
        let mut moved = Array::new();
        for (slot, collection_key) in previous.iter() {
            let text = collection_key.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default();
            let target = match text.strip_prefix(prefix.as_bytes()) {
                Some(rest) => {
                    let mut t = new.as_bytes().to_vec();
                    t.push(b'.');
                    t.extend_from_slice(rest);
                    Value::Str(Str::from(t))
                }
                None => collection_key.clone(),
            };
            self.move_collection(collection_key, &target);
            moved.set(slot.to_owned(), target);
        }
        self.databases.remove(&name_key);
        self.databases.set(new_key.clone(), Value::Array(moved.clone()));
        self.journal(ctx, move |s: &mut Store| {
            for (slot, collection_key) in previous.iter() {
                let from = moved.get(&slot.to_owned()).cloned().unwrap_or(Value::Null);
                s.move_collection(&from, collection_key);
            }
            s.databases.remove(&new_key);
            s.databases.set(name_key, Value::Array(previous));
        });
        Ok(true)
    }

    /// `exists($database)`.
    pub(crate) fn exists(&self, database: &str) -> bool {
        store::isset(&self.databases, &[k(database)])
    }

    /// `collectionExists($database, $collection)`.
    pub(crate) fn collection_exists(&self, database: &str, collection: &str) -> bool {
        store::isset(&self.databases, &[k(database), k(&filter(collection))])
    }

    /// `list()`: a document `['name' => ...]` per database.
    pub(crate) fn list(&self) -> Result<Vec<Document>> {
        self.databases.keys().map(|name| Document::from_pairs([("name", key_value(name))])).collect()
    }

    /// `delete($name)`: the database and every collection it owns.
    pub(crate) fn delete(&mut self, ctx: &Ctx, name: &str) {
        let name = k(name);
        let Some(Value::Array(entry)) = self.databases.get(&name).cloned() else { return };
        let mut saved: Vec<Vec<(ArrayKey, Value)>> = vec![Vec::new(); TABLES];
        for key in entry.values() {
            let key = key_of(key);
            for (i, slot) in saved.iter_mut().enumerate() {
                if let Some(v) = self.table_ref(i).get(&key).filter(|v| !matches!(v, Value::Null)) {
                    slot.push((key.clone(), v.clone()));
                }
                self.table(i).remove(&key);
            }
        }
        self.databases.remove(&name);
        self.journal(ctx, move |s: &mut Store| {
            s.databases.set(name, Value::Array(entry));
            for (i, slot) in saved.into_iter().enumerate() {
                for (key, value) in slot {
                    s.table(i).set(key, value);
                }
            }
        });
    }

    /// `createCollection($collection, $attributes, $indexes)`.
    pub(crate) fn create_collection(
        &mut self,
        ctx: &Ctx,
        name: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        let key = Self::key(ctx, name);
        if self.has(&key) {
            return Err(Error::duplicate("Collection already exists"));
        }
        let mut collection = Array::new();
        collection.set(k("attributes"), Value::Array(Array::new()));
        collection.set(k("indexes"), Value::Array(Array::new()));
        collection.set(k("documents"), Value::Array(Array::new()));
        collection.set(k("sequence"), Value::Int(0));
        self.data.set(key.clone(), Value::Array(collection));
        self.permissions.set(key.clone(), Value::Array(Array::new()));
        let database = ctx.database.clone();
        let mut slot = None;
        if !database.is_empty() {
            let d = k(&database);
            if !store::isset(&self.databases, std::slice::from_ref(&d)) {
                self.databases.set(d.clone(), Value::Array(Array::new()));
            }
            let s = k(&filter(name));
            store::set(&mut self.databases, &[d, s.clone()], key_value(key.as_ref()));
            slot = Some(s);
        }
        for attribute in attributes {
            store::set(
                &mut self.data,
                &[key.clone(), k("attributes"), k(&filter(&attribute.key))],
                attribute_entry(attribute),
            );
        }
        for index in indexes {
            store::set(&mut self.data, &[key.clone(), k("indexes"), k(&filter(&index.key))], index_entry(index));
        }
        self.journal(ctx, move |s: &mut Store| {
            for i in 0..TABLES {
                s.table(i).remove(&key);
            }
            if let Some(slot) = slot {
                store::unset(&mut s.databases, &[k(&database), slot]);
            }
        });
        Ok(true)
    }

    /// `deleteCollection($collection)`.
    pub(crate) fn delete_collection(&mut self, ctx: &Ctx, id: &str) {
        let key = Self::key(ctx, id);
        let saved: Vec<Option<Value>> =
            (0..TABLES).map(|i| self.table_ref(i).get(&key).cloned().filter(|v| !matches!(v, Value::Null))).collect();
        for i in 0..TABLES {
            self.table(i).remove(&key);
        }
        let filtered = k(&filter(id));
        let mut slots: Vec<(ArrayKey, Value)> = Vec::new();
        let names: Vec<ArrayKey> = self.databases.keys().map(|n| n.to_owned()).collect();
        for name in names {
            let at = store::get(&self.databases, &[name.clone(), filtered.clone()]).cloned();
            if let Some(v) = at
                && !matches!(v, Value::Null)
                && v.strict_eq(&key_value(key.as_ref()))
            {
                store::unset(&mut self.databases, &[name.clone(), filtered.clone()]);
                slots.push((name, v));
            }
        }
        self.journal(ctx, move |s: &mut Store| {
            for (i, value) in saved.into_iter().enumerate() {
                if let Some(v) = value {
                    s.table(i).set(key.clone(), v);
                }
            }
            for (name, value) in slots {
                store::set(&mut s.databases, &[name, filtered.clone()], value);
            }
        });
    }

    /// Stores an attribute definition, journaling the previous one.
    fn put_attribute(&mut self, ctx: &Ctx, key: &ArrayKey, id: ArrayKey, definition: Value) {
        let path = [key.clone(), k("attributes"), id];
        let previous = store::get(&self.data, &path).cloned().filter(|v| !matches!(v, Value::Null));
        store::set(&mut self.data, &path, definition);
        self.journal(ctx, move |s: &mut Store| match previous {
            None => store::unset(&mut s.data, &path),
            Some(p) => store::set(&mut s.data, &path, p),
        });
    }

    /// `createAttribute($collection, $attribute)`.
    pub(crate) fn create_attribute(&mut self, ctx: &Ctx, collection: &str, attribute: &Attribute) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        self.put_attribute(ctx, &key, k(&filter(&attribute.key)), attribute_entry(attribute));
        Ok(true)
    }

    /// `updateAttribute($collection, $key, $attribute)`: renames first when
    /// the key changes.
    pub(crate) fn update_attribute(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        attribute: &Attribute,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut target = filter(id);
        if attribute.key != id {
            self.rename_attribute(ctx, collection, &target, &attribute.key)?;
            target = filter(&attribute.key);
        }
        self.put_attribute(ctx, &key, k(&target), attribute_entry(attribute));
        Ok(true)
    }

    /// `deleteAttribute($collection, $key)`: the attribute, its values, and
    /// its place in the indexes.
    pub(crate) fn delete_attribute(&mut self, ctx: &Ctx, collection: &str, id: &str) -> bool {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return true;
        }
        let id = filter(id);
        let id_key = k(&id);
        let path = [key.clone(), k("attributes"), id_key.clone()];
        let Some(previous_attribute) = store::get(&self.data, &path).cloned().filter(|v| !matches!(v, Value::Null))
        else {
            return true;
        };
        store::unset(&mut self.data, &path);
        let mut previous_values: Vec<(ArrayKey, Value)> = Vec::new();
        let documents = store::vivify(&mut self.data, &[key.clone(), k("documents")]);
        store::for_each_mut(documents, |storage_key, document| {
            if let Value::Array(document) = document
                && let Some(v) = document.remove(&id_key)
            {
                previous_values.push((storage_key, v));
            }
        });
        let mut previous_indexes: Vec<(ArrayKey, Value)> = Vec::new();
        let mut previous_hashes: Vec<(ArrayKey, Value)> = Vec::new();
        let index_ids: Vec<ArrayKey> = store::get_array(&self.data, &[key.clone(), k("indexes")])
            .map(|a| a.keys().map(|x| x.to_owned()).collect())
            .unwrap_or_default();
        for index_id in index_ids {
            let path = [key.clone(), k("indexes"), index_id.clone()];
            let Some(Value::Array(index)) = store::get(&self.data, &path).cloned() else { continue };
            let field = |name: &str| match index.get_str(name) {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let (attributes, index_lengths, index_orders) = (field("attributes"), field("lengths"), field("orders"));
            let mut filtered = Array::new();
            let mut lengths = Array::new();
            let mut orders = Array::new();
            let mut touched = false;
            for (i, attribute) in attributes.iter() {
                let Value::Str(name) = attribute else { continue };
                if filter(&String::from_utf8_lossy(name.as_bytes())) == id {
                    touched = true;
                    continue;
                }
                let _ = filtered.push(attribute.clone());
                if let Some(l) = index_lengths.get(&i.to_owned()).filter(|v| !matches!(v, Value::Null)) {
                    let _ = lengths.push(l.clone());
                }
                if let Some(o) = index_orders.get(&i.to_owned()).filter(|v| !matches!(v, Value::Null)) {
                    let _ = orders.push(o.clone());
                }
            }
            if touched {
                previous_indexes.push((index_id.clone(), Value::Array(index.clone())));
                let unique = index.get_str("type").is_some_and(|t| t.strict_eq(&s(IndexType::Unique.as_str())));
                let hash_path = [key.clone(), index_id.clone()];
                if unique && store::isset(&self.hashes, &hash_path) {
                    if let Some(h) = store::get(&self.hashes, &hash_path).cloned() {
                        previous_hashes.push((index_id.clone(), h));
                    }
                    store::unset(&mut self.hashes, &hash_path);
                }
            }
            let mut index = index;
            index.set(k("attributes"), Value::Array(filtered));
            index.set(k("lengths"), Value::Array(lengths));
            index.set(k("orders"), Value::Array(orders));
            store::set(&mut self.data, &path, Value::Array(index));
        }
        self.journal(ctx, move |s: &mut Store| {
            if !s.has(&key) {
                return;
            }
            store::set(&mut s.data, &[key.clone(), k("attributes"), id_key.clone()], previous_attribute);
            for (storage_key, value) in previous_values {
                if store::isset(&s.data, &[key.clone(), k("documents"), storage_key.clone()]) {
                    store::set(&mut s.data, &[key.clone(), k("documents"), storage_key, id_key.clone()], value);
                }
            }
            for (index_id, value) in previous_indexes {
                store::set(&mut s.data, &[key.clone(), k("indexes"), index_id], value);
            }
            for (index_id, value) in previous_hashes {
                store::set(&mut s.hashes, &[key.clone(), index_id], value);
            }
        });
        true
    }

    /// `renameAttribute($collection, $old, $new)`: the definition, every
    /// document's value and the indexes.
    pub(crate) fn rename_attribute(&mut self, ctx: &Ctx, collection: &str, old: &str, new: &str) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let (old, new) = (filter(old), filter(new));
        let (old_key, new_key) = (k(&old), k(&new));
        let attributes = [key.clone(), k("attributes")];
        let Some(definition) = store::get(&self.data, &[key.clone(), k("attributes"), old_key.clone()])
            .cloned()
            .filter(|v| !matches!(v, Value::Null))
        else {
            return Ok(true);
        };
        let target = store::vivify(&mut self.data, &attributes);
        target.set(new_key.clone(), definition);
        target.remove(&old_key);
        let mut touched_docs = Vec::new();
        let documents = store::vivify(&mut self.data, &[key.clone(), k("documents")]);
        store::for_each_mut(documents, |storage_key, document| {
            if let Value::Array(document) = document
                && document.contains_key(&old_key)
            {
                let v = document.get(&old_key).cloned().unwrap_or(Value::Null);
                document.set(new_key.clone(), v);
                document.remove(&old_key);
                touched_docs.push(storage_key);
            }
        });
        let mut touched_indexes = Vec::new();
        let indexes = store::vivify(&mut self.data, &[key.clone(), k("indexes")]);
        store::for_each_mut(indexes, |index_id, index| {
            let Value::Array(index) = index else { return };
            let mut attributes = match index.get_str("attributes") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let mut changed = false;
            let keys: Vec<ArrayKey> = attributes.keys().map(|x| x.to_owned()).collect();
            for i in keys {
                if matches!(attributes.get(&i), Some(Value::Str(a)) if filter(&String::from_utf8_lossy(a.as_bytes())) == old)
                {
                    attributes.set(i, s(&new));
                    changed = true;
                }
            }
            if changed {
                touched_indexes.push(index_id);
                index.set(k("attributes"), Value::Array(attributes));
            }
        });
        self.journal(ctx, move |s: &mut Store| {
            if !s.has(&key) {
                return;
            }
            let definition = store::get(&s.data, &[key.clone(), k("attributes"), new_key.clone()]).cloned();
            store::set(
                &mut s.data,
                &[key.clone(), k("attributes"), old_key.clone()],
                definition.unwrap_or(Value::Null),
            );
            store::unset(&mut s.data, &[key.clone(), k("attributes"), new_key.clone()]);
            for storage_key in touched_docs {
                let path = [key.clone(), k("documents"), storage_key];
                if !store::isset(&s.data, &path) {
                    continue;
                }
                let document = store::vivify(&mut s.data, &path);
                let v = document.get(&new_key).cloned().unwrap_or(Value::Null);
                document.set(old_key.clone(), v);
                document.remove(&new_key);
            }
            for index_id in touched_indexes {
                let path = [key.clone(), k("indexes"), index_id, k("attributes")];
                let mut attributes = match store::get(&s.data, &path) {
                    Some(Value::Array(a)) => a.clone(),
                    _ => Array::new(),
                };
                let keys: Vec<ArrayKey> = attributes.keys().map(|x| x.to_owned()).collect();
                for i in keys {
                    if matches!(attributes.get(&i), Some(Value::Str(a)) if filter(&String::from_utf8_lossy(a.as_bytes())) == new)
                    {
                        attributes.set(i, store::s(&old));
                    }
                }
                store::set(&mut s.data, &path, Value::Array(attributes));
            }
        });
        Ok(true)
    }

    /// `createRelationship($collection, $relationship)`: registers the key
    /// fields.
    pub(crate) fn create_relationship(&mut self, ctx: &Ctx, collection: &str, relationship: &Relationship) {
        let related = relationship.related_collection.as_str();
        let key = relationship.key.as_deref().unwrap_or("");
        let two_way_key = relationship.two_way_key.as_deref().unwrap_or("");
        match relationship.kind {
            RelationshipType::OneToOne => {
                self.register_relationship_field(ctx, collection, key);
                if relationship.two_way {
                    self.register_relationship_field(ctx, related, two_way_key);
                }
            }
            RelationshipType::OneToMany => self.register_relationship_field(ctx, related, two_way_key),
            RelationshipType::ManyToOne => self.register_relationship_field(ctx, collection, key),
            RelationshipType::ManyToMany => {}
        }
    }

    /// `updateRelationship($collection, $relationship, $side, $update)`:
    /// renames the key fields.
    pub(crate) fn update_relationship(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &Update,
    ) {
        let related = relationship.related_collection.as_str();
        let key = filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let new_key = update.key.as_deref().map(filter);
        let new_two_way_key = update.two_way_key.as_deref().map(filter);
        let two_way = update.two_way.unwrap_or(relationship.two_way);
        let changed = |new: &Option<String>, old: &str| new.as_deref().filter(|n| *n != old).map(str::to_owned);
        let parent = side == RelationshipSide::Parent;
        match relationship.kind {
            RelationshipType::OneToOne => {
                if (two_way || parent)
                    && let Some(n) = changed(&new_key, &key)
                {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
                if (two_way || side == RelationshipSide::Child)
                    && let Some(n) = changed(&new_two_way_key, &two_way_key)
                {
                    self.rename_document_field(ctx, related, &two_way_key, &n);
                }
            }
            RelationshipType::OneToMany => {
                if parent {
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, related, &two_way_key, &n);
                    }
                } else if let Some(n) = changed(&new_key, &key) {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
            }
            RelationshipType::ManyToOne => {
                if side == RelationshipSide::Child {
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, related, &two_way_key, &n);
                    }
                } else if let Some(n) = changed(&new_key, &key) {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
            }
            RelationshipType::ManyToMany => {
                if let Some(junction) = self.resolve_junction_collection(ctx, collection, related, side) {
                    if let Some(n) = changed(&new_key, &key) {
                        self.rename_document_field(ctx, &junction, &key, &n);
                    }
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, &junction, &two_way_key, &n);
                    }
                }
            }
        }
    }

    /// `deleteRelationship($collection, $relationship, $side)`: drops the
    /// key fields, or the junction collection of a many-to-many.
    pub(crate) fn delete_relationship(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) {
        let related = relationship.related_collection.as_str();
        let key = filter(relationship.key.as_deref().unwrap_or(""));
        let two_way_key = filter(relationship.two_way_key.as_deref().unwrap_or(""));
        let two_way = relationship.two_way;
        let parent = side == RelationshipSide::Parent;
        match relationship.kind {
            RelationshipType::OneToOne => {
                if parent {
                    self.drop_document_field(ctx, collection, &key);
                    if two_way {
                        self.drop_document_field(ctx, related, &two_way_key);
                    }
                } else {
                    self.drop_document_field(ctx, related, &two_way_key);
                    if two_way {
                        self.drop_document_field(ctx, collection, &key);
                    }
                }
            }
            RelationshipType::OneToMany => {
                if parent {
                    self.drop_document_field(ctx, related, &two_way_key);
                } else {
                    self.drop_document_field(ctx, collection, &key);
                }
            }
            RelationshipType::ManyToOne => {
                if parent {
                    self.drop_document_field(ctx, collection, &key);
                } else {
                    self.drop_document_field(ctx, related, &two_way_key);
                }
            }
            RelationshipType::ManyToMany => {
                if let Some(junction) = self.resolve_junction_collection(ctx, collection, related, side) {
                    self.delete_collection(ctx, &junction);
                }
            }
        }
    }

    /// `registerRelationshipField($collection, $field)`.
    fn register_relationship_field(&mut self, ctx: &Ctx, collection: &str, field: &str) {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return;
        }
        self.put_attribute(
            ctx,
            &key,
            k(&filter(field)),
            definition(ColumnType::Relationship.as_str(), 0, true, false, false),
        );
    }

    /// `renameDocumentField($collection, $oldKey, $newKey)`.
    fn rename_document_field(&mut self, ctx: &Ctx, collection: &str, old: &str, new: &str) {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return;
        }
        let (old_key, new_key) = (k(old), k(new));
        let had = store::isset(&self.data, &[key.clone(), k("attributes"), old_key.clone()]);
        if had {
            let attributes = store::vivify(&mut self.data, &[key.clone(), k("attributes")]);
            let definition = attributes.get(&old_key).cloned().unwrap_or(Value::Null);
            attributes.set(new_key.clone(), definition);
            attributes.remove(&old_key);
        }
        let mut touched = Vec::new();
        let documents = store::vivify(&mut self.data, &[key.clone(), k("documents")]);
        store::for_each_mut(documents, |storage_key, document| {
            if let Value::Array(document) = document
                && document.contains_key(&old_key)
            {
                let v = document.get(&old_key).cloned().unwrap_or(Value::Null);
                document.set(new_key.clone(), v);
                document.remove(&old_key);
                touched.push(storage_key);
            }
        });
        self.journal(ctx, move |s: &mut Store| {
            if had {
                let attributes = store::vivify(&mut s.data, &[key.clone(), k("attributes")]);
                let definition = attributes.get(&new_key).cloned().unwrap_or(Value::Null);
                attributes.set(old_key.clone(), definition);
                attributes.remove(&new_key);
            }
            for storage_key in touched {
                let path = [key.clone(), k("documents"), storage_key];
                if !store::isset(&s.data, &path) {
                    continue;
                }
                let document = store::vivify(&mut s.data, &path);
                let v = document.get(&new_key).cloned().unwrap_or(Value::Null);
                document.set(old_key.clone(), v);
                document.remove(&new_key);
            }
        });
    }

    /// `dropDocumentField($collection, $field)`.
    fn drop_document_field(&mut self, ctx: &Ctx, collection: &str, field: &str) {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return;
        }
        let field_key = k(field);
        let path = [key.clone(), k("attributes"), field_key.clone()];
        let previous = store::get(&self.data, &path).cloned().filter(|v| !matches!(v, Value::Null));
        store::unset(&mut self.data, &path);
        let mut values = Vec::new();
        let documents = store::vivify(&mut self.data, &[key.clone(), k("documents")]);
        store::for_each_mut(documents, |storage_key, document| {
            if let Value::Array(document) = document
                && let Some(v) = document.remove(&field_key)
            {
                values.push((storage_key, v));
            }
        });
        self.journal(ctx, move |s: &mut Store| {
            if let Some(p) = previous {
                store::set(&mut s.data, &[key.clone(), k("attributes"), field_key.clone()], p);
            }
            for (storage_key, value) in values {
                let doc = [key.clone(), k("documents"), storage_key];
                if !store::isset(&s.data, &doc) {
                    continue;
                }
                store::vivify(&mut s.data, &doc).set(field_key.clone(), value);
            }
        });
    }

    /// `resolveJunctionCollection($collection, $relatedCollection, $side)`:
    /// `_<parent sequence>_<child sequence>`.
    fn resolve_junction_collection(
        &self,
        ctx: &Ctx,
        collection: &str,
        related: &str,
        side: RelationshipSide,
    ) -> Option<String> {
        let metadata = Self::key(ctx, METADATA);
        if !self.has(&metadata) {
            return None;
        }
        let a = self.locate_document(ctx, &metadata, METADATA, collection)?;
        let b = self.locate_document(ctx, &metadata, METADATA, related)?;
        let seq = |row: &Array| match row.get_str(crate::storage::SEQUENCE) {
            Some(v @ (Value::Bool(_) | Value::Int(_) | Value::Float(_) | Value::Str(_))) => {
                Some(crate::value::to_text(v))
            }
            _ => None,
        };
        let (a, b) = (seq(&a.1)?, seq(&b.1)?);
        Some(if side == RelationshipSide::Parent { format!("_{a}_{b}") } else { format!("_{b}_{a}") })
    }

    /// `renameIndex($collection, $old, $new)`: whether the index holds the
    /// new name afterwards.
    pub(crate) fn rename_index(&mut self, ctx: &Ctx, collection: &str, old: &str, new: &str) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let (old_key, new_key) = (k(&filter(old)), k(&filter(new)));
        let Some(index) = store::get(&self.data, &[key.clone(), k("indexes"), old_key.clone()])
            .cloned()
            .filter(|v| !matches!(v, Value::Null))
        else {
            return Ok(store::isset(&self.data, &[key, k("indexes"), new_key]));
        };
        let indexes = store::vivify(&mut self.data, &[key.clone(), k("indexes")]);
        indexes.set(new_key.clone(), index);
        indexes.remove(&old_key);
        let mut moved = false;
        if store::isset(&self.hashes, &[key.clone(), old_key.clone()]) {
            let table = store::vivify(&mut self.hashes, std::slice::from_ref(&key));
            let h = table.get(&old_key).cloned().unwrap_or(Value::Null);
            table.set(new_key.clone(), h);
            table.remove(&old_key);
            moved = true;
        }
        self.journal(ctx, move |s: &mut Store| {
            let indexes = store::vivify(&mut s.data, &[key.clone(), k("indexes")]);
            let index = indexes.get(&new_key).cloned().unwrap_or(Value::Null);
            indexes.set(old_key.clone(), index);
            indexes.remove(&new_key);
            if moved {
                let table = store::vivify(&mut s.hashes, std::slice::from_ref(&key));
                let h = table.get(&new_key).cloned().unwrap_or(Value::Null);
                table.set(old_key.clone(), h);
                table.remove(&new_key);
            }
        });
        Ok(true)
    }

    /// `createIndex($collection, $index)`: a unique index first checks the
    /// rows it already has, building its hash table.
    pub(crate) fn create_index(&mut self, ctx: &Ctx, collection: &str, index: &Index) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let is_unique = index.kind == IndexType::Unique;
        let unique = is_unique && !index.attributes.is_empty();
        let mut table = Array::new();
        if unique && let Some(documents) = store::get_array(&self.data, &[key.clone(), k("documents")]) {
            for (doc_key, row) in documents.iter() {
                let Value::Array(row) = row else { continue };
                let mut signature = Array::new();
                for attribute in &index.attributes {
                    let _ = signature.push(normalize_index_value(Self::resolve_attribute_value(row, attribute)));
                }
                if signature.values().any(|v| matches!(v, Value::Null)) {
                    continue;
                }
                if ctx.shared {
                    signature = prepend(signature, row.get_str(crate::storage::TENANT).cloned().unwrap_or(Value::Null));
                }
                let hash = serialize(&signature);
                if table.contains_key(&hash) {
                    return Err(Error::unique(crate::error::UNIQUE_MESSAGE));
                }
                table.set(hash, key_value(doc_key));
            }
        }
        let id_key = k(&filter(&index.key));
        store::set(&mut self.data, &[key.clone(), k("indexes"), id_key.clone()], index_entry(index));
        if unique {
            store::set(&mut self.hashes, &[key.clone(), id_key.clone()], Value::Array(table));
        }
        self.journal(ctx, move |s: &mut Store| {
            store::unset(&mut s.data, &[key.clone(), k("indexes"), id_key.clone()]);
            if is_unique {
                store::unset(&mut s.hashes, &[key, id_key]);
            }
        });
        Ok(true)
    }

    /// `deleteIndex($collection, $key)`.
    pub(crate) fn delete_index(&mut self, ctx: &Ctx, collection: &str, id: &str) {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return;
        }
        let id_key = k(&filter(id));
        let index_path = [key.clone(), k("indexes"), id_key.clone()];
        let hash_path = [key.clone(), id_key.clone()];
        let previous_index = store::get(&self.data, &index_path).cloned().filter(|v| !matches!(v, Value::Null));
        let previous_hash = store::get(&self.hashes, &hash_path).cloned().filter(|v| !matches!(v, Value::Null));
        store::unset(&mut self.data, &index_path);
        store::unset(&mut self.hashes, &hash_path);
        self.journal(ctx, move |s: &mut Store| {
            if let Some(i) = previous_index {
                store::set(&mut s.data, &index_path, i);
            }
            if let Some(h) = previous_hash {
                store::set(&mut s.hashes, &hash_path, h);
            }
        });
    }

    /// `getSizeOfCollection($collection)`: `strlen(serialize($this->data[$key]))`.
    pub(crate) fn size_of_collection(&self, ctx: &Ctx, collection: &str) -> i64 {
        let key = Self::key(ctx, collection);
        match self.data.get(&key) {
            Some(v) if !matches!(v, Value::Null) => php_std::serialize::serialize(v).len() as i64,
            _ => 0,
        }
    }
}

/// The definition array an attribute is stored as.
fn definition(kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Value {
    let mut d = Array::new();
    d.set(k("type"), s(kind));
    d.set(k("size"), Value::Int(size));
    d.set(k("signed"), Value::Bool(signed));
    d.set(k("array"), Value::Bool(array));
    d.set(k("required"), Value::Bool(required));
    Value::Array(d)
}

/// `attributeEntry($attribute)`.
fn attribute_entry(attribute: &Attribute) -> Value {
    definition(
        attribute.kind.as_str(),
        attribute.size.unwrap_or(0),
        attribute.signed,
        attribute.array,
        attribute.required,
    )
}

/// `indexEntry($index)`: its type, attributes, lengths and orders (each
/// order's value, or `null`).
fn index_entry(index: &Index) -> Value {
    let mut d = Array::new();
    d.set(k("type"), s(index.kind.as_str()));
    d.set(k("attributes"), Value::Array(index.attributes.iter().map(|a| s(a)).collect()));
    d.set(k("lengths"), Value::Array(index.lengths.iter().map(|l| l.map_or(Value::Null, Value::Int)).collect()));
    d.set(k("orders"), Value::Array(index.orders.iter().map(|o| o.map_or(Value::Null, |o| s(o.as_str()))).collect()));
    Value::Array(d)
}

/// `array_unshift($signature, $value)`.
pub(crate) fn prepend(signature: Array, value: Value) -> Array {
    std::iter::once(value).chain(signature.into_iter().map(|(_, v)| v)).collect()
}

/// `serialize($signature)` as an array key.
pub(crate) fn serialize(signature: &Array) -> ArrayKey {
    ArrayKey::normalize(Str::from(php_std::serialize::serialize(&Value::Array(signature.clone()))))
}

/// `normalizeIndexValue($value)`: booleans as 0/1, arrays as JSON, numeric
/// strings as numbers.
pub(crate) fn normalize_index_value(value: Value) -> Value {
    match value {
        Value::Null => Value::Null,
        Value::Bool(b) => Value::Int(i64::from(b)),
        Value::Array(_) => super::documents::json_encode(&value),
        Value::Str(s) => match php_std::value::numeric_bytes_ex(s.as_bytes()) {
            Some((php_std::value::Number::Int(i), _)) => Value::Int(i),
            Some((php_std::value::Number::Float(f), _)) => Value::Float(f),
            None => Value::Str(s),
        },
        other => other,
    }
}
