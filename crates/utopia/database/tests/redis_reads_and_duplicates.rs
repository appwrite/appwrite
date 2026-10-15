//! Ported from utopia-php/database 8.1.3 `tests/unit/RedisSkipDuplicatesTest.php`
//! and `tests/unit/RedisLenientReadTest.php`, against a real Redis: the
//! payloads PHP's stub returns are stored on the server.

#![cfg(feature = "redis")]

mod redis_probe;

use std::sync::Arc;

use redis_probe::{Probe, collection, doc, namespace, raw, s};
use serde_json::json;
use utopia_database::adapter::Find;
use utopia_database::adapter::redis::Redis;
use utopia_database::{Adapter, Authorization};

const STORED: &str = "stored";

/// An adapter in namespace `ns`, database `""` (PHP's stub tests set only
/// the namespace).
fn adapter(probe: &Probe, ns: &str) -> Redis<Probe> {
    let adapter = Redis::new(probe.clone());
    adapter.set_namespace(ns);
    adapter
}

#[tokio::test]
async fn test_only_documents_with_a_new_id_are_returned() {
    let probe = probe_or_skip!();
    let ns = namespace("skip_duplicates");
    let adapter = adapter(&probe, &ns);
    let payload = json!({"$id": STORED, "$sequence": "7"}).to_string();
    let _: () = raw(&probe, redis::cmd("SET").arg(format!("utopia:{ns}::doc:notes:{STORED}")).arg(payload)).await;
    let _: () = raw(&probe, redis::cmd("SET").arg(format!("utopia:{ns}::seq:notes")).arg("7")).await;

    let created = adapter
        .state()
        .ignoring_duplicates()
        .with(
            true,
            adapter.create_documents(
                &collection("notes"),
                vec![doc(json!({"$id": STORED})), doc(json!({"$id": "fresh"}))],
            ),
        )
        .await
        .unwrap();

    assert_eq!(created.iter().map(|d| d.id()).collect::<Vec<_>>(), ["fresh"]);
    assert_eq!(created[0].sequence(), Some(&s("8")));
}

#[tokio::test]
async fn test_a_single_skipped_document_still_carries_the_stored_sequence() {
    let probe = probe_or_skip!();
    let ns = namespace("skip_duplicates");
    let adapter = adapter(&probe, &ns);
    let payload = json!({"$id": STORED, "$sequence": "7"}).to_string();
    let _: () = raw(&probe, redis::cmd("SET").arg(format!("utopia:{ns}::doc:notes:{STORED}")).arg(payload)).await;

    let document = adapter
        .state()
        .ignoring_duplicates()
        .with(true, adapter.create_document(&collection("notes"), doc(json!({"$id": STORED}))))
        .await
        .unwrap();

    assert_eq!(document.sequence(), Some(&s("7")));
}

/// A stored payload holding permissions that are not strings.
fn lenient_payload(id: &str) -> String {
    json!({"$id": id, "$permissions": ["read(\"any\")", 42, null, "read(\"any\")"], "title": "stored"}).to_string()
}

#[tokio::test]
async fn test_get_document_drops_a_stored_non_string_permission() {
    let probe = probe_or_skip!();
    let ns = namespace("lenient");
    let adapter = adapter(&probe, &ns);
    let _: () =
        raw(&probe, redis::cmd("SET").arg(format!("utopia:{ns}::doc:notes:note")).arg(lenient_payload("note"))).await;

    let document = adapter.get_document(&collection("notes"), "note", vec![], false).await.unwrap();

    assert_eq!(document.id(), "note");
    assert_eq!(document.permissions().unwrap(), ["read(\"any\")"]);
}

#[tokio::test]
async fn test_find_drops_a_stored_non_string_permission() {
    let probe = probe_or_skip!();
    let ns = namespace("lenient");
    let adapter = adapter(&probe, &ns);
    let authorization = Authorization::new(true);
    authorization.disable();
    adapter.set_authorization(Arc::new(authorization));
    let _: i64 = raw(&probe, redis::cmd("HSET").arg(format!("utopia:{ns}::meta:notes")).arg("attrs").arg("[]")).await;
    for id in ["first", "second"] {
        let _: () =
            raw(&probe, redis::cmd("SET").arg(format!("utopia:{ns}::doc:notes:{id}")).arg(lenient_payload(id))).await;
        let _: i64 = raw(&probe, redis::cmd("SADD").arg(format!("utopia:{ns}::idx:notes")).arg(id)).await;
    }

    let mut documents = adapter.find(&collection("notes"), vec![], Find::default()).await.unwrap();
    documents.sort_by(|a, b| a.id().cmp(b.id()));

    assert_eq!(documents.iter().map(|d| d.id()).collect::<Vec<_>>(), ["first", "second"]);
    for document in &documents {
        assert_eq!(document.permissions().unwrap(), ["read(\"any\")"]);
    }
}
