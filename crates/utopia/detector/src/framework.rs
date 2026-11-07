//! Frameworks: [`Framework`], the built-in frameworks, and the
//! [`FrameworkDetector`] that scores them on file names and manifest
//! dependencies.

use php_std::json::{self, DEFAULT_DEPTH, Flags};
use php_std::types::KeyRef;
use php_std::{Value, yaml};

use crate::{Detected, Error};

/// How a framework's build is served, as its configuration says.
///
/// PHP: the `'ssr'` and `'static'` strings `getAdapter()` returns (`''`
/// is `None`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Adapter {
    /// A server renders pages: `ssr`.
    Ssr,
    /// Pages are files: `static`.
    Static,
}

impl Adapter {
    /// `ssr` or `static`.
    pub fn as_str(&self) -> &'static str {
        match self {
            Adapter::Ssr => "ssr",
            Adapter::Static => "static",
        }
    }
}

/// A web framework, recognised by its configuration files and the packages
/// a project depends on.
///
/// PHP: `Utopia\Detector\Detection\Framework`.
pub trait Framework: Send + Sync {
    /// The framework's name, such as `nextjs`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The file names that reveal it, such as `next.config.js`.
    ///
    /// PHP: `getFiles()`.
    fn files(&self) -> &[&str];

    /// The dependencies that reveal it, such as `next`.
    ///
    /// PHP: `getPackages()`.
    fn packages(&self) -> &[&str] {
        &[]
    }

    /// The command that installs dependencies with `packager` (`npm`,
    /// `pnpm`, `yarn`; anything else, or empty, as pnpm).
    ///
    /// PHP: `getInstallCommand()`, with the packager `setPackager()` set.
    fn install_command(&self, packager: &str) -> &str;

    /// The command that builds with `packager`.
    ///
    /// PHP: `getBuildCommand()`, with the packager `setPackager()` set.
    fn build_command(&self, packager: &str) -> &str;

    /// Where the build writes its output, such as `./.next`.
    ///
    /// PHP: `getOutputDirectory()`.
    fn output_directory(&self) -> &str;

    /// The configuration files to read for [`Framework::adapter`], in
    /// priority order.
    ///
    /// PHP: `getConfigFiles()`.
    fn config_files(&self) -> &[&str] {
        &[]
    }

    /// How the build is served, read from a configuration file's content, or
    /// `None` when it cannot tell.
    ///
    /// PHP: `getAdapter($configContent)`.
    fn adapter(&self, config: &[u8]) -> Option<Adapter> {
        let _ = config;
        None
    }

    /// How many classes the framework's PHP class extends (React extends
    /// JS, Framework and Detection: 3). When frameworks tie on matches, the
    /// one with the fewest wins: the more general framework.
    ///
    /// PHP: the `get_parent_class()` chain `detect()` counts.
    fn parents(&self) -> usize;
}

/// `install` for the JavaScript frameworks: the packager's install command.
fn js_install(packager: &str) -> &'static str {
    match packager {
        "yarn" => "yarn install",
        "npm" => "npm install",
        _ => "pnpm install",
    }
}

/// `build` for the JavaScript frameworks: the packager's build script.
fn js_build(packager: &str) -> &'static str {
    match packager {
        "yarn" => "yarn build",
        "npm" => "npm run build",
        _ => "pnpm run build",
    }
}

/// `$content` without `//` comments, leaving `https://` alone.
///
/// PHP: `preg_replace('/(?<!:)\/\/[^\n]*/', '', $content)`: a `//` not right
/// after a `:` starts a comment that runs to the end of the line (the
/// newline stays). Scanned natively; `equivalence` tests pin it to the regex.
fn strip_comments(config: &[u8]) -> std::borrow::Cow<'_, [u8]> {
    if memchr::memmem::find(config, b"//").is_none() {
        return std::borrow::Cow::Borrowed(config);
    }
    let mut out = Vec::with_capacity(config.len());
    let (mut copied, mut i) = (0, 0);
    // Every `/`, so overlapping candidates (`:///`) are all tried, as the
    // regex tries every position.
    while let Some(n) = memchr::memchr(b'/', &config[i..]) {
        let at = i + n;
        if config.get(at + 1) == Some(&b'/') && (at == 0 || config[at - 1] != b':') {
            out.extend_from_slice(&config[copied..at]);
            copied = memchr::memchr(b'\n', &config[at..]).map_or(config.len(), |n| at + n);
            i = copied;
        } else {
            i = at + 1;
        }
    }
    out.extend_from_slice(&config[copied..]);
    std::borrow::Cow::Owned(out)
}

