//! [`Pool`] and the [`Connection`]s it lends.

use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard, Weak};
use std::time::{Duration, Instant};

use utopia_telemetry::{Histogram, NoTelemetry, Telemetry, Value};

use crate::{Adapter, BoxError, Error, Resource};

/// Bucket boundaries of the wait and use time histograms, in seconds.
const BOUNDARIES: [f64; 14] = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

type Init<T> = Box<dyn Fn() -> utopia_telemetry::BoxFuture<'static, Result<T, BoxError>> + Send + Sync>;

/// What a connection's pool does when the connection is handed back
/// through [`Connection::reclaim`] or [`Connection::destroy`].
trait Owner<T>: Send + Sync {
    fn reclaim(&self, connection: &Connection<T>);
    fn destroy(&self, connection: &Connection<T>);
}

struct Lent<T> {
    id: String,
    resource: T,
    pool: Weak<dyn Owner<T>>,
}

/// A resource checked out of a pool: an id unique in the process
/// (`<pool name>-<uniqid>`), the resource, and a weak link to its pool.
///
/// Clones are the same connection. Handing it back to a pool that no longer
/// exists does nothing, and the connection keeps its resource.
///
/// PHP: `Utopia\Pools\Connection`.
pub struct Connection<T>(Arc<Lent<T>>);

impl<T> Clone for Connection<T> {
    /// The same connection.
    fn clone(&self) -> Self {
        Connection(self.0.clone())
    }
}

impl<T> std::fmt::Debug for Connection<T> {
    /// Writes the id.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_tuple("Connection").field(&self.0.id).finish()
    }
}

impl<T> Connection<T> {
    /// The id: the pool's name, a dash and a `uniqid()`.
    ///
    /// PHP: `$connection->id`.
    pub fn id(&self) -> &str {
        &self.0.id
    }

    /// The pooled resource.
    ///
    /// PHP: `$connection->resource`.
    pub fn resource(&self) -> &T {
        &self.0.resource
    }

    /// Returns the connection to its pool for reuse.
    ///
    /// PHP: `reclaim()`.
    pub fn reclaim(&self) {
        if let Some(pool) = self.0.pool.upgrade() {
            pool.reclaim(self);
        }
    }

    /// Discards the connection and frees its capacity for a replacement.
    ///
    /// PHP: `destroy()`.
    pub fn destroy(&self) {
        if let Some(pool) = self.0.pool.upgrade() {
            pool.destroy(self);
        }
    }

    fn same(&self, other: &Connection<T>) -> bool {
        Arc::ptr_eq(&self.0, &other.0)
    }
}

impl<T> std::ops::Deref for Connection<T> {
    type Target = T;

    /// The pooled resource.
    fn deref(&self) -> &T {
        &self.0.resource
    }
}

/// The pool's bookkeeping, under one lock.
struct Book<T> {
    /// Checked-out connections, in the order they were checked out.
    active: Vec<Connection<T>>,
    /// Capacity in use: resources that exist plus creations in flight. Can
    /// go below zero when a connection handed back twice is destroyed twice.
    reserved: i64,
    /// When each checked-out connection was requested.
    checked_out_at: Vec<(String, Instant)>,
}

struct Inner<T, A> {
    adapter: A,
    name: String,
    size: usize,
    timeout: f64,
    init: Init<T>,
    book: Mutex<Book<T>>,
    wait_time: Histogram,
    use_time: Histogram,
    me: Weak<Inner<T, A>>,
}

/// A slot reserved for a resource being created: freed on drop unless kept.
struct Reservation<'a, T, A: Adapter<T>>(Option<&'a Inner<T, A>>);

impl<T, A: Adapter<T>> Reservation<'_, T, A> {
    /// The resource exists: the slot stays taken.
    fn keep(mut self) {
        self.0 = None;
    }
}

impl<T, A: Adapter<T>> Drop for Reservation<'_, T, A> {
    fn drop(&mut self) {
        if let Some(inner) = self.0.take() {
            lock(&inner.book).reserved -= 1;
            inner.adapter.unblock();
        }
    }
}

