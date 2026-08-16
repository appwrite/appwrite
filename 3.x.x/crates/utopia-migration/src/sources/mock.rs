//! In-memory source used by unit tests. PHP `Utopia\Tests\Unit\Adapters\MockSource`.

use std::collections::HashMap;

use crate::exception::Exception;
use crate::resource::{
    database_entity_type, AnyResource, Resource, ALL_RESOURCES, TYPE_COLLECTION, TYPE_DOCUMENT,
};
use crate::source::{source_run, source_run_with_resource_selector, Source, SourceCommon};
use crate::target::{Target, TargetState};
use crate::transfer::{
    GROUP_AUTH, GROUP_AUTH_RESOURCES, GROUP_BACKUPS, GROUP_BACKUPS_RESOURCES, GROUP_DATABASES,
    GROUP_DATABASES_RESOURCES, GROUP_DOMAINS, GROUP_DOMAINS_RESOURCES, GROUP_FUNCTIONS,
    GROUP_FUNCTIONS_RESOURCES, GROUP_INTEGRATIONS, GROUP_INTEGRATIONS_RESOURCES, GROUP_MESSAGING,
    GROUP_MESSAGING_RESOURCES, GROUP_PROJECTS, GROUP_PROJECTS_RESOURCES, GROUP_SITES,
    GROUP_SITES_RESOURCES, GROUP_STORAGE, GROUP_STORAGE_RESOURCES, ROOT_RESOURCES,
};

#[derive(Default)]
pub struct MockSource {
    common: SourceCommon,
    mock_resources: HashMap<String, HashMap<String, HashMap<String, AnyResource>>>,
    resource_child_id: Option<String>,
    pub run_count: usize,
    pub last_selector: Option<crate::resource_selector::ResourceSelector>,
    pub supports_database_status: bool,
}

impl MockSource {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn push_mock_resource(&mut self, resource: impl Into<AnyResource>) {
        let resource = resource.into();
        self.mock_resources
            .entry(resource.get_group().to_owned())
            .or_default()
            .entry(resource.get_name().to_owned())
            .or_default()
            .insert(resource.get_id().to_owned(), resource);
    }

    #[must_use]
    pub fn get_mock_resource_by_id(
        &self,
        group: &str,
        type_: &str,
        id: &str,
    ) -> Option<AnyResource> {
        self.mock_resources
            .get(group)
            .and_then(|g| g.get(type_))
            .and_then(|t| t.get(id))
            .cloned()
    }

    #[must_use]
    pub fn get_mock_resources_by_type(&self, group: &str, type_: &str) -> Vec<AnyResource> {
        self.mock_resources
            .get(group)
            .and_then(|g| g.get(type_))
            .map(|t| t.values().cloned().collect())
            .unwrap_or_default()
    }

    fn handle_resource_transfer(
        &self,
        group: &str,
        type_: &str,
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        let root_id = &self.common.state.root_resource_id;
        let root_type = &self.common.state.root_resource_type;
        if ROOT_RESOURCES.contains(&type_) && !root_id.is_empty() {
            if let Some(r) = self.get_mock_resource_by_id(group, type_, root_id) {
                emit(vec![r]);
            }
            return;
        }
        let entity_type = database_entity_type(root_type);
        let child = self.resource_child_id.as_deref().unwrap_or("");
        if entity_type == Some(type_) && !root_id.is_empty() && !child.is_empty() {
            if let Some(r) = self.get_mock_resource_by_id(group, type_, child) {
                emit(vec![r]);
            }
            return;
        }
        emit(self.get_mock_resources_by_type(group, type_));
    }
}

impl Target for MockSource {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for MockSource {
    fn name() -> &'static str {
        "MockSource"
    }
    fn supported_resources() -> &'static [&'static str] {
        ALL_RESOURCES
    }
    fn previous_report(&self) -> &HashMap<String, i64> {
        &self.common.previous_report
    }
    fn previous_report_mut(&mut self) -> &mut HashMap<String, i64> {
        &mut self.common.previous_report
    }
    fn supports_database_status(&self) -> bool {
        self.supports_database_status
    }

    fn run(
        &mut self,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        mut root_resource_id: &str,
        root_resource_type: &str,
    ) {
        self.run_count += 1;
        let previous_child = self.resource_child_id.clone();
        if self.resource_child_id.is_none() {
            self.resource_child_id = Some(String::new());
            if !root_resource_id.is_empty()
                && database_entity_type(root_resource_type).is_some()
                && root_resource_id.contains(':')
            {
                let mut parts = root_resource_id.splitn(2, ':');
                let root = parts.next().unwrap_or("");
                let child = parts.next().unwrap_or("");
                root_resource_id = root;
                self.resource_child_id = Some(child.to_owned());
            }
        }
        let owned_root = root_resource_id.to_owned();
        source_run(self, resources, callback, &owned_root, root_resource_type);
        self.resource_child_id = previous_child;
    }

    fn run_with_resource_selector(
        &mut self,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        resource_id: &str,
        resource_internal_id: &str,
        resource_type: &str,
        parent_resource_id: &str,
        parent_resource_internal_id: &str,
        parent_resource_type: &str,
    ) {
        self.last_selector = Some(crate::resource_selector::ResourceSelector::new(
            resource_id,
            resource_internal_id,
            resource_type,
            parent_resource_id,
            parent_resource_internal_id,
            parent_resource_type,
        ));
        let previous_child = self.resource_child_id.clone();
        self.resource_child_id = Some(if parent_resource_id.is_empty() {
            String::new()
        } else {
            resource_id.to_owned()
        });
        source_run_with_resource_selector(
            self,
            resources,
            callback,
            resource_id,
            resource_internal_id,
            resource_type,
            parent_resource_id,
            parent_resource_internal_id,
            parent_resource_type,
        );
        self.resource_child_id = previous_child;
    }

    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        Ok(HashMap::new())
    }

    fn export_group_auth(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_AUTH_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_AUTH, resource, emit);
            }
        }
    }
    fn export_group_databases(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        let wanted: Vec<&str> = resources.iter().map(String::as_str).collect();
        for resource in GROUP_DATABASES_RESOURCES {
            if crate::resource::is_supported(&[*resource], &wanted) {
                self.handle_resource_transfer(GROUP_DATABASES, resource, emit);
            }
        }
    }
    fn export_group_storage(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_STORAGE_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_STORAGE, resource, emit);
            }
        }
    }
    fn export_group_functions(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_FUNCTIONS_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_FUNCTIONS, resource, emit);
            }
        }
    }
    fn export_group_messaging(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_MESSAGING_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_MESSAGING, resource, emit);
            }
        }
    }
    fn export_group_sites(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_SITES_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_SITES, resource, emit);
            }
        }
    }
    fn export_group_integrations(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_INTEGRATIONS_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_INTEGRATIONS, resource, emit);
            }
        }
    }
    fn export_group_backups(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_BACKUPS_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_BACKUPS, resource, emit);
            }
        }
    }
    fn export_group_projects(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_PROJECTS_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_PROJECTS, resource, emit);
            }
        }
    }
    fn export_group_domains(
        &mut self,
        _batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    ) {
        for resource in GROUP_DOMAINS_RESOURCES {
            if resources.iter().any(|r| r == resource) {
                self.handle_resource_transfer(GROUP_DOMAINS, resource, emit);
            }
        }
    }
}

#[allow(dead_code)]
fn _legacy() -> [&'static str; 2] {
    [TYPE_COLLECTION, TYPE_DOCUMENT]
}
