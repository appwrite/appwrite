use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use parking_lot::Mutex;
use utopia_telemetry::{Adapter as Telemetry, Attributes, Histogram, NoneAdapter, ObservableGauge};

use crate::adapter::Adapter;
use crate::error::{BoxError, PoolError};
use crate::{Connection, Recover};

type InitFn<T> = Arc<dyn Fn() -> Result<T, BoxError> + Send + Sync>;

pub(crate) struct Book<T> {
    pub(crate) reserved: usize,
    pub(crate) active: HashMap<String, Connection<T>>,
    pub(crate) checked_out_at: HashMap<String, Instant>,
}

pub(crate) struct PoolInner<T> {
    pub(crate) adapter: Arc<dyn Adapter<T>>,
    pub(crate) name: String,
    pub(crate) size: usize,
    pub(crate) timeout: f64,
    init: InitFn<T>,
    next_id: AtomicU64,
    pub(crate) book: Arc<Mutex<Book<T>>>,
    wait_duration: Arc<dyn Histogram>,
    use_duration: Arc<dyn Histogram>,
    pub(crate) telemetry_attributes: Attributes,
    _gauges: Vec<Arc<dyn ObservableGauge>>,
}

/// PHP `Utopia\Pools\Pool`.
///
/// Clone shares the same pool (Arc). Configuration is constructor-only.
pub struct Pool<T> {
    inner: Arc<PoolInner<T>>,
}

impl<T> Clone for Pool<T> {
    fn clone(&self) -> Self {
        Self {
            inner: Arc::clone(&self.inner),
        }
    }
}

impl<T> std::fmt::Debug for Pool<T> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Pool")
            .field("name", &self.inner.name)
            .field("size", &self.inner.size)
            .field("timeout", &self.inner.timeout)
            .finish_non_exhaustive()
    }
}

