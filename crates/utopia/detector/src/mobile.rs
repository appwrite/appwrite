//! Mobile projects: [`Mobile`], the built-in [`Flutter`], [`ReactNative`],
//! [`Android`] and [`Ios`], and the [`MobileDetector`] that picks the
//! matching one covering the most platforms.
//!
//! Only runtime dependencies count: a React Native or Flutter library lists
//! the framework as a dev or peer dependency, an app ships it. Gradle input
//! counts a plugin id wherever it is quoted outside comments, which covers
//! a root build declaring it with `apply false` and a version catalog.

use php_std::json::{self, DEFAULT_DEPTH, Flags};
use php_std::path::{basename, pathinfo_extension};
use php_std::string::{TRIM_CHARACTERS, trim};
use php_std::types::KeyRef;
use php_std::{Value, yaml};

use crate::Error;

/// A platform a mobile project builds apps for.
///
/// PHP: the `PLATFORM_*` constants of `Utopia\Detector\Detection\Mobile`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Platform {
    /// Android: `android`.
    ///
    /// PHP: `Mobile::PLATFORM_ANDROID`.
    Android,
    /// iOS: `ios`.
    ///
    /// PHP: `Mobile::PLATFORM_IOS`.
    Ios,
}

impl Platform {
    /// `android` or `ios`.
    pub fn as_str(&self) -> &'static str {
        match self {
            Platform::Android => "android",
            Platform::Ios => "ios",
        }
    }
}

/// A mobile toolchain, recognised by file names, file or directory
/// extensions, runtime dependencies or Gradle plugin ids.
///
/// PHP: `Utopia\Detector\Detection\Mobile`.
pub trait Mobile: Send + Sync {
    /// The toolchain's name, such as `react-native`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The platforms the project builds apps for.
    ///
    /// PHP: `getPlatforms()`.
    fn platforms(&self) -> &[Platform];

    /// The file names that reveal it, such as `Podfile`.
    ///
    /// PHP: `getFiles()`.
    fn files(&self) -> &[&str] {
        &[]
    }

    /// The file or directory extensions that reveal it, such as
    /// `xcodeproj`.
    ///
    /// PHP: `getFileExtensions()`.
    fn file_extensions(&self) -> &[&str] {
        &[]
    }

    /// The runtime dependencies that reveal it, such as `flutter`.
    ///
    /// PHP: `getPackages()`.
    fn packages(&self) -> &[&str] {
        &[]
    }

    /// The Gradle plugin ids that reveal it, such as
    /// `com.android.application`.
    ///
    /// PHP: `getPlugins()`.
    fn plugins(&self) -> &[&str] {
        &[]
    }
}

