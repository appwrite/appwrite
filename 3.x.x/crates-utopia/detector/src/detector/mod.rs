//! Detectors (PHP `Utopia\Detector\Detector`).

mod framework;
mod packager;
mod rendering;
mod runtime;
mod strategy;

pub use framework::Framework;
pub use packager::Packager;
pub use rendering::Rendering;
pub use runtime::Runtime;
pub use strategy::Strategy;
