//! Package managers: [`Packager`], the built-in [`Npm`], [`Pnpm`] and
//! [`Yarn`], and the [`PackagerDetector`] that finds one from file names.

/// A package manager, recognised by the files it leaves in a project.
///
/// PHP: `Utopia\Detector\Detection\Packager`.
pub trait Packager: Send + Sync {
    /// The packager's name, such as `pnpm`.
    ///
    /// PHP: `getName()`.
    fn name(&self) -> &str;

    /// The file names that reveal it, such as `pnpm-lock.yaml`.
    ///
    /// PHP: `getFiles()`.
    fn files(&self) -> &[&str];
}

/// npm: `package.json` or `package-lock.json`.
///
/// PHP: `Utopia\Detector\Detection\Packager\NPM`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Npm;

impl Packager for Npm {
    /// `npm`.
    fn name(&self) -> &str {
        "npm"
    }

    /// `package.json`, `package-lock.json`.
    fn files(&self) -> &[&str] {
        &["package.json", "package-lock.json"]
    }
}

/// pnpm: `pnpm-lock.yaml`.
///
/// PHP: `Utopia\Detector\Detection\Packager\PNPM`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Pnpm;

impl Packager for Pnpm {
    /// `pnpm`.
    fn name(&self) -> &str {
        "pnpm"
    }

    /// `pnpm-lock.yaml`.
    fn files(&self) -> &[&str] {
        &["pnpm-lock.yaml"]
    }
}

/// Yarn: `yarn.lock`.
///
/// PHP: `Utopia\Detector\Detection\Packager\Yarn`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Yarn;

impl Packager for Yarn {
    /// `yarn`.
    fn name(&self) -> &str {
        "yarn"
    }

    /// `yarn.lock`.
    fn files(&self) -> &[&str] {
        &["yarn.lock"]
    }
}

/// Finds the packager of a project from its file names: the first option,
/// in the order they were added, with one of its files among the inputs.
///
/// PHP: `Utopia\Detector\Detector\Packager`.
///
/// ```
/// use utopia_detector::{Npm, PackagerDetector, Pnpm, Yarn};
///
/// let mut detector = PackagerDetector::new();
/// detector.add_option(Pnpm).add_option(Yarn).add_option(Npm);
/// detector.add_input("package.json").add_input("yarn.lock");
///
/// assert_eq!(detector.detect().map(|p| p.name()), Some("yarn"));
/// ```
///
/// ```php
/// use Utopia\Detector\Detection\Packager\NPM;
/// use Utopia\Detector\Detection\Packager\PNPM;
/// use Utopia\Detector\Detection\Packager\Yarn;
/// use Utopia\Detector\Detector\Packager;
///
/// $detector = new Packager();
/// $detector->addOption(new PNPM())->addOption(new Yarn())->addOption(new NPM());
/// $detector->addInput('package.json')->addInput('yarn.lock');
///
/// echo $detector->detect()?->getName(), "\n"; // yarn
/// ```
#[derive(Default)]
pub struct PackagerDetector {
    options: Vec<Box<dyn Packager>>,
    inputs: Vec<String>,
}

impl PackagerDetector {
    /// A detector without options or inputs.
    ///
    /// PHP: `new Packager()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// Adds a packager, tried after the others.
    ///
    /// PHP: `addOption($packager)`.
    pub fn add_option(&mut self, option: impl Packager + 'static) -> &mut Self {
        self.options.push(Box::new(option));
        self
    }

    /// Adds a file name of the project.
    ///
    /// PHP: `addInput($file)`.
    pub fn add_input(&mut self, file: impl Into<String>) -> &mut Self {
        self.inputs.push(file.into());
        self
    }

    /// The first packager with a file among the inputs, or `None`.
    ///
    /// PHP: `detect()`.
    pub fn detect(&self) -> Option<&dyn Packager> {
        self.options.iter().find(|p| p.files().iter().any(|f| self.inputs.iter().any(|i| i == f))).map(|p| p.as_ref())
    }
}

impl std::fmt::Debug for PackagerDetector {
    /// The options' names and the inputs.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PackagerDetector")
            .field("options", &self.options.iter().map(|o| o.name()).collect::<Vec<_>>())
            .field("inputs", &self.inputs)
            .finish()
    }
}
