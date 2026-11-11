//! Where resources go.

use async_trait::async_trait;

use crate::error::Result;
use crate::resource::ResourceRef;
use crate::source::{ResourceIds, Sink, Source};
use crate::target::{Destinations, Resolver, Target};
use crate::transfer::{Report, ResourceSelector};

/// Called with each batch a destination imported.
pub type Callback<'a> = dyn FnMut(&[ResourceRef]) -> Result<()> + Send + 'a;

/// A service resources are imported into.
///
/// PHP: `Utopia\Migration\Destination`. PHP's destination holds its source
/// (`setSource()`, `getSource()`); here the transfer owns both and hands the
/// source to [`Destination::run`].
#[async_trait]
pub trait Destination: crate::target::AsAny + Send {
    /// The destination's name.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The resource types it can import.
    ///
    /// PHP: `getSupportedResources()`.
    fn supported_resources(&self) -> Vec<&'static str>;

    /// The shared state.
    fn target(&self) -> &Target;

    /// The shared state, mutably.
    fn target_mut(&mut self) -> &mut Target;

    /// Whether its HTTP calls follow redirects (they do not by default).
    ///
    /// PHP: `setFollowRedirects()`.
    fn set_follow_redirects(&mut self, follow: bool) {
        self.target_mut().set_follow_redirects(follow);
    }

    /// Pins its HTTP calls to the addresses `resolver` returns for each URL.
    ///
    /// PHP: `setResolver()`.
    fn set_resolver(&mut self, resolver: Option<Resolver>) {
        self.target_mut().set_resolver(resolver);
    }

    /// Confines its HTTP calls to `destinations`.
    ///
    /// PHP: `setDestinations()`.
    fn set_destinations(&mut self, destinations: Destinations) {
        self.target_mut().set_destinations(destinations);
    }

    /// Checks the destination can take `resources` (an error when it
    /// cannot); destinations report no counts.
    ///
    /// PHP: `report()`.
    async fn report(&mut self, resources: &[String], resource_ids: &ResourceIds) -> Result<Report>;

    /// Imports one batch and hands it to `callback`.
    ///
    /// PHP: `import($resources, $callback)`.
    async fn import(&mut self, resources: &[ResourceRef], callback: &mut Callback<'_>) -> Result<()>;

    /// Runs `source` into this destination.
    ///
    /// PHP: `run($resources, $callback, $rootResourceId, $rootResourceType)`.
    async fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut Callback<'_>,
        root_resource_id: &str,
        root_resource_type: &str,
    ) -> Result<()> {
        run(self, source, resources, callback, root_resource_id, root_resource_type).await
    }

    /// Runs `source` into this destination for the resource `selector` names.
    ///
    /// PHP: `runWithResourceSelector()`.
    async fn run_with_resource_selector(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut Callback<'_>,
        selector: &ResourceSelector,
    ) -> Result<()> {
        let previous = self.target_mut().resource_selector.replace(selector.clone());
        let result = self.run(source, resources, callback, selector.scope_id(), selector.scope_type()).await;
        self.target_mut().resource_selector = previous;
        result
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

struct Import<'a, 'b, D: Destination + ?Sized> {
    destination: &'a mut D,
    callback: &'a mut Callback<'b>,
}

#[async_trait]
impl<D: Destination + ?Sized> Sink for Import<'_, '_, D> {
    async fn push(&mut self, resources: Vec<ResourceRef>) -> Result<()> {
        self.destination.import(&resources, self.callback).await
    }
}

/// The default [`Destination::run`]: the source exports into this
/// destination's import, through the selector when one is set.
pub async fn run<D: Destination + ?Sized>(
    destination: &mut D,
    source: &mut dyn Source,
    resources: &[String],
    callback: &mut Callback<'_>,
    root_resource_id: &str,
    root_resource_type: &str,
) -> Result<()> {
    let selector = destination.target().resource_selector.clone();
    let mut sink = Import { destination, callback };
    match selector {
        Some(selector) => source.run_with_resource_selector(resources, &mut sink, &selector).await,
        None => source.run(resources, &mut sink, root_resource_id, root_resource_type).await,
    }
}
