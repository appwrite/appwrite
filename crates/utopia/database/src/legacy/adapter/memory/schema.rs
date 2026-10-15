//! Databases, collections, attributes, relationship fields and indexes.
//!
//! PHP: `Memory::create`, `exists`, `list`, `delete`, `createCollection`,
//! `deleteCollection`, `createAttribute(s)`, `updateAttribute`,
//! `deleteAttribute`, `renameAttribute`, `createRelationship`,
//! `updateRelationship`, `deleteRelationship` (with
//! `registerRelationshipField`, `renameDocumentField`, `dropDocumentField`,
//! `resolveJunctionCollection`), `renameIndex`, `createIndex`,
//! `deleteIndex`, `getSizeOfCollection`.

use super::store::{self, k, key_of, key_value, s};
use super::{Ctx, Store, filter};
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

impl Store {
    /// `key($collection)`: `<database>.<namespace>_<collection>`.
    pub(super) fn key(ctx: &Ctx, collection: &str) -> ArrayKey {
        k(&format!("{}.{}_{}", ctx.database, ctx.namespace, filter(collection)))
    }

    /// Whether the collection exists (`isset($this->data[$key])`).
    pub(super) fn has(&self, key: &ArrayKey) -> bool {
        store::isset(&self.data, std::slice::from_ref(key))
    }

    /// `create($name)`.
    pub(super) fn create(&mut self, ctx: &Ctx, name: &str) {
        let name = k(name);
        if !store::isset(&self.databases, std::slice::from_ref(&name)) {
            self.databases.set(name.clone(), Value::Array(Array::new()));
            self.journal(ctx, move |s: &mut Store| {
                s.databases.remove(&name);
            });
        }
    }

    /// `exists($database, $collection)`.
    pub(super) fn exists(&self, database: &str, collection: Option<&str>) -> bool {
        let database = k(database);
        match collection {
            None => store::isset(&self.databases, &[database]),
            Some(c) => {
                store::isset(&self.databases, std::slice::from_ref(&database))
                    && store::isset(&self.databases, &[database, k(&filter(c))])
            }
        }
    }

    /// `list()`: a document `['name' => ...]` per database.
    pub(super) fn list(&self) -> Result<Vec<Document>> {
        self.databases.keys().map(|name| Document::from_pairs([("name", key_value(name))])).collect()
    }

    /// `delete($name)`: the database and every collection it owns.
    pub(super) fn delete(&mut self, ctx: &Ctx, name: &str) {
        let name = k(name);
        let Some(Value::Array(entry)) = self.databases.get(&name).cloned() else { return };
        let mut saved: Vec<(ArrayKey, [Option<Value>; 5])> = Vec::new();
        for key in entry.values() {
            let key = key_of(key);
            let slot = [
                self.data.get(&key).cloned(),
                self.permissions.get(&key).cloned(),
                self.by_document.get(&key).cloned(),
                self.by_permission.get(&key).cloned(),
                self.hashes.get(&key).cloned(),
            ];
            for table in [
                &mut self.data,
                &mut self.permissions,
                &mut self.by_document,
                &mut self.by_permission,
                &mut self.hashes,
            ] {
                table.remove(&key);
            }
            saved.push((key, slot));
        }
        self.databases.remove(&name);
        self.journal(ctx, move |s: &mut Store| {
            s.databases.set(name, Value::Array(entry));
            for table in 0..5 {
                for (key, slot) in &saved {
                    if let Some(v) = &slot[table] {
                        s.table(table).set(key.clone(), v.clone());
                    }
                }
            }
        });
    }

    /// The tables of the store by position (data, permissions, by document,
    /// by permission, hashes).
    fn table(&mut self, i: usize) -> &mut Array {
        match i {
            0 => &mut self.data,
            1 => &mut self.permissions,
            2 => &mut self.by_document,
            3 => &mut self.by_permission,
            _ => &mut self.hashes,
        }
    }

