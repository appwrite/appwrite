//! Appwrite's databases on `utopia-database`.
//!
//! * [`Databases`]: the platform database and per-project databases
//!   (dedicated namespaces or shared tables), configured as
//!   `Appwrite\Database\Factory` configures them.
//! * [`filters`]: the attribute filters of `app/init/database/filters.php`.
//! * [`documents`]: typed views of the documents the Rust services read.
//! * [`collections`]: the `Queries\*` validators of list endpoints.

pub mod collections;
pub mod documents;
pub mod filters;
pub mod project;

use std::collections::HashMap;
use std::sync::Arc;

use utopia_cache::Cache;
use utopia_database::adapter::pool::{Pool, Pooled};
use utopia_database::{Adapter, Authorization, Database, Value};

use crate::{Error, ErrorType, Result};

pub use project::Project;

/// `APP_DATABASE`: the schema every table lives in.
pub const SCHEMA: &str = "appwrite";

/// `APP_DATABASE_QUERY_MAX_VALUES`.
pub const QUERY_MAX_VALUES: i64 = 500;

/// `APP_LIMIT_COUNT`.
pub const LIMIT_COUNT: i64 = 5000;

/// `ID::unique()`.
pub fn unique_id() -> String {
    utopia_database::helpers::id::unique().unwrap_or_default()
}

/// The connections of one database server, as PHP's `pools` group holds
/// them, with a connection its handles read capabilities from until they
/// use one ([`Pool::with_fallback`]). Idle connections wait in a
/// [`utopia_pools::Channel`], as in PHP's Swoole pool adapter: a request
/// finding none waits for one to come back instead of polling for it.
#[derive(Clone)]
pub struct Connections {
    pub pool: utopia_pools::Pool<Pooled, utopia_pools::Channel<Pooled>>,
    pub fallback: Arc<dyn Adapter>,
}

impl Connections {
    /// A database adapter drawing connections from this pool
    /// (`new DatabasePool($pools->get($name))`).
    fn adapter(&self) -> Arc<dyn Adapter> {
        Arc::new(Pool::new(self.pool.clone()).with_fallback(self.fallback.clone()))
    }
}

/// Platform (`_console`) and project databases (`Appwrite\Database\Factory`).
pub struct Databases {
    pools: HashMap<String, Connections>,
    cache: Arc<Cache>,
    shared_tables: Vec<String>,
    /// `APP_DATABASE_TIMEOUT_MILLISECONDS_API`.
    timeout: i64,
    /// `gethostname()`, the `host` of every database's metadata.
    hostname: String,
}

impl Databases {
    /// `pools` by name (`console`, `database_<name>`); registers Appwrite's
    /// filters.
    pub fn new(
        pools: HashMap<String, Connections>,
        cache: Arc<Cache>,
        shared_tables: Vec<String>,
        timeout: std::time::Duration,
    ) -> Self {
        filters::register();
        Self {
            pools,
            cache,
            shared_tables,
            timeout: timeout.as_millis() as i64,
            hostname: php_std::system::gethostname(),
        }
    }

    /// A database on pool `name`, with the settings every Appwrite database
    /// has (`Factory::platform()` / `project()` before their scoping).
    fn database(&self, name: &str, authorization: Arc<Authorization>, project: &str) -> Result<Database> {
        let connections =
            self.pools.get(name).ok_or_else(|| Error::internal(format!("Unknown database pool: {name}")))?;
        let database = Database::new(connections.adapter(), self.cache.clone(), Vec::new());
        database.set_database(SCHEMA)?;
        database.set_authorization(authorization);
        database.set_drop_unknown_attributes(true);
        database.set_document_type("users", "Appwrite\\Utopia\\Database\\Documents\\User")?;
        database.set_metadata("host", &self.hostname);
        database.set_metadata("project", project);
        if self.timeout > 0 {
            database.set_timeout(self.timeout, Database::EVENT_ALL)?;
        }
        database.set_max_query_values(QUERY_MAX_VALUES);
        Ok(database)
    }

    /// An authorization that checks nothing, for work done outside a
    /// request's permissions (PHP wraps it in `$authorization->skip()`).
    pub fn unchecked() -> Arc<Authorization> {
        let authorization = Authorization::new();
        authorization.set_default_status(false);
        Arc::new(authorization)
    }

    /// `dbForPlatform`.
    pub fn platform(&self, authorization: Arc<Authorization>) -> Result<Database> {
        let database = self.database("console", authorization, Project::CONSOLE)?;
        database.set_namespace("_console")?;
        Ok(database)
    }

    /// `dbForProject` (`getProjectDB`): the platform database for the
    /// console project.
    pub fn project(&self, project: &Project, authorization: Arc<Authorization>) -> Result<Database> {
        if project.is_console() || project.id.is_empty() {
            return self.platform(authorization);
        }
        if project.database.is_empty() {
            return Err(Error::with_message(ErrorType::GeneralServerError, "Project database is not configured"));
        }
        // A bare pool name (no scheme) reads as `mysql://<name>`, as in PHP.
        let dsn = utopia_dsn::Dsn::parse(&project.database)
            .or_else(|_| utopia_dsn::Dsn::parse(&format!("mysql://{}", project.database)))
            .map_err(|e| Error::internal(format!("Invalid project database DSN: {e}")))?;
        let database = self.database(dsn.host(), authorization, &project.id)?;
        if self.shared_tables.iter().any(|t| t == dsn.host()) {
            let namespace = match dsn.param("namespace") {
                Ok(Some(ns)) => String::from_utf8_lossy(ns).into_owned(),
                _ => String::new(),
            };
            database
                .set_shared_tables(true)
                .set_global_collections(&collections::project_global_collections())
                .set_tenant(Value::Int(project.sequence_i64()));
            database.set_namespace(&namespace)?;
        } else {
            database.set_shared_tables(false).set_tenant(Value::Null);
            database.set_namespace(&format!("_{}", project.sequence))?;
        }
        Ok(database)
    }

    pub fn cache(&self) -> &Arc<Cache> {
        &self.cache
    }

    pub fn pools(&self) -> impl Iterator<Item = (&String, &Connections)> {
        self.pools.iter()
    }
}
