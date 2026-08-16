//! Framework detections (PHP `Utopia\Detector\Detection\Framework`).

use crate::detection::Detection;
use crate::util::{
    astro_ssr_output_re, js_build_command, js_install_command, strip_js_line_comments,
    tanstack_prerender_false_re, tanstack_prerender_re, unique_preserve,
};

/// PHP `Utopia\Detector\Detection\Framework`.
pub trait Framework: Detection {
    /// PHP `getName()`.
    fn get_name(&self) -> &'static str;
    /// PHP `getFiles()`.
    fn get_files(&self) -> Vec<String>;
    /// PHP `getPackages()`.
    fn get_packages(&self) -> Vec<String> {
        Vec::new()
    }
    /// PHP `getInstallCommand()`.
    fn get_install_command(&self) -> String;
    /// PHP `getBuildCommand()`.
    fn get_build_command(&self) -> String;
    /// PHP `getOutputDirectory()`.
    fn get_output_directory(&self) -> &'static str;
    /// PHP `getConfigFiles()`.
    fn get_config_files(&self) -> Vec<String> {
        Vec::new()
    }
    /// PHP `getAdapter()`.
    fn get_adapter(&self, _config_content: &str) -> String {
        String::new()
    }
    /// PHP `setPackager()`.
    fn set_packager(&mut self, packager: String);
    /// Current packager (PHP `$packager`).
    fn packager(&self) -> &str;
    /// PHP `get_parent_class` chain length (used as a tie-breaker).
    fn parent_count(&self) -> usize;
    /// Clone as a boxed detection (PHP returns the option instance).
    fn box_clone(&self) -> Box<dyn Framework>;
}

impl Clone for Box<dyn Framework> {
    fn clone(&self) -> Self {
        self.box_clone()
    }
}

impl std::fmt::Debug for dyn Framework {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "Framework {{ name: {}, packager: {} }}",
            self.get_name(),
            self.packager()
        )
    }
}

macro_rules! framework_boilerplate {
    ($parent_count:expr) => {
        fn set_packager(&mut self, packager: String) {
            self.packager = packager;
        }
        fn packager(&self) -> &str {
            &self.packager
        }
        fn parent_count(&self) -> usize {
            $parent_count
        }
        fn box_clone(&self) -> Box<dyn Framework> {
            Box::new(self.clone())
        }
        fn get_install_command(&self) -> String {
            js_install_command(&self.packager)
        }
        fn get_build_command(&self) -> String {
            js_build_command(&self.packager)
        }
    };
}

/// PHP `Utopia\Detector\Detection\Framework\JS`.
#[derive(Debug, Clone, Default)]
pub struct JS {
    packager: String,
}

impl JS {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for JS {}

impl Framework for JS {
    fn get_name(&self) -> &'static str {
        "js"
    }
    fn get_files(&self) -> Vec<String> {
        vec!["package.json".to_string()]
    }
    fn get_packages(&self) -> Vec<String> {
        Vec::new()
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    framework_boilerplate!(2);
}

/// PHP `Utopia\Detector\Detection\Framework\Flutter`.
#[derive(Debug, Clone, Default)]
pub struct Flutter {
    packager: String,
}

impl Flutter {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Flutter {}

impl Framework for Flutter {
    fn get_name(&self) -> &'static str {
        "flutter"
    }
    fn get_files(&self) -> Vec<String> {
        vec!["pubspec.yaml".to_string(), "pubspec.lock".to_string()]
    }
    fn get_install_command(&self) -> String {
        String::new()
    }
    fn get_build_command(&self) -> String {
        "flutter build web".to_string()
    }
    fn get_output_directory(&self) -> &'static str {
        "./build/web"
    }
    fn set_packager(&mut self, packager: String) {
        self.packager = packager;
    }
    fn packager(&self) -> &str {
        &self.packager
    }
    fn parent_count(&self) -> usize {
        2
    }
    fn box_clone(&self) -> Box<dyn Framework> {
        Box::new(self.clone())
    }
}

/// PHP `Utopia\Detector\Detection\Framework\React`.
#[derive(Debug, Clone, Default)]
pub struct React {
    packager: String,
}

