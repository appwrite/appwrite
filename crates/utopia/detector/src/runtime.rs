//! Runtimes: [`Runtime`], the eleven built-in runtimes, the [`Strategy`]
//! that decides what the inputs are, and the [`RuntimeDetector`].

use php_std::path::pathinfo_extension;

use crate::{Detected, Error};

/// A language runtime, recognised by its languages, file extensions or
/// files.
///
/// PHP: `Utopia\Detector\Detection\Runtime`.
pub trait Runtime: Send + Sync {
    /// The runtime's name, such as `node`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The languages it runs, such as `TypeScript`.
    ///
    /// PHP: `getLanguages()`.
    fn languages(&self) -> &[&str];

    /// The extensions of its source files, such as `ts`.
    ///
    /// PHP: `getFileExtensions()`.
    fn file_extensions(&self) -> &[&str];

    /// The file names that reveal it, such as `package-lock.json`.
    ///
    /// PHP: `getFiles()`.
    fn files(&self) -> &[&str];

    /// The command that installs dependencies and builds, for `packager`
    /// (`npm`, `pnpm`, `yarn`; anything else, or empty, as pnpm where it
    /// matters).
    ///
    /// PHP: `getCommands()`, with the packager `setPackager()` set.
    fn commands(&self, packager: &str) -> &str;

    /// The default entrypoint file, such as `index.js`.
    ///
    /// PHP: `getEntrypoint()`.
    fn entrypoint(&self) -> &str;
}

macro_rules! runtime {
    ($(#[$doc:meta])* $ty:ident, $name:literal, languages: $languages:expr, extensions: $extensions:expr, files: $files:expr, commands: $commands:expr, entrypoint: $entrypoint:literal) => {
        $(#[$doc])*
        #[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
        pub struct $ty;

        impl Runtime for $ty {
            fn name(&self) -> &str {
                $name
            }

            fn languages(&self) -> &[&str] {
                &$languages
            }

            fn file_extensions(&self) -> &[&str] {
                &$extensions
            }

            fn files(&self) -> &[&str] {
                &$files
            }

            fn commands(&self, packager: &str) -> &str {
                let commands: fn(&str) -> &'static str = $commands;
                commands(packager)
            }

            fn entrypoint(&self) -> &str {
                $entrypoint
            }
        }
    };
}

runtime!(
    /// Bun.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Bun`.
    Bun, "bun",
    languages: ["JavaScript", "TypeScript"],
    extensions: ["ts", "tsx", "js", "jsx"],
    files: ["bun.lockb"],
    commands: |_| "bun install && bun build",
    entrypoint: "main.ts"
);

runtime!(
    /// C++.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\CPP`.
    Cpp, "cpp",
    languages: ["C++"],
    extensions: ["cpp", "h", "hpp", "cxx", "cc"],
    files: ["main.cpp", "Solution", "CMakeLists.txt"],
    commands: |_| "g++ -o main.cpp && ./output",
    entrypoint: ""
);

runtime!(
    /// Dart.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Dart`.
    Dart, "dart",
    languages: ["Dart"],
    extensions: ["dart"],
    files: ["pubspec.yaml", "pubspec.lock"],
    commands: |_| "dart pub get",
    entrypoint: "main.dart"
);

runtime!(
    /// Deno.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Deno`.
    Deno, "deno",
    languages: ["TypeScript"],
    extensions: ["ts", "tsx"],
    files: ["mod.ts", "deps.ts"],
    commands: |_| "deno cache main.ts",
    entrypoint: "main.ts"
);

runtime!(
    /// .NET.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Dotnet`.
    Dotnet, "dotnet",
    languages: ["C#", "Visual Basic .NET"],
    extensions: ["cs", "vb", "sln", "csproj", "vbproj"],
    files: ["Program.cs", "Solution.sln", "Function.csproj", "Program.vb"],
    commands: |_| "dotnet restore && dotnet build",
    entrypoint: "Program.cs"
);

runtime!(
    /// Java.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Java`.
    Java, "java",
    languages: ["Java"],
    extensions: ["java", "class", "jar"],
    files: ["pom.xml", "pmd.xml", "build.gradle", "build.gradle.kts"],
    commands: |_| "mvn install && mvn package",
    entrypoint: "Main.java"
);

runtime!(
    /// Node.js: the only runtime whose install command follows the
    /// packager.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Node`.
    Node, "node",
    languages: ["JavaScript", "TypeScript"],
    extensions: ["js", "ts"],
    files: ["package-lock.json", "yarn.lock", "tsconfig.json"],
    commands: |packager| match packager {
        "yarn" => "yarn install",
        "npm" => "npm install",
        _ => "pnpm install",
    },
    entrypoint: "index.js"
);

runtime!(
    /// PHP.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\PHP`.
    Php, "php",
    languages: ["PHP"],
    extensions: ["php"],
    files: ["composer.json", "composer.lock"],
    commands: |_| "composer install && composer run build",
    entrypoint: "index.php"
);

runtime!(
    /// Python.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Python`.
    Python, "python",
    languages: ["Python"],
    extensions: ["py"],
    files: ["requirements.txt", "setup.py"],
    commands: |_| "pip install",
    entrypoint: "main.py"
);

runtime!(
    /// Ruby.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Ruby`.
    Ruby, "ruby",
    languages: ["Ruby"],
    extensions: ["rb"],
    files: ["Gemfile", "Gemfile.lock", "Rakefile", "Guardfile"],
    commands: |_| "bundle install && bundle exec rake build",
    entrypoint: "main.rb"
);

runtime!(
    /// Swift.
    ///
    /// PHP: `Utopia\Detector\Detection\Runtime\Swift`.
    Swift, "swift",
    languages: ["Swift"],
    extensions: ["swift", "xcodeproj", "xcworkspace"],
    files: ["Package.swift", "Podfile", "project.pbxproj"],
    commands: |_| "swift build",
    entrypoint: "main.swift"
);

/// What a [`RuntimeDetector`]'s inputs are, and so how they are matched.
///
/// PHP: `Utopia\Detector\Detector\Strategy`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Strategy {
    /// File names, matched against [`Runtime::files`].
    ///
    /// PHP: `Strategy::FILEMATCH`.
    FileMatch,
    /// File names, whose extensions are matched against
    /// [`Runtime::file_extensions`].
    ///
    /// PHP: `Strategy::EXTENSION`.
    Extension,
    /// Language names, matched against [`Runtime::languages`].
    ///
    /// PHP: `Strategy::LANGUAGES`.
    Languages,
}

impl Strategy {
    /// The strategy named `value`: `filematch`, `extension` or `languages`.
    ///
    /// PHP: `new Strategy($value)`, which throws for any other name.
    pub fn new(value: &str) -> Result<Self, Error> {
        match value {
            "filematch" => Ok(Strategy::FileMatch),
            "extension" => Ok(Strategy::Extension),
            "languages" => Ok(Strategy::Languages),
            _ => Err(Error::Exception(format!("Invalid strategy: {value}"))),
        }
    }

    /// The strategy's name.
    ///
    /// PHP: `getValue()`.
    pub fn value(&self) -> &'static str {
        match self {
            Strategy::FileMatch => "filematch",
            Strategy::Extension => "extension",
            Strategy::Languages => "languages",
        }
    }
}

