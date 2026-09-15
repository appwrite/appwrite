//! PHP `tests/VCS/Base.php` webhook `getEvents` assertions, without live HTTP.

use serde_json::{json, Value};
use utopia_vcs::adapter::git::{Bitbucket, Forgejo, GitHub, GitLab, Gitea, Gogs};
use utopia_vcs::cache::MemoryCache;
use utopia_vcs::exception::{FileNotFound, RepositoryNotFound};
use utopia_vcs::VcsError;

const EVENT_REPOSITORY_ID: &str = "123";
const EVENT_REPOSITORY_NAME: &str = "test-repo";
const EVENT_OWNER: &str = "test-owner";
const EVENT_COMMIT_HASH: &str = "def456";
const EVENT_COMMIT_MESSAGE: &str = "Test commit message";
const EVENT_AUTHOR_NAME: &str = "Test Author";
const EVENT_AUTHOR_EMAIL: &str = "author@example.com";
const EVENT_HEAD_BRANCH: &str = "feature-branch";
const EVENT_PULL_REQUEST_NUMBER: i64 = 42;
const DEFAULT_BRANCH: &str = "main";

fn github() -> GitHub {
    GitHub::new(MemoryCache::new())
}
fn gitlab() -> GitLab {
    GitLab::new(MemoryCache::new())
}
fn bitbucket() -> Bitbucket {
    Bitbucket::new(MemoryCache::new())
}
fn gitea() -> Gitea {
    Gitea::new(MemoryCache::new())
}
fn gogs() -> Gogs {
    Gogs::new(MemoryCache::new())
}
fn forgejo() -> Forgejo {
    Forgejo::new(MemoryCache::new())
}

fn github_push(
    branch: &str,
    added: &[&str],
    removed: &[&str],
    modified: &[&str],
    created: bool,
    deleted: bool,
) -> String {
    json!({
        "created": created,
        "deleted": deleted,
        "ref": format!("refs/heads/{branch}"),
        "before": "abc123",
        "after": EVENT_COMMIT_HASH,
        "repository": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "full_name": format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
            "private": true,
            "html_url": format!("https://github.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
            "owner": {"name": EVENT_OWNER, "login": EVENT_OWNER},
        },
        "installation": {"id": 1234},
        "head_commit": {
            "id": EVENT_COMMIT_HASH,
            "message": EVENT_COMMIT_MESSAGE,
            "url": format!("https://github.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}/commit/{EVENT_COMMIT_HASH}"),
            "author": {"name": EVENT_AUTHOR_NAME, "email": EVENT_AUTHOR_EMAIL},
        },
        "commits": [{
            "id": EVENT_COMMIT_HASH,
            "added": added,
            "removed": removed,
            "modified": modified,
        }],
        "sender": {
            "html_url": format!("https://github.com/{EVENT_AUTHOR_NAME}"),
            "avatar_url": "https://avatars.githubusercontent.com/u/1?v=4",
        },
    })
    .to_string()
}