macro_rules! mobile {
    (
        $(#[$doc:meta])* $ty:ident, $name:literal, platforms: $platforms:expr,
        files: $files:expr, extensions: $extensions:expr, packages: $packages:expr, plugins: $plugins:expr
    ) => {
        $(#[$doc])*
        #[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
        pub struct $ty;

        impl Mobile for $ty {
            fn name(&self) -> &str {
                $name
            }

            fn platforms(&self) -> &[Platform] {
                &$platforms
            }

            fn files(&self) -> &[&str] {
                &$files
            }

            fn file_extensions(&self) -> &[&str] {
                &$extensions
            }

            fn packages(&self) -> &[&str] {
                &$packages
            }

            fn plugins(&self) -> &[&str] {
                &$plugins
            }
        }
    };
}

mobile!(
    /// A native Android app: a Gradle script applying or declaring
    /// `com.android.application`.
    ///
    /// PHP: `Utopia\Detector\Detection\Mobile\Android`.
    Android, "android", platforms: [Platform::Android],
    files: [], extensions: [], packages: [], plugins: ["com.android.application"]
);

mobile!(
    /// A Flutter app: `flutter` among the `pubspec.yaml` dependencies.
    ///
    /// PHP: `Utopia\Detector\Detection\Mobile\Flutter`.
    Flutter, "flutter", platforms: [Platform::Android, Platform::Ios],
    files: [], extensions: [], packages: ["flutter"], plugins: []
);

mobile!(
    /// A native iOS app: an Xcode project or workspace, or a `Podfile`.
    ///
    /// PHP: `Utopia\Detector\Detection\Mobile\Ios`.
    Ios, "ios", platforms: [Platform::Ios],
    files: ["Podfile"], extensions: ["xcodeproj", "xcworkspace"], packages: [], plugins: []
);

mobile!(
    /// A React Native app: `react-native` among the `package.json`
    /// dependencies.
    ///
    /// PHP: `Utopia\Detector\Detection\Mobile\ReactNative`.
    ReactNative, "react-native", platforms: [Platform::Android, Platform::Ios],
    files: [], extensions: [], packages: ["react-native"], plugins: []
);

/// What a mobile detector input is.
///
/// PHP: the `$type` of `Mobile::addInput()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Input {
    /// A file name or path of the project.
    ///
    /// PHP: `Mobile::INPUT_FILE` (`file`).
    File,
    /// The content of a `package.json` or `pubspec.yaml`, whose runtime
    /// dependencies are read.
    ///
    /// PHP: `Mobile::INPUT_PACKAGES` (`packages`).
    Packages,
    /// The content of a Gradle script or version catalog.
    ///
    /// PHP: `Mobile::INPUT_GRADLE` (`gradle`).
    Gradle,
}

impl Input {
    /// Every input kind, in the order PHP's `Mobile::INPUTS` lists them.
    pub const ALL: [Input; 3] = [Input::File, Input::Packages, Input::Gradle];

    /// The input kind named `kind`: `file`, `packages` or `gradle`.
    ///
    /// PHP: the check in `Mobile::addInput()`, which throws for any other
    /// type.
    pub fn new(kind: &str) -> Result<Self, Error> {
        Input::ALL
            .into_iter()
            .find(|input| input.as_str() == kind)
            .ok_or_else(|| Error::InvalidArgument(format!("Invalid input type '{kind}'")))
    }

    /// `file`, `packages` or `gradle`.
    pub fn as_str(&self) -> &'static str {
        match self {
            Input::File => "file",
            Input::Packages => "packages",
            Input::Gradle => "gradle",
        }
    }
}

/// The quotes a Gradle plugin id is written between.
///
/// PHP: `Mobile::QUOTES`.
pub const QUOTES: [&str; 2] = ["'", "\""];

/// The markers that start a Gradle or TOML line comment.
///
/// PHP: `Mobile::LINE_COMMENTS`.
pub const LINE_COMMENTS: [&str; 2] = ["//", "#"];

/// The runtime dependency names of a `package.json` (JSON) or a
/// `pubspec.yaml` (YAML): the keys of its `dependencies`. Development and
/// peer dependencies are ignored. None for a blank manifest, one that parses
/// as neither, or one without a `dependencies` map.
///
/// PHP: `Mobile::dependencies()`.
pub fn dependencies(manifest: &[u8]) -> Vec<Vec<u8>> {
    if trim(manifest, TRIM_CHARACTERS).is_empty() {
        return Vec::new();
    }
    let parsed = match json::decode(manifest, Some(true), DEFAULT_DEPTH, Flags::NONE) {
        Ok(Value::Array(a)) => a,
        _ => match yaml::parse(manifest) {
            Some(Value::Array(a)) => a,
            _ => return Vec::new(),
        },
    };
    let Some(Value::Array(dependencies)) = parsed.get_ref(KeyRef::Str(b"dependencies")) else {
        return Vec::new();
    };
    dependencies
        .keys()
        .map(|k| match k {
            KeyRef::Int(i) => i.to_string().into_bytes(),
            KeyRef::Str(s) => s.to_vec(),
        })
        .collect()
}

/// The lines of a Gradle script or version catalog that are not comments,
/// trimmed. A line starting `/*` opens a block comment that its first line
/// containing `*/` closes; a line starting `//` or `#` is a comment.
///
/// PHP: `Mobile::statements()`.
pub fn statements(script: &[u8]) -> Vec<&[u8]> {
    let mut statements = Vec::new();
    let mut commented = false;
    for line in script.split(|&b| b == b'\n') {
        let line = trim(line, TRIM_CHARACTERS);
        if commented || line.starts_with(b"/*") {
            commented = memchr::memmem::find(line, b"*/").is_none();
            continue;
        }
        if line.is_empty() || LINE_COMMENTS.iter().any(|marker| line.starts_with(marker.as_bytes())) {
            continue;
        }
        statements.push(line);
    }
    statements
}

