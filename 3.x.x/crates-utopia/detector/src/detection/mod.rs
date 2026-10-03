//! Detection result types (PHP `Utopia\Detector\Detection`).

pub mod framework;
pub mod packager;
pub mod rendering;
pub mod runtime;

/// Marker matching PHP `Utopia\Detector\Detection`.
pub trait Detection: Send + Sync {}
