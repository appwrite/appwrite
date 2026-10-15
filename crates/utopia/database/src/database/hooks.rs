//! Hook registration (`addHook()`, `removeHook()`), lifecycle event
//! dispatch (`listens()`, `dispatch()`), decorators and query hooks.

use std::sync::Arc;

use super::{Database, Registered};
use crate::cache::Invalidator;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::event::{Domain, Event};
use crate::hook::relationships::Relationships;
use crate::hook::{Decorator, Lifecycle, Nested, Queries, Transform, Write};
use crate::query::Query;

/// A hook to register with [`Database::add_hook`], by the kind it is
/// registered as. An object of several kinds (PHP: a class implementing
/// several hook interfaces) is registered once per kind.
///
/// The constructors record the hook's type, which
/// [`Database::remove_hook_class`] removes by (PHP's
/// `removeHook(Class::class)`).
///
/// PHP: the `Utopia\Query\Hook` given to `addHook()`, dispatched on the
/// interfaces it implements.
#[derive(Clone)]
pub enum Hook {
    /// `Hook\Lifecycle`: side effects on database events; a named one
    /// replaces the lifecycle hook registered under its name.
    Lifecycle(Arc<dyn Lifecycle>, &'static str),
    /// `Hook\Decorator`: transforms the documents reads and writes return.
    Decorator(Arc<dyn Decorator>, &'static str),
    /// `Hook\Relationships`: relationship resolution and mutation (attached
    /// to the database first).
    Relationships(Arc<Relationships>),
    /// `Hook\Nested`: prepares each nested related document of a write.
    Nested(Arc<dyn Nested>, &'static str),
    /// `Hook\Queries`: rewrites the queries of reads.
    Queries(Arc<dyn Queries>, &'static str),
    /// `Hook\Write`: row-level write interception (permissions, tenant).
    Write(Arc<dyn Write>, &'static str),
    /// `Hook\Transform`: raw SQL transformation, registered under its type.
    Transform(Arc<dyn Transform>, &'static str),
    /// `Cache\Invalidator`: the query cache invalidation, replacing the one
    /// `setQueryCache()` made.
    Invalidator(Arc<Invalidator>),
}

impl Hook {
    /// A lifecycle hook.
    pub fn lifecycle<T: Lifecycle + 'static>(hook: Arc<T>) -> Hook {
        Hook::Lifecycle(hook, std::any::type_name::<T>())
    }

    /// A decorator.
    pub fn decorator<T: Decorator + 'static>(hook: Arc<T>) -> Hook {
        Hook::Decorator(hook, std::any::type_name::<T>())
    }

    /// A nested hook.
    pub fn nested<T: Nested + 'static>(hook: Arc<T>) -> Hook {
        Hook::Nested(hook, std::any::type_name::<T>())
    }

    /// A queries hook.
    pub fn queries<T: Queries + 'static>(hook: Arc<T>) -> Hook {
        Hook::Queries(hook, std::any::type_name::<T>())
    }

    /// A write hook.
    pub fn write<T: Write + 'static>(hook: Arc<T>) -> Hook {
        Hook::Write(hook, std::any::type_name::<T>())
    }

    /// A transform.
    pub fn transform<T: Transform + 'static>(hook: Arc<T>) -> Hook {
        Hook::Transform(hook, std::any::type_name::<T>())
    }

