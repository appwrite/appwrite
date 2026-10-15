//! Ported from utopia-php/database 8.1.3 `tests/unit/Adapter/RedisUniqueIndexTest.php`,
//! against a real Redis, at the adapter level: PHP drives these through a
//! `Database` (not yet ported); the adapter calls it makes
//! (`updateDocuments`, `upsertDocuments`, `createDocument`,
//! `deleteCollection`, `getSizeOfCollection`, `find` under a reader's
//! roles) are made here directly. The sizing tests refuse `MEMORY USAGE`, as
//! PHP's fake client does, so sizes are the bytes of each key and its
//! content.

#![cfg(feature = "redis")]

mod redis_probe;

use std::sync::Arc;
use std::sync::atomic::Ordering;

use redis_probe::{Content, Probe, collection, doc, namespace, raw, snapshot};
use serde_json::json;
use utopia_database::adapter::Find;
use utopia_database::adapter::redis::Redis;
use utopia_database::attribute::Attribute;
use utopia_database::value::Value;
use utopia_database::{Adapter, Authorization, Change, Document, Index, Kind};

const USERS: &str = "users";
const NOTES: &str = "notes";
const NOTE: &str = "note";
const KEY_SEGMENT: &str = "doc";
const TENANT: i64 = 5;
const OTHER_TENANT: i64 = 6;

async fn database(probe: &Probe, ns: &str) -> Redis<Probe> {
    let adapter = redis_probe::adapter(probe, "redis_unique", ns).await;
    let authorization = Authorization::new(true);
    authorization.add_role("any");
    adapter.set_authorization(Arc::new(authorization));
    adapter
}

async fn create_users(adapter: &Redis<Probe>) {
    adapter.create_collection(USERS, &[Attribute::string("email", 128)], &[]).await.unwrap();
    let index = Index::unique("emailUnique", ["email"], vec![Some(128)], vec![]).unwrap();
    adapter.create_index(USERS, &index, &[], &[]).await.unwrap();
}

fn user(id: &str, email: &str) -> Document {
    doc(json!({"$id": id, "email": email, "$permissions": []}))
}

async fn users_database(probe: &Probe) -> Redis<Probe> {
    let adapter = database(probe, &namespace("unique_users")).await;
    create_users(&adapter).await;
    for id in ["first", "second", "third"] {
        adapter.create_document(&collection(USERS), user(id, &format!("{id}@example.test"))).await.unwrap();
    }
    adapter
}

/// The stored emails, sorted; the users collection has no document
/// security, so the database reads it without the document permissions.
async fn emails(adapter: &Redis<Probe>) -> Vec<String> {
    let authorization = adapter.authorization();
    let mut emails: Vec<String> = authorization
        .skip(adapter.find(&collection(USERS), vec![], Find { limit: None, ..Find::default() }))
        .await
        .unwrap()
        .iter()
        .map(|d| d.get("email").map(utopia_database::value::to_text).unwrap_or_default())
        .collect();
    emails.sort();
    emails
}

async fn stored(adapter: &Redis<Probe>, id: &str) -> Document {
    adapter.get_document(&collection(USERS), id, vec![], false).await.unwrap()
}

fn assert_unique(e: utopia_database::Error) {
    assert_eq!(e.kind(), Kind::Unique);
    assert_eq!(e.message(), "Unique index violation");
}

fn upsert(id: &str, email: &str) -> Change {
    Change::new(Document::empty(), user(id, email))
}