fn contains(haystack: &[u8], needle: &[u8]) -> bool {
    memchr::memmem::find(haystack, needle).is_some()
}

/// A PCRE word character outside UTF mode: `[A-Za-z0-9_]`.
fn word(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_'
}

/// PCRE's `\s` outside UTF mode: space, `\t`, `\n`, `\v`, `\f`, `\r`.
fn space(b: u8) -> bool {
    matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')
}

/// Where `s[i..]` continues after any `\s*`.
fn skip_spaces(s: &[u8], mut i: usize) -> usize {
    while i < s.len() && space(s[i]) {
        i += 1;
    }
    i
}

/// The positions just after each `\b<name>` in `s` (a word boundary before it).
fn words_at<'a>(s: &'a [u8], name: &'a [u8]) -> impl Iterator<Item = usize> + 'a {
    memchr::memmem::find_iter(s, name).filter(move |&i| i == 0 || !word(s[i - 1])).map(move |i| i + name.len())
}

/// Whether `s` matches `/\bprerender\b/`, and whether it matches
/// `/\bprerender[\x27\x22]?\s*:\s*false\b/`, in one pass.
fn prerender(s: &[u8]) -> (bool, bool) {
    let mut found = false;
    for mut i in words_at(s, b"prerender") {
        found |= i == s.len() || !word(s[i]);
        if i < s.len() && (s[i] == b'\'' || s[i] == b'"') {
            i += 1;
        }
        i = skip_spaces(s, i);
        if s.get(i) != Some(&b':') {
            continue;
        }
        i = skip_spaces(s, i + 1);
        if s[i..].starts_with(b"false") && s.get(i + 5).is_none_or(|&b| !word(b)) {
            return (true, true);
        }
    }
    (found, false)
}

/// `/\boutput\s*:\s*[\x27\x22\x60](?:server|hybrid)[\x27\x22\x60]/`.
fn output_server(s: &[u8]) -> bool {
    let quote = |b: Option<&u8>| matches!(b, Some(b'\'' | b'"' | b'`'));
    words_at(s, b"output").any(|i| {
        let i = skip_spaces(s, i);
        if s.get(i) != Some(&b':') {
            return false;
        }
        let i = skip_spaces(s, i + 1);
        if !quote(s.get(i)) {
            return false;
        }
        let rest = &s[i + 1..];
        (rest.starts_with(b"server") || rest.starts_with(b"hybrid")) && quote(rest.get(6))
    })
}

