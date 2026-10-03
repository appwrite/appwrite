//! Hot-path benches: webhook HMAC validation and `get_events` parsing.

use std::time::Instant;

use utopia_vcs::adapter::git::GitHub;
use utopia_vcs::cache::MemoryCache;
use utopia_vcs::php::hmac_sha256_hex;

fn bench(name: &str, iters: u64, mut f: impl FnMut()) {
    for _ in 0..iters.min(1_000) {
        f();
    }
    let start = Instant::now();
    for _ in 0..iters {
        f();
    }
    let elapsed = start.elapsed();
    println!(
        "{name}: {:.0} ops/s ({elapsed:?} for {iters} iters)",
        iters as f64 / elapsed.as_secs_f64()
    );
}

fn push_payload() -> String {
    serde_json::json!({
        "created": false,
        "deleted": false,
        "ref": "refs/heads/main",
        "before": "abc123",
        "after": "def456",
        "repository": {
            "id": 123,
            "name": "test-repo",
            "full_name": "test-owner/test-repo",
            "private": true,
            "html_url": "https://github.com/test-owner/test-repo",
            "owner": {"name": "test-owner", "login": "test-owner"},
        },
        "installation": {"id": 1234},
        "head_commit": {
            "id": "def456",
            "message": "Test commit message",
            "url": "https://github.com/test-owner/test-repo/commit/def456",
            "author": {"name": "Test Author", "email": "author@example.com"},
        },
        "commits": [{
            "id": "def456",
            "added": ["file1.txt"],
            "removed": ["file2.txt"],
            "modified": ["file3.txt"],
        }],
        "sender": {
            "html_url": "https://github.com/Test Author",
            "avatar_url": "https://avatars.githubusercontent.com/u/1?v=4",
        },
    })
    .to_string()
}

fn main() {
    let github = GitHub::new(MemoryCache::new());
    let payload = push_payload();
    let secret = "my-webhook-secret";
    let signature = format!(
        "sha256={}",
        hmac_sha256_hex(payload.as_bytes(), secret.as_bytes())
    );

    bench("validate_webhook", 200_000, || {
        std::hint::black_box(github.validate_webhook_event(&payload, &signature, secret));
    });

    bench("get_events_push", 50_000, || {
        std::hint::black_box(github.get_events("push", &payload).unwrap());
    });
}