#[tokio::test]
async fn test_update_documents_rejects_a_duplicate_unique_value() {
    let probe = probe_or_skip!();
    let adapter = users_database(&probe).await;
    let second = stored(&adapter, "second").await;

    let e = adapter
        .update_documents(&collection(USERS), &doc(json!({"email": "first@example.test"})), vec![second.clone()], &[])
        .await
        .unwrap_err();
    assert_unique(e);

    assert_eq!(emails(&adapter).await, ["first@example.test", "second@example.test", "third@example.test"]);
    let update = |email: &str| doc(json!({"email": email}));
    assert_eq!(
        adapter
            .update_documents(&collection(USERS), &update("second@example.test"), vec![second.clone()], &[])
            .await
            .unwrap(),
        1
    );
    assert_eq!(
        adapter.update_documents(&collection(USERS), &update("renamed@example.test"), vec![second], &[]).await.unwrap(),
        1
    );
    assert_eq!(emails(&adapter).await, ["first@example.test", "renamed@example.test", "third@example.test"]);
}

#[tokio::test]
async fn test_upsert_rejects_a_duplicate_unique_value() {
    let probe = probe_or_skip!();
    let adapter = users_database(&probe).await;
    let upserts = adapter.upserts().unwrap();

    let e = upserts.upsert_documents(&collection(USERS), vec![upsert("second", "first@example.test")], None).await;
    assert_unique(e.unwrap_err());

    assert_eq!(emails(&adapter).await, ["first@example.test", "second@example.test", "third@example.test"]);
    let written = upserts
        .upsert_documents(&collection(USERS), vec![upsert("second", "renamed@example.test")], None)
        .await
        .unwrap();
    assert_eq!(written.len(), 1);
    assert_eq!(emails(&adapter).await, ["first@example.test", "renamed@example.test", "third@example.test"]);
}

#[tokio::test]
async fn test_a_batch_cannot_collide_with_itself() {
    let probe = probe_or_skip!();
    let adapter = users_database(&probe).await;
    let batch = vec![stored(&adapter, "second").await, stored(&adapter, "third").await];

    let e = adapter
        .update_documents(&collection(USERS), &doc(json!({"email": "shared@example.test"})), batch, &[])
        .await
        .unwrap_err();
    assert_unique(e);

    let e = adapter
        .upserts()
        .unwrap()
        .upsert_documents(
            &collection(USERS),
            vec![upsert("second", "shared@example.test"), upsert("third", "shared@example.test")],
            None,
        )
        .await
        .unwrap_err();
    assert_unique(e);

    assert_eq!(emails(&adapter).await, ["first@example.test", "second@example.test", "third@example.test"]);
}

#[tokio::test]
async fn test_an_upsert_batch_reads_the_stored_documents_once_for_its_unique_checks() {
    let probe = probe_or_skip!();
    let adapter = users_database(&probe).await;
    let upserts = adapter.upserts().unwrap();
    let start = probe.member_reads();
    let written = upserts
        .upsert_documents(&collection(USERS), vec![upsert("first", "first-moved@example.test")], None)
        .await
        .unwrap();
    assert_eq!(written.len(), 1);
    let single = probe.member_reads() - start;

    let start = probe.member_reads();
    let written = upserts
        .upsert_documents(
            &collection(USERS),
            vec![
                upsert("third", "moved@example.test"),
                upsert("second", "renamed@example.test"),
                upsert("fourth", "fourth@example.test"),
                upsert("fifth", "fifth@example.test"),
            ],
            None,
        )
        .await
        .unwrap();
    assert_eq!(written.len(), 4);

    assert_eq!(
        probe.member_reads() - start,
        single,
        "The unique checks must read the collection once per batch, not once per document"
    );
    assert_eq!(
        emails(&adapter).await,
        [
            "fifth@example.test",
            "first-moved@example.test",
            "fourth@example.test",
            "moved@example.test",
            "renamed@example.test"
        ]
    );
}

