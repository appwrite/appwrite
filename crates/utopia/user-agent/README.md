# Utopia User Agent

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/user-agent`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/user-agent) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

User-agent parsing: operating system, client, device and bot.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-user-agent = { git = "https://github.com/utopia-rs/user-agent" }
```

A user-agent string tells a server what made a request: the browser, its operating system, the device it runs on, or the bot or HTTP library behind it. This library reads those facts from the `User-Agent` header without data files or network calls. Each category is detected only when you ask for it, and unknown fields are `None`, so any header is safe to parse. This guide covers the common tasks.

## Identify the browser

Parse the header, then read the client. Text values are bytes, because a header can carry any byte.

```rust
use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36",
);
let client = agent.client();

assert!(client.is_browser());
assert_eq!(client.name.as_deref(), Some(&b"Chrome"[..]));
assert_eq!(client.version.as_deref(), Some(&b"126.0"[..]));
assert_eq!(client.engine.as_deref(), Some(&b"Blink"[..]));
assert_eq!(client.engine_version.as_deref(), Some(&b"126.0.6478.127"[..]));
```

## Identify the operating system

The operating system has a short code, a name and a version. Versions written with underscores, like `10_15_7`, come back with dots.

```rust
use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15",
);
let os = agent.operating_system();

assert_eq!(os.code.as_deref(), Some(&b"MAC"[..]));
assert_eq!(os.name.as_deref(), Some(&b"Mac"[..]));
assert_eq!(os.version.as_deref(), Some(&b"10.15"[..]));
```

## Tell phones, tablets and desktops apart

The device type is the class of hardware: `smartphone`, `tablet`, `desktop`, `tv` and so on. Use it to pick a layout or to group traffic.

```rust
use utopia_user_agent::UserAgent;

let headers = [
    "Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0",
];

let layouts: Vec<&str> = headers
    .iter()
    .map(|header| match UserAgent::parse(header).device().kind.as_deref() {
        Some(b"smartphone") => "mobile",
        Some(b"tablet") => "tablet",
        _ => "desktop",
    })
    .collect();
assert_eq!(layouts, ["mobile", "tablet", "desktop"]);
```

## Detect bots and crawlers

`bot()` returns the bot's name and category, such as `search crawler`, `ai crawler` or `social preview`. Bot detection does not hide the other results: a crawler that copies a phone's header still reports that phone.

```rust
use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)",
);
let bot = agent.bot().expect("a bot");
assert_eq!(&*bot.name, b"GPTBot");
assert_eq!(&*bot.category, b"ai crawler");

let agent = UserAgent::parse(
    "Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
);
assert!(agent.is_bot());
assert_eq!(agent.bot().map(|bot| &*bot.category), Some(&b"search crawler"[..]));
assert_eq!(agent.device().model.as_deref(), Some(&b"Nexus 5X"[..]));
```

## Recognize API clients and HTTP libraries

Requests from scripts and servers often come from an HTTP library. Its client type is `library`, so you can tell it from a browser without a list of names.

```rust
use utopia_user_agent::UserAgent;

for (header, name) in [("curl/8.4.0", "curl"), ("python-requests/2.31.0", "Python Requests")] {
    let agent = UserAgent::parse(header);
    let client = agent.client();
    assert_eq!(client.kind.as_deref(), Some(&b"library"[..]));
    assert_eq!(client.name.as_deref(), Some(name.as_bytes()));
    assert!(!client.is_browser());
}
```

## Handle missing or unknown headers

An empty or unfamiliar header never fails. Every field is `None`, and `is_known` tells you whether anything was found.

```rust
use utopia_user_agent::UserAgent;

for header in ["", "not a browser"] {
    let agent = UserAgent::parse(header);
    let known = agent.operating_system().is_known()
        || agent.client().is_known()
        || agent.device().is_known()
        || agent.is_bot();
    assert!(!known);
    assert_eq!(agent.client().name, None);
}
```

## Show a session's device in an account page

Account pages list active sessions with a short label like "Mobile Safari on iOS 17.4". Build it from the client and the operating system, with a fallback for unknown parts.

```rust
use utopia_user_agent::{Text, UserAgent};

fn label(header: &str) -> String {
    let agent = UserAgent::parse(header);
    let text = |value: &Option<Text>| value.as_deref().map(String::from_utf8_lossy).unwrap_or_default().into_owned();

    let client = agent.client();
    let os = agent.operating_system();
    let browser = if client.is_known() { text(&client.name) } else { "Unknown client".to_owned() };
    let system = format!("{} {}", text(&os.name), text(&os.version));
    match system.trim() {
        "" => browser,
        system => format!("{browser} on {system}"),
    }
}

assert_eq!(
    label("Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1"),
    "Mobile Safari on iOS 17.4",
);
assert_eq!(label("okhttp/4.12.0"), "OkHttp");
assert_eq!(label(""), "Unknown client");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
