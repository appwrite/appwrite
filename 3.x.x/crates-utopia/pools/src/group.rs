use std::collections::HashMap;

use crate::{Connection, Pool, PoolError, Recover};

/// PHP `Utopia\Pools\Group`.
///
/// Homogeneous in `T` (PHP is untyped). Mixed MySQL/Redis/HTTP pools stay as
/// separate fields on the app; this map is named lookup of the same resource type.
pub struct Group<T> {
    pools: HashMap<String, Pool<T>>,
}

impl<T> Default for Group<T> {
    fn default() -> Self {
        Self::new()
    }
}

impl<T> std::fmt::Debug for Group<T> {
    fn fmt(&self, formatter: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        formatter
            .debug_struct("Group")
            .field("pools", &self.pools.keys().collect::<Vec<_>>())
            .finish()
    }
}

impl<T> Group<T> {
    #[must_use]
    pub fn new() -> Self {
        Self {
            pools: HashMap::new(),
        }
    }
}

impl<T: Recover + Send + 'static> Group<T> {
    /// PHP `Group::add($pool)`.
    pub fn add(&mut self, pool: Pool<T>) -> &mut Self {
        self.pools.insert(pool.name().to_string(), pool);
        self
    }

    /// PHP `Group::get($name)`.
    pub fn get(&self, name: &str) -> Result<&Pool<T>, PoolError> {
        self.pools
            .get(name)
            .ok_or_else(|| PoolError::NotFound(name.to_string()))
    }

    /// PHP `Group::remove($name)`.
    pub fn remove(&mut self, name: &str) -> &mut Self {
        self.pools.remove(name);
        self
    }

    /// PHP `Group::reclaim()`.
    pub fn reclaim(&self) -> &Self {
        for pool in self.pools.values() {
            pool.reclaim(None);
        }
        self
    }

    /// PHP `Group::use($names, $callback)`.
    ///
    /// Resources are a slice (PHP spreads them as callback arguments).
    pub async fn use_resources<F, R>(&self, names: &[&str], callback: F) -> Result<R, PoolError>
    where
        F: FnOnce(&mut [&mut T]) -> Result<R, PoolError>,
    {
        if names.is_empty() {
            return Err(PoolError::EmptyNames);
        }

        let mut connections: Vec<Connection<T>> = Vec::new();
        let mut pools: Vec<Pool<T>> = Vec::new();
        let mut started = false;
        let mut thrown: Option<PoolError> = None;
        let mut result: Option<R> = None;

        match self.acquire(names, &mut pools, &mut connections).await {
            Ok(()) => {
                started = true;
                let mut guards: Vec<parking_lot::MutexGuard<'_, T>> =
                    connections.iter().map(Connection::resource).collect();
                let mut refs: Vec<&mut T> = guards.iter_mut().map(|guard| &mut **guard).collect();
                match callback(&mut refs) {
                    Ok(value) => result = Some(value),
                    Err(error) => thrown = Some(error),
                }
            }
            Err(error) => thrown = Some(error),
        }

        let failed = started && thrown.is_some();
        for index in (0..connections.len()).rev() {
            pools[index].release(&connections[index], failed);
        }

        if let Some(error) = thrown {
            return Err(error);
        }
        Ok(result.expect("callback succeeded"))
    }

    async fn acquire(
        &self,
        names: &[&str],
        pools: &mut Vec<Pool<T>>,
        connections: &mut Vec<Connection<T>>,
    ) -> Result<(), PoolError> {
        for name in names {
            let pool = self.get(name)?.clone();
            let connection = pool.pop().await?;
            pools.push(pool);
            connections.push(connection);
        }
        Ok(())
    }
}
