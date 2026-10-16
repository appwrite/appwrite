//! PHP `tests/Pools/Adapter/SwooleTest.php` plus shared scopes.

#[allow(dead_code)]
#[path = "harness/shared.rs"]
mod support;

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Duration;

use tokio::sync::oneshot;
use utopia_pools::adapter::Swoole;
use utopia_pools::Pool;

#[tokio::test]
async fn connection_id_is_namespaced() {
    support::connection_id_is_namespaced(Swoole::new()).await;
}

#[tokio::test]
async fn connection_exposes_resource() {
    support::connection_exposes_resource(Swoole::new()).await;
}

#[tokio::test]
async fn connection_reclaim() {
    support::connection_reclaim(Swoole::new()).await;
}

#[tokio::test]
async fn connection_destroy() {
    support::connection_destroy(Swoole::<String>::new).await;
}

#[tokio::test]
async fn dropping_a_pool_frees_idle_resources() {
    support::dropping_a_pool_frees_idle_resources(Swoole::new()).await;
}

#[tokio::test]
async fn connection_outlives_pool() {
    support::connection_outlives_pool(Swoole::new()).await;
}

#[tokio::test]
async fn group_add_get_remove() {
    support::group_add_get_remove(Swoole::<String>::new);
}

#[tokio::test]
async fn group_reclaim() {
    support::group_reclaim(Swoole::new()).await;
}

#[tokio::test]
async fn group_use() {
    support::group_use(Swoole::<String>::new).await;
}

#[tokio::test]
async fn group_use_reclaims_when_later_missing() {
    support::group_use_reclaims_when_later_missing(Swoole::new()).await;
}

#[tokio::test]
async fn pool_pop() {
    support::pool_pop(Swoole::new()).await;
}

#[tokio::test]
async fn pool_use() {
    support::pool_use(Swoole::new()).await;
}

#[tokio::test]
async fn creation_failure_surfaces() {
    support::creation_failure_surfaces(Swoole::new()).await;
}

#[tokio::test]
async fn use_recovers_when_reconnect_succeeds() {
    support::use_recovers_when_reconnect_succeeds(Swoole::new()).await;
}

#[tokio::test]
async fn pop_timeout_then_throws() {
    support::pop_timeout_then_throws(Swoole::new()).await;
}

/// PHP `testInitOutsideCoroutineDoesNotThrow`.
#[test]
fn init_outside_runtime_does_not_throw() {
    let pool = Pool::new(Swoole::new(), "test", 1, || "x".to_string(), 0.0).unwrap();
    assert_eq!(pool.name(), "test");
}

/// PHP `testSwooleCoroutineRaceCondition`.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn coroutine_race_condition() {
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        Swoole::new(),
        "swoole-test",
        5,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            format!("connection-{n}")
        },
        5.0,
    )
    .unwrap();

    let mut joins = Vec::new();
    for i in 0..10 {
        let pool = pool.clone();
        joins.push(tokio::spawn(async move {
            let connection = pool.pop().await.unwrap();
            assert!(!connection.id.is_empty() && connection.id != "0");
            tokio::time::sleep(Duration::from_millis(10)).await;
            pool.reclaim(Some(&connection));
            i
        }));
    }
    for join in joins {
        join.await.unwrap();
    }
    assert_eq!(pool.count(), 5);
    assert_eq!(created.load(Ordering::SeqCst), 5);
}

/// PHP `testSwooleCoroutineHighConcurrency`.
#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn coroutine_high_concurrency() {
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        Swoole::new(),
        "swoole-concurrent",
        3,
        move || {
            created2.fetch_add(1, Ordering::SeqCst);
            format!("connection-{}", created2.load(Ordering::SeqCst))
        },
        5.0,
    )
    .unwrap();

    let mut joins = Vec::new();
    for i in 0..20 {
        let pool = pool.clone();
        joins.push(tokio::spawn(async move {
            pool.use_resource(|_resource| {
                std::thread::sleep(Duration::from_millis(10));
                Ok(format!("processed-{i}"))
            })
            .await
            .unwrap()
        }));
    }
    for join in joins {
        join.await.unwrap();
    }
    assert_eq!(pool.count(), 3);
    assert_eq!(created.load(Ordering::SeqCst), 3);
}

