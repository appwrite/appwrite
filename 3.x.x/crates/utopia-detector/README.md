# utopia-detector

Environment detection for Utopia. Rust port of [utopia-php/detector](https://github.com/utopia-php/detector).

Identifies packagers, runtimes, frameworks, and rendering strategies from file lists, languages, extensions, and `package.json` contents.

## Install

```toml
utopia-detector = { path = "../utopia-detector" }
```

## Usage

```rust
use utopia_detector::prelude::*;

let mut packager = Packager::new();
packager
    .add_option(PNPM::new())
    .add_option(Yarn::new())
    .add_option(NPM::new())
    .add_input("package.json", "")
    .add_input("pnpm-lock.yaml", "");
let detected = packager.detect().unwrap();
assert_eq!(detected.get_name(), "pnpm");

let mut runtime = Runtime::new(Strategy::new(Strategy::FILEMATCH).unwrap(), "pnpm");
runtime
    .add_option(Node::new())
    .add_option(PHP::new())
    .add_input("package-lock.json", "");
let detected = runtime.detect().unwrap();
assert_eq!(detected.get_name(), "node");
assert_eq!(detected.get_commands(), "pnpm install");

let mut framework = Framework::new("pnpm");
framework
    .add_option(NextJs::new())
    .add_option(SvelteKit::new())
    .add_input("next.config.js", Framework::INPUT_FILE)
    .unwrap();
let detected = framework.detect().unwrap();
assert_eq!(detected.get_name(), "nextjs");
```

## API Reference

### `detector::Packager`

PHP `Utopia\Detector\Detector\Packager`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new() -> Self` | Empty detector. |
| `add_input` | `fn add_input(&mut self, content, type_) -> &mut Self` | Append a file name. PHP `$type` defaults to `''`. |
| `add_option` | `fn add_option(&mut self, option: impl PackagerDetection) -> &mut Self` | Register a packager detection. |
| `detect` | `fn detect(&self) -> Option<Box<dyn PackagerDetection>>` | First option whose files intersect the inputs. |

### `detector::Runtime`

PHP `Utopia\Detector\Detector\Runtime`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(strategy: Strategy, packager: impl Into<String>) -> Self` | PHP packager default is `'pnpm'`. |
| `add_input` / `add_option` | fluent | Same pattern as Packager. |
| `detect` | `fn detect(&self) -> Option<Box<dyn RuntimeDetection>>` | First matching option for the strategy; sets packager. |

### `detector::Framework`

PHP `Utopia\Detector\Detector\Framework`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `INPUT_FILE` | `"file"` | File-name inputs. |
| `INPUT_PACKAGES` | `"packages"` | `package.json` body inputs. |
| `new` | `fn new(packager: impl Into<String>) -> Self` | PHP default packager `'pnpm'`. |
| `add_input` | `fn add_input(...) -> Result<&mut Self, DetectorError>` | Rejects types other than `file` / `packages`. |
| `add_option` | fluent | Register a framework detection. |
| `detect` | `fn detect(&self) -> Option<Box<dyn FrameworkDetection>>` | Highest file+package match count; fewest PHP parents on ties; Astro deprioritized when tied. |

### `detector::Rendering`

PHP `Utopia\Detector\Detector\Rendering`.

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(framework: impl Into<String>) -> Self` | Framework name used for SSR file maps. |
| `detect` | `fn detect(&self) -> Box<dyn RenderingDetection>` | First matching option, else `XStatic` (single `.html` becomes the fallback file). |

### `detector::Strategy`

| Constant / method | Value | Description |
|-------------------|-------|-------------|
| `FILEMATCH` | `"filematch"` | Match runtime `get_files()`. |
| `EXTENSION` | `"extension"` | Match `pathinfo` extensions. |
| `LANGUAGES` | `"languages"` | Match language names. |
| `new` | `fn new(value) -> Result<Self, DetectorError>` | Errors with `Invalid strategy: {value}`. |
| `get_value` | `fn get_value(&self) -> &str` | Stored strategy. |

### Detection types

Each PHP class is a Rust struct with `new()`, `get_name()`, and the PHP getters. `set_packager` is on framework and runtime detections.

**Runtimes:** `Node`, `Bun`, `Deno`, `PHP`, `Python`, `Dart`, `Swift`, `Ruby`, `Java`, `CPP`, `Dotnet`.

**Frameworks:** `JS` (abstract parent in PHP), `Flutter`, `React`, `Vue`, `Svelte`, `Angular`, `Astro`, `NextJs`, `Remix`, `Lynx`, `ReactNative`, `TanStackStart`, `Nuxt`, `Analog`, `SvelteKit`.

**Packagers:** `PNPM`, `Yarn`, `NPM`.

**Rendering:** `SSR` (`get_name()` = `"ssr"`), `XStatic` (`get_name()` = `"static"`).

### Errors

| Variant | PHP message |
|---------|-------------|
| `InvalidInputType(String)` | `Invalid input type '{type}'` |
| `InvalidStrategy(String)` | `Invalid strategy: {value}` |

## Tests

```bash
cargo test --manifest-path crates/utopia-detector/Cargo.toml
```

Ports `tests/unit/DetectorTest.php` data providers exactly and adds invalid-strategy coverage.

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-detector/Cargo.toml
```

Prints `packager_detect`, `runtime_detect`, and `framework_detect` ops/s. PHP twin: [`benchmarks/detector/`](../../benchmarks/detector/).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates/utopia-detector/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates/utopia-detector/Cargo.toml --all-targets -- -D warnings`
- Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
