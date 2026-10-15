//! Ports of utopia-php/database 8.1.3's pool tests: `Adapter/PoolTest.php`,
//! `PoolTimeoutTest.php`, `Adapter/PoolSyncTest.php`,
//! `Adapter/PoolDelegationTest.php`, `Adapter/PoolHostnameTest.php`,
//! `Adapter/PoolCapabilityTest.php`, `Adapter/PoolCoroutineTransactionTest.php`
//! (coroutines as tokio tasks, children started with `inherit`), the pool
//! parts of `Adapter/LimitsTest.php` and `Adapter/FeatureContractTest.php`,
//! and `Adapter/ReadWritePoolTest.php`'s routing. PHP stubs the
//! `Utopia\Pools\Pool` and the pooled adapters; here a real pool hands out
//! a [`probe::Probe`] (a Memory adapter recording what reaches it), and a
//! pool adapter that can be taken down stands in for an unreachable backing.
//!
//! Deferred until the `Database` is ported: the tests that build one
//! (`PoolTest::testDisablingProfilingDetachesPooledConnections`,
//! `PoolCapabilityTest`'s cached and validated reads and its profile test,
//! `PoolDelegationTest::testQueryBuilderReadsTheRowsOfTheBorrowedAdapter`,
//! `PoolHostnameTest::testADatabaseOverAPoolNamesThePoolsHostname`,
//! `PoolAlterLockTest`, `PoolCastingTest`, `ReadWritePoolTest`'s database
//! and cache tests, `ReadWritePoolMetadataTest`, `ReadWritePoolWriteDecisionTest`).
//! `PoolParityTest` and `ReadWritePoolRoutingTest` scan PHP sources.
//!
//! Not matched: a capability question on a pool whose first checkout fails
//! answers `false` (the contract's `supports()` cannot fail) where PHP
//! throws the pool's exception; the answer is kept only once a checkout
//! succeeds, as in PHP.

#![cfg(all(feature = "memory", feature = "pool"))]

mod probe;

use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use probe::Probe;
use utopia_database::adapter::pool::{Pool, Pooled, ReadWritePool};
use utopia_database::adapter::{Adapter, Connection, Feature, Find, Schemaless, Timeouts, with_transaction};
use utopia_database::capability::Capability;
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::event::Event;
use utopia_database::hook::{Tenancy, Transform, Write};
use utopia_database::profiler::Profiler;
use utopia_database::relationship::Relationship;
use utopia_database::state::inherit;
use utopia_database::value::{Array, ArrayKey, Str, Value};
use utopia_database::{Document, Kind, Result};
use utopia_pools::Connection as Lent;

/// A stack of idle connections that a test can take down: while down it
/// hands out none and every new connection fails (`backing unreachable`).
#[derive(Default)]
struct Backing {
    stack: utopia_pools::Stack<Pooled>,
    down: Arc<AtomicBool>,
}

impl utopia_pools::Adapter<Pooled> for Backing {
    fn initialize(&mut self, size: usize) {
        self.stack.initialize(size);
    }

    fn push(&self, connection: Lent<Pooled>) {
        self.stack.push(connection);
    }

    async fn pop(&self, timeout: Duration) -> Option<Lent<Pooled>> {
        if self.down.load(Ordering::SeqCst) {
            return None;
        }
        self.stack.pop(timeout).await
    }

    fn count(&self) -> usize {
        if self.down.load(Ordering::SeqCst) { 0 } else { self.stack.count() }
    }
}

type Connections = utopia_pools::Pool<Pooled, Backing>;

/// A pool of `size` connections, each the adapter `make` gives.
fn connections_of(
    name: &str,
    size: usize,
    make: impl Fn() -> Arc<dyn Adapter> + Send + Sync + 'static,
) -> (Connections, Arc<AtomicBool>) {
    let down = Arc::new(AtomicBool::new(false));
    let backing = Backing { stack: utopia_pools::Stack::new(), down: down.clone() };
    let flag = down.clone();
    let pool = utopia_pools::Pool::new(
        backing,
        name,
        size,
        move || {
            let refused = flag.load(Ordering::SeqCst);
            let adapter = make();
            async move {
                if refused {
                    return Err(utopia_database::Error::new(Kind::Runtime, "backing unreachable"));
                }
                Ok(Pooled(adapter))
            }
        },
        0.0,
    )
    .unwrap();
    (pool, down)
}

/// A pool whose every connection is `probe`.
fn connections(probe: &Arc<Probe>) -> (Connections, Arc<AtomicBool>) {
    let probe = probe.clone();
    connections_of("memory", 10, move || probe.clone() as Arc<dyn Adapter>)
}

fn handle(connections: Connections) -> Pool<Backing> {
    Pool::new(connections)
}

fn pool(probe: &Arc<Probe>) -> Pool<Backing> {
    handle(connections(probe).0)
}

fn collection(id: &str) -> Collection {
    let mut a = Array::new();
    a.set(ArrayKey::from("$id"), Value::Str(Str::from(id)));
    Collection::from_document(&Document::new(a).unwrap()).unwrap()
}

fn hook_ptrs(hooks: &[Arc<dyn Write>]) -> Vec<*const ()> {
    hooks.iter().map(|h| Arc::as_ptr(h) as *const ()).collect()
}

struct NoHook;
impl Write for NoHook {}

struct Same;
impl Transform for Same {
    fn transform(&self, _event: Event, query: String) -> Result<String> {
        Ok(query)
    }
}

// PoolTest

