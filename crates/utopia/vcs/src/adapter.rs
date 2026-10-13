//! The adapter contract every provider implements.

use async_trait::async_trait;
use php_std::{Array, Value};
use utopia_console::Command;

use crate::error::{Error, Result};
use crate::webhook;

/// The kind of version control system an adapter speaks.
///
/// PHP: `Adapter::TYPE_GIT`, `Adapter::TYPE_SVN`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum VcsType {
    /// Git.
    Git,
    /// Subversion.
    Svn,
}

impl VcsType {
    /// The name PHP uses (`git`, `svn`).
    pub fn as_str(self) -> &'static str {
        match self {
            VcsType::Git => "git",
            VcsType::Svn => "svn",
        }
    }
}

/// Where a provider delivers webhooks from.
///
/// PHP: `Adapter::WEBHOOK_SCOPE_INSTALLATION`, `Adapter::WEBHOOK_SCOPE_REPOSITORY`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum WebhookScope {
    /// Platform-wide, once the integration is installed (a GitHub App): no
    /// per-repository registration.
    Installation,
    /// Per repository: [`Git::create_webhook`] on each one.
    Repository,
}

impl WebhookScope {
    /// The name PHP uses (`installation`, `repository`).
    pub fn as_str(self) -> &'static str {
        match self {
            WebhookScope::Installation => "installation",
            WebhookScope::Repository => "repository",
        }
    }
}

/// How a clone command checks out its version.
///
/// PHP: `Adapter::CLONE_TYPE_BRANCH`, `CLONE_TYPE_TAG`, `CLONE_TYPE_COMMIT`;
/// [`Adapter::clone_command`] takes the names as strings, so an unknown one
/// fails as in PHP.
pub mod clone_type {
    /// A branch (created locally when the remote has none of that name).
    pub const BRANCH: &str = "branch";
    /// A tag, or a glob matching tags (the last one `git ls-remote` lists).
    pub const TAG: &str = "tag";
    /// A commit hash.
    pub const COMMIT: &str = "commit";
}

/// The identifier a provider gives a webhook: a number, or a UUID string
/// (Bitbucket).
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum WebhookId {
    /// A numbered hook.
    Int(i64),
    /// A hook identified by a string.
    Str(String),
}

/// A check run to create or update: the parameters of `createCheckRun()` and
/// `updateCheckRun()` after the repository and commit (or run id).
///
/// Empty strings and arrays mean "not given", as PHP's defaults do. A new run
/// starts `queued` ([`CheckRun::new`]); an update leaves an empty status as
/// it is.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct CheckRun {
    /// The run's name.
    pub name: String,
    /// `queued`, `in_progress` or `completed`.
    pub status: String,
    /// The verdict (`success`, `failure`, `neutral`, `cancelled`, `skipped`,
    /// `timed_out`, `action_required`); setting one completes the run.
    pub conclusion: String,
    /// The output's title (sent with a summary only).
    pub title: String,
    /// The output's summary.
    pub summary: String,
    /// The output's text.
    pub text: String,
    /// Output annotations, as the provider takes them.
    pub annotations: Array,
    /// Output images, as the provider takes them.
    pub images: Array,
    /// Requested actions, as the provider takes them.
    pub actions: Array,
    /// A link to the run's details.
    pub details_url: String,
    /// The caller's own id of the run.
    pub external_id: String,
    /// When the run started (ISO 8601).
    pub started_at: String,
    /// When the run completed (ISO 8601); filled in when a conclusion
    /// completes it without one.
    pub completed_at: String,
}

impl CheckRun {
    /// A run named `name`, `queued`: `createCheckRun()`'s defaults.
    pub fn new(name: impl Into<String>) -> Self {
        CheckRun { name: name.into(), status: "queued".into(), ..CheckRun::default() }
    }
}

/// A version control system provider.
///
/// Every provider here is a [`Git`] one; this half of the contract is the
/// part that does not depend on Git. Results that are provider payloads (a
/// repository, a pull request) or shapes the adapters normalize them into (a
/// commit, a webhook event, a file) are PHP arrays ([`Value`]), key for key
/// and type for type what the PHP adapter returns.
///
/// PHP: `Utopia\VCS\Adapter`.
#[async_trait]
pub trait Adapter: Send + Sync {
    /// The adapter's name (`github`, `gitlab`, ...).
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The version control system.
    ///
    /// PHP: `getType()`.
    fn vcs_type(&self) -> VcsType;

