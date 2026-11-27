# Utopia Detector

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Detects a project's packager, runtime, framework, rendering and mobile toolchain from its file names, languages, manifests and Gradle scripts.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-detector = { git = "https://github.com/appwrite/appwrite" }
```

Before a project can be built, something has to work out what it is: which package manager it uses, which runtime runs it, which framework builds it, whether the build is served by a server or as files, and which mobile toolchain builds its apps. Each of these is a detector: add the options it may choose from and the inputs you have (file names, languages, a manifest), then call `detect`. This guide covers the common tasks.

## Find the package manager

`PackagerDetector` returns the first option, in the order you added them, that has one of its files among the inputs. Put the more specific lockfiles first, since every npm-compatible project has a `package.json`.

```rust
use utopia_detector::{Npm, PackagerDetector, Pnpm, Yarn};

let mut detector = PackagerDetector::new();
detector.add_option(Pnpm).add_option(Yarn).add_option(Npm);
for file in ["package.json", "pnpm-lock.yaml", "src"] {
    detector.add_input(file);
}

assert_eq!(detector.detect().map(|p| p.name()), Some("pnpm"));
```

## Find the runtime

A `RuntimeDetector` matches by a `Strategy`: file names (`FileMatch`), their extensions (`Extension`) or language names (`Languages`). The packager you pass shapes the install command of runtimes that depend on it.

```rust
use utopia_detector::{Node, Php, Python, RuntimeDetector, Strategy};

let mut detector = RuntimeDetector::new(Strategy::Languages, "yarn");
detector.add_option(Node).add_option(Php).add_option(Python);
detector.add_input("TypeScript").add_input("Dockerfile");

let runtime = detector.detect().expect("a runtime");
assert_eq!(runtime.name(), "node");
assert_eq!(runtime.commands(), "yarn install");
assert_eq!(runtime.entrypoint(), "index.js");

assert_eq!(Strategy::new("languages")?, Strategy::Languages);
assert!(Strategy::new("magic").is_err());
```

## Find the framework from files and dependencies

A `FrameworkDetector` scores every framework: a point for each of its files among the file inputs, and a point for each of its packages in each manifest. Feed it the repository's file names and the content of `package.json` or `pubspec.yaml`.

```rust
use utopia_detector::{Astro, FrameworkDetector, NextJs, React, ReactNative};

let mut detector = FrameworkDetector::new("npm");
detector.add_option(Astro).add_option(NextJs).add_option(React).add_option(ReactNative);
detector.add_file("package.json");
detector.add_manifest(r#"{"dependencies": {"react": "^18.2.0", "react-native": "^0.74.0"}}"#);

let framework = detector.detect().expect("a framework");
assert_eq!(framework.name(), "react-native");
assert_eq!(framework.install_command(), "npm install");
assert_eq!(framework.build_command(), "npm run build");
```

## Read how a framework is configured to render

Some frameworks say in their configuration whether they render on a server. `config_files` lists the files to read, in priority order, and `adapter` reads one.

```rust
use utopia_detector::{Adapter, Astro, Framework, Jaspr};

let astro = Astro;
assert_eq!(astro.config_files()[0], "astro.config.mjs");
assert_eq!(astro.adapter(b"export default defineConfig({ output: 'server' })"), Some(Adapter::Ssr));
assert_eq!(astro.adapter(b"// output: 'server'\nexport default defineConfig({})"), Some(Adapter::Static));

assert_eq!(Jaspr.adapter(b"jaspr:\n  mode: static\n"), Some(Adapter::Static));
assert_eq!(Jaspr.adapter(b"name: my_site\n"), None);
```

## Tell server-rendered builds from static ones

A `RenderingDetector` looks at a framework's build output. It returns the first option with one of that framework's server files; otherwise the build is static, with its only HTML file as the fallback page when there is exactly one.

```rust
use utopia_detector::{RenderingDetector, Ssr, Static};

let mut detector = RenderingDetector::new("nuxt");
detector.add_option(Ssr::new()).add_option(Static::new());
detector.add_input("nuxt.config.js").add_input("server/index.mjs");
assert_eq!(detector.detect().name(), "ssr");

let mut detector = RenderingDetector::new("nuxt");
detector.add_option(Ssr::new()).add_option(Static::new());
detector.add_input("index.html").add_input("_nuxt/entry.js");
let rendering = detector.detect();
assert_eq!((rendering.name(), rendering.fallback_file()), ("static", Some("index.html")));
```

## Find a mobile project's toolchain

A `MobileDetector` reads file paths (an Xcode project or workspace, a `Podfile`), the runtime `dependencies` of a `package.json` or `pubspec.yaml`, and Gradle scripts or version catalogs that quote the `com.android.application` plugin id outside comments. A Flutter or React Native project embeds native Android and iOS projects, so of the matching options the one covering the most platforms wins; a tie keeps the option added first.

```rust
use utopia_detector::MobileDetector;
use utopia_detector::mobile::{Android, Flutter, Input, Ios, ReactNative};

let mut detector = MobileDetector::new();
detector.add_option(Android).add_option(Ios).add_option(Flutter).add_option(ReactNative);
detector.add_input("ios/Runner.xcodeproj", Input::File);
detector.add_input(r#"{"dependencies": {"react-native": "0.88.1"}, "devDependencies": {"jest": "^30.0.0"}}"#, Input::Packages);
detector.add_input("plugins {\n    id(\"com.android.application\")\n}\n", Input::Gradle);

let mobile = detector.detect().expect("a mobile project");
assert_eq!(mobile.name(), "react-native");
assert_eq!(mobile.platforms().iter().map(|p| p.as_str()).collect::<Vec<_>>(), ["android", "ios"]);
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/detector`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/detector). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-detector
cargo clippy -p utopia-detector --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

The utopia PHP extension serves this crate as every class of `Utopia\Detector`, which no longer has a PHP implementation. `crates/extension/tests/run.sh` runs `packages/detector/tests` on it in the development image.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