/// A fixed-capacity pool of resources created on demand.
///
/// [`Pool::pop`] hands out an idle connection, creates one while fewer than
/// `size` exist, or waits up to `timeout` for one to come back. A failed
/// creation fails the pop with `init`'s own error, and frees its slot: retry
/// policy belongs in `init`. Hand connections back with [`Pool::reclaim`]
/// (reuse) or [`Pool::destroy`] (discard), or run work through
/// [`Pool::with`], which does it for you.
///
/// Clones share one pool.
///
/// ```
/// use utopia_pools::{Pool, Stack};
///
/// # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
/// let pool = Pool::new(Stack::new(), "cache", 2, || async { Ok::<_, std::io::Error>("x".to_owned()) }, 0.0)?;
/// assert_eq!(pool.count(), 2);
///
/// let connection = pool.pop().await?;
/// assert_eq!(connection.resource(), "x");
/// assert_eq!(pool.count(), 1);
///
/// pool.reclaim(&connection);
/// assert!(pool.is_full());
/// # Ok::<(), utopia_pools::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Pools\Adapter\Stack;
/// use Utopia\Pools\Pool;
///
/// $pool = new Pool(new Stack(), 'cache', 2, fn () => 'x', timeout: 0.0);
/// echo $pool->count(), "\n"; // 2
///
/// $connection = $pool->pop();
/// echo $connection->resource, "\n"; // x
/// echo $pool->count(), "\n"; // 1
///
/// $pool->reclaim($connection);
/// var_dump($pool->isFull()); // bool(true)
/// ```
///
/// PHP: `Utopia\Pools\Pool`.
pub struct Pool<T, A> {
    inner: Arc<Inner<T, A>>,
}

impl<T, A> Clone for Pool<T, A> {
    /// The same pool.
    fn clone(&self) -> Self {
        Pool { inner: self.inner.clone() }
    }
}

impl<T, A> Pool<T, A> {
    /// A token telling whether the pool (any of its clones) still exists,
    /// without keeping it alive: what a cache keyed by pool (PHP's
    /// `WeakMap`) checks before it reuses an entry.
    ///
    /// Not in PHP, where a `WeakMap` keyed by the pool object does this.
    pub fn liveness(&self) -> Liveness<T, A> {
        Liveness(Arc::downgrade(&self.inner))
    }
}

/// Whether a pool still exists ([`Pool::liveness`]).
pub struct Liveness<T, A>(Weak<Inner<T, A>>);

impl<T, A> Liveness<T, A> {
    /// Whether the pool still exists.
    pub fn is_alive(&self) -> bool {
        self.0.strong_count() > 0
    }

    /// Whether this is the liveness of `pool`.
    pub fn is_of(&self, pool: &Pool<T, A>) -> bool {
        std::ptr::eq(self.0.as_ptr(), Arc::as_ptr(&pool.inner))
    }
}

impl<T, A> std::fmt::Debug for Liveness<T, A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_tuple("Liveness").field(&self.is_alive()).finish()
    }
}

impl<T, A> std::fmt::Debug for Pool<T, A> {
    /// Writes the name and size.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool").field("name", &self.inner.name).field("size", &self.inner.size).finish()
    }
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

impl<T: Resource, A: Adapter<T>> Pool<T, A> {
    /// A pool named `name` of at most `size` resources made by `init`, whose
    /// [`Pool::pop`] waits at most `timeout` seconds for one, recording no
    /// telemetry.
    ///
    /// Fails with [`Error::InvalidArgument`] when `size` is 0 or `timeout`
    /// is negative.
    ///
    /// PHP: `new Pool($adapter, $name, $size, $init, $timeout)`.
    pub fn new<F, E>(
        adapter: A,
        name: &str,
        size: usize,
        init: impl Fn() -> F + Send + Sync + 'static,
        timeout: f64,
    ) -> Result<Self, Error>
    where
        F: Future<Output = Result<T, E>> + Send + 'static,
        E: Into<BoxError>,
    {
        Self::with_telemetry(adapter, name, size, init, timeout, &NoTelemetry)
    }

