//! `MongoAdapterPathsTest`.

use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};

use utopia_database::adapter::{Casting, Connection, Find, Relationships};
use utopia_database::collection::Collection;
use utopia_database::operator::{Operator, OperatorType};
use utopia_database::relationship::{self, Relationship, RelationshipSide};
use utopia_database::value::{BsonValue, Value};
use utopia_database::{Adapter, Index, Kind};
use utopia_mongo::bson::{Bson, doc};

use crate::common::{assert_error, assert_error_contains, doc, s, val};
use crate::fake::{Fake, at, batch, error};

const NAMESPACE: &str = "paths";

async fn adapter(fake: &Arc<Fake>) -> utopia_database::adapter::mongo::Mongo {
    crate::fake::adapter(fake, NAMESPACE, false).await
}

fn collection(json: &str) -> Collection {
    Collection::from_document(&doc(json)).unwrap()
}

fn books() -> Collection {
    collection(r#"{"$id":"books"}"#)
}

fn reply_with_index_metadata(fake: &Fake) {
    fake.on("find", |_| {
        Ok(batch(vec![doc! {
            "_uid": "books",
            "indexes": r#"[{"$id":"by_title","key":"by_title","type":"key","attributes":["title"]}]"#,
            "attributes": r#"[{"$id":"title","key":"title","type":"string"}]"#,
        }]))
    });
}

#[tokio::test]
async fn many_to_many_keys_are_renamed_in_the_junction_of_either_side() {
    let fake = Fake::new();
    fake.on("find", |args| {
        let rows = match at(&args[1], "_uid").and_then(Bson::as_str) {
            Some("books") => vec![doc! { "_uid": "books", "_id": "11" }],
            Some("authors") => vec![doc! { "_uid": "authors", "_id": "22" }],
            _ => vec![],
        };
        Ok(batch(rows))
    });
    let adapter = adapter(&fake).await;

    let books = Relationship::many_to_many("authors").two_way(true).key("authors").two_way_key("books");
    adapter
        .update_relationship(
            "books",
            &books,
            RelationshipSide::Parent,
            &relationship::Update { key: Some("writers".into()), ..Default::default() },
        )
        .await
        .unwrap();
    let authors = Relationship::many_to_many("books").two_way(true).key("books").two_way_key("authors");
    adapter
        .update_relationship(
            "authors",
            &authors,
            RelationshipSide::Child,
            &relationship::Update { key: Some("titles".into()), ..Default::default() },
        )
        .await
        .unwrap();

    let updates: Vec<(Bson, Bson)> = fake.args("update").into_iter().map(|a| (a[0].clone(), a[2].clone())).collect();
    assert_eq!(
        updates,
        vec![
            (Bson::String("paths__11_22".into()), Bson::Document(doc! { "$rename": { "authors": "writers" } })),
            (Bson::String("paths__11_22".into()), Bson::Document(doc! { "$rename": { "books": "titles" } })),
        ]
    );
}

#[tokio::test]
async fn fulltext_indexes_are_created_without_the_collation() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    let collation = [("locale".to_owned(), "en".to_owned())];

    adapter.create_index("books", &Index::fulltext("by_text", ["title"]), &[], &collation).await.unwrap();
    adapter
        .create_index("books", &Index::key("by_title", ["title"], vec![], vec![]).unwrap(), &[], &collation)
        .await
        .unwrap();

    let specifications: Vec<Bson> =
        fake.args("createIndexes").into_iter().map(|a| at(&a[1], "0").unwrap().clone()).collect();
    assert_eq!(specifications.len(), 2);
    assert!(at(&specifications[0], "collation").is_none());
    assert_eq!(at(&specifications[1], "collation"), Some(&Bson::Document(doc! { "locale": "en", "strength": 1 })));
}

#[tokio::test]
async fn unique_index_creation_waits_until_the_build_is_ready() {
    let fake = Fake::new();
    let listings = Arc::new(AtomicI64::new(0));
    let counter = listings.clone();
    fake.on("query", move |_| {
        let n = counter.fetch_add(1, Ordering::Relaxed) + 1;
        let state = if n == 1 { "building" } else { "ready" };
        Ok(Bson::Document(doc! { "cursor": { "firstBatch": [{ "name": "unique_title", "buildState": state }] } }))
    });

    let index = Index::unique("unique_title", ["title"], vec![], vec![]).unwrap();
    assert!(adapter(&fake).await.create_index("books", &index, &[], &[]).await.unwrap());
    assert_eq!(listings.load(Ordering::Relaxed), 2, "The index list must be read again while the build is not ready");
}

