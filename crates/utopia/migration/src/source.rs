//! Where resources come from.

use std::sync::Arc;

use async_trait::async_trait;
use indexmap::IndexMap;
use php_std::Value;

use crate::cache::Cache;
use crate::error::Result;
use crate::resource::{ResourceRef, is_supported, status, types};
use crate::target::Target;
use crate::transfer::{EXPORT_GROUPS, Report, ResourceSelector};

/// Receives batches of exported resources (the destination's import).
#[async_trait]
pub trait Sink: Send {
    /// Takes one batch.
    async fn push(&mut self, resources: Vec<ResourceRef>) -> Result<()>;
}

/// Hands a source's exported batches to the run's sink: resources of types
/// that were not requested are marked skipped, and the rest (except rows
/// and documents, which the destination counts) are cached after the sink
/// took the batch.
///
/// PHP: the `transferCallback` `Source::run()` installs, which
/// `Source::callback()` calls.
pub struct Emitter<'a> {
    sink: &'a mut dyn Sink,
    resources: Vec<String>,
    cache: Option<Arc<Cache>>,
}

impl<'a> Emitter<'a> {
    /// An emitter of a run of `resources` into `sink`.
    pub fn new(sink: &'a mut dyn Sink, resources: &[String], cache: Option<Arc<Cache>>) -> Self {
        Self { sink, resources: resources.to_vec(), cache }
    }

    /// The requested resource types.
    pub fn resources(&self) -> &[String] {
        &self.resources
    }

    /// Hands `batch` to the sink.
    ///
    /// PHP: `Source::callback($resources)`.
    pub async fn emit(&mut self, batch: Vec<ResourceRef>) -> Result<()> {
        let mut pruned = Vec::new();
        for r in &batch {
            if !self.resources.iter().any(|t| t == r.name()) {
                r.set_status(status::SKIPPED, "");
            } else if r.name() != types::ROW && r.name() != types::DOCUMENT {
                pruned.push(r.clone());
            }
        }
        self.sink.push(batch).await?;
        if let Some(cache) = &self.cache {
            cache.add_all(&pruned);
        }
        Ok(())
    }
}

/// Resource ids of a report, by root resource type.
pub type ResourceIds = IndexMap<String, Value>;

/// A service resources are exported from.
///
/// A source exports in groups ([`crate::transfer::group`]): each group it
/// supports in [`Source::export_group`], emitting batches through the
/// [`Emitter`]. Errors of a group are usually collected
/// ([`Target::add_error`]) rather than returned, so the transfer goes on.
///
/// PHP: `Utopia\Migration\Source` (the ten `exportGroup*()` methods are
/// [`Source::export_group`]; the ten `get*BatchSize()` methods
/// [`Source::batch_size`]).
#[async_trait]
pub trait Source: crate::target::AsAny + Send {
    /// The source's name (`Appwrite`, `CSV`, ...).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The resource types it can export.
    ///
    /// PHP: `getSupportedResources()`.
    fn supported_resources(&self) -> Vec<&'static str>;

    /// The shared state.
    fn target(&self) -> &Target;

    /// The shared state, mutably.
    fn target_mut(&mut self) -> &mut Target;

    /// Counts the resources available for transfer (and checks the service
    /// is reachable), for root resources limited to `resource_ids`.
    ///
    /// PHP: `report($resources, $resourceIds)`.
    async fn report(&mut self, resources: &[String], resource_ids: &ResourceIds) -> Result<Report>;

    /// Whether the source reports the provisioning status of databases.
    ///
    /// PHP: `supportsDatabaseStatus()`.
    fn supports_database_status(&self) -> bool {
        false
    }

    /// The batch size of `group` (100 unless a source says otherwise).
    ///
    /// PHP: `getAuthBatchSize()`, `getDatabasesBatchSize()`, ... .
    fn batch_size(&self, group: &str) -> i64 {
        let _ = group;
        100
    }

    /// Exports the requested `resources` of `group`.
    ///
    /// PHP: `exportGroupAuth()`, `exportGroupDatabases()`, ... .
    async fn export_group(
        &mut self,
        group: &str,
        batch_size: i64,
        resources: &[String],
        out: &mut Emitter<'_>,
    ) -> Result<()>;

    /// Exports `resources` into `sink`, limited to the root resource
    /// `root_resource_id` (of `root_resource_type`) when one is given.
    ///
    /// PHP: `run($resources, $callback, $rootResourceId, $rootResourceType)`.
    async fn run(
        &mut self,
        resources: &[String],
        sink: &mut dyn Sink,
        root_resource_id: &str,
        root_resource_type: &str,
    ) -> Result<()> {
        run(self, resources, sink, root_resource_id, root_resource_type).await
    }

    /// Exports `resources` of the resource `selector` names.
    ///
    /// PHP: `runWithResourceSelector()`.
    async fn run_with_resource_selector(
        &mut self,
        resources: &[String],
        sink: &mut dyn Sink,
        selector: &ResourceSelector,
    ) -> Result<()> {
        self.run(resources, sink, selector.scope_id(), selector.scope_type()).await
    }

    /// Exports each group `resources` touch, in group order.
    ///
    /// PHP: `exportResources($resources)`.
    async fn export_resources(&mut self, resources: &[String], out: &mut Emitter<'_>) -> Result<()> {
        export_resources(self, resources, out).await
    }

    /// Called when the transfer completes.
    ///
    /// PHP: `shutdown()`.
    async fn shutdown(&mut self) -> Result<()> {
        Ok(())
    }

    /// Called when the transfer succeeded.
    ///
    /// PHP: `success()`.
    async fn success(&mut self) -> Result<()> {
        Ok(())
    }

    /// Called when the transfer failed.
    ///
    /// PHP: `error()`.
    async fn error(&mut self) -> Result<()> {
        Ok(())
    }

    /// Called to release resources.
    ///
    /// PHP: `cleanUp()`.
    async fn clean_up(&mut self) -> Result<()> {
        Ok(())
    }
}

/// The default [`Source::run`]: sets the root resource for the run (and
/// restores the previous one after), and exports through an [`Emitter`].
pub async fn run<S: Source + ?Sized>(
    source: &mut S,
    resources: &[String],
    sink: &mut dyn Sink,
    root_resource_id: &str,
    root_resource_type: &str,
) -> Result<()> {
    let target = source.target_mut();
    let previous = (
        std::mem::replace(&mut target.root_resource_id, root_resource_id.to_owned()),
        std::mem::replace(&mut target.root_resource_type, root_resource_type.to_owned()),
    );
    let cache = source.target().cache().ok();
    let mut out = Emitter::new(sink, resources, cache);
    let result = source.export_resources(resources, &mut out).await;
    let target = source.target_mut();
    target.root_resource_id = previous.0;
    target.root_resource_type = previous.1;
    result
}

/// The default [`Source::export_resources`].
pub async fn export_resources<S: Source + ?Sized>(
    source: &mut S,
    resources: &[String],
    out: &mut Emitter<'_>,
) -> Result<()> {
    let mut groups: IndexMap<&'static str, Vec<String>> = IndexMap::new();
    for resource in resources {
        for (group, members) in EXPORT_GROUPS {
            if members.contains(&resource.as_str()) {
                groups.entry(group).or_default().push(resource.clone());
                break;
            }
        }
    }
    for (group, list) in groups {
        let batch = source.batch_size(group);
        source.export_group(group, batch, &list, out).await?;
    }
    Ok(())
}

/// Whether any of `types` is requested in `resources` (with legacy names).
pub fn requested(types: &[&str], resources: &[String]) -> bool {
    is_supported(types, resources)
}