/// Finds the runtime of a project: the first option, in the order they were
/// added, that matches an input the way the [`Strategy`] says.
///
/// PHP: `Utopia\Detector\Detector\Runtime`.
///
/// ```
/// use utopia_detector::{Node, Php, RuntimeDetector, Strategy};
///
/// let mut detector = RuntimeDetector::new(Strategy::Extension, "yarn");
/// detector.add_option(Php).add_option(Node);
/// detector.add_input("src/main.ts").add_input("Dockerfile");
///
/// let runtime = detector.detect().expect("a runtime");
/// assert_eq!(runtime.name(), "node");
/// assert_eq!(runtime.commands(), "yarn install");
/// ```
///
/// ```php
/// use Utopia\Detector\Detection\Runtime\Node;
/// use Utopia\Detector\Detection\Runtime\PHP;
/// use Utopia\Detector\Detector\Runtime;
/// use Utopia\Detector\Detector\Strategy;
///
/// $detector = new Runtime(new Strategy(Strategy::EXTENSION), 'yarn');
/// $detector->addOption(new PHP())->addOption(new Node());
/// $detector->addInput('src/main.ts')->addInput('Dockerfile');
///
/// $runtime = $detector->detect();
/// echo $runtime->getName(), "\n";     // node
/// echo $runtime->getCommands(), "\n"; // yarn install
/// ```
pub struct RuntimeDetector {
    strategy: Strategy,
    packager: String,
    options: Vec<Box<dyn Runtime>>,
    inputs: Vec<String>,
}

impl RuntimeDetector {
    /// A detector matching inputs by `strategy`, for a project using
    /// `packager` (`pnpm` when unsure).
    ///
    /// PHP: `new Runtime($strategy, $packager)`.
    pub fn new(strategy: Strategy, packager: impl Into<String>) -> Self {
        RuntimeDetector { strategy, packager: packager.into(), options: Vec::new(), inputs: Vec::new() }
    }

    /// Adds a runtime, tried after the others.
    ///
    /// PHP: `addOption($runtime)`.
    pub fn add_option(&mut self, option: impl Runtime + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// Adds an input: a file name, or a language name for
    /// [`Strategy::Languages`].
    ///
    /// PHP: `addInput($content)`.
    pub fn add_input(&mut self, input: impl Into<String>) -> &mut Self {
        self.inputs.push(input.into());
        self
    }

    /// The first runtime matching an input, with the detector's packager, or
    /// `None`.
    ///
    /// PHP: `detect()`, which also sets the packager on the runtime it
    /// returns.
    pub fn detect(&self) -> Option<Detected<'_, dyn Runtime>> {
        let found = match self.strategy {
            Strategy::FileMatch => {
                self.options.iter().find(|r| r.files().iter().any(|f| self.inputs.iter().any(|i| i == f)))
            }
            Strategy::Extension => {
                let extensions: Vec<&[u8]> = self.inputs.iter().map(|i| pathinfo_extension(i.as_bytes())).collect();
                self.options.iter().find(|r| r.file_extensions().iter().any(|e| extensions.contains(&e.as_bytes())))
            }
            Strategy::Languages => {
                self.options.iter().find(|r| r.languages().iter().any(|l| self.inputs.iter().any(|i| i == l)))
            }
        };
        found.map(|r| Detected::new(r.as_ref(), &self.packager))
    }
}

impl std::fmt::Debug for RuntimeDetector {
    /// The strategy, packager, options' names and inputs.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RuntimeDetector")
            .field("strategy", &self.strategy)
            .field("packager", &self.packager)
            .field("options", &self.options.iter().map(|o| o.name()).collect::<Vec<_>>())
            .field("inputs", &self.inputs)
            .finish()
    }
}

impl<T: Runtime + ?Sized> Detected<'_, T> {
    /// The install and build command for the detector's packager.
    ///
    /// PHP: `getCommands()` on the detected runtime.
    pub fn commands(&self) -> &str {
        self.item().commands(self.packager())
    }
}
