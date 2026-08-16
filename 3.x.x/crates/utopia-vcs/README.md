# utopia-vcs

VCS adapters for Utopia. Rust port of [utopia-php/vcs](https://github.com/utopia-php/vcs)
(`abdb5763221d`, 2026-08-14).

Talks to GitHub, GitLab, Bitbucket, Gitea, Gogs, and Forgejo with the same
method names, payload shapes, webhook schemes, and clone-command formatting as
PHP. Providers live under `adapter::git` (`Adapter\Git\GitHub`, …); exceptions
under `exception`.

## Install

```toml
utopia-vcs = { path = "../utopia-vcs" }
```

## Usage

```rust
use utopia_vcs::adapter::git::GitHub;
use utopia_vcs::cache::MemoryCache;
use utopia_vcs::WEBHOOK_SCOPE_INSTALLATION;

let mut github = GitHub::new(MemoryCache::new());
github.initialize_variables(
    "installation-id",
    include_str!("github-app.pem"),
    Some("app-id"),
    None,
    None,
).unwrap();

assert_eq!(github.get_name(), "github");
assert!(github
    .get_supported_webhook_scopes()
    .contains(&WEBHOOK_SCOPE_INSTALLATION));

let events = github
    .get_events("push", r#"{"ref":"refs/heads/main","repository":{}}"#)
    .unwrap();
```

Pass [`utopia_cache::Cache`](https://docs.rs/utopia-cache) where you already have one:
`GitHub::new` accepts any [`CacheStore`], and `utopia_cache::Cache` implements it.

## Adapters

| Adapter | PHP name | Default API | Webhook signature |
|---------|----------|-------------|-------------------|
| [`GitHub`](#github) | `github` | `https://api.github.com` | `sha256=` HMAC-SHA256 (`x-hub-signature-256`) |
| [`GitLab`](#gitlab) | `gitlab` | `http://gitlab:80/api/v4` | token compare (`x-gitlab-token`) |
| [`Bitbucket`](#bitbucket) | `bitbucket` | `https://api.bitbucket.org/2.0` | `sha256=` HMAC-SHA256 (`x-hub-signature`) |
| [`Gitea`](#gitea) | `gitea` | `http://gitea:3000/api/v1` | unprefixed HMAC-SHA256 (`x-gitea-signature`) |
| [`Gogs`](#gogs) | `gogs` | `http://gogs:3000/api/v1` | unprefixed HMAC-SHA256 (`x-gogs-signature`) |
| [`Forgejo`](#forgejo) | `forgejo` | `http://forgejo:3000/api/v1` | unprefixed HMAC-SHA256 (`x-forgejo-signature`) |

All adapters report [`TYPE_GIT`]. GitHub also supports
[`WEBHOOK_SCOPE_INSTALLATION`]; the others are repository-scoped only.

## API Reference

### Constants (`Adapter`)

| Const | Value |
|-------|-------|
| `CLONE_TYPE_BRANCH` | `"branch"` |
| `CLONE_TYPE_TAG` | `"tag"` |
| `CLONE_TYPE_COMMIT` | `"commit"` |
| `METHOD_GET` / `POST` / `PUT` / `PATCH` / `DELETE` / `HEAD` / `OPTIONS` / `CONNECT` / `TRACE` | HTTP verbs |
| `TYPE_GIT` | `"git"` |
| `TYPE_SVN` | `"svn"` (no SVN adapter ships) |
| `WEBHOOK_SCOPE_INSTALLATION` | `"installation"` |
| `WEBHOOK_SCOPE_REPOSITORY` | `"repository"` |
| `USER_AGENT` | Chrome/70 string sent by PHP `Adapter::call()` |
| `GITHUB_APP_JWT_EXPIRY` | `540` (9 minutes) |
| `EVENT_PUSH` / `EVENT_PULL_REQUEST` / `EVENT_INSTALLATION` | GitHub event names |
| `CONTENTS_FILE` / `CONTENTS_DIRECTORY` | `"file"` / `"dir"` |

### `CacheStore`

PHP `Utopia\Cache\Cache` `load` / `save` / `purge`. Implemented by
[`MemoryCache`] and `utopia_cache::Cache`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `load` | `fn load(&self, key: &str, ttl: i64) -> Option<String>` | Hit if saved less than `ttl` seconds ago (PHP `false` on miss). |
| `save` | `fn save(&self, key: &str, data: &str) -> bool` | Persist a value. |
| `purge` | `fn purge(&self, key: &str) -> bool` | Drop a key. |

### Exceptions

| Type | PHP | Notes |
|------|-----|-------|
| `FileNotFound` | `Utopia\VCS\Exception\FileNotFound` | Empty message when PHP throws `new FileNotFound()`. |
| `RepositoryNotFound` | `Utopia\VCS\Exception\RepositoryNotFound` | Messages match PHP (`"Repository not found."` vs `"Repository not found"`). |
| `VcsError::Exception` | `\Exception` | `getMessage()` + `getCode()` as `status`. |

### Shared adapter surface

Every adapter implements the PHP `Adapter` / `Git` methods below (snake_case).
Return values use `serde_json::Value` where PHP returns `array`.

| PHP | Rust |
|-----|------|
| `getName` / `getType` | `get_name` / `get_type` |
| `initializeVariables` | `initialize_variables` |
| `getUser` | `get_user` |
| `getOwnerName` | `get_owner_name` |
| `hasAccessToAllRepositories` | `has_access_to_all_repositories` |
| `searchRepositories` | `search_repositories` |
| `getInstallationRepository` / `getRepository` | `get_installation_repository` / `get_repository` |
| `createRepository` / `deleteRepository` | `create_repository` / `delete_repository` |
| `getPullRequest` / `getPullRequestFromBranch` / `getPullRequestFiles` | `get_pull_request` / `get_pull_request_from_branch` / `get_pull_request_files` |
| `createComment` / `getComment` / `updateComment` | `create_comment` / `get_comment` / `update_comment` |
| `generateCloneCommand` | `generate_clone_command` |
| `validateWebhookEvent` | `validate_webhook_event` |
| `getEvents` | `get_events` |
| `getEventHeaderName` / `getSignatureHeaderName` / `getSupportedWebhookScopes` | `get_event_header_name` / `get_signature_header_name` / `get_supported_webhook_scopes` |
| `getRepositoryUrl` / `getBranchUrl` / `getCommitUrl` / `getFileUrl` | `get_repository_url` / `get_branch_url` / `get_commit_url` / `get_file_url` |
| `getRepositoryName` | `get_repository_name` |
| `listBranches` / `listTags` | `list_branches` / `list_tags` |
| `updateCommitStatus` / `getCommitStatuses` | `update_commit_status` / `get_commit_statuses` |
| `createCheckRun` / `getCheckRun` / `updateCheckRun` | `create_check_run` / `get_check_run` / `update_check_run` |
| `getRepositoryTree` | `get_repository_tree` |
| `listRepositoryLanguages` | `list_repository_languages` |
| `listRepositoryContents` / `getRepositoryContent` | `list_repository_contents` / `get_repository_content` |
| `getCommit` / `getLatestCommit` | `get_commit` / `get_latest_commit` |
| `getRepositoryPresignedUrl` / `getRepositoryPresignedUrlHeaders` | `get_repository_presigned_url` / `get_repository_presigned_url_headers` |
| `createFile` / `createBranch` / `createPullRequest` / `createWebhook` / `createTag` | `create_file` / `create_branch` / `create_pull_request` / `create_webhook` / `create_tag` |
| `listNamespaces` | `list_namespaces` |
| `setEndpoint` | `set_endpoint` (GitLab/Gitea/Gogs/Forgejo/Bitbucket API host; GitHub extra for GHES/tests) |

`create_webhook` returns [`WebhookId`] (`Number` or `Text` for Bitbucket UUIDs).

### GitHub

PHP `new GitHub($cache)`. App JWT is RS256, `exp = now + GITHUB_APP_JWT_EXPIRY`.
Tokens are cached under the installation id with TTL `expiry - 60`.

`get_user` returns the full `{ headers, body }` call result (PHP quirk).
`create_pull_request`, `create_tag`, and `get_commit_statuses` throw
`"Not implemented"` / `"… is not implemented for GitHub"` as in PHP.
`list_branches_paginated` covers PHP `listBranches($owner, $repo, $perPage, $page, $search)`.

### GitLab

Requires `accessToken` in `initialize_variables` (`"accessToken is required for this adapter."`).
Clone URL uses `oauth2:{token}@`. Merge-request actions map
`open→opened`, `reopen→reopened`, `update→synchronize`, `close/merge→closed`.
Webhook validation is `hash_equals(signature_key, signature)` (payload ignored).
Owners are often `"id:path"`.

### Bitbucket

Push deliveries can describe several branches; tags are skipped.
PR actions: `pullrequest:created→opened`, `updated→synchronize`,
`fulfilled/rejected→closed`. Repository id in events is `full_name`
(`owner/slug`). Check-run id is `{commitHash}:{key}`.

### Gitea / Forgejo / Gogs

Forgejo is Gitea with a different default host and `x-forgejo-*` headers.
Gogs uses `/org/{org}/repos` (singular), no PR/status/language APIs, git CLI
for non-default-branch files/branches/tags, and branch URLs `/src/{branch}`
rather than `/src/branch/{branch}`. Check runs and namespace listing throw
`"… is not supported by {name}"`.

Gitea clone-tag lines keep PHP’s unescaped `refs/tags/{version}`.

## Deviations from PHP

- HTTP uses [`utopia-client`](../utopia-client) (PHP `utopia-php/fetch`). Behaviour is
  matched: Chrome/70 User-Agent, 15s timeout, large connect timeout, JSON /
  multipart / GraphQL / query-string bodies, lowercase response headers plus
  `status-code`, JSON decode when `Content-Type` is `application/json`,
  `"Failed to parse response: {body}"`, curl-style `"{error} with status code {code}"`,
  `eprintln` on HTTP 500, `with_ssl_verification(false)` because PHP
  `$selfSigned` defaults to **true**. Redirects are followed in-process when requested.
- Provider initialize tests hit WireMock via [`utopia-test-wiremock`](../utopia-test-wiremock)
  (compose/CI `wiremock` service).
- Cache is [`CacheStore`] / [`MemoryCache`]. Production should pass
  `utopia_cache::Cache` (PHP `Utopia\Cache\Cache`). The PHP `load($key, $ttl, $hash = '')`
  third argument is defaulted to `""` in the wrapper.
- GitHub `set_endpoint` is extra (GitHub Enterprise / tests). PHP GitHub has a
  fixed `https://api.github.com`.
- Gitea/Forgejo/Gogs constructors initialize the browser URL from the same
  default host as PHP’s `$endpoint`. PHP leaves `$giteaUrl` unset until
  `setEndpoint`.

## Tests

```bash
cargo test --manifest-path crates/utopia-vcs/Cargo.toml
```

Unit tests cover webhook signatures, `get_events` payload parsing (PHP
`Base.php` `EVENT_*` builders), URL builders, clone-command formatting,
header names, scopes, `get_name`/`get_type`, and exception messages.
[utopia-test-wiremock](../utopia-test-wiremock) asserts GitHub `getUser` path
and headers against WireMock 3.12.1.

Provider initialize e2e (PHP `tests/VCS/Base.php`) uses WireMock plus a
disposable test RSA key (`tests/github-app-test.pem`). No provider credentials.

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-vcs/Cargo.toml
```

Reports `validate_webhook` and `get_events_push` ops/s (PHP twin:
`benchmarks/vcs/`).

## Code quality

```bash
cargo fmt --manifest-path crates/utopia-vcs/Cargo.toml
cargo clippy --manifest-path crates/utopia-vcs/Cargo.toml --all-targets -- -D warnings
```

## License

MIT - see [LICENSE](LICENSE).
