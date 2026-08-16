//! PHP `Utopia\VCS\Adapter`.

//! Adapter constants (PHP `Utopia\VCS\Adapter`).

/// Clone a branch (PHP `Adapter::CLONE_TYPE_BRANCH`).
pub const CLONE_TYPE_BRANCH: &str = "branch";
/// Clone a tag (PHP `Adapter::CLONE_TYPE_TAG`).
pub const CLONE_TYPE_TAG: &str = "tag";
/// Clone a commit (PHP `Adapter::CLONE_TYPE_COMMIT`).
pub const CLONE_TYPE_COMMIT: &str = "commit";

/// Git adapter type (PHP `Adapter::TYPE_GIT`).
pub const TYPE_GIT: &str = "git";
/// SVN adapter type (PHP `Adapter::TYPE_SVN`) - no SVN adapter ships yet.
pub const TYPE_SVN: &str = "svn";

/// Platform-wide installation webhook (PHP `Adapter::WEBHOOK_SCOPE_INSTALLATION`).
pub const WEBHOOK_SCOPE_INSTALLATION: &str = "installation";
/// Per-repository webhook (PHP `Adapter::WEBHOOK_SCOPE_REPOSITORY`).
pub const WEBHOOK_SCOPE_REPOSITORY: &str = "repository";

pub use crate::http::{
    METHOD_CONNECT, METHOD_DELETE, METHOD_GET, METHOD_HEAD, METHOD_OPTIONS, METHOD_PATCH,
    METHOD_POST, METHOD_PUT, METHOD_TRACE,
};

/// Webhook id as the provider reports it (int on GitHub/Gitea/GitLab, UUID on Bitbucket).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum WebhookId {
    /// Numeric hook id.
    Number(i64),
    /// UUID or other string id.
    Text(String),
}

impl WebhookId {
    #[must_use]
    pub fn as_i64(&self) -> Option<i64> {
        match self {
            Self::Number(n) => Some(*n),
            Self::Text(text) => text.parse().ok(),
        }
    }
}

impl std::fmt::Display for WebhookId {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Number(n) => write!(f, "{n}"),
            Self::Text(text) => write!(f, "{text}"),
        }
    }
}

pub mod git;
