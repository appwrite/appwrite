//! Local filesystem destination. PHP `Utopia\Migration\Destinations\Local`.

use std::collections::HashMap;
use std::fs;
use std::path::PathBuf;

use crate::destination::{Destination, DestinationCommon};
use crate::exception::Exception;
use crate::resource::{AnyResource, ALL_RESOURCES};
use crate::resource_selector::ResourceSelector;
use crate::target::{Target, TargetState};

pub struct LocalDestination {
    common: DestinationCommon,
    path: PathBuf,
}

impl LocalDestination {
    pub fn new(path: impl Into<PathBuf>) -> Self {
        let path = path.into();
        let _ = fs::create_dir_all(path.join("files"));
        let _ = fs::create_dir_all(path.join("deployments"));
        Self {
            common: DestinationCommon::default(),
            path,
        }
    }
    #[must_use]
    pub fn path(&self) -> &std::path::Path {
        &self.path
    }
}

impl Target for LocalDestination {
    fn state(&self) -> &TargetState {
        &self.common.state
    }
    fn state_mut(&mut self) -> &mut TargetState {
        &mut self.common.state
    }
}

impl Destination for LocalDestination {
    fn name() -> &'static str {
        "Local"
    }
    fn supported_resources() -> &'static [&'static str] {
        ALL_RESOURCES
    }
    fn selector(&self) -> Option<&ResourceSelector> {
        self.common.selector.as_ref()
    }
    fn set_selector(&mut self, selector: Option<ResourceSelector>) {
        self.common.selector = selector;
    }
    fn import(&mut self, resources: Vec<AnyResource>, callback: &mut dyn FnMut(Vec<AnyResource>)) {
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
