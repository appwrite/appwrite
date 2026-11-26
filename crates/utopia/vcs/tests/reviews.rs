//! Pull request comment events and authors: the review tests of
//! `packages/vcs/tests` (BitbucketTest, GitLabTest, GiteaBase, OriginTest),
//! ported.

use std::sync::Arc;

use serde_json::{Value as Json, json};
use utopia_cache::{Cache, None as NoCache};
use utopia_vcs::{Bitbucket, Flavor, Git, GitLab, Gitea, Origin, Value};

fn cache() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(NoCache)))
}

/// The first event, as JSON.
fn first(adapter: &dyn Git, event: &str, payload: Json) -> Json {
    events(adapter, event, payload).into_iter().next().expect("one event")
}

fn events(adapter: &dyn Git, event: &str, payload: Json) -> Vec<Json> {
    adapter.events(event, payload.to_string().as_bytes()).expect("parsed").iter().map(Value::to_json).collect()
}

fn merged(mut base: Json, extra: Json) -> Json {
    if let (Json::Object(b), Json::Object(e)) = (&mut base, extra) {
        b.extend(e);
    }
    base
}

fn bitbucket_comment(extra: Json) -> Json {
    json!({
        "actor": {"nickname": "octo"},
        "repository": {
            "name": "test-repo",
            "full_name": "test-owner/test-repo",
            "workspace": {"slug": "test-owner"},
            "links": {"html": {"href": "https://bitbucket.org/test-owner/test-repo"}},
        },
        "pullrequest": {"id": 42, "author": {"nickname": "author", "type": "user"}},
        "comment": merged(json!({
            "id": 501,
            "content": {"raw": "@appwrite review"},
            "user": {"nickname": "octo", "uuid": "{u-1}", "type": "user"},
            "links": {"html": {"href": "https://bitbucket.org/c/501"}},
        }), extra),
    })
}

#[test]
fn bitbucket_pull_request_comment() {
    let adapter = Bitbucket::new(cache());
    let event = first(&adapter, "pullrequest:comment_created", bitbucket_comment(json!({})));
    assert_eq!(event["action"], "created");
    assert_eq!(event["repositoryId"], "test-owner/test-repo");
    assert_eq!(event["owner"], "test-owner");
    assert_eq!(event["pullRequestNumber"], 42);
    assert_eq!(event["pullRequestAuthor"], "author");
    assert_eq!(event["commentId"], "501");
    assert_eq!(event["commentKind"], "issue");
    assert_eq!(event["commentReplyToId"], "");
    assert_eq!(event["commentBody"], "@appwrite review");
    assert_eq!(event["commentUrl"], "https://bitbucket.org/c/501");
    assert_eq!(event["commentAuthor"], "octo");
    assert_eq!(event["commentAuthorId"], "{u-1}");
    assert_eq!(event["commentAuthorType"], "User");

    let reply = first(
        &adapter,
        "pullrequest:comment_created",
        bitbucket_comment(json!({
            "inline": {"path": "src/index.ts", "to": 3},
            "parent": {"id": 500},
            "user": {"nickname": "agent", "uuid": "{a-1}", "type": "app_user"},
        })),
    );
    assert_eq!(reply["commentKind"], "review");
    assert_eq!(reply["commentReplyToId"], "500");
    assert_eq!(reply["commentAuthorType"], "Bot");
    assert_eq!(adapter.default_webhook_events(), ["push", "pull_request", "comment"]);
}

fn gitlab_note(note: Json) -> Json {
    json!({
        "object_kind": "note",
        "user": {"id": 7, "username": "octo"},
        "project": {"id": 123, "name": "test-repo", "namespace": "test-owner", "web_url": "http://example.com/test-owner/test-repo"},
        "object_attributes": merged(json!({
            "id": 1244,
            "note": "@appwrite review",
            "noteable_type": "MergeRequest",
            "system": false,
            "discussion_id": "d0c1e2f3",
            "type": null,
            "action": "create",
            "url": "http://example.com/mr/42#note_1244",
        }), note),
        "merge_request": {"iid": 42, "author_id": 7},
    })
}

