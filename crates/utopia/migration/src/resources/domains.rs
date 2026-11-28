//! Domain rules.

use php_std::{Array, Value};

use super::{Input, cast_int, cast_string, param, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

/// A proxy rule routing a domain to the API, a deployment or a redirect.
///
/// PHP: `Utopia\Migration\Resources\Domains\Rule`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Rule {
    /// The domain.
    pub domain: String,
    /// `api`, `deployment` or `redirect` (`getType()`).
    pub rule_type: String,
    /// `manual` for rules a user created.
    pub trigger: String,
    /// Where a redirect rule points.
    pub redirect_url: String,
    /// The redirect status code.
    pub redirect_status_code: i64,
    /// `function` or `site` for deployment rules.
    pub deployment_resource_type: String,
    /// The function or site of a deployment rule.
    pub deployment_resource_id: String,
    /// The VCS branch of a deployment rule.
    pub deployment_vcs_provider_branch: String,
}

impl Rule {
    /// A resource of this rule.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::Rule(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `Rule::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Domains\\Rule::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let rule = Rule {
            domain: to_string(&i.raw("domain"), &p(2, "domain"))?,
            rule_type: to_string(&i.raw("type"), &p(3, "type"))?,
            trigger: to_string(&i.or("trigger", Value::from("manual")), &p(4, "trigger"))?,
            redirect_url: cast_string(&i.or("redirectUrl", Value::from(""))),
            redirect_status_code: cast_int(&i.or("redirectStatusCode", Value::Int(0))),
            deployment_resource_type: cast_string(&i.or("deploymentResourceType", Value::from(""))),
            deployment_resource_id: cast_string(&i.or("deploymentResourceId", Value::from(""))),
            deployment_vcs_provider_branch: cast_string(&i.or("deploymentVcsProviderBranch", Value::from(""))),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(10, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(11, "updatedAt"))?;
        Ok(rule.resource(id, created, updated))
    }
}
