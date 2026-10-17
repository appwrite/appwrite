//! Compat adapter for `detector`: maps `tests/compat/detector/spec.json` operations onto `utopia-detector`.
//!
//! Options are named (`nextjs`, `node`, `pnpm`, `ssr`), as in Adapter.php.
//! `detect.fixture` runs every detector over a directory of
//! `tests/compat/detector/fixtures`, listing it the way Adapter.php does.

use std::path::Path;

use serde_json::{Value, json};
use utopia_detector::{
    Adapter, Analog, Angular, Astro, Bun, Cpp, Dart, Deno, Detected, Dotnet, Error, Flutter, Framework,
    FrameworkDetector, Input, Jaspr, Java, Lynx, NextJs, Node, Npm, Nuxt, Packager, PackagerDetector, Php, Pnpm,
    Python, React, ReactNative, Remix, Rendering, RenderingDetector, Ruby, Runtime, RuntimeDetector, Ssr, Static,
    Strategy, Svelte, SvelteKit, Swift, TanStackStart, Vue, Yarn, dependencies,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "packager.detect",
    "packager.describe",
    "strategy.new",
    "runtime.detect",
    "runtime.describe",
    "framework.detect",
    "framework.describe",
    "framework.adapter",
    "framework.dependencies",
    "rendering.detect",
    "rendering.describe",
    "detect.fixture",
];

