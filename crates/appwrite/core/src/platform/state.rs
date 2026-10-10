//! Process-wide state: pools, caches, publishers and static data.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;
use std::time::Duration;

use utopia_cache::Cache;
use utopia_cache::redis::aio::ConnectionManager;
use utopia_database::{Pool, PoolOptions};
use utopia_queue::Publisher;

use super::Hooks;
use super::pwned::Pwned;
use crate::config::Config;
use crate::database::Databases;
use crate::database::project::ProjectStore;
use crate::geo::Geo;
use crate::locale::Translations;

/// Shared state, created once at startup.
pub struct State {
    pub config: Config,
    pub databases: Databases,
    pub projects: ProjectStore,
    pub cache: Arc<Cache>,
    pub publisher: Publisher,
    pub lock: crate::locking::Locking,
    pub translations: Translations,
    pub dictionary: HashSet<String>,
    pub geo: Geo,
    pub pwned: Pwned,
    pub hooks: Arc<dyn Hooks>,
}

pub type BootError = Box<dyn std::error::Error + Send + Sync>;

impl State {
    /// Connects to Redis and PostgreSQL and loads static data.
    pub async fn new(config: Config, hooks: Arc<dyn Hooks>) -> Result<Self, BootError> {
        // Redis: a few multiplexed connections shared by cache, queues and locks.
        let client = utopia_cache::redis::Client::open(config.redis.url())?;
        let mut redis = Vec::with_capacity(config.redis_connections.max(1));
        for _ in 0..config.redis_connections.max(1) {
            redis.push(ConnectionManager::new(client.clone()).await?);
        }
        let cache = Arc::new(Cache::new(vec![redis[0].clone()]));
        let publisher = Publisher::new(redis[redis.len() - 1].clone());
        let lock = crate::locking::Locking::new(redis[0].clone());

        // PostgreSQL pools, de-duplicated by server so that `console` and
        // `database_db_main` share connections on single-server installs.
        let mut by_server: HashMap<String, Pool> = HashMap::new();
        let mut pools: HashMap<String, Pool> = HashMap::new();
        let mut console: Option<Pool> = None;
        for dsn in &config.databases {
            if dsn.scheme != "postgresql" && dsn.scheme != "postgres" {
                return Err(format!(
                    "database '{}' uses '{}'; the Rust API currently supports PostgreSQL only",
                    dsn.name, dsn.scheme
                )
                .into());
            }
            let server = format!("{}:{}/{}@{}", dsn.host, dsn.port, dsn.database, dsn.user);
            let pool = match by_server.get(&server) {
                Some(p) => p.clone(),
                None => {
                    let p = Pool::new(&PoolOptions {
                        host: dsn.host.clone(),
                        port: dsn.port,
                        user: dsn.user.clone(),
                        password: dsn.password.clone(),
                        dbname: dsn.database.clone(),
                        max_size: config.db_pool_size,
                        statement_timeout: config.statement_timeout,
                        connect_timeout: Duration::from_secs(3),
                        wait_timeout: Duration::from_secs(10),
                        application_name: "appwrite-rust".to_owned(),
                    })?;
                    by_server.insert(server, p.clone());
                    p
                }
            };
            if console.is_none() {
                console = Some(pool.clone());
            }
            pools.insert(format!("database_{}", dsn.name), pool);
        }
        let console = console.ok_or("no database configured")?;
        pools.insert("console".to_owned(), console.clone());

        let lists = utopia_emails::Lists::load(&config.asset("packages/emails/data")).unwrap_or_else(|e| {
            tracing::warn!(error = %e, "email domain lists unavailable");
            utopia_emails::Lists::default()
        });
        lists.install();
        let translations = Translations::load(&config.asset("app/config/locale/translations")).await;
        let dictionary = crate::validators::load_dictionary(&config.asset("app/assets/security/10k-common-passwords"));
        let geo = Geo::new(config.geo_endpoint.clone(), config.geo_secret.clone());
        let pwned = Pwned::from_dsn(&config.pwned_dsn, !config.development)?;

        Ok(Self {
            databases: Databases::new(pools, console, cache.clone(), config.shared_tables.clone()),
            projects: ProjectStore::new(&config.region),
            cache,
            publisher,
            lock,
            translations,
            dictionary,
            geo,
            pwned,
            hooks,
            config,
        })
    }
}