#[tokio::test]
async fn test_delegate_replaces_stateful_write_hook_on_reused_adapter() {
    let probe = Arc::new(Probe::new("memory"));
    probe.add_write_hook(Arc::new(Tenancy::new("old_tenant_column")));
    let pool = pool(&probe);
    let hook: Arc<dyn Write> = Arc::new(Tenancy::new("new_tenant_column"));
    pool.add_write_hook(hook.clone());

    assert!(pool.list().await.unwrap().is_empty());
    assert_eq!(hook_ptrs(&probe.write_hooks()), hook_ptrs(&[hook]));
    assert_eq!(probe.tenant_hook().unwrap().column(), "new_tenant_column");
}

#[tokio::test]
async fn test_transaction_replaces_stateful_write_hook_on_reused_adapter() {
    let probe = Arc::new(Probe::new("memory"));
    probe.add_write_hook(Arc::new(NoHook));
    probe.add_write_hook(Arc::new(Tenancy::new("old_tenant_column")));
    let pool = pool(&probe);
    let hook: Arc<dyn Write> = Arc::new(Tenancy::new("new_tenant_column"));
    pool.add_write_hook(hook.clone());

    let committed = with_transaction(&pool, || async { Ok("committed") }).await.unwrap();
    assert_eq!(committed, "committed");
    assert_eq!(hook_ptrs(&probe.write_hooks()), hook_ptrs(&[hook]));
    assert_eq!(probe.tenant_hook().unwrap().column(), "new_tenant_column");
}

#[tokio::test]
async fn test_delegate_and_transaction_remove_a_write_hook_removed_from_the_pool() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    let hook: Arc<dyn Write> = Arc::new(NoHook);
    pool.add_write_hook(hook.clone());
    pool.list().await.unwrap();
    assert_eq!(hook_ptrs(&probe.write_hooks()), hook_ptrs(std::slice::from_ref(&hook)));

    pool.remove_write_hook(&hook);
    pool.list().await.unwrap();
    assert!(probe.write_hooks().is_empty());

    pool.add_write_hook(hook.clone());
    with_transaction(&pool, || async { Ok(()) }).await.unwrap();
    assert_eq!(hook_ptrs(&probe.write_hooks()), hook_ptrs(std::slice::from_ref(&hook)));
    pool.remove_write_hook(&hook);
    with_transaction(&pool, || async { Ok(()) }).await.unwrap();
    assert!(probe.write_hooks().is_empty());
}

#[tokio::test]
async fn test_pinned_adapter_resyncs_tenant_and_database_before_delegated_call() {
    let probe = Arc::new(Probe::new("memory"));
    probe.set_database("old_db");
    probe.set_namespace("old_ns");
    probe.set_tenant(Value::Int(1));
    let pool = pool(&probe);
    pool.set_database("old_db");
    pool.set_namespace("old_ns");
    pool.set_tenant(Value::Int(1));

    with_transaction(&pool, || async {
        pool.set_database("new_db");
        pool.set_namespace("new_ns");
        pool.set_tenant(Value::Int(2));
        assert!(pool.list().await?.is_empty());
        assert_eq!(probe.database(), "new_db");
        assert_eq!(probe.namespace(), "new_ns");
        assert_eq!(probe.tenant(), Value::Int(2));
        Ok(())
    })
    .await
    .unwrap();
}

#[tokio::test]
async fn test_memory_pool_delegates_without_requiring_timeouts() {
    let probe = Arc::new(Probe::new("memory"));
    assert!(pool(&probe).list().await.unwrap().is_empty());
}

#[tokio::test]
async fn test_defined_attributes_support_propagates_across_borrowed_adapters() {
    let first = Arc::new(Probe::new("first").schemaless());
    let second = Arc::new(Probe::new("second").schemaless());
    let queue = Mutex::new(vec![second.clone() as Arc<dyn Adapter>, first.clone() as Arc<dyn Adapter>]);
    let (connections, _) = connections_of("mongo", 1, move || queue.lock().unwrap().pop().unwrap());
    let pool = handle(connections);
    pool.set_schemaless(true);

    assert!(!pool.supports(Capability::DefinedAttributes));
    assert!(pool.is_schemaless());
    assert!(!first.supports(Capability::DefinedAttributes));
    // A pool of one keeps reusing the connection it made; the second was never needed.
    assert!(second.supports(Capability::DefinedAttributes));
}

#[tokio::test]
async fn test_memory_pool_set_timeout_refuses_when_the_timeout_would_be_applied() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    pool.set_timeout(1000, Event::All).unwrap();
    assert_eq!(pool.timeout(Event::All), 1000);

    let error = pool.list().await.unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), "Adapter does not support timeouts");
}

#[tokio::test]
async fn test_missing_feature_throws() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    assert!(pool.upserts().is_none());
    let error = utopia_database::adapter::Upserts::upsert_documents(&pool, &collection("x"), vec![], Some("id"))
        .await
        .unwrap_err();
    assert_eq!(error.kind(), Kind::Database);
    assert_eq!(error.message(), "Adapter does not support upserts");
}

#[tokio::test]
async fn test_timeout_raised_while_pinned_reaches_the_pinned_connection() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    with_transaction(&pool, || async {
        pool.set_timeout(300_000, Event::All)?;
        assert_eq!(
            probe.timeout(Event::All),
            300_000,
            "The connection the open transaction runs on must get the new bound"
        );
        Ok(())
    })
    .await
    .unwrap();
}

#[tokio::test]
async fn test_borrowed_and_pinned_adapters_do_not_keep_the_profiler() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    let profiler = Arc::new(Profiler::new());
    pool.set_profiler(Some(profiler.clone()));

    assert!(pool.ping().await.unwrap());
    assert!(
        Arc::ptr_eq(probe.last().profiler.as_ref().unwrap(), &profiler),
        "The borrowed connection must profile the call it served"
    );
    assert!(probe.profiler().is_none(), "The connection went back to the pool still holding the handle's profiler");

    assert!(with_transaction(&pool, || pool.ping()).await.unwrap());
    assert!(Arc::ptr_eq(probe.last().profiler.as_ref().unwrap(), &profiler));
    assert!(probe.profiler().is_none());
}