    /// A pool recording its metrics with `telemetry`: histograms
    /// `pool.connection.wait_time` and `pool.connection.use_time` (seconds),
    /// and observable gauges `pool.connection.active.count`, `.idle.count`,
    /// `.open.count` and `.capacity.count`, each with the attributes `pool`
    /// (the name) and `size`.
    ///
    /// PHP: the constructor's `telemetry` argument.
    pub fn with_telemetry<F, E>(
        mut adapter: A,
        name: &str,
        size: usize,
        init: impl Fn() -> F + Send + Sync + 'static,
        timeout: f64,
        telemetry: &dyn Telemetry,
    ) -> Result<Self, Error>
    where
        F: Future<Output = Result<T, E>> + Send + 'static,
        E: Into<BoxError>,
    {
        if size < 1 {
            return Err(Error::InvalidArgument(format!("Pool '{name}' size must be at least 1, got {size}.")));
        }
        if timeout < 0.0 || timeout.is_nan() {
            return Err(Error::InvalidArgument(format!(
                "Pool '{name}' timeout cannot be negative, got {}.",
                php_std::number::to_string(timeout)
            )));
        }
        adapter.initialize(size);
        let wait_time = telemetry.histogram("pool.connection.wait_time", Some("s"), None, Some(&BOUNDARIES));
        let use_time = telemetry.histogram("pool.connection.use_time", Some("s"), None, Some(&BOUNDARIES));
        let inner = Arc::new_cyclic(|me| Inner {
            adapter,
            name: name.to_owned(),
            size,
            timeout,
            init: Box::new(move || {
                let created = init();
                Box::pin(async move { created.await.map_err(Into::into) })
            }),
            book: Mutex::new(Book { active: Vec::new(), reserved: 0, checked_out_at: Vec::new() }),
            wait_time,
            use_time,
            me: me.clone(),
        });
        type Sample<T, A> = fn(&Inner<T, A>) -> i64;
        let gauges: [(&str, Sample<T, A>); 4] = [
            ("pool.connection.active.count", |p| lock(&p.book).active.len() as i64),
            ("pool.connection.idle.count", |p| p.adapter.count() as i64),
            ("pool.connection.open.count", |p| (lock(&p.book).active.len() + p.adapter.count()) as i64),
            ("pool.connection.capacity.count", |p| lock(&p.book).reserved),
        ];
        for (gauge, sample) in gauges {
            let pool = Arc::downgrade(&inner);
            telemetry.observable_gauge(gauge, None, None).observe(move |observer| {
                if let Some(pool) = pool.upgrade() {
                    let size = i64::try_from(pool.size).unwrap_or(i64::MAX);
                    observer.observe(
                        sample(&pool).into(),
                        &[("pool", Value::from(pool.name.as_str())), ("size", Value::Int(size))],
                    );
                }
            });
        }
        Ok(Pool { inner })
    }

    /// The pool's name: in errors, connection ids and metrics.
    ///
    /// PHP: `$pool->name`.
    pub fn name(&self) -> &str {
        &self.inner.name
    }

    /// The most resources that exist at once.
    ///
    /// PHP: `$pool->size`.
    pub fn size(&self) -> usize {
        self.inner.size
    }

    /// Seconds [`Pool::pop`] waits for a connection.
    ///
    /// PHP: `$pool->timeout`.
    pub fn timeout(&self) -> f64 {
        self.inner.timeout
    }

    /// The adapter holding the idle connections.
    pub fn adapter(&self) -> &A {
        &self.inner.adapter
    }

    /// Takes a connection: an idle one, a new one while there is capacity,
    /// or one handed back within the timeout. Creation is not bounded by the
    /// timeout, and its failure is returned as [`Error::Init`] at once.
    ///
    /// Fails with [`Error::Exhausted`] when no connection becomes available
    /// in time.
    ///
    /// PHP: `pop()`.
    pub async fn pop(&self) -> Result<Connection<T>, Error> {
        let inner = &*self.inner;
        let start = Instant::now();
        let deadline = start + Duration::from_secs_f64(inner.timeout.min(1e9));
        let result = self.acquire(start, deadline).await;
        inner.wait_time.record(start.elapsed().as_secs_f64(), &self.attributes());
        result
    }

