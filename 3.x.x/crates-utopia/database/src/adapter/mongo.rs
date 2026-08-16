//! MongoDB adapter (PHP `Adapter\Mongo`).

use super::{filter_key, Adapter, AdapterState};
use crate::document::Document;
use crate::error::{DatabaseError, Result};
use crate::query::{Query, TYPE_EQUAL, TYPE_LIMIT, TYPE_NOT_EQUAL, TYPE_OFFSET};
use crate::value::AttrValue;
use indexmap::IndexMap;
use mongodb::bson::{doc, Bson, Document as BsonDocument};
use mongodb::sync::{Client, Collection};

/// MongoDB adapter (PHP `Utopia\Database\Adapter\Mongo`).
#[derive(Debug)]
pub struct Mongo {
    state: AdapterState,
    uri: String,
    client: Client,
}

impl Mongo {
    /// Connect using a MongoDB URI.
    pub fn connect(uri: &str) -> Result<Self> {
        let client = Client::with_uri_str(uri)
            .map_err(|e| DatabaseError::database(format!("Mongo connect failed: {e}")))?;
        Ok(Self {
            state: AdapterState::default(),
            uri: uri.to_owned(),
            client,
        })
    }

    /// Connection URI.
    #[must_use]
    pub fn uri(&self) -> &str {
        &self.uri
    }

    fn db(&self) -> mongodb::sync::Database {
        let name = if self.state.database.is_empty() {
            "utopiaTests"
        } else {
            &self.state.database
        };
        self.client.database(name)
    }

    fn collection(&self, name: &str) -> Collection<BsonDocument> {
        let table = format!("{}_{}", self.state.namespace, filter_key(name));
        self.db().collection(&table)
    }
}

