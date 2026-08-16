//! Adapter identity, webhook signatures, URL builders, and clone-command shape.

use utopia_vcs::adapter::git::{Bitbucket, Forgejo, GitHub, GitLab, Gitea, Gogs};
use utopia_vcs::cache::MemoryCache;
use utopia_vcs::php::hmac_sha256_hex;
use utopia_vcs::{
    CLONE_TYPE_BRANCH, CLONE_TYPE_COMMIT, CLONE_TYPE_TAG, TYPE_GIT, WEBHOOK_SCOPE_INSTALLATION,
    WEBHOOK_SCOPE_REPOSITORY,
};

const PAYLOAD: &str = r#"{"object_kind":"push","action":"push"}"#;
const SECRET: &str = "my-webhook-secret";

fn sha256_prefixed(payload: &str, secret: &str) -> String {
    format!(
        "sha256={}",
        hmac_sha256_hex(payload.as_bytes(), secret.as_bytes())
    )
}

fn sha256_raw(payload: &str, secret: &str) -> String {
    hmac_sha256_hex(payload.as_bytes(), secret.as_bytes())
}

#[test]
fn github_identity_headers_scopes() {
    let adapter = GitHub::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "github");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-github-event");
    assert_eq!(adapter.get_signature_header_name(), "x-hub-signature-256");
    assert_eq!(
        adapter.get_supported_webhook_scopes(),
        &[WEBHOOK_SCOPE_INSTALLATION, WEBHOOK_SCOPE_REPOSITORY]
    );
}

#[test]
fn gitlab_identity_headers_scopes() {
    let adapter = GitLab::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "gitlab");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-gitlab-event");
    assert_eq!(adapter.get_signature_header_name(), "x-gitlab-token");
    assert_eq!(
        adapter.get_supported_webhook_scopes(),
        &[WEBHOOK_SCOPE_REPOSITORY]
    );
}

#[test]
fn bitbucket_identity_headers_scopes() {
    let adapter = Bitbucket::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "bitbucket");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-event-key");
    assert_eq!(adapter.get_signature_header_name(), "x-hub-signature");
    assert_eq!(
        adapter.get_supported_webhook_scopes(),
        &[WEBHOOK_SCOPE_REPOSITORY]
    );
}

#[test]
fn gitea_identity_headers_scopes() {
    let adapter = Gitea::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "gitea");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-gitea-event");
    assert_eq!(adapter.get_signature_header_name(), "x-gitea-signature");
    assert_eq!(
        adapter.get_supported_webhook_scopes(),
        &[WEBHOOK_SCOPE_REPOSITORY]
    );
}

#[test]
fn gogs_identity_headers_scopes() {
    let adapter = Gogs::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "gogs");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-gogs-event");
    assert_eq!(adapter.get_signature_header_name(), "x-gogs-signature");
}

#[test]
fn forgejo_identity_headers_scopes() {
    let adapter = Forgejo::new(MemoryCache::new());
    assert_eq!(adapter.get_name(), "forgejo");
    assert_eq!(adapter.get_type(), TYPE_GIT);
    assert_eq!(adapter.get_event_header_name(), "x-forgejo-event");
    assert_eq!(adapter.get_signature_header_name(), "x-forgejo-signature");
}

#[test]
fn github_validate_webhook_event() {
    let adapter = GitHub::new(MemoryCache::new());
    let good = sha256_prefixed(PAYLOAD, SECRET);
    assert!(adapter.validate_webhook_event(PAYLOAD, &good, SECRET));
    assert!(!adapter.validate_webhook_event(PAYLOAD, "not-the-signature", SECRET));
    assert!(!adapter.validate_webhook_event(
        PAYLOAD,
        &sha256_prefixed(PAYLOAD, "another-secret"),
        SECRET
    ));
    assert!(!adapter.validate_webhook_event(PAYLOAD, &sha256_raw(PAYLOAD, SECRET), SECRET));
}

