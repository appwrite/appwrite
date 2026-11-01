/// How the body was encoded, decided by the original `Content-Type` (Swoole
/// parses form bodies while reading the request).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(super) enum Form {
    None,
    Urlencoded,
    Multipart,
}

impl Form {
    /// Swoole's `Content-Type` sniffing: case-insensitive prefixes.
    pub(super) fn from_content_type(content_type: &str) -> Self {
        let lower = content_type.as_bytes();
        let starts = |p: &str| lower.len() >= p.len() && lower[..p.len()].eq_ignore_ascii_case(p.as_bytes());
        if starts("application/x-www-form-urlencoded") {
            Form::Urlencoded
        } else if starts("multipart/form-data") {
            Form::Multipart
        } else {
            Form::None
        }
    }
}
