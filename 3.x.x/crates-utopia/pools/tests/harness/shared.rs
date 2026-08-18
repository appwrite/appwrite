//! Shared `PHPUnit` scope ports. Call from Stack and Swoole test files.

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Instant;

use async_trait::async_trait;
use parking_lot::Mutex;
use utopia_pools::adapter::Stack;
use utopia_pools::{Adapter, Connection, Group, Pool, PoolError, Recover, RecoverCall, TypeError};
use utopia_telemetry::adapters::TestAdapter;

pub fn pool<A>(adapter: A, name: &str, size: usize) -> Pool<String>
where
    A: Adapter<String> + 'static,
{
    Pool::new(adapter, name, size, || "x".to_string(), 0.0).unwrap()
}

pub async fn connection_id_is_namespaced<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let connection = pool(adapter, "alpha", 2).pop().await.unwrap();
    assert!(connection.id.starts_with("alpha-"));
}

pub async fn connection_exposes_resource<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let connection = pool(adapter, "test", 2).pop().await.unwrap();
    assert_eq!(*connection.resource(), "x");
}

pub async fn connection_reclaim<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 2);
    assert_eq!(pool.count(), 2);
    let connection1 = pool.pop().await.unwrap();
    assert_eq!(pool.count(), 1);
    let connection2 = pool.pop().await.unwrap();
    assert_eq!(pool.count(), 0);
    connection1.reclaim();
    assert_eq!(pool.count(), 1);
    connection2.reclaim();
    assert_eq!(pool.count(), 2);
}

pub struct Seq {
    i: Mutex<u32>,
}

impl Seq {
    pub fn new() -> Self {
        Self { i: Mutex::new(0) }
    }

    pub fn next(&self) -> String {
        let mut i = self.i.lock();
        *i += 1;
        if *i <= 2 {
            "x".into()
        } else {
            "y".into()
        }
    }
}

pub async fn connection_destroy<A, F>(make: F)
where
    A: Adapter<String> + 'static,
    F: Fn() -> A,
{
    let seq = Arc::new(Seq::new());
    let seq2 = Arc::clone(&seq);
    let object = Pool::new(make(), "testDestroy", 2, move || seq2.next(), 0.0).unwrap();
    assert_eq!(object.count(), 2);
    let connection1 = object.pop().await.unwrap();
    let connection2 = object.pop().await.unwrap();
    assert_eq!(object.count(), 0);
    assert_eq!(*connection1.resource(), "x");
    assert_eq!(*connection2.resource(), "x");
    connection1.destroy();
    connection2.destroy();
    assert_eq!(object.count(), 2);
    let connection1 = object.pop().await.unwrap();
    let connection2 = object.pop().await.unwrap();
    assert_eq!(object.count(), 0);
    assert_eq!(*connection1.resource(), "y");
    assert_eq!(*connection2.resource(), "y");
}

pub struct Tracked {
    freed: Arc<AtomicUsize>,
}

impl Recover for Tracked {}

impl Drop for Tracked {
    fn drop(&mut self) {
        self.freed.fetch_add(1, Ordering::SeqCst);
    }
}

pub async fn dropping_a_pool_frees_idle_resources<A>(adapter: A)
where
    A: Adapter<Tracked> + 'static,
{
    let freed = Arc::new(AtomicUsize::new(0));
    {
        let flag = Arc::clone(&freed);
        let pool = Pool::new(
            adapter,
            "lifetime",
            3,
            move || Tracked {
                freed: Arc::clone(&flag),
            },
            1.0,
        )
        .unwrap();
        let connections = [
            pool.pop().await.unwrap(),
            pool.pop().await.unwrap(),
            pool.pop().await.unwrap(),
        ];
        for connection in &connections {
            connection.reclaim();
        }
    }
    assert_eq!(freed.load(Ordering::SeqCst), 3);
}

pub async fn connection_outlives_pool<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let connection = pool(adapter, "orphan", 2).pop().await.unwrap();
    connection.reclaim();
    connection.destroy();
    assert_eq!(*connection.resource(), "x");
}

