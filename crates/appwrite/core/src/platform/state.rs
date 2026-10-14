//! Process-wide state: pools, caches, publishers and static data.

use std::collections::{HashMap, HashSet};
use std::sync::Arc;
use std::time::Duration;

use utopia_cache::Cache;
use utopia_cache::redis::aio::ConnectionManager;
use utopia_database::adapter::pool::Pooled;
use utopia_database::adapter::postgres::{Config as PostgresConfig, Postgres};

use super::Hooks;
use super::pwned::Pwned;
use crate::config::{Config, DatabaseDsn};
use crate::database::project::ProjectStore;
use crate::database::{Connections, Databases};
use crate::geo::Geo;
use crate::locale::Translations;

/// Shared state, created once at startup.
pub struct State {
    pub config: Config,
    pub databases: Databases,
    pub projects: ProjectStore,
    pub cache: Arc<Cache>,
    /// Publishes onto the Redis queues PHP workers consume.
    pub publisher: Arc<utopia_queue::broker::Redis>,
    /// Redis pub/sub for realtime fan-out (`PUBLISH realtime`).
    pub pubsub: ConnectionManager,
    pub lock: crate::locking::Locking,
    /// Redis for the abuse limits (PHP's `abuse` pool).
    pub abuse: ConnectionManager,
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
        // PHP's codec (app/init/registers.php): entries PHP shares are igbinary.
        // Rust-only entries use the native codec (`Cache::save_native`).
        let cache = Arc::new(Cache::from_dyn(Arc::new(utopia_cache::Redis::with_codec(
            redis[0].clone(),
            utopia_cache::Igbinary,
        ))));
        let pubsub = redis[redis.len() - 1].clone();
        let publisher = Arc::new(utopia_queue::broker::Redis::new(Arc::new(pubsub.clone()), Arc::new(pubsub.clone())));
        let lock = crate::locking::Locking::new(redis[0].clone());
        let abuse = redis[0].clone();

        // PostgreSQL pools, de-duplicated by server so that `console` and
        // `database_db_main` share connections on single-server installs.
        let mut by_server: HashMap<String, Connections> = HashMap::new();
        let mut pools: HashMap<String, Connections> = HashMap::new();
        let mut console: Option<Connections> = None;
        for dsn in &config.databases {
            if dsn.scheme != "postgresql" && dsn.scheme != "postgres" {
                return Err(format!(
                    "database '{}' uses '{}'; the Rust API currently supports PostgreSQL only",
                    dsn.name, dsn.scheme
                )
                .into());
            }
            let server = format!("{}:{}/{}@{}", dsn.host, dsn.port, dsn.database, dsn.user);
            let connections = match by_server.get(&server) {
                Some(c) => c.clone(),
                None => {
                    let c = connect(dsn, config.db_pool_size).await?;
                    by_server.insert(server, c.clone());
                    c
                }
            };
            if console.is_none() {
                console = Some(connections.clone());
            }
            pools.insert(format!("database_{}", dsn.name), connections);
        }
        let console = console.ok_or("no database configured")?;
        pools.insert("console".to_owned(), console);

        // The email domain lists are embedded in utopia-emails: parse them
        // now rather than on the first request that needs them.
        let _ = utopia_emails::Lists::global();
        let translations = Translations::load(&config.asset("app/config/locale/translations")).await;
        let dictionary = crate::validators::load_dictionary(&config.asset("app/assets/security/10k-common-passwords"));
        let geo = Geo::new(config.geo_endpoint.clone(), config.geo_secret.clone());
        let pwned = Pwned::from_dsn(&config.pwned_dsn, !config.development)?;

        Ok(Self {
            databases: Databases::new(pools, cache.clone(), config.shared_tables.clone(), config.statement_timeout),
            projects: ProjectStore::new(&config.region),
            cache,
            publisher,
            pubsub,
            lock,
            abuse,
            translations,
            dictionary,
            geo,
            pwned,
            hooks,
            config,
        })
    }
}

/// A pool of `size` PostgreSQL connections to `dsn` (opened as they are
/// needed), with one connection opened now: the fallback its handles read
/// capabilities from. Waits up to 30 seconds for the server.
async fn connect(dsn: &DatabaseDsn, size: usize) -> Result<Connections, BootError> {
    let config = PostgresConfig {
        host: dsn.host.clone(),
        port: dsn.port,
        user: dsn.user.clone(),
        password: dsn.password.clone(),
        dbname: dsn.database.clone(),
    };
    let pool = utopia_pools::Pool::new(
        utopia_pools::Channel::new(),
        &format!("database_{}", dsn.name),
        size.max(1),
        move || {
            let config = config.clone();
            async move { Ok::<_, utopia_database::Error>(Pooled::new(Postgres::with_config(config).await?)) }
        },
        10.0,
    )?;
    let mut attempt = 0;
    let connection = loop {
        match pool.pop().await {
            Ok(connection) => break connection,
            Err(e) if attempt < 30 => {
                tracing::warn!(error = %e, database = dsn.name, "waiting for the database");
                attempt += 1;
                tokio::time::sleep(Duration::from_secs(1)).await;
            }
            Err(e) => return Err(e.into()),
        }
    };
    let fallback = connection.resource().0.clone();
    pool.reclaim(&connection);
    Ok(Connections { pool, fallback })
}