    /// Sets up credentials: an app installation exchanges its private key
    /// for an access token (cached), token-based providers take the access
    /// token as given.
    ///
    /// PHP: `initializeVariables()`.
    async fn initialize(
        &mut self,
        installation_id: &str,
        private_key: &str,
        app_id: Option<&str>,
        access_token: Option<&str>,
        refresh_token: Option<&str>,
    ) -> Result<()>;

    /// A user account.
    ///
    /// PHP: `getUser()`.
    async fn user(&self, username: &str) -> Result<Value>;

    /// The login of the installation's (or repository's) owner.
    ///
    /// PHP: `getOwnerName()`.
    async fn owner_name(&self, installation_id: &str, repository_id: Option<i64>) -> Result<String>;

    /// Whether the credentials reach every repository of the owner.
    ///
    /// PHP: `hasAccessToAllRepositories()`.
    async fn has_access_to_all_repositories(&self) -> Result<bool>;

    /// A page of the owner's repositories whose names contain `search`, as
    /// `{items, total}`.
    ///
    /// PHP: `searchRepositories()`.
    async fn search_repositories(&self, owner: &str, page: i64, per_page: i64, search: &str) -> Result<Value>;

    /// A repository of the installation, by name.
    ///
    /// PHP: `getInstallationRepository()`.
    async fn installation_repository(&self, repository_name: &str) -> Result<Value>;

    /// A repository.
    ///
    /// PHP: `getRepository()`.
    async fn repository(&self, owner: &str, repository_name: &str) -> Result<Value>;

    /// Creates a repository and returns it.
    ///
    /// PHP: `createRepository()`.
    async fn create_repository(&self, owner: &str, repository_name: &str, private: bool) -> Result<Value>;

    /// Deletes a repository.
    ///
    /// PHP: `deleteRepository()`.
    async fn delete_repository(&self, owner: &str, repository_name: &str) -> Result<bool>;