#[test]
fn bitbucket_validate_webhook_event() {
    let adapter = Bitbucket::new(MemoryCache::new());
    let good = sha256_prefixed(PAYLOAD, SECRET);
    assert!(adapter.validate_webhook_event(PAYLOAD, &good, SECRET));
    assert!(!adapter.validate_webhook_event(PAYLOAD, "not-the-signature", SECRET));
}

#[test]
fn gitlab_validate_webhook_event() {
    let adapter = GitLab::new(MemoryCache::new());
    assert!(adapter.validate_webhook_event(PAYLOAD, SECRET, SECRET));
    assert!(!adapter.validate_webhook_event(PAYLOAD, "not-the-signature", SECRET));
    assert!(!adapter.validate_webhook_event(PAYLOAD, &sha256_prefixed(PAYLOAD, SECRET), SECRET));
}

#[test]
fn gitea_family_validate_webhook_event() {
    let raw = sha256_raw(PAYLOAD, SECRET);
    for adapter_ok in [
        Gitea::new(MemoryCache::new()).validate_webhook_event(PAYLOAD, &raw, SECRET),
        Gogs::new(MemoryCache::new()).validate_webhook_event(PAYLOAD, &raw, SECRET),
        Forgejo::new(MemoryCache::new()).validate_webhook_event(PAYLOAD, &raw, SECRET),
    ] {
        assert!(adapter_ok);
    }
    assert!(!Gitea::new(MemoryCache::new()).validate_webhook_event(
        PAYLOAD,
        "not-the-signature",
        SECRET
    ));
    assert!(!Gitea::new(MemoryCache::new()).validate_webhook_event(
        PAYLOAD,
        &sha256_prefixed(PAYLOAD, SECRET),
        SECRET
    ));
}

#[test]
fn github_urls() {
    let adapter = GitHub::new(MemoryCache::new());
    assert_eq!(
        adapter.get_repository_url("acme", "app"),
        "https://github.com/acme/app"
    );
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "https://github.com/acme/app/tree/main"
    );
    assert_eq!(
        adapter.get_commit_url("acme", "app", "abc"),
        "https://github.com/acme/app/commit/abc"
    );
    assert_eq!(
        adapter.get_file_url("acme", "app", "main/README.md"),
        "https://github.com/acme/app/blob/main/README.md"
    );
}

#[test]
fn gitlab_urls() {
    let adapter = GitLab::new(MemoryCache::new());
    assert_eq!(
        adapter.get_repository_url("acme", "app"),
        "http://gitlab:80/acme/app"
    );
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "http://gitlab:80/acme/app/-/tree/main"
    );
    assert_eq!(
        adapter.get_commit_url("acme", "app", "abc"),
        "http://gitlab:80/acme/app/-/commit/abc"
    );
    assert_eq!(
        adapter.get_file_url("acme", "app", "main/README.md"),
        "http://gitlab:80/acme/app/-/blob/main/README.md"
    );
}

#[test]
fn bitbucket_urls() {
    let adapter = Bitbucket::new(MemoryCache::new());
    assert_eq!(
        adapter.get_repository_url("acme", "app"),
        "https://bitbucket.org/acme/app"
    );
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "https://bitbucket.org/acme/app/branch/main"
    );
    assert_eq!(
        adapter.get_commit_url("acme", "app", "abc"),
        "https://bitbucket.org/acme/app/commits/abc"
    );
    assert_eq!(
        adapter.get_file_url("acme", "app", "main/README.md"),
        "https://bitbucket.org/acme/app/src/main/README.md"
    );
}

#[test]
fn gitea_urls() {
    let adapter = Gitea::new(MemoryCache::new());
    assert_eq!(
        adapter.get_repository_url("acme", "app"),
        "http://gitea:3000/acme/app"
    );
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "http://gitea:3000/acme/app/src/branch/main"
    );
    assert_eq!(
        adapter.get_commit_url("acme", "app", "abc"),
        "http://gitea:3000/acme/app/commit/abc"
    );
    assert_eq!(
        adapter.get_file_url("acme", "app", "main/README.md"),
        "http://gitea:3000/acme/app/src/main/README.md"
    );
}

