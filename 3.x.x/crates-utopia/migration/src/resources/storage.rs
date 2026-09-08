//! Storage resources.

use crate::resource::{Resource, ResourceBase, TYPE_BUCKET, TYPE_FILE};
use crate::transfer::GROUP_STORAGE;

#[derive(Debug, Clone)]
pub struct Bucket {
    base: ResourceBase,
    name: String,
}

impl Bucket {
    pub fn new(id: impl Into<String>, name: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            name: name.into(),
        }
    }
    #[must_use]
    pub fn get_bucket_name(&self) -> &str {
        &self.name
    }
}

impl Resource for Bucket {
    fn get_name(&self) -> &'static str {
        TYPE_BUCKET
    }
    fn get_group(&self) -> &'static str {
        GROUP_STORAGE
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}

#[derive(Debug, Clone)]
pub struct File {
    base: ResourceBase,
    bucket: Bucket,
    data: String,
}

impl File {
    pub fn new(id: impl Into<String>, bucket: Bucket) -> Self {
        Self {
            base: ResourceBase::new(id),
            bucket,
            data: String::new(),
        }
    }
    #[must_use]
    pub fn get_bucket(&self) -> &Bucket {
        &self.bucket
    }
    #[must_use]
    pub fn get_data(&self) -> &str {
        &self.data
    }
    pub fn set_data(&mut self, data: impl Into<String>) {
        self.data = data.into();
    }
}

impl Resource for File {
    fn get_name(&self) -> &'static str {
        TYPE_FILE
    }
    fn get_group(&self) -> &'static str {
        GROUP_STORAGE
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}