fn github_pr(external: bool) -> String {
    let head_owner = if external {
        "someone-else"
    } else {
        EVENT_OWNER
    };
    json!({
        "action": "opened",
        "number": EVENT_PULL_REQUEST_NUMBER,
        "pull_request": {
            "id": 1_303_283_688,
            "state": "open",
            "html_url": format!("https://github.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}/pull/{EVENT_PULL_REQUEST_NUMBER}"),
            "head": {
                "ref": EVENT_HEAD_BRANCH,
                "sha": EVENT_COMMIT_HASH,
                "label": format!("{head_owner}:{EVENT_HEAD_BRANCH}"),
                "user": {"login": head_owner},
            },
            "base": {
                "ref": DEFAULT_BRANCH,
                "label": format!("{EVENT_OWNER}:{DEFAULT_BRANCH}"),
                "user": {"login": EVENT_OWNER},
            },
            "user": {"login": head_owner, "avatar_url": "https://avatars.githubusercontent.com/u/1?v=4"},
        },
        "repository": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "full_name": format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
            "owner": {"login": EVENT_OWNER, "name": EVENT_OWNER},
            "html_url": format!("https://github.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        },
        "installation": {"id": 9876},
        "sender": {"html_url": format!("https://github.com/{head_owner}")},
    })
    .to_string()
}

fn gitlab_push(
    branch: &str,
    added: &[&str],
    removed: &[&str],
    modified: &[&str],
    created: bool,
    deleted: bool,
) -> String {
    let blank = "0".repeat(40);
    let repository_url = format!("http://example.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}");
    json!({
        "object_kind": "push",
        "ref": format!("refs/heads/{branch}"),
        "before": if created { blank.clone() } else { "abc123".into() },
        "after": if deleted { blank } else { EVENT_COMMIT_HASH.into() },
        "checkout_sha": if deleted { "" } else { EVENT_COMMIT_HASH },
        "user_avatar": "http://example.com/avatar.png",
        "project": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "namespace": EVENT_OWNER,
            "web_url": repository_url,
        },
        "commits": if deleted {
            json!([])
        } else {
            json!([{
                "id": EVENT_COMMIT_HASH,
                "message": EVENT_COMMIT_MESSAGE,
                "url": format!("{repository_url}/-/commit/{EVENT_COMMIT_HASH}"),
                "author": {"name": EVENT_AUTHOR_NAME, "email": EVENT_AUTHOR_EMAIL},
                "added": added,
                "removed": removed,
                "modified": modified,
            }])
        },
    })
    .to_string()
}

