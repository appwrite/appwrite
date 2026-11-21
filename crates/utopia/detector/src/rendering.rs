//! Rendering: [`Rendering`], server-side ([`Ssr`]) and static ([`Static`])
//! output, and the [`RenderingDetector`] that tells them apart from a build's
//! files.

use php_std::path::pathinfo_extension;

/// How a framework's build output is served, recognised by the files the
/// build leaves for a given framework.
///
/// PHP: `Utopia\Detector\Detection\Rendering`.
pub trait Rendering: Send + Sync {
    /// The rendering's name: `ssr` or `static`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The build files that reveal this rendering for `framework` (a
    /// framework name, such as `nextjs`).
    ///
    /// PHP: `getFiles($framework)`.
    fn files(&self, framework: &str) -> &[&str];

    /// The page to serve for paths without a file, if any.
    ///
    /// PHP: `getFallbackFile()`.
    fn fallback_file(&self) -> Option<&str>;
}

/// Server-side rendering: the build has a server entry for the framework.
///
/// PHP: `Utopia\Detector\Detection\Rendering\SSR`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Ssr {
    fallback_file: Option<String>,
}

impl Ssr {
    /// Server-side rendering without a fallback file.
    ///
    /// PHP: `new SSR()`.
    pub fn new() -> Self {
        Ssr::default()
    }

    /// Server-side rendering that serves `fallback_file` for paths without
    /// a file.
    ///
    /// PHP: `new SSR($fallbackFile)`.
    pub fn with_fallback(fallback_file: impl Into<String>) -> Self {
        Ssr { fallback_file: Some(fallback_file.into()) }
    }

    /// The server entry files of each framework's build.
    ///
    /// PHP: `SSR::FRAMEWORK_FILES`.
    pub const FRAMEWORK_FILES: &'static [(&'static str, &'static [&'static str])] = &[
        ("nextjs", &[".next/server/webpack-runtime.js", ".next/turbopack", "server.js"]),
        ("nuxt", &["server/index.mjs"]),
        ("sveltekit", &["handler.js"]),
        ("astro", &["server/entry.mjs"]),
        ("remix", &["build/server/index.js"]),
        ("angular", &["server/server.mjs"]),
        ("analog", &["server/index.mjs"]),
        ("tanstack-start", &["server/server.js", "server/index.mjs"]),
        ("flutter", &[]),
        ("jaspr", &["app"]),
        ("lynx", &[]),
    ];
}

impl Rendering for Ssr {
    /// `ssr`.
    fn name(&self) -> &str {
        "ssr"
    }

    /// The server entry files of `framework`, none for an unknown one.
    fn files(&self, framework: &str) -> &[&str] {
        Ssr::FRAMEWORK_FILES.iter().find(|(name, _)| *name == framework).map_or(&[], |(_, files)| files)
    }

    /// The fallback file given at construction.
    fn fallback_file(&self) -> Option<&str> {
        self.fallback_file.as_deref()
    }
}

/// Static output: plain files, with an optional fallback page.
///
/// PHP: `Utopia\Detector\Detection\Rendering\XStatic`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Static {
    fallback_file: Option<String>,
}

impl Static {
    /// Static output without a fallback file.
    ///
    /// PHP: `new XStatic()`.
    pub fn new() -> Self {
        Static::default()
    }

    /// Static output that serves `fallback_file` for paths without a file.
    ///
    /// PHP: `new XStatic($fallbackFile)`.
    pub fn with_fallback(fallback_file: impl Into<String>) -> Self {
        Static { fallback_file: Some(fallback_file.into()) }
    }
}

impl Rendering for Static {
    /// `static`.
    fn name(&self) -> &str {
        "static"
    }

    /// None: static output is what remains when nothing else matches.
    fn files(&self, _framework: &str) -> &[&str] {
        &[]
    }

    /// The fallback file given at construction.
    fn fallback_file(&self) -> Option<&str> {
        self.fallback_file.as_deref()
    }
}

/// What a [`RenderingDetector`] found: one of its options, or static output
/// it made up because none matched.
#[derive(Debug, Clone)]
pub enum Rendered<'a> {
    /// An option whose files are in the build.
    Option(&'a dyn Rendering),
    /// Static output with the build's only HTML file as the fallback, if it
    /// has exactly one.
    Static(Static),
}