macro_rules! framework {
    (
        $(#[$doc:meta])* $ty:ident, $name:literal,
        files: $files:expr, packages: $packages:expr,
        install: $install:expr, build: $build:expr, output: $output:literal,
        config: $config:expr, adapter: $adapter:expr, parents: $parents:literal
    ) => {
        $(#[$doc])*
        #[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
        pub struct $ty;

        impl Framework for $ty {
            fn name(&self) -> &str {
                $name
            }

            fn files(&self) -> &[&str] {
                &$files
            }

            fn packages(&self) -> &[&str] {
                &$packages
            }

            fn install_command(&self, packager: &str) -> &str {
                let install: fn(&str) -> &'static str = $install;
                install(packager)
            }

            fn build_command(&self, packager: &str) -> &str {
                let build: fn(&str) -> &'static str = $build;
                build(packager)
            }

            fn output_directory(&self) -> &str {
                $output
            }

            fn config_files(&self) -> &[&str] {
                &$config
            }

            fn adapter(&self, config: &[u8]) -> Option<Adapter> {
                let adapter: fn(&[u8]) -> Option<Adapter> = $adapter;
                adapter(config)
            }

            fn parents(&self) -> usize {
                $parents
            }
        }
    };
}

framework!(
    /// Analog, the Angular meta-framework.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Analog`.
    Analog, "analog",
    files: ["angular.json"], packages: ["@analogjs/platform", "@angular/core"],
    install: js_install, build: js_build, output: "./dist/analog",
    config: [], adapter: |_| None, parents: 4
);

framework!(
    /// Angular.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Angular`.
    Angular, "angular",
    files: ["angular.json"], packages: ["@angular/core"],
    install: js_install, build: js_build, output: "./dist/angular",
    config: [], adapter: |_| None, parents: 3
);

framework!(
    /// Astro. It renders components of other frameworks, so their files and
    /// packages count for it too; a tie between Astro and another framework
    /// goes to the other one.
    ///
    /// The adapter is server-side when the configuration sets `output` to
    /// `server` or `hybrid`, and static otherwise.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Astro`.
    Astro, "astro",
    files: [
        "astro.config.mjs", "astro.config.js", "astro.config.ts", "angular.json",
        "svelte.config.js", "svelte.config.mjs", "svelte.config.ts",
    ],
    packages: ["astro", "@angular/core", "react", "vue", "svelte"],
    install: js_install, build: js_build, output: "./dist",
    config: ["astro.config.mjs", "astro.config.js", "astro.config.ts"],
    adapter: |config| {
        let stripped = strip_comments(config);
        Some(if output_server(&stripped) {
            Adapter::Ssr
        } else {
            Adapter::Static
        })
    },
    parents: 3
);

framework!(
    /// Flutter (web).
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Flutter`.
    Flutter, "flutter",
    files: ["pubspec.yaml", "pubspec.lock"], packages: ["flutter"],
    install: |_| "", build: |_| "flutter build web", output: "./build/web",
    config: [], adapter: |_| None, parents: 2
);

framework!(
    /// Jaspr, the Dart web framework. It is detected from the same pubspec
    /// files as Flutter and wins once a `jaspr` package appears, whatever
    /// order the options were added in.
    ///
    /// The adapter follows `jaspr.mode` in `pubspec.yaml`: `server` is
    /// server-side, `static` and `client` are static.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Jaspr`.
    Jaspr, "jaspr",
    files: ["pubspec.yaml", "pubspec.lock"], packages: ["jaspr", "jaspr_builder", "flutter"],
    install: |_| "dart pub get", build: |_| "jaspr build", output: "./build/jaspr",
    config: ["pubspec.yaml"],
    adapter: |config| {
        let Some(Value::Array(pubspec)) = yaml::parse(config) else { return None };
        let Some(Value::Array(jaspr)) = pubspec.get_ref(KeyRef::Str(b"jaspr")) else { return None };
        match jaspr.get_ref(KeyRef::Str(b"mode")) {
            Some(Value::Str(mode)) if mode.as_bytes() == b"server" => Some(Adapter::Ssr),
            Some(Value::Str(mode)) if mode.as_bytes() == b"static" || mode.as_bytes() == b"client" => {
                Some(Adapter::Static)
            }
            _ => None,
        }
    },
    parents: 3
);

framework!(
    /// Lynx.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Lynx`.
    Lynx, "lynx",
    files: ["lynx.config.ts", "lynx.config.js", "lynx.config.mjs"], packages: ["@lynx-js/react", "react"],
    install: js_install, build: js_build, output: "./dist",
    config: [], adapter: |_| None, parents: 4
);

framework!(
    /// Next.js.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\NextJs`.
    NextJs, "nextjs",
    files: ["next.config.js", "next.config.ts", "next.config.mjs"], packages: ["next", "react"],
    install: js_install, build: js_build, output: "./.next",
    config: [], adapter: |_| None, parents: 4
);

framework!(
    /// Nuxt.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Nuxt`.
    Nuxt, "nuxt",
    files: ["nuxt.config.js", "nuxt.config.ts", "nuxt.config.mjs"], packages: ["nuxt", "vue"],
    install: js_install, build: js_build, output: "./.output",
    config: [], adapter: |_| None, parents: 4
);

framework!(
    /// React.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\React`.
    React, "react",
    files: [], packages: ["react"],
    install: js_install, build: js_build, output: "./dist",
    config: [], adapter: |_| None, parents: 3
);

framework!(
    /// React Native.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\ReactNative`.
    ReactNative, "react-native",
    files: [], packages: ["react-native", "react"],
    install: js_install, build: js_build, output: "./dist",
    config: [], adapter: |_| None, parents: 4
);

framework!(
    /// Remix: always server-side.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Remix`.
    Remix, "remix",
    files: ["remix.config.js", "remix.config.ts", "remix.config.mjs"], packages: ["@remix-run/react", "react"],
    install: js_install, build: js_build, output: "./build",
    config: ["package.json"], adapter: |_| Some(Adapter::Ssr), parents: 4
);

framework!(
    /// Svelte.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Svelte`.
    Svelte, "svelte",
    files: ["svelte.config.js", "svelte.config.mjs", "svelte.config.ts"], packages: ["svelte"],
    install: js_install, build: js_build, output: "./build",
    config: [], adapter: |_| None, parents: 3
);

framework!(
    /// SvelteKit: static when the configuration (or `package.json`) uses
    /// `@sveltejs/adapter-static`, server-side otherwise.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\SvelteKit`.
    SvelteKit, "sveltekit",
    files: ["svelte.config.js", "svelte.config.mjs", "svelte.config.ts"], packages: ["@sveltejs/kit", "svelte"],
    install: js_install, build: js_build, output: "./build",
    config: ["svelte.config.js", "svelte.config.mjs", "svelte.config.ts", "package.json"],
    adapter: |config| {
        Some(if contains(&strip_comments(config), b"@sveltejs/adapter-static") { Adapter::Static } else { Adapter::Ssr })
    },
    parents: 4
);

framework!(
    /// TanStack Start: server-side with a Nitro plugin or without
    /// prerendering, static when it prerenders.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\TanStackStart`.
    TanStackStart, "tanstack-start",
    files: [], packages: ["@tanstack/react-start", "@tanstack/solid-start", "react"],
    install: js_install, build: js_build, output: "./.output",
    config: ["vite.config.ts", "vite.config.js", "vite.config.mjs"],
    adapter: |config| {
        let stripped = strip_comments(config);
        // Nitro emits `.output/server/index.mjs` even when every route is prerendered.
        if contains(&stripped, b"nitro/vite") || contains(&stripped, b"@tanstack/nitro-v2-vite-plugin") {
            return Some(Adapter::Ssr);
        }
        let (prerenders, disabled) = prerender(&stripped);
        if !prerenders || disabled {
            return Some(Adapter::Ssr);
        }
        Some(Adapter::Static)
    },
    parents: 4
);

framework!(
    /// Vue.
    ///
    /// PHP: `Utopia\Detector\Detection\Framework\Vue`.
    Vue, "vue",
    files: [], packages: ["vue"],
    install: js_install, build: js_build, output: "./dist",
    config: [], adapter: |_| None, parents: 3
);

/// What a framework detector input is.
///
/// PHP: the `$type` of `Framework::addInput()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Input {
    /// A file name of the project.
    ///
    /// PHP: `Framework::INPUT_FILE` (`file`).
    File,
    /// The content of a manifest (`package.json`, `pubspec.yaml`) whose
    /// dependencies are read.
    ///
    /// PHP: `Framework::INPUT_PACKAGES` (`packages`).
    Packages,
}

impl Input {
    /// The input kind named `kind`: `file` or `packages`.
    ///
    /// PHP: the check in `Framework::addInput()`, which throws for any
    /// other type.
    pub fn new(kind: &str) -> Result<Self, Error> {
        match kind {
            "file" => Ok(Input::File),
            "packages" => Ok(Input::Packages),
            _ => Err(Error::InvalidArgument(format!("Invalid input type '{kind}'"))),
        }
    }
}

/// The manifest sections whose keys are dependencies.
const DEPENDENCY_SECTIONS: &[&[u8]] = &[
    b"dependencies",
    b"devDependencies",
    b"peerDependencies",
    b"optionalDependencies",
    b"dev_dependencies",
    b"dependency_overrides",
];

/// The dependency names declared in a `package.json` (JSON) or
/// `pubspec.yaml` (YAML), section by section; none when it parses as
/// neither.
///
/// PHP: `Framework::dependencies()`.
pub fn dependencies(manifest: &[u8]) -> Vec<Vec<u8>> {
    let parsed = match json::decode(manifest, Some(true), DEFAULT_DEPTH, Flags::NONE) {
        Ok(Value::Array(a)) => a,
        _ => match yaml::parse(manifest) {
            Some(Value::Array(a)) => a,
            _ => return Vec::new(),
        },
    };
    let mut names = Vec::new();
    for section in DEPENDENCY_SECTIONS {
        if let Some(Value::Array(packages)) = parsed.get_ref(KeyRef::Str(section)) {
            names.extend(packages.keys().map(|k| match k {
                KeyRef::Int(i) => i.to_string().into_bytes(),
                KeyRef::Str(s) => s.to_vec(),
            }));
        }
    }
    names
}

/// Finds a project's framework by scoring every option: one point per
/// framework file among the file inputs, and one per framework package
/// declared in each manifest input. The highest score wins; a tie goes to
/// the framework with the fewest [parents](Framework::parents), except that
/// Astro yields to the runner-up.
///
/// Options are scored by name: two options with one name share a score.
///
/// PHP: `Utopia\Detector\Detector\Framework`.
///
/// ```
/// use utopia_detector::{FrameworkDetector, NextJs, React};
///
/// let mut detector = FrameworkDetector::new("npm");
/// detector.add_option(React).add_option(NextJs);
/// detector.add_file("next.config.js");
/// detector.add_manifest(r#"{"dependencies": {"react": "^18.0.0"}}"#);
///
/// let framework = detector.detect().expect("a framework");
/// assert_eq!(framework.name(), "nextjs");
/// assert_eq!(framework.install_command(), "npm install");
/// assert_eq!(framework.output_directory(), "./.next");
/// ```
///
/// ```php
/// use Utopia\Detector\Detection\Framework\NextJs;
/// use Utopia\Detector\Detection\Framework\React;
/// use Utopia\Detector\Detector\Framework;
///
/// $detector = new Framework('npm');
/// $detector->addOption(new React())->addOption(new NextJs());
/// $detector->addInput('next.config.js', Framework::INPUT_FILE);
/// $detector->addInput('{"dependencies": {"react": "^18.0.0"}}', Framework::INPUT_PACKAGES);
///
/// $framework = $detector->detect();
/// echo $framework->getName(), "\n";            // nextjs
/// echo $framework->getInstallCommand(), "\n";  // npm install
/// echo $framework->getOutputDirectory(), "\n"; // ./.next
/// ```
pub struct FrameworkDetector {
    packager: String,
    options: Vec<Box<dyn Framework>>,
    files: Vec<String>,
    manifests: Vec<Vec<u8>>,
}

impl FrameworkDetector {
    /// A detector for a project using `packager` (`pnpm` when unsure).
    ///
    /// PHP: `new Framework($packager)`.
    pub fn new(packager: impl Into<String>) -> Self {
        FrameworkDetector { packager: packager.into(), options: Vec::new(), files: Vec::new(), manifests: Vec::new() }
    }

    /// Adds a framework to score.
    ///
    /// PHP: `addOption($framework)`.
    pub fn add_option(&mut self, option: impl Framework + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// Adds a file name of the project.
    ///
    /// PHP: `addInput($file, Framework::INPUT_FILE)`.
    pub fn add_file(&mut self, file: impl Into<String>) -> &mut Self {
        self.files.push(file.into());
        self
    }

    /// Adds the content of a manifest (`package.json`, `pubspec.yaml`), whose
    /// dependencies count for the frameworks that list them.
    ///
    /// PHP: `addInput($content, Framework::INPUT_PACKAGES)`.
    pub fn add_manifest(&mut self, content: impl Into<Vec<u8>>) -> &mut Self {
        self.manifests.push(content.into());
        self
    }

    /// The best scoring framework, with the detector's packager, or `None`
    /// when no option scores.
    ///
    /// PHP: `detect()`, which also sets the packager on the framework it
    /// returns.
    pub fn detect(&self) -> Option<Detected<'_, dyn Framework>> {
        let manifests: Vec<Vec<Vec<u8>>> = self.manifests.iter().map(|m| dependencies(m)).collect();

        // Scores by name, in the order the names first appear: matches and parents.
        let mut scores: Vec<(&str, usize, usize)> = Vec::new();
        for option in &self.options {
            if !scores.iter().any(|(name, _, _)| *name == option.name()) {
                scores.push((option.name(), 0, 0));
            }
        }
        for option in &self.options {
            let Some(score) = scores.iter_mut().find(|(name, _, _)| *name == option.name()) else { continue };
            for dependencies in &manifests {
                score.1 += option.packages().iter().filter(|p| dependencies.iter().any(|d| d == p.as_bytes())).count();
            }
            score.1 += option.files().iter().filter(|f| self.files.iter().any(|i| i == *f)).count();
            score.2 += option.parents();
        }

        scores.retain(|(_, matches, _)| *matches > 0);
        // A stable sort, most matches first, as PHP's arsort().
        scores.sort_by_key(|s| std::cmp::Reverse(s.1));
        let highest = scores.first()?.1;
        scores.retain(|(_, matches, _)| *matches == highest);

        let best = if scores.len() == 1 {
            scores[0].0
        } else {
            // A stable sort, fewest parents first, as PHP's usort().
            scores.sort_by_key(|s| s.2);
            // Astro renders the other frameworks' components: an equal match
            // goes to the other framework.
            if scores[0].0 == Astro.name() && scores[0].1 == scores[1].1 { scores[1].0 } else { scores[0].0 }
        };

        self.options.iter().find(|o| o.name() == best).map(|o| Detected::new(o.as_ref(), &self.packager))
    }
}

impl std::fmt::Debug for FrameworkDetector {
    /// The packager, options' names and file inputs.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("FrameworkDetector")
            .field("packager", &self.packager)
            .field("options", &self.options.iter().map(|o| o.name()).collect::<Vec<_>>())
            .field("files", &self.files)
            .field("manifests", &self.manifests.len())
            .finish()
    }
}

