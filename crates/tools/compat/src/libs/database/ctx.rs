//! A session's state (PHP's `CoreState` and the `Objects` table): the
//! databases and adapters it opened (handles), the objects steps stored
//! (`as`), the current database and the log fixtures write to.
//!
//! It lives behind an `Arc` so that callbacks the library runs can run
//! steps on it while the operation that passed them is still running.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, LazyLock, Mutex};

use serde_json::{Map, Value as Json};
use utopia_database::{Database, Error};

use super::obj::Obj;
use crate::adapter::{Fault, OpResult, Outcome, Session};

/// Why an operation did not return a value: the library raised an error
/// (the operation's result is `{"$error": ...}`), or the harness failed.
#[derive(Debug)]
pub enum Fail {
    /// A library error: the operation's result.
    Error(Error),
    /// A harness problem: stops the case.
    Fault(Fault),
    /// An error of another library (or of the engine): its PHP class and
    /// message.
    Php {
        /// The PHP class.
        class: String,
        /// The message.
        message: Vec<u8>,
    },
}

impl Fail {
    /// An error of the PHP class `class`.
    pub fn php(class: impl Into<String>, message: impl Into<Vec<u8>>) -> Fail {
        Fail::Php { class: class.into(), message: message.into() }
    }

    /// The PHP class and message of an error (`None` for a fault).
    pub fn class_and_message(&self) -> Option<(String, Vec<u8>)> {
        match self {
            Fail::Error(e) => Some((e.php_class().to_owned(), e.message_bytes().to_vec())),
            Fail::Php { class, message } => Some((class.clone(), message.clone())),
            Fail::Fault(_) => None,
        }
    }
}

impl From<Error> for Fail {
    fn from(e: Error) -> Self {
        Fail::Error(e)
    }
}

impl From<Fault> for Fail {
    fn from(f: Fault) -> Self {
        Fail::Fault(f)
    }
}

impl From<super::codec::DecodeError> for Fail {
    fn from(e: super::codec::DecodeError) -> Self {
        match e {
            super::codec::DecodeError::Fault(f) => Fail::Fault(f),
            super::codec::DecodeError::Error(e) => Fail::Error(e),
        }
    }
}

impl From<utopia_query::Error> for Fail {
    fn from(e: utopia_query::Error) -> Self {
        Fail::Php { class: e.php_class().to_owned(), message: e.message_bytes().to_vec() }
    }
}

/// What an operation returns.
pub type R<T> = Result<T, Fail>;

/// A fault with a message.
pub fn fault<T>(message: impl Into<String>) -> R<T> {
    Err(Fail::Fault(Fault::new(message)))
}

/// An operation's result as the driver reports it.
pub fn outcome(r: R<Obj>) -> OpResult {
    match r {
        Ok(obj) => Ok(Outcome::Ok(obj.encode())),
        Err(Fail::Error(e)) => Ok(Outcome::err_bytes(e.php_class(), e.message_bytes())),
        Err(Fail::Php { class, message }) => Ok(Outcome::err_bytes(class, &message)),
        Err(Fail::Fault(f)) => Err(f),
    }
}

/// A database a case opened (`db.open`) and what `db.*` operations need
/// besides it.
#[derive(Clone)]
pub struct Db {
    /// The database.
    pub database: Database,
    /// The SQL adapter whose connection `db.sql` runs on.
    pub sql: Option<Arc<utopia_database::adapter::sql::Sql>>,
    /// The connection pool of a Pool database (`pool.of` shares it).
    pub pool: Option<Arc<super::open::PoolFixture>>,
}

/// A session's state.
pub struct Ctx {
    /// The case namespace (`${ns}`).
    pub ns: String,
    /// The service endpoints of this runtime.
    pub services: Map<String, Json>,
    handles: Mutex<HashMap<String, Obj>>,
    dbs: Mutex<HashMap<String, Db>>,
    next: AtomicU64,
    objects: Mutex<HashMap<String, Obj>>,
    current: Mutex<Option<Db>>,
    /// What fixtures logged since the last `db.log`.
    pub log: Log,
    /// The fuzz programs' adapters, by driver.
    pub fuzz: tokio::sync::Mutex<HashMap<String, Arc<dyn utopia_database::Adapter>>>,
}

/// A log fixtures append to.
pub type Log = Arc<Mutex<Vec<Json>>>;

static ACTIVE_LOG: LazyLock<Mutex<Option<Log>>> = LazyLock::new(|| Mutex::new(None));

/// Locks a mutex, poisoned or not.
pub fn lock<T>(m: &Mutex<T>) -> std::sync::MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

/// Appends an entry to the log of the session running now (`Core::log`).
pub fn log(entry: Json) {
    if let Some(log) = lock(&ACTIVE_LOG).as_ref() {
        lock(log).push(entry);
    }
}

/// What a [`Session`] keeps for the database library.
#[derive(Default)]
struct Holder(Option<Arc<Ctx>>);