#[tokio::test]
async fn test_an_upsert_batch_checks_each_document_against_the_ones_before_it() {
    let probe = probe_or_skip!();
    let adapter = users_database(&probe).await;
    let upserts = adapter.upserts().unwrap();

    let e = upserts
        .upsert_documents(
            &collection(USERS),
            vec![upsert("fourth", "second@example.test"), upsert("second", "renamed@example.test")],
            None,
        )
        .await
        .unwrap_err();
    assert_unique(e);
    assert_eq!(emails(&adapter).await, ["first@example.test", "second@example.test", "third@example.test"]);

    let written = upserts
        .upsert_documents(
            &collection(USERS),
            vec![upsert("second", "renamed@example.test"), upsert("fourth", "second@example.test")],
            None,
        )
        .await
        .unwrap();
    assert_eq!(written.len(), 2);
    assert_eq!(
        emails(&adapter).await,
        ["first@example.test", "renamed@example.test", "second@example.test", "third@example.test"]
    );
}

#[tokio::test]
async fn test_tenant_per_document_checks_the_documents_tenant() {
    let probe = probe_or_skip!();
    let adapter = database(&probe, &namespace("unique_tenants")).await;
    adapter.set_shared_tables(true);
    adapter.set_tenant(Value::Null);
    adapter.set_tenant_per_document(true);
    create_users(&adapter).await;
    let tenanted = |id: &str, tenant: i64| {
        doc(json!({"$id": id, "email": "taken@example.test", "$permissions": [], "$tenant": tenant}))
    };

    adapter.create_document(&collection(USERS), tenanted("first", TENANT)).await.unwrap();

    let e = adapter.create_document(&collection(USERS), tenanted("second", TENANT)).await.unwrap_err();
    assert_unique(e);

    adapter.create_document(&collection(USERS), tenanted("second", OTHER_TENANT)).await.unwrap();

    for tenant in [TENANT, OTHER_TENANT] {
        adapter.set_tenant(Value::Int(tenant));
        assert_eq!(emails(&adapter).await, ["taken@example.test"]);
    }
}

/// The notes collection with a note readable by alice and bob, under
/// shared tables (tenant 5) or not.
async fn notes_database(probe: &Probe, shared_tables: bool) -> (Redis<Probe>, String) {
    let ns = namespace("unique_notes");
    let adapter = database(probe, &ns).await;
    adapter.set_shared_tables(shared_tables);
    if shared_tables {
        adapter.set_tenant(Value::Int(TENANT));
    }
    adapter.create_collection(NOTES, &[Attribute::string("title", 64)], &[]).await.unwrap();
    adapter
        .create_document(&collection(NOTES), readers(NOTE, &["alice", "bob"], "kept", shared_tables.then_some(TENANT)))
        .await
        .unwrap();
    (adapter, ns)
}

fn readers(id: &str, readers: &[&str], title: &str, tenant: Option<i64>) -> Document {
    let permissions: Vec<String> = readers.iter().map(|r| format!("read(\"user:{r}\")")).collect();
    let mut document = json!({"$id": id, "$permissions": permissions, "title": title});
    if let Some(tenant) = tenant {
        document["$tenant"] = json!(tenant);
    }
    doc(document)
}

async fn readable_by(adapter: &Redis<Probe>, reader: &str) -> Vec<String> {
    let authorization = adapter.authorization();
    let roles = authorization.role_names();
    authorization.clean_roles();
    authorization.add_role(&format!("user:{reader}"));
    let found = adapter.find(&collection(NOTES), vec![], Find::default()).await;
    authorization.clean_roles();
    for role in roles {
        authorization.add_role(&role);
    }
    found.unwrap().iter().map(|d| d.id().to_owned()).collect()
}

/// The keys a collection owns that exist (`keysOf()`).
async fn keys_of(probe: &Probe, ns: &str, shared_tables: bool, collection: &str) -> Vec<String> {
    let prefix = format!("utopia:{ns}:redis_unique:");
    let tenant = if shared_tables { format!("t:{TENANT}:") } else { String::new() };
    let mut owned = vec![
        format!("{prefix}meta:{collection}"),
        format!("{prefix}grants:{collection}"),
        format!("{prefix}idx:{tenant}{collection}"),
        format!("{prefix}seq:{tenant}{collection}"),
        format!("{prefix}doc:{tenant}{collection}:{NOTE}"),
        format!("{prefix}perm:{tenant}doc:{collection}:{NOTE}"),
    ];
    for letter in ["r", "c", "u", "d"] {
        for role in ["any", "user:alice", "user:bob"] {
            owned.push(format!("{prefix}perm:{tenant}{collection}:{letter}:{role}"));
        }
    }
    let present = snapshot(probe, &prefix).await;
    owned.into_iter().filter(|k| present.contains_key(k)).collect()
}