// PoolTimeoutTest

#[tokio::test]
async fn test_set_timeout_does_not_open_a_connection() {
    let opened = Arc::new(AtomicUsize::new(0));
    let counter = opened.clone();
    let (connections, down) = connections_of("unreachable", 1, move || {
        counter.fetch_add(1, Ordering::SeqCst);
        Arc::new(Probe::new("x")) as Arc<dyn Adapter>
    });
    down.store(true, Ordering::SeqCst);
    let pool = handle(connections);
    pool.set_timeout(300_000, Event::All).unwrap();
    assert_eq!(opened.load(Ordering::SeqCst), 0);
    assert_eq!(pool.timeout(Event::All), 300_000);
}

#[tokio::test]
async fn test_timeout_is_applied_to_every_checkout() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(300_000, Event::All).unwrap();
    pool.list().await.unwrap();
    assert_eq!(probe.recorded_timeouts(), [(Event::All, 300_000)]);
    probe.set_recorded_timeouts(Vec::new());
    pool.list().await.unwrap();
    assert_eq!(probe.recorded_timeouts(), [(Event::All, 300_000)]);
}

#[tokio::test]
async fn test_cleared_timeout_is_cleared_on_every_checkout() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(300_000, Event::All).unwrap();
    pool.list().await.unwrap();
    pool.clear_timeout(Event::All);
    pool.list().await.unwrap();
    assert!(probe.recorded_timeouts().is_empty());
    assert_eq!(probe.timeout(Event::All), 0);
    assert_eq!(pool.timeout(Event::All), 0);
}

#[tokio::test]
async fn test_per_event_timeout_reaches_the_connection() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(300_000, Event::All).unwrap();
    pool.set_timeout(5000, Event::DocumentRead).unwrap();
    pool.list().await.unwrap();
    assert_eq!(probe.recorded_timeouts(), [(Event::DocumentRead, 5000), (Event::All, 300_000)]);
    assert_eq!(probe.timeout(Event::DocumentFind), 300_000);
}

#[tokio::test]
async fn test_clearing_one_event_leaves_the_global_timeout_in_place() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(300_000, Event::All).unwrap();
    pool.set_timeout(5000, Event::DocumentRead).unwrap();
    pool.clear_timeout(Event::DocumentRead);
    pool.list().await.unwrap();
    assert_eq!(probe.recorded_timeouts(), [(Event::All, 300_000)]);
    assert_eq!(pool.timeout(Event::All), 300_000);
}

#[tokio::test]
async fn test_handle_with_no_timeout_resets_the_connection_it_is_given() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let (connections, _) = connections(&probe);
    let bounded = handle(connections.clone());
    bounded.set_timeout(5000, Event::All).unwrap();
    bounded.list().await.unwrap();
    let unbounded = handle(connections);
    unbounded.list().await.unwrap();
    assert!(
        probe.recorded_timeouts().is_empty(),
        "A handle that asked for no timeout must not run under the last holder's"
    );
    assert_eq!(probe.timeout(Event::All), 0);
}

#[tokio::test]
async fn test_clearing_every_timeout_drops_per_event_entries_too() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(300_000, Event::All).unwrap();
    pool.set_timeout(5000, Event::DocumentRead).unwrap();
    pool.clear_timeout(Event::All);
    pool.list().await.unwrap();
    assert!(probe.recorded_timeouts().is_empty());
    assert_eq!(pool.timeout(Event::All), 0);
}

#[tokio::test]
async fn test_timeout_changed_inside_a_transaction_reaches_the_pinned_connection() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(5000, Event::All).unwrap();
    let (raised, cleared) = with_transaction(&pool, || async {
        pool.set_timeout(300_000, Event::All)?;
        let raised = probe.recorded_timeouts();
        pool.clear_timeout(Event::All);
        Ok((raised, probe.recorded_timeouts()))
    })
    .await
    .unwrap();
    assert_eq!(raised, [(Event::All, 300_000)]);
    assert!(cleared.is_empty());
}

#[tokio::test]
async fn test_zero_is_held_as_no_timeout() {
    let probe = Arc::new(Probe::new("memory").timeouts());
    let pool = pool(&probe);
    pool.set_timeout(0, Event::All).unwrap();
    pool.list().await.unwrap();
    assert!(probe.recorded_timeouts().is_empty());
    assert_eq!(pool.timeout(Event::All), 0);
}

// PoolSyncTest

fn sync_pool() -> (Pool<Backing>, Arc<Probe>, Arc<Profiler>, Arc<dyn Transform>) {
    let probe = Arc::new(Probe::new("memory").timeouts().schemaless());
    let pool = pool(&probe);
    let profiler = Arc::new(Profiler::new());
    let first: Arc<dyn Transform> = Arc::new(Same);
    pool.set_namespace("before");
    pool.set_tenant(Value::Int(1));
    pool.set_metadata("request", Value::Str("a".into()));
    pool.add_transform("first", first.clone());
    pool.set_schemaless(false);
    pool.set_profiler(Some(profiler.clone()));
    pool.set_timeout(100, Event::All).unwrap();
    (pool, probe, profiler, first)
}

