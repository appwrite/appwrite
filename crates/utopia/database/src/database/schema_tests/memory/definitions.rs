//! PHP: `DefensiveCopyTest` (Memory cases), `LegacyAttributeFormatTest`
//! (Memory case), `Collections\MetadataModelTest`. Rust's values are copies
//! already; these check what the copies hold.

use std::sync::Arc;

use async_trait::async_trait;
use utopia_query::OrderDirection;

use super::{collection, memory};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::testing::block_on;
use crate::database::{Hook, MAX_ARRAY_INDEX_LENGTH, METADATA, collection_definition};
use crate::document::Document;
use crate::error::Result;
use crate::event::Event;
use crate::hook::Decorator;
use crate::index::Index;
use crate::value::{Array, ArrayKey, Str, Value};

fn attributes() -> Vec<Attribute> {
    vec![
        Attribute::string("name", 256).required(true),
        Attribute::string("tags", 64).array(true),
        Attribute::datetime("expiresAt"),
    ]
}

fn indexes() -> Vec<Index> {
    vec![
        Index::key("_key_name", ["name"], vec![Some(256)], vec![Some(OrderDirection::Asc)]).unwrap(),
        Index::key("_key_tags", ["tags"], vec![Some(64)], vec![Some(OrderDirection::Desc)]).unwrap(),
    ]
}

#[test]
fn create_collection_stores_definitions_fitted_to_the_engine() {
    block_on(async {
        let (_, db) = memory().await;
        let given = (attributes(), indexes());
        let created = db.create_collection(collection("databases", given.0.clone(), given.1.clone())).await.unwrap();
        assert_eq!((attributes(), indexes()), given, "the caller's definitions are untouched");
        assert_eq!(created.attributes().unwrap()[2].filters, ["datetime"]);
        let stored = created.indexes().unwrap();
        assert_eq!(stored[0].lengths, vec![None]);
        assert_eq!(stored[0].orders, vec![Some(OrderDirection::Asc)]);
        assert_eq!(stored[1].lengths, vec![Some(MAX_ARRAY_INDEX_LENGTH)]);
        assert_eq!(stored[1].orders, vec![None]);

        let (_, fresh) = memory().await;
        let again = fresh.create_collection(collection("databases", attributes(), indexes())).await.unwrap();
        assert_eq!(again.attributes().unwrap(), created.attributes().unwrap());
        assert_eq!(again.indexes().unwrap(), created.indexes().unwrap());
    });
}

#[test]
fn create_attribute_and_index_store_the_fitted_definitions() {
    block_on(async {
        let (_, db) = memory().await;
        db.create_collection(collection("events", vec![Attribute::string("tags", 64).array(true)], Vec::new()))
            .await
            .unwrap();
        db.create_attribute("events", Attribute::datetime("startsAt")).await.unwrap();
        db.create_attributes("events", vec![Attribute::datetime("endsAt"), Attribute::string("label", 32)]).await.unwrap();
        let events = db.get_collection("events").await.unwrap();
        assert_eq!(events.attributes().unwrap()[1].filters, ["datetime"]);
        assert_eq!(events.attributes().unwrap()[2].filters, ["datetime"]);

        let index = Index::key("_key_tags", ["tags"], vec![Some(64)], vec![Some(OrderDirection::Desc)]).unwrap();
        db.create_index("events", index).await.unwrap();
        let stored = db.get_collection("events").await.unwrap().indexes().unwrap()[0].clone();
        assert_eq!(stored.lengths, vec![Some(MAX_ARRAY_INDEX_LENGTH)]);
        assert_eq!(stored.orders, vec![None]);
    });
}

#[test]
fn a_stored_empty_format_reads_back_like_the_definition() {
    block_on(async {
        let (_, db) = memory().await;
        let definition = Attribute::string("resourceInternalId", crate::database::LENGTH_KEY);
        db.create_collection(collection("migrations", vec![definition.clone()], Vec::new())).await.unwrap();

        let mut legacy = definition.to_document().unwrap().into_array();
        legacy.set(ArrayKey::Str(Str::from("format")), Value::Str(Str::from("")));
        let mut update = Document::empty();
        update.set("attributes", Value::Array(Array::from_list(vec![Value::Array(legacy)]))).unwrap();
        let authorization = db.authorization();
        authorization.skip(db.update_document(METADATA, "migrations", update)).await.unwrap();

        let stored = db.get_collection("migrations").await.unwrap().attributes().unwrap()[0].to_document().unwrap();
        let expected = definition.to_document().unwrap();
        for key in ["type", "size", "required", "default", "signed", "array", "format", "formatOptions", "filters"] {
            assert_eq!(stored.raw(key), expected.raw(key), "stored '{key}' differs from the definition");
        }
    });
}

#[test]
fn metadata_collection_reads_are_the_definition() {
    block_on(async {
        let (_, db) = memory().await;
        let expected = Collection::from_array(collection_definition().document().array_copy()).unwrap();
        let mut first = db.get_collection(METADATA).await.unwrap();
        assert_eq!(first.document().as_array(), expected.document().as_array());
        first.set("name", Value::Str(Str::from("changed"))).unwrap();
        let second = db.get_collection(METADATA).await.unwrap();
        assert_eq!(second.document().as_array(), expected.document().as_array());

        let read = db.get_document(METADATA, METADATA, Vec::new(), false).await.unwrap();
        assert_eq!(read.as_array(), collection_definition().document().as_array());
    });
}

struct Decorating;

#[async_trait]
impl Decorator for Decorating {
    async fn decorate(&self, _event: Event, _collection: &Collection, mut document: Document) -> Result<Document> {
        document.set("decorated", Value::Bool(true))?;
        Ok(document)
    }
}

#[test]
fn the_metadata_definition_read_under_a_decorator_is_the_definition() {
    block_on(async {
        let (_, db) = memory().await;
        db.add_hook(Hook::decorator(Arc::new(Decorating))).unwrap();
        let read = db.get_document(METADATA, METADATA, Vec::new(), false).await.unwrap();
        assert_eq!(read.as_array(), collection_definition().document().as_array());
    });
}