impl Adapter for Mongo {
    fn state(&self) -> &AdapterState {
        &self.state
    }
    fn state_mut(&mut self) -> &mut AdapterState {
        &mut self.state
    }
    fn ping(&mut self) -> bool {
        self.db().run_command(doc! { "ping": 1 }, None).is_ok()
    }
    fn create(&mut self, name: &str) -> Result<bool> {
        let _ = name;
        let _ = self.db().list_collection_names(None);
        Ok(true)
    }
    fn exists(&mut self, _database: &str, collection: Option<&str>) -> Result<bool> {
        let Some(collection) = collection else {
            return Ok(self
                .client
                .list_database_names(None, None)
                .map_err(|e| DatabaseError::database(e.to_string()))?
                .iter()
                .any(|n| n == &self.state.database));
        };
        let name = format!("{}_{}", self.state.namespace, filter_key(collection));
        let names = self
            .db()
            .list_collection_names(None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(names.iter().any(|n| n == &name))
    }
    fn delete(&mut self, name: &str) -> Result<bool> {
        self.client
            .database(&filter_key(name))
            .drop(None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(true)
    }
    fn create_collection(
        &mut self,
        name: &str,
        _attributes: &[Document],
        _indexes: &[Document],
    ) -> Result<bool> {
        self.db()
            .create_collection(format!("{}_{}", self.state.namespace, filter_key(name)), None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(true)
    }
    fn delete_collection(&mut self, id: &str) -> Result<bool> {
        self.collection(id)
            .drop(None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(true)
    }
    fn get_document(
        &mut self,
        collection: &Document,
        id: &str,
        _queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        let found = self
            .collection(&collection.get_id())
            .find_one(doc! { "_uid": id }, None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(found.map_or_else(Document::new, bson_to_document))
    }
    fn create_document(
        &mut self,
        collection: &Document,
        mut document: Document,
    ) -> Result<Document> {
        let mut bson = document_to_bson(&document);
        let result = self
            .collection(&collection.get_id())
            .insert_one(bson.clone(), None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        if let Bson::ObjectId(oid) = result.inserted_id {
            document.set_attribute("$sequence", AttrValue::from(oid.to_hex()));
            bson.insert("_id", oid);
        }
        Ok(document)
    }
    fn update_document(
        &mut self,
        collection: &Document,
        id: &str,
        document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        let bson = document_to_bson(&document);
        self.collection(&collection.get_id())
            .replace_one(doc! { "_uid": id }, bson, None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(document)
    }
    fn delete_document(&mut self, collection: &str, id: &str) -> Result<bool> {
        self.collection(collection)
            .delete_one(doc! { "_uid": id }, None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(true)
    }
    fn find(
        &mut self,
        collection: &Document,
        queries: &[Query],
        limit: Option<i64>,
        offset: Option<i64>,
        _order_attributes: &[String],
        _order_types: &[String],
        _cursor: Option<&Document>,
        _cursor_direction: &str,
        _for_permission: &str,
    ) -> Result<Vec<Document>> {
        let mut filter = doc! {};
        let mut limit_n = limit;
        let mut skip_n = offset;
        for query in queries {
            match query.get_method() {
                TYPE_EQUAL => {
                    let key = mongo_key(query.get_attribute());
                    if let Some(v) = query.get_value().as_str() {
                        filter.insert(key, v);
                    }
                }
                TYPE_NOT_EQUAL => {
                    let key = mongo_key(query.get_attribute());
                    if let Some(v) = query.get_value().as_str() {
                        filter.insert(key, doc! { "$ne": v });
                    }
                }
                TYPE_LIMIT => {
                    limit_n = query.get_value().as_i64().or(limit_n);
                }
                TYPE_OFFSET => {
                    skip_n = query.get_value().as_i64().or(skip_n);
                }
                _ => {}
            }
        }
        let find = self
            .collection(&collection.get_id())
            .find(filter, None)
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        let mut out = Vec::new();
        let skip = skip_n.unwrap_or(0).max(0) as usize;
        let take = limit_n.unwrap_or(i64::MAX).max(0) as usize;
        let mut i = 0usize;
        for doc in find {
            let doc = doc.map_err(|e| DatabaseError::database(e.to_string()))?;
            if i < skip {
                i += 1;
                continue;
            }
            out.push(bson_to_document(doc));
            if out.len() >= take {
                break;
            }
            i += 1;
        }
        Ok(out)
    }
    fn count(&mut self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let n = self
            .find(
                collection,
                queries,
                max,
                None,
                &[],
                &[],
                None,
                crate::constants::CURSOR_AFTER,
                crate::constants::PERMISSION_READ,
            )?
            .len();
        Ok(n as i64)
    }
    fn get_support_for_schemas(&self) -> bool {
        false
    }
    fn get_support_for_attributes(&self) -> bool {
        false
    }
    fn get_support_for_index(&self) -> bool {
        true
    }
    fn get_support_for_unique_index(&self) -> bool {
        true
    }
    fn get_support_for_ttl_indexes(&self) -> bool {
        true
    }
    fn get_support_for_upserts(&self) -> bool {
        true
    }
    fn get_max_index_length(&self) -> i64 {
        0
    }
    fn get_driver(&self) -> AttrValue {
        AttrValue::from("mongo")
    }
}

fn mongo_key(attribute: &str) -> &str {
    match attribute {
        "$id" => "_uid",
        "$createdAt" => "_createdAt",
        "$updatedAt" => "_updatedAt",
        other => other,
    }
}

fn document_to_bson(document: &Document) -> BsonDocument {
    let mut bson = BsonDocument::new();
    bson.insert("_uid", document.get_id());
    if let Some(created) = document.get_created_at() {
        bson.insert("_createdAt", created);
    }
    if let Some(updated) = document.get_updated_at() {
        bson.insert("_updatedAt", updated);
    }
    bson.insert(
        "_permissions",
        serde_json::to_string(&document.get_permissions()).unwrap_or_else(|_| "[]".into()),
    );
    for (k, v) in document.get_attributes() {
        bson.insert(k, attr_to_bson(&v));
    }
    bson
}

fn attr_to_bson(value: &AttrValue) -> Bson {
    match value {
        AttrValue::Null => Bson::Null,
        AttrValue::Bool(b) => Bson::Boolean(*b),
        AttrValue::Number(n) => {
            if let Some(i) = n.as_i64() {
                Bson::Int64(i)
            } else if let Some(f) = n.as_f64() {
                Bson::Double(f)
            } else {
                Bson::String(n.to_string())
            }
        }
        AttrValue::String(s) => Bson::String(s.clone()),
        other => Bson::String(other.to_json().to_string()),
    }
}

fn bson_to_document(bson: BsonDocument) -> Document {
    let mut map = IndexMap::new();
    for (k, v) in bson {
        let key = match k.as_str() {
            "_uid" => "$id".to_owned(),
            "_id" => "$sequence".to_owned(),
            "_createdAt" => "$createdAt".to_owned(),
            "_updatedAt" => "$updatedAt".to_owned(),
            "_permissions" => {
                let parsed = match &v {
                    Bson::String(s) => serde_json::from_str::<Vec<String>>(s).ok(),
                    _ => None,
                };
                map.insert(
                    "$permissions".into(),
                    parsed.map_or_else(|| bson_to_attr(v), AttrValue::from),
                );
                continue;
            }
            other => other.to_owned(),
        };
        map.insert(key, bson_to_attr(v));
    }
    Document::from_map(map).unwrap_or_else(|_| Document::new())
}

fn bson_to_attr(value: Bson) -> AttrValue {
    match value {
        Bson::Null => AttrValue::Null,
        Bson::Boolean(b) => AttrValue::Bool(b),
        Bson::Int32(i) => AttrValue::from(i64::from(i)),
        Bson::Int64(i) => AttrValue::from(i),
        Bson::Double(f) => AttrValue::from(f.to_string()),
        Bson::String(s) => AttrValue::from(s),
        Bson::ObjectId(oid) => AttrValue::from(oid.to_hex()),
        other => AttrValue::from(other.to_string()),
    }
}