fn gitlab_pr(external: bool) -> String {
    json!({
        "object_kind": "merge_request",
        "project": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "namespace": EVENT_OWNER,
            "web_url": format!("http://example.com/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        },
        "object_attributes": {
            "iid": EVENT_PULL_REQUEST_NUMBER,
            "title": "Test MR",
            "action": "open",
            "source_branch": EVENT_HEAD_BRANCH,
            "target_branch": DEFAULT_BRANCH,
            "source_project_id": if external { 456 } else { 123 },
            "target_project_id": 123,
            "url": format!("http://example.com/mr/{EVENT_PULL_REQUEST_NUMBER}"),
            "last_commit": {
                "id": EVENT_COMMIT_HASH,
                "message": EVENT_COMMIT_MESSAGE,
                "url": format!("http://example.com/commit/{EVENT_COMMIT_HASH}"),
                "author": {"name": EVENT_AUTHOR_NAME, "email": EVENT_AUTHOR_EMAIL},
            },
        },
    })
    .to_string()
}

fn bitbucket_repo() -> Value {
    json!({
        "uuid": "{11111111-2222-3333-4444-555555555555}",
        "name": EVENT_REPOSITORY_NAME,
        "full_name": format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        "workspace": {"slug": EVENT_OWNER},
        "links": {"html": {"href": format!("https://bitbucket.org/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}")}},
    })
}

fn bitbucket_actor() -> Value {
    json!({
        "display_name": "Tester",
        "links": {
            "html": {"href": "https://bitbucket.org/tester"},
            "avatar": {"href": "https://bitbucket.org/account/tester/avatar/"},
        },
    })
}

fn bitbucket_push(branch: &str, created: bool, deleted: bool) -> String {
    let repo_url = format!("https://bitbucket.org/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}");
    let ref_obj = json!({
        "type": "branch",
        "name": branch,
        "target": {
            "hash": EVENT_COMMIT_HASH,
            "message": EVENT_COMMIT_MESSAGE,
            "author": {"raw": format!("{EVENT_AUTHOR_NAME} <{EVENT_AUTHOR_EMAIL}>")},
            "links": {"html": {"href": format!("{repo_url}/commits/{EVENT_COMMIT_HASH}")}},
        },
    });
    json!({
        "actor": bitbucket_actor(),
        "repository": bitbucket_repo(),
        "push": {
            "changes": [{
                "created": created,
                "closed": deleted,
                "old": if created { Value::Null } else { ref_obj.clone() },
                "new": if deleted { Value::Null } else { ref_obj },
            }],
        },
    })
    .to_string()
}

fn bitbucket_pr(external: bool) -> String {
    json!({
        "actor": bitbucket_actor(),
        "repository": bitbucket_repo(),
        "pullrequest": {
            "id": EVENT_PULL_REQUEST_NUMBER,
            "title": "Test PR",
            "state": "OPEN",
            "source": {
                "branch": {"name": EVENT_HEAD_BRANCH},
                "commit": {"hash": EVENT_COMMIT_HASH},
                "repository": {
                    "uuid": if external {
                        "{99999999-2222-3333-4444-555555555555}"
                    } else {
                        "{11111111-2222-3333-4444-555555555555}"
                    }
                },
            },
            "destination": {
                "branch": {"name": "master"},
                "repository": {"uuid": "{11111111-2222-3333-4444-555555555555}"},
            },
        },
    })
    .to_string()
}

fn gitea_push(
    branch: &str,
    added: &[&str],
    removed: &[&str],
    modified: &[&str],
    created: bool,
    deleted: bool,
) -> String {
    let repository_url = format!("http://gitea:3000/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}");
    json!({
        "ref": format!("refs/heads/{branch}"),
        "before": "abc123",
        "after": EVENT_COMMIT_HASH,
        "created": created,
        "deleted": deleted,
        "repository": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "full_name": format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
            "html_url": repository_url,
            "owner": {"login": EVENT_OWNER},
        },
        "sender": {
            "login": EVENT_AUTHOR_NAME,
            "html_url": "http://gitea:3000/pusher-user",
            "avatar_url": "http://gitea:3000/avatars/pusher",
        },
        "head_commit": {
            "id": EVENT_COMMIT_HASH,
            "message": EVENT_COMMIT_MESSAGE,
            "url": format!("{repository_url}/commit/{EVENT_COMMIT_HASH}"),
            "author": {"name": EVENT_AUTHOR_NAME, "email": EVENT_AUTHOR_EMAIL},
        },
        "commits": [{
            "id": EVENT_COMMIT_HASH,
            "added": added,
            "removed": removed,
            "modified": modified,
        }],
    })
    .to_string()
}

fn gitea_pr(external: bool) -> String {
    let repository_url = format!("http://gitea:3000/{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}");
    let head_repository = if external {
        "someone-else/forked-repo".to_string()
    } else {
        format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}")
    };
    json!({
        "action": "opened",
        "number": EVENT_PULL_REQUEST_NUMBER,
        "pull_request": {
            "id": 1,
            "number": EVENT_PULL_REQUEST_NUMBER,
            "state": "open",
            "title": "Test PR",
            "head": {
                "ref": EVENT_HEAD_BRANCH,
                "sha": EVENT_COMMIT_HASH,
                "repo": {"full_name": head_repository},
                "user": {"login": EVENT_OWNER},
            },
            "base": {
                "ref": DEFAULT_BRANCH,
                "sha": "abc123",
                "user": {"login": EVENT_OWNER},
            },
            "user": {"login": EVENT_OWNER, "avatar_url": "http://gitea:3000/avatars/pr-author"},
        },
        "repository": {
            "id": 123,
            "name": EVENT_REPOSITORY_NAME,
            "full_name": format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
            "html_url": repository_url,
            "owner": {"login": EVENT_OWNER},
        },
        "sender": {"login": EVENT_OWNER, "html_url": format!("http://gitea:3000/{EVENT_OWNER}")},
    })
    .to_string()
}