const FRAMEWORKS: &[&str] = &[
    "analog",
    "angular",
    "astro",
    "flutter",
    "jaspr",
    "lynx",
    "nextjs",
    "nuxt",
    "react",
    "react-native",
    "remix",
    "svelte",
    "sveltekit",
    "tanstack-start",
    "vue",
];
const RUNTIMES: &[&str] = &["node", "bun", "deno", "php", "python", "dart", "swift", "ruby", "java", "cpp", "dotnet"];
const PACKAGERS: &[&str] = &["pnpm", "yarn", "npm"];
const FIXTURES: &str = "tests/compat/detector/fixtures";

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("framework", "Score 15 frameworks on 10 file names and a package.json"),
    ("runtime", "Find the runtime of 10 file names by extension among 11 runtimes"),
    ("rendering", "Tell the rendering of a 6-file Next.js build"),
    ("adapter", "Read the rendering adapter of a TanStack Start vite.config.ts"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    const FILES: &[&str] = &[
        "src",
        "static",
        "scripts",
        "eslint.config.js",
        "package.json",
        "pnpm-lock.yaml",
        "svelte.config.js",
        "tsconfig.js",
        "vite.config.js",
        "vite.config.lib.js",
    ];
    const MANIFEST: &str = r#"{"name":"app","dependencies":{"@sveltejs/kit":"^2.0.0","svelte":"^5.0.0","vite":"^5.0.0"},"devDependencies":{"typescript":"^5.0.0","prettier":"^3.0.0"}}"#;

    Ok(match name {
        "framework" => Bench::sync(|| {
            let mut detector = FrameworkDetector::new("pnpm");
            for name in FRAMEWORKS {
                add_framework(&mut detector, name).ok();
            }
            for file in FILES {
                detector.add_file(*file);
            }
            detector.add_manifest(MANIFEST);
            black_box(detector.detect().map(|f| f.name().len()));
        }),
        "runtime" => Bench::sync(|| {
            let mut detector = RuntimeDetector::new(Strategy::Extension, "pnpm");
            for name in RUNTIMES {
                add_runtime(&mut detector, name).ok();
            }
            for file in FILES {
                detector.add_input(*file);
            }
            black_box(detector.detect().map(|r| r.name().len()));
        }),
        "rendering" => Bench::sync(|| {
            let mut detector = RenderingDetector::new("nextjs");
            detector.add_option(Ssr::new()).add_option(Static::new());
            for file in [
                "server/pages/index.html",
                "server/pages/api/users.js",
                ".next/server/pages/_app.js",
                ".next/static/chunks/main.js",
                "public/favicon.ico",
                "package.json",
            ] {
                detector.add_input(file);
            }
            black_box(detector.detect().name().len());
        }),
        "adapter" => {
            let config = b"import { defineConfig } from 'vite' // https://vite.dev\nexport default defineConfig({ plugins: [tanstackStart({ prerender: { enabled: true, crawlLinks: true } })] })\n";
            Bench::sync(move || {
                black_box(TanStackStart.adapter(black_box(config)));
            })
        }
        _ => return Err(Fault::new(format!("detector: no benchmark `{name}`"))),
    })
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn packager(name: &str) -> Result<Box<dyn Packager>, Fault> {
    Ok(match name {
        "npm" => Box::new(Npm),
        "pnpm" => Box::new(Pnpm),
        "yarn" => Box::new(Yarn),
        _ => return Err(Fault::new(format!("unknown packager {name}"))),
    })
}

fn add_packager(d: &mut PackagerDetector, name: &str) -> Result<(), Fault> {
    match name {
        "npm" => d.add_option(Npm),
        "pnpm" => d.add_option(Pnpm),
        "yarn" => d.add_option(Yarn),
        _ => return Err(Fault::new(format!("unknown packager {name}"))),
    };
    Ok(())
}

macro_rules! runtimes {
    ($name:expr, $r:ident => $body:expr) => {
        match $name {
            "bun" => {
                let $r = Bun;
                $body
            }
            "cpp" => {
                let $r = Cpp;
                $body
            }
            "dart" => {
                let $r = Dart;
                $body
            }
            "deno" => {
                let $r = Deno;
                $body
            }
            "dotnet" => {
                let $r = Dotnet;
                $body
            }
            "java" => {
                let $r = Java;
                $body
            }
            "node" => {
                let $r = Node;
                $body
            }
            "php" => {
                let $r = Php;
                $body
            }
            "python" => {
                let $r = Python;
                $body
            }
            "ruby" => {
                let $r = Ruby;
                $body
            }
            "swift" => {
                let $r = Swift;
                $body
            }
            other => return Err(Fault::new(format!("unknown runtime {other}"))),
        }
    };
}

macro_rules! frameworks {
    ($name:expr, $f:ident => $body:expr) => {
        match $name {
            "analog" => {
                let $f = Analog;
                $body
            }
            "angular" => {
                let $f = Angular;
                $body
            }
            "astro" => {
                let $f = Astro;
                $body
            }
            "flutter" => {
                let $f = Flutter;
                $body
            }
            "jaspr" => {
                let $f = Jaspr;
                $body
            }
            "lynx" => {
                let $f = Lynx;
                $body
            }
            "nextjs" => {
                let $f = NextJs;
                $body
            }
            "nuxt" => {
                let $f = Nuxt;
                $body
            }
            "react" => {
                let $f = React;
                $body
            }
            "react-native" => {
                let $f = ReactNative;
                $body
            }
            "remix" => {
                let $f = Remix;
                $body
            }
            "svelte" => {
                let $f = Svelte;
                $body
            }
            "sveltekit" => {
                let $f = SvelteKit;
                $body
            }
            "tanstack-start" => {
                let $f = TanStackStart;
                $body
            }
            "vue" => {
                let $f = Vue;
                $body
            }
            other => return Err(Fault::new(format!("unknown framework {other}"))),
        }
    };
}

fn add_runtime(d: &mut RuntimeDetector, name: &str) -> Result<(), Fault> {
    runtimes!(name, r => { d.add_option(r); });
    Ok(())
}

fn add_framework(d: &mut FrameworkDetector, name: &str) -> Result<(), Fault> {
    frameworks!(name, f => { d.add_option(f); });
    Ok(())
}

fn framework(name: &str) -> Result<Box<dyn Framework>, Fault> {
    frameworks!(name, f => Ok(Box::new(f)))
}

fn runtime(name: &str) -> Result<Box<dyn Runtime>, Fault> {
    runtimes!(name, r => Ok(Box::new(r)))
}

/// `ssr`, `static`, or `{type, fallback}`.
fn rendering(option: &Value) -> Result<Box<dyn Rendering>, Fault> {
    let (kind, fallback) = match option {
        Value::String(s) => (s.as_str(), None),
        _ => {
            let a = Args(option);
            (a.str("type")?, a.opt_str("fallback")?)
        }
    };
    Ok(match (kind, fallback) {
        ("ssr", None) => Box::new(Ssr::new()),
        ("ssr", Some(f)) => Box::new(Ssr::with_fallback(f)),
        ("static", None) => Box::new(Static::new()),
        ("static", Some(f)) => Box::new(Static::with_fallback(f)),
        _ => return Err(Fault::new(format!("unknown rendering {kind}"))),
    })
}

/// Boxed renderings as options.
struct Boxed(Box<dyn Rendering>);

impl Rendering for Boxed {
    fn name(&self) -> &str {
        self.0.name()
    }

    fn files(&self, framework: &str) -> &[&str] {
        self.0.files(framework)
    }

    fn fallback_file(&self) -> Option<&str> {
        self.0.fallback_file()
    }
}

fn strings<'a>(a: &Args<'a>, key: &str) -> Result<Vec<&'a str>, Fault> {
    a.opt(key)
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .map(|v| v.as_str().ok_or_else(|| Fault::new(format!("`{key}` holds strings"))))
        .collect()
}