/// The bytes of a key and its content (`bytes()`).
async fn bytes(probe: &Probe, key: &str) -> i64 {
    let content = snapshot(probe, key).await.remove(key);
    key.len() as i64
        + match content {
            Some(Content::String(s)) => s.len() as i64,
            Some(Content::Set(members)) => members.iter().map(|m| m.len() as i64).sum(),
            Some(Content::Hash(pairs)) => pairs.iter().map(|(f, v)| (f.len() + v.len()) as i64).sum(),
            _ => 0,
        }
}

/// `bytesOf()`: the bytes of the collection's keys but its grant set and
/// sequence.
async fn bytes_of(probe: &Probe, ns: &str, shared_tables: bool, collection: &str) -> i64 {
    let mut total = 0;
    for key in keys_of(probe, ns, shared_tables, collection).await {
        if key.contains(":grants:") || key.contains(":seq:") {
            continue;
        }
        total += bytes(probe, &key).await;
    }
    total
}

/// Deletes the keys `pattern` matches.
async fn forget(probe: &Probe, pattern: &str) {
    let keys: Vec<String> = raw(probe, redis::cmd("KEYS").arg(pattern)).await;
    for key in keys {
        let _: i64 = raw(probe, redis::cmd("DEL").arg(key)).await;
    }
}

#[tokio::test]
async fn test_dropping_a_collection_named_like_a_key_segment_keeps_other_grants() {
    let probe = probe_or_skip!();
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        let tenant = shared_tables.then_some(TENANT);
        adapter.create_collection(KEY_SEGMENT, &[Attribute::string("title", 64)], &[]).await.unwrap();
        let mut dropped = readers(NOTE, &[], "dropped", tenant);
        dropped.set("$permissions", utopia_database::value::list([redis_probe::s("read(\"any\")")])).unwrap();
        adapter.create_document(&collection(KEY_SEGMENT), dropped).await.unwrap();

        adapter.delete_collection(KEY_SEGMENT).await.unwrap();
        let mut update = readers(NOTE, &["alice"], "kept", tenant);
        update.remove("$id");
        adapter.update_document(&collection(NOTES), NOTE, update, false).await.unwrap();

        assert_eq!(readable_by(&adapter, "alice").await, [NOTE]);
        assert!(
            readable_by(&adapter, "bob").await.is_empty(),
            "Dropping another collection must leave the grants a later revoke removes"
        );
        assert!(
            keys_of(&probe, &ns, shared_tables, KEY_SEGMENT).await.is_empty(),
            "Dropping a collection must remove every key it owns"
        );
    }
}

#[tokio::test]
async fn test_dropping_a_collection_removes_grants_written_before_the_registry() {
    let probe = probe_or_skip!();
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        forget(&probe, &format!("utopia:{ns}:*:grants:*")).await;

        adapter.delete_collection(NOTES).await.unwrap();

        assert!(
            keys_of(&probe, &ns, shared_tables, NOTES).await.is_empty(),
            "Dropping a collection must remove the grants written before the registry existed"
        );
    }
}

#[tokio::test]
async fn test_dropping_a_collection_removes_registered_grants_its_id_index_misses() {
    let probe = probe_or_skip!();
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        forget(&probe, &format!("utopia:{ns}:*:idx:*{NOTES}")).await;

        adapter.delete_collection(NOTES).await.unwrap();

        let grants: Vec<String> =
            keys_of(&probe, &ns, shared_tables, NOTES).await.into_iter().filter(|k| k.contains(":perm:")).collect();
        assert!(
            grants.is_empty(),
            "Dropping a collection must remove the grants it registered, even those its id index no longer lists: {grants:?}"
        );
    }
}

