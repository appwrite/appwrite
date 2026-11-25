//! [`Token`]: what a challenge issues.

/// A token a challenge issued: the signed value the client sends back with
/// its answer. A kind whose client needs more to answer has its own token,
/// as [`super::proof_of_work::Token`] does.
///
/// PHP: `Utopia\Abuse\Challenge\Token`.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Token {
    /// The signed value.
    pub value: String,
}

impl Token {
    /// A token of `value`.
    ///
    /// PHP: `new Token($value)`.
    pub fn new(value: String) -> Self {
        Token { value }
    }
}
