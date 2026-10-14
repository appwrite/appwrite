# Utopia VCS

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/vcs`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/vcs) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Version control system adapters: GitHub, GitLab, Gitea, Forgejo, Gogs, Bitbucket and Origin: repositories, branches, tags, files, commits, pull requests, comments, statuses and check runs, webhook parsing and signature validation, app installation tokens, and shallow sparse clone commands.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-vcs = { git = "https://github.com/utopia-rs/vcs" }
```

# Getting started

`utopia-vcs` talks to Git providers (GitHub, GitLab, Gitea, Forgejo, Gogs, Bitbucket and Origin) through one contract: the `Adapter` and `Git` traits. An adapter reads and writes repositories, branches, files, commits, pull requests, comments, statuses and check runs; parses the provider's webhook deliveries into events and checks their signatures; and builds the shell command that clones one version of a repository. Payload-shaped results are `php_std::Value` arrays, key for key what the PHP library returns.

## Read a webhook delivery

Check the delivery's signature with the secret the webhook was created with, then turn the payload into events. Neither step reaches the provider.

```rust
use std::sync::Arc;
use utopia_cache::{Cache, None as NoCache};
use utopia_vcs::{Adapter, Gitea};

let gitea = Gitea::new(Arc::new(Cache::from_dyn(Arc::new(NoCache))));
let payload = br#"{"ref":"refs/heads/main","after":"def456","created":false,"deleted":false,
    "repository":{"id":123,"name":"website","full_name":"acme/website","html_url":"https://gitea.example.com/acme/website","owner":{"login":"acme"}},
    "sender":{"login":"ada"},"head_commit":{"id":"def456","message":"Fix","url":"https://gitea.example.com/acme/website/commit/def456","author":{"name":"Ada","email":"ada@example.com"}},
    "commits":[{"id":"def456","added":["a.txt"],"removed":[],"modified":[]}]}"#;

// x-gitea-signature: the hex HMAC-SHA256 of the body
let signature = b"not-the-signature";
assert!(!gitea.validate_webhook_event(payload, signature, b"webhook-secret"));

let events = gitea.events("push", payload).expect("a push payload");
let event = php_std::json::encode(&events[0], php_std::json::Flags::NONE, 512).unwrap();
assert!(event.contains(r#""branch":"main""#));
```

## Build a clone command

The command checks out one branch, tag (or tag glob) or commit, shallow and sparse: only `rootDirectory` lands on disk. It runs in a shell; every argument is quoted.

```rust
use std::sync::Arc;
use utopia_cache::{Cache, None as NoCache};
use utopia_vcs::{clone_type, Adapter, Gitea};

let mut gitea = Gitea::new(Arc::new(Cache::from_dyn(Arc::new(NoCache))));
gitea.set_endpoint("https://gitea.example.com");
gitea.initialize("", "", None, Some("token"), None).await?;

let command = gitea.clone_command("acme", "website", "v1.*", clone_type::TAG, "/tmp/build", "apps/web")?;
println!("{command}");
```

## Authenticate a GitHub App

`initialize` signs an RS256 app JWT with the app's private key (PEM, base64 PEM or PEM with escaped newlines) and exchanges it for an installation token, cached under the installation id. Both runtimes read each other's cache entries.

```rust
use std::sync::Arc;
use utopia_cache::{Cache, Memory};
use utopia_vcs::{Adapter, Git, GitHub};

let mut github = GitHub::new(Arc::new(Cache::from_dyn(Arc::new(Memory::new()))));
github.initialize("12345678", private_key, Some("123456"), None, None).await?;

let owner = github.owner_name("12345678", None).await?;
let branches = github.list_branches(&owner, "website").await?;
let latest = github.latest_commit(&owner, "website", "main").await?;
```

## Report a build as a check run

`CheckRun` carries `createCheckRun()`'s named parameters; `CheckRun::new` starts one queued.

```rust
use utopia_vcs::{Adapter, CheckRun, Git, GitHub};

let mut run = CheckRun::new("appwrite/build");
run.status = "in_progress".into();
let created = github.create_check_run("acme", "website", sha, &run).await?;

let mut done = CheckRun::new("appwrite/build");
done.status = "completed".into();
done.conclusion = "success".into();
done.title = "Build".into();
done.summary = "Deployed".into();
let id = php_std::json::encode(&created, php_std::json::Flags::NONE, 512).unwrap_or_default();
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