pub fn group_add_get_remove<A, F>(make: F)
where
    A: Adapter<String> + 'static,
    F: Fn() -> A,
{
    let mut group = Group::new();
    group.add(pool(make(), "test", 1));
    assert!(group.get("test").is_ok());
    assert!(matches!(
        group.get("testx"),
        Err(PoolError::NotFound(name)) if name == "testx"
    ));
    group.remove("test");
    assert!(matches!(group.get("test"), Err(PoolError::NotFound(_))));
}

pub async fn group_reclaim<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let mut group = Group::new();
    group.add(pool(adapter, "test", 5));
    assert_eq!(group.get("test").unwrap().count(), 5);
    group.get("test").unwrap().pop().await.unwrap();
    group.get("test").unwrap().pop().await.unwrap();
    group.get("test").unwrap().pop().await.unwrap();
    assert_eq!(group.get("test").unwrap().count(), 2);
    group.reclaim();
    assert_eq!(group.get("test").unwrap().count(), 5);
}

pub async fn group_use<A, F>(make: F)
where
    A: Adapter<String> + 'static,
    F: Fn() -> A,
{
    let mut group = Group::new();
    let pool1 = Pool::new(make(), "pool1", 1, || "1".into(), 0.0).unwrap();
    let pool2 = Pool::new(make(), "pool2", 1, || "2".into(), 0.0).unwrap();
    let pool3 = Pool::new(make(), "pool3", 1, || "3".into(), 0.0).unwrap();
    group.add(pool1.clone());
    group.add(pool2.clone());
    group.add(pool3.clone());
    assert_eq!(pool1.count(), 1);
    group
        .use_resources(&["pool1", "pool3"], |resources| {
            assert_eq!(*resources[0], "1");
            assert_eq!(*resources[1], "3");
            assert_eq!(pool1.count(), 0);
            assert_eq!(pool2.count(), 1);
            assert_eq!(pool3.count(), 0);
            Ok(())
        })
        .await
        .unwrap();
    assert_eq!(pool1.count(), 1);
    assert_eq!(pool2.count(), 1);
    assert_eq!(pool3.count(), 1);
}

pub struct Named {
    pub name: String,
}

impl Recover for Named {}

pub async fn group_use_reclaims_when_later_missing<A>(adapter: A)
where
    A: Adapter<Named> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let mut group = Group::new();
    let pool = Pool::new(
        adapter,
        "pool1",
        1,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Named {
                name: format!("resource-{n}"),
            }
        },
        0.0,
    )
    .unwrap();
    group.add(pool.clone());
    let err = group
        .use_resources(&["pool1", "missing"], |_| Ok(()))
        .await
        .unwrap_err();
    assert!(matches!(err, PoolError::NotFound(_)));
    assert_eq!(pool.count(), 1);
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-1");
        Ok(())
    })
    .await
    .unwrap();
    assert_eq!(created.load(Ordering::SeqCst), 1);
}

pub async fn group_use_records_use_duration<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let telemetry = Arc::new(TestAdapter::new());
    let mut group = Group::new();
    group.add(
        Pool::with_telemetry(
            adapter,
            "pool1",
            1,
            || "1".into(),
            0.0,
            Some(telemetry.clone()),
        )
        .unwrap(),
    );
    assert!(telemetry
        .histogram_measurements("pool.connection.use_time")
        .is_empty());
    group
        .use_resources(&["pool1"], |resources| {
            assert_eq!(*resources[0], "1");
            Ok(())
        })
        .await
        .unwrap();
    assert_eq!(
        telemetry
            .histogram_measurements("pool.connection.use_time")
            .len(),
        1
    );
}

pub fn pool_name_size<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    assert_eq!(pool.name(), "test");
    assert_eq!(pool.size(), 5);
}

pub async fn pool_pop<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    assert_eq!(pool.count(), 5);
    let connection = pool.pop().await.unwrap();
    assert_eq!(pool.count(), 4);
    assert_eq!(*connection.resource(), "x");
    for _ in 0..4 {
        pool.pop().await.unwrap();
    }
    let err = pool.pop().await.unwrap_err();
    assert!(matches!(err, PoolError::Timeout(_)));
}

