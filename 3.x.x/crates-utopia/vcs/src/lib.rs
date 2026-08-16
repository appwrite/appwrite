//! VCS adapters for Utopia.
//!
//! Rust port of [`utopia-php/vcs`](https://github.com/utopia-php/vcs).
//!
//! Layout matches PHP `Utopia\VCS\`:
//! - [`adapter`] constants and [`adapter::git`] providers
//!   (`Adapter\Git\GitHub`, `Adapter\Git\GitLab`, …)
//! - [`exception`] (`Exception\FileNotFound`, `Exception\RepositoryNotFound`)

pub mod adapter;
pub mod cache;
mod error;
pub mod exception;
mod http;
pub mod php;

pub use adapter::{
    WebhookId, CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, METHOD_CONNECT, METHOD_DELETE,
    METHOD_GET, METHOD_HEAD, METHOD_OPTIONS, METHOD_PATCH, METHOD_POST, METHOD_PUT, METHOD_TRACE,
    TYPE_GIT, TYPE_SVN, WEBHOOK_SCOPE_INSTALLATION, WEBHOOK_SCOPE_REPOSITORY,
};
pub use error::VcsError;

/// Prelude for Git providers and Adapter constants.
pub mod prelude {
    pub use crate::adapter::git::{Bitbucket, Forgejo, GitHub, GitLab, Gitea, Gogs};
    pub use crate::cache::{CacheStore, MemoryCache};
    pub use crate::exception::{FileNotFound, RepositoryNotFound};
    pub use crate::{
        VcsError, WebhookId, CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, TYPE_GIT,
        WEBHOOK_SCOPE_INSTALLATION, WEBHOOK_SCOPE_REPOSITORY,
    };
}