/// PHP `testSwooleCoroutineConnectionUniqueness`.
#[tokio::test(flavor = "multi_thread", worker_threads = 8)]
async fn coroutine_connection_uniqueness() {
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        Swoole::new(),
        "swoole-uniqueness",
        5,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            format!("connection-{n}")
        },
        5.0,
    )
    .unwrap();

    let (tx, mut rx) = tokio::sync::mpsc::unbounded_channel();
    let mut joins = Vec::new();
    for _ in 0..5 {
        let pool = pool.clone();
        let tx = tx.clone();
        joins.push(tokio::spawn(async move {
            let connection = pool.pop().await.unwrap();
            let resource = connection.resource().clone();
            tx.send(resource).unwrap();
            tokio::time::sleep(Duration::from_millis(10)).await;
        }));
    }
    drop(tx);
    let mut seen = Vec::new();
    while let Some(resource) = rx.recv().await {
        seen.push(resource);
    }
    for join in joins {
        join.await.unwrap();
    }
    seen.sort();
    seen.dedup();
    assert_eq!(seen.len(), 5);
}

/// PHP `testSwooleCoroutineIdleConnectionReuse`.
#[tokio::test]
async fn coroutine_idle_connection_reuse() {
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        Swoole::new(),
        "swoole-reuse",
        3,
        move || {
            created2.fetch_add(1, Ordering::SeqCst);
            format!("connection-{}", created2.load(Ordering::SeqCst))
        },
        5.0,
    )
    .unwrap();

    let mut first = Vec::new();
    for _ in 0..3 {
        first.push(pool.pop().await.unwrap().id.clone());
    }
    // Reclaim via pool.reclaim(None) after pops - connections still in active.
    pool.reclaim(None);

    let mut second = Vec::new();
    for _ in 0..3 {
        second.push(pool.pop().await.unwrap().id.clone());
    }
    pool.reclaim(None);

    first.sort();
    second.sort();
    assert_eq!(created.load(Ordering::SeqCst), 3);
    assert_eq!(first, second);
}

/// PHP `testSwooleCoroutineStressTest`.
#[tokio::test(flavor = "multi_thread", worker_threads = 8)]
async fn coroutine_stress() {
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        Swoole::new(),
        "swoole-stress",
        10,
        move || {
            created2.fetch_add(1, Ordering::SeqCst);
            format!("connection-{}", created2.load(Ordering::SeqCst))
        },
        5.0,
    )
    .unwrap();

    let mut held = Vec::new();
    for _ in 0..10 {
        held.push(pool.pop().await.unwrap());
    }
    assert_eq!(created.load(Ordering::SeqCst), 10);
    for connection in &held {
        pool.reclaim(Some(connection));
    }

    let mut joins = Vec::new();
    for _ in 0..100 {
        let pool = pool.clone();
        joins.push(tokio::spawn(async move {
            pool.use_resource(|resource| {
                let _ = resource;
                Ok(())
            })
            .await
            .unwrap();
        }));
    }
    for join in joins {
        join.await.unwrap();
    }
    assert_eq!(created.load(Ordering::SeqCst), 10);
    assert_eq!(pool.count(), 10);
}

/// Waiters wake when a connection is returned (oneshot handshake).
#[tokio::test]
async fn waiter_is_notified_on_push() {
    let pool = Pool::new(Swoole::new(), "notify", 1, || "x".to_string(), 2.0).unwrap();
    let held = pool.pop().await.unwrap();
    let (started_tx, started_rx) = oneshot::channel::<()>();
    let waiter = {
        let pool = pool.clone();
        tokio::spawn(async move {
            started_tx.send(()).unwrap();
            pool.pop().await.unwrap()
        })
    };
    started_rx.await.unwrap();
    tokio::time::sleep(Duration::from_millis(20)).await;
    pool.reclaim(Some(&held));
    let got = tokio::time::timeout(Duration::from_secs(1), waiter)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(*got.resource(), "x");
}