fn assert_push_shape(result: &Value, branch: &str, repository_id: &str, reports_files: bool) {
    assert_eq!(result["branch"], json!(branch));
    assert_eq!(result["repositoryId"], json!(repository_id));
    assert_eq!(result["repositoryName"], json!(EVENT_REPOSITORY_NAME));
    assert_eq!(result["owner"], json!(EVENT_OWNER));
    assert_eq!(result["commitHash"], json!(EVENT_COMMIT_HASH));
    assert_eq!(result["headCommitMessage"], json!(EVENT_COMMIT_MESSAGE));
    assert_eq!(result["headCommitAuthorName"], json!(EVENT_AUTHOR_NAME));
    assert_eq!(result["headCommitAuthorEmail"], json!(EVENT_AUTHOR_EMAIL));
    assert!(!result["headCommitUrl"].as_str().unwrap_or("").is_empty());
    assert!(!result["repositoryUrl"].as_str().unwrap_or("").is_empty());
    assert!(!result["branchUrl"].as_str().unwrap_or("").is_empty());
    assert_eq!(result["branchCreated"], json!(false));
    assert_eq!(result["branchDeleted"], json!(false));
    let files = result["affectedFiles"]
        .as_array()
        .unwrap()
        .iter()
        .filter_map(Value::as_str)
        .collect::<std::collections::HashSet<_>>();
    if reports_files {
        assert_eq!(
            files,
            ["file1.txt", "file2.txt", "file3.txt"]
                .into_iter()
                .collect()
        );
    } else {
        assert!(files.is_empty());
    }
}

fn assert_pr_shape(result: &Value, repository_id: &str, external: bool) {
    if external {
        assert_eq!(result["external"], json!(true));
    } else {
        assert_eq!(result["action"], json!("opened"));
        assert_eq!(result["branch"], json!(EVENT_HEAD_BRANCH));
        assert_eq!(
            result["pullRequestNumber"],
            json!(EVENT_PULL_REQUEST_NUMBER)
        );
        assert_eq!(result["repositoryId"], json!(repository_id));
        assert_eq!(result["repositoryName"], json!(EVENT_REPOSITORY_NAME));
        assert_eq!(result["owner"], json!(EVENT_OWNER));
        assert_eq!(result["commitHash"], json!(EVENT_COMMIT_HASH));
        assert_eq!(result["external"], json!(false));
    }
}

fn assert_invalid(err: VcsError) {
    assert_eq!(err.to_string(), "Invalid payload.");
}

#[test]
fn github_get_event_push() {
    let events = github()
        .get_events(
            "push",
            &github_push(
                DEFAULT_BRANCH,
                &["file1.txt"],
                &["file2.txt"],
                &["file3.txt"],
                false,
                false,
            ),
        )
        .unwrap();
    assert_eq!(events.len(), 1);
    assert_push_shape(&events[0], DEFAULT_BRANCH, EVENT_REPOSITORY_ID, true);
}

#[test]
fn github_get_event_push_created_deleted() {
    let created = github()
        .get_events(
            "push",
            &github_push(DEFAULT_BRANCH, &[], &[], &[], true, false),
        )
        .unwrap();
    assert_eq!(created[0]["branchCreated"], json!(true));
    assert_eq!(created[0]["branchDeleted"], json!(false));
    let deleted = github()
        .get_events(
            "push",
            &github_push(DEFAULT_BRANCH, &[], &[], &[], false, true),
        )
        .unwrap();
    assert_eq!(deleted[0]["branchCreated"], json!(false));
    assert_eq!(deleted[0]["branchDeleted"], json!(true));
}

#[test]
fn github_get_event_pull_request() {
    let events = github()
        .get_events("pull_request", &github_pr(false))
        .unwrap();
    assert_eq!(events.len(), 1);
    assert_pr_shape(&events[0], EVENT_REPOSITORY_ID, false);
    let external = github()
        .get_events("pull_request", &github_pr(true))
        .unwrap();
    assert_pr_shape(&external[0], EVENT_REPOSITORY_ID, true);
}