/// What a change is observed as on a snapshot, for comparison.
fn observe(snapshot: &probe::Snapshot, what: &str) -> String {
    match what {
        "metadata" => format!("{:?}", snapshot.metadata),
        "transforms" => format!(
            "{:?}",
            snapshot
                .transforms
                .iter()
                .map(|(n, t)| (n.clone(), Arc::as_ptr(t) as *const () as usize))
                .collect::<Vec<_>>()
        ),
        "schemaless" => snapshot.schemaless.to_string(),
        "profiler" => format!("{:?}", snapshot.profiler.as_ref().map(|p| Arc::as_ptr(p) as usize)),
        "tenant" => format!("{:?}", snapshot.tenant),
        "namespace" => snapshot.namespace.clone(),
        "timeouts" => format!("{:?}", snapshot.timeouts),
        other => panic!("{other}"),
    }
}

/// `PoolSyncTest::changes()`: a change, what it is observed as, and the
/// expected observation.
fn changes(
    second: &Arc<dyn Transform>,
    other: &Arc<Profiler>,
    first: &Arc<dyn Transform>,
) -> Vec<(&'static str, Box<dyn Fn(&Pool<Backing>) + Send + Sync>, &'static str, String)> {
    let (s1, s2, s3) = (second.clone(), second.clone(), other.clone());
    let f = first.clone();
    vec![
        (
            "metadata added",
            Box::new(|p: &Pool<Backing>| p.set_metadata("trace", Value::Str("b".into()))),
            "metadata",
            format!(
                "{:?}",
                vec![("request".to_owned(), Value::Str("a".into())), ("trace".to_owned(), Value::Str("b".into()))]
            ),
        ),
        (
            "metadata replaced",
            Box::new(|p: &Pool<Backing>| p.set_metadata("request", Value::Str("c".into()))),
            "metadata",
            format!("{:?}", vec![("request".to_owned(), Value::Str("c".into()))]),
        ),
        ("metadata reset", Box::new(|p: &Pool<Backing>| p.reset_metadata()), "metadata", "[]".to_owned()),
        (
            "transform added",
            Box::new(move |p: &Pool<Backing>| p.add_transform("second", s1.clone())),
            "transforms",
            format!(
                "{:?}",
                vec![
                    ("first".to_owned(), Arc::as_ptr(&f) as *const () as usize),
                    ("second".to_owned(), Arc::as_ptr(second) as *const () as usize)
                ]
            ),
        ),
        (
            "transform replaced",
            Box::new(move |p: &Pool<Backing>| p.add_transform("first", s2.clone())),
            "transforms",
            format!("{:?}", vec![("first".to_owned(), Arc::as_ptr(second) as *const () as usize)]),
        ),
        ("transform removed", Box::new(|p: &Pool<Backing>| p.remove_transform("first")), "transforms", "[]".to_owned()),
        ("schemaless", Box::new(|p: &Pool<Backing>| p.set_schemaless(true)), "schemaless", "true".to_owned()),
        (
            "profiler replaced",
            Box::new(move |p: &Pool<Backing>| p.set_profiler(Some(s3.clone()))),
            "profiler",
            format!("{:?}", Some(Arc::as_ptr(other) as usize)),
        ),
        ("profiler removed", Box::new(|p: &Pool<Backing>| p.set_profiler(None)), "profiler", "None".to_owned()),
        ("tenant", Box::new(|p: &Pool<Backing>| p.set_tenant(Value::Int(2))), "tenant", format!("{:?}", Value::Int(2))),
        ("namespace", Box::new(|p: &Pool<Backing>| p.set_namespace("after")), "namespace", "after".to_owned()),
        (
            "timeout replaced",
            Box::new(|p: &Pool<Backing>| p.set_timeout(250, Event::All).unwrap()),
            "timeouts",
            format!("{:?}", vec![(Event::All, 250i64)]),
        ),
        (
            "timeout per event",
            Box::new(|p: &Pool<Backing>| p.set_timeout(50, Event::DocumentFind).unwrap()),
            "timeouts",
            format!("{:?}", vec![(Event::DocumentFind, 50i64), (Event::All, 100)]),
        ),
        ("timeout cleared", Box::new(|p: &Pool<Backing>| p.clear_timeout(Event::All)), "timeouts", "[]".to_owned()),
    ]
}

#[tokio::test]
async fn test_borrowed_pinned_and_later_calls_see_the_change() {
    let second: Arc<dyn Transform> = Arc::new(Same);
    let other = Arc::new(Profiler::new());
    for mode in ["borrowed", "pinned", "after the transaction", "next transaction"] {
        let (_, _, _, first) = sync_pool();
        for (name, change, what, expected) in changes(&second, &other, &first) {
            let (pool, probe, _, first_of_pool) = sync_pool();
            // The expected transforms name this pool's own first transform.
            let expected = expected.replace(
                &format!("{}", Arc::as_ptr(&first) as *const () as usize),
                &format!("{}", Arc::as_ptr(&first_of_pool) as *const () as usize),
            );
            let change = |p: &Pool<Backing>| change(p);
            match mode {
                "borrowed" => {
                    pool.ping().await.unwrap();
                    assert_ne!(
                        observe(&probe.last(), what),
                        expected,
                        "{name}: the change must differ from the starting state"
                    );
                    change(&pool);
                    pool.ping().await.unwrap();
                }
                "pinned" => {
                    with_transaction(&pool, || async {
                        pool.ping().await?;
                        change(&pool);
                        pool.ping().await
                    })
                    .await
                    .unwrap();
                    let snapshots = probe.snapshots.lock().unwrap().clone();
                    assert_eq!(snapshots.len(), 2, "{name}");
                    assert_ne!(observe(&snapshots[0], what), expected, "{name}");
                }
                "after the transaction" => {
                    with_transaction(&pool, || async {
                        pool.ping().await?;
                        change(&pool);
                        Ok(())
                    })
                    .await
                    .unwrap();
                    pool.ping().await.unwrap();
                }
                _ => {
                    with_transaction(&pool, || pool.ping()).await.unwrap();
                    change(&pool);
                    with_transaction(&pool, || pool.ping()).await.unwrap();
                }
            }
            assert_eq!(observe(&probe.last(), what), expected, "{mode}: {name}");
        }
    }
}