#[test]
fn gitlab_merge_request_comments_and_authors() {
    let adapter = GitLab::new(cache());
    let event = first(&adapter, "Note Hook", gitlab_note(json!({})));
    assert_eq!(event["action"], "created");
    assert_eq!(event["repositoryId"], "123");
    assert_eq!(event["owner"], "test-owner");
    assert_eq!(event["pullRequestNumber"], 42);
    assert_eq!(event["pullRequestAuthor"], "octo");
    assert_eq!(event["commentId"], "1244");
    assert_eq!(event["commentKind"], "issue");
    assert_eq!(event["commentThreadId"], "");
    assert_eq!(event["commentReplyToId"], "");
    assert_eq!(event["commentUrl"], "http://example.com/mr/42#note_1244");
    assert_eq!(event["commentAuthorId"], "7");
    assert_eq!(event["commentAuthorType"], "User");

    let diff = first(&adapter, "Note Hook", gitlab_note(json!({"type": "DiffNote"})));
    assert_eq!(diff["commentKind"], "review");
    assert_eq!(diff["commentThreadId"], "d0c1e2f3");
    assert_eq!(diff["commentReplyToId"], "d0c1e2f3");

    for other in [json!({"noteable_type": "Issue"}), json!({"system": true}), json!({"action": "update"})] {
        assert!(events(&adapter, "Note Hook", gitlab_note(other)).is_empty());
    }

    let update = json!({
        "object_kind": "merge_request",
        "user": {"id": 7, "username": "octo"},
        "project": {"id": 1, "name": "r", "namespace": "o", "web_url": "http://example.com/o/r"},
        "object_attributes": {"iid": 1, "action": "update", "author_id": 8},
    });
    assert_eq!(first(&adapter, "Merge Request Hook", update)["pullRequestAuthor"], "");

    let project = json!({
        "id": 89442539,
        "name": "Magic Portfolio",
        "namespace": "Jane Doe",
        "path_with_namespace": "jane-doe/magic-portfolio",
        "web_url": "https://gitlab.com/jane-doe/magic-portfolio",
    });
    let note = json!({
        "object_kind": "note",
        "project": project,
        "object_attributes": {"id": 1, "note": "@appwrite review", "noteable_type": "MergeRequest", "action": "create"},
        "merge_request": {"iid": 1},
    });
    let path = first(&adapter, "Note Hook", note);
    assert_eq!((path["owner"].clone(), path["repositoryName"].clone()), (json!("jane-doe"), json!("magic-portfolio")));
    assert_eq!(adapter.default_webhook_events(), ["push", "pull_request", "comment"]);
}

fn gitea_comment(overrides: Json) -> Json {
    merged(
        json!({
            "action": "created",
            "is_pull": true,
            "issue": {"number": 42, "user": {"login": "author"}},
            "pull_request": {"number": 42, "user": {"login": "author"}},
            "comment": {
                "id": 99,
                "body": "@appwrite review",
                "html_url": "http://gitea:3000/o/r/pulls/42#issuecomment-99",
                "user": {"id": 5, "login": "octo"},
            },
            "repository": {
                "id": 123,
                "name": "test-repo",
                "html_url": "http://gitea:3000/test-owner/test-repo",
                "owner": {"login": "test-owner"},
            },
        }),
        overrides,
    )
}

#[test]
fn gitea_pull_request_comments() {
    for flavor in [Flavor::Gitea, Flavor::Forgejo, Flavor::Gogs] {
        let adapter = Gitea::flavored(flavor, cache());
        let event = first(&adapter, "issue_comment", gitea_comment(json!({})));
        assert_eq!(event["action"], "created");
        assert_eq!(event["repositoryId"], "123");
        assert_eq!(event["owner"], "test-owner");
        assert_eq!(event["pullRequestNumber"], 42);
        assert_eq!(event["pullRequestAuthor"], "author");
        assert_eq!(event["commentId"], "99");
        assert_eq!(event["commentKind"], "issue");
        assert_eq!(event["commentBody"], "@appwrite review");
        assert_eq!(event["commentAuthor"], "octo");
        assert_eq!(event["commentAuthorId"], "5");
        assert_eq!(event["commentAuthorType"], "User");
        assert!(events(&adapter, "issue_comment", gitea_comment(json!({"is_pull": false}))).is_empty());
        assert!(events(&adapter, "issue_comment", gitea_comment(json!({"action": "edited"}))).is_empty());
        assert_eq!(adapter.default_webhook_events(), ["push", "pull_request", "pull_request_comment"]);
    }
}

