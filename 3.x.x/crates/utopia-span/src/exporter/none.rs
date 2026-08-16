use crate::exporter::Exporter;
use crate::span::Span;

/// Null exporter that discards all spans (PHP `Exporter\None`).
#[derive(Debug, Default, Clone, Copy)]
pub struct None;

impl None {
    pub fn new() -> Self {
        Self
    }
}

impl Exporter for None {
    fn sample(&self, _span: &Span) -> bool {
        false
    }

    fn export(&self, _span: &Span) {}
}
