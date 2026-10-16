use std::collections::HashMap;

use crate::exception::Exception;
use crate::resource::{
    is_supported, AnyResource, Resource, STATUS_SKIPPED, TYPE_DOCUMENT, TYPE_ROW,
};
use crate::resource_selector::ResourceSelector;
use crate::target::{Target, TargetState};
use crate::transfer::{
    GROUP_AUTH, GROUP_AUTH_RESOURCES, GROUP_BACKUPS, GROUP_BACKUPS_RESOURCES, GROUP_DATABASES,
    GROUP_DATABASES_RESOURCES, GROUP_DOMAINS, GROUP_DOMAINS_RESOURCES, GROUP_FUNCTIONS,
    GROUP_FUNCTIONS_RESOURCES, GROUP_INTEGRATIONS, GROUP_INTEGRATIONS_RESOURCES, GROUP_MESSAGING,
    GROUP_MESSAGING_RESOURCES, GROUP_PROJECTS, GROUP_PROJECTS_RESOURCES, GROUP_SITES,
    GROUP_SITES_RESOURCES, GROUP_STORAGE, GROUP_STORAGE_RESOURCES,
};

/// PHP `Utopia\Migration\Source`.
pub trait Source: Target {
    fn name() -> &'static str
    where
        Self: Sized;
    fn supported_resources() -> &'static [&'static str]
    where
        Self: Sized;

    fn previous_report(&self) -> &HashMap<String, i64>;
    fn previous_report_mut(&mut self) -> &mut HashMap<String, i64>;

    fn supports_database_status(&self) -> bool {
        false
    }

    fn get_auth_batch_size(&self) -> usize {
        100
    }
    fn get_databases_batch_size(&self) -> usize {
        100
    }
    fn get_storage_batch_size(&self) -> usize {
        100
    }
    fn get_functions_batch_size(&self) -> usize {
        100
    }
    fn get_messaging_batch_size(&self) -> usize {
        100
    }
    fn get_sites_batch_size(&self) -> usize {
        100
    }
    fn get_integrations_batch_size(&self) -> usize {
        100
    }
    fn get_backups_batch_size(&self) -> usize {
        100
    }
    fn get_projects_batch_size(&self) -> usize {
        100
    }
    fn get_domains_batch_size(&self) -> usize {
        100
    }

    fn run(
        &mut self,
        resources: &[String],
        callback: &mut dyn FnMut(Vec<AnyResource>),
        root_resource_id: &str,
        root_resource_type: &str,
    ) {
        source_run(
            self,
            resources,
            callback,
            root_resource_id,
            root_resource_type,
        );
    }

    #[allow(clippy::too_many_arguments)]
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
    }

    fn export_resources(&mut self, resources: &[String], emit: &mut dyn FnMut(Vec<AnyResource>)) {
        let mapping: &[(&str, &[&str])] = &[
            (GROUP_AUTH, GROUP_AUTH_RESOURCES),
            (GROUP_DATABASES, GROUP_DATABASES_RESOURCES),
            (GROUP_STORAGE, GROUP_STORAGE_RESOURCES),
            (GROUP_FUNCTIONS, GROUP_FUNCTIONS_RESOURCES),
            (GROUP_MESSAGING, GROUP_MESSAGING_RESOURCES),
            (GROUP_SITES, GROUP_SITES_RESOURCES),
            (GROUP_INTEGRATIONS, GROUP_INTEGRATIONS_RESOURCES),
            (GROUP_BACKUPS, GROUP_BACKUPS_RESOURCES),
            (GROUP_PROJECTS, GROUP_PROJECTS_RESOURCES),
            (GROUP_DOMAINS, GROUP_DOMAINS_RESOURCES),
        ];
        let mut groups: HashMap<&str, Vec<String>> = HashMap::new();
        for resource in resources {
            for (group, list) in mapping {
                if list.contains(&resource.as_str()) {
                    groups.entry(*group).or_default().push(resource.clone());
                    break;
                }
            }
        }
        if groups.is_empty() {
            return;
        }
        for (group, res) in groups {
            match group {
                g if g == GROUP_AUTH => {
                    self.export_group_auth(self.get_auth_batch_size(), &res, emit);
                }
                g if g == GROUP_DATABASES => {
                    self.export_group_databases(self.get_databases_batch_size(), &res, emit);
                }
                g if g == GROUP_STORAGE => {
                    self.export_group_storage(self.get_storage_batch_size(), &res, emit);
                }
                g if g == GROUP_FUNCTIONS => {
                    self.export_group_functions(self.get_functions_batch_size(), &res, emit);
                }
                g if g == GROUP_MESSAGING => {
                    self.export_group_messaging(self.get_messaging_batch_size(), &res, emit);
                }
                g if g == GROUP_SITES => {
                    self.export_group_sites(self.get_sites_batch_size(), &res, emit);
                }
                g if g == GROUP_INTEGRATIONS => {
                    self.export_group_integrations(self.get_integrations_batch_size(), &res, emit);
                }
                g if g == GROUP_BACKUPS => {
                    self.export_group_backups(self.get_backups_batch_size(), &res, emit);
                }
                g if g == GROUP_PROJECTS => {
                    self.export_group_projects(self.get_projects_batch_size(), &res, emit);
                }
                g if g == GROUP_DOMAINS => {
                    self.export_group_domains(self.get_domains_batch_size(), &res, emit);
                }
                _ => {}
            }
        }
    }

    fn export_group_auth(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_databases(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_storage(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_functions(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_messaging(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_sites(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_integrations(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_backups(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_projects(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );
    fn export_group_domains(
        &mut self,
        batch_size: usize,
        resources: &[String],
        emit: &mut dyn FnMut(Vec<AnyResource>),
    );

    fn report(
        &mut self,
        resources: &[&str],
        resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception>;
}

/// PHP `Source::run` body, extracted so adapters can wrap it without recursion.
pub fn source_run<S: Source + ?Sized>(
    source: &mut S,
    resources: &[String],
    callback: &mut dyn FnMut(Vec<AnyResource>),
    root_resource_id: &str,
    root_resource_type: &str,
) {
    let previous_id = source.state().root_resource_id.clone();
    let previous_type = source.state().root_resource_type.clone();
    root_resource_id.clone_into(&mut source.state_mut().root_resource_id);
    root_resource_type.clone_into(&mut source.state_mut().root_resource_type);

    let wanted: Vec<String> = resources.to_vec();
    let cache = source.state().cache();
    let mut wrapped = |returned: Vec<AnyResource>| {
        let mut pruned = Vec::new();
        for mut resource in returned.clone() {
            if !wanted.iter().any(|n| n == resource.get_name()) {
                resource.set_status(STATUS_SKIPPED, "");
            }
            if resource.get_name() != TYPE_ROW && resource.get_name() != TYPE_DOCUMENT {
                pruned.push(resource);
            }
        }
        callback(returned);
        if let Some(cache) = &cache {
            if let Ok(mut guard) = cache.lock() {
                guard.add_all(&mut pruned);
            }
        }
    };

    source.export_resources(resources, &mut wrapped);
    source.state_mut().root_resource_id = previous_id;
    source.state_mut().root_resource_type = previous_type;
}

#[allow(clippy::too_many_arguments)]
pub fn source_run_with_resource_selector<S: Source + ?Sized>(
    source: &mut S,
    resources: &[String],
    callback: &mut dyn FnMut(Vec<AnyResource>),
    resource_id: &str,
    resource_internal_id: &str,
    resource_type: &str,
    parent_resource_id: &str,
    parent_resource_internal_id: &str,
    parent_resource_type: &str,
) {
    let _ = (resource_internal_id, parent_resource_internal_id);
    let selector = ResourceSelector::new(
        resource_id,
        resource_internal_id,
        resource_type,
        parent_resource_id,
        parent_resource_internal_id,
        parent_resource_type,
    );
    source.run(
        resources,
        callback,
        selector.get_scope_id(),
        selector.get_scope_type(),
    );
}

pub struct SourceCommon {
    pub state: TargetState,
    pub previous_report: HashMap<String, i64>,
}

impl Default for SourceCommon {
    fn default() -> Self {
        Self {
            state: TargetState::new(),
            previous_report: HashMap::new(),
        }
    }
}

#[allow(dead_code)]
fn _is_supported_bridge(types: &[&str], resources: &[&str]) -> bool {
    is_supported(types, resources)
}