    fn attributes(&self) -> [(&str, Value<'_>); 2] {
        [("pool", Value::from(self.inner.name.as_str())), ("size", Value::Int(self.inner.size as i64))]
    }

    async fn acquire(&self, start: Instant, deadline: Instant) -> Result<Connection<T>, Error> {
        let inner = &*self.inner;
        loop {
            let slot = inner.adapter.synchronized(|| {
                let mut book = lock(&inner.book);
                if inner.adapter.count() == 0 && book.reserved < inner.size as i64 {
                    book.reserved += 1;
                    true
                } else {
                    false
                }
            });
            if slot {
                // A pop dropped while the resource is being created (a
                // cancelled request, a `now_or_never` poll) frees its slot,
                // as a failed creation does: otherwise the capacity leaks and
                // the pool stops growing.
                let reservation = Reservation(Some(inner));
                return match (inner.init)().await {
                    Ok(resource) => {
                        reservation.keep();
                        let owner: Weak<dyn Owner<T>> = inner.me.clone();
                        let id = format!("{}-{}", inner.name, php_std::system::uniqid("", false));
                        let connection = Connection(Arc::new(Lent { id, resource, pool: owner }));
                        inner.track(&connection, start);
                        Ok(connection)
                    }
                    Err(error) => {
                        drop(reservation);
                        Err(Error::Init(error))
                    }
                };
            }
            let left = deadline.saturating_duration_since(Instant::now());
            if let Some(connection) = inner.adapter.pop(left).await {
                inner.track(&connection, start);
                return Ok(connection);
            }
            if Instant::now() >= deadline {
                break;
            }
        }
        let (active, idle) = (lock(&inner.book).active.len(), inner.adapter.count());
        Err(Error::Exhausted(format!(
            "Pool '{}' could not provide a connection within {}s (size {}, active {active}, idle {idle})",
            inner.name,
            php_std::number::to_string(inner.timeout),
            inner.size,
        )))
    }

    /// Hands a connection back to the idle set without inspecting it.
    ///
    /// PHP: `push()`.
    pub fn push(&self, connection: &Connection<T>) -> &Self {
        self.inner.push(connection);
        self
    }

    /// Returns a connection for reuse.
    ///
    /// PHP: `reclaim($connection)`.
    pub fn reclaim(&self, connection: &Connection<T>) -> &Self {
        self.inner.push(connection);
        self
    }

    /// Returns every checked-out connection for reuse, even ones their
    /// holders still use.
    ///
    /// PHP: `reclaim()` without a connection.
    pub fn reclaim_all(&self) -> &Self {
        let active = lock(&self.inner.book).active.clone();
        for connection in &active {
            self.inner.push(connection);
        }
        self
    }

    /// Discards a connection and frees its capacity; the next
    /// [`Pool::pop`] creates a replacement. Destroying a connection twice,
    /// or one of another pool, frees nothing.
    ///
    /// PHP: `destroy($connection)`.
    pub fn destroy(&self, connection: &Connection<T>) -> &Self {
        self.inner.destroy(connection);
        self
    }

    /// Discards every checked-out connection.
    ///
    /// PHP: `destroy()` without a connection.
    pub fn destroy_all(&self) -> &Self {
        let active = lock(&self.inner.book).active.clone();
        for connection in &active {
            self.inner.destroy(connection);
        }
        self
    }

    /// Returns a connection after use: reclaimed when the work succeeded,
    /// otherwise reclaimed if [`Resource::recover`] makes it reusable and
    /// destroyed if not.
    ///
    /// PHP: `release($connection, $failed)`.
    pub async fn release(&self, connection: &Connection<T>, failed: bool) -> &Self {
        if !failed || connection.resource().recover().await {
            self.inner.push(connection);
        } else {
            self.inner.destroy(connection);
        }
        self
    }

    /// Runs `work` with a connection's resource, then returns the connection:
    /// reclaimed when `work` succeeds, released as failed when it returns an
    /// error. A failed [`Pool::pop`] is returned as `E`.
    ///
    /// PHP: `use(callable $callback)`.
    pub async fn with<R, E: From<Error>>(&self, work: impl AsyncFnOnce(&T) -> Result<R, E>) -> Result<R, E> {
        let connection = self.pop().await?;
        let result = work(connection.resource()).await;
        self.release(&connection, result.is_err()).await;
        result
    }

    /// Runs the upkeep of every idle resource ([`Resource::tick`]), one at a
    /// time. A resource whose tick fails is discarded and its capacity freed;
    /// checked-out resources are left alone.
    ///
    /// PHP: `maintain()`.
    pub async fn maintain(&self) -> &Self {
        let inner = &*self.inner;
        let mut taken = Vec::new();
        for _ in 0..inner.adapter.count() {
            match inner.adapter.pop(Duration::ZERO).await {
                Some(connection) => taken.push(connection),
                None => break,
            }
        }
        for connection in taken {
            if connection.resource().tick().await.is_ok() {
                inner.adapter.push(connection);
            } else {
                lock(&inner.book).reserved -= 1;
                inner.adapter.unblock();
            }
        }
        self
    }

    /// Connections a caller could still get: idle ones plus those not yet
    /// created.
    ///
    /// PHP: `count()`.
    pub fn count(&self) -> usize {
        let reserved = lock(&self.inner.book).reserved;
        let count = self.inner.adapter.count() as i64 + self.inner.size as i64 - reserved;
        usize::try_from(count).unwrap_or(0)
    }

    /// No connection can be had until one is handed back.
    ///
    /// PHP: `isEmpty()`.
    pub fn is_empty(&self) -> bool {
        self.count() == 0
    }

    /// Every connection the pool could hand out is available.
    ///
    /// PHP: `isFull()`.
    pub fn is_full(&self) -> bool {
        self.count() == self.inner.size
    }
}

impl<T: Resource, A: Adapter<T>> Inner<T, A> {
    fn track(&self, connection: &Connection<T>, requested: Instant) {
        self.adapter.synchronized(|| {
            // By id, as PHP keys them: a connection handed back twice and
            // taken again is tracked once.
            let mut book = lock(&self.book);
            if !book.active.iter().any(|c| c.same(connection)) {
                book.active.push(connection.clone());
            }
            match book.checked_out_at.iter_mut().find(|(id, _)| id == connection.id()) {
                Some((_, at)) => *at = requested,
                None => book.checked_out_at.push((connection.id().to_owned(), requested)),
            }
        });
    }

