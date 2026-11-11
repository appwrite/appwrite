//! Exporters: where finished spans go.
//!
//! [`Span::finish`](crate::Span::finish) hands the span to every registered
//! [`Exporter`] whose [`Exporter::sample`] accepts it. [`None`] discards
//! spans, [`Stdout`] writes JSON lines, [`Pretty`] writes colourful
//! multi-line output for a terminal, and [`Sentry`] reports errors to Sentry.
//!
//! PHP: the classes of `Utopia\Span\Exporter`.

mod pretty;
mod sentry;
mod stdout;

use std::io::Write;
use std::sync::Mutex;

pub use pretty::Pretty;
#[cfg(feature = "http")]
pub use sentry::HttpTransport;
pub use sentry::{Request, Sentry, SentryField, SentryLevel, Transport};
pub use stdout::Stdout;

use crate::Span;

/// Receives finished spans.
///
/// Implement it to send spans to an observability backend, then register it
/// with [`Span::set_exporters`](crate::Span::set_exporters).
///
/// PHP: `Utopia\Span\Exporter\Exporter`.
pub trait Exporter: Send + Sync {
    /// Exports a finished span. Called for spans [`Exporter::sample`]
    /// accepted.
    ///
    /// PHP: `export($span)`.
    fn export(&self, span: &Span);

    /// Whether to export `span`; `false` drops it.
    ///
    /// PHP: `sample($span)`.
    fn sample(&self, span: &Span) -> bool;
}

/// Decides whether an exporter exports a span.
///
/// PHP: the `Closure(Span): bool` `$sampler` of an exporter's constructor.
pub type Sampler = Box<dyn Fn(&Span) -> bool + Send + Sync>;

/// Discards every span: [`Exporter::sample`] is always `false`.
///
/// PHP: `Utopia\Span\Exporter\None`.
///
/// ```
/// use utopia_span::exporter::{Exporter, None};
/// use utopia_span::Span;
///
/// let span = Span::new("test");
/// assert!(!None.sample(&span));
/// None.export(&span); // does nothing
/// ```
///
/// ```php
/// use Utopia\Span\Exporter\None;
/// use Utopia\Span\Span;
///
/// $span = new Span('test');
/// var_dump((new None())->sample($span)); // bool(false)
/// (new None())->export($span);           // does nothing
/// ```
#[derive(Debug, Default, Clone, Copy)]
pub struct None;

impl Exporter for None {
    fn export(&self, _span: &Span) {}

    fn sample(&self, _span: &Span) -> bool {
        false
    }
}

/// Where an exporter writes: one stream for spans, one for spans with an
/// error.
pub(crate) struct Streams {
    out: Mutex<Box<dyn Write + Send>>,
    err: Mutex<Box<dyn Write + Send>>,
}

impl Streams {
    /// The process's standard output and standard error.
    pub(crate) fn standard() -> Self {
        Self::new(Box::new(std::io::stdout()), Box::new(std::io::stderr()))
    }

    pub(crate) fn new(out: Box<dyn Write + Send>, err: Box<dyn Write + Send>) -> Self {
        Self { out: Mutex::new(out), err: Mutex::new(err) }
    }

    /// Writes `bytes` to the error stream when `error`, else to the output.
    pub(crate) fn write(&self, error: bool, bytes: &[u8]) {
        let stream = if error { &self.err } else { &self.out };
        let mut stream = stream.lock().unwrap_or_else(|e| e.into_inner());
        // Tracing never breaks the application: a closed stream drops spans.
        let _ = stream.write_all(bytes).and_then(|()| stream.flush());
    }
}

/// How many of `len` stack frames an exporter prints at most `max` of.
pub(crate) fn kept(len: usize, max: usize) -> usize {
    len.min(max)
}