pub async fn pool_use<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    assert_eq!(pool.count(), 5);
    pool.use_resource(|resource| {
        assert_eq!(pool.count(), 4);
        assert_eq!(*resource, "x");
        Ok(())
    })
    .await
    .unwrap();
    assert_eq!(pool.count(), 5);
}

pub async fn pool_push_count<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    let connection = pool.pop().await.unwrap();
    assert_eq!(pool.count(), 4);
    pool.push(&connection);
    assert_eq!(pool.count(), 5);
}

pub async fn pool_reclaim<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    pool.pop().await.unwrap();
    pool.pop().await.unwrap();
    pool.pop().await.unwrap();
    assert_eq!(pool.count(), 2);
    pool.reclaim(None);
    assert_eq!(pool.count(), 5);
}

pub async fn pool_is_empty_full<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    assert!(pool.is_full());
    let connection = pool.pop().await.unwrap();
    assert!(!pool.is_full());
    pool.push(&connection);
    assert!(pool.is_full());
    for _ in 0..5 {
        pool.pop().await.unwrap();
    }
    assert!(pool.is_empty());
    assert!(!pool.is_full());
    pool.reclaim(None);
    assert!(pool.is_full());
}

pub async fn pool_destroy<A, F>(make: F)
where
    A: Adapter<String> + 'static,
    F: Fn() -> A,
{
    let seq = Arc::new(Seq::new());
    let seq2 = Arc::clone(&seq);
    let object = Pool::new(make(), "testDestroy", 2, move || seq2.next(), 0.0).unwrap();
    let _c1 = object.pop().await.unwrap();
    let _c2 = object.pop().await.unwrap();
    object.destroy(None);
    assert_eq!(object.count(), 2);
    let c1 = object.pop().await.unwrap();
    let c2 = object.pop().await.unwrap();
    assert_eq!(*c1.resource(), "y");
    assert_eq!(*c2.resource(), "y");
}

pub async fn pop_timeout_then_throws<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = Pool::new(adapter, "test-budget", 1, || "x".into(), 0.25).unwrap();
    pool.pop().await.unwrap();
    let start = Instant::now();
    let err = pool.pop().await.unwrap_err();
    let msg = err.to_string();
    assert!(msg.contains("could not provide a connection"), "{msg}");
    assert!(start.elapsed().as_secs_f64() < 3.0);
}

pub async fn creation_failure_surfaces<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let calls = Arc::new(AtomicUsize::new(0));
    let calls2 = Arc::clone(&calls);
    let pool = Pool::try_new(
        adapter,
        "test-create-fails",
        1,
        move || {
            calls2.fetch_add(1, Ordering::SeqCst);
            Err("connect refused".into())
        },
        0.0,
        None,
    )
    .unwrap();
    let err = pool.pop().await.unwrap_err();
    assert_eq!(err.to_string(), "connect refused");
    assert!(err.init_source().unwrap().source().is_none());
    assert_eq!(calls.load(Ordering::SeqCst), 1);
    assert_eq!(pool.count(), 1);
}

pub async fn pop_releases_slot_on_type_error<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = Pool::try_new(
        adapter,
        "test-error-leak",
        2,
        || Err(Box::new(TypeError::new("Connection init failed"))),
        0.0,
        None,
    )
    .unwrap();
    let mut thrown = 0;
    for _ in 0..5 {
        match pool.pop().await {
            Err(PoolError::Init(inner)) => {
                thrown += 1;
                assert!(inner.downcast_ref::<TypeError>().is_some());
            }
            other => panic!("expected TypeError, got {other:?}"),
        }
    }
    assert_eq!(thrown, 5);
    assert_eq!(pool.count(), 2);
}

pub async fn double_destroy_does_not_inflate<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        adapter,
        "test-double-destroy",
        2,
        move || {
            created2.fetch_add(1, Ordering::SeqCst);
            "x".into()
        },
        0.0,
    )
    .unwrap();
    let connection = pool.pop().await.unwrap();
    pool.destroy(Some(&connection));
    pool.destroy(Some(&connection));
    assert_eq!(pool.count(), 2);
    pool.pop().await.unwrap();
    pool.pop().await.unwrap();
    assert!(pool.pop().await.is_err());
    assert_eq!(created.load(Ordering::SeqCst), 3);
}