#[tokio::test]
async fn test_sizing_a_collection_named_like_a_key_segment_counts_only_its_own_keys() {
    let probe = probe_or_skip!();
    probe.recorder.no_memory.store(true, Ordering::SeqCst);
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        adapter.create_collection(KEY_SEGMENT, &[Attribute::string("title", 64)], &[]).await.unwrap();
        let mut sized = readers(NOTE, &[], "sized", shared_tables.then_some(TENANT));
        sized.set("$permissions", utopia_database::value::list([redis_probe::s("read(\"any\")")])).unwrap();
        adapter.create_document(&collection(KEY_SEGMENT), sized).await.unwrap();

        assert_eq!(
            adapter.size_of_collection(KEY_SEGMENT).await.unwrap(),
            bytes_of(&probe, &ns, shared_tables, KEY_SEGMENT).await,
            "A collection named like a key segment must not count other collections' grants"
        );
        assert_eq!(adapter.size_of_collection(NOTES).await.unwrap(), bytes_of(&probe, &ns, shared_tables, NOTES).await);
    }
}

#[tokio::test]
async fn test_sizing_counts_grants_written_before_the_registry() {
    let probe = probe_or_skip!();
    probe.recorder.no_memory.store(true, Ordering::SeqCst);
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        forget(&probe, &format!("utopia:{ns}:*:grants:*")).await;

        assert_eq!(
            adapter.size_of_collection(NOTES).await.unwrap(),
            bytes_of(&probe, &ns, shared_tables, NOTES).await,
            "Sizing must count the grants written before the registry existed"
        );
    }
}

#[tokio::test]
async fn test_sizing_counts_registered_grants_its_id_index_misses() {
    let probe = probe_or_skip!();
    probe.recorder.no_memory.store(true, Ordering::SeqCst);
    for shared_tables in [false, true] {
        let (adapter, ns) = notes_database(&probe, shared_tables).await;
        forget(&probe, &format!("utopia:{ns}:*:idx:*{NOTES}")).await;
        let unindexed: Vec<String> =
            raw(&probe, redis::cmd("KEYS").arg(format!("utopia:{ns}:redis_unique:doc:*{NOTES}:{NOTE}"))).await;
        assert_eq!(unindexed.len(), 1);
        let expected = bytes_of(&probe, &ns, shared_tables, NOTES).await - bytes(&probe, &unindexed[0]).await;

        assert_eq!(
            adapter.size_of_collection(NOTES).await.unwrap(),
            expected,
            "Sizing must count the grants the collection registered, even those its id index no longer lists"
        );
    }
}

#[tokio::test]
async fn test_sizing_under_shared_tables_counts_only_the_selected_tenants_grants() {
    let probe = probe_or_skip!();
    probe.recorder.no_memory.store(true, Ordering::SeqCst);
    let (adapter, ns) = notes_database(&probe, true).await;
    let expected = bytes_of(&probe, &ns, true, NOTES).await;

    adapter.set_tenant(Value::Int(OTHER_TENANT));
    adapter
        .create_document(&collection(NOTES), readers(NOTE, &["carol"], "other tenant", Some(OTHER_TENANT)))
        .await
        .unwrap();
    adapter.set_tenant(Value::Int(TENANT));

    let other: Vec<String> =
        raw(&probe, redis::cmd("KEYS").arg(format!("utopia:{ns}:*:perm:t:{OTHER_TENANT}:{NOTES}:*"))).await;
    assert!(!other.is_empty());
    assert_eq!(
        adapter.size_of_collection(NOTES).await.unwrap(),
        expected,
        "Sizing must count only the selected tenant's grants"
    );
}
