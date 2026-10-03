//! Runtime detections (PHP `Utopia\Detector\Detection\Runtime`).

use crate::detection::Detection;
use crate::util::js_install_command;

/// PHP `Utopia\Detector\Detection\Runtime`.
pub trait Runtime: Detection {
    /// PHP `getName()`.
    fn get_name(&self) -> &'static str;
    /// PHP `getLanguages()`.
    fn get_languages(&self) -> Vec<String>;
    /// PHP `getFileExtensions()`.
    fn get_file_extensions(&self) -> Vec<String>;
    /// PHP `getFiles()`.
    fn get_files(&self) -> Vec<String>;
    /// PHP `getCommands()`.
    fn get_commands(&self) -> String;
    /// PHP `getEntrypoint()`.
    fn get_entrypoint(&self) -> &'static str;
    /// PHP `setPackager()`.
    fn set_packager(&mut self, packager: String);
    /// Current packager (PHP `$packager`).
    fn packager(&self) -> &str;
    /// Clone as a boxed detection.
    fn box_clone(&self) -> Box<dyn Runtime>;
}

impl Clone for Box<dyn Runtime> {
    fn clone(&self) -> Self {
        self.box_clone()
    }
}

impl std::fmt::Debug for dyn Runtime {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "Runtime {{ name: {}, packager: {} }}",
            self.get_name(),
            self.packager()
        )
    }
}

macro_rules! runtime {
    (
        $name:ident,
        php_name = $php_name:literal,
        languages = [$($lang:literal),* $(,)?],
        extensions = [$($ext:literal),* $(,)?],
        files = [$($file:literal),* $(,)?],
        entrypoint = $entrypoint:literal,
        commands = $commands:expr
    ) => {
        /// PHP `Utopia\Detector\Detection\Runtime::$name`.
        #[derive(Debug, Clone, Default)]
        pub struct $name {
            packager: String,
        }

        impl $name {
            /// PHP `__construct()`.
            #[must_use]
            pub fn new() -> Self {
                Self::default()
            }
        }

        impl Detection for $name {}

        impl Runtime for $name {
            fn get_name(&self) -> &'static str {
                $php_name
            }
            fn get_languages(&self) -> Vec<String> {
                vec![$($lang.to_string()),*]
            }
            fn get_file_extensions(&self) -> Vec<String> {
                vec![$($ext.to_string()),*]
            }
            fn get_files(&self) -> Vec<String> {
                vec![$($file.to_string()),*]
            }
            fn get_commands(&self) -> String {
                #[allow(clippy::redundant_closure_call)]
                ($commands)(&self.packager)
            }
            fn get_entrypoint(&self) -> &'static str {
                $entrypoint
            }
            fn set_packager(&mut self, packager: String) {
                self.packager = packager;
            }
            fn packager(&self) -> &str {
                &self.packager
            }
            fn box_clone(&self) -> Box<dyn Runtime> {
                Box::new(self.clone())
            }
        }
    };
}

runtime!(
    Node,
    php_name = "node",
    languages = ["JavaScript", "TypeScript"],
    extensions = ["js", "ts"],
    files = ["package-lock.json", "yarn.lock", "tsconfig.json"],
    entrypoint = "index.js",
    commands = |packager: &str| js_install_command(packager)
);

runtime!(
    Bun,
    php_name = "bun",
    languages = ["JavaScript", "TypeScript"],
    extensions = ["ts", "tsx", "js", "jsx"],
    files = ["bun.lockb"],
    entrypoint = "main.ts",
    commands = |_packager: &str| "bun install && bun build".to_string()
);

runtime!(
    Deno,
    php_name = "deno",
    languages = ["TypeScript"],
    extensions = ["ts", "tsx"],
    files = ["mod.ts", "deps.ts"],
    entrypoint = "main.ts",
    commands = |_packager: &str| "deno cache main.ts".to_string()
);

runtime!(
    PHP,
    php_name = "php",
    languages = ["PHP"],
    extensions = ["php"],
    files = ["composer.json", "composer.lock"],
    entrypoint = "index.php",
    commands = |_packager: &str| "composer install && composer run build".to_string()
);

runtime!(
    Python,
    php_name = "python",
    languages = ["Python"],
    extensions = ["py"],
    files = ["requirements.txt", "setup.py"],
    entrypoint = "main.py",
    commands = |_packager: &str| "pip install".to_string()
);

runtime!(
    Dart,
    php_name = "dart",
    languages = ["Dart"],
    extensions = ["dart"],
    files = ["pubspec.yaml", "pubspec.lock"],
    entrypoint = "main.dart",
    commands = |_packager: &str| "dart pub get".to_string()
);

runtime!(
    Swift,
    php_name = "swift",
    languages = ["Swift"],
    extensions = ["swift", "xcodeproj", "xcworkspace"],
    files = ["Package.swift", "Podfile", "project.pbxproj"],
    entrypoint = "main.swift",
    commands = |_packager: &str| "swift build".to_string()
);

runtime!(
    Ruby,
    php_name = "ruby",
    languages = ["Ruby"],
    extensions = ["rb"],
    files = ["Gemfile", "Gemfile.lock", "Rakefile", "Guardfile"],
    entrypoint = "main.rb",
    commands = |_packager: &str| "bundle install && bundle exec rake build".to_string()
);

runtime!(
    Java,
    php_name = "java",
    languages = ["Java"],
    extensions = ["java", "class", "jar"],
    files = ["pom.xml", "pmd.xml", "build.gradle", "build.gradle.kts"],
    entrypoint = "Main.java",
    commands = |_packager: &str| "mvn install && mvn package".to_string()
);

runtime!(
    CPP,
    php_name = "cpp",
    languages = ["C++"],
    extensions = ["cpp", "h", "hpp", "cxx", "cc"],
    files = ["main.cpp", "Solution", "CMakeLists.txt"],
    entrypoint = "",
    commands = |_packager: &str| "g++ -o main.cpp && ./output".to_string()
);

runtime!(
    Dotnet,
    php_name = "dotnet",
    languages = ["C#", "Visual Basic .NET"],
    extensions = ["cs", "vb", "sln", "csproj", "vbproj"],
    files = [
        "Program.cs",
        "Solution.sln",
        "Function.csproj",
        "Program.vb"
    ],
    entrypoint = "Program.cs",
    commands = |_packager: &str| "dotnet restore && dotnet build".to_string()
);