pub async fn empty_error_includes_active<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let pool = pool(adapter, "test", 5);
    for _ in 0..5 {
        pool.pop().await.unwrap();
    }
    let msg = pool.pop().await.unwrap_err().to_string();
    assert!(msg.contains("active 5"), "{msg}");
}

pub struct Recycle {
    pub name: String,
    pub reconnect: RecoverCall,
    pub panic_on_reconnect: bool,
}

impl Recover for Recycle {
    fn reconnect(&mut self) -> RecoverCall {
        assert!(!self.panic_on_reconnect, "Recovery failed");
        self.reconnect
    }
}

pub async fn use_destroys_when_recovery_fails<A>(adapter: A)
where
    A: Adapter<Recycle> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        adapter,
        "test-destroy-on-error",
        2,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Recycle {
                name: format!("resource-{n}"),
                reconnect: RecoverCall::Succeeded,
                panic_on_reconnect: n == 1,
            }
        },
        0.0,
    )
    .unwrap();
    let err = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await
        .unwrap_err();
    assert_eq!(err.to_string(), "Callback failed");
    assert_eq!(pool.count(), 2);
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-2");
        Ok(())
    })
    .await
    .unwrap();
}

pub async fn use_destroys_when_recovery_returns_false<A>(adapter: A)
where
    A: Adapter<Recycle> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        adapter,
        "test-destroy-on-false-recovery",
        2,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Recycle {
                name: format!("resource-{n}"),
                reconnect: RecoverCall::Failed,
                panic_on_reconnect: false,
            }
        },
        0.0,
    )
    .unwrap();
    let _ = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await;
    assert_eq!(pool.count(), 2);
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-2");
        Ok(())
    })
    .await
    .unwrap();
}

pub async fn use_recovers_when_reconnect_succeeds<A>(adapter: A)
where
    A: Adapter<Recycle> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        adapter,
        "test-recover-and-reuse",
        2,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Recycle {
                name: format!("resource-{n}"),
                reconnect: RecoverCall::Succeeded,
                panic_on_reconnect: false,
            }
        },
        0.0,
    )
    .unwrap();
    let _ = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await;
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-1");
        assert_eq!(created.load(Ordering::SeqCst), 1);
        Ok(())
    })
    .await
    .unwrap();
}

pub async fn use_destroys_without_hooks<A>(adapter: A)
where
    A: Adapter<Named> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::new(
        adapter,
        "test-destroy-without-recovery",
        2,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Named {
                name: format!("resource-{n}"),
            }
        },
        0.0,
    )
    .unwrap();
    let _ = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await;
    assert_eq!(pool.count(), 2);
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-2");
        Ok(())
    })
    .await
    .unwrap();
}

pub struct FailSync<T> {
    inner: Stack<T>,
    fail: Arc<std::sync::atomic::AtomicBool>,
}

impl<T> FailSync<T> {
    pub fn new(fail: Arc<std::sync::atomic::AtomicBool>) -> Self {
        Self {
            inner: Stack::new(),
            fail,
        }
    }
}

#[async_trait]
impl<T: Send + 'static> Adapter<T> for FailSync<T> {
    fn initialize(&self, size: usize) {
        self.inner.initialize(size);
    }

    fn push(&self, connection: Connection<T>) {
        self.inner.push(connection);
    }

    async fn pop(&self, timeout: std::time::Duration) -> Option<Connection<T>> {
        self.inner.pop(timeout).await
    }

    fn count(&self) -> usize {
        self.inner.count()
    }

    fn synchronized(&self, callback: Box<dyn FnOnce() + Send>) -> Result<(), PoolError> {
        if self.fail.swap(false, Ordering::SeqCst) {
            return Err(PoolError::Adapter("Lock failed".into()));
        }
        self.inner.synchronized(callback)
    }
}

