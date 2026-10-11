//! Detection of what a project is from what it contains: its package
//! manager, its language runtime, its web framework, and whether a build is
//! served by a server or as static files.
//!
//! Each question has a detector fed with inputs (file names, language names
//! or manifest contents) and options to choose from:
//!
//! - [`PackagerDetector`] with [`Packager`]s ([`Npm`], [`Pnpm`], [`Yarn`]);
//! - [`RuntimeDetector`] with [`Runtime`]s ([`Node`], [`Php`], [`Python`]
//!   and eight more), matching by a [`Strategy`];
//! - [`FrameworkDetector`] with [`Framework`]s ([`NextJs`], [`Nuxt`],
//!   [`SvelteKit`], [`Astro`], [`Flutter`] and ten more), scored on files and
//!   [`dependencies`];
//! - [`RenderingDetector`] with [`Rendering`]s ([`Ssr`], [`Static`]).
//!
//! Options are traits, so a project can add its own. Runtime and framework
//! detections come back as [`Detected`], which carries the detector's
//! packager into the install and build commands.
//!
//! ```
//! use utopia_detector::{FrameworkDetector, NextJs, Nuxt, SvelteKit};
//!
//! let mut detector = FrameworkDetector::new("pnpm");
//! detector.add_option(NextJs).add_option(Nuxt).add_option(SvelteKit);
//! for file in ["src", "package.json", "svelte.config.js"] {
//!     detector.add_file(file);
//! }
//!
//! let framework = detector.detect().expect("a framework");
//! assert_eq!(framework.name(), "sveltekit");
//! assert_eq!(framework.build_command(), "pnpm run build");
//! ```
//!
//! ```php
//! use Utopia\Detector\Detection\Framework\NextJs;
//! use Utopia\Detector\Detection\Framework\Nuxt;
//! use Utopia\Detector\Detection\Framework\SvelteKit;
//! use Utopia\Detector\Detector\Framework;
//!
//! $detector = new Framework('pnpm');
//! $detector->addOption(new NextJs())->addOption(new Nuxt())->addOption(new SvelteKit());
//! foreach (['src', 'package.json', 'svelte.config.js'] as $file) {
//!     $detector->addInput($file, Framework::INPUT_FILE);
//! }
//!
//! $framework = $detector->detect();
//! echo $framework->getName(), "\n";         // sveltekit
//! echo $framework->getBuildCommand(), "\n"; // pnpm run build
//! ```
//!
//! PHP: ports `utopia-php/detector`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Detector\Detector` (abstract), `Detection` (abstract) | one detector type per question; no common base |
//! | `Detector::addOption()`, `addInput()`, `detect()` | `add_option`, `add_input` (`add_file` / `add_manifest` on [`FrameworkDetector`]), `detect` on each detector |
//! | `Detector\Packager`, `Detection\Packager` | [`PackagerDetector`], [`Packager`] |
//! | `Detection\Packager\NPM`, `PNPM`, `Yarn` | [`Npm`], [`Pnpm`], [`Yarn`] |
//! | `Detector\Runtime`, `Detection\Runtime` | [`RuntimeDetector`], [`Runtime`] |
//! | `Detector\Strategy` (`FILEMATCH`, `EXTENSION`, `LANGUAGES`) | [`Strategy`] |
//! | `Detection\Runtime\Bun`, `CPP`, `Dart`, `Deno`, `Dotnet`, `Java`, `Node`, `PHP`, `Python`, `Ruby`, `Swift` | [`Bun`], [`Cpp`], [`Dart`], [`Deno`], [`Dotnet`], [`Java`], [`Node`], [`Php`], [`Python`], [`Ruby`], [`Swift`] |
//! | `Detector\Framework`, `Detection\Framework` | [`FrameworkDetector`], [`Framework`] |
//! | `Framework::INPUT_FILE`, `INPUT_PACKAGES` | [`Input`] |
//! | `Framework::dependencies()` | [`dependencies`] |
//! | `Detection\Framework\JS` (abstract) | the JavaScript frameworks' shared install and build commands |
//! | `Detection\Framework\Analog`, `Angular`, `Astro`, `Flutter`, `Jaspr`, `Lynx`, `NextJs`, `Nuxt`, `React`, `ReactNative`, `Remix`, `Svelte`, `SvelteKit`, `TanStackStart`, `Vue` | [`Analog`], [`Angular`], [`Astro`], [`Flutter`], [`Jaspr`], [`Lynx`], [`NextJs`], [`Nuxt`], [`React`], [`ReactNative`], [`Remix`], [`Svelte`], [`SvelteKit`], [`TanStackStart`], [`Vue`] |
//! | `getAdapter()` (`'ssr'`, `'static'`, `''`) | [`Framework::adapter`] ([`Adapter`], `None`) |
//! | `setPackager()` on a runtime or framework | the `packager` argument of [`Runtime::commands`], [`Framework::install_command`], [`Framework::build_command`]; [`Detected`] passes the detector's |
//! | `Detector\Rendering`, `Detection\Rendering` | [`RenderingDetector`], [`Rendering`] ([`Rendered`] from `detect`) |
//! | `Detection\Rendering\SSR`, `XStatic` | [`Ssr`], [`Static`] |
//! | `\InvalidArgumentException`, `\Exception` | [`Error::InvalidArgument`], [`Error::Exception`] |
//!
//! PHP passes file names as byte strings; here they are `String`s, so a name
//! that is not UTF-8 has to be converted before it is added.

#![warn(missing_docs)]

mod error;
mod framework;
mod packager;
mod rendering;
mod runtime;

pub use error::Error;
pub use framework::{
    Adapter, Analog, Angular, Astro, Flutter, Framework, FrameworkDetector, Input, Jaspr, Lynx, NextJs, Nuxt, React,
    ReactNative, Remix, Svelte, SvelteKit, TanStackStart, Vue, dependencies,
};
pub use packager::{Npm, Packager, PackagerDetector, Pnpm, Yarn};
pub use rendering::{Rendered, Rendering, RenderingDetector, Ssr, Static};
pub use runtime::{
    Bun, Cpp, Dart, Deno, Dotnet, Java, Node, Php, Python, Ruby, Runtime, RuntimeDetector, Strategy, Swift,
};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A runtime or framework a detector chose, with the detector's packager:
/// its commands are the ones for that packager. Everything else is the
/// option itself, through `Deref`.
///
/// PHP: the detection object `detect()` returns after `setPackager()`.
pub struct Detected<'a, T: ?Sized> {
    item: &'a T,
    packager: &'a str,
}

impl<'a, T: ?Sized> Detected<'a, T> {
    /// `item`, detected for a project using `packager`.
    pub fn new(item: &'a T, packager: &'a str) -> Self {
        Detected { item, packager }
    }

    /// The option the detector chose.
    pub fn item(&self) -> &'a T {
        self.item
    }

    /// The packager of the project.
    pub fn packager(&self) -> &'a str {
        self.packager
    }
}

impl<T: ?Sized> std::ops::Deref for Detected<'_, T> {
    type Target = T;

    /// The option the detector chose.
    fn deref(&self) -> &T {
        self.item
    }
}

impl<T: ?Sized> Clone for Detected<'_, T> {
    fn clone(&self) -> Self {
        *self
    }
}

impl<T: ?Sized> Copy for Detected<'_, T> {}

impl<T: ?Sized> std::fmt::Debug for Detected<'_, T> {
    /// The packager.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Detected").field("packager", &self.packager).finish_non_exhaustive()
    }
}
