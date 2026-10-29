//! Spans as colourful, human-readable text.

use std::io::Write;

use php_std::format::{RoundingMode, round};
use php_std::types::KeyRef;
use php_std::value::Number;
use php_std::{Array, ArrayKey};

use super::{Exporter, Sampler, Streams, kept};
use crate::{Span, Value};

const RESET: &str = "\x1b[0m";
const BOLD: &str = "\x1b[1m";
const DIM: &str = "\x1b[2m";
const RED: &str = "\x1b[31m";
const GREEN: &str = "\x1b[32m";
const YELLOW: &str = "\x1b[33m";
const CYAN: &str = "\x1b[36m";
const WHITE: &str = "\x1b[37m";

/// Writes each span as a coloured block for local development: a header
/// (action, duration, short trace id), the attributes other than `span.*`
/// aligned in a column with `level` first, the error with at most
/// `max_trace_frames` stack frames, and a separator line `width` wide. Spans
/// with an error go to standard error, the others to standard output.
///
/// PHP: `Utopia\Span\Exporter\Pretty`.
///
/// ```
/// use utopia_span::{Pretty, Span};
///
/// let span = Span::new("db.query");
/// span.set("span.duration", 0.0123).set("rows", 3);
/// let text = String::from_utf8(Pretty::new().width(10).render(&span)).unwrap();
/// assert!(text.contains("12.3ms"));
/// assert!(text.contains("rows"));
/// ```
///
/// ```php
/// use Utopia\Span\Exporter\Pretty;
/// use Utopia\Span\Span;
///
/// $span = new Span('db.query');
/// $span->set('span.duration', 0.0123)->set('rows', 3);
/// (new Pretty(width: 10))->export($span); // db.query · 12.3ms · … then "rows 3"
/// ```
pub struct Pretty {
    sampler: Sampler,
    max_trace_frames: usize,
    width: usize,
    streams: Streams,
}

impl Default for Pretty {
    fn default() -> Self {
        Self::new()
    }
}

impl std::fmt::Debug for Pretty {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pretty").field("max_trace_frames", &self.max_trace_frames).field("width", &self.width).finish()
    }
}

/// `(string) $key` of an array key.
fn key_bytes(key: KeyRef<'_>) -> Vec<u8> {
    match key {
        KeyRef::Int(i) => i.to_string().into_bytes(),
        KeyRef::Str(s) => s.to_vec(),
    }
}

fn format_value(value: &php_std::Value) -> Vec<u8> {
    match value {
        php_std::Value::Null => b"null".to_vec(),
        php_std::Value::Bool(true) => b"true".to_vec(),
        php_std::Value::Bool(false) => b"false".to_vec(),
        other => escape(&other.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default()),
    }
}

/// Replaces C0 controls and DEL with `\xNN`, so a value cannot forge extra
/// log lines or inject terminal control sequences.
///
/// PHP: `Pretty::escape()`.
fn escape(value: &[u8]) -> Vec<u8> {
    let mut escaped = Vec::with_capacity(value.len());
    for &byte in value {
        if byte < 0x20 || byte == 0x7F {
            escaped.extend_from_slice(format!("\\x{byte:02x}").as_bytes());
        } else {
            escaped.push(byte);
        }
    }
    escaped
}

fn format_duration(seconds: f64) -> String {
    let round = |x: f64, places: i64| round(Number::Float(x), places, RoundingMode::HalfAwayFromZero);
    if seconds >= 1.0 {
        format!("{}s", php_std::number::to_string(round(seconds, 2)))
    } else {
        format!("{}ms", php_std::number::to_string(round(seconds * 1000.0, 1)))
    }
}

fn duration_color(seconds: f64) -> &'static str {
    if seconds >= 1.0 {
        RED
    } else if seconds >= 0.1 {
        YELLOW
    } else {
        GREEN
    }
}