pub async fn use_forgets_when_destroy_sync_fails() {
    let fail = Arc::new(std::sync::atomic::AtomicBool::new(false));
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let fail_flag = Arc::clone(&fail);
    let pool = Pool::new(
        FailSync::new(Arc::clone(&fail)),
        "test-forget-on-destroy-failure",
        1,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            Named {
                name: format!("resource-{n}"),
            }
        },
        0.0,
    )
    .unwrap();
    let err = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            fail_flag.store(true, Ordering::SeqCst);
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await
        .unwrap_err();
    assert_eq!(err.to_string(), "Callback failed");
    pool.use_resource(|resource| {
        assert_eq!(resource.name, "resource-2");
        assert_eq!(created.load(Ordering::SeqCst), 2);
        Ok(())
    })
    .await
    .unwrap();
}

pub async fn use_preserves_callback_error<A>(adapter: A)
where
    A: Adapter<Recycle> + 'static,
{
    let created = Arc::new(AtomicUsize::new(0));
    let created2 = Arc::clone(&created);
    let pool = Pool::try_new(
        adapter,
        "test-preserve-callback-error",
        1,
        move || {
            let n = created2.fetch_add(1, Ordering::SeqCst) + 1;
            if n > 1 {
                return Err("Replacement failed".into());
            }
            Ok(Recycle {
                name: format!("resource-{n}"),
                reconnect: RecoverCall::Succeeded,
                panic_on_reconnect: true,
            })
        },
        0.0,
        None,
    )
    .unwrap();
    let err = pool
        .use_resource(|resource| {
            assert_eq!(resource.name, "resource-1");
            Err::<(), _>(PoolError::callback("Callback failed"))
        })
        .await
        .unwrap_err();
    assert_eq!(err.to_string(), "Callback failed");
    assert_eq!(pool.count(), 1);
}

pub async fn pool_use_duration_telemetry<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let telemetry = Arc::new(TestAdapter::new());
    let pool = Pool::with_telemetry(
        adapter,
        "test",
        5,
        || "x".into(),
        0.0,
        Some(telemetry.clone()),
    )
    .unwrap();
    assert!(telemetry
        .histogram_measurements("pool.connection.use_time")
        .is_empty());
    pool.use_resource(|resource| {
        assert_eq!(*resource, "x");
        Ok(())
    })
    .await
    .unwrap();
    assert_eq!(
        telemetry
            .histogram_measurements("pool.connection.use_time")
            .len(),
        1
    );
}

pub async fn pool_wait_time_telemetry<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let telemetry = Arc::new(TestAdapter::new());
    let pool = Pool::with_telemetry(
        adapter,
        "test",
        5,
        || "x".into(),
        0.0,
        Some(telemetry.clone()),
    )
    .unwrap();
    let mut connections = Vec::new();
    for _ in 0..3 {
        connections.push(pool.pop().await.unwrap());
    }
    assert_eq!(
        telemetry
            .histogram_measurements("pool.connection.wait_time")
            .len(),
        3
    );
    assert!(telemetry
        .histogram_measurements("pool.connection.use_time")
        .is_empty());
    pool.reclaim(Some(&connections.pop().unwrap()));
    for connection in connections {
        pool.reclaim(Some(&connection));
    }
    assert_eq!(pool.count(), 5);
}

pub fn invalid_size_timeout<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let err = Pool::new(adapter, "bad", 0, || "x".into(), 0.0).unwrap_err();
    assert!(err.to_string().contains("size must be at least 1, got 0"));
}

pub fn invalid_timeout<A, F>(make: F)
where
    A: Adapter<String> + 'static,
    F: Fn() -> A,
{
    let err = Pool::new(make(), "bad", 1, || "x".into(), -1.0).unwrap_err();
    assert!(err
        .to_string()
        .contains("timeout cannot be negative, got -1"));
}

pub async fn group_empty_names<A>(adapter: A)
where
    A: Adapter<String> + 'static,
{
    let mut group = Group::new();
    group.add(pool(adapter, "test", 1));
    let err = group.use_resources(&[], |_| Ok(())).await.unwrap_err();
    assert!(matches!(err, PoolError::EmptyNames));
}
