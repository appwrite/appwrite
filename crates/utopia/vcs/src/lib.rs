//! Version control system adapters: GitHub, GitLab, Gitea, Forgejo, Gogs,
//! Bitbucket and Origin behind one contract.
//!
//! An adapter ([`Adapter`], [`Git`]) reads and writes a provider's
//! repositories, branches, tags, files, commits, pull requests, comments,
//! commit statuses and check runs over its REST API, parses its webhook
//! deliveries into events and validates their signatures, and builds the
//! shallow, sparse clone command a build checks a version out with.
//! Providers that authenticate apps (GitHub, Origin) exchange a signed app
//! JWT for an installation token, cached in a [`utopia_cache::Cache`].
//!
//! ```no_run
//! use std::sync::Arc;
//! use utopia_cache::{Cache, None as NoCache};
//! use utopia_vcs::{Adapter, Gitea};
//!
//! # async fn run() -> utopia_vcs::Result<()> {
//! let mut gitea = Gitea::new(Arc::new(Cache::from_dyn(Arc::new(NoCache))));
//! gitea.set_endpoint("https://gitea.example.com");
//! gitea.initialize("", "", None, Some("token"), None).await?;
//! let branches = gitea.list_branches("acme", "website").await?;
//! # let _ = branches;
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Cache\Adapter\None;
//! use Utopia\Cache\Cache;
//! use Utopia\VCS\Adapter\Git\Gitea;
//!
//! $gitea = new Gitea(new Cache(new None()));
//! $gitea->setEndpoint('https://gitea.example.com');
//! $gitea->initializeVariables('', '', accessToken: 'token');
//! // $gitea->listBranches('acme', 'website') returns the branch names
//! ```
//!
//! Results that are provider payloads (a repository, a pull request) or the
//! shapes adapters normalize them into (a commit, a webhook event, a file)
//! are PHP arrays ([`php_std::Value`]): key for key and type for type what
//! the PHP adapter returns, since both runtimes serve them to the same
//! clients. Requests are sent through [`utopia_client`] exactly as PHP's
//! cURL adapter sends them.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\VCS\Adapter` | [`Adapter`] (`getType` is [`Adapter::vcs_type`], `initializeVariables` [`Adapter::initialize`], `getX` methods `x`) |
//! | `Utopia\VCS\Adapter\Git` | [`Git`] |
//! | `Adapter::CLONE_TYPE_*`, `TYPE_*`, `WEBHOOK_SCOPE_*` | [`clone_type`], [`VcsType`], [`WebhookScope`] |
//! | `createCheckRun`/`updateCheckRun` parameters | [`CheckRun`] |
//! | `createWebhook()`'s `int\|string` | [`WebhookId`] |
//! | `Utopia\VCS\Adapter\Git\GitHub` | [`GitHub`] (`listBranches($perPage, $page, $search)` is [`GitHub::list_branches_page`]) |
//! | `Utopia\VCS\Adapter\Git\GitLab` | [`GitLab`] |
//! | `Utopia\VCS\Adapter\Git\Gitea`, `Forgejo`, `Gogs` | [`Gitea`] with its [`Flavor`] ([`Gitea::new`], [`Gitea::forgejo`], [`Gitea::gogs`]) |
//! | `Utopia\VCS\Adapter\Git\Bitbucket` | [`Bitbucket`] |
//! | `Utopia\VCS\Adapter\Git\Origin` | [`Origin`] (`batchUpsertCheckRuns` takes [`BatchCheckRun`]s) |
//! | `Utopia\VCS\Exception\FileNotFound`, `OwnerNotFound`, `RepositoryNotFound` | [`Error::FileNotFound`], [`Error::OwnerNotFound`], [`Error::RepositoryNotFound`] |
//! | the protected `$endpoint`, `$webEndpoint`, `$gitEndpoint`, `$bitbucketUrl` a subclass sets | [`GitHub::with_endpoint`], [`Origin::with_endpoint`], [`Origin::with_web_endpoint`], [`Origin::with_git_endpoint`], [`Bitbucket::with_web_url`] |

#![warn(missing_docs)]

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

mod adapter;
mod bitbucket;
mod error;
mod git;
mod gitea;
mod github;
mod gitlab;
mod jwt;
mod origin;
mod php;
mod time;
mod transport;
mod webhook;

pub use adapter::{Adapter, CheckRun, Git, VcsType, WebhookId, WebhookScope, clone_type};
pub use bitbucket::Bitbucket;
pub use error::{Error, Message, Result};
pub use gitea::{Flavor, Gitea};
pub use github::GitHub;
pub use gitlab::GitLab;
pub use origin::{BatchCheckRun, Origin};
pub use php_std::Value;

/// GitHub's app JWT lifetime in seconds.
pub use github::APP_JWT_EXPIRY as GITHUB_APP_JWT_EXPIRY;
/// Origin's app JWT lifetime in seconds.
pub use origin::APP_JWT_EXPIRY as ORIGIN_APP_JWT_EXPIRY;
/// Origin's push event type.
pub use origin::EVENT_PUSH as ORIGIN_EVENT_PUSH;
