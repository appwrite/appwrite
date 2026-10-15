//! [`Invalidator`]: the query cache invalidation a database runs for its
//! mutations.

use std::sync::Arc;

use super::{Query, Scope, Target, Tokens};
use crate::error::Result;
use crate::event::Event;

/// The query cache invalidation a database runs for its mutations. It is
/// not a lifecycle hook: it is never silenced, reads the raw write targets
/// and runs before the hooks.
///
/// PHP: `Utopia\Database\Cache\Invalidator`.
#[derive(Debug)]
pub struct Invalidator {
    query_cache: Arc<Query>,
    scope: Scope,
}

impl Invalidator {
    /// An invalidation of `query_cache` (`new Invalidator($queryCache)`).
    pub fn new(query_cache: Arc<Query>) -> Self {
        Invalidator { query_cache, scope: Scope::default() }
    }

    /// An invalidation of `query_cache` under `scope` when none is given.
    pub fn with_scope(query_cache: Arc<Query>, scope: Scope) -> Self {
        Invalidator { query_cache, scope }
    }

    /// The query cache it invalidates.
    pub fn query_cache(&self) -> &Arc<Query> {
        &self.query_cache
    }

    /// `handle()`: invalidates under its own scope.
    pub async fn handle(&self, event: Event, data: &[Target]) -> Result<()> {
        self.invalidate(event, data, &self.scope).await
    }

    /// `invalidate()`: block, then activate.
    pub async fn invalidate(&self, event: Event, data: &[Target], scope: &Scope) -> Result<()> {
        let _ = (event, data, scope);
        todo!("cache area: Invalidator::invalidate")
    }

    /// The tokens of the collections `data` touches, by collection key
    /// (`tokens()`); with `tenant_per_document`, each document's collections
    /// are keyed under its own tenant, or the scope's.
    pub fn tokens(&self, event: Event, data: &[Target], scope: Option<&Scope>, tenant_per_document: bool) -> Tokens {
        let _ = (event, data, scope, tenant_per_document);
        todo!("cache area: Invalidator::tokens")
    }

    /// `block()`.
    pub async fn block(&self, tokens: &Tokens) -> Result<()> {
        let _ = tokens;
        todo!("cache area: Invalidator::block")
    }

    /// `activate()`: every token, then the first failure.
    pub async fn activate(&self, tokens: &Tokens, writer_timeout: i64) -> Result<()> {
        let _ = (tokens, writer_timeout);
        todo!("cache area: Invalidator::activate")
    }

    /// Whether `event` changes what reads return (`isMutation()`).
    pub fn is_mutation(&self, event: Event) -> bool {
        let _ = event;
        todo!("cache area: Invalidator::isMutation")
    }
}