fn origin_comment(thread: Json, author: Json) -> Json {
    json!({
        "deliveryId": "whd_0123456789",
        "appId": "app_0123456789",
        "installationId": "i_0123456789",
        "event": {
            "id": "evt_0123456789",
            "type": "pull_request.comment.created",
            "eventTime": "2026-08-18T10:00:00Z",
            "payload": {
                "pullRequest": {
                    "id": "pr_0123456789",
                    "number": "42",
                    "repository": {
                        "id": "repo_0123456789",
                        "name": "test-repo",
                        "owner": {"slug": "test-owner", "id": "ns_0123456789", "type": "team"},
                    },
                },
                "comment": {
                    "id": "cmt_0123456789",
                    "thread": thread,
                    "body": "@appwrite review",
                    "author": author,
                    "createdAt": "2026-08-18T10:00:00Z",
                    "updatedAt": "2026-08-18T10:00:00Z",
                    "reactions": [],
                },
            },
        },
    })
}

#[test]
fn origin_pull_request_comments() {
    let adapter = Origin::new(cache());
    let kind = utopia_vcs::ORIGIN_EVENT_PULL_REQUEST_COMMENT;
    let general = first(
        &adapter,
        kind,
        origin_comment(
            json!({"id": "cth_0123456789", "createdAt": "2026-08-18T10:00:00Z", "updatedAt": "2026-08-18T10:00:00Z"}),
            json!({"user": {"id": "user_0123456789", "handle": "octo"}}),
        ),
    );
    assert_eq!(general["action"], "created");
    assert_eq!(general["repositoryId"], "repo_0123456789");
    assert_eq!(general["installationId"], "i_0123456789");
    assert_eq!(general["pullRequestNumber"], 42);
    assert_eq!(general["commentId"], "cmt_0123456789");
    assert_eq!(general["commentKind"], "issue");
    assert_eq!(general["commentThreadId"], "");
    assert_eq!(general["commentReplyToId"], "");
    assert_eq!(general["commentUrl"], "https://cursor.com/codebase/test-owner/test-repo/pull/42");
    assert_eq!(general["commentAuthor"], "octo");
    assert_eq!(general["commentAuthorId"], "user_0123456789");
    assert_eq!(general["commentAuthorType"], "User");

    let inline = first(
        &adapter,
        kind,
        origin_comment(
            json!({"id": "cth_0123456789", "path": "src/index.ts", "side": "right", "startLine": 3}),
            json!({"user": {"id": "user_0123456789"}}),
        ),
    );
    assert_eq!(inline["commentKind"], "review");
    assert_eq!(inline["commentThreadId"], "cth_0123456789");
    assert_eq!(inline["commentReplyToId"], "");
    assert_eq!(inline["commentAuthor"], "user_0123456789");

    let reply = first(
        &adapter,
        kind,
        origin_comment(
            json!({"id": "cth_0123456789"}),
            json!({"app": {"id": "app_0123456789", "displayName": "Reviewer"}}),
        ),
    );
    assert_eq!(reply["commentKind"], "review");
    assert_eq!(reply["commentThreadId"], "cth_0123456789");
    assert_eq!(reply["commentReplyToId"], "cth_0123456789");
    assert_eq!(reply["commentAuthorId"], "app_0123456789");
    assert_eq!(reply["commentAuthorType"], "App");

    let bot = first(
        &adapter,
        kind,
        origin_comment(
            json!({"id": "cth_0123456789"}),
            json!({"serviceAccount": {"id": "sa_0123456789", "type": "bugbot"}}),
        ),
    );
    assert_eq!(bot["commentAuthorId"], "sa_0123456789");
    assert_eq!(bot["commentAuthorType"], "Bot");
    assert_eq!(adapter.pull_request_url("o", "r", 7), "https://cursor.com/codebase/o/r/pull/7");
}
