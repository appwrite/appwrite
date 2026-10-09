//! Appwrite collections on top of `utopia-database`.
//!
//! * [`Databases`]: the platform database and per-project databases
//!   (dedicated namespaces or shared tables), mirroring `Appwrite\Database\Factory`.
//! * [`documents`]: typed rows of the system collections the Rust services use.
//! * [`collections`]: attribute schemas used to validate and render queries.

pub mod collections;
pub mod documents;
pub mod project;

use std::collections::HashMap;
use std::sync::Arc;

use utopia_cache::Cache;
use utopia_database::{Database, Pool};

use crate::{Error, ErrorType, Result};

pub use project::Project;

/// Database schema used for every table.
pub const SCHEMA: &str = utopia_database::DEFAULT_SCHEMA;

/// Platform (`_console`) and project databases.
pub struct Databases {
    pools: HashMap<String, Pool>,
    console: Pool,
    cache: Arc<Cache>,
    shared_tables: Vec<String>,
}

impl Databases {
    pub fn new(pools: HashMap<String, Pool>, console: Pool, cache: Arc<Cache>, shared_tables: Vec<String>) -> Self {
        Self { pools, console, cache, shared_tables }
    }

    /// `dbForPlatform`.
    pub fn platform(&self) -> Database {
        Database::new(self.console.clone(), SCHEMA, "_console", None, Some(self.cache.clone()))
    }

    /// `dbForProject` for a non-console project.
    pub fn project(&self, project: &Project) -> Result<Database> {
        if project.is_console() {
            return Ok(self.platform());
        }
        if project.database.is_empty() {
            return Err(Error::with_message(ErrorType::GeneralServerError, "Project database is not configured"));
        }
        // A bare pool name (no scheme) reads as `mysql://<name>`, as in PHP.
        let dsn = utopia_dsn::Dsn::parse(&project.database)
            .or_else(|_| utopia_dsn::Dsn::parse(&format!("mysql://{}", project.database)))
            .map_err(|e| Error::internal(format!("Invalid project database DSN: {e}")))?;
        let pool = self
            .pools
            .get(dsn.host())
            .ok_or_else(|| Error::internal(format!("Unknown database pool: {}", dsn.host())))?
            .clone();
        let sequence = project.sequence_i64();
        if self.shared_tables.iter().any(|t| t == dsn.host()) {
            let namespace = match dsn.param("namespace") {
                Ok(Some(ns)) => String::from_utf8_lossy(ns).into_owned(),
                _ => String::new(),
            };
            Ok(Database::new(pool, SCHEMA, &namespace, Some(sequence), Some(self.cache.clone())))
        } else {
            Ok(Database::new(pool, SCHEMA, &format!("_{sequence}"), None, Some(self.cache.clone())))
        }
    }

    pub fn cache(&self) -> &Arc<Cache> {
        &self.cache
    }

    pub fn pools(&self) -> impl Iterator<Item = (&String, &Pool)> {
        self.pools.iter()
    }
}