#[test]
fn github_get_event_installation() {
    let payload = json!({
        "action": "deleted",
        "installation": {"id": 1234, "account": {"login": "vermakhushboo"}},
    })
    .to_string();
    let events = github().get_events("installation", &payload).unwrap();
    assert_eq!(events.len(), 1);
    assert_eq!(events[0]["action"], json!("deleted"));
    assert_eq!(events[0]["installationId"], json!("1234"));
}

#[test]
fn github_get_event_invalid_and_unsupported() {
    assert_invalid(github().get_events("push", "invalid json").unwrap_err());
    assert!(github()
        .get_events("unsupported_event", &json!({"test": "data"}).to_string())
        .unwrap()
        .is_empty());
}

#[test]
fn gitlab_get_event_push() {
    let events = gitlab()
        .get_events(
            "Push Hook",
            &gitlab_push(
                DEFAULT_BRANCH,
                &["file1.txt"],
                &["file2.txt"],
                &["file3.txt"],
                false,
                false,
            ),
        )
        .unwrap();
    assert_eq!(events.len(), 1);
    assert_push_shape(&events[0], DEFAULT_BRANCH, EVENT_REPOSITORY_ID, true);
}

#[test]
fn gitlab_get_event_push_created_deleted() {
    let created = gitlab()
        .get_events(
            "Push Hook",
            &gitlab_push(DEFAULT_BRANCH, &[], &[], &[], true, false),
        )
        .unwrap();
    assert_eq!(created[0]["branchCreated"], json!(true));
    let deleted = gitlab()
        .get_events(
            "Push Hook",
            &gitlab_push(DEFAULT_BRANCH, &[], &[], &[], false, true),
        )
        .unwrap();
    assert_eq!(deleted[0]["branchDeleted"], json!(true));
}

#[test]
fn gitlab_get_event_pull_request() {
    let events = gitlab()
        .get_events("Merge Request Hook", &gitlab_pr(false))
        .unwrap();
    assert_pr_shape(&events[0], EVENT_REPOSITORY_ID, false);
    let external = gitlab()
        .get_events("Merge Request Hook", &gitlab_pr(true))
        .unwrap();
    assert_pr_shape(&external[0], EVENT_REPOSITORY_ID, true);
}

#[test]
fn gitlab_get_event_push_matches_checkout_sha() {
    let payload = json!({
        "object_kind": "push",
        "ref": "refs/heads/main",
        "checkout_sha": "def456",
        "project": {"name": "test-repo", "namespace": "test-org"},
        "commits": [
            {"id": "abc123", "message": "Older commit", "url": "http://example.com/commit/abc123", "author": {"name": "Old Author"}},
            {"id": "def456", "message": "Head commit", "url": "http://example.com/commit/def456", "author": {"name": "Head Author"}},
        ],
    })
    .to_string();
    let events = gitlab().get_events("Push Hook", &payload).unwrap();
    assert_eq!(events[0]["commitHash"], json!("def456"));
    assert_eq!(events[0]["headCommitAuthorName"], json!("Head Author"));
    assert_eq!(events[0]["headCommitMessage"], json!("Head commit"));
    assert_eq!(
        events[0]["headCommitUrl"],
        json!("http://example.com/commit/def456")
    );
}

#[test]
fn gitlab_get_event_pull_request_action_mapping() {
    for (native, mapped) in [
        ("open", "opened"),
        ("reopen", "reopened"),
        ("update", "synchronize"),
        ("close", "closed"),
        ("merge", "closed"),
    ] {
        let payload = json!({
            "object_kind": "merge_request",
            "project": {"id": 1, "name": "r", "namespace": "o", "web_url": "http://example.com/o/r"},
            "object_attributes": {"iid": 1, "action": native, "source_branch": "f", "target_branch": "main"},
        })
        .to_string();
        let events = gitlab().get_events("Merge Request Hook", &payload).unwrap();
        assert_eq!(events[0]["action"], json!(mapped), "native {native}");
    }
}