impl<T: Recover + Send + 'static> Pool<T> {
    /// PHP `new Pool($adapter, $name, $size, $init, $timeout)`.
    pub fn new(
        adapter: impl Adapter<T> + 'static,
        name: impl Into<String>,
        size: usize,
        init: impl Fn() -> T + Send + Sync + 'static,
        timeout: f64,
    ) -> Result<Self, PoolError> {
        Self::try_new(adapter, name, size, move || Ok(init()), timeout, None)
    }

    /// PHP constructor `telemetry:` argument.
    pub fn with_telemetry(
        adapter: impl Adapter<T> + 'static,
        name: impl Into<String>,
        size: usize,
        init: impl Fn() -> T + Send + Sync + 'static,
        timeout: f64,
        telemetry: Option<Arc<dyn Telemetry>>,
    ) -> Result<Self, PoolError> {
        Self::try_new(adapter, name, size, move || Ok(init()), timeout, telemetry)
    }

    /// `init` returns `Result` so PHP `throw` from the factory is `Err`.
    pub fn try_new(
        adapter: impl Adapter<T> + 'static,
        name: impl Into<String>,
        size: usize,
        init: impl Fn() -> Result<T, BoxError> + Send + Sync + 'static,
        timeout: f64,
        telemetry: Option<Arc<dyn Telemetry>>,
    ) -> Result<Self, PoolError> {
        let name = name.into();
        if size < 1 {
            return Err(PoolError::invalid_size(&name, size));
        }
        if timeout < 0.0 {
            return Err(PoolError::invalid_timeout(&name, timeout));
        }

        let adapter: Arc<dyn Adapter<T>> = Arc::new(adapter);
        adapter.initialize(size);

        let telemetry: Arc<dyn Telemetry> =
            telemetry.unwrap_or_else(|| Arc::new(NoneAdapter::new()));
        let mut advisory = HashMap::new();
        advisory.insert(
            "ExplicitBucketBoundaries".into(),
            "0.005,0.01,0.025,0.05,0.075,0.1,0.25,0.5,0.75,1,2.5,5,7.5,10".into(),
        );
        let wait_duration = telemetry.create_histogram(
            "pool.connection.wait_time",
            Some("s"),
            None,
            advisory.clone(),
        );
        let use_duration =
            telemetry.create_histogram("pool.connection.use_time", Some("s"), None, advisory);

        let mut telemetry_attributes = Attributes::new();
        telemetry_attributes.insert("pool".into(), name.clone());
        telemetry_attributes.insert("size".into(), size.to_string());

        let book = Arc::new(Mutex::new(Book {
            reserved: 0,
            active: HashMap::new(),
            checked_out_at: HashMap::new(),
        }));

        let gauges = vec![
            telemetry.create_observable_gauge(
                "pool.connection.active.count",
                None,
                None,
                HashMap::new(),
            ),
            telemetry.create_observable_gauge(
                "pool.connection.idle.count",
                None,
                None,
                HashMap::new(),
            ),
            telemetry.create_observable_gauge(
                "pool.connection.open.count",
                None,
                None,
                HashMap::new(),
            ),
            telemetry.create_observable_gauge(
                "pool.connection.capacity.count",
                None,
                None,
                HashMap::new(),
            ),
        ];
        for gauge in &gauges {
            gauge.observe(Box::new(|_observer| {}));
        }

        Ok(Self {
            inner: Arc::new(PoolInner {
                adapter,
                name,
                size,
                timeout,
                init: Arc::new(init),
                next_id: AtomicU64::new(1),
                book,
                wait_duration,
                use_duration,
                telemetry_attributes,
                _gauges: gauges,
            }),
        })
    }

    /// PHP `$pool->name`.
    #[must_use]
    pub fn name(&self) -> &str {
        &self.inner.name
    }

    /// PHP `$pool->size`.
    #[must_use]
    pub fn size(&self) -> usize {
        self.inner.size
    }

    /// PHP `$pool->timeout` (seconds).
    #[must_use]
    pub fn timeout(&self) -> f64 {
        self.inner.timeout
    }

    /// Blocking PHP `Pool::use()` for sync callers (`utopia-cache`, `utopia-queue`).
    ///
    /// Uses `block_in_place` when already on a multi-thread Tokio runtime; otherwise
    /// a thread-local current-thread runtime. Callback errors are returned as `R`
    /// and do **not** mark the connection failed (PHP `use()` only destroys on throw).
    pub fn use_sync<F, R>(&self, callback: F) -> Result<R, PoolError>
    where
        F: FnOnce(&mut T) -> R,
    {
        match tokio::runtime::Handle::try_current() {
            Ok(handle) => tokio::task::block_in_place(move || {
                handle.block_on(self.use_resource(move |resource| Ok(callback(resource))))
            }),
            Err(_) => tokio::runtime::Builder::new_current_thread()
                .enable_time()
                .build()
                .expect("blocking pool runtime")
                .block_on(self.use_resource(|resource| Ok(callback(resource)))),
        }
    }

    /// PHP `Pool::use()`.
    pub async fn use_resource<F, R>(&self, callback: F) -> Result<R, PoolError>
    where
        F: FnOnce(&mut T) -> Result<R, PoolError>,
    {
        let connection = self.pop().await?;
        let mut failed = false;
        let result = {
            let mut resource = connection.resource();
            match callback(&mut resource) {
                Ok(value) => Ok(value),
                Err(error) => {
                    failed = true;
                    Err(error)
                }
            }
        };
        self.release(&connection, failed);
        result
    }

    /// PHP `Pool::pop()`.
    pub async fn pop(&self) -> Result<Connection<T>, PoolError> {
        self.inner.clone().pop().await
    }

    /// PHP `Pool::push($connection)`.
    pub fn push(&self, connection: &Connection<T>) -> &Self {
        self.inner.push_one(connection);
        self
    }

    /// PHP `Pool::reclaim(?Connection $connection = null)`.
    pub fn reclaim(&self, connection: Option<&Connection<T>>) -> &Self {
        match connection {
            Some(connection) => self.inner.reclaim_one(connection),
            None => self.inner.reclaim_all(),
        }
        self
    }

    /// PHP `Pool::destroy(?Connection $connection = null)`.
    pub fn destroy(&self, connection: Option<&Connection<T>>) -> &Self {
        match connection {
            Some(connection) => self.inner.destroy_one(connection),
            None => self.inner.destroy_all(),
        }
        self
    }

    /// PHP `Pool::release($connection, $failed = false)`.
    pub fn release(&self, connection: &Connection<T>, failed: bool) -> &Self {
        self.inner.release(connection, failed);
        self
    }

    /// PHP `Pool::count()`.
    #[must_use]
    pub fn count(&self) -> usize {
        self.inner.count()
    }

    /// PHP `Pool::isEmpty()`.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.count() == 0
    }

    /// PHP `Pool::isFull()`.
    #[must_use]
    pub fn is_full(&self) -> bool {
        self.count() == self.inner.size
    }
}

