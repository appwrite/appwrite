use std::io::Write;

use crate::attr::AttrValue;
use crate::error::SpanError;
use crate::exporter::Exporter;
use crate::span::Span;

const RESET: &str = "\x1b[0m";
const BOLD: &str = "\x1b[1m";
const DIM: &str = "\x1b[2m";
const RED: &str = "\x1b[31m";
const GREEN: &str = "\x1b[32m";
const YELLOW: &str = "\x1b[33m";
const CYAN: &str = "\x1b[36m";
const WHITE: &str = "\x1b[37m";

type Sampler = Box<dyn Fn(&Span) -> bool + Send + Sync>;

/// Colourful human-readable exporter (PHP `Exporter\Pretty`).
pub struct Pretty {
    sampler: Sampler,
    max_trace_frames: usize,
    width: usize,
}

impl std::fmt::Debug for Pretty {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Pretty")
            .field("max_trace_frames", &self.max_trace_frames)
            .field("width", &self.width)
            .finish_non_exhaustive()
    }
}

impl Pretty {
    pub fn new() -> Self {
        Self::new_with(None, 3, 60)
    }

    pub fn new_with(sampler: Option<Sampler>, max_trace_frames: usize, width: usize) -> Self {
        Self {
            sampler: sampler.unwrap_or_else(|| Box::new(|_| true)),
            max_trace_frames,
            width,
        }
    }

    pub fn format(&self, span: &Span) -> String {
        let error = span.get_error();
        let has_error = error.is_some();
        let mut lines = Vec::new();
        lines.push(self.header(span, has_error));
        lines.push(String::new());

        let mut attributes: Vec<(String, AttrValue)> = span
            .get_attributes()
            .into_iter()
            .filter(|(key, _)| !key.starts_with("span."))
            .collect();

        if let Some(idx) = attributes.iter().position(|(k, _)| k == "level") {
            let level = attributes.remove(idx);
            attributes.insert(0, level);
        }

        let max_key_len = attributes.iter().map(|(k, _)| k.len()).max().unwrap_or(0);

        for (key, value) in &attributes {
            lines.push(attribute_line(key, value, max_key_len));
        }

        if let Some(error) = &error {
            if !attributes.is_empty() {
                lines.push(String::new());
            }
            lines.push(error_block(error));
            let limited = error.frames.iter().take(self.max_trace_frames);
            for frame in limited {
                let file = frame.file.as_deref().unwrap_or("unknown");
                let line = frame
                    .line
                    .map_or_else(|| "?".to_string(), |n| n.to_string());
                lines.push(format!("{DIM}    at {file}:{line}{RESET}"));
            }
            if error.frames.len() > self.max_trace_frames {
                let remaining = error.frames.len() - self.max_trace_frames;
                lines.push(format!("{DIM}    ... {remaining} more{RESET}"));
            }
        }

        lines.push(String::new());
        lines.push(format!("{DIM}{}{RESET}", "─".repeat(self.width)));
        lines.join("\n")
    }

    fn header(&self, span: &Span, has_error: bool) -> String {
        let action_color = if has_error { RED } else { GREEN };
        let action_str = format!("{BOLD}{action_color}{}{RESET}", span.get_action());
        let mut parts = vec![action_str];
        if let Some(duration) = span.get("span.duration").and_then(|v| v.as_f64()) {
            let duration_str = format_duration(duration);
            let duration_color = duration_color(duration);
            parts.push(format!("{duration_color}{duration_str}{RESET}"));
        }
        if let Some(trace_id) = span
            .get("span.trace_id")
            .and_then(|v| v.as_str().map(str::to_string))
        {
            let short: String = trace_id.chars().take(8).collect();
            parts.push(format!("{DIM}{short}{RESET}"));
        }
        parts.join(&format!("{DIM} · {RESET}"))
    }
}

impl Default for Pretty {
    fn default() -> Self {
        Self::new()
    }
}

impl Exporter for Pretty {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        let output = self.format(span);
        let stream: &mut dyn Write = if span.get_error().is_some() {
            &mut std::io::stderr()
        } else {
            &mut std::io::stdout()
        };
        let _ = writeln!(stream, "{output}");
    }
}

fn attribute_line(key: &str, value: &AttrValue, pad_to: usize) -> String {
    let padded = format!("{key:<pad_to$}");
    format!("  {CYAN}{padded}{RESET} {WHITE}{}{RESET}", value.display())
}

fn error_block(error: &SpanError) -> String {
    format!(
        "{RED}{BOLD}  {}{RESET}{RED}: {}{RESET}\n{DIM}    at {}:{}{RESET}",
        error.type_name, error.message, error.file, error.line
    )
}

fn format_duration(seconds: f64) -> String {
    if seconds >= 1.0 {
        format!("{}s", php_round(seconds, 2))
    } else {
        format!("{}ms", php_round(seconds * 1000.0, 1))
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

fn php_round(value: f64, digits: i32) -> f64 {
    let factor = 10f64.powi(digits);
    (value * factor).round() / factor
}