/// Whether one of `plugins` appears quoted in one of `statements`: applied
/// (`apply plugin:`, `id(...)`), declared with `apply false` in a root
/// build, or listed in a version catalog.
///
/// PHP: `Mobile::declares()`.
pub fn declares<'a, S: AsRef<[u8]>>(plugins: impl IntoIterator<Item = &'a [u8]>, statements: &[S]) -> bool {
    plugins.into_iter().any(|plugin| {
        QUOTES.iter().any(|quote| {
            let literal = [quote.as_bytes(), plugin, quote.as_bytes()].concat();
            statements.iter().any(|statement| memchr::memmem::find(statement.as_ref(), &literal).is_some())
        })
    })
}

/// Whether `values` holds one of `wanted`.
///
/// PHP: `array_intersect($wanted, $values) !== []`.
pub fn intersects<V: AsRef<[u8]>, W: AsRef<[u8]>>(wanted: &[W], values: &[V]) -> bool {
    wanted.iter().any(|w| values.iter().any(|v| v.as_ref() == w.as_ref()))
}

/// What a mobile detector reads from its inputs: the name and extension of
/// each file, the runtime dependencies of each manifest and the statements
/// of each Gradle script, in the order they were added.
///
/// PHP: the `$files`, `$extensions`, `$dependencies` and `$statements` of
/// `Mobile::detect()`.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Signals {
    /// The last component of each file path (PHP `basename()`).
    pub files: Vec<Vec<u8>>,
    /// The extension of each file path, empty without one (PHP
    /// `pathinfo($path, PATHINFO_EXTENSION)`).
    pub extensions: Vec<Vec<u8>>,
    /// The runtime dependencies of every manifest ([`dependencies`]).
    pub dependencies: Vec<Vec<u8>>,
    /// The statements of every Gradle script ([`statements`]).
    pub statements: Vec<Vec<u8>>,
}

impl Signals {
    /// Reads `content` as an input of the kind `input`.
    pub fn add(&mut self, content: &[u8], input: Input) {
        match input {
            Input::File => self.add_file(content),
            Input::Packages => self.dependencies.extend(dependencies(content)),
            Input::Gradle => self.statements.extend(statements(content).into_iter().map(<[u8]>::to_vec)),
        }
    }

    /// Reads a file path: its name and its extension.
    pub fn add_file(&mut self, path: &[u8]) {
        self.files.push(basename(path, b"").to_vec());
        self.extensions.push(pathinfo_extension(path).to_vec());
    }

    /// Whether `option` matches: one of its files, extensions or packages
    /// is among the signals, or one of its plugins is declared.
    ///
    /// PHP: `$matches` in `Mobile::detect()`.
    pub fn matches(&self, option: &dyn Mobile) -> bool {
        intersects(option.files(), &self.files)
            || intersects(option.file_extensions(), &self.extensions)
            || intersects(option.packages(), &self.dependencies)
            || declares(option.plugins().iter().map(|p| p.as_bytes()), &self.statements)
    }
}

