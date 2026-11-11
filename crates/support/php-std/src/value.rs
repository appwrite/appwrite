//! The PHP value model.

/// A PHP number after `$value + 0`.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Number {
    /// An integer.
    Int(i64),
    /// A float.
    Float(f64),
}

impl Number {
    /// The number as a float.
    pub fn as_f64(self) -> f64 {
        match self {
            Number::Int(i) => i as f64,
            Number::Float(f) => f,
        }
    }
}
