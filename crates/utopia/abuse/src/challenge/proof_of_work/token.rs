//! [`Token`]: a proof-of-work puzzle.

/// A proof-of-work token: the value to send back, the nonce the client
/// hashes its answers against, and how many leading zero bits the hash must
/// have.
///
/// PHP: `Utopia\Abuse\Challenge\ProofOfWork\Token`.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct Token {
    /// The signed value.
    pub value: String,
    /// The nonce answers are hashed against.
    pub nonce: String,
    /// The leading zero bits an answer's hash needs.
    pub difficulty: i64,
}

impl Token {
    /// A puzzle token.
    ///
    /// PHP: `new Token($value, $nonce, $difficulty)`.
    pub fn new(value: String, nonce: String, difficulty: i64) -> Self {
        Token { value, nonce, difficulty }
    }
}
