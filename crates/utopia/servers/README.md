# Utopia Servers

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/servers`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/servers) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

The base of Utopia-style servers: hooks with params, validators, injections, groups and labels, and the mode and hook registries a server keeps.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-servers = { git = "https://github.com/utopia-rs/servers" }
```

Every Utopia server (HTTP, CLI, queue workers) runs callbacks declared the same way: a hook names the params it reads, with a default and a validator each, and the resources it injects. The server resolves the params from what a request or a job carries, validates them, and calls the callback.

## Declare a hook

A hook's params and injections keep their declaration order, which is the order its callback receives them in.

```rust
use serde_json::json;
use utopia_servers::{Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.desc("Send a welcome email")
    .groups(&["mails"])
    .label("scope", json!("users.write"))
    .param("email", Param::new(json!(""), Text::new(256), "Recipient.").example("me@example.com"))
    .inject("mailer");

assert_eq!(hook.get_groups(), ["mails"]);
assert_eq!(hook.get_label("scope"), Some(&json!("users.write")));
assert_eq!(hook.params()["email"].example, "me@example.com");
assert_eq!(hook.dependencies(), ["mailer"]);
```

## Resolve and validate params

`Base::prepare` reads each param from the path values or the request (under its key or an alias), falls back to its default, validates it, and returns a child container holding the values.

```rust
use std::sync::Arc;
use serde_json::{json, Map, Value};
use utopia_di::Container;
use utopia_servers::{Base, Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.param("projectId", Param::new(json!(""), Text::new(36), "").aliases(&["project"]));

let request: Map<String, Value> = [("project".to_owned(), json!("demo"))].into_iter().collect();
let scope = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &request)?;

assert_eq!(*scope.get::<Value>("projectId")?, json!("demo"));
assert_eq!(hook.param_value("projectId")?, &json!("demo"));
```

## Report invalid params

A missing required param, or one its validator rejects, fails with a 400 error whose message names the param.

```rust
use std::sync::Arc;
use serde_json::{json, Map};
use utopia_di::Container;
use utopia_servers::{Base, Error, Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.param("name", Param::new(json!(""), Text::new(10), ""));

let error = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &Map::new()).unwrap_err();
assert!(matches!(error, Error::Server { code: 400, .. }));
assert_eq!(error.to_string(), "Param \"name\" is not optional.");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