    /// `createCollection($name, $attributes, $indexes)`.
    pub(super) fn create_collection(
        &mut self,
        ctx: &Ctx,
        name: &str,
        attributes: &[Document],
        indexes: &[Document],
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
        let attr = |d: &Document, name: &str, default: Value| d.get(name).cloned().unwrap_or(default);
        for attribute in attributes {
            let mut definition = Array::new();
            definition.set(k("type"), attr(attribute, "type", Value::Null));
            definition.set(k("size"), attr(attribute, "size", Value::Int(0)));
            definition.set(k("signed"), attr(attribute, "signed", Value::Bool(true)));
            definition.set(k("array"), attr(attribute, "array", Value::Bool(false)));
            definition.set(k("required"), attr(attribute, "required", Value::Bool(false)));
            store::set(
                &mut self.data,
                &[key.clone(), k("attributes"), k(&filter(attribute.id()))],
                Value::Array(definition),
            );
        }
        for index in indexes {
            let mut definition = Array::new();
            definition.set(k("type"), attr(index, "type", Value::Null));
            definition.set(k("attributes"), attr(index, "attributes", Value::Array(Array::new())));
            definition.set(k("lengths"), attr(index, "lengths", Value::Array(Array::new())));
            definition.set(k("orders"), attr(index, "orders", Value::Array(Array::new())));
            store::set(&mut self.data, &[key.clone(), k("indexes"), k(&filter(index.id()))], Value::Array(definition));
        }
        self.journal(ctx, move |s: &mut Store| {
            for table in 0..5 {
                s.table(table).remove(&key);
            }
            if !database.is_empty()
                && let Some(slot) = slot
            {
                store::unset(&mut s.databases, &[k(&database), slot]);
            }
        });
        Ok(true)
    }

    /// `deleteCollection($id)`.
    pub(super) fn delete_collection(&mut self, ctx: &Ctx, id: &str) {
        let key = Self::key(ctx, id);
        let saved: [Option<Value>; 5] = [
            self.data.get(&key).cloned(),
            self.permissions.get(&key).cloned(),
            self.by_document.get(&key).cloned(),
            self.by_permission.get(&key).cloned(),
            self.hashes.get(&key).cloned(),
        ];
        for table in 0..5 {
            self.table(table).remove(&key);
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
            for (table, value) in saved.into_iter().enumerate() {
                if let Some(v) = value
                    && !matches!(v, Value::Null)
                {
                    s.table(table).set(key.clone(), v);
                }
            }
            for (name, value) in slots {
                store::set(&mut s.databases, &[name, filtered.clone()], value);
            }
        });
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