#[test]
fn gitlab_get_event_invalid() {
    assert_invalid(
        gitlab()
            .get_events("Push Hook", "invalid json")
            .unwrap_err(),
    );
    assert!(gitlab()
        .get_events("unsupported_event", &json!({"test": "data"}).to_string())
        .unwrap()
        .is_empty());
}

#[test]
fn bitbucket_get_event_push() {
    let events = bitbucket()
        .get_events("repo:push", &bitbucket_push("master", false, false))
        .unwrap();
    assert_eq!(events.len(), 1);
    assert_push_shape(
        &events[0],
        "master",
        &format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        false,
    );
}

#[test]
fn bitbucket_get_event_push_created_deleted() {
    let created = bitbucket()
        .get_events("repo:push", &bitbucket_push("master", true, false))
        .unwrap();
    assert_eq!(created[0]["branchCreated"], json!(true));
    let deleted = bitbucket()
        .get_events("repo:push", &bitbucket_push("master", false, true))
        .unwrap();
    assert_eq!(deleted[0]["branchDeleted"], json!(true));
}

#[test]
fn bitbucket_get_event_pull_request() {
    let events = bitbucket()
        .get_events("pullrequest:created", &bitbucket_pr(false))
        .unwrap();
    assert_pr_shape(
        &events[0],
        &format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        false,
    );
    let external = bitbucket()
        .get_events("pullrequest:created", &bitbucket_pr(true))
        .unwrap();
    assert_pr_shape(
        &external[0],
        &format!("{EVENT_OWNER}/{EVENT_REPOSITORY_NAME}"),
        true,
    );
}

#[test]
fn bitbucket_get_event_push_with_linked_author() {
    let mut payload: Value = serde_json::from_str(&bitbucket_push("master", false, false)).unwrap();
    payload["push"]["changes"][0]["new"]["target"]["author"]["user"] =
        json!({"display_name": "Linked User"});
    let events = bitbucket()
        .get_events("repo:push", &payload.to_string())
        .unwrap();
    assert_eq!(events[0]["headCommitAuthorName"], json!("Linked User"));
    assert_eq!(
        events[0]["headCommitAuthorEmail"],
        json!(EVENT_AUTHOR_EMAIL)
    );
}

#[test]
fn bitbucket_get_events_reports_every_pushed_branch() {
    let payload = json!({
        "actor": bitbucket_actor(),
        "repository": bitbucket_repo(),
        "push": {
            "changes": [
                {"new": {"type": "branch", "name": "main", "target": {"hash": "aaa111"}}, "created": false, "closed": false},
                {"new": {"type": "tag", "name": "v1.0.0", "target": {"hash": "bbb222"}}},
                {"new": {"type": "branch", "name": "feature", "target": {"hash": "ccc333"}}, "created": true, "closed": false},
            ],
        },
    })
    .to_string();
    let events = bitbucket().get_events("repo:push", &payload).unwrap();
    assert_eq!(events.len(), 2);
    assert_eq!(events[0]["branch"], json!("main"));
    assert_eq!(events[1]["branch"], json!("feature"));
    assert_eq!(events[0]["commitHash"], json!("aaa111"));
    assert_eq!(events[1]["commitHash"], json!("ccc333"));
    assert_eq!(events[1]["branchCreated"], json!(true));

    let tags_only = json!({
        "repository": bitbucket_repo(),
        "push": {"changes": [{"new": {"type": "tag", "name": "v1.0.0", "target": {"hash": "aaa111"}}}]},
    })
    .to_string();
    assert!(bitbucket()
        .get_events("repo:push", &tags_only)
        .unwrap()
        .is_empty());
}

#[test]
fn bitbucket_get_event_pull_request_action_mapping() {
    for (event, action) in [
        ("pullrequest:created", "opened"),
        ("pullrequest:updated", "synchronize"),
        ("pullrequest:fulfilled", "closed"),
        ("pullrequest:rejected", "closed"),
    ] {
        let events = bitbucket().get_events(event, &bitbucket_pr(false)).unwrap();
        assert_eq!(events[0]["action"], json!(action), "{event}");
    }
}

