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
use utopia_database::database::Hook;
use utopia_database::event::Event;
use utopia_database::hook::{Permissions, Tenancy};
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
    utopia_database::id::unique().unwrap_or_default()
}

/// The connections of one database server, as PHP's `pools` group holds
/// them. Idle connections wait in a [`utopia_pools::Channel`], as in PHP's
/// Swoole pool adapter: a request finding none waits for one to come back
/// instead of polling for it.
#[derive(Clone)]
pub struct Connections {
    pub pool: utopia_pools::Pool<Pooled, utopia_pools::Channel<Pooled>>,
}

impl Connections {
    /// A database adapter drawing connections from this pool, named after
    /// the pool (`(new DatabasePool($pools->get($name)))->setHostname($name)`):
    /// the hostname is part of the cache keys.
    fn adapter(&self, name: &str) -> Arc<dyn Adapter> {
        let adapter = Pool::new(self.pool.clone());
        adapter.set_hostname(name);
        Arc::new(adapter)
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
    /// has (`Factory::platform()` / `project()` before their scoping):
    /// `newDatabase()`, `setDatabase()`, `setAuthorization()`,
    /// `setDropUnknownAttributes(true)`, `configureDocumentTypes()` and
    /// `configureOptions()`.
    fn database(&self, name: &str, authorization: Arc<Authorization>, project: &str) -> Result<Database> {
        let connections =
            self.pools.get(name).ok_or_else(|| Error::internal(format!("Unknown database pool: {name}")))?;
        let database = Database::new(connections.adapter(name), self.cache.clone());
        database.set_database(SCHEMA);
        database.set_authorization(authorization);
        database.set_drop_unknown_attributes(true);
        database.set_document_type("users", "Appwrite\\Utopia\\Database\\Documents\\User");
        database.set_metadata("host", Value::from(self.hostname.as_str()));
        database.set_metadata("project", Value::from(project));
        if self.timeout > 0 {
            database.set_timeout(self.timeout, Event::All)?;
        }
        database.set_max_query_values(QUERY_MAX_VALUES);
        Ok(database)
    }

    /// `applyHooks()` (without relationships): permissions, and the tenant
    /// of rows under shared tables.
    fn hooks(database: Database) -> Result<Database> {
        database.add_hook(Hook::write(Arc::new(Permissions::new())))?;
        if database.shared_tables() && !matches!(database.tenant(), Value::Null) {
            database.add_hook(Hook::write(Arc::new(Tenancy::default())))?;
        }
        Ok(database)
    }

    /// An authorization that checks nothing, for work done outside a
    /// request's permissions (PHP wraps it in `$authorization->skip()`).
    pub fn unchecked() -> Arc<Authorization> {
        Arc::new(Authorization::new(false))
    }

    /// `dbForPlatform`.
    pub fn platform(&self, authorization: Arc<Authorization>) -> Result<Database> {
        let database = self.database("console", authorization, Project::CONSOLE)?;
        database.set_namespace("_console");
        Self::hooks(database)
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
            database.set_namespace(&namespace);
        } else {
            database.set_shared_tables(false).set_tenant(Value::Null);
            database.set_namespace(&format!("_{}", project.sequence));
        }
        Self::hooks(database)
    }

    pub fn cache(&self) -> &Arc<Cache> {
        &self.cache
    }

    pub fn pools(&self) -> impl Iterator<Item = (&String, &Connections)> {
        self.pools.iter()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Databases over pools of in-memory SQLite connections: the cache keys
    /// name the pool, which a database reads once it knows its adapter has
    /// a connection.
    async fn databases() -> Databases {
        use utopia_database::adapter::sql::{Engine, Sql};
        let mut pools = HashMap::new();
        for name in ["console", "database_db_main"] {
            let pool = utopia_pools::Pool::new(
                utopia_pools::Channel::new(),
                name,
                1,
                || async {
                    Ok::<_, utopia_database::Error>(Pooled::new(
                        Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await?,
                    ))
                },
                1.0,
            )
            .unwrap();
            Pool::new(pool.clone()).warm().await.unwrap();
            pools.insert(name.to_owned(), Connections { pool });
        }
        let cache = Arc::new(Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
        Databases::new(pools, cache, Vec::new(), std::time::Duration::from_secs(15))
    }

    /// The keys PHP's `Factory` databases give (`getCacheKeys()` and
    /// `getQueryCacheKey()` printed by PHP 8.1.3 with Appwrite's filters):
    /// the cache is shared, so they must be the same.
    #[utopia_async::test]
    async fn cache_keys_match_php() {
        let databases = databases().await;
        let authorization = Databases::unchecked();
        let platform = databases.platform(authorization.clone()).unwrap();
        let mut project = Project::empty();
        project.id = "p1".to_owned();
        project.sequence = "7".to_owned();
        project.database = "database_db_main".to_owned();
        let db = databases.project(&project, authorization).unwrap();

        let keys = |k: (String, String, String)| [k.0, k.1, k.2];
        assert_eq!(
            keys(platform.cache_keys("projects", Some("p1"), &[]).await.unwrap()),
            [
                "default-cache-console:appwrite:_console::collection:projects",
                "default-cache-console:appwrite:_console::collection:projects:p1",
                "default-cache-console:appwrite:_console::collection:projects:p1:33cef9106fc54859d59ac2e4e64c3180",
            ]
        );
        assert_eq!(
            keys(db.cache_keys("users", Some("u1"), &[]).await.unwrap()),
            [
                "default-cache-database_db_main:appwrite:_7::collection:users",
                "default-cache-database_db_main:appwrite:_7::collection:users:u1",
                "default-cache-database_db_main:appwrite:_7::collection:users:u1:33cef9106fc54859d59ac2e4e64c3180",
            ]
        );
        let selects = ["name".to_owned(), "$id".to_owned()];
        assert_eq!(
            db.cache_keys("users", Some("u1"), &selects).await.unwrap().2,
            "default-cache-database_db_main:appwrite:_7::collection:users:u1:c18f23e260f01bcf88ac1ee872b6c741"
        );
        assert_eq!(
            db.query_cache_key("users", None).await.unwrap(),
            "default-cache-database_db_main:appwrite:_7::collection:users:query"
        );
        let skipped = filters::names(filters::USERS_SUBQUERIES);
        let (_, _, hash) =
            db.skip_filters(Some(skipped.as_slice()), db.cache_keys("users", Some("u1"), &[])).await.unwrap();
        assert_eq!(
            hash,
            "default-cache-database_db_main:appwrite:_7::collection:users:u1:7e28cd740374d16a34075c661e11f5c5"
        );
    }
}