    /// The data pointer of the hook, what `===` compares.
    fn address(&self) -> *const () {
        match self {
            Hook::Lifecycle(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Decorator(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Relationships(h) => Arc::as_ptr(h) as *const (),
            Hook::Nested(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Queries(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Write(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Transform(h, _) => Arc::as_ptr(h) as *const (),
            Hook::Invalidator(h) => Arc::as_ptr(h) as *const (),
        }
    }
}

fn address<T: ?Sized>(hook: &Arc<T>) -> *const () {
    Arc::as_ptr(hook) as *const ()
}

impl Database {
    /// Registers a hook into the database pipeline (`addHook()`). A
    /// relationships hook is attached to this database first.
    ///
    /// Fails ([`crate::Kind::Database`]) when a relationships hook is
    /// attached to another database.
    pub fn add_hook(&self, hook: Hook) -> Result<&Self> {
        match hook {
            Hook::Lifecycle(hook, class) => {
                let mut settings = self.settings();
                let name = hook.named().map(|n| n.name().to_owned());
                if let Some(name) = name
                    && let Some(slot) =
                        settings.lifecycle_hooks.iter_mut().find(|r| r.hook.named().is_some_and(|n| n.name() == name))
                {
                    *slot = Registered { hook, class };
                    return Ok(self);
                }
                settings.lifecycle_hooks.push(Registered { hook, class });
            }
            Hook::Decorator(hook, class) => self.settings().decorators.push(Registered { hook, class }),
            Hook::Relationships(hook) => {
                hook.attach(self)?;
                self.settings().relationship_hook = Some(hook);
            }
            Hook::Nested(hook, class) => self.settings().nested_hooks.push(Registered { hook, class }),
            Hook::Queries(hook, class) => self.settings().query_hooks.push(Registered { hook, class }),
            Hook::Write(hook, _) => self.inner.adapter.add_write_hook(hook),
            Hook::Transform(hook, class) => self.inner.adapter.add_transform(class, hook),
            Hook::Invalidator(hook) => self.settings().invalidator = Some(hook),
        }
        Ok(self)
    }

    /// Unregisters a hook from wherever [`Database::add_hook`] registered it
    /// (`removeHook($hook)`): the same instance.
    pub fn remove_hook(&self, hook: &Hook) -> &Self {
        let target = hook.address();
        {
            let mut settings = self.settings();
            settings.lifecycle_hooks.retain(|r| address(&r.hook) != target);
            settings.decorators.retain(|r| address(&r.hook) != target);
            settings.nested_hooks.retain(|r| address(&r.hook) != target);
            settings.query_hooks.retain(|r| address(&r.hook) != target);
            if settings.invalidator.as_ref().is_some_and(|i| address(i) == target) {
                settings.invalidator = None;
            }
            if settings.relationship_hook.as_ref().is_some_and(|h| address(h) == target) {
                settings.relationship_hook = None;
            }
        }
        match hook {
            Hook::Write(h, _) => self.inner.adapter.remove_write_hook(h),
            Hook::Transform(_, class) => self.inner.adapter.remove_transform(class),
            _ => {}
        }
        self
    }

    /// Unregisters every hook of the type named `class` (as
    /// `std::any::type_name` names it), from wherever it was registered
    /// (`removeHook(Class::class)`). `Hook\Tenancy` write hooks are removed by
    /// [`crate::Adapter::remove_tenancy_hooks`].
    pub fn remove_hook_class(&self, class: &str) -> &Self {
        let mut settings = self.settings();
        settings.lifecycle_hooks.retain(|r| r.class != class);
        settings.decorators.retain(|r| r.class != class);
        settings.nested_hooks.retain(|r| r.class != class);
        settings.query_hooks.retain(|r| r.class != class);
        if class == std::any::type_name::<Invalidator>() {
            settings.invalidator = None;
        }
        if class == std::any::type_name::<Relationships>() {
            settings.relationship_hook = None;
        }
        if class == std::any::type_name::<crate::hook::Tenancy>() {
            self.inner.adapter.remove_tenancy_hooks();
        }
        drop(settings);
        self.inner.adapter.remove_transform(class);
        self
    }

    /// The registered lifecycle hooks that handle `event` now (`listens()`):
    /// none while events are silenced; a silenced named hook and a selective
    /// hook that does not handle the event are left out. The list is a
    /// snapshot an operation reuses for every event of its dispatch loop.
    pub(crate) fn listens(&self, event: Event) -> Vec<Arc<dyn Lifecycle>> {
        let hooks: Vec<Arc<dyn Lifecycle>> = {
            let settings = self.settings();
            if settings.lifecycle_hooks.is_empty() {
                return Vec::new();
            }
            settings.lifecycle_hooks.iter().map(|r| r.hook.clone()).collect()
        };
        if self.are_events_silenced() {
            return Vec::new();
        }
        let silenced = self.silenced_listeners();
        hooks
            .into_iter()
            .filter(|hook| {
                if let Some(named) = hook.named()
                    && silenced.iter().any(|s| s == named.name())
                {
                    return false;
                }
                hook.selective().is_none_or(|s| s.handles(event))
            })
            .collect()
    }

    /// Hands a typed event to the hooks [`Database::listens`] returned for
    /// it (`dispatch()`). A hook's error reaches the caller for the events
    /// that propagate hook failures; for the others it is swallowed and the
    /// remaining hooks run. An engine error always reaches the caller.
    pub(crate) async fn dispatch(&self, event: &Domain, listeners: &[Arc<dyn Lifecycle>]) -> Result<()> {
        let propagates = propagates_hook_failures(event.event());
        for hook in listeners {
            if let Err(e) = hook.handle(event).await
                && (propagates || e.is_engine_error())
            {
                return Err(e);
            }
        }
        Ok(())
    }

    /// Hands a typed event to the hooks and lets the first hook error reach
    /// the caller whatever the event (`dispatchPropagating()`).
    pub(crate) async fn dispatch_propagating(&self, event: &Domain, listeners: &[Arc<dyn Lifecycle>]) -> Result<()> {
        for hook in listeners {
            hook.handle(event).await?;
        }
        Ok(())
    }

    /// Dispatches the event built by `event` to the hooks that handle
    /// `kind`, building it only when one does (the `listens()` + `dispatch()`
    /// pair of every trigger site).
    pub(crate) async fn trigger(&self, kind: Event, event: impl FnOnce() -> Domain) -> Result<()> {
        let listeners = self.listens(kind);
        if listeners.is_empty() {
            return Ok(());
        }
        self.dispatch(&event(), &listeners).await
    }

    /// `document` as every decorator transforms it for `event`
    /// (`decorateDocument()`); unchanged while events are silenced.
    pub(crate) async fn decorate_document(
        &self,
        event: Event,
        collection: &Collection,
        mut document: Document,
    ) -> Result<Document> {
        let decorators: Vec<Arc<dyn Decorator>> = self.settings().decorators.iter().map(|r| r.hook.clone()).collect();
        if decorators.is_empty() || self.are_events_silenced() {
            return Ok(document);
        }
        for decorator in decorators {
            document = decorator.decorate(event, collection, document).await?;
        }
        Ok(document)
    }

    /// Each of `documents` decorated (`decorateDocuments()`).
    pub(crate) async fn decorate_documents(
        &self,
        event: Event,
        collection: &Collection,
        documents: Vec<Document>,
    ) -> Result<Vec<Document>> {
        if self.settings().decorators.is_empty() || self.are_events_silenced() {
            return Ok(documents);
        }
        let mut out = Vec::with_capacity(documents.len());
        for document in documents {
            out.push(self.decorate_document(event, collection, document).await?);
        }
        Ok(out)
    }

    /// Whether any decorator is registered.
    pub(crate) fn has_decorators(&self) -> bool {
        !self.settings().decorators.is_empty()
    }

    /// The registered nested hooks, in registration order.
    pub(crate) fn nested_hooks(&self) -> Vec<Arc<dyn Nested>> {
        self.settings().nested_hooks.iter().map(|r| r.hook.clone()).collect()
    }

    /// The queries of a read as the query hooks rewrite them
    /// (`rewriteQueries()`): each hook given the previous one's output, in
    /// registration order; unchanged under [`Database::skip_query_hooks`].
    pub(crate) async fn rewrite_queries(
        &self,
        event: Event,
        collection: &Collection,
        mut queries: Vec<Query>,
    ) -> Result<Vec<Query>> {
        let hooks: Vec<Arc<dyn Queries>> = self.settings().query_hooks.iter().map(|r| r.hook.clone()).collect();
        if hooks.is_empty() || collection.id() == super::METADATA || !self.is_rewriting() {
            return Ok(queries);
        }
        for hook in hooks {
            queries = hook.rewrite(event, collection, queries).await?;
        }
        Ok(queries)
    }

    /// The query cache invalidation run for a lifecycle event
    /// (`invalidate()`): block, then activate.
    pub(crate) async fn invalidate(&self, event: Event, data: &[crate::cache::Target]) -> Result<()> {
        let Some(invalidator) = self.settings().invalidator.clone() else {
            return Ok(());
        };
        if !invalidator.is_mutation(event) {
            return Ok(());
        }
        let tokens = self.invalidation_tokens(event, data).await;
        invalidator.block(&tokens).await?;
        invalidator.activate(&tokens, self.cache_writer_timeout()).await
    }

    /// The tokens of the collections a mutation invalidates
    /// (`getInvalidationTokens()`), by collection key.
    pub(crate) async fn invalidation_tokens(
        &self,
        event: Event,
        data: &[crate::cache::Target],
    ) -> crate::cache::Tokens {
        let Some(invalidator) = self.settings().invalidator.clone() else {
            return crate::cache::Tokens::new();
        };
        let scope = self.query_cache_scope(None).await;
        let per_document = self.inner.adapter.shared_tables() && self.inner.adapter.tenant_per_document();
        invalidator.tokens(event, data, Some(&scope), per_document)
    }

    /// `blockInvalidation()`.
    pub(crate) async fn block_invalidation(&self, tokens: &crate::cache::Tokens) -> Result<()> {
        let invalidator = self.settings().invalidator.clone();
        match invalidator {
            Some(i) => i.block(tokens).await,
            None => Ok(()),
        }
    }

    /// `activateInvalidation()`.
    pub(crate) async fn activate_invalidation(&self, tokens: &crate::cache::Tokens) -> Result<()> {
        let invalidator = self.settings().invalidator.clone();
        match invalidator {
            Some(i) => i.activate(tokens, self.cache_writer_timeout()).await,
            None => Ok(()),
        }
    }
}

/// Whether a hook's failure on `event` reaches the caller
/// (`propagatesHookFailures()`): the events 7.x dispatched unguarded.
pub(crate) fn propagates_hook_failures(event: Event) -> bool {
    matches!(
        event,
        Event::IndexCreate
            | Event::IndexesCreate
            | Event::DocumentRead
            | Event::DocumentCreate
            | Event::DocumentsCreate
            | Event::DocumentUpdate
            | Event::DocumentsUpdate
            | Event::DocumentUpsert
            | Event::DocumentsUpsert
            | Event::DocumentIncrease
            | Event::DocumentDecrease
            | Event::DocumentDelete
            | Event::DocumentsDelete
            | Event::DocumentFind
            | Event::DocumentAggregate
            | Event::DocumentCount
            | Event::DocumentSum
    )
}
