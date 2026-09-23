# Utopia User Agent

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/user-agent`](https://github.com/utopia-php/monorepo/tree/main/packages/user-agent) — please open issues and pull requests there.

A fast user-agent parser and device detector for PHP.

Utopia User Agent provides typed operating-system, client, device, and bot
results without runtime data files or dependencies. Detection categories are
lazy and memoized: asking only for a device does not run browser or bot rules.

## Installation

```bash
composer require utopia-php/user-agent
```

## Quick start

```php
use Utopia\UserAgent\UserAgent;

$agent = UserAgent::parse($_SERVER['HTTP_USER_AGENT'] ?? '');

$os = $agent->operatingSystem();
$client = $agent->client();
$device = $agent->device();

echo $os->name;       // iOS
echo $client->name;   // Mobile Safari
echo $device->type;   // smartphone
echo $device->brand;  // Apple
echo $device->model;  // iPhone

if ($agent->isBot()) {
    echo $agent->bot()?->name;
}
```

## Results

Every category returns a value object. Unknown fields are `null`, so malformed,
empty, and unfamiliar user-agent strings are safe to inspect without exception
handling.

### Operating system

```php
$os = $agent->operatingSystem();

$os->code;       // ?string, for example IOS, AND, WIN, MAC
$os->name;       // ?string
$os->version;    // ?string
$os->isKnown();  // bool
```

### Client

```php
$client = $agent->client();

$client->type;           // ?string: browser, library, desktop, ...
$client->code;           // ?string
$client->name;           // ?string
$client->version;        // ?string
$client->engine;         // ?string
$client->engineVersion;  // ?string
$client->isBrowser();    // bool
```

### Device

```php
$device = $agent->device();

$device->type;      // ?string: desktop, smartphone, tablet, tv, console, ...
$device->brand;     // ?string
$device->model;     // ?string
$device->isKnown(); // bool
```

`Device::$type` represents the device class rather than its model name.

### Bot

Bot detection is independent from the other categories. A bot user-agent can
still return its browser, OS, and device information.

```php
if ($agent->isBot()) {
    $agent->bot()?->name;      // Googlebot
    $agent->bot()?->category;  // search crawler
}
```

### Serialization

Each value object has a `toArray()` method. The complete nested result is also
available:

```php
$data = $agent->toArray();

// [
//     'os' => ['code' => ..., 'name' => ..., 'version' => ...],
//     'client' => [...],
//     'device' => [...],
//     'bot' => null|['name' => ..., 'category' => ...],
// ]
```

## Detection coverage

The rule set covers common user agents:

- Windows, Windows Phone, macOS, iOS, iPadOS, tvOS, watchOS, Android,
  Fire OS, Chrome OS, HarmonyOS, webOS, Sailfish, Tizen, KaiOS, BlackBerry,
  PlayStation, Nintendo, and popular Linux distributions (Ubuntu, Debian,
  Fedora, Arch Linux, Mint, and more)
- Chrome, Safari, Firefox, Edge, Opera, Opera Mobile, Samsung Internet,
  Brave, Vivaldi, Yandex Browser, UC Browser, DuckDuckGo, QQ Browser,
  Coc Coc, Whale, Huawei Browser, Amazon Silk, Firefox Focus, Android
  WebView, Internet Explorer, and their common mobile variants
- Common HTTP libraries (curl, Wget, OkHttp, Guzzle, Python Requests,
  aiohttp, Go-http-client, Node Fetch, Axios, Java, HTTPie, and more)
- Desktop, smartphone, tablet, TV, console, wearable, and portable-media
  device classes with common device brands and models
- Search, social-preview, monitoring, automation, SEO, and AI crawlers
  (including GPTBot, ClaudeBot, PerplexityBot, Google Extended, and other
  large-language-model agents)

Core result fields are regression-tested against Matomo DeviceDetector 6.4 for
representative profiles. The runtime does not contain or load Matomo's LGPL
rule data.

## Performance

The hot path uses direct token checks and a small bounded set of regular
expressions. There are no YAML files, runtime rule compilation, global caches,
or third-party runtime dependencies.

Run the bundled comparison benchmark:

```bash
composer bench
```

`BENCH_ITERATIONS` controls its duration. Matomo DeviceDetector is installed
only as a development dependency for differential tests and benchmarks.

There is no `skipBotDetection()` setting. Bot matching runs only when `bot()`
or `isBot()` is requested and never prevents the other categories from being
detected.