    /// Records how long a connection was out, once.
    fn record_use(&self, book: &mut Book<T>, id: &str) {
        if let Some(i) = book.checked_out_at.iter().position(|(c, _)| c == id) {
            let (_, at) = book.checked_out_at.swap_remove(i);
            let size = i64::try_from(self.size).unwrap_or(i64::MAX);
            self.use_time.record(
                at.elapsed().as_secs_f64(),
                &[("pool", Value::from(self.name.as_str())), ("size", Value::Int(size))],
            );
        }
    }

    fn push(&self, connection: &Connection<T>) {
        self.adapter.synchronized(|| {
            let mut book = lock(&self.book);
            self.record_use(&mut book, connection.id());
            if let Some(i) = book.active.iter().position(|c| c.same(connection)) {
                book.active.remove(i);
            }
            drop(book);
            self.adapter.push(connection.clone());
        });
    }

    fn destroy(&self, connection: &Connection<T>) {
        {
            let mut book = lock(&self.book);
            self.record_use(&mut book, connection.id());
            if let Some(i) = book.active.iter().position(|c| c.same(connection)) {
                book.active.remove(i);
                book.reserved -= 1;
            }
        }
        self.adapter.unblock();
    }
}

impl<T: Resource, A: Adapter<T>> Owner<T> for Inner<T, A> {
    fn reclaim(&self, connection: &Connection<T>) {
        self.push(connection);
    }

    fn destroy(&self, connection: &Connection<T>) {
        Inner::destroy(self, connection);
    }
}