    /// The latest open pull request from `branch`, or an empty array.
    ///
    /// PHP: `getPullRequestFromBranch()`.
    async fn pull_request_from_branch(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value>;

    /// A pull request.
    ///
    /// PHP: `getPullRequest()`.
    async fn pull_request(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value>;

    /// The files a pull request changes.
    ///
    /// PHP: `getPullRequestFiles()`.
    async fn pull_request_files(&self, owner: &str, repository_name: &str, number: i64) -> Result<Value>;

    /// Comments on a pull request and returns the comment's id.
    ///
    /// PHP: `createComment()`.
    async fn create_comment(&self, owner: &str, repository_name: &str, number: i64, comment: &str) -> Result<String>;

    /// A pull request comment's text.
    ///
    /// PHP: `getComment()`.
    async fn comment(&self, owner: &str, repository_name: &str, comment_id: &str) -> Result<String>;

    /// Replaces a pull request comment's text and returns its id.
    ///
    /// PHP: `updateComment()`.
    async fn update_comment(&self, owner: &str, repository_name: &str, comment_id: &str, comment: &str)
    -> Result<String>;

    /// A shallow, sparse clone of one version (a [`clone_type`]) into
    /// `directory`, checking out only `root_directory`.
    ///
    /// PHP: `generateCloneCommand()`.
    fn clone_command(
        &self,
        owner: &str,
        repository_name: &str,
        version: &str,
        version_type: &str,
        directory: &str,
        root_directory: &str,
    ) -> Result<Command>;

    /// Whether `signature` is the provider's signature of `payload` with
    /// `signature_key`. The default is an unprefixed hex HMAC-SHA256.
    ///
    /// PHP: `validateWebhookEvent()`.
    fn validate_webhook_event(&self, payload: &[u8], signature: &[u8], signature_key: &[u8]) -> bool {
        webhook::hmac_matches(b"", payload, signature, signature_key)
    }

    /// The events a webhook delivery describes (several for a push that
    /// touched several refs, none for an event the adapter does not read).
    ///
    /// PHP: `getEvents()`.
    fn events(&self, event: &str, payload: &[u8]) -> Result<Vec<Value>>;

    /// The header carrying a delivery's event type.
    ///
    /// PHP: `getEventHeaderName()`.
    fn event_header_name(&self) -> &str;

    /// The header carrying a delivery's signature (or shared secret).
    ///
    /// PHP: `getSignatureHeaderName()`.
    fn signature_header_name(&self) -> &str;

    /// Where the provider delivers webhooks from.
    ///
    /// PHP: `getSupportedWebhookScopes()`.
    fn supported_webhook_scopes(&self) -> &'static [WebhookScope];

    /// A repository's home page.
    ///
    /// PHP: `getRepositoryUrl()`.
    fn repository_url(&self, owner: &str, repository_name: &str) -> String;

    /// An owner's home page; unsupported unless the adapter overrides it.
    ///
    /// PHP: `getOrganizationUrl()`.
    fn organization_url(&self, owner: &str) -> Result<String> {
        let _ = owner;
        Err(Error::exception(format!("getOrganizationUrl() is not supported by {}", self.name()), 0))
    }

    /// A branch's page.
    ///
    /// PHP: `getBranchUrl()`.
    fn branch_url(&self, owner: &str, repository_name: &str, branch: &str) -> String;

    /// A commit's page.
    ///
    /// PHP: `getCommitUrl()`.
    fn commit_url(&self, owner: &str, repository_name: &str, commit_hash: &str) -> String;

    /// A file's page at a ref.
    ///
    /// PHP: `getFileUrl()`.
    fn file_url(&self, owner: &str, repository_name: &str, reference: &str) -> String;

    /// A repository's name, by id.
    ///
    /// PHP: `getRepositoryName()`.
    async fn repository_name(&self, repository_id: &str) -> Result<String>;

    /// The repository's branch names.
    ///
    /// PHP: `listBranches()`.
    async fn list_branches(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>>;

    /// The repository's tag names matching a glob (`v1.*`; empty: all).
    ///
    /// PHP: `listTags()`.
    async fn list_tags(&self, owner: &str, repository_name: &str, search: &str) -> Result<Vec<Value>>;

    /// Sets a commit status (`error`, `failure`, `pending`, `success`).
    ///
    /// PHP: `updateCommitStatus($repositoryName, $SHA, $owner, ...)`.
    #[allow(clippy::too_many_arguments)]
    async fn update_commit_status(
        &self,
        repository_name: &str,
        sha: &str,
        owner: &str,
        state: &str,
        description: &str,
        target_url: &str,
        context: &str,
    ) -> Result<()>;

    /// Creates a check run on a commit.
    ///
    /// PHP: `createCheckRun()`.
    async fn create_check_run(&self, owner: &str, repository_name: &str, head_sha: &str, run: &CheckRun) -> Result<Value> {
        let _ = (owner, repository_name, head_sha, run);
        Err(Error::exception(format!("createCheckRun() is not implemented for {}", self.name()), 0))
    }

    /// A check run.
    ///
    /// PHP: `getCheckRun()`.
    async fn check_run(&self, owner: &str, repository_name: &str, check_run_id: &str) -> Result<Value> {
        let _ = (owner, repository_name, check_run_id);
        Err(Error::exception(format!("getCheckRun() is not implemented for {}", self.name()), 0))
    }

    /// Updates a check run.
    ///
    /// PHP: `updateCheckRun()`.
    async fn update_check_run(
        &self,
        owner: &str,
        repository_name: &str,
        check_run_id: &str,
        run: &CheckRun,
    ) -> Result<Value> {
        let _ = (owner, repository_name, check_run_id, run);
        Err(Error::exception(format!("updateCheckRun() is not implemented for {}", self.name()), 0))
    }

    /// The paths of a ref's tree (recursively: every path below it).
    ///
    /// PHP: `getRepositoryTree()`.
    async fn repository_tree(&self, owner: &str, repository_name: &str, branch: &str, recursive: bool)
    -> Result<Vec<Value>>;

    /// The repository's languages.
    ///
    /// PHP: `listRepositoryLanguages()`.
    async fn list_repository_languages(&self, owner: &str, repository_name: &str) -> Result<Vec<Value>>;

    /// The entries of a directory, as `{name, size, type}` (`file` or `dir`).
    ///
    /// PHP: `listRepositoryContents()`.
    async fn list_repository_contents(
        &self,
        owner: &str,
        repository_name: &str,
        path: &str,
        reference: &str,
    ) -> Result<Vec<Value>>;

    /// A file, as `{sha, size, content}`.
    ///
    /// PHP: `getRepositoryContent()`.
    async fn repository_content(&self, owner: &str, repository_name: &str, path: &str, reference: &str)
    -> Result<Value>;

    /// A commit, as `{commitAuthor, commitMessage, commitAuthorAvatar,
    /// commitAuthorUrl, commitHash, commitUrl}`.
    ///
    /// PHP: `getCommit()`.
    async fn commit(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Value>;

    /// The latest commit of a branch, shaped as [`Adapter::commit`].
    ///
    /// PHP: `getLatestCommit()`.
    async fn latest_commit(&self, owner: &str, repository_name: &str, branch: &str) -> Result<Value>;

    /// A short-lived URL to download an archive (`tarball` or `zipball`).
    ///
    /// PHP: `getRepositoryPresignedUrl()`.
    async fn repository_presigned_url(
        &self,
        owner: &str,
        repository_name: &str,
        reference: &str,
        format: &str,
    ) -> Result<String> {
        let _ = (owner, repository_name, reference, format);
        Err(Error::exception(format!("getRepositoryPresignedUrl() is not implemented for {}", self.name()), 0))
    }
}

/// A Git provider: content writes, webhooks, capabilities and statuses on
/// top of the [`Adapter`] contract.
///
/// PHP: `Utopia\VCS\Adapter\Git`.
#[async_trait]
pub trait Git: Adapter {
    /// Commits a file and returns the provider's answer.
    ///
    /// PHP: `createFile()` (message `Add file` and the default branch by
    /// default).
    async fn create_file(
        &self,
        owner: &str,
        repository_name: &str,
        filepath: &str,
        content: &[u8],
        message: &str,
        branch: &str,
    ) -> Result<Value>;

    /// Creates a branch from another.
    ///
    /// PHP: `createBranch()`.
    async fn create_branch(&self, owner: &str, repository_name: &str, new_branch: &str, old_branch: &str)
    -> Result<Value>;

    /// Opens a pull request.
    ///
    /// PHP: `createPullRequest()`.
    async fn create_pull_request(
        &self,
        owner: &str,
        repository_name: &str,
        title: &str,
        head: &str,
        base: &str,
        body: &str,
    ) -> Result<Value>;

    /// Registers a webhook (PHP's default events: `push`, `pull_request`).
    ///
    /// PHP: `createWebhook()`.
    async fn create_webhook(
        &self,
        owner: &str,
        repository_name: &str,
        url: &str,
        secret: &str,
        events: &[&str],
    ) -> Result<WebhookId>;

    /// Creates a tag (annotated with a message).
    ///
    /// PHP: `createTag()`.
    async fn create_tag(&self, owner: &str, repository_name: &str, tag: &str, target: &str, message: &str)
    -> Result<Value>;

    /// Whether this integration can create repositories.
    ///
    /// PHP: `supportsRepositoryCreation()`.
    fn supports_repository_creation(&self) -> bool {
        true
    }

    /// Whether the provider can delete repositories.
    ///
    /// PHP: `supportsRepositoryDeletion()`.
    fn supports_repository_deletion(&self) -> bool {
        true
    }

    /// Whether the provider hands out archive download URLs.
    ///
    /// PHP: `supportsRepositoryArchives()`.
    fn supports_repository_archives(&self) -> bool {
        true
    }

    /// The HTTPS clone URL, without credentials (they ride
    /// [`Git::repository_clone_headers`]).
    ///
    /// PHP: `getRepositoryCloneUrl()`.
    fn repository_clone_url(&self, owner: &str, repository_name: &str) -> Result<String> {
        let _ = (owner, repository_name);
        Err(Error::exception(format!("getRepositoryCloneUrl() is not supported by {}", self.name()), 0))
    }

    /// Headers authenticating a fetch of the clone URL.
    ///
    /// PHP: `getRepositoryCloneHeaders()`.
    fn repository_clone_headers(&self) -> Vec<(String, String)> {
        Vec::new()
    }

    /// Whether images in pull request comments can render.
    ///
    /// PHP: `supportsCommentImages()`.
    fn supports_comment_images(&self) -> bool {
        true
    }

    /// Whether repositories can be readable anonymously.
    ///
    /// PHP: `supportsPublicRepositories()`.
    fn supports_public_repositories(&self) -> bool {
        true
    }

    /// Headers to send with the presigned URL.
    ///
    /// PHP: `getRepositoryPresignedUrlHeaders()`.
    fn repository_presigned_url_headers(&self) -> Vec<(String, String)> {
        Vec::new()
    }

    /// A page of the namespaces repositories can be created in, as `{items,
    /// total}`.
    ///
    /// PHP: `listNamespaces()`.
    async fn list_namespaces(&self, page: i64, per_page: i64, search: &str) -> Result<Value> {
        let _ = (page, per_page, search);
        Err(Error::exception(format!("listNamespaces() is not supported by {}", self.name()), 0))
    }

    /// A commit's statuses, each `{state, description, target_url, context}`.
    ///
    /// PHP: `getCommitStatuses()`.
    async fn commit_statuses(&self, owner: &str, repository_name: &str, commit_hash: &str) -> Result<Vec<Value>>;
}

/// The error a Git adapter raises for an optional method it does not
/// support: `<method>() is not supported by <name>`.
pub(crate) fn unsupported(method: &str, name: &str) -> Error {
    Error::exception(format!("{method}() is not supported by {name}"), 0)
}