impl<T: Framework + ?Sized> Detected<'_, T> {
    /// The install command for the detector's packager.
    ///
    /// PHP: `getInstallCommand()` on the detected framework.
    pub fn install_command(&self) -> &str {
        self.item().install_command(self.packager())
    }

    /// The build command for the detector's packager.
    ///
    /// PHP: `getBuildCommand()` on the detected framework.
    pub fn build_command(&self) -> &str {
        self.item().build_command(self.packager())
    }
}

/// The native scans against the regexes PHP runs, on generated configs.
#[cfg(test)]
mod equivalence {
    use php_std::pcre::{self, StrOrArray};

    use super::{output_server, prerender, strip_comments};

    fn regex_strip(s: &[u8]) -> Vec<u8> {
        match pcre::preg_replace(
            &StrOrArray::Str(br"/(?<!:)\/\/[^\n]*/"),
            &StrOrArray::Str(b""),
            &StrOrArray::Str(s),
            -1,
        ) {
            Ok(p) => match p.value.result {
                pcre::Value::Str(s) => s,
                _ => s.to_vec(),
            },
            Err(_) => s.to_vec(),
        }
    }

    fn regex(pattern: &[u8], s: &[u8]) -> bool {
        pcre::preg_match_bare(pattern, s).is_ok_and(|p| matches!(p.value, pcre::Value::Int(n) if n > 0))
    }

