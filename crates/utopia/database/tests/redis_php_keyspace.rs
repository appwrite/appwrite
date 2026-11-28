//! The Redis adapter writes the keyspace PHP's writes: the same sequence
//! of schema, document, permission, operator, upsert, relationship and
//! transaction calls leaves keys, document payloads, meta records and
//! permission sets byte for byte as utopia-php/database 8.1.3 leaves them.
//!
//! `fixtures/redis_keyspace_php.json` is the keyspace PHP 8.5 wrote with
//! phpredis (namespace written `{ns}`; set members sorted; hash fields
//! compared sorted, since Redis orders a hash table's fields itself).
//! Regenerate it with the script in the fixture's sibling
//! `redis_keyspace_php.php`.

#![cfg(feature = "redis")]

mod redis_probe;

use std::collections::BTreeMap;

use redis_probe::{Content, collection, doc, namespace, snapshot};
use serde_json::{Value as Json, json};
use utopia_database::adapter::redis::Redis;
use utopia_database::attribute::Attribute;
use utopia_database::operator::Operator;
use utopia_database::relationship::Relationship;
use utopia_database::value::Value;
use utopia_database::{Adapter, Change, Document, Index};
use utopia_query::OrderDirection;

/// A keyspace as the fixture spells it.
fn normalize(snapshot: BTreeMap<String, Content>, ns: &str) -> BTreeMap<String, Json> {
    let text = |s: &str| s.replace(ns, "{ns}");
    snapshot
        .into_iter()
        .map(|(key, content)| {
            let value = match content {
                Content::String(bytes) => json!(["string", text(&String::from_utf8_lossy(&bytes))]),
                Content::Set(members) => {
                    let mut members: Vec<String> = members.iter().map(|m| text(m)).collect();
                    members.sort();
                    json!(["set", members])
                }
                Content::Hash(pairs) => {
                    let mut pairs: Vec<(String, String)> = pairs.iter().map(|(f, v)| (f.clone(), text(v))).collect();
                    pairs.sort();
                    json!(["hash", pairs])
                }
                Content::Other(kind) => json!([kind]),
            };
            (text(&key), value)
        })
        .collect()
}

/// The fixture, hash fields sorted.
fn fixture() -> BTreeMap<String, Json> {
    let raw: BTreeMap<String, Json> =
        serde_json::from_str(include_str!("fixtures/redis_keyspace_php.json")).expect("the fixture is JSON");
    raw.into_iter()
        .map(|(key, mut value)| {
            if value[0] == "hash" {
                let mut pairs: Vec<(String, String)> = serde_json::from_value(value[1].clone()).unwrap();
                pairs.sort();
                value = json!(["hash", pairs]);
            }
            (key, value)
        })
        .collect()
}

fn permissions(list: &[&str]) -> Json {
    json!(list)
}