    /// `createAttribute(...)`.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn create_attribute(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        self.put_attribute(ctx, &key, k(&filter(id)), Self::definition(kind, size, signed, array, required));
        Ok(true)
    }

    /// `createAttributes($collection, $attributes)`: one at a time.
    pub(super) fn create_attributes(&mut self, ctx: &Ctx, collection: &str, attributes: &[Array]) -> Result<bool> {
        for attribute in attributes {
            let get = |name: &str| attribute.get_str(name).filter(|v| !matches!(v, Value::Null));
            let text = |v: Option<&Value>| v.map(crate::value::to_text).unwrap_or_default();
            self.create_attribute(
                ctx,
                collection,
                &text(attribute.get_str("$id")),
                &text(attribute.get_str("type")),
                get("size").map(super::operators::to_long).unwrap_or(0),
                get("signed").map(Value::truthy).unwrap_or(true),
                get("array").map(Value::truthy).unwrap_or(false),
                get("required").map(Value::truthy).unwrap_or(false),
            )?;
        }
        Ok(true)
    }

    /// `updateAttribute(...)`: renames first when the key changes.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn update_attribute(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let mut id = filter(id);
        if let Some(new_key) = new_key
            && !(new_key.is_empty() || new_key == "0")
            && new_key != id
        {
            self.rename_attribute(ctx, collection, &id, new_key)?;
            id = filter(new_key);
        }
        self.put_attribute(ctx, &key, k(&id), Self::definition(kind, size, signed, array, required));
        Ok(true)
    }

    /// `deleteAttribute($collection, $id)`: the attribute, its values, and
    /// its place in the indexes.
    pub(super) fn delete_attribute(&mut self, ctx: &Ctx, collection: &str, id: &str) -> bool {
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
            let attributes = match index.get_str("attributes") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let mut filtered = Array::new();
            let mut lengths = Array::new();
            let mut orders = Array::new();
            let mut touched = false;
            for (i, attribute) in attributes.iter() {
                if filter(&crate::value::to_text(attribute)) == id {
                    touched = true;
                    continue;
                }
                let _ = filtered.push(attribute.clone());
                if let Some(l) = store::get(&index, &[k("lengths"), i.to_owned()]).filter(|v| !matches!(v, Value::Null))
                {
                    let _ = lengths.push(l.clone());
                }
                if let Some(o) = store::get(&index, &[k("orders"), i.to_owned()]).filter(|v| !matches!(v, Value::Null))
                {
                    let _ = orders.push(o.clone());
                }
            }
            if touched {
                previous_indexes.push((index_id.clone(), Value::Array(index.clone())));
                let unique = index.get_str("type").is_some_and(|t| t.strict_eq(&s(Database::INDEX_UNIQUE)));
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
    pub(super) fn rename_attribute(&mut self, ctx: &Ctx, collection: &str, old: &str, new: &str) -> Result<bool> {
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
                && let Some(v) = document.remove(&old_key)
            {
                document.set(new_key.clone(), v);
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
                if attributes.get(&i).is_some_and(|a| filter(&crate::value::to_text(a)) == old) {
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
                    if attributes.get(&i).is_some_and(|a| filter(&crate::value::to_text(a)) == new) {
                        attributes.set(i, store::s(&old));
                    }
                }
                store::set(&mut s.data, &path, Value::Array(attributes));
            }
        });
        Ok(true)
    }

    /// `createRelationship(...)`: registers the key fields.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn create_relationship(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        related: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                self.register_relationship_field(ctx, collection, id);
                if two_way {
                    self.register_relationship_field(ctx, related, two_way_key);
                }
            }
            Database::RELATION_ONE_TO_MANY => self.register_relationship_field(ctx, related, two_way_key),
            Database::RELATION_MANY_TO_ONE => self.register_relationship_field(ctx, collection, id),
            Database::RELATION_MANY_TO_MANY => {}
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `updateRelationship(...)`: renames the key fields.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn update_relationship(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        related: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        let new_key = new_key.map(filter);
        let new_two_way_key = new_two_way_key.map(filter);
        let changed = |new: &Option<String>, old: &str| new.as_deref().filter(|n| *n != old).map(str::to_owned);
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if let Some(n) = changed(&new_key, &key) {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
                if two_way && let Some(n) = changed(&new_two_way_key, &two_way_key) {
                    self.rename_document_field(ctx, related, &two_way_key, &n);
                }
            }
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, related, &two_way_key, &n);
                    }
                } else if let Some(n) = changed(&new_key, &key) {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_CHILD {
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, related, &two_way_key, &n);
                    }
                } else if let Some(n) = changed(&new_key, &key) {
                    self.rename_document_field(ctx, collection, &key, &n);
                }
            }
            Database::RELATION_MANY_TO_MANY => {
                if let Some(junction) = self.resolve_junction_collection(ctx, collection, related, side) {
                    if let Some(n) = changed(&new_key, &key) {
                        self.rename_document_field(ctx, &junction, &key, &n);
                    }
                    if let Some(n) = changed(&new_two_way_key, &two_way_key) {
                        self.rename_document_field(ctx, &junction, &two_way_key, &n);
                    }
                }
            }
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
    }

    /// `deleteRelationship(...)`: drops the key fields.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn delete_relationship(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        related: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        let key = filter(key);
        let two_way_key = filter(two_way_key);
        match kind {
            Database::RELATION_ONE_TO_ONE => {
                if side == Database::RELATION_SIDE_PARENT {
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
            Database::RELATION_ONE_TO_MANY => {
                if side == Database::RELATION_SIDE_PARENT {
                    self.drop_document_field(ctx, related, &two_way_key);
                } else {
                    self.drop_document_field(ctx, collection, &key);
                }
            }
            Database::RELATION_MANY_TO_ONE => {
                if side == Database::RELATION_SIDE_PARENT {
                    self.drop_document_field(ctx, collection, &key);
                } else {
                    self.drop_document_field(ctx, related, &two_way_key);
                }
            }
            Database::RELATION_MANY_TO_MANY => {}
            _ => return Err(Error::database("Invalid relationship type")),
        }
        Ok(true)
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
            Self::definition(Database::VAR_RELATIONSHIP, 0, true, false, false),
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
                && let Some(v) = document.remove(&old_key)
            {
                document.set(new_key.clone(), v);
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

    /// `resolveJunctionCollection($collection, $relatedCollection, $side)`.
    fn resolve_junction_collection(&self, ctx: &Ctx, collection: &str, related: &str, side: &str) -> Option<String> {
        let metadata = Self::key(ctx, Database::METADATA);
        if !self.has(&metadata) {
            return None;
        }
        let a = self.locate_document(ctx, &metadata, Database::METADATA, collection)?;
        let b = self.locate_document(ctx, &metadata, Database::METADATA, related)?;
        let seq = |row: &Array| row.get_str("_id").filter(|v| !matches!(v, Value::Null)).map(crate::value::to_text);
        let (a, b) = (seq(&a.1)?, seq(&b.1)?);
        Some(if side == Database::RELATION_SIDE_PARENT { format!("_{a}_{b}") } else { format!("_{b}_{a}") })
    }

    /// `renameIndex($collection, $old, $new)`.
    pub(super) fn rename_index(&mut self, ctx: &Ctx, collection: &str, old: &str, new: &str) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let (old_key, new_key) = (k(&filter(old)), k(&filter(new)));
        let Some(index) = store::get(&self.data, &[key.clone(), k("indexes"), old_key.clone()])
            .cloned()
            .filter(|v| !matches!(v, Value::Null))
        else {
            return Ok(true);
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

    /// `createIndex(...)`: a unique index first checks the rows it already
    /// has, building its hash table.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn create_index(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
    ) -> Result<bool> {
        let lengths = lengths.iter().cloned().collect();
        let orders = orders.iter().cloned().collect();
        self.create_index_with(ctx, collection, id, kind, attributes, lengths, orders)
    }

    /// `createIndex(...)` with the `$lengths` and `$orders` arrays as given.
    #[allow(clippy::too_many_arguments)]
    pub(super) fn create_index_with(
        &mut self,
        ctx: &Ctx,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: Array,
        orders: Array,
    ) -> Result<bool> {
        let key = Self::key(ctx, collection);
        if !self.has(&key) {
            return Err(Error::not_found("Collection not found"));
        }
        let unique = kind == Database::INDEX_UNIQUE && !attributes.is_empty();
        let mut table = Array::new();
        if unique && let Some(documents) = store::get_array(&self.data, &[key.clone(), k("documents")]) {
            for (doc_key, row) in documents.iter() {
                let Value::Array(row) = row else { continue };
                let mut signature = Array::new();
                for attribute in attributes {
                    let _ = signature.push(normalize_index_value(self.resolve_attribute_value(ctx, row, attribute)));
                }
                if signature.values().any(|v| matches!(v, Value::Null)) {
                    continue;
                }
                if ctx.shared {
                    signature = prepend(signature, row.get_str("_tenant").cloned().unwrap_or(Value::Null));
                }
                let hash = serialize(&signature);
                if table.contains_key(&hash) {
                    return Err(Error::new(Kind::Unique, "Unique index violation"));
                }
                table.set(hash, key_value(doc_key));
            }
        }
        let id_key = k(&filter(id));
        let mut definition = Array::new();
        definition.set(k("type"), s(kind));
        definition.set(k("attributes"), Value::Array(attributes.iter().map(|a| s(a)).collect()));
        definition.set(k("lengths"), Value::Array(lengths));
        definition.set(k("orders"), Value::Array(orders));
        store::set(&mut self.data, &[key.clone(), k("indexes"), id_key.clone()], Value::Array(definition));
        if unique {
            store::set(&mut self.hashes, &[key.clone(), id_key.clone()], Value::Array(table));
        }
        let is_unique = kind == Database::INDEX_UNIQUE;
        self.journal(ctx, move |s: &mut Store| {
            store::unset(&mut s.data, &[key.clone(), k("indexes"), id_key.clone()]);
            if is_unique {
                store::unset(&mut s.hashes, &[key, id_key]);
            }
        });
        Ok(true)
    }

    /// `deleteIndex($collection, $id)`.
    pub(super) fn delete_index(&mut self, ctx: &Ctx, collection: &str, id: &str) {
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
    pub(super) fn size_of_collection(&self, ctx: &Ctx, collection: &str) -> i64 {
        let key = Self::key(ctx, collection);
        match self.data.get(&key) {
            Some(v) if !matches!(v, Value::Null) => php_std::serialize::serialize(v).len() as i64,
            _ => 0,
        }
    }
}

/// `array_unshift($signature, $value)`.
pub(super) fn prepend(signature: Array, value: Value) -> Array {
    std::iter::once(value).chain(signature.into_iter().map(|(_, v)| v)).collect()
}

/// `serialize($signature)` as an array key.
pub(super) fn serialize(signature: &Array) -> ArrayKey {
    ArrayKey::normalize(Str::from(php_std::serialize::serialize(&Value::Array(signature.clone()))))
}

/// `normalizeIndexValue($value)`: booleans as 0/1, arrays as JSON, numeric
/// strings as numbers.
pub(super) fn normalize_index_value(value: Value) -> Value {
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