impl<T: Recover + Send + 'static> PoolInner<T> {
    async fn pop(self: Arc<Self>) -> Result<Connection<T>, PoolError> {
        let start = Instant::now();
        let deadline = start + Duration::from_secs_f64(self.timeout.max(0.0));
        let result = self.pop_inner(start, deadline).await;
        self.wait_duration
            .record(start.elapsed().as_secs_f64(), &self.telemetry_attributes);
        result
    }

    async fn pop_inner(
        self: &Arc<Self>,
        start: Instant,
        deadline: Instant,
    ) -> Result<Connection<T>, PoolError> {
        let slot = Arc::new(AtomicBool::new(false));
        {
            let slot = Arc::clone(&slot);
            let book = Arc::clone(&self.book);
            let adapter = Arc::clone(&self.adapter);
            let size = self.size;
            self.adapter.synchronized(Box::new(move || {
                let mut book = book.lock();
                if adapter.count() == 0 && book.reserved < size {
                    book.reserved += 1;
                    slot.store(true, Ordering::SeqCst);
                }
            }))?;
        }

        if slot.load(Ordering::SeqCst) {
            match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| (self.init)())) {
                Ok(Ok(resource)) => {
                    let connection = self.make_connection(resource);
                    self.track(&connection, start);
                    return Ok(connection);
                }
                Ok(Err(error)) => {
                    self.release_reserved();
                    return Err(PoolError::Init(error));
                }
                Err(payload) => {
                    self.release_reserved();
                    std::panic::resume_unwind(payload);
                }
            }
        }

        let remaining = deadline.saturating_duration_since(Instant::now());
        if let Some(connection) = self.adapter.pop(remaining).await {
            self.track(&connection, start);
            return Ok(connection);
        }

        let active = self.book.lock().active.len();
        Err(PoolError::timeout_exhausted(
            &self.name,
            self.timeout,
            self.size,
            active,
            self.adapter.count(),
        ))
    }

    fn make_connection(self: &Arc<Self>, resource: T) -> Connection<T> {
        let seq = self.next_id.fetch_add(1, Ordering::Relaxed);
        let id = format!("{}-{seq:013x}", self.name);
        Connection::new(id, resource, Arc::downgrade(self))
    }

    fn track(&self, connection: &Connection<T>, requested_at: Instant) {
        let connection = connection.clone();
        let book = Arc::clone(&self.book);
        let _ = self.adapter.synchronized(Box::new(move || {
            let mut book = book.lock();
            book.checked_out_at
                .insert(connection.id.clone(), requested_at);
            book.active.insert(connection.id.clone(), connection);
        }));
    }

    fn record_use(&self, id: &str) {
        let mut book = self.book.lock();
        if let Some(started) = book.checked_out_at.remove(id) {
            self.use_duration
                .record(started.elapsed().as_secs_f64(), &self.telemetry_attributes);
        }
    }

    fn push_one(&self, connection: &Connection<T>) {
        self.record_use(&connection.id);
        self.adapter.push(connection.clone());
        self.book.lock().active.remove(&connection.id);
    }

    pub(crate) fn reclaim_one(&self, connection: &Connection<T>) {
        self.push_one(connection);
    }

    fn reclaim_all(&self) {
        let active: Vec<Connection<T>> = self.book.lock().active.values().cloned().collect();
        for connection in active {
            self.push_one(&connection);
        }
    }

    pub(crate) fn destroy_one(&self, connection: &Connection<T>) {
        self.record_use(&connection.id);
        let id = connection.id.clone();
        let book = Arc::clone(&self.book);
        let _ = self.adapter.synchronized(Box::new(move || {
            let mut book = book.lock();
            if book.active.remove(&id).is_some() {
                book.reserved = book.reserved.saturating_sub(1);
                book.checked_out_at.remove(&id);
            }
        }));
    }

    fn destroy_all(&self) {
        let active: Vec<Connection<T>> = self.book.lock().active.values().cloned().collect();
        for connection in active {
            self.destroy_one(&connection);
        }
    }

    fn release_reserved(&self) {
        let book = Arc::clone(&self.book);
        let _ = self.adapter.synchronized(Box::new(move || {
            let mut book = book.lock();
            book.reserved = book.reserved.saturating_sub(1);
        }));
    }

    fn forget(&self, connection: &Connection<T>) {
        let id = connection.id.clone();
        let book = Arc::clone(&self.book);
        let untrack_book = Arc::clone(&self.book);
        let untrack_id = id.clone();
        let result = self.adapter.synchronized(Box::new(move || {
            let mut book = book.lock();
            if book.active.remove(&id).is_some() {
                book.checked_out_at.remove(&id);
                book.reserved = book.reserved.saturating_sub(1);
            }
        }));
        if result.is_err() {
            let mut book = untrack_book.lock();
            if book.active.remove(&untrack_id).is_some() {
                book.checked_out_at.remove(&untrack_id);
                book.reserved = book.reserved.saturating_sub(1);
            }
        }
    }

    fn release(&self, connection: &Connection<T>, failed: bool) {
        if !failed {
            self.reclaim_one(connection);
            return;
        }

        if connection.resource().recover() {
            self.reclaim_one(connection);
            return;
        }

        self.record_use(&connection.id);
        let id = connection.id.clone();
        let book = Arc::clone(&self.book);
        let result = self.adapter.synchronized(Box::new(move || {
            let mut book = book.lock();
            if book.active.remove(&id).is_some() {
                book.reserved = book.reserved.saturating_sub(1);
            }
        }));
        if result.is_err() {
            self.forget(connection);
        }
    }

    fn count(&self) -> usize {
        let reserved = self.book.lock().reserved;
        self.adapter.count() + (self.size - reserved)
    }
}
