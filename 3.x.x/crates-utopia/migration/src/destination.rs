use std::collections::HashMap;

use crate::exception::Exception;
use crate::resource::AnyResource;
use crate::resource_selector::ResourceSelector;
use crate::source::Source;
use crate::target::{Target, TargetState};

/// PHP `Utopia\Migration\Destination`.
pub trait Destination: Target {
    fn name() -> &'static str
    where
        Self: Sized;
    fn supported_resources() -> &'static [&'static str]
    where
        Self: Sized;

    fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        root_resource_id: &str,
        root_resource_type: &str,
    ) {
        dest_run(
            self,
            source,
            resources,
            callback,
            root_resource_id,
            root_resource_type,
        );
    }

    #[allow(clippy::too_many_arguments)]
    fn run_with_resource_selector(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        resource_id: &str,
        resource_internal_id: &str,
        resource_type: &str,
        parent_resource_id: &str,
        parent_resource_internal_id: &str,
        parent_resource_type: &str,
    ) {
        let previous = self.selector().cloned();
        let selector = ResourceSelector::new(
            resource_id,
            resource_internal_id,
            resource_type,
            parent_resource_id,
            parent_resource_internal_id,
            parent_resource_type,
        );
        let scope_id = selector.get_scope_id().to_owned();
        let scope_type = selector.get_scope_type().to_owned();
        self.set_selector(Some(selector));
        self.run(source, resources, callback, &scope_id, &scope_type);
        self.set_selector(previous);
    }

    fn selector(&self) -> Option<&ResourceSelector>;
    fn set_selector(&mut self, selector: Option<ResourceSelector>);
    fn set_source_supports_database_status(&mut self, _supports: bool) {}
    fn import(&mut self, resources: Vec<AnyResource>, callback: &mut dyn FnMut(Vec<AnyResource>));
    fn report(
        &mut self,
        resources: &[&str],
        resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception>;
}

pub fn dest_run<D: Destination + ?Sized>(
    dest: &mut D,
    source: &mut dyn Source,
    resources: &[String],
    callback: &mut dyn FnMut(Vec<AnyResource>),
    root_resource_id: &str,
    root_resource_type: &str,
) {
    dest.set_source_supports_database_status(source.supports_database_status());
    if let Some(selector) = dest.selector().cloned() {
        source.run_with_resource_selector(
            resources,
            &mut |resources| dest.import(resources, callback),
            &selector.resource_id,
            &selector.resource_internal_id,
            &selector.resource_type,
            &selector.parent_resource_id,
            &selector.parent_resource_internal_id,
            &selector.parent_resource_type,
        );
        return;
    }
    source.run(
        resources,
        &mut |resources| dest.import(resources, callback),
        root_resource_id,
        root_resource_type,
    );
}

pub struct DestinationCommon {
    pub state: TargetState,
    pub selector: Option<ResourceSelector>,
}

impl Default for DestinationCommon {
    fn default() -> Self {
        Self {
            state: TargetState::new(),
            selector: None,
        }
    }
}