#[test]
fn bitbucket_get_event_invalid() {
    assert_invalid(
        bitbucket()
            .get_events("repo:push", "invalid json")
            .unwrap_err(),
    );
}

fn assert_gitea_family_push(get: impl Fn(&str, &str) -> Result<Vec<Value>, VcsError>) {
    let events = get(
        "push",
        &gitea_push(
            DEFAULT_BRANCH,
            &["file1.txt"],
            &["file2.txt"],
            &["file3.txt"],
            false,
            false,
        ),
    )
    .unwrap();
    assert_eq!(events.len(), 1);
    assert_push_shape(&events[0], DEFAULT_BRANCH, EVENT_REPOSITORY_ID, true);
    let created = get(
        "push",
        &gitea_push(DEFAULT_BRANCH, &[], &[], &[], true, false),
    )
    .unwrap();
    assert_eq!(created[0]["branchCreated"], json!(true));
    let deleted = get(
        "push",
        &gitea_push(DEFAULT_BRANCH, &[], &[], &[], false, true),
    )
    .unwrap();
    assert_eq!(deleted[0]["branchDeleted"], json!(true));
}

fn assert_gitea_family_pr(get: impl Fn(&str, &str) -> Result<Vec<Value>, VcsError>) {
    let events = get("pull_request", &gitea_pr(false)).unwrap();
    assert_pr_shape(&events[0], EVENT_REPOSITORY_ID, false);
    let external = get("pull_request", &gitea_pr(true)).unwrap();
    assert_pr_shape(&external[0], EVENT_REPOSITORY_ID, true);
    assert_invalid(get("push", "invalid json").unwrap_err());
    assert!(
        get("unsupported_event", &json!({"test": "data"}).to_string())
            .unwrap()
            .is_empty()
    );
}

#[test]
fn gitea_get_events() {
    let adapter = gitea();
    assert_gitea_family_push(|e, p| adapter.get_events(e, p));
    assert_gitea_family_pr(|e, p| adapter.get_events(e, p));
}

#[test]
fn gogs_get_events() {
    let adapter = gogs();
    assert_gitea_family_push(|e, p| adapter.get_events(e, p));
    assert_gitea_family_pr(|e, p| adapter.get_events(e, p));
}

#[test]
fn forgejo_get_events() {
    let adapter = forgejo();
    assert_gitea_family_push(|e, p| adapter.get_events(e, p));
    assert_gitea_family_pr(|e, p| adapter.get_events(e, p));
}

#[test]
fn exception_types() {
    assert_eq!(FileNotFound::new().to_string(), "");
    assert_eq!(
        RepositoryNotFound::new("Repository not found.").to_string(),
        "Repository not found."
    );
    assert_eq!(
        RepositoryNotFound::new("Repository not found").to_string(),
        "Repository not found"
    );
    let err = VcsError::from(RepositoryNotFound::new("Repository not found."));
    assert!(err.is_repository_not_found());
    let err = VcsError::from(FileNotFound::new());
    assert!(err.is_file_not_found());
    assert_eq!(
        github()
            .create_pull_request("o", "r", "t", "h", "b", "")
            .unwrap_err()
            .to_string(),
        "Not implemented"
    );
    assert_eq!(
        github()
            .create_tag("o", "r", "v1", "sha", "")
            .unwrap_err()
            .to_string(),
        "createTag() is not implemented for GitHub"
    );
    assert_eq!(
        gitea()
            .create_check_run(
                "o",
                "r",
                "sha",
                "n",
                "queued",
                "",
                "",
                "",
                "",
                &[],
                &[],
                &[],
                "",
                "",
                "",
                ""
            )
            .unwrap_err()
            .to_string(),
        "createCheckRun() is not supported by gitea"
    );
}
