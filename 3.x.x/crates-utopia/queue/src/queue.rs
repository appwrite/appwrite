use crate::error::QueueError;

/// Named queue identity (`name` + `namespace` + optional job TTL).
///
/// PHP `Utopia\Queue\Queue`. Empty name or `"0"` throws
/// `"Cannot create queue with empty name."`
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Queue {
    pub name: String,
    pub namespace: String,
    pub job_ttl: i64,
}

impl Queue {
    pub fn new(name: impl Into<String>) -> Result<Self, QueueError> {
        Self::with_namespace(name, "utopia-queue")
    }

    pub fn with_namespace(
        name: impl Into<String>,
        namespace: impl Into<String>,
    ) -> Result<Self, QueueError> {
        Self::with_ttl(name, namespace, 0)
    }

    pub fn with_ttl(
        name: impl Into<String>,
        namespace: impl Into<String>,
        job_ttl: i64,
    ) -> Result<Self, QueueError> {
        let name = name.into();
        if name.is_empty() || name == "0" {
            return Err(QueueError::invalid_argument(
                "Cannot create queue with empty name.",
            ));
        }
        Ok(Self {
            name,
            namespace: namespace.into(),
            job_ttl,
        })
    }

    pub fn key(&self, kind: &str) -> String {
        format!("{}.{}.{}", self.namespace, kind, self.name)
    }
}
