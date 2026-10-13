//! Backup policies.

use php_std::{Array, Value};

use super::{Input, param, to_array, to_bool, to_int, to_string};
use crate::error::Result;
use crate::resource::{Kind, Resource, ResourceRef};

/// A backup policy.
///
/// PHP: `Utopia\Migration\Resources\Backups\Policy` (`getPolicyName()` is the name).
#[derive(Debug, Clone, PartialEq)]
pub struct Policy {
    /// The name.
    pub name: String,
    /// The services backed up.
    pub services: Array,
    /// Days to keep backups.
    pub retention: i64,
    /// The CRON schedule.
    pub schedule: String,
    /// Whether the policy runs.
    pub enabled: bool,
    /// The resource backed up.
    pub resource_id: String,
    /// The type of the resource backed up.
    pub resource_type: String,
}

impl Default for Policy {
    fn default() -> Self {
        Self {
            name: String::new(),
            services: Array::new(),
            retention: 0,
            schedule: String::new(),
            enabled: true,
            resource_id: String::new(),
            resource_type: String::new(),
        }
    }
}

impl Policy {
    /// A resource of this policy.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::BackupPolicy(self), id)
    }

    /// PHP: `Policy::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Backups\\Policy::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        Ok(Policy {
            name: to_string(&i.or("name", Value::from("")), &p(2, "name"))?,
            services: to_array(&i.or("services", Value::Array(Array::new())), &p(3, "services"))?,
            retention: to_int(&i.or("retention", Value::Int(0)), &p(4, "retention"))?,
            schedule: to_string(&i.or("schedule", Value::from("")), &p(5, "schedule"))?,
            enabled: to_bool(&i.or("enabled", Value::Bool(true)), &p(6, "enabled"))?,
            resource_id: to_string(&i.or("resourceId", Value::from("")), &p(7, "resourceId"))?,
            resource_type: to_string(&i.or("resourceType", Value::from("")), &p(8, "resourceType"))?,
        }
        .resource(id))
    }
}