#[tokio::test]
async fn test_a_connections_own_change_does_not_reach_the_next_call() {
    let other = Arc::new(Profiler::new());
    let second: Arc<dyn Transform> = Arc::new(Same);
    type Mutation = Box<dyn Fn(&Probe) + Send + Sync>;
    let mutations: Vec<(&str, Arc<Mutation>)> = vec![
        ("state added", {
            let (other, second) = (other.clone(), second.clone());
            Arc::new(Box::new(move |c: &Probe| {
                c.set_metadata("leak", Value::Bool(true));
                c.add_transform("leak", second.clone());
                c.set_schemaless(true);
                c.set_profiler(Some(other.clone()));
                c.set_timeout(250, Event::All).unwrap();
            }) as Mutation)
        }),
        (
            "state removed",
            Arc::new(Box::new(|c: &Probe| {
                c.reset_metadata();
                c.reset_transforms();
                c.set_profiler(None);
                c.clear_timeout(Event::All);
            }) as Mutation),
        ),
        ("state replaced", {
            let second = second.clone();
            Arc::new(Box::new(move |c: &Probe| {
                c.set_metadata("request", Value::Str("leak".into()));
                c.add_transform("first", second.clone());
            }) as Mutation)
        }),
    ];
    for (name, mutation) in mutations {
        for pinned in [false, true] {
            let (pool, probe, _, _) = sync_pool();
            let m = mutation.clone();
            if pinned {
                with_transaction(&pool, || {
                    let m = m.clone();
                    let probe = probe.clone();
                    let pool = &pool;
                    async move {
                        probe.mutate_on_next_ping(move |c| m(c));
                        pool.ping().await?;
                        pool.ping().await
                    }
                })
                .await
                .unwrap();
            } else {
                probe.mutate_on_next_ping(move |c| m(c));
                pool.ping().await.unwrap();
                pool.ping().await.unwrap();
            }
            let snapshots = probe.snapshots.lock().unwrap().clone();
            assert_eq!(snapshots.len(), 2);
            for what in ["metadata", "transforms", "schemaless", "profiler", "tenant", "namespace", "timeouts"] {
                assert_eq!(
                    observe(&snapshots[0], what),
                    observe(&snapshots[1], what),
                    "{name} (pinned: {pinned}): {what}"
                );
            }
        }
    }
}

// PoolDelegationTest

#[tokio::test]
async fn test_each_missing_feature_names_itself() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    use utopia_database::adapter::{Casting, QueryBuilder, RawQuery, Relationships, Spatial, Upserts};
    let check = |result: Result<()>, message: &str| {
        let error = result.unwrap_err();
        assert_eq!(error.kind(), Kind::Database);
        assert_eq!(error.message(), message);
    };
    check(
        RawQuery::raw_query(&pool, "SELECT 1", utopia_query::Array::new()).await.map(|_| ()),
        "Adapter does not support raw queries",
    );
    check(QueryBuilder::builder(&pool).map(|_| ()), "Adapter does not support query builder");
    check(QueryBuilder::schema(&pool).map(|_| ()), "Adapter does not support query builder");
    check(
        Spatial::decode(&pool, b"", utopia_query::schema::ColumnType::Point).map(|_| ()),
        "Adapter does not support spatial",
    );
    check(
        Casting::cast_before(&pool, &collection("x"), Document::empty()).map(|_| ()),
        "Adapter does not support casting",
    );
    check(
        Casting::cast_after(&pool, &collection("x"), vec![Document::empty()]).map(|_| ()),
        "Adapter does not support casting",
    );
    check(Casting::cast_datetime(&pool, "2026-01-01").map(|_| ()), "Adapter does not support casting");
    check(
        Upserts::upsert_document(&pool, &collection("x"), Change::default()).await.map(|_| ()),
        "Adapter does not support upserts",
    );
    // The probe has a connection and relationships; a plain Memory pool has no connection.
    let memory = handle(
        connections_of("plain", 1, || Arc::new(utopia_database::adapter::memory::Memory::new()) as Arc<dyn Adapter>).0,
    );
    check(Connection::id(&memory).await.map(|_| ()), "Adapter does not support connections");
    assert!(
        Relationships::create_relationship(&pool, "books", &Relationship::one_to_one("authors").key("author"))
            .await
            .unwrap()
    );
}

#[tokio::test]
async fn test_metadata_entries_reach_the_borrowed_adapter() {
    let probe = Arc::new(Probe::new("memory"));
    probe.set_metadata("stale", Value::Str("entry".into()));
    let pool = pool(&probe);
    pool.set_metadata("request", Value::Str("r-1".into()));
    assert!(pool.list().await.unwrap().is_empty());
    assert_eq!(probe.metadata(), [("request".to_owned(), Value::Str("r-1".into()))]);
}

#[tokio::test]
async fn test_direct_transaction_calls_reach_the_borrowed_adapter() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    assert!(pool.start_transaction().await.unwrap());
    assert!(probe.in_transaction());
    assert!(pool.commit_transaction().await.unwrap());
    assert!(!probe.in_transaction());
    assert!(
        !pool.commit_transaction().await.unwrap(),
        "A commit without a transaction reports false, as the adapter does"
    );
    assert!(pool.start_transaction().await.unwrap());
    assert!(pool.rollback_transaction().await.unwrap());
    assert!(!probe.in_transaction());
    assert!(!pool.rollback_transaction().await.unwrap());
}

// PoolHostnameTest

