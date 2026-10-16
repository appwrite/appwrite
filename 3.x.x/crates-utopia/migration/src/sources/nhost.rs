//! `NHost` source. PHP `Utopia\Migration\Sources\NHost`.
//! Postgres is lazy-connected so default tests do not need a live database.

use std::collections::HashMap;

use crate::exception::Exception;
use crate::resource::{AnyResource, ALL_RESOURCES};
use crate::source::{Source, SourceCommon};
use crate::target::{Target, TargetState};

pub struct NHost {
    common: SourceCommon,
    pub subdomain: String,
    pub region: String,
    pub admin_secret: String,
    pub database_name: String,
    pub username: String,
    pub password: String,
    pub port: String,
    pub storage_url: String,
}

impl NHost {
    pub fn new(
        subdomain: impl Into<String>,
        region: impl Into<String>,
        admin_secret: impl Into<String>,
        database_name: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
        port: impl Into<String>,
    ) -> Self {
        let subdomain = subdomain.into();
        let region = region.into();
        let storage_url = format!("https://{subdomain}.storage.{region}.nhost.run");
        Self {
            common: SourceCommon::default(),
            subdomain,
            region,
            admin_secret: admin_secret.into(),
            database_name: database_name.into(),
            username: username.into(),
            password: password.into(),
            port: port.into(),
            storage_url,
        }
    }
}

impl Target for NHost {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Source for NHost {
    fn name() -> &'static str {
        "NHost"
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