#[test]
fn gogs_branch_url_differs_from_gitea() {
    let adapter = Gogs::new(MemoryCache::new());
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "http://gogs:3000/acme/app/src/main"
    );
}

#[test]
fn forgejo_urls() {
    let adapter = Forgejo::new(MemoryCache::new());
    assert_eq!(
        adapter.get_repository_url("acme", "app"),
        "http://forgejo:3000/acme/app"
    );
    assert_eq!(
        adapter.get_branch_url("acme", "app", "main"),
        "http://forgejo:3000/acme/app/src/branch/main"
    );
}

fn assert_clone_shape(command: &str, repo: &str, version: &str) {
    assert!(command.contains("git init"), "{command}");
    assert!(command.contains("git remote add origin"), "{command}");
    assert!(
        command.contains("git config core.sparseCheckout true"),
        "{command}"
    );
    assert!(command.contains("sparse-checkout"), "{command}");
    assert!(command.contains(repo), "{command}");
    assert!(command.contains(version), "{command}");
}

#[test]
fn github_generate_clone_command() {
    let adapter = GitHub::new(MemoryCache::new());
    let branch = adapter
        .generate_clone_command("acme", "app", "main", CLONE_TYPE_BRANCH, "/tmp/clone", "*")
        .unwrap();
    assert_clone_shape(&branch, "app", "main");
    assert!(branch.contains("https://acme@github.com/acme/app"));
    assert!(!branch.contains("escapeshellarg"));

    let commit = adapter
        .generate_clone_command("acme", "app", "abc123", CLONE_TYPE_COMMIT, "/tmp/c", "*")
        .unwrap();
    assert!(commit.contains("--depth=1"));
    assert!(commit.contains("abc123"));

    let tag = adapter
        .generate_clone_command("acme", "app", "v1.0.0", CLONE_TYPE_TAG, "/tmp/t", "*")
        .unwrap();
    assert!(tag.contains("refs/tags/"));
    assert!(tag.contains("'v1.0.0'"));
}

#[test]
fn gitlab_generate_clone_command() {
    let adapter = GitLab::new(MemoryCache::new());
    let command = adapter
        .generate_clone_command("acme", "app", "main", CLONE_TYPE_BRANCH, "/tmp/clone", "*")
        .unwrap();
    assert_clone_shape(&command, "app", "main");
    assert!(command.contains("http://gitlab:80/acme/app.git"));
}

#[test]
fn bitbucket_generate_clone_command() {
    let adapter = Bitbucket::new(MemoryCache::new());
    let command = adapter
        .generate_clone_command("acme", "app", "main", CLONE_TYPE_BRANCH, "/tmp/clone", "*")
        .unwrap();
    assert_clone_shape(&command, "app", "main");
    assert!(command.contains("https://bitbucket.org/acme/app.git"));
}

#[test]
fn gitea_generate_clone_command() {
    let adapter = Gitea::new(MemoryCache::new());
    let command = adapter
        .generate_clone_command("acme", "app", "main", CLONE_TYPE_BRANCH, "/tmp/clone", "*")
        .unwrap();
    assert_clone_shape(&command, "app", "main");
    assert!(command.contains("http://gitea:3000/acme/app"));
    let tag = adapter
        .generate_clone_command("acme", "app", "v1.0.0", CLONE_TYPE_TAG, "/tmp/t", "*")
        .unwrap();
    assert!(tag.contains("refs/tags/v1.0.0"));
}

#[test]
fn normalize_and_match_glob() {
    assert_eq!(
        utopia_vcs::php::normalize_repository_path("src//./lib/"),
        "src/lib"
    );
    assert_eq!(
        utopia_vcs::php::match_glob(vec!["v1.0.0".into(), "v2.0.0".into(), "dev".into()], "v1.*"),
        vec!["v1.0.0"]
    );
    assert_eq!(
        utopia_vcs::php::match_glob(vec!["a".into(), "b".into()], ""),
        vec!["a", "b"]
    );
}
