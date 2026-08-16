//! Rendering detections (PHP `Utopia\Detector\Detection\Rendering`).

use crate::detection::Detection;

/// PHP `Utopia\Detector\Detection\Rendering`.
pub trait Rendering: Detection {
    /// PHP `getName()`.
    fn get_name(&self) -> &'static str;
    /// PHP `getFiles($framework)`.
    fn get_files(&self, framework: &str) -> Vec<String>;
    /// PHP `getFallbackFile()`.
    fn get_fallback_file(&self) -> Option<&str>;
    /// Clone as a boxed detection.
    fn box_clone(&self) -> Box<dyn Rendering>;
}

impl Clone for Box<dyn Rendering> {
    fn clone(&self) -> Self {
        self.box_clone()
    }
}

impl std::fmt::Debug for dyn Rendering {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "Rendering {{ name: {}, fallback: {:?} }}",
            self.get_name(),
            self.get_fallback_file()
        )
    }
}

/// PHP `Utopia\Detector\Detection\Rendering\SSR`.
#[derive(Debug, Clone, Default)]
pub struct SSR {
    fallback_file: Option<String>,
}

impl SSR {
    /// PHP `FRAMEWORK_FILES`.
    pub const FRAMEWORK_FILES_NEXTJS: &'static [&'static str] = &[
        ".next/server/webpack-runtime.js",
        ".next/turbopack",
        "server.js",
    ];
    /// PHP `FRAMEWORK_FILES['nuxt']`.
    pub const FRAMEWORK_FILES_NUXT: &'static [&'static str] = &["server/index.mjs"];
    /// PHP `FRAMEWORK_FILES['sveltekit']`.
    pub const FRAMEWORK_FILES_SVELTEKIT: &'static [&'static str] = &["handler.js"];
    /// PHP `FRAMEWORK_FILES['astro']`.
    pub const FRAMEWORK_FILES_ASTRO: &'static [&'static str] = &["server/entry.mjs"];
    /// PHP `FRAMEWORK_FILES['remix']`.
    pub const FRAMEWORK_FILES_REMIX: &'static [&'static str] = &["build/server/index.js"];
    /// PHP `FRAMEWORK_FILES['angular']`.
    pub const FRAMEWORK_FILES_ANGULAR: &'static [&'static str] = &["server/server.mjs"];
    /// PHP `FRAMEWORK_FILES['analog']`.
    pub const FRAMEWORK_FILES_ANALOG: &'static [&'static str] = &["server/index.mjs"];
    /// PHP `FRAMEWORK_FILES['tanstack-start']`.
    pub const FRAMEWORK_FILES_TANSTACK_START: &'static [&'static str] =
        &["server/server.js", "server/index.mjs"];

    /// PHP `__construct(?string $fallbackFile = null)`.
    #[must_use]
    pub fn new(fallback_file: Option<String>) -> Self {
        Self { fallback_file }
    }
}

impl Detection for SSR {}

impl Rendering for SSR {
    fn get_name(&self) -> &'static str {
        "ssr"
    }

    fn get_files(&self, framework: &str) -> Vec<String> {
        match framework {
            "nextjs" => Self::FRAMEWORK_FILES_NEXTJS
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "nuxt" => Self::FRAMEWORK_FILES_NUXT
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "sveltekit" => Self::FRAMEWORK_FILES_SVELTEKIT
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "astro" => Self::FRAMEWORK_FILES_ASTRO
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "remix" => Self::FRAMEWORK_FILES_REMIX
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "angular" => Self::FRAMEWORK_FILES_ANGULAR
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "analog" => Self::FRAMEWORK_FILES_ANALOG
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            "tanstack-start" => Self::FRAMEWORK_FILES_TANSTACK_START
                .iter()
                .map(|s| (*s).to_string())
                .collect(),
            _ => Vec::new(),
        }
    }

    fn get_fallback_file(&self) -> Option<&str> {
        self.fallback_file.as_deref()
    }

    fn box_clone(&self) -> Box<dyn Rendering> {
        Box::new(self.clone())
    }
}

/// PHP `Utopia\Detector\Detection\Rendering\XStatic` (`getName()` returns `static`).
#[derive(Debug, Clone, Default)]
pub struct XStatic {
    fallback_file: Option<String>,
}

impl XStatic {
    /// PHP `__construct(?string $fallbackFile = null)`.
    #[must_use]
    pub fn new(fallback_file: Option<String>) -> Self {
        Self { fallback_file }
    }
}

impl Detection for XStatic {}

impl Rendering for XStatic {
    fn get_name(&self) -> &'static str {
        "static"
    }

    fn get_files(&self, _framework: &str) -> Vec<String> {
        Vec::new()
    }

    fn get_fallback_file(&self) -> Option<&str> {
        self.fallback_file.as_deref()
    }

    fn box_clone(&self) -> Box<dyn Rendering> {
        Box::new(self.clone())
    }
}