impl React {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for React {}

impl Framework for React {
    fn get_name(&self) -> &'static str {
        "react"
    }
    fn get_files(&self) -> Vec<String> {
        JS::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["react".to_string()];
        packages.extend(JS::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    framework_boilerplate!(3);
}

/// PHP `Utopia\Detector\Detection\Framework\Vue`.
#[derive(Debug, Clone, Default)]
pub struct Vue {
    packager: String,
}

impl Vue {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Vue {}

impl Framework for Vue {
    fn get_name(&self) -> &'static str {
        "vue"
    }
    fn get_files(&self) -> Vec<String> {
        JS::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["vue".to_string()];
        packages.extend(JS::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    framework_boilerplate!(3);
}

/// PHP `Utopia\Detector\Detection\Framework\Svelte`.
#[derive(Debug, Clone, Default)]
pub struct Svelte {
    packager: String,
}

impl Svelte {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Svelte {}

impl Framework for Svelte {
    fn get_name(&self) -> &'static str {
        "svelte"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "svelte.config.js".to_string(),
            "svelte.config.mjs".to_string(),
            "svelte.config.ts".to_string(),
        ];
        files.extend(JS::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["svelte".to_string()];
        packages.extend(JS::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./build"
    }
    framework_boilerplate!(3);
}

/// PHP `Utopia\Detector\Detection\Framework\Angular`.
#[derive(Debug, Clone, Default)]
pub struct Angular {
    packager: String,
}

impl Angular {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Angular {}

impl Framework for Angular {
    fn get_name(&self) -> &'static str {
        "angular"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec!["angular.json".to_string()];
        files.extend(JS::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["@angular/core".to_string()];
        packages.extend(JS::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist/angular"
    }
    framework_boilerplate!(3);
}

/// PHP `Utopia\Detector\Detection\Framework\Astro`.
#[derive(Debug, Clone, Default)]
pub struct Astro {
    packager: String,
}

impl Astro {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Astro {}

impl Framework for Astro {
    fn get_name(&self) -> &'static str {
        "astro"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "astro.config.mjs".to_string(),
            "astro.config.js".to_string(),
            "astro.config.ts".to_string(),
        ];
        files.extend(JS::new().get_files());
        files.extend(Angular::new().get_files());
        files.extend(React::new().get_files());
        files.extend(Vue::new().get_files());
        files.extend(Svelte::new().get_files());
        unique_preserve(files)
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["astro".to_string()];
        packages.extend(JS::new().get_packages());
        packages.extend(Angular::new().get_packages());
        packages.extend(React::new().get_packages());
        packages.extend(Vue::new().get_packages());
        packages.extend(Svelte::new().get_packages());
        unique_preserve(packages)
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    fn get_config_files(&self) -> Vec<String> {
        vec![
            "astro.config.mjs".to_string(),
            "astro.config.js".to_string(),
            "astro.config.ts".to_string(),
        ]
    }
    fn get_adapter(&self, config_content: &str) -> String {
        let stripped = strip_js_line_comments(config_content);
        if astro_ssr_output_re().is_match(&stripped) {
            "ssr".to_string()
        } else {
            "static".to_string()
        }
    }
    framework_boilerplate!(3);
}

/// PHP `Utopia\Detector\Detection\Framework\NextJs`.
#[derive(Debug, Clone, Default)]
pub struct NextJs {
    packager: String,
}

impl NextJs {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for NextJs {}

impl Framework for NextJs {
    fn get_name(&self) -> &'static str {
        "nextjs"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "next.config.js".to_string(),
            "next.config.ts".to_string(),
            "next.config.mjs".to_string(),
        ];
        files.extend(React::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["next".to_string()];
        packages.extend(React::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./.next"
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\Remix`.
#[derive(Debug, Clone, Default)]
pub struct Remix {
    packager: String,
}

impl Remix {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Remix {}

impl Framework for Remix {
    fn get_name(&self) -> &'static str {
        "remix"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "remix.config.js".to_string(),
            "remix.config.ts".to_string(),
            "remix.config.mjs".to_string(),
        ];
        files.extend(React::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["@remix-run/react".to_string()];
        packages.extend(React::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./build"
    }
    fn get_config_files(&self) -> Vec<String> {
        vec!["package.json".to_string()]
    }
    fn get_adapter(&self, _config_content: &str) -> String {
        "ssr".to_string()
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\Lynx`.
#[derive(Debug, Clone, Default)]
pub struct Lynx {
    packager: String,
}

impl Lynx {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Lynx {}

impl Framework for Lynx {
    fn get_name(&self) -> &'static str {
        "lynx"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "lynx.config.ts".to_string(),
            "lynx.config.js".to_string(),
            "lynx.config.mjs".to_string(),
        ];
        files.extend(React::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["@lynx-js/react".to_string()];
        packages.extend(React::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\ReactNative`.
#[derive(Debug, Clone, Default)]
pub struct ReactNative {
    packager: String,
}

impl ReactNative {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for ReactNative {}

impl Framework for ReactNative {
    fn get_name(&self) -> &'static str {
        "react-native"
    }
    fn get_files(&self) -> Vec<String> {
        React::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["react-native".to_string()];
        packages.extend(React::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist"
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\TanStackStart`.
#[derive(Debug, Clone, Default)]
pub struct TanStackStart {
    packager: String,
}

impl TanStackStart {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for TanStackStart {}

impl Framework for TanStackStart {
    fn get_name(&self) -> &'static str {
        "tanstack-start"
    }
    fn get_files(&self) -> Vec<String> {
        React::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec![
            "@tanstack/react-start".to_string(),
            "@tanstack/solid-start".to_string(),
        ];
        packages.extend(React::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./.output"
    }
    fn get_config_files(&self) -> Vec<String> {
        vec![
            "vite.config.ts".to_string(),
            "vite.config.js".to_string(),
            "vite.config.mjs".to_string(),
        ]
    }
    fn get_adapter(&self, config_content: &str) -> String {
        let stripped = strip_js_line_comments(config_content);
        if !tanstack_prerender_re().is_match(&stripped)
            || tanstack_prerender_false_re().is_match(&stripped)
        {
            "ssr".to_string()
        } else {
            "static".to_string()
        }
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\Nuxt`.
#[derive(Debug, Clone, Default)]
pub struct Nuxt {
    packager: String,
}

impl Nuxt {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Nuxt {}

impl Framework for Nuxt {
    fn get_name(&self) -> &'static str {
        "nuxt"
    }
    fn get_files(&self) -> Vec<String> {
        let mut files = vec![
            "nuxt.config.js".to_string(),
            "nuxt.config.ts".to_string(),
            "nuxt.config.mjs".to_string(),
        ];
        files.extend(Vue::new().get_files());
        files
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["nuxt".to_string()];
        packages.extend(Vue::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./.output"
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\Analog`.
#[derive(Debug, Clone, Default)]
pub struct Analog {
    packager: String,
}

impl Analog {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for Analog {}

impl Framework for Analog {
    fn get_name(&self) -> &'static str {
        "analog"
    }
    fn get_files(&self) -> Vec<String> {
        Angular::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["@analogjs/platform".to_string()];
        packages.extend(Angular::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./dist/analog"
    }
    framework_boilerplate!(4);
}

/// PHP `Utopia\Detector\Detection\Framework\SvelteKit`.
#[derive(Debug, Clone, Default)]
pub struct SvelteKit {
    packager: String,
}

impl SvelteKit {
    /// PHP `__construct()`.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }
}

impl Detection for SvelteKit {}

impl Framework for SvelteKit {
    fn get_name(&self) -> &'static str {
        "sveltekit"
    }
    fn get_files(&self) -> Vec<String> {
        Svelte::new().get_files()
    }
    fn get_packages(&self) -> Vec<String> {
        let mut packages = vec!["@sveltejs/kit".to_string()];
        packages.extend(Svelte::new().get_packages());
        packages
    }
    fn get_output_directory(&self) -> &'static str {
        "./build"
    }
    fn get_config_files(&self) -> Vec<String> {
        vec![
            "svelte.config.js".to_string(),
            "svelte.config.mjs".to_string(),
            "svelte.config.ts".to_string(),
            "package.json".to_string(),
        ]
    }
    fn get_adapter(&self, config_content: &str) -> String {
        let stripped = strip_js_line_comments(config_content);
        if stripped.contains("@sveltejs/adapter-static") {
            "static".to_string()
        } else {
            "ssr".to_string()
        }
    }
    framework_boilerplate!(4);
}