/// Finds which mobile toolchain a project uses. A cross-platform project
/// embeds native Android and iOS projects, so of the matching options the
/// one covering the most platforms wins; a tie keeps the option added
/// first.
///
/// PHP: `Utopia\Detector\Detector\Mobile`.
///
/// ```
/// use utopia_detector::MobileDetector;
/// use utopia_detector::mobile::{Android, Flutter, Input, Ios, ReactNative};
///
/// let mut detector = MobileDetector::new();
/// detector.add_option(Android).add_option(Ios).add_option(Flutter).add_option(ReactNative);
/// detector.add_input("pubspec.yaml", Input::File).add_input("ios/Runner.xcodeproj", Input::File);
/// detector.add_input("dependencies:\n  flutter:\n    sdk: flutter\n", Input::Packages);
/// detector.add_input("plugins {\n    id(\"com.android.application\")\n}\n", Input::Gradle);
///
/// let mobile = detector.detect().expect("a mobile project");
/// assert_eq!(mobile.name(), "flutter");
/// assert_eq!(mobile.platforms().len(), 2);
/// ```
///
/// ```php
/// use Utopia\Detector\Detection\Mobile\Android;
/// use Utopia\Detector\Detection\Mobile\Flutter;
/// use Utopia\Detector\Detection\Mobile\Ios;
/// use Utopia\Detector\Detection\Mobile\ReactNative;
/// use Utopia\Detector\Detector\Mobile;
///
/// $detector = new Mobile();
/// $detector->addOption(new Android())->addOption(new Ios())->addOption(new Flutter())->addOption(new ReactNative());
/// $detector->addInput('pubspec.yaml', Mobile::INPUT_FILE)->addInput('ios/Runner.xcodeproj', Mobile::INPUT_FILE);
/// $detector->addInput("dependencies:\n  flutter:\n    sdk: flutter\n", Mobile::INPUT_PACKAGES);
/// $detector->addInput("plugins {\n    id(\"com.android.application\")\n}\n", Mobile::INPUT_GRADLE);
///
/// $mobile = $detector->detect();
/// echo $mobile->getName(), "\n";                     // flutter
/// echo implode(',', $mobile->getPlatforms()), "\n"; // android,ios
/// ```
#[derive(Default)]
pub struct MobileDetector {
    options: Vec<Box<dyn Mobile>>,
    signals: Signals,
}

impl MobileDetector {
    /// A detector without options or inputs.
    ///
    /// PHP: `new Mobile()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// Adds a toolchain, which wins a tie with the ones added after it.
    ///
    /// PHP: `addOption($mobile)`.
    pub fn add_option(&mut self, option: impl Mobile + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// Adds a file path, a `package.json` or `pubspec.yaml`, or a Gradle
    /// script or version catalog, as `input` says.
    ///
    /// PHP: `addInput($content, $type)`.
    pub fn add_input(&mut self, content: impl AsRef<[u8]>, input: Input) -> &mut Self {
        self.signals.add(content.as_ref(), input);
        self
    }

    /// What the inputs added so far reveal.
    pub fn signals(&self) -> &Signals {
        &self.signals
    }

    /// The matching option covering the most platforms (the first added of
    /// those that tie), or `None` when nothing matches.
    ///
    /// PHP: `detect()`.
    pub fn detect(&self) -> Option<&dyn Mobile> {
        let mut detection: Option<&dyn Mobile> = None;
        for option in &self.options {
            let option = option.as_ref();
            if self.signals.matches(option)
                && detection.is_none_or(|detection| option.platforms().len() > detection.platforms().len())
            {
                detection = Some(option);
            }
        }
        detection
    }
}

impl std::fmt::Debug for MobileDetector {
    /// The options' names and the signals.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MobileDetector")
            .field("options", &self.options.iter().map(|o| o.name()).collect::<Vec<_>>())
            .field("signals", &self.signals)
            .finish()
    }
}

#[cfg(test)]
mod tests {
    use super::{
        Android, Flutter, Input, Ios, Mobile, MobileDetector, Platform, ReactNative, declares, dependencies, statements,
    };

    const FLUTTER_PUBSPEC: &str = "name: my_app\n\
        description: A new Flutter project.\n\
        publish_to: 'none'\n\
        version: 1.0.0+1\n\
        \n\
        environment:\n  sdk: ^3.9.0\n\
        \n\
        dependencies:\n  flutter:\n    sdk: flutter\n  cupertino_icons: ^1.0.8\n\
        \n\
        dev_dependencies:\n  flutter_test:\n    sdk: flutter\n  flutter_lints: ^6.0.0\n\
        \n\
        flutter:\n  uses-material-design: true\n";