impl Pretty {
    /// An exporter of every span, with at most 3 stack frames per error and a
    /// 60 columns wide separator, writing to the process's standard output
    /// and error.
    ///
    /// PHP: `new Pretty()`.
    pub fn new() -> Self {
        Self { sampler: Box::new(|_| true), max_trace_frames: 3, width: 60, streams: Streams::standard() }
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

    /// The width of the separator line, in columns.
    ///
    /// PHP: the `$width` argument.
    pub fn width(mut self, width: usize) -> Self {
        self.width = width;
        self
    }

    /// Writes spans to `out`, and spans with an error to `err`.
    ///
    /// PHP: the `$stdout` and `$stderr` arguments.
    pub fn writers(mut self, out: Box<dyn Write + Send>, err: Box<dyn Write + Send>) -> Self {
        self.streams = Streams::new(out, err);
        self
    }

    /// The text this exporter writes for `span`.
    pub fn render(&self, span: &Span) -> Vec<u8> {
        let error = span.error();
        let mut lines: Vec<Vec<u8>> = vec![self.header(span, error.is_some()), Vec::new()];

        let mut attributes = Array::<php_std::Never>::default();
        span.with_attributes(|attrs| {
            for (key, value) in attrs {
                if !key.starts_with("span.") {
                    attributes.set(ArrayKey::from(&**key), value.to_php());
                }
            }
        });
        let level = ArrayKey::from("level");
        if let Some(value) = attributes.remove(&level) {
            let mut first = Array::default();
            first.set(level, value);
            for (key, value) in attributes {
                first.set(key, value);
            }
            attributes = first;
        }
        let pad = attributes.keys().map(|k| escape(&key_bytes(k)).len()).max().unwrap_or(0);
        for (key, value) in attributes.iter() {
            let mut line = format!("  {CYAN}").into_bytes();
            let key = escape(&key_bytes(key));
            line.extend_from_slice(&key);
            line.resize(line.len() + pad.saturating_sub(key.len()), b' ');
            line.extend_from_slice(format!("{RESET} {WHITE}").as_bytes());
            line.extend_from_slice(&format_value(value));
            line.extend_from_slice(RESET.as_bytes());
            lines.push(line);
        }

        if let Some(error) = &error {
            if !attributes.is_empty() {
                lines.push(Vec::new());
            }
            let mut line = format!("{RED}{BOLD}  ").into_bytes();
            line.extend_from_slice(&escape(error.class().as_bytes()));
            line.extend_from_slice(format!("{RESET}{RED}: ").as_bytes());
            line.extend_from_slice(&escape(error.message_bytes()));
            line.extend_from_slice(format!("{RESET}\n{DIM}    at ").as_bytes());
            line.extend_from_slice(&escape(error.file().as_bytes()));
            line.extend_from_slice(format!(":{}{RESET}", error.line()).as_bytes());
            lines.push(line);
            let frames = error.frames();
            for frame in &frames[..kept(frames.len(), self.max_trace_frames)] {
                let file = escape(frame.file.as_deref().unwrap_or("unknown").as_bytes());
                let line = frame.line.map_or_else(|| "?".to_owned(), |l| l.to_string());
                let mut out = format!("{DIM}    at ").into_bytes();
                out.extend_from_slice(&file);
                out.extend_from_slice(format!(":{line}{RESET}").as_bytes());
                lines.push(out);
            }
            if frames.len() > self.max_trace_frames {
                lines.push(format!("{DIM}    ... {} more{RESET}", frames.len() - self.max_trace_frames).into_bytes());
            }
        }

        lines.push(Vec::new());
        lines.push(format!("{DIM}{}{RESET}", "─".repeat(self.width)).into_bytes());
        let mut out = lines.join(&b"\n"[..]);
        out.push(b'\n');
        out
    }

    fn header(&self, span: &Span, error: bool) -> Vec<u8> {
        let color = if error { RED } else { GREEN };
        let mut action = format!("{BOLD}{color}").into_bytes();
        action.extend_from_slice(&escape(span.action().as_bytes()));
        action.extend_from_slice(RESET.as_bytes());
        let mut parts = vec![action];
        if let Value::Float(duration) = span.get("span.duration") {
            parts.push(format!("{}{}{RESET}", duration_color(duration), format_duration(duration)).into_bytes());
        }
        if let Value::Str(trace) = span.get("span.trace_id") {
            let mut part = DIM.as_bytes().to_vec();
            part.extend_from_slice(&trace.as_bytes()[..trace.len().min(8)]);
            part.extend_from_slice(RESET.as_bytes());
            parts.push(part);
        }
        parts.join(format!("{DIM} · {RESET}").as_bytes())
    }
}

impl Exporter for Pretty {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        self.streams.write(span.error().is_some(), &self.render(span));
    }
}