#[tokio::test]
async fn test_the_hostname_is_the_pooled_adapters_unless_the_pool_has_one() {
    let pool = pool(&Arc::new(Probe::new("x").hostname("db-1")));
    assert_eq!(pool.hostname().await.unwrap(), "db-1");
    pool.set_hostname("edge-1");
    assert_eq!(pool.hostname().await.unwrap(), "edge-1");
    pool.set_hostname("");
    assert_eq!(pool.hostname().await.unwrap(), "db-1");
}

#[tokio::test]
async fn test_a_read_write_pool_follows_the_hostname_set_after_it_answered() {
    let write = Arc::new(Probe::new("write").hostname("db-1"));
    let read = Arc::new(Probe::new("read").hostname("replica-1"));
    let pool: ReadWritePool<Backing> = Pool::read_write(connections(&write).0, connections(&read).0);
    assert_eq!(pool.hostname().await.unwrap(), "db-1");
    pool.set_hostname("edge-1");
    assert_eq!(pool.hostname().await.unwrap(), "edge-1");
    pool.set_hostname("");
    assert_eq!(pool.hostname().await.unwrap(), "db-1");
}

// PoolCapabilityTest (adapter level)

fn checkouts(probe: &Probe) -> usize {
    probe.checkouts.load(Ordering::SeqCst)
}

#[tokio::test]
async fn test_a_capability_question_on_a_cold_pool_checks_out_once() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    assert!(pool.supports(Capability::Operators));
    assert!(!pool.supports(Capability::AlterLock));
    assert_eq!(pool.capabilities(), utopia_database::adapter::memory::Memory::new().capabilities());
    assert_eq!(checkouts(&probe), 1);
}

#[tokio::test]
async fn test_every_handle_over_one_pool_shares_the_answers() {
    let probe = Arc::new(Probe::new("memory"));
    let (connections, down) = connections(&probe);
    assert!(handle(connections.clone()).supports(Capability::Operators));
    down.store(true, Ordering::SeqCst);
    let before = checkouts(&probe);
    let other = handle(connections);
    assert!(other.supports(Capability::Operators));
    assert!(other.supports(Capability::IndexFulltext));
    assert_eq!(checkouts(&probe), before);
}

#[tokio::test]
async fn test_features_are_asked_once_per_feature() {
    let probe = Arc::new(Probe::new("memory"));
    let (connections, down) = connections(&probe);
    let pool = handle(connections);
    assert!(pool.has_feature(Feature::Relationships));
    assert!(!pool.has_feature(Feature::Spatial));
    assert_eq!(checkouts(&probe), 2);
    down.store(true, Ordering::SeqCst);
    assert!(pool.has_feature(Feature::Relationships));
    assert!(!pool.has_feature(Feature::Spatial));
    assert_eq!(checkouts(&probe), 2);
}

#[tokio::test]
async fn test_defined_attributes_always_asks_a_connection_with_a_mode_of_its_own() {
    let probe = Arc::new(Probe::new("mongo").schemaless());
    let pool = pool(&probe);
    assert!(pool.has_feature(Feature::Schemaless));
    let before = checkouts(&probe);
    probe.set_own_schemaless(true);
    assert!(!pool.supports(Capability::DefinedAttributes));
    probe.set_own_schemaless(false);
    assert!(pool.supports(Capability::DefinedAttributes));
    assert_eq!(checkouts(&probe) - before, 2);
    assert!(
        probe.supports(Capability::DefinedAttributes),
        "A handle that never set the schema mode must leave the connection's own"
    );
}

#[tokio::test]
async fn test_defined_attributes_is_asked_once_per_mode_the_handle_set() {
    let probe = Arc::new(Probe::new("mongo").schemaless());
    let (connections, _) = connections(&probe);
    let pool = handle(connections.clone());
    pool.set_schemaless(true);
    let base = checkouts(&probe);
    assert!(!pool.supports(Capability::DefinedAttributes));
    assert!(!pool.supports(Capability::DefinedAttributes));
    // The first question also asks whether the connection has a schema mode.
    let asked = checkouts(&probe) - base;
    assert!(asked <= 2, "A schema mode the handle set is asked of a connection once");
    probe.set_own_schemaless(false);
    assert!(
        !pool.supports(Capability::DefinedAttributes),
        "Every connection the handle borrows is put in its mode first"
    );

    pool.set_schemaless(false);
    let base = checkouts(&probe);
    assert!(pool.supports(Capability::DefinedAttributes));
    assert!(pool.supports(Capability::DefinedAttributes));
    assert_eq!(checkouts(&probe) - base, 1, "Each mode is answered by a connection in that mode");

    let other = handle(connections);
    other.set_schemaless(true);
    let base = checkouts(&probe);
    assert!(!other.supports(Capability::DefinedAttributes));
    assert!(pool.supports(Capability::DefinedAttributes));
    assert_eq!(checkouts(&probe) - base, 0, "Handles over one pool share the answer of each mode");
}

#[tokio::test]
async fn test_setting_the_schema_mode_on_a_pool_of_schema_enforcing_adapters_leaves_them_enforcing_it() {
    let probe = Arc::new(Probe::new("memory"));
    let (connections, down) = connections(&probe);
    let pool = handle(connections);
    assert!(!pool.has_feature(Feature::Schemaless));
    down.store(true, Ordering::SeqCst);
    Schemaless::set_schemaless(&pool, true);
    Schemaless::set_schemaless(&pool, false);
    down.store(false, Ordering::SeqCst);
    Schemaless::set_schemaless(&pool, true);
    assert!(pool.supports(Capability::DefinedAttributes));
    assert!(!Schemaless::is_schemaless(&pool), "a pool of schema-enforcing adapters has no schemaless mode");
}

