use std::fmt;

/// [`Utopia\Migration\Warning`](https://github.com/utopia-php/migration/blob/7e371c8f59bf/src/Migration/Warning.php).
#[derive(Debug, Clone)]
pub struct Warning {
    message: String,
    resource_name: String,
    resource_group: String,
    resource_id: Option<String>,
}

impl Warning {
    pub fn new(
        resource_name: impl Into<String>,
        resource_group: impl Into<String>,
        resource_id: Option<String>,
        message: impl Into<String>,
    ) -> Self {
        Self {
            resource_name: resource_name.into(),
            resource_group: resource_group.into(),
            resource_id,
            message: message.into(),
        }
    }

    #[must_use]
    pub fn get_message(&self) -> &str {
        &self.message
    }

    #[must_use]
    pub fn get_resource_name(&self) -> &str {
        &self.resource_name
    }

    #[must_use]
    pub fn get_resource_group(&self) -> &str {
        &self.resource_group
    }

    #[must_use]
    pub fn get_resource_id(&self) -> &str {
        self.resource_id.as_deref().unwrap_or("")
    }
}

impl fmt::Display for Warning {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}", self.message)
    }
}