#[tokio::test]
async fn index_creation_failures_are_mapped() {
    let fake = Fake::new();
    fake.on("createIndexes", |_| Err(error("Index already exists with a different name", 85)));

    let index = Index::key("by_title", ["title"], vec![], vec![]).unwrap();
    let result = adapter(&fake).await.create_index("books", &index, &[], &[]).await;
    assert_error_contains(result, Kind::Duplicate, "Index already exists");
}

#[tokio::test]
async fn renaming_an_index_the_metadata_does_not_list_is_refused() {
    let fake = Fake::new();
    fake.on("find", |_| {
        Ok(batch(vec![doc! {
            "_uid": "books",
            "indexes": r#"[{"$id":"by_title","key":"by_title","type":"key","attributes":["title"]}]"#,
            "attributes": "[]",
        }]))
    });

    let result = adapter(&fake).await.rename_index("books", "missing", "renamed").await;
    assert_error(result, Kind::Database, "Index not found: missing");
    assert!(fake.args("dropIndexes").is_empty());
    assert!(fake.args("createIndexes").is_empty());
}

#[tokio::test]
async fn renaming_an_index_the_schema_does_not_have_fails_with_the_driver_error() {
    let fake = Fake::new();
    reply_with_index_metadata(&fake);
    fake.on("dropIndexes", |_| Err(error("index not found with name [by_title]", 27)));

    let failure = adapter(&fake).await.rename_index("books", "by_title", "by_name").await.unwrap_err();
    assert_eq!(failure.kind(), Kind::Mongo);
    assert_eq!(failure.code(), 27);
    assert!(fake.args("createIndexes").is_empty());
}

#[tokio::test]
async fn renaming_an_index_the_schema_has_rebuilds_it_under_the_new_name() {
    let fake = Fake::new();
    reply_with_index_metadata(&fake);

    assert!(adapter(&fake).await.rename_index("books", "by_title", "by_name").await.unwrap());
    assert_eq!(
        fake.args("dropIndexes"),
        vec![vec![Bson::String("paths_books".into()), Bson::Array(vec![Bson::String("by_title".into())])]]
    );
    let names: Vec<Bson> =
        fake.args("createIndexes").into_iter().map(|a| at(&a[1], "0.name").unwrap().clone()).collect();
    assert_eq!(names, vec![Bson::String("by_name".into())]);
}

#[tokio::test]
async fn a_non_numeric_power_exponent_is_refused() {
    let fake = Fake::new();
    let update = utopia_database::Document::from_pairs([(
        "price",
        Value::from(Operator::new(OperatorType::Power, "", vec![s("two")])),
    )])
    .unwrap();

    let result = adapter(&fake).await.update_document(&books(), "first", update, true).await;
    assert_error(result, Kind::Database, "Invalid numeric operand for operator power");
    assert!(fake.args("query").is_empty(), "No update may reach the server");
}

