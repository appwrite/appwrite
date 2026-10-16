//! PHP `Utopia\VCS\Adapter\Git`.

//! Git adapter defaults (PHP `Utopia\VCS\Adapter\Git`).
//!
//! PHP `Git` is the abstract parent of every adapter in this crate. Rust ports
//! the constructor (`Cache` / [`crate::cache::CacheStore`]), `getType()` = `TYPE_GIT`, path
//! normalization, glob matching, and the default “not supported” messages on
//! each concrete adapter.

use crate::error::VcsError;

/// PHP `Git::getRepositoryPresignedUrl()` default error.
#[must_use]
pub fn unsupported_presigned_url(adapter_name: &str) -> VcsError {
    VcsError::message(format!(
        "getRepositoryPresignedUrl() is not supported by {adapter_name}"
    ))
}

/// PHP `Git::createCheckRun()` default error.
#[must_use]
pub fn unsupported_create_check_run(adapter_name: &str) -> VcsError {
    VcsError::message(format!(
        "createCheckRun() is not supported by {adapter_name}"
    ))
}

/// PHP `Git::getCheckRun()` default error.
#[must_use]
pub fn unsupported_get_check_run(adapter_name: &str) -> VcsError {
    VcsError::message(format!("getCheckRun() is not supported by {adapter_name}"))
}

/// PHP `Git::updateCheckRun()` default error.
#[must_use]
pub fn unsupported_update_check_run(adapter_name: &str) -> VcsError {
    VcsError::message(format!(
        "updateCheckRun() is not supported by {adapter_name}"
    ))
}

/// PHP `Git::listNamespaces()` default error.
#[must_use]
pub fn unsupported_list_namespaces(adapter_name: &str) -> VcsError {
    VcsError::message(format!(
        "listNamespaces() is not supported by {adapter_name}"
    ))
}

pub mod bitbucket;
pub mod forgejo;
pub mod gitea;
pub mod github;
pub mod gitlab;
pub mod gogs;

pub use bitbucket::Bitbucket;
pub use forgejo::Forgejo;
pub use gitea::Gitea;
pub use github::GitHub;
pub use gitlab::GitLab;
pub use gogs::Gogs;
