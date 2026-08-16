/// [`Utopia\Migration\ResourceSelector`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/ResourceSelector.php).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ResourceSelector {
    pub resource_id: String,
    pub resource_internal_id: String,
    pub resource_type: String,
    pub parent_resource_id: String,
    pub parent_resource_internal_id: String,
    pub parent_resource_type: String,
}

impl ResourceSelector {
    pub fn new(
        resource_id: impl Into<String>,
        resource_internal_id: impl Into<String>,
        resource_type: impl Into<String>,
        parent_resource_id: impl Into<String>,
        parent_resource_internal_id: impl Into<String>,
        parent_resource_type: impl Into<String>,
    ) -> Self {
        Self {
            resource_id: resource_id.into(),
            resource_internal_id: resource_internal_id.into(),
            resource_type: resource_type.into(),
            parent_resource_id: parent_resource_id.into(),
            parent_resource_internal_id: parent_resource_internal_id.into(),
            parent_resource_type: parent_resource_type.into(),
        }
    }

    #[must_use]
    pub fn get_scope_id(&self) -> &str {
        if self.parent_resource_id.is_empty() {
            &self.resource_id
        } else {
            &self.parent_resource_id
        }
    }

    #[must_use]
    pub fn get_scope_type(&self) -> &str {
        if self.parent_resource_type.is_empty() {
            &self.resource_type
        } else {
            &self.parent_resource_type
        }
    }
}