    /// Strings built from the pieces the patterns care about, by a small
    /// deterministic generator (xorshift), so failures replay.
    fn inputs() -> impl Iterator<Item = Vec<u8>> {
        const PIECES: &[&[u8]] = &[
            b"prerender",
            b"output",
            b"server",
            b"hybrid",
            b"false",
            b"falsey",
            b":",
            b"//",
            b"/",
            b"https:",
            b"\n",
            b" ",
            b"\t",
            b"\x0b",
            b"\x0c",
            b"\r",
            b"'",
            b"\"",
            b"`",
            b"_",
            b"x",
            b"9",
            b"\xc3\xa9",
            b"{",
            b"}",
            b"=",
            b"prerender:",
            b"enabled: true",
        ];
        let mut state = 0x9E37_79B9_7F4A_7C15u64;
        (0..30_000).map(move |_| {
            let mut next = || {
                state ^= state << 13;
                state ^= state >> 7;
                state ^= state << 17;
                state
            };
            let n = (next() % 12) as usize;
            (0..n).flat_map(|_| PIECES[(next() % PIECES.len() as u64) as usize].to_vec()).collect()
        })
    }

    #[test]
    fn native_scans_match_the_regexes() {
        for s in inputs() {
            assert_eq!(&*strip_comments(&s), &regex_strip(&s)[..], "strip {:?}", String::from_utf8_lossy(&s));
            let (found, disabled) = prerender(&s);
            assert_eq!(found, regex(br"/\bprerender\b/", &s), "prerender {:?}", String::from_utf8_lossy(&s));
            assert_eq!(
                disabled,
                regex(br"/\bprerender[\x27\x22]?\s*:\s*false\b/", &s),
                "prerender false {:?}",
                String::from_utf8_lossy(&s)
            );
            assert_eq!(
                output_server(&s),
                regex(br"/\boutput\s*:\s*[\x27\x22\x60](?:server|hybrid)[\x27\x22\x60]/", &s),
                "output {:?}",
                String::from_utf8_lossy(&s)
            );
        }
    }
}