#[tokio::test]
async fn sequences_are_read_beyond_the_first_batch() {
    let fake = Fake::new();
    fake.on("find", |_| {
        Ok(Bson::Document(doc! { "cursor": { "firstBatch": [{ "_uid": "first", "_id": "one" }], "id": 7 } }))
    });
    fake.on("getMore", |_| {
        Ok(Bson::Document(doc! { "cursor": { "nextBatch": [{ "_uid": "second", "_id": "two" }], "id": 0 } }))
    });

    let documents = adapter(&fake)
        .await
        .get_sequences(&books(), vec![doc(r#"{"$id":"first"}"#), doc(r#"{"$id":"second"}"#)])
        .await
        .unwrap();

    let sequences: Vec<Option<&Value>> = documents.iter().map(|d| d.sequence()).collect();
    assert_eq!(sequences, vec![Some(&s("one")), Some(&s("two"))]);
    let cursors: Vec<Bson> = fake.args("getMore").into_iter().map(|a| a[0].clone()).collect();
    assert_eq!(cursors, vec![Bson::Int64(7)]);
}

#[tokio::test]
async fn sequence_read_failures_are_mapped() {
    let fake = Fake::new();
    fake.on("find", |_| Err(error("operation exceeded time limit", 50)));

    let result = adapter(&fake).await.get_sequences(&books(), vec![doc(r#"{"$id":"first"}"#)]).await;
    assert_error(result, Kind::Timeout, "Query timed out");
}

#[tokio::test]
async fn integer_operator_operands_are_cast_before_the_write() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    let collection = collection(r#"{"attributes":[{"$id":"count","type":"integer","array":false}]}"#);

    let increment = Operator::new(OperatorType::Increment, "", vec![s("5"), s("100")]);
    let document = adapter
        .cast_before(&collection, utopia_database::Document::from_pairs([("count", Value::from(increment))]).unwrap())
        .unwrap();

    let Some(Value::Ext(utopia_database::value::Ext::Operator(operator))) = document.get("count") else {
        panic!("an operator");
    };
    assert_eq!(operator.values().values().cloned().collect::<Vec<_>>(), vec![Value::Int(5), Value::Int(100)]);

    let overflow = Operator::new(OperatorType::Increment, "", vec![s("9223372036854775808")]);
    let result = adapter
        .cast_before(&collection, utopia_database::Document::from_pairs([("count", Value::from(overflow))]).unwrap());
    assert_error_contains(result, Kind::Type, "outside the signed 64-bit range");
}

#[tokio::test]
async fn array_attributes_are_decoded_or_wrapped_before_the_write() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    let collection = collection(
        r#"{"attributes":[
            {"$id":"tags","type":"string","array":true},
            {"$id":"labels","type":"string","array":true},
            {"$id":"meta","type":"object","array":false}
        ]}"#,
    );

    let document = adapter
        .cast_before(
            &collection,
            utopia_database::Document::from_pairs([
                ("tags", s(r#"["a","b"]"#)),
                ("labels", Value::Int(7)),
                ("meta", s(r#"{"colour":"red"}"#)),
            ])
            .unwrap(),
        )
        .unwrap();

    assert_eq!(document.get("tags"), Some(&val(r#"["a","b"]"#)));
    assert_eq!(document.get("labels"), Some(&val("[7]")));
    let Some(Value::Object(meta)) = document.get("meta") else { panic!("a stdClass") };
    assert_eq!(meta.get_str("colour"), Some(&s("red")));

    let result =
        adapter.cast_before(&collection, utopia_database::Document::from_pairs([("tags", s("not json"))]).unwrap());
    assert_error_contains(result, Kind::Database, "Failed to decode JSON for attribute tags");
}

#[tokio::test]
async fn stored_values_are_read_back_by_their_attribute_type() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    let collection = collection(
        r#"{"attributes":[
            {"$id":"tags","type":"string","array":true},
            {"$id":"labels","type":"string","array":true},
            {"$id":"count","type":"integer","array":false}
        ]}"#,
    );

    let empty = adapter.cast_after(&collection, vec![utopia_database::Document::empty()]).unwrap();
    assert!(empty[0].is_empty());

    let document = adapter
        .cast_after(
            &collection,
            vec![
                utopia_database::Document::from_pairs([
                    ("tags", s(r#"["a","b"]"#)),
                    ("labels", Value::Int(7)),
                    ("count", s("42")),
                ])
                .unwrap(),
            ],
        )
        .unwrap()
        .remove(0);

    assert_eq!(document.get("tags"), Some(&val(r#"["a","b"]"#)));
    assert_eq!(document.get("labels"), Some(&val(r#"["7"]"#)));
    assert_eq!(document.get("count"), Some(&Value::Int(42)));

    let result = adapter
        .cast_after(&collection, vec![utopia_database::Document::from_pairs([("tags", s("not json"))]).unwrap()]);
    assert_error_contains(result, Kind::Database, "Failed to decode JSON for attribute tags");
}

#[tokio::test]
async fn schemaless_casting_keeps_strings_that_are_not_dates() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    adapter.schemaless().unwrap().set_schemaless(true);
    let collection = collection(r#"{"indexes":[{"$id":"expiry","type":"ttl","attributes":["expiresAt"]}]}"#);

    let document = adapter
        .cast_before(
            &collection,
            utopia_database::Document::from_pairs([("expiresAt", s("2026-13-45T99:99:99Z")), ("label", s("plain"))])
                .unwrap(),
        )
        .unwrap();

    assert_eq!(document.get("expiresAt"), Some(&s("2026-13-45T99:99:99Z")));
    assert_eq!(document.get("label"), Some(&s("plain")));
}

/// The `Int64` half of PHP's test has no counterpart: the driver decodes
/// every BSON integer as an integer, as utopia-php/mongo unwraps `Int64`.
#[tokio::test]
async fn numeric_dates_and_int64_values_are_converted() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;

    let before = adapter
        .cast_before(
            &collection(r#"{"attributes":[{"$id":"when","type":"datetime","array":false}]}"#),
            utopia_database::Document::from_pairs([("when", s("1700000000000"))]).unwrap(),
        )
        .unwrap();
    assert_eq!(before.get("when"), Some(&Value::from(BsonValue::UtcDateTime(1_700_000_000_000))));

    let after = adapter
        .cast_after(
            &collection(r#"{"attributes":[{"$id":"count","type":"biginteger","array":false}]}"#),
            vec![utopia_database::Document::from_pairs([("count", Value::Int(9_007_199_254_740_993))]).unwrap()],
        )
        .unwrap()
        .remove(0);
    assert_eq!(after.get("count"), Some(&Value::Int(9_007_199_254_740_993)));
}

#[tokio::test]
async fn reconnect_reconnects_the_client() {
    let fake = Fake::new();
    let adapter = adapter(&fake).await;
    let connections = fake.args("connect").len();

    Connection::reconnect(&adapter).await.unwrap();

    assert_eq!(fake.args("connect").len(), connections + 1);
}

#[tokio::test]
async fn operators_refuse_operands_of_the_wrong_type() {
    let fake = Fake::new();
    let cases = [
        (
            Operator::new(OperatorType::DateAddDays, "", vec![s("5")]),
            "Invalid integer operand for operator dateAddDays",
        ),
        (
            Operator::new(OperatorType::ArrayInsert, "", vec![s("1"), s("x")]),
            "Invalid integer operand for operator arrayInsert",
        ),
        (
            Operator::new(OperatorType::ArrayFilter, "", vec![Value::Int(5)]),
            "Invalid string operand for operator arrayFilter",
        ),
    ];

    for (operator, message) in cases {
        let update = utopia_database::Document::from_pairs([("value", Value::from(operator))]).unwrap();
        let result = adapter(&fake).await.update_document(&books(), "first", update, true).await;
        assert_error(result, Kind::Database, message);
    }

    assert!(fake.args("query").is_empty(), "No update may reach the server");
}

#[tokio::test]
async fn a_read_ending_on_a_full_batch_kills_its_cursor() {
    let fake = Fake::new();
    fake.on("find", |_| {
        Ok(Bson::Document(doc! { "cursor": {
            "firstBatch": [{ "_uid": "row1" }, { "_uid": "row2" }, { "_uid": "row3" }],
            "id": 7,
        } }))
    });
    fake.on("getMore", |_| Ok(Bson::Document(doc! { "cursor": { "nextBatch": [], "id": 7 } })));

    let documents = adapter(&fake).await.find(&books(), vec![], Find { limit: None, ..Find::default() }).await.unwrap();

    assert_eq!(documents.len(), 3);
    let commands: Vec<Bson> = fake.args("query").into_iter().map(|a| a[0].clone()).collect();
    assert_eq!(commands, vec![Bson::Document(doc! { "killCursors": "paths_books", "cursors": [7] })]);
}

#[tokio::test]
async fn responses_without_a_cursor_id_end_the_read() {
    let fake = Fake::new();
    fake.on("find", |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": [{ "_uid": "first" }] } })));

    let found = adapter(&fake).await.find(&books(), vec![], Find { limit: None, ..Find::default() }).await.unwrap();
    assert_eq!(found.len(), 1);
    assert!(fake.args("getMore").is_empty());

    fake.clear();
    fake.on("find", |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": [{ "_uid": "first" }], "id": 7 } })));
    fake.on("getMore", |_| Ok(Bson::Document(doc! { "cursor": { "nextBatch": [{ "_uid": "second" }] } })));

    let found = adapter(&fake).await.find(&books(), vec![], Find { limit: None, ..Find::default() }).await.unwrap();
    assert_eq!(found.len(), 2);
    assert_eq!(fake.args("getMore").len(), 1);
    assert!(fake.args("query").is_empty(), "A cursor the server closed must not be killed");
}

#[tokio::test]
async fn dollar_prefixed_user_key_round_trips() {
    let fake = Fake::new();
    fake.on("find", |_| Ok(batch(vec![doc! { "_uid": "first", "_custom": "x" }])));

    let created = adapter(&fake)
        .await
        .create_document(&books(), doc(r#"{"$id":"first","$permissions":[],"$custom":"x"}"#))
        .await
        .unwrap();

    let inserted = fake.args("insert").remove(0).remove(1);
    assert_eq!(at(&inserted, "_custom"), Some(&Bson::String("x".into())));
    assert!(at(&inserted, "$custom").is_none());
    assert_eq!(created.get("$custom"), Some(&s("x")));
}
