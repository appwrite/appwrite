# utopia-user-agent

User-agent parsing for Utopia. Rust port of [utopia-php/user-agent](https://github.com/utopia-php/user-agent).

Detects operating system, browser/client, device, and bot metadata from HTTP user-agent strings with lazy, memoized evaluation per category.

## Install

```toml
utopia-user-agent = { path = "../utopia-user-agent" }
```

## Usage

```rust
use utopia_user_agent::UserAgent;

let agent = UserAgent::parse(
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) \
     AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 \
     Mobile/15E148 Safari/604.1",
);

println!("OS: {} {}", agent.operating_system().name.unwrap_or_default(), agent.operating_system().version.unwrap_or_default());
println!("Client: {}", agent.client().name.unwrap_or_default());
println!("Device: {:?}", agent.device().model);
println!("Bot: {:?}", agent.is_bot());

let snapshot = agent.to_array();
```

## API Reference

### `UserAgent`

| Method | Signature | Description |
|--------|-----------|-------------|
| `parse` | `fn parse(ua: &str) -> UserAgent` | Parse a user-agent string. Detection is lazy and memoized per category. |
| `raw` | `fn raw(&self) -> &str` | Original user-agent string. |
| `operating_system` | `fn operating_system(&self) -> OperatingSystem` | Detected OS (memoized). |
| `client` | `fn client(&self) -> Client` | Detected client / browser / library (memoized). |
| `device` | `fn device(&self) -> Device` | Detected device (memoized). |
| `bot` | `fn bot(&self) -> Option<Bot>` | Detected bot, if any (memoized). Independent from client/device. |
| `is_bot` | `fn is_bot(&self) -> bool` | `true` when `bot()` is present. |
| `to_array` | `fn to_array(&self) -> UserAgentArray` | Nested snapshot of all categories (PHP `toArray` shape, snake_case keys). |

### `OperatingSystem`

| Field / method | Type | Description |
|----------------|------|-------------|
| `code` | `Option<String>` | Short code (e.g. `WIN`, `IOS`, `AND`). |
| `name` | `Option<String>` | Display name (e.g. `Windows`, `iOS`, `Android`). |
| `version` | `Option<String>` | Normalized version when detected. |
| `is_known` | `fn is_known(&self) -> bool` | `true` when `name` is set. |
| `to_array` | `fn to_array(&self) -> OperatingSystemArray` | `{ code, name, version }`. |

Supported families include Windows, Windows Phone, macOS/iOS/iPadOS/tvOS/watchOS, Android, Fire OS, HarmonyOS, OpenHarmony, KaiOS, Tizen, Chrome OS, webOS, Sailfish, BlackBerry, Nintendo, PlayStation, GNU/Linux (+ major distros), and more.

### `Client`

| Field / method | Type | Description |
|----------------|------|-------------|
| `type` | `Option<String>` | `browser` or `library`. |
| `code` | `Option<String>` | Short browser code (e.g. `CH`, `FF`, `MF`). Libraries have `None`. |
| `name` | `Option<String>` | Display name (e.g. `Chrome`, `Firefox Mobile`, `curl`). |
| `version` | `Option<String>` | Major.minor display version for browsers/libraries. |
| `engine` | `Option<String>` | Rendering engine (`Blink`, `WebKit`, `Gecko`, `Trident`, `Presto`). |
| `engine_version` | `Option<String>` | Engine version when known. |
| `is_known` | `fn is_known(&self) -> bool` | `true` when `name` is set. |
| `is_browser` | `fn is_browser(&self) -> bool` | `true` when `type == "browser"`. |
| `to_array` | `fn to_array(&self) -> ClientArray` | Flat map with snake_case keys. |

Detection order matches PHP: Edge, Opera, Samsung Browser, Chrome/Firefox iOS, Chromium derivatives, Android WebView, Chrome, Firefox, Safari, IE, HTTP libraries.

### `Device`

| Field / method | Type | Description |
|----------------|------|-------------|
| `type` | `Option<String>` | e.g. `desktop`, `smartphone`, `tablet`, `tv`, `console`, `wearable`. |
| `brand` | `Option<String>` | Manufacturer when inferred. |
| `model` | `Option<String>` | Model string when extracted. |
| `is_known` | `fn is_known(&self) -> bool` | `true` when any field is set. |
| `to_array` | `fn to_array(&self) -> DeviceArray` | `{ type, brand, model }`. |

### `Bot`

| Field / method | Type | Description |
|----------------|------|-------------|
| `name` | `String` | Bot name. |
| `category` | `String` | e.g. `search crawler`, `ai crawler`, `social preview`, `site crawler`, `automation`, `site monitor`, `crawler`. |
| `to_array` | `fn to_array(&self) -> BotArray` | `{ name, category }`. |

Bot detection does not suppress client or device results.

### Serialization types

`UserAgentArray`, `OperatingSystemArray`, `ClientArray`, `DeviceArray`, and `BotArray` implement `serde::Serialize` for JSON/logging. Field names use snake_case (`engine_version`, not `engineVersion`).

## Tests

```bash
cargo test --manifest-path crates/utopia-user-agent/Cargo.toml
```

Golden compatibility tests mirror `tests/UserAgentTest.php` from the PHP package (10 integration tests).

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-user-agent/Cargo.toml
```

Reports `user_agent_parse` ops/s for sample desktop, iOS, Android, curl, and Googlebot user agents (full parse of all categories per iteration).

## Code quality

This crate uses a local `[workspace]` (not yet in the repo root workspace). Lint config mirrors the monorepo via inherited `[workspace.lints]`.

```bash
cargo fmt --manifest-path crates/utopia-user-agent/Cargo.toml
cargo clippy --manifest-path crates/utopia-user-agent/Cargo.toml --all-targets -- -D warnings
```

## License

MIT - see [LICENSE](LICENSE).
