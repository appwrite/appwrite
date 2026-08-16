use crate::error::SpanError;
use crate::exporter::Exporter;
use crate::span::Span;

type Sampler = Box<dyn Fn(&Span) -> bool + Send + Sync>;

/// NDJSON exporter (PHP `Exporter\Stdout`).
pub struct Stdout {
    sampler: Sampler,
    max_trace_frames: usize,
}

impl std::fmt::Debug for Stdout {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Stdout")
            .field("max_trace_frames", &self.max_trace_frames)
            .finish_non_exhaustive()
    }
}

impl Stdout {
    pub fn new() -> Self {
        Self::new_with(None, 3)
    }

    pub fn new_with(sampler: Option<Sampler>, max_trace_frames: usize) -> Self {
        Self {
            sampler: sampler.unwrap_or_else(|| Box::new(|_| true)),
            max_trace_frames,
        }
    }

    pub fn format(&self, span: &Span) -> Option<String> {
        let mut entries: Vec<(String, serde_json::Value)> = Vec::new();
        let attributes = span.get_attributes();
        if let Some((_, level)) = attributes.iter().find(|(k, _)| k == "level") {
            entries.push(("level".into(), level.to_json()));
        }
        entries.push((
            "action".into(),
            serde_json::Value::String(span.get_action()),
        ));
        for (key, value) in &attributes {
            if key == "level" {
                continue;
            }
            entries.push((key.clone(), value.to_json()));
        }
        if let Some(error) = span.get_error() {
            append_error(&mut entries, &error, self.max_trace_frames);
        }
        encode_object(&entries)
    }
}

impl Default for Stdout {
    fn default() -> Self {
        Self::new()
    }
}

impl Exporter for Stdout {
    fn sample(&self, span: &Span) -> bool {
        (self.sampler)(span)
    }

    fn export(&self, span: &Span) {
        let Some(output) = self.format(span) else {
            return;
        };
        let stream: &mut dyn std::io::Write = if span.get_error().is_some() {
            &mut std::io::stderr()
        } else {
            &mut std::io::stdout()
        };
        let _ = writeln!(stream, "{output}");
    }
}

fn append_error(
    entries: &mut Vec<(String, serde_json::Value)>,
    error: &SpanError,
    max_trace_frames: usize,
) {
    entries.push(("error.type".into(), error.type_name.clone().into()));
    entries.push(("error.message".into(), error.message.clone().into()));
    entries.push(("error.code".into(), error.code.into()));
    entries.push(("error.file".into(), error.file.clone().into()));
    entries.push(("error.line".into(), u64::from(error.line).into()));
    let limited: Vec<serde_json::Value> = error
        .frames
        .iter()
        .take(max_trace_frames)
        .map(|frame| {
            serde_json::json!({
                "file": frame.file,
                "line": frame.line,
                "function": frame.function,
            })
        })
        .collect();
    entries.push(("error.trace".into(), serde_json::Value::Array(limited)));
    if error.frames.len() > max_trace_frames {
        let remaining = error.frames.len() - max_trace_frames;
        entries.push((
            "error.trace_truncated".into(),
            serde_json::Value::from(remaining),
        ));
    }
}

fn encode_object(entries: &[(String, serde_json::Value)]) -> Option<String> {
    let mut out = String::from("{");
    for (i, (key, value)) in entries.iter().enumerate() {
        if i > 0 {
            out.push(',');
        }
        out.push_str(&serde_json::to_string(key).ok()?);
        out.push(':');
        out.push_str(&serde_json::to_string(value).ok()?);
    }
    out.push('}');
    Some(out)
}