fn adapter_name(a: Option<Adapter>) -> &'static str {
    a.map_or("", |a| a.as_str())
}

fn detected(f: Option<Detected<'_, dyn Framework>>) -> Value {
    match f {
        Some(f) => json!({
            "name": f.name(),
            "install": f.install_command(),
            "build": f.build_command(),
            "output": f.output_directory(),
        }),
        None => Value::Null,
    }
}

/// Files under `root`, relative to it, sorted by bytes.
fn listing(root: &Path) -> Result<Vec<String>, Fault> {
    fn walk(dir: &Path, root: &Path, out: &mut Vec<String>) -> Result<(), Fault> {
        for entry in std::fs::read_dir(dir).map_err(|e| Fault::new(e.to_string()))? {
            let path = entry.map_err(|e| Fault::new(e.to_string()))?.path();
            if path.is_dir() {
                walk(&path, root, out)?;
            } else if path.is_file() {
                let relative = path.strip_prefix(root).map_err(|e| Fault::new(e.to_string()))?;
                out.push(relative.to_string_lossy().into_owned());
            }
        }
        Ok(())
    }
    let mut out = Vec::new();
    walk(root, root, &mut out)?;
    out.sort();
    Ok(out)
}

fn fixture(name: &str) -> Result<Value, Fault> {
    // Relative to the repository, wherever the driver runs (the replay test runs in the crate).
    let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../..").join(FIXTURES).join(name);
    if !root.is_dir() {
        return Err(Fault::new(format!("no fixture {name}")));
    }
    let files = listing(&root)?;
    let read = |file: &str| std::fs::read(root.join(file)).map_err(|e| Fault::new(e.to_string()));

    let mut packagers = PackagerDetector::new();
    for option in PACKAGERS {
        add_packager(&mut packagers, option)?;
    }
    let mut by_file = RuntimeDetector::new(Strategy::FileMatch, "npm");
    let mut by_extension = RuntimeDetector::new(Strategy::Extension, "npm");
    for option in RUNTIMES {
        add_runtime(&mut by_file, option)?;
        add_runtime(&mut by_extension, option)?;
    }
    let mut frameworks = FrameworkDetector::new("npm");
    for option in FRAMEWORKS {
        add_framework(&mut frameworks, option)?;
    }
    for file in &files {
        packagers.add_input(file.as_str());
        by_file.add_input(file.as_str());
        by_extension.add_input(file.as_str());
        frameworks.add_file(file.as_str());
    }
    for manifest in ["package.json", "pubspec.yaml"] {
        if files.iter().any(|f| f == manifest) {
            frameworks.add_manifest(read(manifest)?);
        }
    }

    let found = frameworks.detect();
    let mut adapter = Value::Null;
    let mut rendered = Value::Null;
    if let Some(f) = &found {
        if let Some(config) = f.config_files().iter().find(|c| files.iter().any(|f| f == *c)) {
            adapter = json!(adapter_name(f.adapter(&read(config)?)));
        }
        let build = root.join("build");
        if build.is_dir() {
            let mut detector = RenderingDetector::new(f.name());
            detector.add_option(Ssr::new()).add_option(Static::new());
            for file in listing(&build)? {
                detector.add_input(file);
            }
            let r = detector.detect();
            rendered = json!({ "name": r.name(), "fallback": r.fallback_file() });
        }
    }
    let runtime = |r: Option<Detected<'_, dyn Runtime>>| match r {
        Some(r) => json!({ "name": r.name(), "commands": r.commands() }),
        None => Value::Null,
    };
    Ok(json!({
        "files": files.len(),
        "packager": packagers.detect().map(|p| p.name().to_owned()),
        "runtime": { "filematch": runtime(by_file.detect()), "extension": runtime(by_extension.detect()) },
        "framework": detected(found),
        "adapter": adapter,
        "rendering": rendered,
    }))
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let result = match op {
        "packager.detect" => {
            let mut d = PackagerDetector::new();
            for name in strings(&a, "options")? {
                add_packager(&mut d, name)?;
            }
            for input in strings(&a, "inputs")? {
                d.add_input(input);
            }
            Outcome::Ok(d.detect().map_or(Value::Null, |p| json!(p.name())))
        }
        "packager.describe" => {
            let p = packager(a.str("packager")?)?;
            Outcome::Ok(json!({ "name": p.name(), "files": p.files() }))
        }
        "strategy.new" => match Strategy::new(a.str("value")?) {
            Ok(s) => Outcome::ok(s.value()),
            Err(e) => err(e),
        },
        "runtime.detect" => {
            let strategy = match Strategy::new(a.str("strategy")?) {
                Ok(s) => s,
                Err(e) => return Ok(err(e)),
            };
            let mut d = RuntimeDetector::new(strategy, a.opt_str("packager")?.unwrap_or("pnpm"));
            for name in strings(&a, "options")? {
                add_runtime(&mut d, name)?;
            }
            for input in strings(&a, "inputs")? {
                d.add_input(input);
            }
            Outcome::Ok(match d.detect() {
                Some(r) => json!({ "name": r.name(), "commands": r.commands(), "entrypoint": r.entrypoint() }),
                None => Value::Null,
            })
        }
        "runtime.describe" => {
            let r = runtime(a.str("runtime")?)?;
            let packager = a.opt_str("packager")?.unwrap_or("");
            Outcome::Ok(json!({
                "name": r.name(),
                "languages": r.languages(),
                "extensions": r.file_extensions(),
                "files": r.files(),
                "commands": r.commands(packager),
                "entrypoint": r.entrypoint(),
            }))
        }
        "framework.detect" => {
            let mut d = FrameworkDetector::new(a.opt_str("packager")?.unwrap_or("pnpm"));
            for name in strings(&a, "options")? {
                add_framework(&mut d, name)?;
            }
            for file in strings(&a, "files")? {
                d.add_file(file);
            }
            for manifest in a.opt("packages").and_then(Value::as_array).into_iter().flatten() {
                d.add_manifest(crate::adapter::bytes(manifest).ok_or_else(|| Fault::new("manifests are strings"))?);
            }
            for input in a.opt("inputs").and_then(Value::as_array).into_iter().flatten() {
                let i = Args(input);
                match Input::new(i.str("type")?) {
                    Ok(Input::File) => {
                        d.add_file(i.str("content")?);
                    }
                    Ok(Input::Packages) => {
                        d.add_manifest(i.bytes("content")?);
                    }
                    Err(e) => return Ok(err(e)),
                }
            }
            Outcome::Ok(detected(d.detect()))
        }
        "framework.describe" => {
            let f = framework(a.str("framework")?)?;
            let packager = a.opt_str("packager")?.unwrap_or("");
            Outcome::Ok(json!({
                "name": f.name(),
                "files": f.files(),
                "packages": f.packages(),
                "install": f.install_command(packager),
                "build": f.build_command(packager),
                "output": f.output_directory(),
                "configFiles": f.config_files(),
            }))
        }
        "framework.adapter" => {
            let f = framework(a.str("framework")?)?;
            Outcome::ok(adapter_name(f.adapter(&a.bytes("config")?)))
        }
        "framework.dependencies" => {
            Outcome::Ok(Value::Array(dependencies(&a.bytes("manifest")?).iter().map(|d| bytes_value(d)).collect()))
        }
        "rendering.detect" => {
            let mut d = RenderingDetector::new(a.str("framework")?);
            for option in a.opt("options").and_then(Value::as_array).into_iter().flatten() {
                d.add_option(Boxed(rendering(option)?));
            }
            for input in strings(&a, "inputs")? {
                d.add_input(input);
            }
            let r = d.detect();
            Outcome::Ok(json!({ "name": r.name(), "fallback": r.fallback_file() }))
        }
        "rendering.describe" => {
            let r = rendering(a.value("rendering")?)?;
            Outcome::Ok(json!({
                "name": r.name(),
                "files": r.files(a.str("framework")?),
                "fallback": r.fallback_file(),
            }))
        }
        "detect.fixture" => Outcome::Ok(fixture(a.str("fixture")?)?),
        _ => return Err(Fault::new(format!("detector: unknown operation `{op}`"))),
    };
    Ok(result)
}