#[tokio::test]
async fn test_a_failed_first_checkout_answers_nothing_and_the_next_one_fills_the_answers() {
    let probe = Arc::new(Probe::new("memory"));
    let (connections, down) = connections(&probe);
    let pool = handle(connections);
    down.store(true, Ordering::SeqCst);
    assert!(
        !pool.supports(Capability::Operators),
        "A capability question with no answer yet has none while the backing is down"
    );
    down.store(false, Ordering::SeqCst);
    assert!(pool.supports(Capability::Operators));
    down.store(true, Ordering::SeqCst);
    assert!(pool.supports(Capability::Operators));
}

// FeatureContractTest, LimitsTest (pool parts)

#[tokio::test]
async fn test_memory_pool_has_feature_delegates_to_inner_adapter() {
    let pool = handle(
        connections_of("plain", 1, || Arc::new(utopia_database::adapter::memory::Memory::new()) as Arc<dyn Adapter>).0,
    );
    assert!(pool.has_feature(Feature::Relationships));
    assert!(!pool.has_feature(Feature::Upserts));
    assert!(pool.relationships().is_some());
    assert!(pool.upserts().is_none());
}

#[tokio::test]
async fn test_pool_answers_for_timeouts_without_a_connection() {
    let probe = Arc::new(Probe::new("x"));
    let (connections, down) = connections(&probe);
    down.store(true, Ordering::SeqCst);
    let pool = handle(connections);
    assert!(pool.has_feature(Feature::Timeouts));
    assert!(pool.timeouts().is_some());
    assert_eq!(checkouts(&probe), 0);
}

#[tokio::test]
async fn test_a_pool_answers_with_the_limits_of_the_adapter_it_borrows() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = pool(&probe);
    assert_eq!(pool.limits().index_length, 1024);
    assert_eq!(pool.limits().id_type, utopia_query::schema::ColumnType::Integer);
    let asked = probe.calls().iter().filter(|c| **c == "limits").count();
    pool.set_shared_tables(true);
    pool.limits();
    assert_eq!(
        probe.calls().iter().filter(|c| **c == "limits").count(),
        asked + 1,
        "The pool asks again once shared tables change the limits"
    );
}

