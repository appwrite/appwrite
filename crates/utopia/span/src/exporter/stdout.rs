//! Spans as JSON lines.

use std::io::Write;

use php_std::json::{self, Flags};
use php_std::{Array, ArrayKey, Value as Php};

use super::{Exporter, Sampler, Streams, kept};
use crate::Span;

/// Writes each span as one line of JSON (NDJSON): `level` and `action`
/// first, then every attribute, then the error (`error.type`,
/// `error.message`, `error.code`, `error.file`, `error.line`, `error.trace`
/// with at most `max_trace_frames` frames, `error.trace_truncated`). Spans
/// with an error go to standard error, the others to standard output.
///
/// A span whose JSON cannot be encoded (a string attribute that is not
/// UTF-8) is dropped.
///
/// PHP: `Utopia\Span\Exporter\Stdout`.
///
/// ```
/// use std::io::Write;
/// use std::sync::{Arc, Mutex};
/// use utopia_span::{Exporter, Span, Stdout};
///
/// #[derive(Clone, Default)]
/// struct Buffer(Arc<Mutex<Vec<u8>>>);
/// impl Write for Buffer {
///     fn write(&mut self, b: &[u8]) -> std::io::Result<usize> { self.0.lock().unwrap().write(b) }
///     fn flush(&mut self) -> std::io::Result<()> { Ok(()) }
/// }
///
/// let out = Buffer::default();
/// let exporter = Stdout::new().writers(Box::new(out.clone()), Box::new(std::io::sink()));
/// let span = Span::new("job");
/// span.set("span.trace_id", "t").set("span.id", "s").set("span.started_at", 1.5).set("level", "info");
/// exporter.export(&span);
///
/// let line = String::from_utf8(out.0.lock().unwrap().clone()).unwrap();
/// assert_eq!(line, "{\"level\":\"info\",\"action\":\"job\",\"span.trace_id\":\"t\",\"span.id\":\"s\",\"span.started_at\":1.5}\n");
/// ```
///
/// ```php
/// use Utopia\Span\Exporter\Stdout;
/// use Utopia\Span\Span;
///
/// $span = new Span('job');
/// $span->set('span.trace_id', 't')->set('span.id', 's')->set('span.started_at', 1.5)->set('level', 'info');
/// (new Stdout())->export($span);
/// // {"level":"info","action":"job","span.trace_id":"t","span.id":"s","span.started_at":1.5}
/// ```
pub struct Stdout {
    sampler: Sampler,
    max_trace_frames: usize,
    streams: Streams,
}

impl Default for Stdout {
    fn default() -> Self {
        Self::new()
    }
}

impl std::fmt::Debug for Stdout {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Stdout").field("max_trace_frames", &self.max_trace_frames).finish()
    }
}

impl Stdout {
    /// An exporter of every span, with at most 3 stack frames per error,
    /// writing to the process's standard output and error.
    ///
    /// PHP: `new Stdout()`.
    pub fn new() -> Self {
        Self { sampler: Box::new(|_| true), max_trace_frames: 3, streams: Streams::standard() }
    }

    /// Exports only the spans `sampler` accepts.
    ///
    /// PHP: the `$sampler` argument.
    pub fn sampler(mut self, sampler: impl Fn(&Span) -> bool + Send + Sync + 'static) -> Self {
        self.sampler = Box::new(sampler);
        self
    }

    /// Prints at most `frames` stack frames of an error.
    ///
    /// PHP: the `$maxTraceFrames` argument.
    pub fn max_trace_frames(mut self, frames: usize) -> Self {
        self.max_trace_frames = frames;
        self
    }

    /// Writes spans to `out`, and spans with an error to `err`.
    pub fn writers(mut self, out: Box<dyn Write + Send>, err: Box<dyn Write + Send>) -> Self {
        self.streams = Streams::new(out, err);
        self
    }

    /// The line this exporter writes for `span` (without its newline), or
    /// `None` when it cannot be encoded.
    pub fn line(&self, span: &Span) -> Option<String> {
        let mut attributes = Array::<php_std::Never>::default();
        span.with_attributes(|attrs| {
            for (key, value) in attrs {
                attributes.set(ArrayKey::from(&**key), value.to_php());
            }
        });
        let mut data = Array::default();
        let level = ArrayKey::from("level");
        if let Some(level_value) = attributes.remove(&level) {
            data.set(level, level_value);
        }
        data.set(ArrayKey::from("action"), Php::from(span.action()));
        for (key, value) in attributes {
            if !data.contains_key(&key) {
                data.set(key, value);
            }
        }
        if let Some(error) = span.error() {
            let set = |data: &mut Array, key: &str, value: Php| {
                data.set(ArrayKey::from(key), value);
            };
            set(&mut data, "error.type", Php::from(error.class()));
            set(&mut data, "error.message", Php::Str(error.message_bytes().into()));
            set(&mut data, "error.code", Php::Int(error.code_value()));
            set(&mut data, "error.file", Php::from(error.file()));
            set(&mut data, "error.line", Php::Int(i64::from(error.line())));
            let frames = error.frames();
            let trace: Array = frames[..kept(frames.len(), self.max_trace_frames)]
                .iter()
                .map(|frame| {
                    let mut f = Array::default();
                    f.set(ArrayKey::from("file"), frame.file.as_deref().map_or(Php::Null, Php::from));
                    f.set(ArrayKey::from("line"), frame.line.map_or(Php::Null, |l| Php::Int(i64::from(l))));
                    f.set(ArrayKey::from("function"), Php::from(frame.function.as_str()));
                    Php::Array(f)
                })
                .collect();
            set(&mut data, "error.trace", Php::Array(trace));
            if frames.len() > self.max_trace_frames {
                set(&mut data, "error.trace_truncated", Php::Int((frames.len() - self.max_trace_frames) as i64));
            }
        }
        json::encode(&Php::Array(data), Flags::UNESCAPED_SLASHES, json::DEFAULT_DEPTH).ok()
    }
}

impl Exporter for Stdout {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        let Some(mut line) = self.line(span) else {
            return;
        };
        line.push('\n');
        self.streams.write(span.error().is_some(), line.as_bytes());
    }
}
