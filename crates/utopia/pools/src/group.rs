//! [`Group`]: pools by name.

use crate::{Adapter, Connection, Error, Pool, Resource};

/// Pools by name, to reclaim them together or run work with a connection
/// from several at once.
///
/// The pools of one group lend one resource type; pools of different
/// resources share a group through an enum resource.
///
/// ```
/// use utopia_pools::{Group, Pool, Stack};
///
/// # utopia_async::run(async {
/// let make = |name: &str, value: &'static str| {
///     Pool::new(Stack::new(), name, 1, move || async move { Ok::<_, std::io::Error>(value) }, 0.0)
/// };
/// let mut group = Group::new();
/// group.add(make("db", "1")?).add(make("cache", "3")?);
///
/// let joined = group.with(&["db", "cache"], async |resources| Ok::<_, utopia_pools::Error>(resources.iter().map(|r| **r).collect::<String>())).await?;
/// assert_eq!(joined, "13");
/// # Ok::<(), utopia_pools::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Pools\Adapter\Stack;
/// use Utopia\Pools\Group;
/// use Utopia\Pools\Pool;
///
/// $group = new Group();
/// $group->add(new Pool(new Stack(), 'db', 1, fn () => '1', timeout: 0.0));
/// $group->add(new Pool(new Stack(), 'cache', 1, fn () => '3', timeout: 0.0));
///
/// echo $group->use(['db', 'cache'], fn ($db, $cache) => $db . $cache), "\n"; // 13
/// ```
///
/// PHP: `Utopia\Pools\Group`.
pub struct Group<T, A> {
    pools: Vec<Pool<T, A>>,
}

impl<T, A> Default for Group<T, A> {
    /// An empty group.
    fn default() -> Self {
        Group { pools: Vec::new() }
    }
}

impl<T: Resource, A: Adapter<T>> Group<T, A> {
    /// An empty group.
    pub fn new() -> Self {
        Self::default()
    }

    /// Adds `pool` under its name, replacing a pool of the same name.
    ///
    /// PHP: `add()`.
    pub fn add(&mut self, pool: Pool<T, A>) -> &mut Self {
        match self.pools.iter_mut().find(|p| p.name() == pool.name()) {
            Some(existing) => *existing = pool,
            None => self.pools.push(pool),
        }
        self
    }

    /// The pool named `name`.
    ///
    /// Fails with [`Error::NotFound`] when there is none.
    ///
    /// PHP: `get()`.
    pub fn get(&self, name: &str) -> Result<&Pool<T, A>, Error> {
        self.pools.iter().find(|p| p.name() == name).ok_or_else(|| Error::NotFound(format!("Pool '{name}' not found")))
    }

    /// Removes the pool named `name`, if any.
    ///
    /// PHP: `remove()`.
    pub fn remove(&mut self, name: &str) -> &mut Self {
        self.pools.retain(|p| p.name() != name);
        self
    }

    /// Returns every checked-out connection of every pool.
    ///
    /// PHP: `reclaim()`.
    pub fn reclaim(&self) -> &Self {
        for pool in &self.pools {
            pool.reclaim_all();
        }
        self
    }

    /// Runs `work` with one resource from each named pool, in order, then
    /// returns the connections, last first: released as failed when `work`
    /// returns an error, reclaimed otherwise (also when a later pop failed).
    ///
    /// Fails with [`Error::Empty`] for no names, [`Error::NotFound`] for an
    /// unknown name, or a pool's pop error.
    ///
    /// PHP: `use(array $names, callable $callback)`.
    pub async fn with<R, E: From<Error>>(
        &self,
        names: &[&str],
        work: impl AsyncFnOnce(Vec<&T>) -> Result<R, E>,
    ) -> Result<R, E> {
        if names.is_empty() {
            return Err(Error::Empty("Cannot use with empty names".into()).into());
        }
        let mut held: Vec<(&Pool<T, A>, Connection<T>)> = Vec::with_capacity(names.len());
        let mut failure: Option<Error> = None;
        for name in names {
            let popped = match self.get(name) {
                Ok(pool) => pool.pop().await.map(|c| (pool, c)),
                Err(e) => Err(e),
            };
            match popped {
                Ok(pair) => held.push(pair),
                Err(e) => {
                    failure = Some(e);
                    break;
                }
            }
        }
        let result = match failure {
            Some(e) => Err(E::from(e)),
            None => work(held.iter().map(|(_, c)| c.resource()).collect()).await,
        };
        let failed = result.is_err() && held.len() == names.len();
        for (pool, connection) in held.iter().rev() {
            pool.release(connection, failed).await;
        }
        result
    }
}