// PoolCoroutineTransactionTest, with tasks

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn test_a_sibling_runs_outside_another_tasks_transaction() {
    let made = Arc::new(Mutex::new(Vec::<Arc<Probe>>::new()));
    let record = made.clone();
    let (connections, _) = connections_of("memory", 2, move || {
        let probe = Arc::new(Probe::new("memory"));
        record.lock().unwrap().push(probe.clone());
        probe as Arc<dyn Adapter>
    });
    let pool = Arc::new(handle(connections));
    let (entered_tx, entered_rx) = tokio::sync::oneshot::channel::<()>();
    let (release_tx, release_rx) = tokio::sync::oneshot::channel::<()>();

    let owner = {
        let pool = pool.clone();
        tokio::spawn(async move {
            let mut entered = Some(entered_tx);
            let mut release = Some(release_rx);
            with_transaction(&*pool, || {
                let pool = pool.clone();
                let entered = entered.take();
                let release = release.take();
                async move {
                    let inside = pool.in_transaction();
                    pool.ping().await?;
                    if let Some(entered) = entered {
                        let _ = entered.send(());
                    }
                    if let Some(release) = release {
                        let _ = release.await;
                    }
                    Ok(inside)
                }
            })
            .await
            .unwrap()
        })
    };
    entered_rx.await.unwrap();
    let sibling = {
        let pool = pool.clone();
        tokio::spawn(async move {
            let outside = pool.in_transaction();
            pool.ping().await.unwrap();
            let nested = with_transaction(&*pool, || async { Ok(pool.in_transaction()) }).await.unwrap();
            (outside, nested, tokio::task::id())
        })
    };
    let (sibling_outside, sibling_nested, sibling_task) = sibling.await.unwrap();
    release_tx.send(()).unwrap();
    let owner_inside = owner.await.unwrap();

    assert!(owner_inside);
    assert!(!sibling_outside);
    assert!(sibling_nested);
    let pinned = made.lock().unwrap()[0].clone();
    assert!(
        !pinned.snapshots.lock().unwrap().iter().any(|s| s.task == Some(sibling_task)),
        "the sibling must not run on the pinned connection"
    );
    assert!(!pool.in_transaction());
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn test_a_task_started_inside_a_transaction_runs_in_it() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = Arc::new(pool(&probe));
    let child = with_transaction(&*pool, || {
        let pool = pool.clone();
        async move {
            let inner = pool.clone();
            Ok(tokio::spawn(inherit(async move { inner.in_transaction() })).await.unwrap())
        }
    })
    .await
    .unwrap();
    assert!(child);
    assert!(!pool.in_transaction());
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn test_tasks_on_the_pinned_connection_keep_their_own_tenant() {
    let probe = Arc::new(Probe::new("memory"));
    let pool = Arc::new(pool(&probe));
    let tenant = pool.state().tenant();
    tenant
        .with(Value::Int(1), async {
            with_transaction(&*pool, || {
                let pool = pool.clone();
                let probe = probe.clone();
                async move {
                    let (paused_tx, paused_rx) = tokio::sync::oneshot::channel::<()>();
                    let (resume_tx, resume_rx) = tokio::sync::oneshot::channel::<()>();
                    let paused = Mutex::new(Some((paused_tx, resume_rx)));
                    probe.pause_next_ping(move || {
                        let (paused_tx, resume_rx) = paused.lock().unwrap().take().unwrap();
                        Box::pin(async move {
                            let _ = paused_tx.send(());
                            let _ = resume_rx.await;
                        })
                    });
                    let child = {
                        let pool = pool.clone();
                        tokio::spawn(inherit(async move {
                            pool.state().tenant().with(Value::Int(2), pool.ping()).await.unwrap();
                        }))
                    };
                    paused_rx.await.unwrap();
                    pool.ping().await?;
                    resume_tx.send(()).unwrap();
                    child.await.unwrap();
                    Ok(())
                }
            })
            .await
        })
        .await
        .unwrap();

    let pings: Vec<(Value, Value)> =
        probe.snapshots.lock().unwrap().iter().map(|s| (s.before.clone(), s.after.clone())).collect();
    assert_eq!(pings, [(Value::Int(1), Value::Int(1)), (Value::Int(2), Value::Int(2))]);
}

// ReadWritePoolTest (routing)

fn read_write() -> (ReadWritePool<Backing>, Arc<Probe>, Arc<Probe>) {
    let write = Arc::new(Probe::new("write"));
    let read = Arc::new(Probe::new("read"));
    let pool = Pool::read_write(connections(&write).0, connections(&read).0);
    (pool, write, read)
}

#[tokio::test]
async fn test_read_methods_route_to_the_read_pool() {
    let (pool, write, read) = read_write();
    let c = collection("posts");
    pool.find(&c, vec![], Find::default()).await.unwrap_err();
    pool.get_document(&c, "x", vec![], false).await.unwrap();
    pool.count(&c, vec![], None).await.unwrap_err();
    pool.sum(&c, "a", vec![], None).await.unwrap_err();
    pool.exists("db").await.unwrap();
    pool.collection_exists("db", "c").await.unwrap();
    pool.list().await.unwrap();
    pool.schema_attributes("c").await.unwrap();
    pool.schema_indexes("c").await.unwrap();
    pool.size_of_collection("c").await.unwrap();
    pool.size_of_collection_on_disk("c").await.unwrap();
    pool.ping().await.unwrap();
    Connection::id(&pool).await.unwrap();
    pool.capabilities();
    pool.limits();
    pool.has_feature(Feature::Spatial);
    pool.attribute_width(&c).unwrap();
    pool.count_of_attributes(&c).unwrap();
    pool.count_of_indexes(&c).unwrap();
    pool.column_type(&utopia_database::attribute::Attribute::string("a", 1)).unwrap();
    for call in [
        "find",
        "getDocument",
        "count",
        "sum",
        "exists",
        "collectionExists",
        "list",
        "getSchemaAttributes",
        "getSchemaIndexes",
        "getSizeOfCollection",
        "getSizeOfCollectionOnDisk",
        "ping",
        "id",
        "capabilities",
        "limits",
        "getAttributeWidth",
        "getCountOfAttributes",
        "getCountOfIndexes",
        "getColumnType",
    ] {
        assert!(read.calls().contains(&call), "{call} must reach the read pool");
    }
    assert!(write.calls().is_empty(), "no read reaches the write pool: {:?}", write.calls());
}

#[tokio::test]
async fn test_writes_route_to_the_write_pool_and_make_reads_sticky() {
    let (pool, write, read) = read_write();
    pool.set_sticky(true).set_sticky_duration(5000);
    pool.create_collection("posts", &[], &[]).await.unwrap();
    assert_eq!(write.calls(), ["createCollection"]);
    pool.ping().await.unwrap();
    assert!(write.calls().contains(&"ping"), "a read right after a write stays on the write pool");
    assert!(read.calls().is_empty());
    assert!(!pool.served_by_replica());
}

#[tokio::test]
async fn test_sticky_duration_expiry_and_disabled_stickiness_route_reads_normally() {
    let (pool, write, read) = read_write();
    pool.set_sticky_duration(1);
    pool.create_collection("posts", &[], &[]).await.unwrap();
    tokio::time::sleep(Duration::from_millis(5)).await;
    pool.ping().await.unwrap();
    assert!(read.calls().contains(&"ping"));
    assert!(pool.served_by_replica());

    let (pool, write2, read2) = read_write();
    pool.set_sticky(false);
    pool.create_collection("posts", &[], &[]).await.unwrap();
    pool.ping().await.unwrap();
    assert!(read2.calls().contains(&"ping"));
    assert!(!write2.calls().contains(&"ping"));
    let _ = write;
}

#[tokio::test]
async fn test_reads_that_decide_a_write_and_reads_after_a_transaction_go_to_the_primary() {
    let (pool, write, read) = read_write();
    let c = collection("posts");
    pool.set_sticky(false);
    pool.get_document(&c, "x", vec![], true).await.unwrap();
    pool.find(&c, vec![], Find { for_permission: utopia_database::PermissionType::Update, ..Find::default() })
        .await
        .unwrap_err();
    assert_eq!(write.calls(), ["getDocument", "find"]);
    assert!(read.calls().is_empty());

    let (pool, write, read) = read_write();
    write.memory().create_collection("posts", &[], &[]).await.unwrap();
    with_transaction(&pool, || async { pool.get_document(&c, "x", vec![], false).await }).await.unwrap();
    pool.get_document(&c, "x", vec![], false).await.unwrap();
    assert!(
        read.calls().is_empty(),
        "reads inside and right after a transaction stay on the primary: {:?}",
        read.calls()
    );
}

#[tokio::test]
async fn test_the_read_replica_receives_the_write_hooks_and_clears_a_stale_timeout() {
    let write = Arc::new(Probe::new("write"));
    let read = Arc::new(Probe::new("read").timeouts());
    read.set_timeout(99, Event::All).unwrap();
    let pool: ReadWritePool<Backing> = Pool::read_write(connections(&write).0, connections(&read).0);
    let hook: Arc<dyn Write> = Arc::new(NoHook);
    pool.add_write_hook(hook.clone());
    pool.set_sticky(false);
    pool.ping().await.unwrap();
    assert_eq!(hook_ptrs(&read.write_hooks()), hook_ptrs(&[hook]));
    assert!(read.recorded_timeouts().is_empty());
}