impl Ctx {
    /// The session's state, created (with the process-wide registries
    /// reset, `Core::fresh()`) on its first operation; makes it the one
    /// fixtures log into (`Core::enter`).
    pub fn enter(session: &mut Session) -> Arc<Ctx> {
        let (ns, services) = (session.ns.clone(), session.services.clone());
        let holder = session.state::<Holder>();
        let ctx = match &holder.0 {
            Some(ctx) => ctx.clone(),
            None => {
                super::fixtures::fresh();
                let ctx = Arc::new(Ctx {
                    ns,
                    services,
                    handles: Mutex::new(HashMap::new()),
                    dbs: Mutex::new(HashMap::new()),
                    next: AtomicU64::new(0),
                    objects: Mutex::new(HashMap::new()),
                    current: Mutex::new(None),
                    log: Arc::new(Mutex::new(Vec::new())),
                    fuzz: tokio::sync::Mutex::new(HashMap::new()),
                });
                holder.0 = Some(ctx.clone());
                ctx
            }
        };
        *lock(&ACTIVE_LOG) = Some(ctx.log.clone());
        ctx
    }

    /// Endpoint of a service this runtime was configured with.
    pub fn service(&self, name: &str) -> R<String> {
        match self.services.get(name).and_then(Json::as_str) {
            Some(s) => Ok(s.to_owned()),
            None => fault(format!("service `{name}` is not configured for this run")),
        }
    }

    fn next_handle(&self) -> String {
        format!("h{}", self.next.fetch_add(1, Ordering::SeqCst) + 1)
    }

    /// Stores a database and returns its handle (`{"$handle": "h1"}`).
    pub fn handle_db(&self, db: Db) -> Json {
        let id = self.next_handle();
        lock(&self.dbs).insert(id.clone(), db);
        handle_json(id)
    }

    /// Stores an object (an adapter) and returns its handle.
    pub fn handle(&self, obj: Obj) -> Json {
        let id = self.next_handle();
        lock(&self.handles).insert(id.clone(), obj);
        handle_json(id)
    }

    /// The database a handle names.
    pub fn db_of(&self, handle: &Json) -> R<Db> {
        let id = handle_id(handle)?;
        match lock(&self.dbs).get(id) {
            Some(db) => Ok(db.clone()),
            None => fault(format!("handle {id} is not a database handle")),
        }
    }

    /// Whether a handle names a database.
    pub fn is_db(&self, handle: &Json) -> bool {
        handle_id(handle).is_ok_and(|id| lock(&self.dbs).contains_key(id))
    }

    /// The object a handle names.
    pub fn handled(&self, handle: &Json) -> R<Obj> {
        let id = handle_id(handle)?;
        if let Some(db) = lock(&self.dbs).get(id) {
            return Ok(Obj::object(super::database::DatabaseObj(db.database.clone())));
        }
        match lock(&self.handles).get(id) {
            Some(obj) => Ok(obj.clone()),
            None => fault(format!("handle {id} is missing")),
        }
    }

    /// The database an operation acts on: `db` (a handle) or the current one
    /// (`Core::db`).
    pub fn db(&self, args: &Json) -> R<Db> {
        match args.get("db") {
            Some(h) if !h.is_null() => {
                self.db_of(h).map_err(|_| Fail::Fault(Fault::new("`db` is not a database handle")))
            }
            _ => match lock(&self.current).as_ref() {
                Some(db) => Ok(db.clone()),
                None => fault("no database: run `db.open` first"),
            },
        }
    }

    /// The current database, if any.
    pub fn current(&self) -> Option<Db> {
        lock(&self.current).clone()
    }

    /// Makes `db` the current database.
    pub fn set_current(&self, db: Db) {
        *lock(&self.current) = Some(db);
    }

    /// Stores an object under a name (`as`).
    pub fn store(&self, name: &str, obj: Obj) {
        lock(&self.objects).insert(name.to_owned(), obj);
    }

    /// A copy of the object stored under a name.
    pub fn stored(&self, name: &str) -> R<Obj> {
        match lock(&self.objects).get(name) {
            Some(obj) => Ok(obj.clone()),
            None => fault(format!("no stored object `{name}`")),
        }
    }

    /// Whether an object is stored under a name.
    pub fn has_stored(&self, name: &str) -> bool {
        lock(&self.objects).contains_key(name)
    }

    /// The log since the last call, cleared (`db.log`).
    pub fn take_log(&self) -> Vec<Json> {
        std::mem::take(&mut *lock(&self.log))
    }
}

fn handle_json(id: String) -> Json {
    let mut o = Map::new();
    o.insert("$handle".into(), Json::String(id));
    Json::Object(o)
}

fn handle_id(value: &Json) -> R<&str> {
    match value.get("$handle").and_then(Json::as_str) {
        Some(id) => Ok(id),
        None => fault(format!("expected a handle, got {value}")),
    }
}
