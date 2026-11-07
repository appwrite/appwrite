//! [`Pool`]: an adapter over a pool of adapters, and [`ReadWritePool`], a
//! pool that sends reads to replicas.
//!
//! Every call checks a connection (an adapter) out of a
//! [`utopia_pools::Pool`], puts it in this handle's settings (database,
//! namespace, shared tables, tenant, tenant per document, authorization,
//! locks, schemaless mode, timeouts, metadata, profiler, transforms, write
//! hooks), runs the call on it and hands it back: reclaimed when the call
//! succeeded, recovered (reconnected) or discarded when it failed. The
//! profiler is taken back from the connection after each checkout.
//!
//! A transaction ([`with_transaction`](super::with_transaction)) pins one
//! connection for the future running it (and the tasks it starts with
//! [`inherit`](crate::state::inherit)): every call that future makes runs
//! on that connection, under the caller's tenant, while other tasks
//! sharing the handle check connections of their own out and run outside
//! the transaction.
//!
//! What every connection of a pool answers alike is asked once per pool
//! and kept for every handle over it while the pool lives: the capabilities, each feature, and
//! [`Capability::DefinedAttributes`] per schema mode this handle set (a
//! connection with a schema mode of its own is asked every time while the
//! handle leaves the mode unset). The limits are kept per handle and
//! shared-tables mode. Timeouts are this handle's own state: setting one
//! opens no connection; each checkout is put in them.
//!
//! The synchronous methods of the contract (capabilities, limits, casting,
//! builders) check a connection out too: on a multi-threaded runtime they
//! wait for it in place; elsewhere they take one only when it is available
//! at once. [`Pool::warm`] answers the questions kept per pool ahead of the
//! first synchronous call.
//!
//! A [`ReadWritePool`] (`Pool::read_write`) sends reads to its read pool
//! and every other call to its write pool, and keeps reads on the write
//! pool for a while after a write (sticky reads), inside a transaction, and
//! for reads that decide a write (`getDocument` for update, `find` for a
//! permission other than read).
//!
//! PHP: `Utopia\Database\Adapter\Pool`, `Utopia\Database\Adapter\ReadWritePool`.

use std::any::Any;
use std::collections::HashMap;
use std::future::Future;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};
use std::time::Instant;

use async_trait::async_trait;
use futures_util::FutureExt;
use futures_util::future::BoxFuture;
use utopia_pools::{Connection as Lent, Resource, Stack};
use utopia_query::schema::{ColumnType, IndexType};

use super::{
    Adapter, Casting, Connection, Feature, Features, Find, Limits, QueryBuilder, RawQuery, Relationships, Schemaless,
    Spatial, SqlSchema, State, TimeoutState, Timeouts, TransactionBody, Upserts,
};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::index::Index;
use crate::permission::PermissionType;
use crate::query::Query;
use crate::relationship::{Relationship, RelationshipSide, Update};
use crate::state::Scoped;
use crate::value::Value;

/// A pooled adapter: the resource of the [`utopia_pools::Pool`] a [`Pool`]
/// draws from.
#[derive(Clone)]
pub struct Pooled(pub Arc<dyn Adapter>);

impl std::fmt::Debug for Pooled {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_tuple("Pooled").finish_non_exhaustive()
    }
}

impl Pooled {
    /// Wraps an adapter.
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Pooled(Arc::new(adapter))
    }
}

impl Resource for Pooled {
    /// After failed work: reconnects an adapter with a connection
    /// ([`Connection::reconnect`]); one without cannot recover and is
    /// discarded (PHP's pool probes the resource for `reset()` and
    /// `reconnect()`).
    async fn recover(&self) -> bool {
        match self.0.connection() {
            Some(connection) => connection.reconnect().await.is_ok(),
            None => false,
        }
    }
}

/// The calls a [`ReadWritePool`] sends to its read pool
/// (`ReadWritePool::READ_METHODS`).
pub const READ_METHODS: [&str; 13] = [
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
];

/// The calls that neither read nor write data: a [`ReadWritePool`] sends
/// them where a read would go, without opening the sticky window
/// (`ReadWritePool::METADATA_METHODS`).
pub const METADATA_METHODS: [&str; 18] = [
    "supports",
    "capabilities",
    "hasFeature",
    "limits",
    "isSchemaless",
    "setSchemaless",
    "builder",
    "schema",
    "getColumnType",
    "getSchemaIndexType",
    "encode",
    "decode",
    "castBefore",
    "castAfter",
    "castDatetime",
    "getAttributeWidth",
    "getCountOfAttributes",
    "getCountOfIndexes",
];

/// The metadata a [`ReadWritePool`] always asks its write pool
/// (`ReadWritePool::WRITE_POOL_METADATA_METHODS`): the hostname names the
/// cache keys, so it must not change with the pool a read goes to.
pub const WRITE_POOL_METADATA_METHODS: [&str; 1] = ["hostname"];

/// The schema mode a handle left unset (`Pool::FIXED_SCHEMA`).
const FIXED_SCHEMA: i8 = -1;

/// What every connection of one pool answers alike, kept for every handle
/// over it.
#[derive(Default)]
struct Answers {
    capabilities: Mutex<Option<Capabilities>>,
    features: Mutex<Vec<(Feature, bool)>>,
    defined_attributes: Mutex<Vec<(i8, bool)>>,
}

/// The answers of each live pool (PHP's `WeakMap` keyed by the pool).
static ANSWERS: Mutex<Vec<Registered>> = Mutex::new(Vec::new());

/// Whether a pool (as `Any`) is the registered one.
type IsPool = dyn Fn(&dyn Any) -> bool + Send;

/// A synchronous call on an adapter.
type SyncCall<'a, R> = Box<dyn FnOnce(&dyn Adapter) -> Result<R> + 'a>;

