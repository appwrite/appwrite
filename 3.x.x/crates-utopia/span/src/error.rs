//! Captured exception information (PHP `Throwable`).

use std::backtrace::Backtrace;
use std::error::Error;
use std::panic::Location;

/// One stack frame (PHP `Throwable::getTrace()` element).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TraceFrame {
    pub file: Option<String>,
    pub line: Option<u32>,
    pub function: String,
}

/// Exception captured on a span (`set_error` / `finish(error:)`).
#[derive(Debug, Clone)]
pub struct SpanError {
    pub type_name: String,
    pub message: String,
    pub code: i64,
    pub file: String,
    pub line: u32,
    pub frames: Vec<TraceFrame>,
    pub previous: Option<Box<SpanError>>,
}

impl SpanError {
    /// Capture `error` plus `source()` chain. `location` is the `set_error`/`finish` site.
    pub fn from_typed<E: Error + 'static>(error: &E, location: &Location<'_>) -> Self {
        let mut node = capture_one(std::any::type_name::<E>(), error, location);
        let mut current: Option<&dyn Error> = error.source();
        let mut tail = &mut node;
        while let Some(err) = current {
            tail.previous = Some(Box::new(capture_one(debug_type_name(err), err, location)));
            tail = tail.previous.as_mut().expect("just set");
            current = err.source();
        }
        node
    }

    /// PHP namespace (`substr($class, 0, strrpos($class, '\\'))`), if any.
    pub fn module(&self) -> Option<&str> {
        self.type_name.rfind("::").map(|idx| &self.type_name[..idx])
    }
}

impl std::fmt::Display for SpanError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}: {}", self.type_name, self.message)
    }
}

impl Error for SpanError {}

fn capture_one(
    type_name: impl Into<String>,
    error: &dyn Error,
    location: &Location<'_>,
) -> SpanError {
    let mut frames = parse_backtrace(&Backtrace::force_capture());
    frames.retain(|frame| frame.file.is_some());
    frames.push(TraceFrame {
        file: Some(location.file().to_string()),
        line: Some(location.line()),
        function: String::new(),
    });
    SpanError {
        type_name: type_name.into(),
        message: error.to_string(),
        code: 0,
        file: location.file().to_string(),
        line: location.line(),
        frames,
        previous: None,
    }
}

fn debug_type_name(error: &dyn Error) -> String {
    let dbg = format!("{error:?}");
    let token = dbg.split(['(', '{', ' ', ':']).next().unwrap_or("Error");
    if token.is_empty() {
        "Error".to_string()
    } else {
        token.to_string()
    }
}

fn parse_backtrace(trace: &Backtrace) -> Vec<TraceFrame> {
    let rendered = format!("{trace}");
    let mut frames = Vec::new();
    let mut pending_fn: Option<String> = None;
    for line in rendered.lines() {
        let trimmed = line.trim();
        if let Some(rest) = trimmed.strip_prefix("at ") {
            let (file, line_no) = parse_at(rest);
            frames.push(TraceFrame {
                file,
                line: line_no,
                function: pending_fn.take().unwrap_or_default(),
            });
        } else if let Some(func) = parse_frame_fn(trimmed) {
            pending_fn = Some(func);
        }
    }
    frames
}

fn parse_frame_fn(line: &str) -> Option<String> {
    let stripped = line
        .trim()
        .trim_start_matches(|c: char| c.is_ascii_digit() || c == ':' || c.is_whitespace());
    if stripped.is_empty() || stripped.starts_with("at ") {
        None
    } else {
        Some(stripped.to_string())
    }
}

fn parse_at(rest: &str) -> (Option<String>, Option<u32>) {
    let rest = rest.trim();
    let bytes = rest.as_bytes();
    if let Some(colon) = rest.rfind(':') {
        let after = &rest[colon + 1..];
        if after.bytes().all(|b| b.is_ascii_digit()) {
            if colon > 0 && bytes.get(colon.saturating_sub(1)) != Some(&b':') {
                if let Some(prev) = rest[..colon].rfind(':') {
                    let maybe_line = &rest[prev + 1..colon];
                    if maybe_line.bytes().all(|b| b.is_ascii_digit()) {
                        return (Some(rest[..prev].to_string()), maybe_line.parse().ok());
                    }
                }
            }
            return (Some(rest[..colon].to_string()), after.parse().ok());
        }
    }
    (Some(rest.to_string()), None)
}
