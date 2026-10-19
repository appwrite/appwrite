/// A measurement attribute value.
#[derive(Debug, Clone, PartialEq)]
pub enum Attribute {
    /// A string value.
    Str(String),
    /// An integer value.
    Int(i64),
    /// An attribute that does not apply (`null`).
    None,
}
