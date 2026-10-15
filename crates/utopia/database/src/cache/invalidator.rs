//! [`Invalidator`]: the query cache invalidation a database runs for its
//! mutations.

use std::sync::Arc;

use super::{Query, Scope, Target, Tokens};
use crate::attribute::Attribute;
use crate::error::{Error, Result};
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
        let tokens = self.tokens(event, data, Some(scope), false);
        self.block(&tokens).await?;
        self.activate(&tokens, scope.writer_timeout).await
    }

    /// The tokens of the collections `data` touches, by collection key
    /// (`tokens()`); with `tenant_per_document`, each document's collections
    /// are keyed under the tenant it is stored under: its own, or the
    /// scope's when it has none.
    ///
    /// PHP lets a relationship attribute whose options cannot be read
    /// throw out of here; such an attribute names no related collection.
    pub fn tokens(&self, event: Event, data: &[Target], scope: Option<&Scope>, tenant_per_document: bool) -> Tokens {
        if !self.is_mutation(event) {
            return Tokens::new();
        }
        let scope = scope.unwrap_or(&self.scope);
        if !tenant_per_document {
            return self.scoped_tokens(event, data.iter(), scope);
        }

        // Grouped by `serialize($tenant)`, in first-seen order.
        let mut groups: Vec<(Vec<u8>, Scope, Vec<&Target>)> = Vec::new();
        for target in data {
            let tenant = match target {
                Target::Document(d) => d.tenant().unwrap_or_else(|| scope.tenant.clone()),
                Target::Collection(_) => scope.tenant.clone(),
            };
            let key = php_std::serialize::serialize(&tenant);
            match groups.iter_mut().find(|(k, _, _)| *k == key) {
                Some(group) => group.2.push(target),
                None => groups.push((key, scope.with_tenant(tenant), vec![target])),
            }
        }

        let mut tokens = Tokens::new();
        for (_, tenant_scope, targets) in &groups {
            for (key, token) in self.scoped_tokens(event, targets.iter().copied(), tenant_scope) {
                // `$tokens += ...`: the first token of a key stays.
                if !tokens.iter().any(|(k, _)| *k == key) {
                    tokens.push((key, token));
                }
            }
        }
        tokens
    }

    /// `block()`.
    pub async fn block(&self, tokens: &Tokens) -> Result<()> {
        for (key, token) in tokens {
            self.query_cache.block_collection(key, token).await?;
        }
        Ok(())
    }

    /// `activate()`: every token, then the first failure.
    /// `writer_timeout` is the writer timeout of the database the tokens
    /// were created through.
    pub async fn activate(&self, tokens: &Tokens, writer_timeout: i64) -> Result<()> {
        let mut failure: Option<Error> = None;
        for (key, token) in tokens {
            if let Err(e) = self.query_cache.activate_collection(key, token, writer_timeout).await {
                failure.get_or_insert(e);
            }
        }
        failure.map_or(Ok(()), Err)
    }

    /// Whether `event` changes what reads return (`isMutation()`).
    pub fn is_mutation(&self, event: Event) -> bool {
        matches!(
            event,
            Event::CollectionCreate
                | Event::CollectionUpdate
                | Event::CollectionDelete
                | Event::AttributeCreate
                | Event::AttributesCreate
                | Event::AttributeUpdate
                | Event::AttributeRename
                | Event::AttributeDelete
                | Event::IndexCreate
                | Event::IndexesCreate
                | Event::IndexRename
                | Event::IndexDelete
                | Event::DocumentPurge
                | Event::DocumentCreate
                | Event::DocumentsCreate
                | Event::DocumentUpdate
                | Event::DocumentsUpdate
                | Event::DocumentUpsert
                | Event::DocumentsUpsert
                | Event::DocumentDelete
                | Event::DocumentsDelete
                | Event::DocumentIncrease
                | Event::DocumentDecrease
                | Event::PermissionsCreate
                | Event::PermissionsDelete
        )
    }

    /// `scopedTokens()`: a fresh token per collection the targets touch.
    fn scoped_tokens<'a>(&self, event: Event, data: impl Iterator<Item = &'a Target>, scope: &Scope) -> Tokens {
        let mut collections: Vec<String> = Vec::new();
        for target in data {
            for collection in extract_collections(event, target) {
                if !collections.contains(&collection) {
                    collections.push(collection);
                }
            }
        }
        let mut tokens = Tokens::new();
        for collection in collections {
            let key = self.query_cache.collection_key(scope, &collection);
            let token = self.query_cache.create_token();
            match tokens.iter_mut().find(|(k, _)| *k == key) {
                Some(entry) => entry.1 = token,
                None => tokens.push((key, token)),
            }
        }
        tokens
    }
}

/// Only an attribute event's relationship options name a related
/// collection: a written document's own `options` attribute is data
/// (`isAttributeMutation()`).
fn is_attribute_mutation(event: Event) -> bool {
    matches!(
        event,
        Event::AttributeCreate
            | Event::AttributesCreate
            | Event::AttributeUpdate
            | Event::AttributeRename
            | Event::AttributeDelete
    )
}

/// The collections a target touches, in order (`extractCollections()`).
pub(crate) fn extract_collections(event: Event, target: &Target) -> Vec<String> {
    let mut collections = Vec::new();
    match target {
        Target::Document(document) => {
            // A collection event's document is a definition, as is any
            // document of the metadata collection.
            let collection =
                if matches!(event, Event::CollectionCreate | Event::CollectionUpdate | Event::CollectionDelete)
                    || document.collection() == crate::database::METADATA
                {
                    document.id()
                } else {
                    document.collection()
                };
            if !collection.is_empty() {
                collections.push(collection.to_owned());
            }
            if !is_attribute_mutation(event) || !Attribute::is_relationship_document(document) {
                return collections;
            }
            if let Ok(attribute) = Attribute::from_document(document)
                && let Some(relationship) = attribute.relationship
                && !collections.contains(&relationship.related_collection)
            {
                collections.push(relationship.related_collection);
            }
        }
        Target::Collection(id) if !id.is_empty() => collections.push(id.clone()),
        Target::Collection(_) => {}
    }
    collections
}