impl Rendered<'_> {
    /// The rendering found.
    pub fn rendering(&self) -> &dyn Rendering {
        match self {
            Rendered::Option(r) => *r,
            Rendered::Static(s) => s,
        }
    }

    /// Its name: `ssr` or `static`.
    ///
    /// PHP: `getName()` on the detected rendering.
    pub fn name(&self) -> &str {
        self.rendering().name()
    }

    /// Its fallback file, if any.
    ///
    /// PHP: `getFallbackFile()` on the detected rendering.
    pub fn fallback_file(&self) -> Option<&str> {
        self.rendering().fallback_file()
    }
}

impl std::fmt::Debug for dyn Rendering + '_ {
    /// The name and fallback file.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Rendering").field("name", &self.name()).field("fallback_file", &self.fallback_file()).finish()
    }
}

/// Tells how a framework's build is served from the build's file names: the
/// first option, in the order they were added, with one of its files for the
/// framework among the inputs; otherwise static output, falling back to the
/// only HTML file when there is exactly one.
///
/// PHP: `Utopia\Detector\Detector\Rendering`.
///
/// ```
/// use utopia_detector::{RenderingDetector, Ssr, Static};
///
/// let mut detector = RenderingDetector::new("nextjs");
/// detector.add_option(Ssr::new()).add_option(Static::new());
/// detector.add_input("server/pages/index.html").add_input("server/pages/api/users.js");
///
/// let rendering = detector.detect();
/// assert_eq!(rendering.name(), "static");
/// assert_eq!(rendering.fallback_file(), Some("server/pages/index.html"));
/// ```
///
/// ```php
/// use Utopia\Detector\Detection\Rendering\SSR;
/// use Utopia\Detector\Detection\Rendering\XStatic;
/// use Utopia\Detector\Detector\Rendering;
///
/// $detector = new Rendering('nextjs');
/// $detector->addOption(new SSR())->addOption(new XStatic());
/// $detector->addInput('server/pages/index.html')->addInput('server/pages/api/users.js');
///
/// $rendering = $detector->detect();
/// echo $rendering->getName(), "\n";         // static
/// echo $rendering->getFallbackFile(), "\n"; // server/pages/index.html
/// ```
pub struct RenderingDetector {
    framework: String,
    options: Vec<Box<dyn Rendering>>,
    inputs: Vec<String>,
}

impl RenderingDetector {
    /// A detector for the build of `framework` (a framework name, such as
    /// `nextjs`).
    ///
    /// PHP: `new Rendering($framework)`.
    pub fn new(framework: impl Into<String>) -> Self {
        RenderingDetector { framework: framework.into(), options: Vec::new(), inputs: Vec::new() }
    }

    /// Adds a rendering, tried after the others.
    ///
    /// PHP: `addOption($rendering)`.
    pub fn add_option(&mut self, option: impl Rendering + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// Adds a file name of the build.
    ///
    /// PHP: `addInput($file)`.
    pub fn add_input(&mut self, file: impl Into<String>) -> &mut Self {
        self.inputs.push(file.into());
        self
    }

    fn static_fallback(&self) -> Static {
        let mut html = self.inputs.iter().filter(|f| pathinfo_extension(f.as_bytes()) == b"html");
        match (html.next(), html.next()) {
            (Some(only), None) => Static::with_fallback(only.clone()),
            _ => Static::new(),
        }
    }

    /// The first option with a file for the framework among the inputs, or
    /// static output (with the only HTML file as the fallback, when there is
    /// exactly one).
    ///
    /// PHP: `detect()`.
    pub fn detect(&self) -> Rendered<'_> {
        self.options
            .iter()
            .find(|r| r.files(&self.framework).iter().any(|f| self.inputs.iter().any(|i| i == f)))
            .map_or_else(|| Rendered::Static(self.static_fallback()), |r| Rendered::Option(r.as_ref()))
    }
}

impl std::fmt::Debug for RenderingDetector {
    /// The framework, options' names and inputs.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RenderingDetector")
            .field("framework", &self.framework)
            .field("options", &self.options.iter().map(|o| o.name()).collect::<Vec<_>>())
            .field("inputs", &self.inputs)
            .finish()
    }
}
