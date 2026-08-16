//! Firebase source. PHP `Utopia\Migration\Sources\Firebase`.

use std::collections::HashMap;

use serde_json::{Map, Value};

use crate::exception::Exception;
use crate::resource::{AnyResource, ALL_RESOURCES};
use crate::source::{Source, SourceCommon};
use crate::target::{Target, TargetState};

pub struct Firebase {
    common: SourceCommon,
    pub service_account: Map<String, Value>,
    pub project_id: String,
}

impl Firebase {
    pub fn new(service_account: Map<String, Value>) -> Self {
        let project_id = service_account
            .get("project_id")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        Self {
            common: SourceCommon::default(),
            service_account,
            project_id,
        }
    }
}

impl Target for Firebase {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for Firebase {
    fn name() -> &'static str {
        "Firebase"
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
    fn report(
        &mut self,
        _resources: &[&str],
        _resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<HashMap<String, i64>, Exception> {
        Ok(HashMap::new())
    }
    fn export_group_auth(&mut self, _: usize, _: &[String], _: &mut dyn FnMut(Vec<AnyResource>)) {}
    fn export_group_databases(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_storage(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_functions(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_messaging(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_sites(&mut self, _: usize, _: &[String], _: &mut dyn FnMut(Vec<AnyResource>)) {}
    fn export_group_integrations(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_backups(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_projects(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
    fn export_group_domains(
        &mut self,
        _: usize,
        _: &[String],
        _: &mut dyn FnMut(Vec<AnyResource>),
    ) {
    }
}