    const REACT_NATIVE_PACKAGE: &str = r#"{
        "name": "MyApp",
        "private": true,
        "dependencies": {"react": "19.2.0", "react-native": "0.88.1"},
        "devDependencies": {"@react-native-community/cli": "20.0.0"}
    }"#;

    const ANDROID_KOTLIN: &str = "plugins {\n    id(\"com.android.application\")\n    id(\"kotlin-android\")\n}\n";

    const ANDROID_GROOVY: &str = "apply plugin: \"com.android.application\"\napply plugin: \"com.facebook.react\"\n";

    type Case<'a> = (&'a str, &'a [&'a str], &'a [&'a str], &'a [&'a str], Option<&'a str>);

    fn detect(files: &[&str], manifests: &[&str], scripts: &[&str]) -> Option<String> {
        let mut detector = MobileDetector::new();
        detector.add_option(Flutter).add_option(ReactNative).add_option(Android).add_option(Ios);
        for file in files {
            detector.add_input(file, Input::File);
        }
        for manifest in manifests {
            detector.add_input(manifest, Input::Packages);
        }
        for script in scripts {
            detector.add_input(script, Input::Gradle);
        }
        detector.detect().map(|mobile| mobile.name().to_owned())
    }

    #[test]
    fn detects_each_toolchain() {
        let cases: &[Case] = &[
            ("flutter app", &["pubspec.yaml", "lib", "android", "ios"], &[FLUTTER_PUBSPEC], &[], Some("flutter")),
            (
                "flutter with its android module",
                &["pubspec.yaml"],
                &[FLUTTER_PUBSPEC],
                &[ANDROID_KOTLIN],
                Some("flutter"),
            ),
            (
                "flutter with its ios project",
                &["pubspec.yaml", "ios/Runner.xcodeproj", "ios/Podfile"],
                &[FLUTTER_PUBSPEC],
                &[],
                Some("flutter"),
            ),
            (
                "flow style pubspec",
                &[],
                &["name: my_app\ndependencies: {flutter: {sdk: flutter}}\n"],
                &[],
                Some("flutter"),
            ),
            ("dart package", &["pubspec.yaml"], &["name: cli\ndependencies:\n  args: ^2.7.0\n"], &[], None),
            (
                "flutter in dev dependencies",
                &[],
                &["name: lints\ndev_dependencies:\n  flutter:\n    sdk: flutter\n"],
                &[],
                None,
            ),
            ("commented flutter", &[], &["name: cli\ndependencies:\n  # flutter:\n  args: ^2.7.0\n"], &[], None),
            ("unparsable pubspec", &[], &["dependencies: {flutter: {sdk: flutter}\n"], &[], None),
            ("react native app", &["package.json"], &[REACT_NATIVE_PACKAGE], &[], Some("react-native")),
            (
                "react native with android",
                &["package.json"],
                &[REACT_NATIVE_PACKAGE],
                &[ANDROID_GROOVY],
                Some("react-native"),
            ),
            (
                "react native library",
                &["package.json"],
                &[r#"{"peerDependencies":{"react-native":"*"},"devDependencies":{"react-native":"0.88.1"}}"#],
                &[],
                None,
            ),
            ("react web app", &["package.json"], &[r#"{"dependencies":{"react":"19.2.0"}}"#], &[], None),
            (
                "android apply plugin",
                &["build.gradle"],
                &[],
                &["apply plugin: 'com.android.application'\n"],
                Some("android"),
            ),
            ("android kotlin plugins block", &["build.gradle.kts"], &[], &[ANDROID_KOTLIN], Some("android")),
            (
                "android root build",
                &[],
                &[],
                &["plugins {\n    id(\"com.android.application\") version \"9.5.1\" apply false\n}\n"],
                Some("android"),
            ),
            (
                "android version catalog",
                &[],
                &[],
                &["[plugins]\nandroid-application = { id = \"com.android.application\", version.ref = \"agp\" }\n"],
                Some("android"),
            ),
            ("android crlf", &[], &[], &["plugins {\r\n    id 'com.android.application'\r\n}\r\n"], Some("android")),
            ("android library", &[], &[], &["plugins {\n    id(\"com.android.library\")\n}\n"], None),
            ("commented plugin", &[], &[], &["// id(\"com.android.application\")\n"], None),
            ("hash commented plugin", &[], &[], &["# id = \"com.android.application\"\n"], None),
            (
                "block commented plugin",
                &[],
                &[],
                &["/*\nid 'com.android.application'\n*/\napply plugin: 'java'\n"],
                None,
            ),
            ("one line block comment", &[], &[], &["/* id 'com.android.application' */\n"], None),
            (
                "plugin id inside a longer string",
                &[],
                &[],
                &["description = 'from com.android.application'\nid 'com.android.application.custom'\n"],
                None,
            ),
            ("ios project", &["MyApp.xcodeproj", "MyApp"], &[], &[], Some("ios")),
            ("ios workspace", &["MyApp.xcworkspace"], &[], &[], Some("ios")),
            ("cocoapods", &["Podfile"], &[], &[], Some("ios")),
            ("ios project as a directory path", &["./MyApp.xcodeproj/"], &[], &[], Some("ios")),
            ("swift package", &["Package.swift", "Sources"], &[], &[], None),
            ("native android and ios", &["ios/MyApp.xcodeproj"], &[], &[ANDROID_KOTLIN], Some("android")),
            ("empty manifest", &["package.json"], &[""], &[], None),
            ("no inputs", &[], &[], &[], None),
        ];
        for (assertion, files, manifests, scripts, expected) in cases {
            assert_eq!(detect(files, manifests, scripts).as_deref(), *expected, "{assertion}");
        }
    }

    #[test]
    fn most_platforms_win_whatever_the_order() {
        let orders: [[&'static dyn Mobile; 3]; 3] =
            [[&Flutter, &Android, &Ios], [&Android, &Ios, &Flutter], [&Ios, &Flutter, &Android]];
        for order in orders {
            let mut detector = MobileDetector::new();
            for option in order {
                match option.name() {
                    "flutter" => detector.add_option(Flutter),
                    "android" => detector.add_option(Android),
                    _ => detector.add_option(Ios),
                };
            }
            detector
                .add_input("pubspec.yaml", Input::File)
                .add_input("ios/Podfile", Input::File)
                .add_input(FLUTTER_PUBSPEC, Input::Packages)
                .add_input(ANDROID_KOTLIN, Input::Gradle);
            assert_eq!(detector.detect().map(|m| m.name()), Some("flutter"));
        }
    }

    #[test]
    fn ties_keep_the_option_added_first() {
        let mut detector = MobileDetector::new();
        detector.add_option(Ios).add_option(Android);
        detector.add_input("Podfile", Input::File).add_input(ANDROID_KOTLIN, Input::Gradle);
        assert_eq!(detector.detect().map(|m| m.name()), Some("ios"));
    }

    #[test]
    fn platforms() {
        let both = [Platform::Android, Platform::Ios];
        assert_eq!(Flutter.platforms(), both);
        assert_eq!(ReactNative.platforms(), both);
        assert_eq!(Android.platforms(), [Platform::Android]);
        assert_eq!(Ios.platforms(), [Platform::Ios]);
        assert_eq!(both.map(|p| p.as_str()), ["android", "ios"]);
    }

    #[test]
    fn input_kinds() {
        assert_eq!(Input::new("gradle"), Ok(Input::Gradle));
        let error = Input::new("language").expect_err("not an input kind");
        assert_eq!(error.to_string(), "Invalid input type 'language'");
        assert_eq!(error.php_class(), "InvalidArgumentException");
    }

    #[test]
    fn runtime_dependencies_only() {
        assert_eq!(
            dependencies(br#"{"dependencies":{"react-native":"1","0":"x"},"devDependencies":{"jest":"1"}}"#),
            [b"react-native".to_vec(), b"0".to_vec()]
        );
        assert!(dependencies(b"  \n").is_empty());
        assert!(dependencies(br#"{"dependencies":"flutter"}"#).is_empty());
    }

    #[test]
    fn statements_skip_comments() {
        let script = b"  a\n// b\n# c\n/* d\ne */ f\ng\n/* h */\n\ni\n";
        assert_eq!(statements(script), [&b"a"[..], b"g", b"i"]);
    }

    #[test]
    fn plugins_count_only_quoted() {
        let plugin: &[u8] = b"com.android.application";
        assert!(declares([plugin], &[&b"id 'com.android.application'"[..]]));
        assert!(declares([plugin], &[&b"id(\"com.android.application\")"[..]]));
        assert!(!declares([plugin], &[&b"id com.android.application"[..]]));
        assert!(!declares([plugin], &[&b"id 'com.android.application.x'"[..]]));
        assert!(!declares([], &[&b"'com.android.application'"[..]]));
    }
}
