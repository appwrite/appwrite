//! Environment detection for Utopia.
//!
//! Rust port of [`utopia-php/detector`](https://github.com/utopia-php/detector).

mod detection;
mod detector;
mod error;
mod input;
mod util;

pub use detection::framework::Framework as FrameworkDetection;
pub use detection::framework::{
    Analog, Angular, Astro, Flutter, Lynx, NextJs, Nuxt, React, ReactNative, Remix, Svelte,
    SvelteKit, TanStackStart, Vue, JS,
};
pub use detection::packager::{Packager as PackagerDetection, Yarn, NPM, PNPM};
pub use detection::rendering::{Rendering as RenderingDetection, XStatic, SSR};
pub use detection::runtime::{
    Bun, Dart, Deno, Dotnet, Java, Node, Python, Ruby, Runtime as RuntimeDetection, Swift, CPP, PHP,
};
pub use detection::Detection;
pub use detector::{Framework, Packager, Rendering, Runtime, Strategy};
pub use error::DetectorError;
pub use input::Input;

/// Prelude for common detector types.
pub mod prelude {
    pub use crate::{
        Analog, Angular, Astro, Bun, Dart, Deno, DetectorError, Dotnet, Flutter, Framework,
        FrameworkDetection, Java, Lynx, NextJs, Node, Nuxt, Packager, PackagerDetection, Python,
        React, ReactNative, Remix, Rendering, RenderingDetection, Ruby, Runtime, RuntimeDetection,
        Strategy, Svelte, SvelteKit, Swift, TanStackStart, Vue, XStatic, Yarn, CPP, JS, NPM, PHP,
        PNPM, SSR,
    };
}
