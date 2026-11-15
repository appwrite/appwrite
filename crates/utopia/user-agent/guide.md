A user-agent string tells a server what made a request: the browser, its operating system, the device it runs on, or the bot or HTTP library behind it. This library reads those facts from the `User-Agent` header without data files or network calls. Each category is detected only when you ask for it, and unknown fields are `None`, so any header is safe to parse. This guide covers the common tasks.

## Identify the browser

Parse the header, then read the client. Text values are bytes, because a header can carry any byte.

```
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

```php
use Utopia\UserAgent\UserAgent;

$agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.127 Safari/537.36');
$client = $agent->client();

var_dump($client->isBrowser());        // bool(true)
echo $client->name, "\n";              // Chrome
echo $client->version, "\n";           // 126.0
echo $client->engine, "\n";            // Blink
echo $client->engineVersion, "\n";     // 126.0.6478.127
```

## Identify the operating system

The operating system has a short code, a name and a version. Versions written with underscores, like `10_15_7`, come back with dots.

```
use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15",
);
let os = agent.operating_system();

assert_eq!(os.code.as_deref(), Some(&b"MAC"[..]));
assert_eq!(os.name.as_deref(), Some(&b"Mac"[..]));
assert_eq!(os.version.as_deref(), Some(&b"10.15"[..]));
```

```php
use Utopia\UserAgent\UserAgent;

$agent = UserAgent::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Safari/605.1.15');
$os = $agent->operatingSystem();

echo $os->code, "\n";    // MAC
echo $os->name, "\n";    // Mac
echo $os->version, "\n"; // 10.15
```

## Tell phones, tablets and desktops apart

The device type is the class of hardware: `smartphone`, `tablet`, `desktop`, `tv` and so on. Use it to pick a layout or to group traffic.

```
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

```php
use Utopia\UserAgent\UserAgent;

$headers = [
    'Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
];

foreach ($headers as $header) {
    echo match (UserAgent::parse($header)->device()->type) {
        'smartphone' => 'mobile',
        'tablet' => 'tablet',
        default => 'desktop',
    }, "\n";
}
// mobile
// tablet
// desktop
```

## Detect bots and crawlers

`bot()` returns the bot's name and category, such as `search crawler`, `ai crawler` or `social preview`. Bot detection does not hide the other results: a crawler that copies a phone's header still reports that phone.

```
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

```php
use Utopia\UserAgent\UserAgent;

$agent = UserAgent::parse('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)');
echo $agent->bot()?->name, "\n";     // GPTBot
echo $agent->bot()?->category, "\n"; // ai crawler

$agent = UserAgent::parse('Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
var_dump($agent->isBot());           // bool(true)
echo $agent->bot()?->category, "\n"; // search crawler
echo $agent->device()->model, "\n";  // Nexus 5X
```

## Recognize API clients and HTTP libraries

Requests from scripts and servers often come from an HTTP library. Its client type is `library`, so you can tell it from a browser without a list of names.

```
use utopia_user_agent::UserAgent;

for (header, name) in [("curl/8.4.0", "curl"), ("python-requests/2.31.0", "Python Requests")] {
    let agent = UserAgent::parse(header);
    let client = agent.client();
    assert_eq!(client.kind.as_deref(), Some(&b"library"[..]));
    assert_eq!(client.name.as_deref(), Some(name.as_bytes()));
    assert!(!client.is_browser());
}
```

```php
use Utopia\UserAgent\UserAgent;

foreach (['curl/8.4.0', 'python-requests/2.31.0'] as $header) {
    $client = UserAgent::parse($header)->client();
    echo $client->type, ': ', $client->name, "\n";
}
// library: curl
// library: Python Requests
```

## Handle missing or unknown headers

An empty or unfamiliar header never fails. Every field is `None`, and `is_known` tells you whether anything was found.

```
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

```php
use Utopia\UserAgent\UserAgent;

foreach (['', 'not a browser'] as $header) {
    $agent = UserAgent::parse($header);
    $known = $agent->operatingSystem()->isKnown()
        || $agent->client()->isKnown()
        || $agent->device()->isKnown()
        || $agent->isBot();
    var_dump($known, $agent->client()->name);
}
// bool(false)
// NULL
// bool(false)
// NULL
```

## Show a session's device in an account page

Account pages list active sessions with a short label like "Mobile Safari on iOS 17.4". Build it from the client and the operating system, with a fallback for unknown parts.

```
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

```php
use Utopia\UserAgent\UserAgent;

function label(string $header): string
{
    $agent = UserAgent::parse($header);
    $client = $agent->client();
    $os = $agent->operatingSystem();

    $browser = $client->isKnown() ? $client->name : 'Unknown client';
    $system = trim($os->name . ' ' . $os->version);

    return $system === '' ? $browser : "{$browser} on {$system}";
}

echo label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'), "\n"; // Mobile Safari on iOS 17.4
echo label('okhttp/4.12.0'), "\n"; // OkHttp
echo label(''), "\n";              // Unknown client
```