#[utopia_async::test]
async fn test_the_keyspace_is_the_one_php_writes() {
    let probe = probe_or_skip!();
    let ns = namespace("oracle_rs");
    let a = redis_probe::adapter(&probe, "db", &ns).await;

    a.create_collection(
        "notes",
        &[
            Attribute::string("title", 64),
            Attribute::integer("count"),
            Attribute::big_integer("big"),
            Attribute::string("tags", 16).array(true),
            Attribute::object("meta"),
            Attribute::float("ratio"),
        ],
        &[
            Index::unique("u_title", ["title"], vec![Some(64)], vec![]).unwrap(),
            Index::key("k_count", ["count"], vec![], vec![Some(OrderDirection::Desc)]).unwrap(),
        ],
    )
    .await
    .unwrap();
    a.create_collection("other", &[], &[]).await.unwrap();
    a.create_attribute("notes", &Attribute::string("extra", 10)).await.unwrap();
    a.update_attribute("notes", "extra", &Attribute::string("renamed", 20).required(true)).await.unwrap();
    a.create_index("notes", &Index::key("k_big", ["big"], vec![], vec![]).unwrap(), &[], &[]).await.unwrap();
    a.rename_index("notes", "k_big", "k_big2").await.unwrap();
    let notes = collection("notes");
    a.create_document(
        &notes,
        doc(json!({
            "$id": "Doc1",
            "$permissions": permissions(&["read(\"any\")", "update(\"user:a\")", "delete(\"user:a\")", "read(\"user:a\")"]),
            "$createdAt": "2026-01-01 00:00:00.000",
            "$updatedAt": "2026-01-01 00:00:00.000",
            "title": "héllo/ \"world\" ✓ <tag> & 'q'",
            "count": 5,
            "big": "18446744073709551615",
            "tags": ["a", "b"],
            "meta": {"x": [1, 2], "y": [], "z": 1.0, "w": {"$id": "nested", "v": 0.5}},
            "ratio": 1.0,
        })),
    )
    .await
    .unwrap();
    a.create_document(
        &notes,
        doc(json!({"$id": "doc2", "$permissions": [], "title": "two", "count": 7, "ratio": 2.5, "tags": []})),
    )
    .await
    .unwrap();
    let mut update = Document::empty();
    update.set("count", Value::from(Operator::increment(3, None))).unwrap();
    update.set("tags", Value::from(Operator::array_append([Value::from("z")]))).unwrap();
    update.set("title", Value::from(Operator::string_concat(Value::from("!")))).unwrap();
    update.set("ratio", Value::from(Operator::multiply(2, None))).unwrap();
    update.set("$permissions", utopia_database::value::list([Value::from("read(\"users\")")])).unwrap();
    a.update_document(&notes, "doc2", update, false).await.unwrap();
    a.increase_document_attribute(&notes, "Doc1", "count", Value::Int(2), "2026-01-02 00:00:00.000", None, None)
        .await
        .unwrap();
    a.upserts()
        .unwrap()
        .upsert_documents(
            &notes,
            vec![
                Change::new(
                    Document::empty(),
                    doc(json!({"$id": "doc3", "$permissions": ["read(\"any\")"], "title": "three", "count": 1})),
                ),
                Change::new(
                    Document::empty(),
                    doc(json!({"$id": "doc2", "$permissions": ["read(\"any\")"], "title": "two!!", "count": 1})),
                ),
            ],
            Some("count"),
        )
        .await
        .unwrap();
    a.relationships()
        .unwrap()
        .create_relationship(
            "notes",
            &Relationship::one_to_one("other").two_way(true).key("partner").two_way_key("partnerOf"),
        )
        .await
        .unwrap();
    a.delete_attribute("notes", "ratio").await.unwrap();
    a.rename_attribute("notes", "tags", "labels").await.unwrap();
    a.delete_document(&notes, "doc3").await.unwrap();
    a.start_transaction().await.unwrap();
    a.create_document(&notes, doc(json!({"$id": "doc4", "$permissions": ["read(\"any\")"], "title": "four"})))
        .await
        .unwrap();
    a.update_document(&notes, "Doc1", doc(json!({"title": "changed", "$permissions": []})), false).await.unwrap();
    a.delete_document(&notes, "doc2").await.unwrap();
    a.rollback_transaction().await.unwrap();
    a.start_transaction().await.unwrap();
    a.create_document(
        &notes,
        doc(json!({"$id": "doc5", "$sequence": "40", "$permissions": ["write(\"any\")"], "title": "five"})),
    )
    .await
    .unwrap();
    a.commit_transaction().await.unwrap();

    let s = Redis::new(probe.clone());
    s.set_authorization(a.authorization());
    s.set_database("db");
    s.set_namespace(&format!("{ns}_shared"));
    s.set_shared_tables(true);
    s.set_tenant(Value::Int(3));
    s.create("db").await.unwrap();
    s.create_collection(
        "notes",
        &[Attribute::string("title", 64)],
        &[Index::unique("u", ["title"], vec![], vec![]).unwrap()],
    )
    .await
    .unwrap();
    s.create_document(&notes, doc(json!({"$id": "a", "$tenant": 3, "$permissions": ["read(\"any\")"], "title": "x"})))
        .await
        .unwrap();
    s.create_document(
        &notes,
        doc(json!({"$id": "b", "$tenant": 4, "$permissions": ["read(\"team:t/owner\")"], "title": "x"})),
    )
    .await
    .unwrap();
    s.set_tenant(Value::Null);
    s.create_document(&collection("_metadata"), doc(json!({"$id": "notes", "$permissions": [], "name": "notes"})))
        .await
        .unwrap();

    let written = normalize(snapshot(&probe, &format!("utopia:{ns}")).await, &ns);
    let expected = fixture();
    for (key, value) in &expected {
        assert_eq!(written.get(key), Some(value), "{key}");
    }
    assert_eq!(written.keys().collect::<Vec<_>>(), expected.keys().collect::<Vec<_>>());

    // What PHP reads back from these keys (`find` ordered by count, then
    // a document by its upper-cased id).
    let found = a
        .find(
            &notes,
            vec![],
            utopia_database::adapter::Find {
                order_attributes: vec!["count".into()],
                order_types: vec![OrderDirection::Asc],
                ..Default::default()
            },
        )
        .await
        .unwrap();
    assert_eq!(found.iter().map(Document::id).collect::<Vec<_>>(), ["doc5", "Doc1", "doc2"]);
    let doc1 = a.get_document(&notes, "doc1", vec![], false).await.unwrap();
    assert_eq!(doc1.id(), "Doc1");
    assert_eq!(doc1.get("partner"), None, "a relationship key is surfaced as null");
    assert!(doc1.has("partner"));
    assert_eq!(a.count(&notes, vec![], None).await.unwrap(), 3);
    assert!(a.sum(&notes, "count", vec![], None).await.unwrap().strict_eq(&Value::Int(18)));
}
