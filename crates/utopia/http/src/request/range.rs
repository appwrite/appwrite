/// A `Range` or `Content-Range` header, parsed.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Range {
    /// The range unit, such as `bytes`.
    pub unit: String,
    /// The first position, inclusive.
    pub start: i64,
    /// The last position, inclusive; `None` for an open range (`bytes=0-`).
    pub end: Option<i64>,
    /// The total size (`Content-Range` only).
    pub size: Option<i64>,
}
