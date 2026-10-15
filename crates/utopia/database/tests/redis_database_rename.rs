//! Ported from utopia-php/database 8.1.3 `tests/unit/Adapter/RedisDatabaseRenameTest.php`,
//! against a real Redis: the stored keys are written to the server, and a
//! failing key move is a fault on that `RENAME` (a lost connection, or the
//! server's `nil`, phpredis's `false`).

#![cfg(feature = "redis")]

mod redis_probe;

use redis_probe::{Content, Fault, Probe, namespace, raw, snapshot};
use utopia_database::Adapter;
use utopia_database::Kind;
use utopia_database::adapter::redis::Redis;

/// The keys of the PHP test, under a namespace of this run.
struct Keys {
    ns: String,
    databases: String,
    document: String,
    grants: String,
    collections: String,
}

impl Keys {
    fn new() -> Self {
        let ns = namespace("ns");
        Keys {
            databases: format!("utopia:{ns}:dbs"),
            document: format!("utopia:{ns}:library:doc:books:hobbit"),
            grants: format!("utopia:{ns}:library:grants:books:r:any"),
            collections: format!("utopia:{ns}:library:cols"),
            ns,
        }
    }

    async fn store(&self, probe: &Probe) {
        let _: i64 = raw(probe, redis::cmd("SADD").arg(&self.databases).arg("library")).await;
        let _: () = raw(probe, redis::cmd("SET").arg(&self.document).arg("{\"title\":\"The Hobbit\"}")).await;
        let _: i64 = raw(probe, redis::cmd("SADD").arg(&self.grants).arg(&self.document).arg("elsewhere")).await;
        let _: i64 = raw(probe, redis::cmd("SADD").arg(&self.collections).arg("books")).await;
    }

    fn adapter(&self, probe: &Probe) -> Redis<Probe> {
        let adapter = Redis::new(probe.clone());
        adapter.set_namespace(&self.ns);
        adapter
    }

    fn prefix(&self) -> String {
        format!("utopia:{}:", self.ns)
    }
}

fn set(members: &[&str]) -> Content {
    let mut members: Vec<String> = members.iter().map(|m| (*m).to_owned()).collect();
    members.sort();
    Content::Set(members)
}

#[tokio::test]
async fn test_rename_moves_every_key_and_the_grant_members() {
    let probe = probe_or_skip!();
    let keys = Keys::new();
    keys.store(&probe).await;

    assert!(keys.adapter(&probe).update("library", "archive").await.unwrap());

    let ns = &keys.ns;
    let moved = format!("utopia:{ns}:archive:doc:books:hobbit");
    let expected = [
        (format!("utopia:{ns}:archive:cols"), set(&["books"])),
        (moved.clone(), Content::String(b"{\"title\":\"The Hobbit\"}".to_vec())),
        (format!("utopia:{ns}:archive:grants:books:r:any"), set(&["elsewhere", &moved])),
        (keys.databases.clone(), set(&["archive"])),
    ]
    .into_iter()
    .collect();
    assert_eq!(snapshot(&probe, &keys.prefix()).await, expected);
}

#[tokio::test]
async fn test_a_failure_part_way_moves_the_moved_keys_back() {
    let probe = probe_or_skip!();
    let keys = Keys::new();
    keys.store(&probe).await;
    let before = snapshot(&probe, &keys.prefix()).await;
    probe.fault("RENAME", &keys.collections, Fault::Lost("connection lost"));

    let e = keys.adapter(&probe).update("library", "archive").await.unwrap_err();
    assert!(e.is_a(Kind::Database));
    assert!(e.message().contains("connection lost"), "{}", e.message());

    assert_eq!(snapshot(&probe, &keys.prefix()).await, before);
}

#[tokio::test]
async fn test_a_key_gone_part_way_moves_the_moved_keys_back() {
    let probe = probe_or_skip!();
    let keys = Keys::new();
    keys.store(&probe).await;
    let before = snapshot(&probe, &keys.prefix()).await;
    probe.fault("RENAME", &keys.collections, Fault::Nil);

    let e = keys.adapter(&probe).update("library", "archive").await.unwrap_err();
    assert_eq!(e.kind(), Kind::Database);
    assert_eq!(e.message(), format!("Failed to move {} to utopia:{}:archive:cols", keys.collections, keys.ns));

    assert_eq!(snapshot(&probe, &keys.prefix()).await, before);
}

#[tokio::test]
async fn test_shared_tables_refuse_the_rename() {
    let probe = probe_or_skip!();
    let keys = Keys::new();
    keys.store(&probe).await;
    let before = snapshot(&probe, &keys.prefix()).await;
    let adapter = keys.adapter(&probe);
    adapter.set_shared_tables(true);

    let e = adapter.update("library", "archive").await.unwrap_err();
    assert_eq!(e.kind(), Kind::Database);
    assert_eq!(e.message(), "Cannot rename a database while shared tables are enabled");

    assert_eq!(snapshot(&probe, &keys.prefix()).await, before);
}