/// A pool's answers, with whether the pool still exists and whether a
/// pool is it.
struct Registered {
    alive: Box<dyn Fn() -> bool + Send>,
    is: Box<IsPool>,
    answers: Arc<Answers>,
}

fn answers_of<S: utopia_pools::Adapter<Pooled>>(pool: &utopia_pools::Pool<Pooled, S>) -> Arc<Answers> {
    let mut registry = lock(&ANSWERS);
    registry.retain(|r| (r.alive)());
    if let Some(r) = registry.iter().find(|r| (r.is)(pool as &dyn Any)) {
        return r.answers.clone();
    }
    let answers = Arc::new(Answers::default());
    let (alive, is) = (pool.liveness(), pool.liveness());
    registry.push(Registered {
        alive: Box::new(move || alive.is_alive()),
        is: Box::new(move |other| {
            other.downcast_ref::<utopia_pools::Pool<Pooled, S>>().is_some_and(|other| is.is_of(other))
        }),
        answers: answers.clone(),
    });
    answers
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// A pool failure as a database error: PHP lets the pool's exception
/// through (`Exception`, or `InvalidArgumentException`).
fn pool_error(error: utopia_pools::Error) -> Error {
    match error {
        utopia_pools::Error::Init(cause) => match cause.downcast::<Error>() {
            Ok(error) => *error,
            Err(cause) => Error::new(Kind::Exception, cause.to_string()),
        },
        utopia_pools::Error::InvalidArgument(message) => Error::new(Kind::InvalidArgument, message),
        other => Error::new(Kind::Exception, other.to_string()),
    }
}

/// The message of a call to a feature the pooled adapter lacks
/// (`unsupportedFeatureMessage()`).
pub fn unsupported_feature_message(feature: Feature) -> String {
    match feature {
        Feature::Upserts => "Adapter does not support upserts".to_owned(),
        Feature::RawQuery => "Adapter does not support raw queries".to_owned(),
        Feature::QueryBuilder => "Adapter does not support query builder".to_owned(),
        Feature::Spatial => "Adapter does not support spatial".to_owned(),
        Feature::Casting => "Adapter does not support casting".to_owned(),
        Feature::Connection => "Adapter does not support connections".to_owned(),
        Feature::Relationships => "Adapter does not support relationships".to_owned(),
        Feature::Timeouts => "Adapter does not support timeouts".to_owned(),
        Feature::Schemaless => "Adapter does not support schemaless".to_owned(),
    }
}

/// The feature methods of the adapter, or the error naming the feature.
fn feature<T: ?Sized>(methods: Option<&T>, feature: Feature) -> Result<&T> {
    methods.ok_or_else(|| Error::database(unsupported_feature_message(feature)))
}

/// Runs `future` to its end without awaiting: in place on a multi-threaded
/// runtime, else only when it is ready at once.
fn block<F: Future>(future: F) -> Option<F::Output> {
    match utopia_async::Handle::current() {
        Some(handle) if handle.flavor() == utopia_async::Flavor::MultiThread => {
            Some(utopia_async::block_in_place(|| handle.block_on(future)))
        }
        _ => future.now_or_never(),
    }
}

/// A connection checked out for one call: destroyed (its capacity freed)
/// when the call's future is dropped before it ends.
struct Checkout(Option<Lent<Pooled>>);

impl Checkout {
    fn done(mut self) {
        self.0 = None;
    }
}

impl Drop for Checkout {
    fn drop(&mut self) {
        if let Some(connection) = self.0.take() {
            connection.destroy();
        }
    }
}

/// The read side of a [`ReadWritePool`].
struct Replica<S> {
    read: utopia_pools::Pool<Pooled, S>,
    sticky: AtomicBool,
    sticky_ms: AtomicI64,
    last_write: Mutex<Option<Instant>>,
    write_hostname: Mutex<Option<String>>,
    /// Where each task's latest read was served (`Coroutine::getContext()`);
    /// `None` is a call outside a task (`$replicaRead`).
    replica_reads: Mutex<HashMap<Option<utopia_async::task::Id>, bool>>,
}

/// The most tasks whose last read a [`ReadWritePool`] remembers.
const REPLICA_READS: usize = 4096;

impl<S> Replica<S> {
    fn stick(&self) {
        *lock(&self.last_write) = Some(Instant::now());
    }

    fn is_sticky(&self) -> bool {
        if !self.sticky.load(Ordering::Relaxed) {
            return false;
        }
        match *lock(&self.last_write) {
            None => false,
            Some(at) => (at.elapsed().as_secs_f64() * 1000.0) < self.sticky_ms.load(Ordering::Relaxed) as f64,
        }
    }

    fn record(&self, method: &str, replica: bool) {
        if !READ_METHODS.contains(&method) {
            return;
        }
        let mut reads = lock(&self.replica_reads);
        if reads.len() >= REPLICA_READS {
            reads.clear();
        }
        reads.insert(utopia_async::task::id(), replica);
    }
}

/// An adapter over a pool of adapters. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Pool`.
pub struct Pool<S = Stack<Pooled>> {
    state: State,
    pool: utopia_pools::Pool<Pooled, S>,
    /// The connection the current future's transaction runs on (`$pinned`).
    pinned: Scoped<Option<Arc<dyn Adapter>>>,
    /// The schema mode this handle puts every borrowed adapter in
    /// (`$schemaless`).
    schemaless: Mutex<Option<bool>>,
    timeouts: TimeoutState,
    answers: Arc<Answers>,
    replica: Option<Replica<S>>,
    /// The last connection this handle checked out: the limits come from
    /// it when no connection can be had.
    last: Mutex<Option<Arc<dyn Adapter>>>,
}

/// A pool that sends reads to replicas (`Pool::read_write`).
///
/// PHP: `Utopia\Database\Adapter\ReadWritePool`.
pub type ReadWritePool<S = Stack<Pooled>> = Pool<S>;

impl<S> std::fmt::Debug for Pool<S> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pool").field("pool", &self.pool).field("read_write", &self.replica.is_some()).finish()
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Pool<S> {
    /// A pooled adapter drawing its connections from `pool`. Building it
    /// opens no connection.
    ///
    /// PHP: `new Pool($pool)`.
    pub fn new(pool: utopia_pools::Pool<Pooled, S>) -> Self {
        let answers = answers_of(&pool);
        Pool {
            state: State::default(),
            pool,
            pinned: Scoped::new(None),
            schemaless: Mutex::new(None),
            timeouts: TimeoutState::default(),
            answers,
            replica: None,
            last: Mutex::new(None),
        }
    }

    /// A pool sending reads to `read` and every other call to `write`, with
    /// sticky reads for 5 seconds after a write.
    ///
    /// PHP: `new ReadWritePool($writePool, $readPool)`.
    pub fn read_write(write: utopia_pools::Pool<Pooled, S>, read: utopia_pools::Pool<Pooled, S>) -> Self {
        let mut pool = Pool::new(write);
        pool.replica = Some(Replica {
            read,
            sticky: AtomicBool::new(true),
            sticky_ms: AtomicI64::new(5000),
            last_write: Mutex::new(None),
            write_hostname: Mutex::new(None),
            replica_reads: Mutex::new(HashMap::new()),
        });
        pool
    }

    /// The pool the connections come from (`getPool()`; the write pool of
    /// a [`ReadWritePool`]).
    pub fn pool(&self) -> &utopia_pools::Pool<Pooled, S> {
        &self.pool
    }

    /// How long reads stay on the write pool after a write
    /// (`ReadWritePool::setStickyDuration()`; no effect on a plain pool).
    pub fn set_sticky_duration(&self, milliseconds: i64) -> &Self {
        if let Some(replica) = &self.replica {
            replica.sticky_ms.store(milliseconds, Ordering::Relaxed);
        }
        self
    }

    /// Whether reads stay on the write pool after a write
    /// (`ReadWritePool::setSticky()`; no effect on a plain pool).
    pub fn set_sticky(&self, sticky: bool) -> &Self {
        if let Some(replica) = &self.replica {
            replica.sticky.store(sticky, Ordering::Relaxed);
        }
        self
    }

    /// Answers ahead what every connection answers alike (capabilities,
    /// features, limits), so synchronous calls need no connection for them.
    ///
    /// Not in PHP, whose synchronous calls check a connection out.
    pub async fn warm(&self) -> Result<()> {
        let (capabilities, features, limits) = self
            .delegate("capabilities", false, |a| async move {
                let features: Vec<(Feature, bool)> = Feature::ALL.iter().map(|f| (*f, a.has_feature(*f))).collect();
                Ok((a.capabilities(), features, a.limits()))
            })
            .await?;
        *lock(&self.answers.capabilities) = Some(capabilities);
        {
            let mut known = lock(&self.answers.features);
            for (f, present) in features {
                if !known.iter().any(|(k, _)| *k == f) {
                    known.push((f, present));
                }
            }
        }
        self.state.lock().limits.get_or_insert(limits);
        Ok(())
    }

    /// The connection the current future's transaction pinned, if any
    /// (`pin()`).
    pub fn pinned_adapter(&self) -> Option<Arc<dyn Adapter>> {
        self.pin()
    }

    fn pin(&self) -> Option<Arc<dyn Adapter>> {
        self.pinned.get()
    }

    /// Puts a connection in this handle's settings (`syncBorrowed()`).
    fn sync_borrowed(&self, adapter: &dyn Adapter) -> Result<()> {
        let s = self.state.lock().clone();
        adapter.set_database(&s.database);
        adapter.set_namespace(&s.namespace);
        adapter.set_shared_tables(s.shared_tables);
        adapter.set_tenant(self.tenant());
        adapter.set_tenant_per_document(s.tenant_per_document);
        adapter.set_authorization(s.authorization.clone());
        adapter.set_locks(s.locks);
        let mode = *lock(&self.schemaless);
        if let Some(mode) = mode
            && let Some(schemaless) = adapter.schemaless()
        {
            schemaless.set_schemaless(mode);
        }
        self.sync_timeouts(adapter)?;
        let current = adapter.metadata();
        let same = current.len() == s.metadata.len()
            && current.iter().zip(&s.metadata).all(|((ka, va), (kb, vb))| ka == kb && va.strict_eq(vb));
        if !same {
            adapter.reset_metadata();
            for (key, value) in &s.metadata {
                adapter.set_metadata(key, value.clone());
            }
        }
        let same_profiler = match (adapter.profiler(), &s.profiler) {
            (None, None) => true,
            (Some(a), Some(b)) => Arc::ptr_eq(&a, b),
            _ => false,
        };
        if !same_profiler {
            adapter.set_profiler(s.profiler.clone());
        }
        let transforms = adapter.state().lock().transforms.clone();
        let same_transforms = transforms.len() == s.transforms.len()
            && transforms.iter().zip(&s.transforms).all(|((na, ta), (nb, tb))| na == nb && Arc::ptr_eq(ta, tb));
        if !same_transforms {
            adapter.reset_transforms();
            for (name, transform) in &s.transforms {
                adapter.add_transform(name, transform.clone());
            }
        }
        let hooks = adapter.write_hooks();
        let same_hooks =
            hooks.len() == s.write_hooks.len() && hooks.iter().zip(&s.write_hooks).all(|(a, b)| Arc::ptr_eq(a, b));
        if !same_hooks {
            for hook in &hooks {
                adapter.remove_write_hook(hook);
            }
            for hook in &s.write_hooks {
                adapter.add_write_hook(hook.clone());
            }
        }
        Ok(())
    }

    /// Takes back what [`Pool::sync_borrowed`] lent the connection
    /// (`releaseBorrowed()`): the profiler.
    fn release_borrowed(&self, adapter: &dyn Adapter) {
        adapter.set_profiler(None);
    }

    /// Puts a connection in this handle's timeouts: the per-event ones
    /// first, the global one last (`syncTimeouts()`).
    ///
    /// Fails ([`Kind::Database`]) when the handle holds a timeout and the
    /// connection has none (`Adapter does not support timeouts`).
    fn sync_timeouts(&self, adapter: &dyn Adapter) -> Result<()> {
        let entries = self.timeouts.entries();
        let Some(timeouts) = adapter.timeouts() else {
            if !entries.is_empty() {
                return Err(Error::database(unsupported_feature_message(Feature::Timeouts)));
            }
            return Ok(());
        };
        if entries.is_empty() {
            timeouts.clear_timeout(Event::All);
            return Ok(());
        }
        if entries.len() == 1 && entries[0].0 == Event::All {
            return timeouts.set_timeout(entries[0].1, Event::All);
        }
        timeouts.clear_timeout(Event::All);
        for (event, milliseconds) in &entries {
            if *event != Event::All {
                timeouts.set_timeout(*milliseconds, *event)?;
            }
        }
        if let Some((_, milliseconds)) = entries.iter().find(|(e, _)| *e == Event::All) {
            timeouts.set_timeout(*milliseconds, Event::All)?;
        }
        Ok(())
    }

    /// Whether the call is a write for a [`ReadWritePool`] (`isWrite()`).
    fn is_write(method: &str, decides_write: bool) -> bool {
        decides_write
            || !(READ_METHODS.contains(&method)
                || METADATA_METHODS.contains(&method)
                || WRITE_POOL_METADATA_METHODS.contains(&method))
    }

    /// Runs `f` on a connection (`delegate()` / `borrowAndInvoke()`): the
    /// pinned one, else one checked out of the pool the call goes to.
    async fn delegate<R, F, Fut>(&self, method: &'static str, decides_write: bool, f: F) -> Result<R>
    where
        F: FnOnce(Arc<dyn Adapter>) -> Fut + Send,
        Fut: Future<Output = Result<R>> + Send,
        R: Send,
    {
        let Some(replica) = &self.replica else {
            return self.borrow_and_invoke(f).await;
        };
        if Self::is_write(method, decides_write) {
            replica.record(method, false);
            let result = self.borrow_and_invoke(f).await;
            replica.stick();
            return result;
        }
        if self.pin().is_some() || replica.is_sticky() || WRITE_POOL_METADATA_METHODS.contains(&method) {
            replica.record(method, false);
            return self.borrow_and_invoke(f).await;
        }
        replica.record(method, true);
        self.checkout(&replica.read, f).await
    }

    /// `Pool::borrowAndInvoke()`: the pinned connection under the caller's
    /// tenant, else one of the write pool.
    async fn borrow_and_invoke<R, F, Fut>(&self, f: F) -> Result<R>
    where
        F: FnOnce(Arc<dyn Adapter>) -> Fut + Send,
        Fut: Future<Output = Result<R>> + Send,
        R: Send,
    {
        if let Some(pinned) = self.pin() {
            self.sync_borrowed(&*pinned)?;
            let tenant = self.tenant();
            let call = self.invoke(pinned.clone(), f);
            return pinned.state().tenant().with(tenant, call).await;
        }
        self.checkout(&self.pool, f).await
    }

    /// Runs `f` on a connection of `pool`, handed back after
    /// (`$pool->use()`).
    async fn checkout<R, F, Fut>(&self, pool: &utopia_pools::Pool<Pooled, S>, f: F) -> Result<R>
    where
        F: FnOnce(Arc<dyn Adapter>) -> Fut + Send,
        Fut: Future<Output = Result<R>> + Send,
        R: Send,
    {
        let connection = pool.pop().await.map_err(pool_error)?;
        let checkout = Checkout(Some(connection.clone()));
        let adapter = connection.resource().0.clone();
        *lock(&self.last) = Some(adapter.clone());
        let result = match self.sync_borrowed(&*adapter) {
            Ok(()) => self.invoke(adapter.clone(), f).await,
            Err(e) => Err(e),
        };
        self.release_borrowed(&*adapter);
        pool.release(&connection, result.is_err()).await;
        checkout.done();
        result
    }

    /// `invokeDelegated()`: under `ignoreDuplicates()` when this handle is.
    async fn invoke<R, F, Fut>(&self, adapter: Arc<dyn Adapter>, f: F) -> Result<R>
    where
        F: FnOnce(Arc<dyn Adapter>) -> Fut + Send,
        Fut: Future<Output = Result<R>> + Send,
        R: Send,
    {
        if self.state.ignoring_duplicates().get() {
            let flag = adapter.clone();
            return flag.state().ignoring_duplicates().with(true, f(adapter)).await;
        }
        f(adapter).await
    }

    /// [`Pool::delegate`] for the synchronous calls.
    fn delegate_sync<R>(&self, method: &'static str, f: impl FnOnce(&dyn Adapter) -> Result<R>) -> Result<R> {
        let pinned = self.pin();
        let read = match &self.replica {
            None => false,
            Some(replica) => {
                if Self::is_write(method, false) {
                    replica.record(method, false);
                    let result = self.borrow_sync(pinned, &self.pool, f);
                    replica.stick();
                    return result;
                }
                let read = !(pinned.is_some() || replica.is_sticky() || WRITE_POOL_METADATA_METHODS.contains(&method));
                replica.record(method, read);
                read
            }
        };
        match (&self.replica, read) {
            (Some(replica), true) => self.borrow_sync(None, &replica.read, f),
            _ => self.borrow_sync(pinned, &self.pool, f),
        }
    }

    fn borrow_sync<R>(
        &self,
        pinned: Option<Arc<dyn Adapter>>,
        pool: &utopia_pools::Pool<Pooled, S>,
        f: impl FnOnce(&dyn Adapter) -> Result<R>,
    ) -> Result<R> {
        let ignoring = self.state.ignoring_duplicates().get();
        let invoke = |adapter: &dyn Adapter, f: SyncCall<'_, R>| {
            if ignoring { adapter.state().ignoring_duplicates().with_sync(true, || f(adapter)) } else { f(adapter) }
        };
        if let Some(pinned) = pinned {
            self.sync_borrowed(&*pinned)?;
            let tenant = self.tenant();
            return pinned.state().tenant().with_sync(tenant, || invoke(&*pinned, Box::new(f)));
        }
        let connection = match block(pool.pop()) {
            Some(Ok(connection)) => connection,
            Some(Err(e)) => return Err(pool_error(e)),
            None => {
                return Err(Error::new(
                    Kind::Exception,
                    format!("Pool '{}' could not provide a connection without waiting", pool.name()),
                ));
            }
        };
        let checkout = Checkout(Some(connection.clone()));
        let adapter = connection.resource().0.clone();
        *lock(&self.last) = Some(adapter.clone());
        let result = self.sync_borrowed(&*adapter).and_then(|()| invoke(&*adapter, Box::new(f)));
        self.release_borrowed(&*adapter);
        if result.is_ok() {
            pool.reclaim(&connection);
        } else if block(pool.release(&connection, true)).is_none() {
            pool.destroy(&connection);
        }
        checkout.done();
        result
    }

    /// `supports(DefinedAttributes)`: asked every time while the handle
    /// leaves the schema mode unset over connections with a mode of their
    /// own; otherwise once per pool and mode.
    fn supports_defined_attributes(&self) -> bool {
        let mode = *lock(&self.schemaless);
        if mode.is_none() && self.has_feature(Feature::Schemaless) {
            return self.delegate_sync("supports", |a| Ok(a.supports(Capability::DefinedAttributes))).unwrap_or(false);
        }
        let mode = mode.map_or(FIXED_SCHEMA, i8::from);
        if let Some((_, known)) = lock(&self.answers.defined_attributes).iter().find(|(m, _)| *m == mode) {
            return *known;
        }
        match self.delegate_sync("supports", |a| Ok(a.supports(Capability::DefinedAttributes))) {
            Ok(answer) => {
                lock(&self.answers.defined_attributes).push((mode, answer));
                answer
            }
            Err(_) => false,
        }
    }

    /// The hostname of the connections, unless this handle was given one
    /// (`Pool::hostname()`).
    async fn pool_hostname(&self) -> Result<String> {
        let hostname = self.state.lock().hostname.clone();
        if !hostname.is_empty() {
            return Ok(hostname);
        }
        self.delegate(
            "hostname",
            false,
            |a| async move { feature(a.connection(), Feature::Connection)?.hostname().await },
        )
        .await
    }
}

/// A call delegated to the connection: `$this->delegate(__FUNCTION__, func_get_args())`.
macro_rules! delegate {
    ($self:ident, $method:literal, |$a:ident| $body:expr) => {
        $self.delegate($method, false, move |$a: Arc<dyn Adapter>| async move { $body }).await
    };
    ($self:ident, $method:literal, write: $write:expr, |$a:ident| $body:expr) => {
        $self.delegate($method, $write, move |$a: Arc<dyn Adapter>| async move { $body }).await
    };
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> Adapter for Pool<S> {
    fn state(&self) -> &State {
        &self.state
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        if let Some(known) = *lock(&self.answers.capabilities) {
            return known;
        }
        match self.delegate_sync("capabilities", |a| Ok(a.capabilities())) {
            Ok(capabilities) => {
                *lock(&self.answers.capabilities) = Some(capabilities);
                capabilities
            }
            Err(_) => Capabilities::EMPTY,
        }
    }

    fn supports(&self, capability: Capability) -> bool {
        if capability == Capability::DefinedAttributes {
            return self.supports_defined_attributes();
        }
        self.capabilities().contains(capability)
    }

    fn limits(&self) -> Arc<Limits> {
        if let Some(limits) = self.state.lock().limits.clone() {
            return limits;
        }
        match self.delegate_sync("limits", |a| Ok(a.limits())) {
            Ok(limits) => {
                self.state.lock().limits = Some(limits.clone());
                limits
            }
            Err(e) => match lock(&self.last).clone() {
                Some(last) => last.limits(),
                None => panic!("the pool could not answer its limits: {e}"),
            },
        }
    }

    fn features(&self) -> Features {
        Feature::ALL.into_iter().filter(|f| self.has_feature(*f)).collect()
    }

    fn has_feature(&self, feature: Feature) -> bool {
        if feature == Feature::Timeouts {
            return true;
        }
        if let Some((_, known)) = lock(&self.answers.features).iter().find(|(f, _)| *f == feature) {
            return *known;
        }
        match self.delegate_sync("hasFeature", |a| Ok(a.has_feature(feature))) {
            Ok(answer) => {
                lock(&self.answers.features).push((feature, answer));
                answer
            }
            Err(_) => false,
        }
    }

    fn casting(&self) -> Option<&dyn Casting> {
        self.has_feature(Feature::Casting).then_some(self as &dyn Casting)
    }

    fn connection(&self) -> Option<&dyn Connection> {
        self.has_feature(Feature::Connection).then_some(self as &dyn Connection)
    }

    fn query_builder(&self) -> Option<&dyn QueryBuilder> {
        self.has_feature(Feature::QueryBuilder).then_some(self as &dyn QueryBuilder)
    }

    fn raw_query(&self) -> Option<&dyn RawQuery> {
        self.has_feature(Feature::RawQuery).then_some(self as &dyn RawQuery)
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        self.has_feature(Feature::Relationships).then_some(self as &dyn Relationships)
    }

    fn schemaless(&self) -> Option<&dyn Schemaless> {
        self.has_feature(Feature::Schemaless).then_some(self as &dyn Schemaless)
    }

    fn spatial(&self) -> Option<&dyn Spatial> {
        self.has_feature(Feature::Spatial).then_some(self as &dyn Spatial)
    }

    fn timeouts(&self) -> Option<&dyn Timeouts> {
        Some(self)
    }

    fn upserts(&self) -> Option<&dyn Upserts> {
        self.has_feature(Feature::Upserts).then_some(self as &dyn Upserts)
    }

    /// `ReadWritePool::setHostname()` forgets the write pool's hostname.
    fn set_hostname(&self, hostname: &str) {
        if let Some(replica) = &self.replica {
            *lock(&replica.write_hostname) = None;
        }
        self.state.lock().hostname = hostname.to_owned();
    }

    async fn start_transaction(&self) -> Result<bool> {
        delegate!(self, "startTransaction", |a| a.start_transaction().await)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        delegate!(self, "commitTransaction", |a| a.commit_transaction().await)
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        delegate!(self, "rollbackTransaction", |a| a.rollback_transaction().await)
    }

    fn in_transaction(&self) -> bool {
        match self.pin() {
            Some(pinned) => pinned.in_transaction(),
            None => self.state.lock().in_transaction > 0,
        }
    }

    fn is_retryable(&self, failure: &Error) -> bool {
        self.delegate_sync("isRetryable", |a| Ok(a.is_retryable(failure))).unwrap_or(false)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        delegate!(self, "create", |a| a.create(name).await)
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        delegate!(self, "update", |a| a.update(name, new).await)
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        delegate!(self, "exists", |a| a.exists(database).await)
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        delegate!(self, "collectionExists", |a| a.collection_exists(database, collection).await)
    }

    async fn list(&self) -> Result<Vec<Document>> {
        delegate!(self, "list", |a| a.list().await)
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        delegate!(self, "delete", |a| a.delete(name).await)
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        delegate!(self, "createCollection", |a| a.create_collection(collection, attributes, indexes).await)
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        delegate!(self, "deleteCollection", |a| a.delete_collection(collection).await)
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        delegate!(self, "analyzeCollection", |a| a.analyze_collection(collection).await)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        delegate!(self, "getSizeOfCollection", |a| a.size_of_collection(collection).await)
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        delegate!(self, "getSizeOfCollectionOnDisk", |a| a.size_of_collection_on_disk(collection).await)
    }

    fn attribute_width(&self, collection: &Collection) -> Result<i64> {
        self.delegate_sync("getAttributeWidth", |a| a.attribute_width(collection))
    }

    fn count_of_attributes(&self, collection: &Collection) -> Result<i64> {
        self.delegate_sync("getCountOfAttributes", |a| a.count_of_attributes(collection))
    }

    fn count_of_indexes(&self, collection: &Collection) -> Result<i64> {
        self.delegate_sync("getCountOfIndexes", |a| a.count_of_indexes(collection))
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        delegate!(self, "createAttribute", |a| a.create_attribute(collection, attribute).await)
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        delegate!(self, "createAttributes", |a| a.create_attributes(collection, attributes).await)
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        delegate!(self, "updateAttribute", |a| a.update_attribute(collection, key, attribute).await)
    }

    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        delegate!(self, "relaxAttributeRequired", |a| a.relax_attribute_required(collection, id).await)
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        delegate!(self, "deleteAttribute", |a| a.delete_attribute(collection, key).await)
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self, "renameAttribute", |a| a.rename_attribute(collection, old, new).await)
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<crate::schema::Column>> {
        delegate!(self, "getSchemaAttributes", |a| a.schema_attributes(collection).await)
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<crate::schema::Index>> {
        delegate!(self, "getSchemaIndexes", |a| a.schema_indexes(collection).await)
    }

    fn schema_index_type(&self, kind: IndexType) -> IndexType {
        self.delegate_sync("getSchemaIndexType", |a| Ok(a.schema_index_type(kind))).unwrap_or(kind)
    }

    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        self.delegate_sync("getColumnType", |a| a.column_type(attribute))
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        delegate!(self, "createIndex", |a| a.create_index(collection, index, attribute_types, collation).await)
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        delegate!(self, "deleteIndex", |a| a.delete_index(collection, key).await)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self, "renameIndex", |a| a.rename_index(collection, old, new).await)
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        delegate!(self, "createDocument", |a| a.create_document(collection, document).await)
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        delegate!(self, "createDocuments", |a| a.create_documents(collection, documents).await)
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        delegate!(self, "getDocument", write: for_update, |a| a.get_document(collection, id, queries, for_update).await)
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        delegate!(self, "updateDocument", |a| a.update_document(collection, id, document, skip_permissions).await)
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        delegate!(self, "updateDocuments", |a| a
            .update_documents(collection, updates, documents, skip_permissions)
            .await)
    }

    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        delegate!(self, "increaseDocumentAttribute", |a| a
            .increase_document_attribute(collection, id, attribute, value, updated_at, min, max)
            .await)
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        delegate!(self, "deleteDocument", |a| a.delete_document(collection, id).await)
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        delegate!(self, "deleteDocuments", |a| a.delete_documents(collection, sequences, permission_ids).await)
    }

    /// Hands the pooled adapter the filters through its own
    /// `deleteDocumentsMatching()`.
    async fn delete_documents_matching(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
        queries: Vec<Query>,
    ) -> Result<i64> {
        if queries.is_empty() {
            return self.delete_documents(collection, sequences, permission_ids).await;
        }
        delegate!(self, "deleteDocumentsMatching", |a| a
            .delete_documents_matching(collection, sequences, permission_ids, queries)
            .await)
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        let decides_write = options.for_permission != PermissionType::Read;
        delegate!(self, "find", write: decides_write, |a| a.find(collection, queries, options).await)
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        delegate!(self, "count", |a| a.count(collection, queries, max).await)
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        delegate!(self, "sum", |a| a.sum(collection, attribute, queries, max).await)
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        delegate!(self, "getSequences", |a| a.get_sequences(collection, documents).await)
    }

    /// `getReadConcurrency()`: one inside a transaction, else the
    /// connections the read pool can still give but one.
    fn read_concurrency(&self) -> usize {
        if self.pin().is_some() {
            return 1;
        }
        let pool = match &self.replica {
            Some(replica) if !replica.is_sticky() => &replica.read,
            _ => &self.pool,
        };
        pool.count().saturating_sub(1).max(1)
    }

    /// `ReadWritePool::servedByReplica()`: whether the current task's
    /// latest read went to the read pool.
    fn served_by_replica(&self) -> bool {
        match &self.replica {
            Some(replica) => lock(&replica.replica_reads).get(&utopia_async::task::id()).copied().unwrap_or(false),
            None => false,
        }
    }

    /// `withTransaction()`: on the pinned connection when there is one, else
    /// on a connection of the write pool pinned for the transaction.
    fn transaction_scope<'a>(
        &'a self,
        body: TransactionBody<'a>,
    ) -> std::result::Result<BoxFuture<'a, Result<()>>, TransactionBody<'a>> {
        Ok(Box::pin(async move {
            let result = self.transaction(body).await;
            if let Some(replica) = &self.replica {
                replica.stick();
            }
            result
        }))
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Pool<S> {
    async fn transaction(&self, body: TransactionBody<'_>) -> Result<()> {
        if let Some(pinned) = self.pin() {
            body(pinned).await;
            return Ok(());
        }
        let connection = self.pool.pop().await.map_err(pool_error)?;
        let checkout = Checkout(Some(connection.clone()));
        let adapter = connection.resource().0.clone();
        *lock(&self.last) = Some(adapter.clone());
        // The body's own failure reaches the caller through `with_transaction`;
        // here it only decides how the connection is handed back.
        let (succeeded, refused) = match self.sync_borrowed(&*adapter) {
            Ok(()) => {
                let run = body(adapter.clone());
                let ignoring = self.state.ignoring_duplicates().get();
                let pinned = self.pinned.with(Some(adapter.clone()), async {
                    if ignoring { adapter.state().ignoring_duplicates().with(true, run).await } else { run.await }
                });
                (pinned.await, None)
            }
            Err(e) => (false, Some(e)),
        };
        self.release_borrowed(&*adapter);
        self.pool.release(&connection, !succeeded).await;
        checkout.done();
        match refused {
            Some(e) => Err(e),
            None => Ok(()),
        }
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Casting for Pool<S> {
    fn cast_before(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.delegate_sync("castBefore", move |a| {
            feature(a.casting(), Feature::Casting)?.cast_before(collection, document)
        })
    }

    fn cast_after(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.delegate_sync("castAfter", move |a| {
            feature(a.casting(), Feature::Casting)?.cast_after(collection, documents)
        })
    }

    fn cast_datetime(&self, value: &str) -> Result<Value> {
        self.delegate_sync("castDatetime", |a| feature(a.casting(), Feature::Casting)?.cast_datetime(value))
    }
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> Connection for Pool<S> {
    async fn ping(&self) -> Result<bool> {
        delegate!(self, "ping", |a| feature(a.connection(), Feature::Connection)?.ping().await)
    }

    async fn reconnect(&self) -> Result<()> {
        delegate!(self, "reconnect", |a| feature(a.connection(), Feature::Connection)?.reconnect().await)
    }

    async fn id(&self) -> Result<String> {
        delegate!(self, "id", |a| feature(a.connection(), Feature::Connection)?.id().await)
    }

    /// The hostname of the connections, unless this handle was given one;
    /// a [`ReadWritePool`] asks its write pool once.
    async fn hostname(&self) -> Result<String> {
        let Some(replica) = &self.replica else {
            return self.pool_hostname().await;
        };
        if let Some(known) = lock(&replica.write_hostname).clone() {
            return Ok(known);
        }
        let hostname = self.pool_hostname().await?;
        *lock(&replica.write_hostname) = Some(hostname.clone());
        Ok(hostname)
    }
}

impl<S: utopia_pools::Adapter<Pooled>> QueryBuilder for Pool<S> {
    fn builder(&self) -> Result<crate::hook::SqlBuilder> {
        self.delegate_sync("builder", |a| feature(a.query_builder(), Feature::QueryBuilder)?.builder())
    }

    fn schema(&self) -> Result<SqlSchema> {
        self.delegate_sync("schema", |a| feature(a.query_builder(), Feature::QueryBuilder)?.schema())
    }
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> RawQuery for Pool<S> {
    async fn raw_query(&self, query: &str, bindings: utopia_query::Array) -> Result<Vec<Document>> {
        delegate!(self, "rawQuery", |a| feature(a.raw_query(), Feature::RawQuery)?.raw_query(query, bindings).await)
    }

    async fn raw_mutation(&self, query: &str, bindings: utopia_query::Array) -> Result<i64> {
        delegate!(self, "rawMutation", |a| feature(a.raw_query(), Feature::RawQuery)?
            .raw_mutation(query, bindings)
            .await)
    }
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> Relationships for Pool<S> {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        delegate!(self, "createRelationship", |a| feature(a.relationships(), Feature::Relationships)?
            .create_relationship(collection, relationship)
            .await)
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &Update,
    ) -> Result<bool> {
        delegate!(self, "updateRelationship", |a| feature(a.relationships(), Feature::Relationships)?
            .update_relationship(collection, relationship, side, update)
            .await)
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        delegate!(self, "deleteRelationship", |a| feature(a.relationships(), Feature::Relationships)?
            .delete_relationship(collection, relationship, side)
            .await)
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Schemaless for Pool<S> {
    /// Every adapter this handle borrows afterwards is put in the mode
    /// first; one without a schemaless mode is left as it is.
    fn set_schemaless(&self, schemaless: bool) {
        *lock(&self.schemaless) = Some(schemaless);
    }

    fn is_schemaless(&self) -> bool {
        let mode = *lock(&self.schemaless);
        if let Some(mode) = mode
            && self.has_feature(Feature::Schemaless)
        {
            return mode;
        }
        self.delegate_sync("isSchemaless", |a| Ok(feature(a.schemaless(), Feature::Schemaless)?.is_schemaless()))
            .unwrap_or(false)
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Spatial for Pool<S> {
    /// The well-known text, built without a connection (`Wkt::encode()`).
    fn encode(&self, value: &Value, kind: ColumnType) -> Result<String> {
        wkt(value, kind)
    }

    fn decode(&self, value: &[u8], kind: ColumnType) -> Result<Value> {
        self.delegate_sync("decode", |a| feature(a.spatial(), Feature::Spatial)?.decode(value, kind))
    }
}

impl<S: utopia_pools::Adapter<Pooled>> Timeouts for Pool<S> {
    /// Held by the handle, not a connection: zero or less clears the
    /// event; a pinned connection gets the new bound at once.
    fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<()> {
        if milliseconds <= 0 {
            self.clear_timeout(event);
            return Ok(());
        }
        self.timeouts.set(milliseconds, event);
        match self.pin() {
            Some(pinned) => self.sync_timeouts(&*pinned),
            None => Ok(()),
        }
    }

    fn clear_timeout(&self, event: Event) {
        self.timeouts.clear(event);
        if let Some(pinned) = self.pin() {
            let _ = self.sync_timeouts(&*pinned);
        }
    }

    fn timeout(&self, event: Event) -> i64 {
        self.timeouts.get(event)
    }
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> Upserts for Pool<S> {
    async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        delegate!(self, "upsertDocument", |a| feature(a.upserts(), Feature::Upserts)?
            .upsert_document(collection, change)
            .await)
    }

    async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        delegate!(self, "upsertDocuments", |a| feature(a.upserts(), Feature::Upserts)?
            .upsert_documents(collection, changes, increase)
            .await)
    }
}

/// `"{$value[$i]}"`: a string's byte, an array's element, as PHP
/// interpolates it.
fn at(value: &Value, i: i64) -> String {
    match value {
        Value::Str(s) => {
            let bytes = s.as_bytes();
            let index = if i < 0 { bytes.len() as i64 + i } else { i };
            usize::try_from(index)
                .ok()
                .and_then(|i| bytes.get(i))
                .map(|b| String::from_utf8_lossy(&[*b]).into_owned())
                .unwrap_or_default()
        }
        Value::Array(a) => a.get(&php_std::ArrayKey::Int(i)).map(crate::value::to_text).unwrap_or_default(),
        _ => String::new(),
    }
}

fn element(value: &Value, i: i64) -> Value {
    match value {
        Value::Array(a) => a.get(&php_std::ArrayKey::Int(i)).cloned().unwrap_or(Value::Null),
        Value::Str(_) => crate::value::string(at(value, i)),
        _ => Value::Null,
    }
}

fn points(value: &Value) -> String {
    match value {
        Value::Array(a) => a.values().map(|p| format!("{} {}", at(p, 0), at(p, 1))).collect::<Vec<_>>().join(", "),
        _ => String::new(),
    }
}

/// `Wkt::encode($value, $type)`: the well-known text of a spatial value (a
/// polygon may be given as one ring).
///
/// Fails ([`Kind::Structure`]) for an invalid geometry, and
/// ([`Kind::Database`]) for a type that is not spatial.
pub fn wkt(value: &Value, kind: ColumnType) -> Result<String> {
    let validator = crate::validator::spatial::Spatial::new(kind.as_str());
    if !validator.is_valid(value) {
        return Err(Error::structure(validator.description()));
    }
    match kind {
        ColumnType::Point => Ok(format!("POINT({} {})", at(value, 0), at(value, 1))),
        ColumnType::Linestring => Ok(format!("LINESTRING({})", points(value))),
        ColumnType::Polygon => {
            let first = element(value, 0);
            let single = matches!(&first, Value::Array(p) if p.len() == 2
                && crate::validator::is_numeric(&element(&first, 0))
                && crate::validator::is_numeric(&element(&first, 1)));
            let rings = if single { vec![value.clone()] } else { element_list(value) };
            Ok(format!("POLYGON({})", rings.iter().map(|r| format!("({})", points(r))).collect::<Vec<_>>().join(", ")))
        }
        other => Err(Error::database(format!("Unknown spatial type: {}", other.as_str()))),
    }
}

fn element_list(value: &Value) -> Vec<Value> {
    match value {
        Value::Array(a) => a.values().cloned().collect(),
        _ => Vec::new(),
    }
}
