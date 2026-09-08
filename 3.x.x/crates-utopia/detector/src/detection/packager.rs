//! Packager detections (PHP `Utopia\Detector\Detection\Packager`).

use crate::detection::Detection;

/// PHP `Utopia\Detector\Detection\Packager`.
pub trait Packager: Detection {
    /// PHP `getName()`.
    fn get_name(&self) -> &'static str;
    /// PHP `getFiles()`.
    fn get_files(&self) -> Vec<String>;
    /// Clone as a boxed detection.
    fn box_clone(&self) -> Box<dyn Packager>;
}

impl Clone for Box<dyn Packager> {
    fn clone(&self) -> Self {
        self.box_clone()
    }
}

impl std::fmt::Debug for dyn Packager {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "Packager {{ name: {} }}", self.get_name())
    }
}

macro_rules! packager {
    ($name:ident, php_name = $php_name:literal, files = [$($file:literal),* $(,)?]) => {
        /// PHP `Utopia\Detector\Detection\Packager::$name`.
        #[derive(Debug, Clone, Default)]
        pub struct $name;

        impl $name {
            /// PHP `__construct()`.
            #[must_use]
            pub fn new() -> Self {
                Self
            }
        }

        impl Detection for $name {}

        impl Packager for $name {
            fn get_name(&self) -> &'static str {
                $php_name
            }
            fn get_files(&self) -> Vec<String> {
                vec![$($file.to_string()),*]
            }
            fn box_clone(&self) -> Box<dyn Packager> {
                Box::new(self.clone())
            }
        }
    };
}

packager!(PNPM, php_name = "pnpm", files = ["pnpm-lock.yaml"]);
packager!(Yarn, php_name = "yarn", files = ["yarn.lock"]);
packager!(
    NPM,
    php_name = "npm",
    files = ["package.json", "package-lock.json"]
);
