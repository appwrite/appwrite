//! In-memory destination used by unit tests. PHP `MockDestination`.

use std::collections::HashMap;

use crate::destination::{dest_run, Destination, DestinationCommon};
use crate::exception::Exception;
use crate::resource::{AnyResource, Resource, ALL_RESOURCES, STATUS_SUCCESS};
use crate::resource_selector::ResourceSelector;
use crate::source::Source;
use crate::target::{Target, TargetState};

#[derive(Default)]
pub struct MockDestination {
    common: DestinationCommon,
    pub data: HashMap<String, HashMap<String, HashMap<String, AnyResource>>>,
    pub run_count: usize,
}

impl MockDestination {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    #[must_use]
    pub fn get_resource_type_data(&self, group: &str, resource_type: &str) -> Vec<String> {
        self.data
            .get(group)
            .and_then(|g| g.get(resource_type))
            .map(|t| t.keys().cloned().collect())
            .unwrap_or_default()
    }

    #[must_use]
    pub fn get_resource_by_id(
        &self,
        group: &str,
        resource_type: &str,
        resource_id: &str,
    ) -> Option<&AnyResource> {
        self.data
            .get(group)
            .and_then(|g| g.get(resource_type))
            .and_then(|t| t.get(resource_id))
    }
}

impl Target for MockDestination {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Destination for MockDestination {
    fn name() -> &'static str {
        "MockDestination"
    }
    fn supported_resources() -> &'static [&'static str] {
        ALL_RESOURCES
    }

    fn run(
        &mut self,
        source: &mut dyn Source,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        root_resource_id: &str,
        root_resource_type: &str,
    ) {
        self.run_count += 1;
        dest_run(
            self,
            source,
            resources,
            callback,
            root_resource_id,
            root_resource_type,
        );
    }

    fn selector(&self) -> Option<&ResourceSelector> {
        self.common.selector.as_ref()
    }
    fn set_selector(&mut self, selector: Option<ResourceSelector>) {
        self.common.selector = selector;
    }

    fn import(
        &mut self,
        mut resources: Vec<AnyResource>,
        callback: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in &mut resources {
            resource.set_status(STATUS_SUCCESS, "");
            self.data
                .entry(resource.get_group().to_owned())
                .or_default()
                .entry(resource.get_name().to_owned())
                .or_default()
                .insert(resource.get_id().to_owned(), resource.clone());
            if let Some(cache) = self.state().cache() {
                if let Ok(mut guard) = cache.lock() {
                    guard.update(resource);
                }
            }
        }
        callback(resources);
    }

    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        Ok(HashMap::new())
    }
}
